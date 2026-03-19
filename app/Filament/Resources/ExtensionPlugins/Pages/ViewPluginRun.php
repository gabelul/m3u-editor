<?php

namespace App\Filament\Resources\ExtensionPlugins\Pages;

use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use App\Jobs\ExecutePluginInvocation;
use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ViewPluginRun extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ExtensionPluginResource::class;

    protected string $view = 'filament.resources.extension-plugins.pages.view-plugin-run';

    public ExtensionPluginRun $runRecord;

    public Collection $logs;

    public function mount(int|string $record, int|string $run): void
    {
        app(PluginManager::class)->recoverStaleRuns();

        $this->record = $this->resolveRecord($record);

        static::authorizeResourceAccess();

        /** @var ExtensionPlugin $plugin */
        $plugin = $this->getRecord();
        $runRecord = $plugin->runs()
            ->with(['plugin', 'user'])
            ->find($run);

        if (! $runRecord) {
            throw (new ModelNotFoundException)->setModel(ExtensionPluginRun::class, [$run]);
        }

        $this->runRecord = $runRecord;
        $this->logs = $runRecord->logs()->latest()->limit(150)->get()->reverse()->values();
    }

    public function getTitle(): string
    {
        $label = $this->runRecord->action ?: $this->runRecord->hook ?: 'Plugin Run';

        return Str::headline($label).' #'.$this->runRecord->id;
    }

    public function isReviewableScanRun(): bool
    {
        return $this->runRecord->action === 'scan' && $this->runRecord->status === 'completed';
    }

    public function reviewDecisions(): array
    {
        return data_get($this->runRecord->result, 'data.review.decisions', []);
    }

    public function approvedReviewCount(): int
    {
        return (int) data_get($this->runRecord->result, 'data.review.counts.approved', 0);
    }

    public function markReviewDecision(int $channelId, string $status): void
    {
        if (! $this->isReviewableScanRun()) {
            return;
        }

        if (! in_array($status, ['approved', 'rejected', 'pending'], true)) {
            return;
        }

        $previewItem = collect(data_get($this->runRecord->result, 'data.channels_preview', []))
            ->firstWhere('channel_id', $channelId);

        if (! $previewItem || ! data_get($previewItem, 'repairable') || ! filled(data_get($previewItem, 'suggested_epg_channel_id'))) {
            Notification::make()
                ->danger()
                ->title('Candidate not reviewable')
                ->body('Only reviewable preview candidates can be approved or rejected from this screen.')
                ->send();

            return;
        }

        $result = $this->runRecord->result ?? [];
        $data = $result['data'] ?? [];
        $review = $data['review'] ?? [];
        $decisions = $review['decisions'] ?? [];
        $existingStatus = Arr::get($decisions, (string) $channelId.'.status');

        if ($existingStatus === 'applied') {
            Notification::make()
                ->warning()
                ->title('Candidate already applied')
                ->body('Applied review decisions are locked on the source scan run.')
                ->send();

            return;
        }

        if ($status === 'pending') {
            unset($decisions[(string) $channelId]);
        } else {
            $decisions[(string) $channelId] = [
                'status' => $status,
                'updated_at' => now()->toIso8601String(),
                'user_id' => auth()->id(),
                'user_name' => auth()->user()?->name,
                'item' => $previewItem,
            ];
        }

        $review['decisions'] = $decisions;
        $review['counts'] = $this->reviewCounts($data['channels_preview'] ?? [], $decisions);
        $review['updated_at'] = now()->toIso8601String();

        $data['review'] = $review;
        $result['data'] = $data;

        $this->runRecord->forceFill(['result' => $result])->save();
        $this->runRecord->logs()->create([
            'level' => 'info',
            'message' => 'Candidate review updated.',
            'context' => [
                'channel_id' => $channelId,
                'review_status' => $status,
                'user_id' => auth()->id(),
            ],
        ]);

        $this->refreshRunState();

        Notification::make()
            ->success()
            ->title('Review updated')
            ->body(match ($status) {
                'approved' => 'Candidate approved for Apply Reviewed.',
                'rejected' => 'Candidate rejected and will be skipped by Apply Reviewed.',
                default => 'Candidate returned to pending review.',
            })
            ->send();
    }

    public function approveAllVisibleCandidates(): void
    {
        if (! $this->isReviewableScanRun()) {
            return;
        }

        $previewItems = collect(data_get($this->runRecord->result, 'data.channels_preview', []))
            ->filter(fn (array $item): bool => (bool) data_get($item, 'repairable') && filled(data_get($item, 'suggested_epg_channel_id')))
            ->reject(fn (array $item): bool => data_get($this->runRecord->result, 'data.review.decisions.'.(string) $item['channel_id'].'.status') === 'applied')
            ->values();

        if ($previewItems->isEmpty()) {
            return;
        }

        $result = $this->runRecord->result ?? [];
        $data = $result['data'] ?? [];
        $review = $data['review'] ?? [];
        $decisions = $review['decisions'] ?? [];

        foreach ($previewItems as $item) {
            $decisions[(string) $item['channel_id']] = [
                'status' => 'approved',
                'updated_at' => now()->toIso8601String(),
                'user_id' => auth()->id(),
                'user_name' => auth()->user()?->name,
                'item' => $item,
            ];
        }

        $review['decisions'] = $decisions;
        $review['counts'] = $this->reviewCounts($data['channels_preview'] ?? [], $decisions);
        $review['updated_at'] = now()->toIso8601String();
        $data['review'] = $review;
        $result['data'] = $data;

        $this->runRecord->forceFill(['result' => $result])->save();
        $this->runRecord->logs()->create([
            'level' => 'info',
            'message' => 'All visible reviewable candidates were approved.',
            'context' => [
                'candidate_count' => $previewItems->count(),
                'user_id' => auth()->id(),
            ],
        ]);

        $this->refreshRunState();

        Notification::make()
            ->success()
            ->title('Visible candidates approved')
            ->body('Apply Reviewed can now consume these approved preview candidates.')
            ->send();
    }

    public function clearReviewDecisions(): void
    {
        if (! $this->isReviewableScanRun()) {
            return;
        }

        $result = $this->runRecord->result ?? [];
        $data = $result['data'] ?? [];
        $existingDecisions = data_get($data, 'review.decisions', []);
        $appliedDecisions = collect($existingDecisions)
            ->filter(fn (array $decision): bool => ($decision['status'] ?? null) === 'applied')
            ->all();
        $data['review'] = [
            'decisions' => $appliedDecisions,
            'counts' => $this->reviewCounts($data['channels_preview'] ?? [], $appliedDecisions),
            'updated_at' => now()->toIso8601String(),
        ];
        $result['data'] = $data;

        $this->runRecord->forceFill(['result' => $result])->save();
        $this->runRecord->logs()->create([
            'level' => 'info',
            'message' => 'Review decisions cleared by operator.',
            'context' => [
                'user_id' => auth()->id(),
            ],
        ]);

        $this->refreshRunState();

        Notification::make()
            ->success()
            ->title('Review decisions cleared')
            ->body('All preview candidates are back to pending review.')
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download_report')
                ->label('Download Report')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(function (): bool {
                    $reportPath = data_get($this->runRecord->result, 'data.report.path')
                        ?? data_get($this->runRecord->run_state, 'epg_repair.report_path');

                    return filled($reportPath) && Storage::disk('local')->exists($reportPath);
                })
                ->url(fn (): string => route('extension-plugins.runs.report', [
                    'plugin' => $this->getRecord(),
                    'run' => $this->runRecord,
                ])),
            Action::make('approve_visible')
                ->label('Approve Visible')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (): bool => $this->isReviewableScanRun())
                ->action(fn () => $this->approveAllVisibleCandidates()),
            Action::make('clear_review')
                ->label('Clear Review')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->visible(fn (): bool => $this->isReviewableScanRun())
                ->requiresConfirmation()
                ->action(fn () => $this->clearReviewDecisions()),
            Action::make('apply_reviewed')
                ->label('Apply Reviewed')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->visible(fn (): bool => $this->isReviewableScanRun() && $this->approvedReviewCount() > 0)
                ->requiresConfirmation()
                ->modalDescription('Only candidates you approved on this run will be applied. No fresh scan is performed.')
                ->action(function (): void {
                    dispatch(new ExecutePluginInvocation(
                        pluginId: $this->getRecord()->id,
                        invocationType: 'action',
                        name: 'apply_reviewed',
                        payload: [
                            'source_run_id' => $this->runRecord->id,
                        ],
                        options: [
                            'trigger' => 'manual',
                            'dry_run' => false,
                            'user_id' => auth()->id(),
                        ],
                    ));

                    Notification::make()
                        ->success()
                        ->title('Apply Reviewed queued')
                        ->body('A background run will apply only the candidates you approved on this scan.')
                        ->send();
                }),
            Action::make('stop_run')
                ->label('Stop Run')
                ->icon('heroicon-o-stop-circle')
                ->color('warning')
                ->visible(fn (): bool => $this->runRecord->status === 'running')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(PluginManager::class)->requestCancellation($this->runRecord, auth()->id());
                    $this->runRecord = $this->runRecord->fresh();
                    $this->logs = $this->runRecord->logs()->latest()->limit(150)->get()->reverse()->values();

                    Notification::make()
                        ->success()
                        ->title('Cancellation requested')
                        ->body('The worker will stop the run at the next safe checkpoint.')
                        ->send();
                }),
            Action::make('resume_run')
                ->label('Resume Run')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => in_array($this->runRecord->status, ['cancelled', 'stale', 'failed'], true))
                ->action(function (): void {
                    app(PluginManager::class)->resumeRun($this->runRecord, auth()->id());
                    $this->runRecord = $this->runRecord->fresh();
                    $this->logs = $this->runRecord->logs()->latest()->limit(150)->get()->reverse()->values();

                    Notification::make()
                        ->success()
                        ->title('Run resumed')
                        ->body('The run was queued again and will continue from the last saved checkpoint when possible.')
                        ->send();
                }),
            Action::make('back_to_plugin')
                ->label('Back to Plugin')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => ExtensionPluginResource::getUrl('edit', [
                    'record' => $this->getRecord(),
                ])),
        ];
    }

    private function refreshRunState(): void
    {
        $this->runRecord = $this->runRecord->fresh(['plugin', 'user']);
        $this->logs = $this->runRecord->logs()->latest()->limit(150)->get()->reverse()->values();
    }

    private function reviewCounts(array $previewItems, array $decisions): array
    {
        $reviewableIds = collect($previewItems)
            ->filter(fn (array $item): bool => (bool) data_get($item, 'repairable') && filled(data_get($item, 'suggested_epg_channel_id')))
            ->pluck('channel_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $counts = [
            'approved' => 0,
            'rejected' => 0,
            'applied' => 0,
            'pending' => 0,
        ];

        foreach ($reviewableIds as $channelId) {
            $status = Arr::get($decisions, $channelId.'.status', 'pending');

            if (! array_key_exists($status, $counts)) {
                $status = 'pending';
            }

            $counts[$status]++;
        }

        return $counts;
    }
}

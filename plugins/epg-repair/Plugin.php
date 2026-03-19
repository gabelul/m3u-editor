<?php

namespace AppLocalPlugins\EpgRepair;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Plugins\Contracts\EpgRepairPluginInterface;
use App\Plugins\Contracts\HookablePluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Support\PluginActionResult;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\ChannelTitleNormalizerService;
use App\Services\EpgCacheService;
use App\Services\SimilaritySearchService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Plugin implements EpgRepairPluginInterface, HookablePluginInterface, ScheduledPluginInterface
{
    private const SCAN_CHUNK_SIZE = 250;

    private const CHECKPOINT_EVERY_CHUNKS = 5;

    private const MAX_DETAILED_APPLY_LOGS = 25;

    private const MAX_RESULT_CHANNELS = 50;

    public function __construct(
        private readonly SimilaritySearchService $similaritySearch,
        private readonly ChannelTitleNormalizerService $normalizer,
        private readonly EpgCacheService $cacheService,
    ) {}

    public function runAction(string $action, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        return match ($action) {
            'scan' => $this->scan($payload, $context, false),
            'apply' => $this->apply($payload, $context),
            default => PluginActionResult::failure("Unsupported action [{$action}]"),
        };
    }

    public function runHook(string $hook, array $payload, PluginExecutionContext $context): PluginActionResult
    {
        if ($hook !== 'epg.cache.generated') {
            return PluginActionResult::success("Hook [{$hook}] ignored by EPG Repair.");
        }

        if (! ($context->settings['auto_scan_on_epg_ready'] ?? false)) {
            return PluginActionResult::success('Auto scan is disabled.');
        }

        $playlistId = $context->settings['default_playlist_id'] ?? ($payload['playlist_ids'][0] ?? null);
        $epgId = $context->settings['default_epg_id'] ?? ($payload['epg_id'] ?? null);

        if (! $playlistId || ! $epgId) {
            return PluginActionResult::success('Auto scan skipped because no default playlist or EPG is configured.');
        }

        return $this->scan([
            'playlist_id' => $playlistId,
            'epg_id' => $epgId,
            'hours_ahead' => $context->settings['hours_ahead'] ?? 12,
            'confidence_threshold' => $context->settings['confidence_threshold'] ?? 0.65,
        ], $context, true);
    }

    public function scheduledActions(CarbonInterface $now, array $settings): array
    {
        if (! ($settings['schedule_enabled'] ?? false)) {
            return [];
        }

        $playlistId = $settings['default_playlist_id'] ?? null;
        $epgId = $settings['default_epg_id'] ?? null;
        $cron = $settings['schedule_cron'] ?? null;

        if (! $playlistId || ! $epgId || ! is_string($cron) || ! CronExpression::isValidExpression($cron)) {
            return [];
        }

        $expression = new CronExpression($cron);
        if (! $expression->isDue($now)) {
            return [];
        }

        return [[
            'type' => 'action',
            'name' => 'scan',
            'payload' => [
                'playlist_id' => $playlistId,
                'epg_id' => $epgId,
                'hours_ahead' => $settings['hours_ahead'] ?? 12,
                'confidence_threshold' => $settings['confidence_threshold'] ?? 0.65,
            ],
            'dry_run' => true,
        ]];
    }

    private function scan(array $payload, PluginExecutionContext $context, bool $implicitDryRun): PluginActionResult
    {
        [$playlist, $epg] = $this->resolveTargets($payload, $context->settings);
        if (! $playlist || ! $epg) {
            $context->error('EPG Repair scan failed because the selected playlist or EPG could not be resolved.', [
                'playlist_id' => $payload['playlist_id'] ?? $context->settings['default_playlist_id'] ?? null,
                'epg_id' => $payload['epg_id'] ?? $context->settings['default_epg_id'] ?? null,
            ]);
            return PluginActionResult::failure('Playlist or EPG could not be resolved.');
        }

        $hoursAhead = max(1, (int) ($payload['hours_ahead'] ?? $context->settings['hours_ahead'] ?? 12));
        $threshold = min(1, max(0.1, (float) ($payload['confidence_threshold'] ?? $context->settings['confidence_threshold'] ?? 0.65)));

        $context->info('Starting EPG Repair scan.', [
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
            'epg_id' => $epg->id,
            'epg_name' => $epg->name,
            'hours_ahead' => $hoursAhead,
            'confidence_threshold' => $threshold,
            'dry_run' => $context->dryRun || $implicitDryRun,
        ]);

        [$issues, $cancelled] = $this->processRepairStream(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            context: $context,
            applyRepairs: false,
        );

        if ($issues['totals']['channels_scanned'] === 0) {
            $context->warning('Scan found no enabled live channels in the selected playlist.', [
                'playlist_id' => $playlist->id,
                'playlist_name' => $playlist->name,
            ]);
        } else {
            $context->info('Scan completed.', [
                'channels_scanned' => $issues['totals']['channels_scanned'],
                'issues_found' => $issues['totals']['issues_found'],
                'repair_candidates' => $issues['totals']['repair_candidates'],
                'epg_channels_available' => $issues['totals']['epg_channels_available'],
                'channels_with_existing_programmes' => $issues['totals']['channels_with_existing_programmes'],
            ]);
        }

        if ($cancelled) {
            return PluginActionResult::cancelled(
                sprintf(
                    'Scan stopped after checking %d channels. Resume the run to continue from the last saved checkpoint.',
                    $issues['totals']['channels_scanned'],
                ),
                [
                    'dry_run' => $context->dryRun || $implicitDryRun,
                    ...$this->resultSnapshot($issues),
                ],
            );
        }

        $summary = $issues['totals']['channels_scanned'] === 0
            ? 'Scanned 0 channels. The selected playlist currently has no enabled live channels to inspect.'
            : sprintf(
                'Scanned %d channels and found %d repair candidate(s).',
                $issues['totals']['channels_scanned'],
                $issues['totals']['repair_candidates']
            );

        return PluginActionResult::success(
            $summary,
            [
                'dry_run' => $context->dryRun || $implicitDryRun,
                ...$this->resultSnapshot($issues),
            ],
        );
    }

    private function apply(array $payload, PluginExecutionContext $context): PluginActionResult
    {
        [$playlist, $epg] = $this->resolveTargets($payload, $context->settings);
        if (! $playlist || ! $epg) {
            $context->error('EPG Repair apply failed because the selected playlist or EPG could not be resolved.', [
                'playlist_id' => $payload['playlist_id'] ?? $context->settings['default_playlist_id'] ?? null,
                'epg_id' => $payload['epg_id'] ?? $context->settings['default_epg_id'] ?? null,
            ]);
            return PluginActionResult::failure('Playlist or EPG could not be resolved.');
        }

        $hoursAhead = max(1, (int) ($payload['hours_ahead'] ?? $context->settings['hours_ahead'] ?? 12));
        $threshold = min(1, max(0.1, (float) ($payload['confidence_threshold'] ?? $context->settings['confidence_threshold'] ?? 0.65)));

        $context->info('Starting EPG Repair apply run.', [
            'playlist_id' => $playlist->id,
            'playlist_name' => $playlist->name,
            'epg_id' => $epg->id,
            'epg_name' => $epg->name,
            'hours_ahead' => $hoursAhead,
            'confidence_threshold' => $threshold,
        ]);

        [$report, $cancelled] = $this->processRepairStream(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            context: $context,
            applyRepairs: true,
        );

        $applied = $report['totals']['repairs_applied'] ?? 0;

        if ($cancelled) {
            return PluginActionResult::cancelled(
                "Apply stopped after {$applied} EPG repair(s). Resume the run to continue from the last saved checkpoint.",
                [
                    'dry_run' => false,
                    ...$this->resultSnapshot($report),
                ],
            );
        }

        if (($report['totals']['repairs_applied'] ?? 0) === 0) {
            $context->warning('Apply finished with no repairs applied.', [
                'issues_found' => $report['totals']['issues_found'],
                'repair_candidates' => $report['totals']['repair_candidates'],
            ]);
        }

        return PluginActionResult::success(
            "Applied {$applied} EPG repair(s).",
            [
                'dry_run' => false,
                ...$this->resultSnapshot($report),
            ],
        );
    }

    private function processRepairStream(
        Playlist $playlist,
        Epg $epg,
        int $hoursAhead,
        float $threshold,
        PluginExecutionContext $context,
        bool $applyRepairs,
    ): array
    {
        $mode = $applyRepairs ? 'apply' : 'scan';
        $totalChannels = (int) $playlist->enabled_live_channels()->count();
        $epgChannelsAvailable = (int) $epg->channels()->count();
        $checkpoint = $this->initialCheckpointState(
            playlist: $playlist,
            epg: $epg,
            hoursAhead: $hoursAhead,
            threshold: $threshold,
            totalChannels: $totalChannels,
            epgChannelsAvailable: $epgChannelsAvailable,
            context: $context,
            mode: $mode,
        );
        $checkpoint = $this->prepareReportState($context, $checkpoint, $mode);

        $start = Carbon::now();
        $end = $start->copy()->addHours($hoursAhead);
        $chunkNumber = 0;
        $detailedApplyLogs = (int) ($checkpoint['detailed_apply_logs'] ?? 0);
        $cancelled = false;

        $query = $playlist->enabled_live_channels()
            ->with(['epgChannel.epg'])
            ->orderBy('channels.id');

        if (($checkpoint['last_channel_id'] ?? null) !== null) {
            $query->where('channels.id', '>', $checkpoint['last_channel_id']);
        }

        $query->chunkById(self::SCAN_CHUNK_SIZE, function (Collection $channels) use (
            $applyRepairs,
            $context,
            $epg,
            $playlist,
            $end,
            &$cancelled,
            &$checkpoint,
            &$chunkNumber,
            &$detailedApplyLogs,
            $start,
            $threshold
        ): bool {
            if ($context->cancellationRequested()) {
                $cancelled = true;
                $context->checkpoint(
                    progress: $this->progressPercent($checkpoint['channels_scanned'], $checkpoint['total_channels']),
                    message: 'Cancellation requested. Saving the last safe checkpoint.',
                    state: ['epg_repair' => $checkpoint],
                    log: true,
                );

                return false;
            }

            $chunkNumber++;

            $mappedChannelIds = $channels
                ->filter(fn (Channel $channel) => $channel->epgChannel?->epg_id === $epg->id && filled($channel->epgChannel?->channel_id))
                ->map(fn (Channel $channel) => $channel->epgChannel->channel_id)
                ->unique()
                ->values()
                ->all();

            $programmes = $mappedChannelIds === []
                ? []
                : $this->cacheService->getCachedProgrammesRange(
                    $epg,
                    $start->toDateString(),
                    $end->toDateString(),
                    $mappedChannelIds,
                );

            foreach ($channels as $channel) {
                $checkpoint['channels_scanned']++;
                $checkpoint['last_channel_id'] = $channel->id;

                if ($channel->epgChannel?->epg_id === $epg->id && filled($channel->epgChannel?->channel_id)) {
                    $checkpoint['channels_with_existing_programmes']++;
                }

                $issue = $this->detectIssue($channel, $epg, $programmes);
                if (! $issue) {
                    continue;
                }

                $checkpoint['issues_found']++;
                $this->incrementBreakdown($checkpoint, 'issue_breakdown', $issue);

                $suggested = $this->similaritySearch->findMatchingEpgChannel($channel, $epg);
                $matchInsight = $suggested
                    ? $this->matchInsight($channel, $suggested, $threshold)
                    : $this->emptyMatchInsight();
                $confidence = $matchInsight['confidence'];
                $repairable = $matchInsight['repairable'];

                if ($repairable) {
                    $checkpoint['repair_candidates']++;
                }

                $item = [
                    'channel_id' => $channel->id,
                    'channel_name' => $channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name,
                    'playlist_id' => $playlist->id,
                    'playlist_name' => $playlist->name,
                    'issue' => $issue,
                    'current_epg_channel_id' => $channel->epg_channel_id,
                    'current_epg_channel_name' => $channel->epgChannel?->display_name ?? $channel->epgChannel?->name ?? $channel->epgChannel?->channel_id,
                    'current_epg_source_id' => $channel->epgChannel?->epg_id,
                    'current_epg_source_name' => $channel->epgChannel?->epg?->name,
                    'suggested_epg_channel_id' => $suggested?->id,
                    'suggested_epg_channel_name' => $suggested?->display_name ?? $suggested?->name ?? $suggested?->channel_id,
                    'suggested_epg_source_id' => $suggested?->epg_id,
                    'suggested_epg_source_name' => $suggested?->epg?->name ?? $epg->name,
                    'confidence' => $confidence,
                    'confidence_band' => $matchInsight['confidence_band'],
                    'match_reason' => $matchInsight['match_reason'],
                    'repairable' => $repairable,
                    'decision' => $this->decisionForItem($suggested, $repairable),
                ];

                $this->recordPreviewItem($checkpoint, $item);
                $this->incrementBreakdown($checkpoint, 'decision_breakdown', $item['decision']);
                $this->incrementBreakdown($checkpoint, 'confidence_breakdown', $item['confidence_band']);

                if (! $applyRepairs || ! $repairable) {
                    $item['applied'] = false;
                    $this->appendReportRow($checkpoint, $item);
                    continue;
                }

                Channel::query()
                    ->whereKey($channel->id)
                    ->update([
                        'epg_channel_id' => $item['suggested_epg_channel_id'],
                    ]);

                $checkpoint['repairs_applied']++;
                $item['applied'] = true;
                $this->appendReportRow($checkpoint, $item);

                if ($detailedApplyLogs < self::MAX_DETAILED_APPLY_LOGS) {
                    $context->info('Applied EPG repair to channel.', [
                        'channel_id' => $item['channel_id'],
                        'channel_name' => $item['channel_name'],
                        'suggested_epg_channel_id' => $item['suggested_epg_channel_id'],
                        'suggested_epg_channel_name' => $item['suggested_epg_channel_name'],
                        'confidence' => $item['confidence'],
                    ]);
                    $detailedApplyLogs++;
                    $checkpoint['detailed_apply_logs'] = $detailedApplyLogs;
                }

                continue;
            }

            $progress = $this->progressPercent($checkpoint['channels_scanned'], $checkpoint['total_channels']);
            $state = ['epg_repair' => $checkpoint];

            if ($chunkNumber % self::CHECKPOINT_EVERY_CHUNKS === 0) {
                $context->checkpoint(
                    progress: $progress,
                    message: $this->chunkMessage($applyRepairs, $checkpoint),
                    state: $state,
                    log: true,
                    context: [
                        'channels_scanned' => $checkpoint['channels_scanned'],
                        'issues_found' => $checkpoint['issues_found'],
                        'repair_candidates' => $checkpoint['repair_candidates'],
                        'repairs_applied' => $checkpoint['repairs_applied'],
                    ],
                );
            } else {
                $context->heartbeat(
                    message: $this->chunkMessage($applyRepairs, $checkpoint),
                    progress: $progress,
                    state: $state,
                );
            }

            if ($context->cancellationRequested()) {
                $cancelled = true;
                $context->checkpoint(
                    progress: $progress,
                    message: 'Cancellation requested. Saving the last safe checkpoint.',
                    state: $state,
                    log: true,
                );

                return false;
            }

            return true;
        }, 'channels.id', 'id');

        return [[
            'playlist' => [
                'id' => $playlist->id,
                'name' => $playlist->name,
            ],
            'epg' => [
                'id' => $epg->id,
                'name' => $epg->name,
            ],
            'progress' => $cancelled ? $this->progressPercent($checkpoint['channels_scanned'], $checkpoint['total_channels']) : 100,
            'totals' => [
                'channels_scanned' => $checkpoint['channels_scanned'],
                'issues_found' => $checkpoint['issues_found'],
                'repair_candidates' => $checkpoint['repair_candidates'],
                'repairs_applied' => $checkpoint['repairs_applied'],
                'epg_channels_available' => $checkpoint['epg_channels_available'],
                'channels_with_existing_programmes' => $checkpoint['channels_with_existing_programmes'],
            ],
            'issue_breakdown' => $checkpoint['issue_breakdown'],
            'decision_breakdown' => $checkpoint['decision_breakdown'],
            'confidence_breakdown' => $checkpoint['confidence_breakdown'],
            'channels' => $checkpoint['preview_channels'],
            'report' => [
                'path' => $checkpoint['report_path'],
                'filename' => $checkpoint['report_filename'],
                'rows_written' => $checkpoint['report_rows_written'],
            ],
        ], $cancelled];
    }

    private function resolveTargets(array $payload, array $settings): array
    {
        $playlistId = $payload['playlist_id'] ?? $settings['default_playlist_id'] ?? null;
        $epgId = $payload['epg_id'] ?? $settings['default_epg_id'] ?? null;

        $playlist = $playlistId ? Playlist::find($playlistId) : null;
        $epg = $epgId ? Epg::find($epgId) : null;

        return [$playlist, $epg];
    }

    private function detectIssue(Channel $channel, Epg $epg, array $programmes): ?string
    {
        if (! $channel->epg_channel_id) {
            return 'unmapped';
        }

        if (! $channel->epgChannel) {
            return 'mapped_channel_missing';
        }

        if ($channel->epgChannel->epg_id !== $epg->id) {
            return 'mapped_to_other_epg';
        }

        $channelKey = $channel->epgChannel->channel_id;
        if ($channelKey && empty($programmes[$channelKey] ?? [])) {
            return 'mapped_without_programmes';
        }

        return null;
    }

    private function initialCheckpointState(
        Playlist $playlist,
        Epg $epg,
        int $hoursAhead,
        float $threshold,
        int $totalChannels,
        int $epgChannelsAvailable,
        PluginExecutionContext $context,
        string $mode,
    ): array {
        $state = $context->state('epg_repair', []);

        $canResume = ($state['mode'] ?? null) === $mode
            && ($state['playlist_id'] ?? null) === $playlist->id
            && ($state['epg_id'] ?? null) === $epg->id
            && (int) ($state['hours_ahead'] ?? 0) === $hoursAhead
            && (float) ($state['confidence_threshold'] ?? 0) === $threshold;

        if (! $canResume) {
            return [
                'mode' => $mode,
                'playlist_id' => $playlist->id,
                'epg_id' => $epg->id,
                'hours_ahead' => $hoursAhead,
                'confidence_threshold' => $threshold,
                'total_channels' => $totalChannels,
                'epg_channels_available' => $epgChannelsAvailable,
                'last_channel_id' => null,
                'channels_scanned' => 0,
                'issues_found' => 0,
                'repair_candidates' => 0,
                'repairs_applied' => 0,
                'channels_with_existing_programmes' => 0,
                'detailed_apply_logs' => 0,
                'preview_channels' => [],
                'preview_truncated' => false,
                'issue_breakdown' => [],
                'decision_breakdown' => [],
                'confidence_breakdown' => [],
                'report_path' => null,
                'report_filename' => null,
                'report_rows_written' => 0,
            ];
        }

        $state['total_channels'] = $totalChannels;
        $state['epg_channels_available'] = $epgChannelsAvailable;
        $state['preview_channels'] = $state['preview_channels'] ?? [];
        $state['preview_truncated'] = (bool) ($state['preview_truncated'] ?? false);
        $state['issue_breakdown'] = $state['issue_breakdown'] ?? [];
        $state['decision_breakdown'] = $state['decision_breakdown'] ?? [];
        $state['confidence_breakdown'] = $state['confidence_breakdown'] ?? [];
        $state['repairs_applied'] = (int) ($state['repairs_applied'] ?? 0);
        $state['detailed_apply_logs'] = (int) ($state['detailed_apply_logs'] ?? 0);
        $state['report_path'] = $state['report_path'] ?? null;
        $state['report_filename'] = $state['report_filename'] ?? null;
        $state['report_rows_written'] = (int) ($state['report_rows_written'] ?? 0);

        return $state;
    }

    private function chunkMessage(bool $applyRepairs, array $checkpoint): string
    {
        $prefix = $applyRepairs ? 'Applying repairs' : 'Scanning channels';

        return sprintf(
            '%s: %d/%d channels checked, %d issue(s), %d repair candidate(s), %d applied.',
            $prefix,
            $checkpoint['channels_scanned'],
            $checkpoint['total_channels'],
            $checkpoint['issues_found'],
            $checkpoint['repair_candidates'],
            $checkpoint['repairs_applied'],
        );
    }

    private function progressPercent(int $processed, int $total): int
    {
        if ($total <= 0) {
            return 100;
        }

        return min(99, (int) floor(($processed / $total) * 100));
    }

    private function prepareReportState(PluginExecutionContext $context, array $checkpoint, string $mode): array
    {
        $disk = Storage::disk('local');
        $reportPath = $checkpoint['report_path'] ?? null;

        if ($reportPath && $disk->exists($reportPath)) {
            return $checkpoint;
        }

        $directory = 'plugin-reports/'.$context->plugin->plugin_id;
        $disk->makeDirectory($directory);

        $filename = Str::slug($context->plugin->plugin_id.'-'.$mode.'-run-'.$context->run->id).'.csv';
        $reportPath = $directory.'/'.$filename;

        $disk->put($reportPath, $this->csvRow([
            'channel_id',
            'channel_name',
            'playlist_name',
            'issue',
            'decision',
            'current_epg_channel_id',
            'current_epg_channel_name',
            'current_epg_source_name',
            'suggested_epg_channel_id',
            'suggested_epg_channel_name',
            'suggested_epg_source_name',
            'confidence',
            'confidence_band',
            'match_reason',
            'repairable',
            'applied',
        ]).PHP_EOL);

        $checkpoint['report_path'] = $reportPath;
        $checkpoint['report_filename'] = $filename;
        $checkpoint['report_rows_written'] = 0;

        return $checkpoint;
    }

    private function appendReportRow(array &$checkpoint, array $item): void
    {
        if (! ($checkpoint['report_path'] ?? null)) {
            return;
        }

        Storage::disk('local')->append($checkpoint['report_path'], $this->csvRow([
            $item['channel_id'],
            $item['channel_name'],
            $item['playlist_name'],
            $item['issue'],
            $item['decision'],
            $item['current_epg_channel_id'],
            $item['current_epg_channel_name'],
            $item['current_epg_source_name'],
            $item['suggested_epg_channel_id'],
            $item['suggested_epg_channel_name'],
            $item['suggested_epg_source_name'],
            $item['confidence'],
            $item['confidence_band'],
            $item['match_reason'],
            $item['repairable'] ? 'yes' : 'no',
            ($item['applied'] ?? false) ? 'yes' : 'no',
        ]));

        $checkpoint['report_rows_written'] = (int) ($checkpoint['report_rows_written'] ?? 0) + 1;
    }

    private function csvRow(array $columns): string
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $columns);
        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return rtrim($csv, "\r\n");
    }

    private function resultSnapshot(array $report): array
    {
        $channels = $report['channels'] ?? [];
        $preview = array_slice($channels, 0, self::MAX_RESULT_CHANNELS);

        return [
            'progress' => $report['progress'] ?? 100,
            'playlist' => $report['playlist'] ?? null,
            'epg' => $report['epg'] ?? null,
            'totals' => $report['totals'] ?? [],
            'issue_breakdown' => $report['issue_breakdown'] ?? [],
            'decision_breakdown' => $report['decision_breakdown'] ?? [],
            'confidence_breakdown' => $report['confidence_breakdown'] ?? [],
            'channels_preview' => $preview,
            'channels_preview_count' => count($preview),
            'channels_total_count' => data_get($report, 'totals.issues_found', count($channels)),
            'channels_truncated' => (bool) data_get($report, 'totals.issues_found', count($channels)) > count($preview),
            'report' => $report['report'] ?? null,
        ];
    }

    private function incrementBreakdown(array &$checkpoint, string $key, string $bucket): void
    {
        if ($bucket === '') {
            return;
        }

        $checkpoint[$key] ??= [];
        $checkpoint[$key][$bucket] = (int) ($checkpoint[$key][$bucket] ?? 0) + 1;
    }

    private function recordPreviewItem(array &$checkpoint, array $item): void
    {
        $checkpoint['preview_channels'] ??= [];

        if (count($checkpoint['preview_channels']) < self::MAX_RESULT_CHANNELS) {
            $checkpoint['preview_channels'][] = $item;

            return;
        }

        $checkpoint['preview_truncated'] = true;
    }

    private function emptyMatchInsight(): array
    {
        return [
            'confidence' => null,
            'confidence_band' => 'none',
            'match_reason' => 'no_candidate',
            'repairable' => false,
        ];
    }

    private function decisionForItem(?EpgChannel $suggested, bool $repairable): string
    {
        if (! $suggested) {
            return 'no_candidate';
        }

        return $repairable ? 'repairable' : 'needs_review';
    }

    private function matchInsight(Channel $channel, EpgChannel $epgChannel, float $threshold): array
    {
        $channelName = $channel->title_custom ?? $channel->title ?? $channel->name_custom ?? $channel->name;
        $candidateNames = array_filter([
            $epgChannel->display_name,
            $epgChannel->name,
            $epgChannel->channel_id,
        ]);

        $normalizedChannel = $this->normalizer->normalize($channelName);
        if ($normalizedChannel === '') {
            return $this->emptyMatchInsight();
        }

        $best = [
            'confidence' => 0.0,
            'confidence_band' => 'low',
            'match_reason' => 'fuzzy_name_match',
            'repairable' => false,
        ];

        foreach ($candidateNames as $candidateName) {
            $normalizedCandidate = $this->normalizer->normalize($candidateName);
            if ($normalizedCandidate === '') {
                continue;
            }

            $candidate = $this->candidateConfidence($normalizedChannel, $normalizedCandidate);
            if ($candidate['confidence'] > $best['confidence']) {
                $best = $candidate;
            }
        }

        if ($best['confidence'] <= 0) {
            return $this->emptyMatchInsight();
        }

        $best['confidence'] = round($best['confidence'], 4);
        $best['confidence_band'] = $this->confidenceBand($best['confidence']);
        $best['repairable'] = $best['confidence'] >= $threshold;

        return $best;
    }

    private function candidateConfidence(string $normalizedChannel, string $normalizedCandidate): array
    {
        if ($normalizedChannel === $normalizedCandidate) {
            return [
                'confidence' => 1.0,
                'confidence_band' => 'exact',
                'match_reason' => 'exact_normalized_name',
                'repairable' => true,
            ];
        }

        $channelTokens = $this->tokenize($normalizedChannel);
        $candidateTokens = $this->tokenize($normalizedCandidate);

        $intersection = array_values(array_intersect($channelTokens, $candidateTokens));
        $union = array_values(array_unique([...$channelTokens, ...$candidateTokens]));
        $minTokenCount = max(1, min(count($channelTokens), count($candidateTokens)));

        $tokenOverlap = count($intersection) / $minTokenCount;
        $jaccard = count($union) > 0 ? count($intersection) / count($union) : 0.0;

        similar_text($normalizedChannel, $normalizedCandidate, $similarityPercent);
        $similarity = $similarityPercent / 100;

        $distance = levenshtein($normalizedChannel, $normalizedCandidate);
        $length = max(strlen($normalizedChannel), strlen($normalizedCandidate));
        $levenshteinScore = $length > 0 ? max(0, 1 - ($distance / $length)) : 0.0;

        $contains = str_contains($normalizedChannel, $normalizedCandidate) || str_contains($normalizedCandidate, $normalizedChannel);
        $score = ($levenshteinScore * 0.4) + ($similarity * 0.25) + ($tokenOverlap * 0.25) + ($jaccard * 0.1);

        if ($contains && $tokenOverlap >= 0.75) {
            $score = max($score, 0.88);
        }

        if ($tokenOverlap === 1.0 && count($channelTokens) === count($candidateTokens)) {
            $score = max($score, 0.93);
        }

        $channelNumbers = $this->numericTokens($channelTokens);
        $candidateNumbers = $this->numericTokens($candidateTokens);

        if ($channelNumbers !== [] && $candidateNumbers !== [] && array_values($channelNumbers) !== array_values($candidateNumbers)) {
            $score = max(0.0, $score - 0.18);
            $reason = 'number_mismatch_penalty';
        } elseif ($contains && $tokenOverlap >= 0.75) {
            $reason = 'normalized_subset_match';
        } elseif ($tokenOverlap >= 0.8) {
            $reason = 'strong_token_overlap';
        } elseif ($similarity >= 0.75 || $levenshteinScore >= 0.75) {
            $reason = 'fuzzy_name_match';
        } else {
            $reason = 'semantic_or_borderline_match';
        }

        return [
            'confidence' => min(1.0, round($score, 4)),
            'confidence_band' => 'low',
            'match_reason' => $reason,
            'repairable' => false,
        ];
    }

    private function confidenceBand(?float $confidence): string
    {
        if ($confidence === null) {
            return 'none';
        }

        return match (true) {
            $confidence >= 0.98 => 'exact',
            $confidence >= 0.85 => 'high',
            $confidence >= 0.7 => 'medium',
            default => 'low',
        };
    }

    private function tokenize(string $value): array
    {
        return array_values(array_filter(explode(' ', trim($value))));
    }

    private function numericTokens(array $tokens): array
    {
        return array_values(array_filter($tokens, fn (string $token): bool => preg_match('/^\d+$/', $token) === 1));
    }
}

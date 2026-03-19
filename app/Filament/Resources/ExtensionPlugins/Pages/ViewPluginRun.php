<?php

namespace App\Filament\Resources\ExtensionPlugins\Pages;

use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use App\Models\ExtensionPlugin;
use App\Models\ExtensionPluginRun;
use App\Plugins\PluginManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
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

    protected function getHeaderActions(): array
    {
        return [
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
}

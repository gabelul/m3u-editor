<?php

namespace App\Filament\Resources\ExtensionPlugins\Pages;

use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use App\Jobs\ExecutePluginInvocation;
use App\Models\ExtensionPlugin;
use App\Plugins\PluginManager;
use App\Plugins\PluginSchemaMapper;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditExtensionPlugin extends EditRecord
{
    protected static string $resource = ExtensionPluginResource::class;

    public function mount(int|string $record): void
    {
        app(PluginManager::class)->recoverStaleRuns();

        parent::mount($record);
    }

    public function getSubheading(): ?string
    {
        return 'Monitor this plugin, queue one-off jobs, and tune the defaults that automation will reuse.';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ExtensionPlugin $record */
        app(PluginManager::class)->updateSettings($record, $data['settings'] ?? []);

        return $record->fresh();
    }

    protected function getHeaderActions(): array
    {
        $record = $this->record;
        $actions = [
            Action::make('validate')
                ->label('Validate')
                ->icon('heroicon-o-shield-check')
                ->action(function () use ($record): void {
                    $plugin = app(PluginManager::class)->validate($record);

                    Notification::make()
                        ->title('Validation completed')
                        ->body($plugin->validation_status === 'valid'
                            ? 'Plugin manifest and class contract are valid.'
                            : implode("\n", $plugin->validation_errors ?? ['Plugin validation failed.']))
                        ->color($plugin->validation_status === 'valid' ? 'success' : 'danger')
                        ->send();

                    $this->refreshFormData(['validation_status', 'validation_errors_json']);
                }),
            Action::make('enable')
                ->label('Enable')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->hidden(fn () => $this->record->enabled || ! $this->record->isInstalled())
                ->disabled(fn () => $this->record->validation_status !== 'valid' || ! $this->record->available)
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['enabled' => true]);
                    Notification::make()->success()->title('Plugin enabled')->send();
                    $this->refreshFormData(['enabled']);
                }),
            Action::make('disable')
                ->label('Disable')
                ->icon('heroicon-o-x-circle')
                ->color('warning')
                ->hidden(fn () => ! $this->record->enabled || ! $this->record->isInstalled())
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['enabled' => false]);
                    Notification::make()->success()->title('Plugin disabled')->send();
                    $this->refreshFormData(['enabled']);
                }),
            Action::make('reinstall')
                ->label('Reinstall')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->hidden(fn () => $this->record->isInstalled())
                ->disabled(fn () => ! $this->record->available)
                ->requiresConfirmation()
                ->modalDescription('Reinstalling makes this plugin eligible to run again. Saved settings stay in place unless you previously purged plugin-owned data.')
                ->action(function () use ($record): void {
                    $plugin = app(PluginManager::class)->reinstall($record);

                    Notification::make()
                        ->success()
                        ->title('Plugin reinstalled')
                        ->body($plugin->validation_status === 'valid'
                            ? 'The plugin can be enabled again when you are ready.'
                            : 'The plugin was reinstalled, but validation still needs attention before it can run.')
                        ->send();

                    $this->refreshFormData(['installation_status', 'validation_status', 'validation_errors_json', 'uninstalled_at']);
                }),
            Action::make('uninstall')
                ->label('Uninstall Plugin')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->hidden(fn () => ! $this->record->isInstalled())
                ->requiresConfirmation()
                ->modalHeading('Uninstall plugin')
                ->modalDescription('Uninstalling disables the plugin immediately. Preserve keeps plugin-owned data for later reinstall. Purge deletes only the plugin-owned tables and storage paths declared in the manifest. If a run is still active, the system will request cancellation before any purge is allowed.')
                ->schema([
                    Select::make('cleanup_mode')
                        ->label('Data cleanup')
                        ->options([
                            'preserve' => 'Preserve plugin-owned data',
                            'purge' => 'Purge plugin-owned data',
                        ])
                        ->default(fn () => $record->defaultCleanupMode())
                        ->required()
                        ->helperText('Disable is reversible. Uninstall changes the lifecycle state and optionally purges plugin-owned tables, files, and report directories.'),
                ])
                ->action(function (array $data) use ($record): void {
                    try {
                        $plugin = app(PluginManager::class)->uninstall(
                            $record,
                            $data['cleanup_mode'] ?? 'preserve',
                            auth()->id(),
                        );

                        Notification::make()
                            ->success()
                            ->title('Plugin uninstalled')
                            ->body(($data['cleanup_mode'] ?? 'preserve') === 'purge'
                                ? 'The plugin was disabled and its declared plugin-owned data was purged.'
                                : 'The plugin was disabled and marked uninstalled. Plugin-owned data was preserved for a possible reinstall.')
                            ->send();

                        $this->refreshFormData(['enabled', 'installation_status', 'last_cleanup_mode', 'uninstalled_at']);
                    } catch (\RuntimeException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Uninstall blocked')
                            ->body($exception->getMessage())
                            ->send();
                    }
                }),
        ];

        foreach ($record->actions ?? [] as $pluginAction) {
            $actionId = $pluginAction['id'] ?? null;
            if (! $actionId || ($pluginAction['hidden'] ?? false)) {
                continue;
            }

            $actions[] = Action::make('plugin_action_'.$actionId)
                ->label($pluginAction['label'] ?? ucfirst($actionId))
                ->icon($pluginAction['icon'] ?? 'heroicon-o-play')
                ->color(($pluginAction['destructive'] ?? false) ? 'danger' : 'primary')
                ->disabled(fn () => ! $this->record->enabled || ! $this->record->isInstalled() || $this->record->validation_status !== 'valid')
                ->requiresConfirmation((bool) ($pluginAction['requires_confirmation'] ?? false))
                ->schema(app(PluginSchemaMapper::class)->actionComponents($record, $actionId))
                ->action(function (array $data) use ($record, $pluginAction, $actionId): void {
                    dispatch(new ExecutePluginInvocation(
                        pluginId: $record->id,
                        invocationType: 'action',
                        name: $actionId,
                        payload: $data,
                        options: [
                            'trigger' => 'manual',
                            'dry_run' => (bool) ($pluginAction['dry_run'] ?? false),
                            'user_id' => auth()->id(),
                        ],
                    ));

                    Notification::make()
                        ->success()
                        ->title(($pluginAction['label'] ?? ucfirst($actionId)).' queued')
                        ->body('The plugin action is running in the background. Watch the Live Activity and Run History tabs for progress and results.')
                        ->send();
                });
        }

        $actions[] = DeleteAction::make()
            ->label('Forget Registry Record')
            ->disabled(fn () => $this->record->hasActiveRuns())
            ->modalDescription('This deletes the registry row, saved plugin settings, and recorded run history. It does not uninstall the local plugin files and does not clean plugin-owned data. Discovery will register the plugin again if its folder still exists.')
            ->successRedirectUrl(ExtensionPluginResource::getUrl());

        return $actions;
    }
}

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
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditExtensionPlugin extends EditRecord
{
    protected static string $resource = ExtensionPluginResource::class;

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
                ->hidden(fn () => $this->record->enabled)
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
                ->hidden(fn () => ! $this->record->enabled)
                ->requiresConfirmation()
                ->action(function () use ($record): void {
                    $record->update(['enabled' => false]);
                    Notification::make()->success()->title('Plugin disabled')->send();
                    $this->refreshFormData(['enabled']);
                }),
        ];

        foreach ($record->actions ?? [] as $pluginAction) {
            $actionId = $pluginAction['id'] ?? null;
            if (! $actionId) {
                continue;
            }

            $actions[] = Action::make('plugin_action_'.$actionId)
                ->label($pluginAction['label'] ?? ucfirst($actionId))
                ->icon($pluginAction['icon'] ?? 'heroicon-o-play')
                ->color(($pluginAction['destructive'] ?? false) ? 'danger' : 'primary')
                ->disabled(fn () => ! $this->record->enabled || $this->record->validation_status !== 'valid')
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
            ->modalDescription('This removes the plugin record from the registry. The local plugin files are not deleted.')
            ->successRedirectUrl(ExtensionPluginResource::getUrl());

        return $actions;
    }
}

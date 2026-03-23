<?php

namespace App\Filament\Resources\ExtensionPlugins\Pages;

use App\Filament\Actions\PluginInstallActions;
use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use App\Plugins\PluginManager;
use Filament\Resources\Pages\ListRecords;

class ListExtensionPlugins extends ListRecords
{
    protected static string $resource = ExtensionPluginResource::class;

    public function mount(): void
    {
        parent::mount();

        app(PluginManager::class)->recoverStaleRuns();
    }

    protected function getHeaderActions(): array
    {
        return [
            PluginInstallActions::discover(),
            PluginInstallActions::pluginInstallsLink(),
        ];
    }
}

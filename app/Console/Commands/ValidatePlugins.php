<?php

namespace App\Console\Commands;

use App\Models\ExtensionPlugin;
use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class ValidatePlugins extends Command
{
    protected $signature = 'plugins:validate {pluginId?}';

    protected $description = 'Validate one plugin or all discovered plugins';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginId = $this->argument('pluginId');
        $pluginManager->discover();
        $plugins = ExtensionPlugin::query()
            ->when($pluginId, fn ($query) => $query->where('plugin_id', $pluginId))
            ->get();

        if ($plugins->isEmpty()) {
            $this->error('No matching plugins found.');

            return self::FAILURE;
        }

        foreach ($plugins as $plugin) {
            $plugin = $pluginManager->validate($plugin);
            $this->line("{$plugin->plugin_id}: {$plugin->validation_status}");
            foreach ($plugin->validation_errors ?? [] as $error) {
                $this->warn("  - {$error}");
            }
        }

        return self::SUCCESS;
    }
}

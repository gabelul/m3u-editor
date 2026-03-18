<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class DiscoverPlugins extends Command
{
    protected $signature = 'plugins:discover';

    protected $description = 'Discover trusted local plugins and sync them into the registry';

    public function handle(PluginManager $pluginManager): int
    {
        $plugins = $pluginManager->discover();

        $this->info('Discovered '.count($plugins).' plugin(s).');
        foreach ($plugins as $plugin) {
            $this->line("- {$plugin->plugin_id} [{$plugin->validation_status}]");
        }

        return self::SUCCESS;
    }
}

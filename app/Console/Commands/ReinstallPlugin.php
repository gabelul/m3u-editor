<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class ReinstallPlugin extends Command
{
    protected $signature = 'plugins:reinstall {pluginId}';

    protected $description = 'Reinstall a previously uninstalled plugin so it can be enabled again.';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginManager->discover();
        $plugin = $pluginManager->findPluginById((string) $this->argument('pluginId'));

        if (! $plugin) {
            $this->error('No matching plugin found.');

            return self::FAILURE;
        }

        $plugin = $pluginManager->reinstall($plugin);

        $this->info("Plugin [{$plugin->plugin_id}] reinstalled.");
        $this->line("Validation status: {$plugin->validation_status}");

        return self::SUCCESS;
    }
}

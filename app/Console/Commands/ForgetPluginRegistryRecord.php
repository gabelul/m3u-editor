<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use RuntimeException;

class ForgetPluginRegistryRecord extends Command
{
    protected $signature = 'plugins:forget {pluginId}';

    protected $description = 'Delete only the plugin registry row, saved settings, and run history. Local files and plugin-owned data are not touched.';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginManager->discover();
        $plugin = $pluginManager->findPluginById((string) $this->argument('pluginId'));

        if (! $plugin) {
            $this->error('No matching plugin found.');

            return self::FAILURE;
        }

        try {
            $pluginManager->forgetRegistryRecord($plugin);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin [{$plugin->plugin_id}] registry record deleted.");

        return self::SUCCESS;
    }
}

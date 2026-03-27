<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use RuntimeException;

class ForgetPluginRegistryRecord extends Command
{
    protected $signature = 'plugins:forget {pluginId}';

    protected $description = 'Remove a plugin from the registry, including its settings and run history. Local files stay on disk.';

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

<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use RuntimeException;

class UninstallPlugin extends Command
{
    protected $signature = 'plugins:uninstall {pluginId} {--cleanup= : preserve|purge}';

    protected $description = 'Mark a plugin uninstalled and optionally purge the plugin-owned data declared in its manifest.';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginId = (string) $this->argument('pluginId');
        $pluginManager->discover();

        $plugin = $pluginManager->findPluginById($pluginId);
        if (! $plugin) {
            $this->error("Plugin [{$pluginId}] was not found.");

            return self::FAILURE;
        }

        $cleanupMode = (string) ($this->option('cleanup') ?: $plugin->defaultCleanupMode());

        try {
            $plugin = $pluginManager->uninstall($plugin, $cleanupMode);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin [{$plugin->plugin_id}] uninstalled with cleanup mode [{$cleanupMode}].");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class PluginRegistryDoctor extends Command
{
    protected $signature = 'plugins:doctor';

    protected $description = 'Check plugin registry state, lifecycle integrity, and uninstall cleanup leftovers.';

    public function handle(PluginManager $pluginManager): int
    {
        $pluginManager->discover();
        $issues = $pluginManager->registryDiagnostics();

        if ($issues === []) {
            $this->info('Plugin registry looks healthy.');

            return self::SUCCESS;
        }

        foreach ($issues as $issue) {
            $prefix = match ($issue['level']) {
                'error' => 'ERROR',
                'warning' => 'WARN',
                default => 'INFO',
            };

            $this->line("[{$prefix}] {$issue['plugin_id']}: {$issue['message']}");
        }

        return collect($issues)->contains(fn (array $issue) => $issue['level'] === 'error')
            ? self::FAILURE
            : self::SUCCESS;
    }
}

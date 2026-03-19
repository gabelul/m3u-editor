<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class RecoverStalePluginRuns extends Command
{
    protected $signature = 'plugins:recover-stale-runs {--minutes=15 : Minutes without heartbeat before a run is marked stale}';

    protected $description = 'Mark running plugin invocations as stale when they have stopped sending heartbeats.';

    public function handle(PluginManager $pluginManager): int
    {
        $recovered = $pluginManager->recoverStaleRuns((int) $this->option('minutes'));

        $this->info("Recovered {$recovered} stale plugin run(s).");

        return self::SUCCESS;
    }
}

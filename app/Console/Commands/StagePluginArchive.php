<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class StagePluginArchive extends Command
{
    protected $signature = 'plugins:stage-archive {archive : Absolute or relative path to a plugin archive}';

    protected $description = 'Stage a plugin archive for reviewed install.';

    public function handle(PluginManager $pluginManager): int
    {
        $review = $pluginManager->stageArchiveReview((string) $this->argument('archive'), auth()->id());

        $this->info("Created install review #{$review->id} for plugin [{$review->plugin_id}]");
        $this->line("Status: {$review->status}");
        $this->line("Scan status: {$review->scan_status}");

        return self::SUCCESS;
    }
}

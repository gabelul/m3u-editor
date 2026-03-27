<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Throwable;

class ApprovePluginInstall extends Command
{
    protected $signature = 'plugins:approve-install {reviewId : Plugin install review id} {--trust : Trust the plugin immediately after install} {--notes= : Optional review note}';

    protected $description = 'Approve a reviewed plugin install and copy it into the managed plugin directory.';

    public function handle(PluginManager $pluginManager): int
    {
        $review = $pluginManager->findInstallReviewById((int) $this->argument('reviewId'));
        if (! $review) {
            $this->error('No matching install review found.');

            return self::FAILURE;
        }

        try {
            $review = $pluginManager->approveInstallReview(
                $review,
                (bool) $this->option('trust'),
                auth()->id(),
                $this->option('notes') ? (string) $this->option('notes') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Review #{$review->id} installed plugin [{$review->plugin_id}].");
        $this->line("Review status: {$review->status}");

        return self::SUCCESS;
    }
}

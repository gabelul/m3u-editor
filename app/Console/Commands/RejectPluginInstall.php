<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;

class RejectPluginInstall extends Command
{
    protected $signature = 'plugins:reject-install {reviewId : Plugin install review id} {--notes= : Optional rejection note}';

    protected $description = 'Reject a staged plugin install review.';

    public function handle(PluginManager $pluginManager): int
    {
        $review = $pluginManager->findInstallReviewById((int) $this->argument('reviewId'));
        if (! $review) {
            $this->error('No matching install review found.');

            return self::FAILURE;
        }

        $review = $pluginManager->rejectInstallReview(
            $review,
            auth()->id(),
            $this->option('notes') ? (string) $this->option('notes') : null,
        );

        $this->info("Review #{$review->id} marked as rejected.");

        return self::SUCCESS;
    }
}

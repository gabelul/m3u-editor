<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\ChannelTitleNormalizerService;
use Illuminate\Console\Command;

/**
 * Backfill the title_normalized column for existing channels.
 *
 * Runs the ChannelTitleNormalizerService on all channels that don't
 * yet have a normalized title. Useful after first deploying the
 * normalization feature, or to re-normalize all channels if the
 * normalization rules change.
 */
class NormalizeChannelTitles extends Command
{
    /**
     * @var string
     */
    protected $signature = 'app:normalize-channel-titles
        {--force : Re-normalize all channels, not just those missing a normalized title}
        {--playlist= : Only normalize channels from a specific playlist ID}';

    /**
     * @var string
     */
    protected $description = 'Backfill normalized titles for existing channels (strips provider junk for better EPG matching)';

    /**
     * Execute the console command.
     */
    public function handle(ChannelTitleNormalizerService $normalizer): int
    {
        $query = Channel::query();

        // Filter by playlist if specified
        if ($playlistId = $this->option('playlist')) {
            $query->where('playlist_id', $playlistId);
        }

        // Only process channels without normalized titles (unless --force)
        if (! $this->option('force')) {
            $query->whereNull('title_normalized');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('All channels already have normalized titles.');

            return self::SUCCESS;
        }

        $this->info("Normalizing {$total} channel titles...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;

        // Process in chunks to keep memory usage reasonable
        $query->chunkById(500, function ($channels) use ($normalizer, &$updated, $bar) {
            foreach ($channels as $channel) {
                $rawTitle = $channel->title ?? $channel->name;
                $normalized = $normalizer->normalize($rawTitle);

                if ($normalized !== $channel->title_normalized) {
                    $channel->updateQuietly(['title_normalized' => $normalized]);
                    $updated++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done! Updated {$updated} of {$total} channels.");

        return self::SUCCESS;
    }
}

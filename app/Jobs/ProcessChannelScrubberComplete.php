<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessChannelScrubberComplete implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public int $maxLogs = 15;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $scrubberId,
        public string $batchNo,
        public Carbon $start,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $scrubber = ChannelScrubber::find($this->scrubberId);
        if (! $scrubber || $scrubber->uuid !== $this->batchNo || $scrubber->status === Status::Cancelled) {
            return;
        }

        $runtime = round($this->start->diffInSeconds(now()), 2);
        $cacheKey = "channel_scrubber_dead_{$this->batchNo}";
        $deadChannels = Cache::pull($cacheKey, []);

        $scrubber->refresh();
        $deadCount = $scrubber->dead_count;
        $channelCount = $scrubber->channel_count;

        ChannelScrubberLog::create([
            'channel_scrubber_id' => $scrubber->id,
            'user_id' => $scrubber->user_id,
            'playlist_id' => $scrubber->playlist_id,
            'status' => 'completed',
            'channel_count' => $channelCount,
            'dead_count' => $deadCount,
            'disabled_count' => $deadCount,
            'runtime' => $runtime,
            'meta' => $deadChannels,
        ]);

        // Trim logs to max 15 entries
        $logsQuery = $scrubber->logs()->orderBy('created_at', 'asc');
        $logCount = $logsQuery->count();
        if ($logCount > $this->maxLogs) {
            $logsQuery->limit($logCount - $this->maxLogs)->delete();
        }

        $scrubber->update([
            'status' => Status::Completed,
            'sync_time' => $runtime,
            'progress' => 100,
            'processing' => false,
            'errors' => null,
        ]);

        Notification::make()
            ->success()
            ->title("Channel Scrubber \"{$scrubber->name}\" completed")
            ->body("Checked {$channelCount} channel(s), found {$deadCount} dead link(s). Completed in {$runtime} seconds.")
            ->broadcast($scrubber->user)
            ->sendToDatabase($scrubber->user);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Channel scrubber complete job failed: {$exception->getMessage()}");
    }
}

<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\ChannelScrubber;
use App\Models\ChannelScrubberLog;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessChannelScrubber implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public $timeout = 60 * 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $channelScrubberId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $start = now();
        $batchNo = Str::orderedUuid()->toString();

        $scrubber = ChannelScrubber::find($this->channelScrubberId);
        if (! $scrubber) {
            return;
        }

        $scrubber->update([
            'uuid' => $batchNo,
            'progress' => 0,
            'status' => Status::Processing,
            'processing' => true,
            'last_run_at' => now(),
            'errors' => null,
        ]);

        try {
            $playlist = $scrubber->playlist;
            if (! $playlist) {
                $error = 'No playlist associated with this scrubber.';
                Log::error("Channel scrubber #{$scrubber->id}: {$error}");
                $scrubber->update([
                    'status' => Status::Failed,
                    'errors' => $error,
                    'progress' => 100,
                    'processing' => false,
                ]);

                return;
            }

            $query = $playlist->channels()
                ->when(! $scrubber->include_vod, fn ($q) => $q->where('is_vod', false))
                ->when(! $scrubber->scan_all, fn ($q) => $q->where('enabled', true));

            $channelCount = $query->count();

            $scrubber->update([
                'channel_count' => $channelCount,
                'dead_count' => 0,
                'progress' => 3,
            ]);

            $log = ChannelScrubberLog::create([
                'channel_scrubber_id' => $scrubber->id,
                'user_id' => $scrubber->user_id,
                'playlist_id' => $scrubber->playlist_id,
                'status' => 'processing',
                'channel_count' => $channelCount,
            ]);

            $channelIds = $query->pluck('id')->toArray();
            $chunks = array_chunk($channelIds, 50);

            $jobs = [];
            foreach ($chunks as $chunk) {
                $jobs[] = new ProcessChannelScrubberChunk(
                    channelIds: $chunk,
                    scrubberId: $scrubber->id,
                    logId: $log->id,
                    checkMethod: $scrubber->check_method,
                    batchNo: $batchNo,
                    totalChannels: $channelCount,
                );
            }

            $jobs[] = new ProcessChannelScrubberComplete(
                scrubberId: $scrubber->id,
                logId: $log->id,
                batchNo: $batchNo,
                start: $start,
            );

            Bus::chain($jobs)
                ->onConnection('redis')
                ->onQueue('import')
                ->catch(function (Throwable $e) use ($scrubber) {
                    $error = "Error running scrubber \"{$scrubber->name}\": {$e->getMessage()}";
                    Notification::make()
                        ->danger()
                        ->title("Channel Scrubber \"{$scrubber->name}\" failed")
                        ->body('Please view your notifications for details.')
                        ->broadcast($scrubber->user);
                    Notification::make()
                        ->danger()
                        ->title("Channel Scrubber \"{$scrubber->name}\" failed")
                        ->body($error)
                        ->sendToDatabase($scrubber->user);
                    $scrubber->update([
                        'status' => Status::Failed,
                        'errors' => $error,
                        'progress' => 100,
                        'processing' => false,
                    ]);
                })->dispatch();
        } catch (Exception $e) {
            Log::error("Error processing channel scrubber #{$scrubber->id}: {$e->getMessage()}");

            Notification::make()
                ->danger()
                ->title("Channel Scrubber \"{$scrubber->name}\" failed")
                ->body('Please view your notifications for details.')
                ->broadcast($scrubber->user);
            Notification::make()
                ->danger()
                ->title("Channel Scrubber \"{$scrubber->name}\" failed")
                ->body($e->getMessage())
                ->sendToDatabase($scrubber->user);

            $scrubber->update([
                'status' => Status::Failed,
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Channel scrubber job failed: {$exception->getMessage()}");
    }
}

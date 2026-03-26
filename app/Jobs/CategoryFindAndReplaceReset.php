<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class CategoryFindAndReplaceReset implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $user_id,
        public ?Collection $categories = null,
        public ?int $playlist_id = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $start = now();
        $totalUpdated = 0;

        if (! $this->categories) {
            Category::query()
                ->where('user_id', $this->user_id)
                ->when($this->playlist_id, fn ($query) => $query->where('playlist_id', $this->playlist_id))
                ->whereColumn('name', '!=', 'name_internal')
                ->chunkById(1000, function ($categories) use (&$totalUpdated) {
                    $categoryIds = $categories->pluck('id')->toArray();

                    if (count($categoryIds) > 0) {
                        $updated = DB::table('categories')
                            ->whereIn('id', $categoryIds)
                            ->update([
                                'name' => DB::raw('name_internal'),
                                'updated_at' => now(),
                            ]);

                        $totalUpdated += $updated;
                    }
                });
        } else {
            $this->categories
                ->filter(fn ($category) => $category->name !== $category->name_internal)
                ->chunk(1000)
                ->each(function ($chunk) use (&$totalUpdated) {
                    $categoryIds = $chunk->pluck('id')->toArray();

                    if (count($categoryIds) > 0) {
                        $updated = DB::table('categories')
                            ->whereIn('id', $categoryIds)
                            ->update([
                                'name' => DB::raw('name_internal'),
                                'updated_at' => now(),
                            ]);

                        $totalUpdated += $updated;
                    }
                });
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->user_id);

        Notification::make()
            ->success()
            ->title('Find & Replace reset')
            ->body("Category find & replace reset has completed successfully. {$totalUpdated} categories updated.")
            ->broadcast($user);
        Notification::make()
            ->success()
            ->title('Find & Replace reset completed')
            ->body("Category find & replace reset has completed successfully. {$totalUpdated} categories updated in {$completedIn} seconds.")
            ->sendToDatabase($user);
    }
}

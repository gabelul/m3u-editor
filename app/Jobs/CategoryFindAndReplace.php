<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class CategoryFindAndReplace implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $user_id,
        public bool $use_regex,
        public string $find_replace,
        public string $replace_with,
        public ?Collection $categories = null,
        public ?int $playlist_id = null,
        public bool $silent = false,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $start = now();
        $updated = 0;

        if (! $this->categories) {
            Category::query()
                ->where('user_id', $this->user_id)
                ->when($this->playlist_id, fn ($query) => $query->where('playlist_id', $this->playlist_id))
                ->chunkById(1000, function ($categories) use (&$updated) {
                    $updated += $this->processCategoryChunk($categories);
                });
        } else {
            $this->categories
                ->chunk(1000)
                ->each(function ($chunk) use (&$updated) {
                    $updated += $this->processCategoryChunk($chunk);
                });
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->user_id);

        if (! $this->silent) {
            Notification::make()
                ->success()
                ->title('Find & Replace completed')
                ->body("Category find & replace has completed successfully. {$updated} categories updated.")
                ->broadcast($user);
            Notification::make()
                ->success()
                ->title('Find & Replace completed')
                ->body("Category find & replace has completed successfully. Operation completed in {$completedIn} seconds and updated {$updated} categories.")
                ->sendToDatabase($user);
        }
    }

    /**
     * Process a chunk of categories and perform find/replace operations.
     */
    private function processCategoryChunk($categories): int
    {
        $updatesMap = [];
        $find = $this->find_replace;
        $replace = $this->replace_with;

        foreach ($categories as $category) {
            $valueToModify = $category->name;

            if (empty($valueToModify)) {
                continue;
            }

            if ($this->use_regex) {
                $delimiter = '/';
                $pattern = str_replace($delimiter, '\\'.$delimiter, $find);
                $finalPattern = $delimiter.$pattern.$delimiter.'ui';

                if (! preg_match($finalPattern, $valueToModify)) {
                    continue;
                }

                $newValue = preg_replace($finalPattern, $replace, $valueToModify);
            } else {
                if (! stristr($valueToModify, $find)) {
                    continue;
                }

                $newValue = str_ireplace($find, $replace, $valueToModify);
            }

            if ($newValue && $newValue !== $valueToModify) {
                $updatesMap[$category->id] = $newValue;
            }
        }

        if (! empty($updatesMap)) {
            return $this->performBatchUpdate($updatesMap);
        }

        return 0;
    }

    /**
     * Perform batch update using CASE/WHEN SQL.
     */
    private function performBatchUpdate(array $updatesMap): int
    {
        $ids = array_keys($updatesMap);
        $cases = [];

        foreach ($updatesMap as $id => $value) {
            $cases[] = "WHEN {$id} THEN ".DB::connection()->getPdo()->quote($value);
        }

        $caseStatement = implode(' ', $cases);

        DB::statement("
            UPDATE categories
            SET name = CASE id {$caseStatement} END,
                updated_at = ?
            WHERE id IN (".implode(',', $ids).')
        ', [now()]);

        return count($updatesMap);
    }
}

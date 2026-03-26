<?php

namespace App\Jobs;

use App\Models\Group;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GroupFindAndReplace implements ShouldQueue
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
        public ?Collection $groups = null,
        public ?int $playlist_id = null,
        public bool $silent = false,
        public ?string $group_type = null,
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

        if (! $this->groups) {
            Group::query()
                ->where('user_id', $this->user_id)
                ->when($this->playlist_id, fn ($query) => $query->where('playlist_id', $this->playlist_id))
                ->when($this->group_type, fn ($query) => $query->where('type', $this->group_type))
                ->chunkById(1000, function ($groups) use (&$updated) {
                    $updated += $this->processGroupChunk($groups);
                });
        } else {
            $this->groups
                ->chunk(1000)
                ->each(function ($chunk) use (&$updated) {
                    $updated += $this->processGroupChunk($chunk);
                });
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->user_id);

        if (! $this->silent) {
            Notification::make()
                ->success()
                ->title('Find & Replace completed')
                ->body("Group find & replace has completed successfully. {$updated} groups updated.")
                ->broadcast($user);
            Notification::make()
                ->success()
                ->title('Find & Replace completed')
                ->body("Group find & replace has completed successfully. Operation completed in {$completedIn} seconds and updated {$updated} groups.")
                ->sendToDatabase($user);
        }
    }

    /**
     * Process a chunk of groups and perform find/replace operations.
     */
    private function processGroupChunk($groups): int
    {
        $updatesMap = [];
        $find = $this->find_replace;
        $replace = $this->replace_with;

        foreach ($groups as $group) {
            $valueToModify = $group->name;

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
                $updatesMap[$group->id] = $newValue;
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
            UPDATE groups
            SET name = CASE id {$caseStatement} END,
                updated_at = ?
            WHERE id IN (".implode(',', $ids).')
        ', [now()]);

        return count($updatesMap);
    }
}

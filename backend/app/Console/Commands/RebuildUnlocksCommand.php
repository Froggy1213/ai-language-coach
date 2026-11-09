<?php

namespace App\Console\Commands;

use App\Enums\RoadmapStatus;
use App\Grammar\LessonUnlocks;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RebuildUnlocksCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'roadmap:rebuild-unlocks
                            {--dry-run : Show which cards would be unlocked without saving}
                            {--user= : Rebuild only for the given user ID or email}';

    /**
     * @var string
     */
    protected $description = 'Recalculate lesson card unlocks across active roadmaps using the grammar DAG and recorded mistakes';

    public function handle(LessonUnlocks $unlocks): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $identifier = $this->option('user');

        $query = Roadmap::query()
            ->with('user')
            ->where('status', RoadmapStatus::Active);

        if (is_string($identifier) && $identifier !== '') {
            $user = User::query()
                ->when(
                    filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false,
                    static fn (Builder $q): Builder => $q->where('email', $identifier),
                    static fn (Builder $q): Builder => $q->whereKey($identifier),
                )
                ->first();

            if ($user === null) {
                $this->components->error("No user matches `{$identifier}`.");

                return self::FAILURE;
            }

            $query->where('user_id', $user->getKey());
        }

        $roadmaps = $query->get();
        $totalUnlocked = 0;

        foreach ($roadmaps as $roadmap) {
            $unlocked = DB::transaction(function () use ($roadmap, $unlocks, $dryRun): int {
                $mistakePointIds = $unlocks->mistakeGrammarPointIdsForUser(
                    $roadmap->user_id,
                    $roadmap->user?->target_language,
                );

                return $unlocks->syncRoadmap(
                    $roadmap,
                    forceReadyGrammarPointIds: $mistakePointIds,
                    dryRun: $dryRun,
                );
            });

            $totalUnlocked += $unlocked;
            $email = $roadmap->user?->email ?? "user#{$roadmap->user_id}";
            $prefix = $dryRun ? '[dry-run] ' : '';

            $this->line("{$prefix}Roadmap #{$roadmap->id} ({$email}): {$unlocked} card(s) unlocked.");
        }

        $mode = $dryRun ? 'would be unlocked' : 'unlocked';
        $this->components->info("Processed {$roadmaps->count()} active roadmap(s); {$totalUnlocked} card(s) {$mode}.");

        return self::SUCCESS;
    }
}

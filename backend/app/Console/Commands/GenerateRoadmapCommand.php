<?php

namespace App\Console\Commands;

use App\Grammar\RoadmapGenerator;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class GenerateRoadmapCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'roadmap:generate
                            {user : User id or email address}
                            {--regenerate : Archive the active roadmap and build a new one}';

    /**
     * @var string
     */
    protected $description = 'Generate the roadmap and lesson cards for a user';

    public function handle(RoadmapGenerator $generator): int
    {
        $identifier = (string) $this->argument('user');

        $user = User::query()
            ->when(
                filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false,
                static fn (Builder $query): Builder => $query->where('email', $identifier),
                static fn (Builder $query): Builder => $query->whereKey($identifier),
            )
            ->first();

        if ($user === null) {
            $this->components->error("No user matches `{$identifier}`.");

            return self::FAILURE;
        }

        $roadmap = $this->option('regenerate')
            ? $generator->regenerate($user)
            : $generator->generate($user);

        $this->components->info("{$roadmap->title} — {$roadmap->lessonCards->count()} lesson cards for {$user->email}.");

        $this->table(
            ['#', 'Grammar point', 'Status'],
            $roadmap->lessonCards->map(fn ($card): array => [
                $card->order_index,
                $card->grammarPoint->title,
                $card->status->value,
            ])->all(),
        );

        return self::SUCCESS;
    }
}

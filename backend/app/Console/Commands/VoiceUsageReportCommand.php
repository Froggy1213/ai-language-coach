<?php

namespace App\Console\Commands;

use App\Models\VoiceSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Voice minutes per learner (plan §7).
 *
 * Paired with the AWS Budget alert in the infrastructure: the budget says how
 * much the account spent, this says whose calls it was, which is the question an
 * operator actually has when the alert fires.
 */
class VoiceUsageReportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'voice:usage-report
                            {--days=30 : Length of the reporting window, in days}
                            {--json : Print the report as JSON instead of a table}';

    /**
     * @var string
     */
    protected $description = 'Report voice minutes per learner against the monthly budget';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = CarbonImmutable::now('UTC')->subDays($days);

        $monthlyBudget = (int) config('observability.voice_minutes.monthly_budget_per_user');

        // The budget is monthly, so a shorter window is compared against its
        // share of it — otherwise a seven-day report would flag every regular
        // learner as over budget.
        $windowBudget = (int) round($monthlyBudget * $days / 30);

        $rows = VoiceSession::query()
            ->join('users', 'users.id', '=', 'voice_sessions.user_id')
            ->where('voice_sessions.created_at', '>=', $since)
            ->groupBy('voice_sessions.user_id', 'users.email')
            ->orderByDesc('seconds')
            ->get([
                'voice_sessions.user_id',
                'users.email',
                DB::raw('COUNT(*) as sessions'),
                DB::raw('COALESCE(SUM(voice_sessions.duration_sec), 0) as seconds'),
            ]);

        $report = $rows->map(static function (object $row) use ($windowBudget): array {
            $minutes = round(((int) $row->seconds) / 60, 1);

            return [
                'user_id' => (int) $row->user_id,
                'email' => (string) $row->email,
                'sessions' => (int) $row->sessions,
                'minutes' => $minutes,
                'over_budget' => $minutes > $windowBudget,
            ];
        })->all();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'window_days' => $days,
                'window_start' => $since->toIso8601ZuluString(),
                'window_minutes_budget' => $windowBudget,
                'users' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Voice usage for the last %d day(s); %d minutes per learner in this window.',
            $days,
            $windowBudget,
        ));

        if ($report === []) {
            $this->components->warn('No voice sessions in this window.');

            return self::SUCCESS;
        }

        $this->table(
            ['User', 'Email', 'Sessions', 'Minutes', 'Over budget'],
            array_map(static fn (array $row): array => [
                $row['user_id'],
                $row['email'],
                $row['sessions'],
                $row['minutes'],
                $row['over_budget'] ? 'yes' : 'no',
            ], $report),
        );

        $overBudget = count(array_filter($report, static fn (array $row): bool => $row['over_budget']));

        if ($overBudget > 0) {
            $this->components->warn(sprintf(
                '%d learner(s) above the voice-minutes budget — check the AWS Budget alert against these accounts.',
                $overBudget,
            ));
        }

        return self::SUCCESS;
    }
}

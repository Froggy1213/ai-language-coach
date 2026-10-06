<?php

namespace App\Console\Commands;

use App\Models\VoiceSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

final class PurgeTranscripts extends Command
{
    protected $signature = 'privacy:purge-transcripts {--dry-run : Simulate the purge without modifying the database}';

    protected $description = 'Purge voice session transcripts older than the retention policy window';

    public function handle(): int
    {
        $retentionDays = (int) config('privacy.transcript_retention_days', 90);
        $cutoff = Date::now()->subDays($retentionDays);
        $isDryRun = (bool) $this->option('dry-run');

        $query = VoiceSession::query()
            ->whereNotNull('transcript')
            ->where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($isDryRun) {
            $this->info("[Dry Run] Would purge transcripts from {$count} voice session(s) older than {$retentionDays} days.");

            return self::SUCCESS;
        }

        // We keep the voice_sessions row, its duration_sec, status, and associated
        // mistakes / review_items. They are the learning record the learner paid for.
        $purged = $query->update(['transcript' => null]);

        $this->info("Purged transcripts from {$purged} voice session(s) older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}

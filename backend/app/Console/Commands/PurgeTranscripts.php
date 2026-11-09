<?php

namespace App\Console\Commands;

use App\Models\VoiceSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class PurgeTranscripts extends Command
{
    protected $signature = 'privacy:purge-transcripts {--dry-run : Simulate the purge without modifying the database}';

    protected $description = 'Purge voice session and assessment transcripts older than the retention policy window';

    public function handle(): int
    {
        $retentionDays = (int) config('privacy.transcript_retention_days', 90);
        $cutoff = Date::now()->subDays($retentionDays);
        $isDryRun = (bool) $this->option('dry-run');

        $voiceQuery = VoiceSession::query()
            ->whereNotNull('transcript')
            ->where('created_at', '<', $cutoff);

        // The onboarding assessment keeps its transcript in raw_data, so the same
        // retention window has to reach that column too. JSON_CONTAINS_PATH keeps
        // the count to rows that actually hold one; JSON_REMOVE keeps the rest of
        // the analysis (level, pronunciation evidence) intact.
        $assessmentQuery = DB::table('assessments')
            ->whereNotNull('raw_data')
            ->where('created_at', '<', $cutoff)
            ->whereRaw("JSON_CONTAINS_PATH(raw_data, 'one', '$.transcript')");

        $voiceCount = (clone $voiceQuery)->count();
        $assessmentCount = (clone $assessmentQuery)->count();

        if ($isDryRun) {
            $this->info("[Dry Run] Would purge transcripts from {$voiceCount} voice session(s) and {$assessmentCount} assessment(s) older than {$retentionDays} days.");

            return self::SUCCESS;
        }

        // We keep the voice_sessions row, its duration_sec, status, and associated
        // mistakes / review_items. They are the learning record the learner paid for.
        $voicePurged = $voiceQuery->update(['transcript' => null]);
        $assessmentPurged = $assessmentQuery->update([
            'raw_data' => DB::raw("JSON_REMOVE(raw_data, '$.transcript')"),
        ]);

        $this->info("Purged transcripts from {$voicePurged} voice session(s) and {$assessmentPurged} assessment(s) older than {$retentionDays} days.");

        return self::SUCCESS;
    }
}

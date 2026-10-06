<?php

namespace Tests\Feature\Privacy;

use App\Enums\AssessmentStatus;
use App\Enums\VoiceSessionStatus;
use App\Models\Assessment;
use App\Models\GrammarPoint;
use App\Models\LessonCard;
use App\Models\Mistake;
use App\Models\ReviewItem;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Mockery;
use Tests\TestCase;

class RetentionPurgeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'privacy.transcript_retention_days' => 90,
            'privacy.orphan_audio_retention_days' => 7,
            'filesystems.disks.s3.bucket' => 'coach-audio',
            'assessments.key_prefix' => 'assessments',
        ]);
    }

    public function test_purge_transcripts_clears_old_transcripts_while_preserving_session_records_and_mistakes(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $roadmap1 = Roadmap::factory()->for($user1)->create();
        $roadmap2 = Roadmap::factory()->for($user2)->create();

        $card1 = LessonCard::factory()->for($roadmap1)->create();
        $card2 = LessonCard::factory()->for($roadmap2)->create();

        $grammarPoint = GrammarPoint::factory()->create();

        // 1. Old session for user1 (100 days old)
        $oldSession = VoiceSession::factory()->for($user1)->for($card1)->create([
            'status' => VoiceSessionStatus::Completed,
            'duration_sec' => 300,
            'transcript' => [
                ['speaker' => 'learner', 'text' => 'Hello there'],
            ],
            'created_at' => Date::now()->subDays(100),
        ]);

        $mistake = Mistake::factory()->for($user1)->for($grammarPoint)->create([
            'session_id' => $oldSession->id,
            'user_utterance' => 'Hello there',
            'correction' => 'Hello there!',
            'explanation' => 'Punctuation test',
        ]);

        $reviewItem = ReviewItem::factory()->for($user1)->for($grammarPoint)->create();

        // 2. Recent session for user2 (30 days old)
        $recentSession = VoiceSession::factory()->for($user2)->for($card2)->create([
            'status' => VoiceSessionStatus::Completed,
            'duration_sec' => 250,
            'transcript' => [
                ['speaker' => 'learner', 'text' => 'Recent utterance'],
            ],
            'created_at' => Date::now()->subDays(30),
        ]);

        // Run command
        $this->artisan('privacy:purge-transcripts')
            ->expectsOutputToContain('Purged transcripts from 1 voice session(s) older than 90 days.')
            ->assertSuccessful();

        $oldSession->refresh();
        $recentSession->refresh();

        // Old session transcript is cleared
        $this->assertNull($oldSession->transcript);
        // Session row metadata is preserved
        $this->assertSame(VoiceSessionStatus::Completed, $oldSession->status);
        $this->assertSame(300, $oldSession->duration_sec);
        // Learning records are preserved
        $this->assertDatabaseHas('mistakes', ['id' => $mistake->id]);
        $this->assertDatabaseHas('review_items', ['id' => $reviewItem->id]);

        // Recent session is completely untouched
        $this->assertNotNull($recentSession->transcript);
        $this->assertSame('Recent utterance', $recentSession->transcript[0]['text']);

        // Idempotency check: running again purges 0 and succeeds
        $this->artisan('privacy:purge-transcripts')
            ->expectsOutputToContain('Purged transcripts from 0 voice session(s) older than 90 days.')
            ->assertSuccessful();
    }

    public function test_purge_transcripts_dry_run_does_not_modify_database(): void
    {
        $user = User::factory()->create();
        $roadmap = Roadmap::factory()->for($user)->create();
        $card = LessonCard::factory()->for($roadmap)->create();

        $session = VoiceSession::factory()->for($user)->for($card)->create([
            'transcript' => [['speaker' => 'learner', 'text' => 'Will survive dry run']],
            'created_at' => Date::now()->subDays(120),
        ]);

        $this->artisan('privacy:purge-transcripts', ['--dry-run' => true])
            ->expectsOutputToContain('[Dry Run] Would purge transcripts from 1 voice session(s) older than 90 days.')
            ->assertSuccessful();

        $session->refresh();
        $this->assertNotNull($session->transcript);
        $this->assertSame('Will survive dry run', $session->transcript[0]['text']);
    }

    public function test_purge_orphan_audio_deletes_old_orphaned_s3_recordings(): void
    {
        $user = User::factory()->create();

        $oldOrphanKey = "assessments/{$user->id}/old-orphan.webm";
        $recentKey = "assessments/{$user->id}/recent-upload.webm";
        $processingKey = "assessments/{$user->id}/active-processing.webm";

        // Assessment row in processing for processingKey
        Assessment::factory()->for($user)->create([
            'status' => AssessmentStatus::Processing,
            'audio_url' => "http://localhost:9000/coach-audio/{$processingKey}",
        ]);

        // Mock S3Client
        $s3Mock = Mockery::mock(S3Client::class);

        $listResults = [
            [
                'Contents' => [
                    [
                        'Key' => $oldOrphanKey,
                        'LastModified' => Carbon::now()->subDays(10)->toIso8601String(),
                    ],
                    [
                        'Key' => $recentKey,
                        'LastModified' => Carbon::now()->subDays(2)->toIso8601String(),
                    ],
                    [
                        'Key' => $processingKey,
                        'LastModified' => Carbon::now()->subDays(10)->toIso8601String(),
                    ],
                ],
            ],
        ];

        $s3Mock->shouldReceive('getPaginator')
            ->once()
            ->with('ListObjectsV2', [
                'Bucket' => 'coach-audio',
                'Prefix' => 'assessments/',
            ])
            ->andReturn(new \ArrayIterator($listResults));

        // Only the old orphan without processing assessment should be deleted
        $s3Mock->shouldReceive('deleteObject')
            ->once()
            ->with([
                'Bucket' => 'coach-audio',
                'Key' => $oldOrphanKey,
            ])
            ->andReturn(new Result);

        $this->app->instance(S3Client::class, $s3Mock);

        $this->artisan('privacy:purge-orphan-audio')
            ->expectsOutputToContain('Purged 1 orphan audio recording(s) older than 7 days.')
            ->assertSuccessful();
    }

    public function test_purge_orphan_audio_dry_run_does_not_delete_s3_objects(): void
    {
        $user = User::factory()->create();
        $oldOrphanKey = "assessments/{$user->id}/orphan-dry-run.webm";

        $s3Mock = Mockery::mock(S3Client::class);

        $listResults = [
            [
                'Contents' => [
                    [
                        'Key' => $oldOrphanKey,
                        'LastModified' => Carbon::now()->subDays(15)->toIso8601String(),
                    ],
                ],
            ],
        ];

        $s3Mock->shouldReceive('getPaginator')
            ->once()
            ->with('ListObjectsV2', [
                'Bucket' => 'coach-audio',
                'Prefix' => 'assessments/',
            ])
            ->andReturn(new \ArrayIterator($listResults));

        // deleteObject must NOT be called on dry run
        $s3Mock->shouldNotReceive('deleteObject');

        $this->app->instance(S3Client::class, $s3Mock);

        $this->artisan('privacy:purge-orphan-audio', ['--dry-run' => true])
            ->expectsOutputToContain('[Dry Run] Would purge 1 orphan audio recording(s) older than 7 days.')
            ->assertSuccessful();
    }
}

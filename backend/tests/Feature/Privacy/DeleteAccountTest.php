<?php

namespace Tests\Feature\Privacy;

use App\Enums\AssessmentStatus;
use App\Enums\LessonCardStatus;
use App\Enums\VoiceSessionStatus;
use App\Models\Assessment;
use App\Models\GrammarPoint;
use App\Models\LessonCard;
use App\Models\Mistake;
use App\Models\ReviewItem;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\VoiceSession;
use Aws\CommandInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Nuwave\Lighthouse\Subscriptions\Contracts\StoresSubscriptions;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private const SPA_HEADERS = [
        'Origin' => 'http://localhost:3000',
        'X-Socket-ID' => '9999.8888',
    ];

    private const DELETE_ACCOUNT = /** @lang GraphQL */ '
        mutation ($password: String!, $confirmation: String!) {
            deleteAccount(password: $password, confirmation: $confirmation)
        }
    ';

    private const SUBSCRIBE_ASSESSMENT = /** @lang GraphQL */ '
        subscription ($userId: ID!) {
            assessmentReady(userId: $userId) {
                id
                status
            }
        }
    ';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.s3.bucket' => 'coach-audio',
            'assessments.key_prefix' => 'assessments',
        ]);

        $redis = Redis::connection(config('lighthouse.subscriptions.broadcasters.echo.connection', 'default'));
        $prefix = (string) config('database.redis.options.prefix', '');

        foreach ($redis->keys('*graphql.*') as $key) {
            $unprefixed = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
            $redis->del($unprefixed);
        }
    }

    public function test_guests_cannot_delete_account(): void
    {
        $this->graphQL(self::DELETE_ACCOUNT, [
            'password' => 'secret',
            'confirmation' => 'DELETE',
        ])->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_confirmation_must_literally_equal_delete(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::DELETE_ACCOUNT, [
            'password' => 'password123',
            'confirmation' => 'delete', // lowercase
        ]);

        $this->assertSame(
            'CONFIRMATION_REQUIRED',
            $response->json('errors.0.extensions.code'),
        );
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_wrong_password_returns_password_mismatch(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        Sanctum::actingAs($user);

        $response = $this->graphQL(self::DELETE_ACCOUNT, [
            'password' => 'wrong-password',
            'confirmation' => 'DELETE',
        ]);

        $this->assertSame(
            'PASSWORD_MISMATCH',
            $response->json('errors.0.extensions.code'),
        );
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_it_clears_genuine_lighthouse_subscribers_before_deleting_user_row(): void
    {
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser);
        $otherSubscribeResponse = $this->graphQL(
            self::SUBSCRIBE_ASSESSMENT,
            ['userId' => (string) $otherUser->id],
            [],
            self::SPA_HEADERS,
        )->assertGraphQLErrorFree();
        $otherChannel = $otherSubscribeResponse->json('extensions.lighthouse_subscriptions.channel');
        $this->assertNotNull($otherChannel);

        $user = User::factory()->create(['password' => 'secret123']);
        Sanctum::actingAs($user);

        // 1. Create a genuine subscriber in Redis as AssessmentReadyChannelTest does
        $subscribeResponse = $this->graphQL(
            self::SUBSCRIBE_ASSESSMENT,
            ['userId' => (string) $user->id],
            [],
            self::SPA_HEADERS,
        )->assertGraphQLErrorFree();

        $channel = $subscribeResponse->json('extensions.lighthouse_subscriptions.channel');
        $this->assertNotNull($channel);

        /** @var StoresSubscriptions $storage */
        $storage = $this->app->make(StoresSubscriptions::class);
        $subscriber = $storage->subscriberByChannel($channel);
        $this->assertNotNull($subscriber);
        $this->assertNotNull($storage->subscriberByChannel($otherChannel));

        // 2. Set up S3 client mock
        $s3Mock = Mockery::mock(S3Client::class);
        $s3Mock->shouldReceive('deleteMatchingObjects')
            ->once()
            ->with('coach-audio', "assessments/{$user->id}/");
        $this->app->instance(S3Client::class, $s3Mock);

        // 3. Delete account
        $deleteResponse = $this->graphQL(self::DELETE_ACCOUNT, [
            'password' => 'secret123',
            'confirmation' => 'DELETE',
        ]);
        $deleteResponse->assertGraphQLErrorFree();
        $this->assertTrue($deleteResponse->json('data.deleteAccount'));

        // 4. Assert subscriber is GONE from Redis while other user's subscriber survives
        $this->assertNull($storage->subscriberByChannel($channel));
        $this->assertNotNull($storage->subscriberByChannel($otherChannel));

        // 5. Assert user row is GONE from database while other user remains
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $otherUser->id]);
    }

    public function test_fk_on_delete_cascade_removes_all_associated_records(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        Sanctum::actingAs($user);

        $grammarPoint = GrammarPoint::factory()->create();

        $roadmap = Roadmap::factory()->for($user)->create();
        $card = LessonCard::factory()->for($roadmap)->for($grammarPoint)->create(['status' => LessonCardStatus::Ready]);
        $assessment = Assessment::factory()->for($user)->create(['status' => AssessmentStatus::Done]);
        $voiceSession = VoiceSession::factory()->for($user)->for($card)->create(['status' => VoiceSessionStatus::Completed]);
        $mistake = Mistake::factory()->for($user)->for($grammarPoint)->create(['session_id' => $voiceSession->id]);
        $reviewItem = ReviewItem::factory()->for($user)->for($grammarPoint)->create();

        $s3Mock = Mockery::mock(S3Client::class);
        $s3Mock->shouldReceive('deleteMatchingObjects')->once();
        $this->app->instance(S3Client::class, $s3Mock);

        $this->graphQL(self::DELETE_ACCOUNT, [
            'password' => 'secret123',
            'confirmation' => 'DELETE',
        ])->assertGraphQLErrorFree();

        // Verify FK cascades
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('roadmaps', ['id' => $roadmap->id]);
        $this->assertDatabaseMissing('lesson_cards', ['id' => $card->id]);
        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
        $this->assertDatabaseMissing('voice_sessions', ['id' => $voiceSession->id]);
        $this->assertDatabaseMissing('mistakes', ['id' => $mistake->id]);
        $this->assertDatabaseMissing('review_items', ['id' => $reviewItem->id]);

        // Grammar points must survive because they are shared reference data
        $this->assertDatabaseHas('grammar_points', ['id' => $grammarPoint->id]);
    }

    public function test_an_s3_failure_is_reported_and_does_not_resurrect_the_user_row(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        Sanctum::actingAs($user);

        $s3Mock = Mockery::mock(S3Client::class);
        $s3Mock->shouldReceive('deleteMatchingObjects')
            ->once()
            ->andThrow(new S3Exception('S3 delete failure', Mockery::mock(CommandInterface::class)));
        $this->app->instance(S3Client::class, $s3Mock);

        $response = $this->graphQL(self::DELETE_ACCOUNT, [
            'password' => 'secret123',
            'confirmation' => 'DELETE',
        ]);
        $response->assertGraphQLErrorFree();

        // User row is permanently deleted despite S3 exception
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_operator_command_deletes_user_by_email(): void
    {
        $user = User::factory()->create(['email' => 'operator-test@example.com']);
        $roadmap = Roadmap::factory()->for($user)->create();

        $s3Mock = Mockery::mock(S3Client::class);
        $s3Mock->shouldReceive('deleteMatchingObjects')->once();
        $this->app->instance(S3Client::class, $s3Mock);

        $this->artisan('privacy:delete-user', ['email' => 'operator-test@example.com'])
            ->expectsOutputToContain('was permanently deleted')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('roadmaps', ['id' => $roadmap->id]);
    }

    public function test_operator_command_fails_for_unknown_email(): void
    {
        $this->artisan('privacy:delete-user', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('User with email `nobody@example.com` not found.')
            ->assertFailed();
    }
}

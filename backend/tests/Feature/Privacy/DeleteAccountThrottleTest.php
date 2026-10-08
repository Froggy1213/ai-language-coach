<?php

namespace Tests\Feature\Privacy;

use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class DeleteAccountThrottleTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.s3.bucket' => 'coach-audio',
            'assessments.key_prefix' => 'assessments',
        ]);
    }

    public function test_sixth_delete_account_attempt_within_one_minute_with_wrong_password_is_rejected_by_rate_limiter(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        Sanctum::actingAs($user);

        $attemptDelete = fn () => $this->graphQL(self::DELETE_ACCOUNT, [
            'password' => 'wrong-password',
            'confirmation' => 'DELETE',
        ], [], self::SPA_HEADERS);

        $limited = fn ($response): bool => str_contains(
            (string) $response->json('errors.0.message'),
            'Rate limit',
        );

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = $attemptDelete();
            $this->assertFalse($limited($response), "attempt {$attempt} must not be rate limited");
            $this->assertSame(
                'PASSWORD_MISMATCH',
                $response->json('errors.0.extensions.code'),
                "attempt {$attempt} must fail with PASSWORD_MISMATCH",
            );
        }

        $sixthResponse = $attemptDelete();
        $this->assertTrue($limited($sixthResponse), 'the 6th attempt must be rejected by the rate limiter');
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_correct_password_delete_account_within_rate_limit_succeeds(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);
        Sanctum::actingAs($user);

        $s3Mock = Mockery::mock(S3Client::class);
        $s3Mock->shouldReceive('deleteMatchingObjects')->once();
        $this->app->instance(S3Client::class, $s3Mock);

        $response = $this->graphQL(self::DELETE_ACCOUNT, [
            'password' => 'correct-password',
            'confirmation' => 'DELETE',
        ], [], self::SPA_HEADERS);

        $response->assertGraphQLErrorFree();
        $this->assertTrue($response->json('data.deleteAccount'));
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}

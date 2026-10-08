<?php

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    /** Inside the CIDR phpunit.xml puts in TRUSTED_PROXIES (the "ALB"). */
    private const PROXY = '10.0.1.5';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', fn () => response()->json(['ip' => request()->ip()]));
    }

    public function test_forwarded_for_is_honoured_from_a_trusted_proxy(): void
    {
        $response = $this->call('GET', '/_test/ip', server: [
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        $this->assertSame('203.0.113.7', $response->json('ip'));
    }

    public function test_forwarded_for_is_ignored_from_an_untrusted_peer(): void
    {
        $response = $this->call('GET', '/_test/ip', server: [
            'REMOTE_ADDR' => '198.51.100.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
        ]);

        $this->assertSame('198.51.100.9', $response->json('ip'));
    }

    public function test_login_throttle_is_counted_per_client_ip_behind_the_proxy(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);
        $login = fn (string $clientIp) => $this->call(
            'POST',
            '/graphql',
            server: [
                'REMOTE_ADDR' => self::PROXY,
                'HTTP_X_FORWARDED_FOR' => $clientIp,
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'http://localhost:3000',
            ],
            content: json_encode(['query' => 'mutation { login(email: "'.$user->email.'", password: "wrong") { id } }']),
        );

        $limited = fn (string $clientIp): bool => str_contains(
            (string) $login($clientIp)->json('errors.0.message'),
            'Rate limit',
        );

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->assertFalse($limited('203.0.113.7'), "attempt {$attempt} must not be limited");
        }
        $this->assertTrue($limited('203.0.113.7'), 'the 11th attempt from one client is limited');

        // A different client behind the same proxy has its own, untouched budget.
        $this->assertFalse($limited('203.0.113.8'));
    }
}

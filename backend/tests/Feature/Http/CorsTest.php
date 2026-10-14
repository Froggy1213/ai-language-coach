<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class CorsTest extends TestCase
{
    private const PATHS = ['/graphql', '/graphql/subscriptions/auth'];

    private const ALLOWED = 'http://localhost:3000';

    private const FOREIGN = 'https://evil.example';

    public function test_the_configured_origin_list_has_no_wildcard(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertContains(self::ALLOWED, config('cors.allowed_origins'));
    }

    public function test_preflight_from_an_allowed_origin_is_echoed_back(): void
    {
        foreach (self::PATHS as $path) {
            $response = $this->call('OPTIONS', $path, server: [
                'HTTP_ORIGIN' => self::ALLOWED,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]);

            $this->assertSame(self::ALLOWED, $response->headers->get('Access-Control-Allow-Origin'), $path);
            $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'), $path);
        }
    }

    public function test_preflight_from_a_foreign_origin_gets_no_allow_origin_header(): void
    {
        foreach (self::PATHS as $path) {
            $response = $this->call('OPTIONS', $path, server: [
                'HTTP_ORIGIN' => self::FOREIGN,
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]);

            $this->assertNull($response->headers->get('Access-Control-Allow-Origin'), $path);
        }
    }

    public function test_actual_requests_follow_the_same_rule(): void
    {
        foreach (self::PATHS as $path) {
            $allowed = $this->call('POST', $path, server: ['HTTP_ORIGIN' => self::ALLOWED]);
            $this->assertSame(self::ALLOWED, $allowed->headers->get('Access-Control-Allow-Origin'), $path);

            $foreign = $this->call('POST', $path, server: ['HTTP_ORIGIN' => self::FOREIGN]);
            $this->assertNull($foreign->headers->get('Access-Control-Allow-Origin'), $path);
        }
    }

    /**
     * The value has to be written to `$_SERVER` as well as to `$_ENV`, and the
     * reason is the whole point of this test's history.
     *
     * `env()` reads through phpdotenv's default adapters, whose order is
     * `[ServerConstAdapter, EnvConstAdapter]` — `$_SERVER` wins. `backend/.env`
     * is loaded into both at boot, so on any machine where that file defines
     * `CORS_ALLOWED_ORIGINS`, writing to `$_ENV` alone is shadowed by the `.env`
     * value and the assertion sees the default instead of the value under test.
     * The CI job copies `.env.example`, which does define it, and this test
     * failed there for months while passing on a developer machine whose `.env`
     * does not — see the commit that fixed it. `putenv` is kept because it is
     * the third place the value can live, even though `PutenvAdapter` is not
     * among Laravel's default adapters.
     */
    public function test_origin_list_is_parsed_from_the_environment_value(): void
    {
        $value = ' https://a.test , ,https://b.test ,';

        $previousServer = $_SERVER['CORS_ALLOWED_ORIGINS'] ?? null;
        $previousEnv = $_ENV['CORS_ALLOWED_ORIGINS'] ?? null;
        $previousPutenv = getenv('CORS_ALLOWED_ORIGINS');

        putenv("CORS_ALLOWED_ORIGINS={$value}");
        $_SERVER['CORS_ALLOWED_ORIGINS'] = $value;
        $_ENV['CORS_ALLOWED_ORIGINS'] = $value;

        try {
            $config = require config_path('cors.php');
        } finally {
            if ($previousServer === null) {
                unset($_SERVER['CORS_ALLOWED_ORIGINS']);
            } else {
                $_SERVER['CORS_ALLOWED_ORIGINS'] = $previousServer;
            }

            if ($previousEnv === null) {
                unset($_ENV['CORS_ALLOWED_ORIGINS']);
            } else {
                $_ENV['CORS_ALLOWED_ORIGINS'] = $previousEnv;
            }

            if ($previousPutenv === false) {
                putenv('CORS_ALLOWED_ORIGINS');
            } else {
                putenv("CORS_ALLOWED_ORIGINS={$previousPutenv}");
            }
        }

        $this->assertSame(['https://a.test', 'https://b.test'], $config['allowed_origins']);
    }
}

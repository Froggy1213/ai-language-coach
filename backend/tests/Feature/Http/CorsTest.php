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

    public function test_origin_list_is_parsed_from_the_environment_value(): void
    {
        putenv('CORS_ALLOWED_ORIGINS= https://a.test , ,https://b.test ,');
        $_ENV['CORS_ALLOWED_ORIGINS'] = ' https://a.test , ,https://b.test ,';

        try {
            $config = require config_path('cors.php');
        } finally {
            putenv('CORS_ALLOWED_ORIGINS');
            unset($_ENV['CORS_ALLOWED_ORIGINS']);
        }

        $this->assertSame(['https://a.test', 'https://b.test'], $config['allowed_origins']);
    }
}

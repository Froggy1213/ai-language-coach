<?php

namespace Tests\Feature\Support;

use App\Providers\AppServiceProvider;
use App\Support\ProductionSanityCheck;
use Nuwave\Lighthouse\Schema\TypeRegistry;
use RuntimeException;
use Tests\TestCase;

class ProductionSanityCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        // Explicitly restore testing environment so subsequent tests in the suite are never contaminated.
        $this->app->detectEnvironment(fn () => 'testing');

        parent::tearDown();
    }

    /**
     * Parity test: verify that backend/config/dev-defaults.php stays in lockstep
     * with the interpolation defaults committed in docker-compose.yml.
     */
    public function test_dev_defaults_match_docker_compose_interpolation_defaults(): void
    {
        $composePath = base_path('../docker-compose.yml');
        $this->assertFileExists($composePath, 'docker-compose.yml must exist at repository root.');

        $composeContent = (string) file_get_contents($composePath);

        $keysToVerify = [
            'app_key' => 'APP_KEY',
            'livekit_api_secret' => 'LIVEKIT_API_SECRET',
            'voice_internal_secret' => 'VOICE_INTERNAL_SECRET',
        ];

        $parsedDefaults = [];
        foreach ($keysToVerify as $configKey => $envVar) {
            $pattern = '/\$\{'.preg_quote($envVar, '/').':-([^}\s]+)\}/';
            $matched = preg_match_all($pattern, $composeContent, $matches);

            $this->assertGreaterThan(
                0,
                $matched,
                "Failed to find default interpolation for \${$envVar}:-...} in docker-compose.yml."
            );

            $uniqueValues = array_unique($matches[1]);
            $this->assertCount(
                1,
                $uniqueValues,
                "Conflicting defaults found for {$envVar} in docker-compose.yml: ".implode(', ', $uniqueValues)
            );

            $parsedDefaults[$configKey] = $uniqueValues[0];
        }

        $configDefaults = config('dev-defaults');
        $this->assertIsArray($configDefaults, 'config/dev-defaults.php must return an array.');

        foreach ($keysToVerify as $configKey => $envVar) {
            $this->assertSame(
                $parsedDefaults[$configKey],
                $configDefaults[$configKey] ?? null,
                "dev-defaults.php key '{$configKey}' has drifted from \${$envVar}:-...} in docker-compose.yml."
            );
        }
    }

    /**
     * Verify that a valid, production-hardened environment passes sanity check without error.
     */
    public function test_passes_when_production_environment_is_correctly_configured(): void
    {
        $this->configureValidProduction();

        $this->assertTrue($this->app->isProduction());

        // Should execute smoothly with no exception thrown
        ProductionSanityCheck::check($this->app);
    }

    /**
     * Condition (a): APP_DEBUG must not be true in production.
     */
    public function test_condition_a_refuses_to_boot_when_app_debug_is_true(): void
    {
        $this->configureValidProduction();
        config(['app.debug' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG');

        ProductionSanityCheck::check($this->app);
    }

    /**
     * Condition (b): APP_KEY must not equal committed docker-compose default.
     */
    public function test_condition_b_refuses_to_boot_when_app_key_is_dev_default(): void
    {
        $this->configureValidProduction();
        config(['app.key' => (string) config('dev-defaults.app_key')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY');

        ProductionSanityCheck::check($this->app);
    }

    /**
     * Condition (b): LIVEKIT_API_SECRET must not equal committed docker-compose default.
     */
    public function test_condition_b_refuses_to_boot_when_livekit_api_secret_is_dev_default(): void
    {
        $this->configureValidProduction();
        config(['voice.livekit.api_secret' => (string) config('dev-defaults.livekit_api_secret')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LIVEKIT_API_SECRET');

        ProductionSanityCheck::check($this->app);
    }

    /**
     * Condition (b): VOICE_INTERNAL_SECRET must not equal committed docker-compose default.
     */
    public function test_condition_b_refuses_to_boot_when_voice_internal_secret_is_dev_default(): void
    {
        $this->configureValidProduction();
        config(['voice.internal_secret' => (string) config('dev-defaults.voice_internal_secret')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('VOICE_INTERNAL_SECRET');

        ProductionSanityCheck::check($this->app);
    }

    /**
     * Condition (c): cors.allowed_origins must not contain wildcard '*' (array format).
     */
    public function test_condition_c_refuses_to_boot_when_cors_allowed_origins_contains_wildcard_array(): void
    {
        $this->configureValidProduction();
        config(['cors.allowed_origins' => ['https://app.example.com', '*']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CORS_ALLOWED_ORIGINS');

        ProductionSanityCheck::check($this->app);
    }

    /**
     * Condition (c): cors.allowed_origins must not contain wildcard '*' (string format).
     */
    public function test_condition_c_refuses_to_boot_when_cors_allowed_origins_contains_wildcard_string(): void
    {
        $this->configureValidProduction();
        config(['cors.allowed_origins' => '*']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CORS_ALLOWED_ORIGINS');

        ProductionSanityCheck::check($this->app);
    }

    /**
     * Condition (d): SESSION_SECURE_COOKIE must be true in production (testing false value).
     */
    public function test_condition_d_refuses_to_boot_when_session_secure_cookie_is_false(): void
    {
        $this->configureValidProduction();
        config(['session.secure' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECURE_COOKIE');

        ProductionSanityCheck::check($this->app);
    }

    /**
     * Condition (d): SESSION_SECURE_COOKIE must be true in production (testing null value).
     */
    public function test_condition_d_refuses_to_boot_when_session_secure_cookie_is_null(): void
    {
        $this->configureValidProduction();
        config(['session.secure' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SESSION_SECURE_COOKIE');

        ProductionSanityCheck::check($this->app);
    }

    /**
     * Verify that non-production environments (local/testing) are completely unhindered by dev defaults.
     */
    public function test_does_not_throw_in_non_production_environments_even_with_dev_defaults(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertFalse($this->app->isProduction());

        // In local/testing, all dev defaults are expected to be present and active
        config([
            'app.debug' => true,
            'app.key' => (string) config('dev-defaults.app_key'),
            'voice.livekit.api_secret' => (string) config('dev-defaults.livekit_api_secret'),
            'voice.internal_secret' => (string) config('dev-defaults.voice_internal_secret'),
            'cors.allowed_origins' => ['*'],
            'session.secure' => false,
        ]);

        // Must return cleanly without exception
        ProductionSanityCheck::check($this->app);
    }

    /**
     * Verify that AppServiceProvider boots and triggers the production sanity check.
     */
    public function test_app_service_provider_triggers_sanity_check_during_boot(): void
    {
        $this->configureValidProduction();
        config(['app.debug' => true]);

        $provider = new AppServiceProvider($this->app);
        $typeRegistry = $this->app->make(TypeRegistry::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_DEBUG');

        $provider->boot($typeRegistry);
    }

    /**
     * Helper to set up a known-good production configuration.
     */
    private function configureValidProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        config([
            'app.debug' => false,
            'app.key' => 'base64:cHJvZHVjdGlvbmtleXRlc3QxMjM0NTY3ODkwMTIzNDU2Nw==',
            'voice.livekit.api_secret' => 'production_livekit_secret_at_least_32_chars_long',
            'voice.internal_secret' => 'production_voice_internal_secret_at_least_32_chars_long',
            'cors.allowed_origins' => ['https://app.example.com'],
            'session.secure' => true,
        ]);
    }
}

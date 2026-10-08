<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

class ProductionSanityCheck
{
    /**
     * Refuse to boot in production if unsafe development defaults or insecure configurations are active.
     *
     * @throws RuntimeException
     */
    public static function check(?Application $app = null): void
    {
        $app = $app ?? app();

        // Safety check applies strictly to production environments so local dev and testing suites run unhindered.
        if (! $app->isProduction()) {
            return;
        }

        self::assertDebugDisabled($app);
        self::assertNoDevelopmentSecrets($app);
        self::assertCorsOriginsSecure($app);
        self::assertSecureSessionCookies($app);
    }

    /**
     * Ensure APP_DEBUG is disabled in production.
     *
     * @throws RuntimeException
     */
    public static function assertDebugDisabled(?Application $app = null): void
    {
        $app = $app ?? app();
        $debug = $app['config']->get('app.debug');

        if (filter_var($debug, FILTER_VALIDATE_BOOLEAN) === true) {
            throw new RuntimeException(
                "Production sanity check failed: APP_DEBUG (config('app.debug')) must be false in production. ".
                'Debug mode exposes sensitive environment details, database credentials, and stack traces on error. '.
                'Set APP_DEBUG=false in your production environment or secrets manager.'
            );
        }
    }

    /**
     * Ensure APP_KEY, LIVEKIT_API_SECRET, and VOICE_INTERNAL_SECRET do not match committed development defaults.
     *
     * @throws RuntimeException
     */
    public static function assertNoDevelopmentSecrets(?Application $app = null): void
    {
        $app = $app ?? app();
        $knownDefaults = array_values(self::devDefaults($app));

        $appKey = (string) $app['config']->get('app.key', '');
        if ($appKey !== '' && in_array($appKey, $knownDefaults, true)) {
            throw new RuntimeException(
                'Production sanity check failed: APP_KEY matches a committed development default from docker-compose.yml. '.
                'The application key signs encrypted cookies, sessions, and signed URLs; running with a committed key enables cookie and session forgery. '.
                "Generate a cryptographically secure key using 'php artisan key:generate --show' and configure APP_KEY in your production secrets manager."
            );
        }

        $livekitSecret = (string) $app['config']->get('voice.livekit.api_secret', '');
        if ($livekitSecret !== '' && in_array($livekitSecret, $knownDefaults, true)) {
            throw new RuntimeException(
                'Production sanity check failed: LIVEKIT_API_SECRET matches a committed development default from docker-compose.yml. '.
                'The LiveKit API secret signs learner access tokens and authenticates room webhooks; running with a committed secret compromises room access and webhook verification. '.
                "Generate a secure 32+ character secret (e.g. 'openssl rand -base64 32') and configure LIVEKIT_API_SECRET in your production secrets manager."
            );
        }

        $voiceSecret = (string) $app['config']->get('voice.internal_secret', '');
        if ($voiceSecret !== '' && in_array($voiceSecret, $knownDefaults, true)) {
            throw new RuntimeException(
                'Production sanity check failed: VOICE_INTERNAL_SECRET matches a committed development default from docker-compose.yml. '.
                'The voice internal secret authenticates voice agent callback endpoints (/internal/sessions/{id}/fail, /turns); running with a committed secret permits unauthorized reports. '.
                "Generate a secure secret (e.g. 'openssl rand -hex 32') and configure VOICE_INTERNAL_SECRET in your production secrets manager."
            );
        }
    }

    /**
     * Ensure CORS allowed origins do not contain wildcard '*' in production.
     *
     * @throws RuntimeException
     */
    public static function assertCorsOriginsSecure(?Application $app = null): void
    {
        $app = $app ?? app();
        $allowedOrigins = $app['config']->get('cors.allowed_origins', []);

        $origins = is_array($allowedOrigins)
            ? $allowedOrigins
            : (is_string($allowedOrigins) ? explode(',', $allowedOrigins) : []);

        foreach ($origins as $origin) {
            if (trim((string) $origin) === '*') {
                throw new RuntimeException(
                    "Production sanity check failed: CORS_ALLOWED_ORIGINS (config('cors.allowed_origins')) cannot contain '*' in production. ".
                    'Because supports_credentials is true, wildcard origins allow arbitrary websites to make credentialed cross-origin requests. '.
                    "Set CORS_ALLOWED_ORIGINS to explicit allowed origin(s) (e.g. 'https://yourdomain.com') in your production environment."
                );
            }
        }
    }

    /**
     * Ensure SESSION_SECURE_COOKIE is true in production.
     *
     * @throws RuntimeException
     */
    public static function assertSecureSessionCookies(?Application $app = null): void
    {
        $app = $app ?? app();
        $secure = $app['config']->get('session.secure');

        if (filter_var($secure, FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new RuntimeException(
                "Production sanity check failed: SESSION_SECURE_COOKIE (config('session.secure')) must be true in production. ".
                'In production, session cookies must be marked Secure to ensure they are transmitted exclusively over HTTPS. '.
                'Set SESSION_SECURE_COOKIE=true in your production environment or terraform configuration.'
            );
        }
    }

    /**
     * Retrieve the known development defaults from configuration or config file.
     *
     * @return array<string, string>
     */
    public static function devDefaults(?Application $app = null): array
    {
        $app = $app ?? app();

        /** @var array<string, string>|null $defaults */
        $defaults = $app['config']->get('dev-defaults');

        if (! is_array($defaults) || empty($defaults)) {
            $path = $app->basePath('config/dev-defaults.php');
            if (file_exists($path)) {
                $defaults = require $path;
            }
        }

        return is_array($defaults) ? $defaults : [];
    }
}

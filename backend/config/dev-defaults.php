<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Committed Development Defaults
    |--------------------------------------------------------------------------
    |
    | These exact values are committed in docker-compose.yml as local development
    | fallback defaults (via ${VAR:-default} interpolation syntax).
    |
    | In production, running with any of these defaults compromises the deployment:
    | - APP_KEY signs sessions, encrypted cookies, and signed URLs.
    | - LIVEKIT_API_SECRET signs access tokens and incoming webhooks.
    | - VOICE_INTERNAL_SECRET authenticates the voice agent's internal endpoints.
    |
    | ProductionSanityCheck ensures the application refuses to boot if any of these
    | committed defaults are active when app()->isProduction() is true.
    |
    */

    'app_key' => 'base64:/z0ptXSDQvAl3H0G0+Yn+HjGr+x+kaKcXBkzrEv2iZE=',

    'livekit_api_secret' => '99fed3cd67e98cae5fa57a9aec3818d317f5e8a54f67c87e',

    'voice_internal_secret' => '82cba8627ffb6407e152a3baa17f586b85f213cacb9b55b9',

];

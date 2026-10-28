<?php

use App\Http\Controllers\FailVoiceSessionController;
use App\Http\Controllers\LiveKitWebhookController;
use App\Http\Controllers\RecordVoiceSessionTurnController;
use App\Http\Controllers\ReportVoiceClientErrorController;
use App\Http\Middleware\VerifyInternalSecret;
use Illuminate\Support\Facades\Route;

/*
| LiveKit calls this one, so it is deliberately outside the Sanctum guard: the
| caller is a server, not a learner, and proves itself with a signature instead
| of a session. The route also has to stay CSRF-exempt, which it is by being in
| the `api` middleware group rather than `web`.
*/
Route::post('/webhooks/livekit', LiveKitWebhookController::class);

// The voice agent reports its own failures here (plan §5).
Route::post('/internal/sessions/{session}/fail', FailVoiceSessionController::class)
    ->middleware([VerifyInternalSecret::class, 'throttle:120,1'])
    ->whereNumber('session');

// The voice agent reports per-turn latency here (plan §5).
Route::post('/internal/sessions/{session}/turns', RecordVoiceSessionTurnController::class)
    ->middleware([VerifyInternalSecret::class, 'throttle:120,1'])
    ->whereNumber('session');

// The learner's browser reports client-side WebRTC / microphone failures here.
Route::post('/sessions/{session}/client-error', ReportVoiceClientErrorController::class)
    ->middleware(['auth:sanctum', 'throttle:30,1'])
    ->whereNumber('session');

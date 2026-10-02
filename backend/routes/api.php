<?php

use App\Http\Controllers\FailVoiceSessionController;
use App\Http\Controllers\LiveKitWebhookController;
use App\Http\Controllers\RecordVoiceSessionTurnController;
use App\Http\Middleware\VerifyInternalSecret;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
| LiveKit calls this one, so it is deliberately outside the Sanctum guard: the
| caller is a server, not a learner, and proves itself with a signature instead
| of a session. The route also has to stay CSRF-exempt, which it is by being in
| the `api` middleware group rather than `web`.
*/
Route::post('/webhooks/livekit', LiveKitWebhookController::class);

// The voice agent reports its own failures here (plan §5).
Route::post('/internal/sessions/{session}/fail', FailVoiceSessionController::class)
    ->middleware(VerifyInternalSecret::class)
    ->whereNumber('session');

// The voice agent reports per-turn latency here (plan §5).
Route::post('/internal/sessions/{session}/turns', RecordVoiceSessionTurnController::class)
    ->middleware(VerifyInternalSecret::class)
    ->whereNumber('session');

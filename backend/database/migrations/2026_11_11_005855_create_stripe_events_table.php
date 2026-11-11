<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table): void {
            $table->id();

            // Stripe retries a webhook until it receives a 2xx, and it may
            // deliver the same event twice even without a failure. The unique
            // id is the dedupe key: the row is written in the same transaction
            // as its effect, so a rolled-back handler can still be retried.
            $table->string('stripe_event_id', 255)->unique('uq_stripe_events_event');
            $table->string('type', 128);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};

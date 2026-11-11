<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();

            // One learner can accumulate rows over time (a cancelled plan and a
            // later resubscribe); the newest row is the current one.
            $table->foreignId('user_id')
                ->constrained('users', indexName: 'fk_subscriptions_user')
                ->cascadeOnDelete();

            $table->string('stripe_subscription_id', 255)->unique('uq_subscriptions_stripe_id');
            $table->string('stripe_price_id', 255)->nullable();

            // `plan_code` is the tier (entry/standard/pro); `billing_interval`
            // is how often Stripe charges it. A yearly subscription still gets
            // its minutes monthly, which is why the two are stored apart.
            $table->string('plan_code', 32);
            $table->string('billing_interval', 16);

            $table->string('status', 32);

            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);

            // The first day of the current monthly allowance window. For a
            // yearly charge this stays the subscription start, because the
            // allowance resets every month regardless of the billing interval.
            $table->timestamp('usage_anchor_at')->nullable();

            // When access actually ends after a cancellation.
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_subscriptions_user_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

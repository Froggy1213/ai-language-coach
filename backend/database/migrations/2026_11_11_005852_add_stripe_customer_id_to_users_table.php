<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The Stripe customer is an identity, not a credential: it is what
            // the Checkout and Billing Portal calls say *who* is paying. It is
            // nullable because a learner can use the free allowance forever.
            $table->string('stripe_customer_id', 255)
                ->nullable()
                ->after('voice_consent_at')
                ->unique('uq_users_stripe_customer');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('uq_users_stripe_customer');
            $table->dropColumn('stripe_customer_id');
        });
    }
};

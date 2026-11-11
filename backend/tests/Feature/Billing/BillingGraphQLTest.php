<?php

namespace Tests\Feature\Billing;

use App\Billing\StripeGateway;
use App\Enums\VoiceSessionStatus;
use App\Models\LessonCard;
use App\Models\Roadmap;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VoiceSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * The billing surface as the interface reads it: plans, allowance and the two
 * Stripe redirects.
 */
class BillingGraphQLTest extends TestCase
{
    use LazilyRefreshDatabase;
    use MakesGraphQLRequests;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.secret' => 'sk_test_fake',
            'billing.frontend_url' => 'https://app.test',
            'billing.free_seconds' => 900,
            'billing.plans.entry.prices.month.stripe_price' => 'price_entry_month',
            'billing.plans.entry.prices.year.stripe_price' => 'price_entry_year',
            'billing.plans.standard.prices.month.stripe_price' => 'price_standard_month',
            'billing.plans.standard.prices.year.stripe_price' => 'price_standard_year',
            'billing.plans.pro.prices.month.stripe_price' => 'price_pro_month',
            'billing.plans.pro.prices.year.stripe_price' => 'price_pro_year',
        ]);

        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    public function test_plans_are_public_and_carry_prices_and_minutes(): void
    {
        $response = $this->graphQL(/** @lang GraphQL */ '
            query {
                billingPlans { code monthlyPriceJpy yearlyPriceJpy includedMinutes }
            }
        ');

        $response->assertGraphQLErrorFree();

        $plans = collect($response->json('data.billingPlans'))->keyBy('code');

        $this->assertSame(['entry', 'standard', 'pro'], $plans->keys()->all());
        $this->assertSame(799, $plans['entry']['monthlyPriceJpy']);
        $this->assertSame(7990, $plans['entry']['yearlyPriceJpy']);
        $this->assertSame(150, $plans['entry']['includedMinutes']);
        $this->assertSame(1480, $plans['standard']['monthlyPriceJpy']);
        $this->assertSame(300, $plans['standard']['includedMinutes']);
        $this->assertSame(2980, $plans['pro']['monthlyPriceJpy']);
        $this->assertSame(700, $plans['pro']['includedMinutes']);
    }

    public function test_overview_is_not_available_to_guests(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            query { billingOverview { planCode } }
        ')->assertGraphQLErrorMessage('Unauthenticated.');
    }

    public function test_a_free_learner_sees_the_lifetime_allowance(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->overview();

        $response->assertGraphQLErrorFree();
        $this->assertSame('free', $response->json('data.billingOverview.planCode'));
        $this->assertSame(15, $response->json('data.billingOverview.includedMinutes'));
        $this->assertSame(0, $response->json('data.billingOverview.usedMinutes'));
        $this->assertSame(15, $response->json('data.billingOverview.remainingMinutes'));
        $this->assertNull($response->json('data.billingOverview.resetsAt'));
        $this->assertFalse($response->json('data.billingOverview.hasSubscription'));
        $this->assertFalse($response->json('data.billingOverview.canManage'));
    }

    public function test_a_subscriber_sees_usage_and_can_manage_the_subscription(): void
    {
        $user = User::factory()->create();
        Subscription::factory()->for($user)->forPlan('entry')->create();
        Sanctum::actingAs($user);

        $roadmap = Roadmap::factory()->for($user)->create();
        $card = LessonCard::factory()->for($roadmap)->create();

        VoiceSession::factory()->for($user)->for($card, 'lessonCard')->create([
            'status' => VoiceSessionStatus::Completed,
            'duration_sec' => 600,
        ]);

        $response = $this->overview();

        $this->assertSame('entry', $response->json('data.billingOverview.planCode'));
        $this->assertSame('active', $response->json('data.billingOverview.status'));
        $this->assertSame(150, $response->json('data.billingOverview.includedMinutes'));
        $this->assertSame(10, $response->json('data.billingOverview.usedMinutes'));
        $this->assertSame(140, $response->json('data.billingOverview.remainingMinutes'));
        $this->assertTrue($response->json('data.billingOverview.hasSubscription'));
        $this->assertTrue($response->json('data.billingOverview.canManage'));
        $this->assertNotNull($response->json('data.billingOverview.resetsAt'));
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            mutation { createCheckoutSession(planCode: "entry", interval: month) { url } }
        ')->assertGraphQLErrorMessage('Unauthenticated.');

        $this->assertSame([], $this->stripe->checkouts);
    }

    public function test_checkout_returns_the_hosted_url_for_the_chosen_plan(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation { createCheckoutSession(planCode: "standard", interval: year) { url } }
        ');

        $response->assertGraphQLErrorFree();
        $this->assertSame('https://checkout.stripe.test/cs_test', $response->json('data.createCheckoutSession.url'));

        $this->assertCount(1, $this->stripe->checkouts);
        $checkout = $this->stripe->checkouts[0];
        $this->assertSame('standard', $checkout['plan']->code);
        $this->assertSame('year', $checkout['interval']->value);
        $this->assertSame('https://app.test/settings?checkout=success', $checkout['successUrl']);
        $this->assertSame('https://app.test/pricing?checkout=cancelled', $checkout['cancelUrl']);
    }

    public function test_an_unknown_plan_is_a_typed_error(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation { createCheckoutSession(planCode: "enterprise", interval: month) { url } }
        ');

        $this->assertSame('BILLING_PLAN_NOT_FOUND', $response->json('errors.0.extensions.code'));
        $this->assertSame([], $this->stripe->checkouts);
    }

    public function test_a_plan_without_a_configured_stripe_price_is_a_typed_error(): void
    {
        config(['billing.plans.entry.prices.month.stripe_price' => null]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation { createCheckoutSession(planCode: "entry", interval: month) { url } }
        ');

        $this->assertSame('BILLING_NOT_CONFIGURED', $response->json('errors.0.extensions.code'));
        $this->assertSame([], $this->stripe->checkouts);
    }

    public function test_the_portal_redirect_is_returned_for_a_subscriber(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_test']);
        Sanctum::actingAs($user);

        $response = $this->graphQL(/** @lang GraphQL */ '
            mutation { createBillingPortalSession { url } }
        ');

        $response->assertGraphQLErrorFree();
        $this->assertSame('https://billing.stripe.test/ps_test', $response->json('data.createBillingPortalSession.url'));
        $this->assertCount(1, $this->stripe->portals);
        $this->assertSame('https://app.test/settings', $this->stripe->portals[0]['returnUrl']);
    }

    private function overview(): TestResponse
    {
        return $this->graphQL(/** @lang GraphQL */ '
            query {
                billingOverview {
                    planCode
                    status
                    interval
                    includedMinutes
                    usedMinutes
                    remainingMinutes
                    resetsAt
                    currentPeriodEnd
                    cancelAtPeriodEnd
                    canManage
                    hasSubscription
                }
            }
        ');
    }
}

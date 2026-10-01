<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionState;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\User;
use App\Payments\FakeGateway;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Subscriptions API (Phase 8 step 8.2). Payments go through the FakeGateway: the test plays the
 * user on the fake checkout page (FakeGateway::markPaid), and the server only believes it after
 * asking the gateway (reconciliation), exactly as with PayMongo.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['payments.gateway' => 'fake']);
    }

    public function test_each_role_sees_only_its_own_plans_in_order(): void
    {
        Sanctum::actingAs($this->driverUser());
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/subscription-plans')
            ->assertOk()
            ->assertJsonPath('data.*.code', ['drv_1m', 'drv_6m', 'drv_12m'])
            ->assertJsonPath('data.0.name', 'Driver · 1 month')
            ->assertJsonPath('data.0.price', '199.00');

        Sanctum::actingAs($this->passengerUser());
        $this->withHeader('Accept-Language', 'fil')->getJson('/api/v1/subscription-plans')
            ->assertJsonPath('data.*.code', ['pax_1m', 'pax_6m', 'pax_12m'])
            ->assertJsonPath('data.0.name', 'Pasahero · 1 buwan');
    }

    public function test_an_admin_has_no_subscription_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/subscription-plans')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_ROLE');
    }

    public function test_checkout_creates_a_pending_subscription_with_the_price_copied(): void
    {
        Sanctum::actingAs($driver = $this->driverUser());

        $response = $this->postJson('/api/v1/subscriptions', ['plan_id' => $this->plan('drv_1m')->id])
            ->assertCreated()
            ->assertJsonPath('data.subscription.status', 'pending')
            ->assertJsonPath('data.subscription.amount', '199.00')
            ->assertJsonPath('data.subscription.starts_at', null);

        $this->assertStringContainsString('/payments/fake-checkout/fake_cs_', $response->json('data.checkout_url'));
        $this->assertDatabaseHas('subscription_transactions', [
            'subscription_id' => $response->json('data.subscription.id'),
            'amount' => '199.00',
            'status' => 'pending',
            'provider' => 'fake',
        ]);
        $this->assertFalse(app(\App\Services\SubscriptionService::class)->isActive($driver));
    }

    public function test_a_passenger_cannot_buy_a_driver_plan_and_inactive_plans_are_refused(): void
    {
        Sanctum::actingAs($this->passengerUser());
        $this->postJson('/api/v1/subscriptions', ['plan_id' => $this->plan('drv_1m')->id])
            ->assertStatus(422)->assertJsonPath('code', 'PLAN_NOT_FOR_YOU');

        $this->plan('pax_6m')->update(['is_active' => false]);
        $this->postJson('/api/v1/subscriptions', ['plan_id' => $this->plan('pax_6m')->id])
            ->assertStatus(422)->assertJsonPath('code', 'PLAN_INACTIVE');

        $this->postJson('/api/v1/subscriptions', ['plan_id' => 99999])->assertStatus(422); // unknown plan
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_paying_activates_only_after_the_server_asks_the_gateway(): void
    {
        $driver = $this->driverUser();
        [$id, $checkoutId] = $this->checkout($driver, 'drv_1m');

        // Opening the return page proves nothing (guarantee #1): still pending.
        $this->get('/payments/return?result=success')->assertOk();
        $this->getJson("/api/v1/subscriptions/{$id}")->assertJsonPath('data.status', 'pending');

        FakeGateway::markPaid($checkoutId, 'gcash'); // the user pays on the (fake) checkout page

        $this->freezeSecond(); // MySQL keeps whole seconds
        $response = $this->getJson("/api/v1/subscriptions/{$id}") // the app's check → reconciliation
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.payment.method', 'gcash');

        $subscription = Subscription::findOrFail($id);
        $this->assertTrue($subscription->starts_at->equalTo(now()));
        $this->assertTrue($subscription->ends_at->equalTo(now()->addMonthNoOverflow()));
        $this->assertSame($subscription->ends_at->toIso8601ZuluString(), $response->json('data.ends_at'));

        $this->getJson('/api/v1/subscriptions/current')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current.id', $id)
            ->assertJsonPath('data.pending', null)
            ->assertJsonPath('data.remaining_days', (int) ceil(now()->diffInSeconds($subscription->ends_at) / 86400));
        $this->getJson('/api/v1/auth/me')->assertJsonPath('subscription.status', 'active');

        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.activated', 'auditable_id' => $id, 'actor_id' => null]);
    }

    public function test_a_renewal_starts_when_the_current_period_ends_and_only_one_may_wait(): void
    {
        $passenger = $this->passengerUser();
        [$first, $firstCheckout] = $this->checkout($passenger, 'pax_1m');
        FakeGateway::markPaid($firstCheckout, 'gcash');
        $this->getJson("/api/v1/subscriptions/{$first}")->assertJsonPath('data.status', 'active');

        $this->travel(10)->days();
        [$second, $secondCheckout] = $this->checkout($passenger, 'pax_6m');
        FakeGateway::markPaid($secondCheckout, 'card');
        $this->getJson("/api/v1/subscriptions/{$second}")->assertJsonPath('data.status', 'scheduled');

        $a = Subscription::findOrFail($first);
        $b = Subscription::findOrFail($second);
        $this->assertTrue($b->starts_at->equalTo($a->ends_at), 'no paid day lost');
        $this->assertTrue($b->ends_at->equalTo($a->ends_at->copy()->addMonthsNoOverflow(6)));

        $this->getJson('/api/v1/subscriptions/current')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.current.id', $first)
            ->assertJsonPath('data.renewal.id', $second)
            ->assertJsonPath('data.active_until', $b->ends_at->toIso8601ZuluString());

        $this->postJson('/api/v1/subscriptions', ['plan_id' => $this->plan('pax_1m')->id])
            ->assertStatus(409)->assertJsonPath('code', 'ALREADY_RENEWED');
    }

    public function test_a_new_checkout_replaces_an_unpaid_one(): void
    {
        $driver = $this->driverUser();
        [$old] = $this->checkout($driver, 'drv_1m');
        [$new] = $this->checkout($driver, 'drv_12m');

        $this->assertSame(SubscriptionState::Cancelled, Subscription::findOrFail($old)->state);
        $this->assertDatabaseHas('subscription_transactions', ['subscription_id' => $old, 'status' => 'failed', 'failure_reason' => 'replaced_by_new_checkout']);
        $this->getJson('/api/v1/subscriptions/current')->assertJsonPath('data.pending.id', $new);
    }

    public function test_an_old_checkout_paid_meanwhile_is_activated_not_replaced(): void
    {
        $driver = $this->driverUser();
        [$old, $oldCheckout] = $this->checkout($driver, 'drv_1m');
        FakeGateway::markPaid($oldCheckout, 'gcash'); // paid, but the app never checked

        $this->checkout($driver, 'drv_1m'); // "a payment always wins" (decision #5)

        $this->assertSame(SubscriptionState::Paid, Subscription::findOrFail($old)->state);
    }

    public function test_cancel_closes_an_unpaid_checkout_but_never_a_paid_one(): void
    {
        $driver = $this->driverUser();
        [$id] = $this->checkout($driver, 'drv_1m');

        $this->postJson("/api/v1/subscriptions/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('expired', Cache::get(FakeGateway::cacheKey(SubscriptionTransaction::where('subscription_id', $id)->value('provider_checkout_id')))['status'], 'the gateway was told');
        $this->postJson("/api/v1/subscriptions/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'SUBSCRIPTION_NOT_PENDING');

        // Paid, then pressed Cancel: the payment wins.
        [$paid, $paidCheckout] = $this->checkout($driver, 'drv_1m');
        FakeGateway::markPaid($paidCheckout, 'gcash');
        $this->postJson("/api/v1/subscriptions/{$paid}/cancel")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_an_expired_checkout_is_closed_when_checked(): void
    {
        $driver = $this->driverUser();
        [$id, $checkoutId] = $this->checkout($driver, 'drv_1m');
        (new FakeGateway)->expireCheckout($checkoutId); // e.g. a day passed at PayMongo

        $this->getJson("/api/v1/subscriptions/{$id}")->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(TransactionStatus::Expired, SubscriptionTransaction::where('subscription_id', $id)->first()->status);
    }

    public function test_another_users_subscription_is_not_found(): void
    {
        [$id] = $this->checkout($this->driverUser(), 'drv_1m');

        Sanctum::actingAs($this->driverUser());
        $this->getJson("/api/v1/subscriptions/{$id}")->assertNotFound();
        $this->postJson("/api/v1/subscriptions/{$id}/cancel")->assertNotFound();
    }

    public function test_when_the_gateway_is_down_nothing_is_left_behind(): void
    {
        config(['payments.gateway' => 'paymongo', 'services.paymongo.secret_key' => 'sk_test_x']);
        Http::fake(['api.paymongo.com/*' => Http::response([], 500)]);
        Sanctum::actingAs($this->driverUser());

        $this->postJson('/api/v1/subscriptions', ['plan_id' => $this->plan('drv_1m')->id])
            ->assertStatus(502)->assertJsonPath('code', 'PAYMENT_GATEWAY_ERROR');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_history_and_payments_list_only_my_own(): void
    {
        $driver = $this->driverUser();
        [$id, $checkoutId] = $this->checkout($driver, 'drv_1m');
        FakeGateway::markPaid($checkoutId, 'paymaya');
        $this->getJson("/api/v1/subscriptions/{$id}");
        $this->checkout($this->driverUser(), 'drv_1m'); // someone else

        Sanctum::actingAs($driver);
        $this->getJson('/api/v1/subscriptions')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/subscription-transactions')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.method', 'paymaya')
            ->assertJsonPath('data.0.plan_name', 'Driver · 1 month')
            ->assertJsonMissingPath('data.0.provider_checkout_id');
    }

    public function test_the_return_page_and_the_fake_checkout_pages(): void
    {
        $this->get('/payments/return?result=success&lang=en')
            ->assertOk()->assertSee('Thank you')->assertSee('com.papayahatidgo.app://payment?result=success', false);
        $this->get('/payments/return?result=cancelled')
            ->assertOk()->assertSee('Na-cancel ang bayad');

        [, $checkoutId] = $this->checkout($this->driverUser(), 'drv_1m');
        $this->get("/payments/fake-checkout/{$checkoutId}")->assertOk()->assertSee('₱199.00')->assertSee('TEST MODE');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post("/payments/fake-checkout/{$checkoutId}/pay", ['method' => 'card'])
            ->assertRedirectContains('/payments/return?result=success');
        $this->assertTrue((new FakeGateway)->fetchCheckout($checkoutId)->paid);

        config(['payments.gateway' => 'paymongo']);
        $this->get("/payments/fake-checkout/{$checkoutId}")->assertNotFound(); // only with the fake gateway
    }
}

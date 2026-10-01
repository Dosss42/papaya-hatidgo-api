<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\SubscriptionState;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\PaymentEvent;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Payments\PayMongoSignature;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /webhooks/paymongo (Phase 8 step 8.3). Each test signs its payload exactly as PayMongo
 * does (HMAC-SHA256 of "{t}.{body}" with the webhook secret), or deliberately doesn't.
 */
class PayMongoWebhookTest extends TestCase
{
    use RefreshDatabase;
    use SubscriptionHelpers;

    private const SECRET = 'whsk_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['payments.gateway' => 'paymongo', 'services.paymongo.webhook_secret' => self::SECRET]);
    }

    /** A checkout_session.payment.paid event, shaped like PayMongo's. */
    private function paidEvent(string $eventId, string $checkoutId, int $centavos = 19900, bool $livemode = false, string $type = 'checkout_session.payment.paid'): array
    {
        return ['data' => [
            'id' => $eventId,
            'type' => 'event',
            'attributes' => [
                'type' => $type,
                'livemode' => $livemode,
                'data' => [
                    'id' => $checkoutId,
                    'type' => 'checkout_session',
                    'attributes' => [
                        'payment_method_used' => 'gcash',
                        'payments' => [[
                            'id' => 'pay_'.$eventId,
                            'type' => 'payment',
                            'attributes' => ['amount' => $centavos, 'status' => 'paid', 'source' => ['type' => 'gcash']],
                        ]],
                    ],
                ],
            ],
        ]];
    }

    /** Send the exact bytes that were signed (a re-encoded body would break the signature). */
    private function send(array $payload, ?string $secret = self::SECRET, string $mode = 'te'): TestResponse
    {
        $raw = json_encode($payload);
        $t = time();
        $header = $secret === null ? null : "t={$t},{$mode}=".PayMongoSignature::sign($raw, $secret, $t);

        return $this->call('POST', '/api/v1/webhooks/paymongo', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $header,
        ]), $raw);
    }

    public function test_a_signed_paid_event_activates_the_subscription(): void
    {
        $subscription = $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_test_1');

        $this->send($this->paidEvent('evt_1', 'cs_test_1'))->assertOk()->assertJsonPath('status', 'ok');

        $subscription->refresh();
        $this->assertSame(SubscriptionState::Paid, $subscription->state);
        $this->assertNotNull($subscription->ends_at);
        $transaction = SubscriptionTransaction::where('provider_checkout_id', 'cs_test_1')->first();
        $this->assertSame(TransactionStatus::Paid, $transaction->status);
        $this->assertSame('pay_evt_1', $transaction->provider_payment_id);
        $this->assertSame('gcash', $transaction->payment_method);
        $this->assertNotNull(PaymentEvent::where('provider_event_id', 'evt_1')->value('processed_at'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.activated', 'auditable_id' => $subscription->id]);
    }

    public function test_the_same_event_twice_activates_once(): void
    {
        $subscription = $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_test_1');

        $this->send($this->paidEvent('evt_1', 'cs_test_1'))->assertJsonPath('status', 'ok');
        $endsAt = $subscription->refresh()->ends_at;
        $this->travel(5)->minutes();
        $this->send($this->paidEvent('evt_1', 'cs_test_1'))->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertDatabaseCount('payment_events', 1);
        $this->assertSame(1, AuditLog::where('action', 'subscription.activated')->count());
        $this->assertTrue($subscription->refresh()->ends_at->equalTo($endsAt));
    }

    public function test_a_different_event_for_an_already_paid_checkout_changes_nothing(): void
    {
        $subscription = $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_test_1');
        $this->send($this->paidEvent('evt_1', 'cs_test_1'));
        $endsAt = $subscription->refresh()->ends_at;

        $this->travel(1)->hour();
        $this->send($this->paidEvent('evt_2', 'cs_test_1'))->assertJsonPath('status', 'ok');

        $this->assertTrue($subscription->refresh()->ends_at->equalTo($endsAt), 'no second period');
        $this->assertSame(1, SubscriptionTransaction::where('status', 'paid')->count());
    }

    public function test_unsigned_or_forged_webhooks_are_refused_and_nothing_is_stored(): void
    {
        $subscription = $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_test_1');
        $event = $this->paidEvent('evt_1', 'cs_test_1');

        $this->send($event, secret: null)->assertStatus(401)->assertJsonPath('code', 'INVALID_SIGNATURE');
        $this->send($event, secret: 'whsk_guess')->assertStatus(401);
        $this->send($event, mode: 'li')->assertStatus(401); // a live signature on a test event

        $this->assertDatabaseCount('payment_events', 0);
        $this->assertSame(SubscriptionState::Pending, $subscription->refresh()->state);
    }

    public function test_with_no_webhook_secret_configured_every_webhook_is_refused(): void
    {
        config(['services.paymongo.webhook_secret' => null]);
        $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_test_1');

        $this->send($this->paidEvent('evt_1', 'cs_test_1'), secret: '')->assertStatus(401);
    }

    public function test_a_live_event_must_carry_the_live_signature(): void
    {
        $subscription = $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_live_1');

        $this->send($this->paidEvent('evt_1', 'cs_live_1', livemode: true), mode: 'li')->assertOk();

        $this->assertSame(SubscriptionState::Paid, $subscription->refresh()->state);
    }

    public function test_a_wrong_amount_is_never_activated(): void
    {
        $subscription = $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_test_1'); // ₱199.00

        $this->send($this->paidEvent('evt_1', 'cs_test_1', centavos: 100))->assertOk();

        $this->assertSame(SubscriptionState::Pending, $subscription->refresh()->state);
        $transaction = SubscriptionTransaction::where('provider_checkout_id', 'cs_test_1')->first();
        $this->assertSame(TransactionStatus::Failed, $transaction->status);
        $this->assertStringStartsWith('amount_mismatch', $transaction->failure_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.payment_rejected', 'auditable_id' => $subscription->id]);
    }

    public function test_unknown_checkouts_and_other_event_types_are_logged_and_answered_200(): void
    {
        $this->send($this->paidEvent('evt_1', 'cs_nobody'))->assertOk();
        $this->send($this->paidEvent('evt_2', 'cs_nobody', type: 'payment.failed'))->assertOk();

        $this->assertSame(2, PaymentEvent::whereNotNull('processed_at')->count());
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_money_for_a_cancelled_checkout_still_activates(): void
    {
        $subscription = $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_test_1');
        $subscription->forceFill(['state' => SubscriptionState::Cancelled, 'cancelled_at' => now()])->save();
        SubscriptionTransaction::where('provider_checkout_id', 'cs_test_1')->update(['status' => 'failed', 'failure_reason' => 'cancelled_by_user']);

        $this->send($this->paidEvent('evt_1', 'cs_test_1'))->assertOk();

        $subscription->refresh();
        $this->assertSame(SubscriptionState::Paid, $subscription->state);
        $this->assertNull($subscription->cancelled_at);
    }

    public function test_an_event_logged_but_not_processed_is_processed_on_the_retry(): void
    {
        $subscription = $this->pendingSubscription($this->driverUser(), 'drv_1m', 'cs_test_1');
        $event = $this->paidEvent('evt_1', 'cs_test_1');
        // As if the first delivery crashed after logging the event (PayMongo then retries).
        PaymentEvent::forceCreate(['provider' => 'paymongo', 'provider_event_id' => 'evt_1', 'event_type' => 'checkout_session.payment.paid', 'payload' => $event]);

        $this->send($event)->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame(SubscriptionState::Paid, $subscription->refresh()->state);
        $this->assertDatabaseCount('payment_events', 1);
    }
}

<?php

namespace Tests\Feature\Subscriptions;

use App\Exceptions\ApiException;
use App\Payments\CheckoutRequest;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use App\Payments\PayMongoGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The payment gateway layer (Phase 8 step 8.1). PayMongo is never called for real in tests:
 * Http::fake answers with the shapes from PayMongo's API reference, and we check exactly
 * what our code sends and how it reads the answer.
 */
class PaymentGatewayTest extends TestCase
{
    private function request(): CheckoutRequest
    {
        return new CheckoutRequest(
            reference: 'PHG-SUB-7-TX-9',
            description: 'Driver · 1 month',
            amountCentavos: 19900,
            successUrl: 'https://example.test/payments/return?result=success',
            cancelUrl: 'https://example.test/payments/return?result=cancelled',
            metadata: ['transaction_id' => '9'],
        );
    }

    private function gateway(): PayMongoGateway
    {
        return new PayMongoGateway('sk_test_secret', 'https://api.paymongo.com/v1', ['gcash', 'card']);
    }

    /** @param list<array<string, mixed>> $payments */
    private function checkoutResource(array $payments = [], string $status = 'active', ?string $methodUsed = null): array
    {
        return ['data' => [
            'id' => 'cs_abc123',
            'type' => 'checkout_session',
            'attributes' => [
                'checkout_url' => 'https://checkout.paymongo.com/cs_abc123',
                'status' => $status,
                'payments' => $payments,
                'payment_method_used' => $methodUsed,
            ],
        ]];
    }

    public function test_create_checkout_sends_centavos_methods_urls_and_basic_auth(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::response($this->checkoutResource())]);

        $session = $this->gateway()->createCheckout($this->request());

        $this->assertSame('cs_abc123', $session->id);
        $this->assertSame('https://checkout.paymongo.com/cs_abc123', $session->url);

        Http::assertSent(function (Request $request) {
            $attributes = $request['data']['attributes'];

            return $request->url() === 'https://api.paymongo.com/v1/checkout_sessions'
                && $request->method() === 'POST'
                // Basic auth: the secret key is the username, the password is empty.
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_secret:'))
                && $attributes['line_items'][0]['amount'] === 19900
                && $attributes['line_items'][0]['currency'] === 'PHP'
                && $attributes['payment_method_types'] === ['gcash', 'card']
                && $attributes['reference_number'] === 'PHG-SUB-7-TX-9'
                && str_contains($attributes['success_url'], 'result=success')
                && $attributes['metadata'] === ['transaction_id' => '9'];
        });
    }

    public function test_fetch_reads_a_paid_checkout(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::response($this->checkoutResource([
            ['id' => 'pay_xyz', 'attributes' => ['status' => 'paid', 'amount' => 19900, 'source' => ['type' => 'gcash']]],
        ], methodUsed: 'gcash'))]);

        $status = $this->gateway()->fetchCheckout('cs_abc123');

        $this->assertTrue($status->paid);
        $this->assertSame('pay_xyz', $status->paymentId);
        $this->assertSame('gcash', $status->method);
        $this->assertSame(19900, $status->amountCentavos);
    }

    public function test_fetch_reads_unpaid_and_expired_checkouts(): void
    {
        Http::fakeSequence('api.paymongo.com/*')
            ->push($this->checkoutResource())
            ->push($this->checkoutResource(status: 'expired'));

        $active = $this->gateway()->fetchCheckout('cs_abc123');
        $expired = $this->gateway()->fetchCheckout('cs_abc123');

        $this->assertFalse($active->paid);
        $this->assertFalse($active->expired);
        $this->assertFalse($expired->paid);
        $this->assertTrue($expired->expired);
    }

    public function test_a_refusal_becomes_a_502_without_paymongo_details(): void
    {
        Http::fake(['api.paymongo.com/*' => Http::response(['errors' => [['code' => 'parameter_invalid', 'detail' => 'secret detail']]], 400)]);

        try {
            $this->gateway()->createCheckout($this->request());
            $this->fail('Expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame('PAYMENT_GATEWAY_ERROR', $e->errorCode);
            $this->assertSame(502, $e->status);
            $this->assertStringNotContainsString('secret detail', $e->getMessage());
        }
    }

    public function test_fake_gateway_is_paid_only_after_its_test_page_says_so(): void
    {
        $fake = new FakeGateway;
        $session = $fake->createCheckout($this->request());

        $this->assertStringStartsWith('fake_cs_', $session->id);
        $this->assertStringContainsString('/payments/fake-checkout/'.$session->id, $session->url);
        $this->assertFalse($fake->fetchCheckout($session->id)->paid);

        FakeGateway::markPaid($session->id, 'paymaya');
        $status = $fake->fetchCheckout($session->id);

        $this->assertTrue($status->paid);
        $this->assertSame('paymaya', $status->method);
        $this->assertSame(19900, $status->amountCentavos);
        $this->assertTrue($fake->fetchCheckout('fake_cs_unknown')->expired);
    }

    public function test_the_configured_gateway_is_bound_and_fake_is_refused_in_production(): void
    {
        config(['payments.gateway' => 'paymongo', 'services.paymongo.secret_key' => 'sk_test_x']);
        $this->assertInstanceOf(PayMongoGateway::class, app(PaymentGateway::class));

        config(['payments.gateway' => 'fake']);
        $this->assertInstanceOf(FakeGateway::class, app(PaymentGateway::class));

        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        app(PaymentGateway::class);
    }
}

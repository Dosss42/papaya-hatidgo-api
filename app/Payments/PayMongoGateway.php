<?php

namespace App\Payments;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo Checkout Sessions (https://developers.paymongo.com/reference/checkout-session-resource).
 * PayMongo hosts the payment page (GCash, Maya, card), so this app never handles payment details.
 *
 * Authentication: HTTP Basic with the SECRET key as the username. The key lives only in
 * Laravel's .env (guarantee #4) and is never logged.
 */
class PayMongoGateway implements PaymentGateway
{
    /**
     * @param  list<string>  $methods  payment_method_types offered on the page
     */
    public function __construct(
        private readonly string $secretKey,
        private readonly string $baseUrl = 'https://api.paymongo.com/v1',
        private readonly array $methods = ['gcash', 'paymaya', 'card'],
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/checkout_sessions', [
            'data' => ['attributes' => [
                'line_items' => [[
                    'currency' => 'PHP',
                    'amount' => $request->amountCentavos,
                    'name' => $request->description,
                    'quantity' => 1,
                ]],
                'payment_method_types' => $this->methods,
                'description' => $request->description,
                'reference_number' => $request->reference,
                'success_url' => $request->successUrl,
                'cancel_url' => $request->cancelUrl,
                'send_email_receipt' => false,
                'show_description' => true,
                'show_line_items' => true,
                'metadata' => $request->metadata,
            ]],
        ]));

        return new CheckoutSession(
            (string) $response->json('data.id'),
            (string) $response->json('data.attributes.checkout_url'),
        );
    }

    public function fetchCheckout(string $checkoutId): CheckoutStatus
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/checkout_sessions/'.rawurlencode($checkoutId)));

        return self::statusFromCheckout((array) $response->json('data'));
    }

    public function expireCheckout(string $checkoutId): void
    {
        try {
            $this->http()->post('/checkout_sessions/'.rawurlencode($checkoutId).'/expire');
        } catch (ConnectionException) {
            // Best effort: an unpaid checkout also expires by itself at PayMongo.
        }
    }

    /**
     * Reads a checkout_session resource, from GET /checkout_sessions/{id} or from inside a webhook
     * event (the same shape). Paid = PayMongo lists a payment with status "paid".
     *
     * @param  array<string, mixed>  $checkout
     */
    public static function statusFromCheckout(array $checkout): CheckoutStatus
    {
        $attributes = (array) ($checkout['attributes'] ?? []);
        $payment = collect((array) ($attributes['payments'] ?? []))
            ->first(fn ($p) => data_get($p, 'attributes.status') === 'paid');

        if (! $payment) {
            return CheckoutStatus::unpaid(expired: ($attributes['status'] ?? null) === 'expired');
        }

        return new CheckoutStatus(
            paid: true,
            paymentId: data_get($payment, 'id'),
            method: $attributes['payment_method_used'] ?? data_get($payment, 'attributes.source.type'),
            amountCentavos: (int) data_get($payment, 'attributes.amount'),
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds);
    }

    /**
     * One place for errors: PayMongo down or refusing → 502 PAYMENT_GATEWAY_ERROR for the app,
     * with PayMongo's own error details only in the server log.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call($this->http());
        } catch (ConnectionException $e) {
            Log::warning('PayMongo unreachable', ['error' => $e->getMessage()]);
            throw $this->gatewayError();
        }

        if ($response->failed()) {
            Log::warning('PayMongo refused a request', ['status' => $response->status(), 'errors' => $response->json('errors')]);
            throw $this->gatewayError();
        }

        return $response;
    }

    private function gatewayError(): ApiException
    {
        return new ApiException(__('api.payment_gateway_error'), 'PAYMENT_GATEWAY_ERROR', 502);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PaymentEvent;
use App\Models\SubscriptionTransaction;
use App\Payments\PayMongoGateway;
use App\Payments\PayMongoSignature;
use App\Services\SubscriptionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/paymongo (Phase 8 step 8.3, phase-0 § F.2 steps 1–6):
 *
 *   1 verify the signature (else 401, nothing is read)
 *   2 log the event in payment_events: its UNIQUE id makes a repeat a no-op (idempotency)
 *   3–4 SubscriptionService::activate() checks the amount and activates in one DB transaction
 *   6 answer 200, so PayMongo stops retrying
 *
 * An error while processing answers 500, so PayMongo retries later; the retry is processed
 * because the event was logged WITHOUT processed_at.
 */
class PayMongoWebhookController extends Controller
{
    private const PAID_EVENT = 'checkout_session.payment.paid';

    public function __invoke(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $raw = $request->getContent(); // the exact bytes PayMongo signed
        $payload = json_decode($raw, true);
        $livemode = (bool) data_get($payload, 'data.attributes.livemode', false);

        if (! is_array($payload) || ! PayMongoSignature::verify($raw, $request->header('Paymongo-Signature'), config('services.paymongo.webhook_secret'), $livemode)) {
            Log::warning('PayMongo webhook refused: bad signature', ['ip' => $request->ip()]);
            throw new ApiException(__('api.forbidden'), 'INVALID_SIGNATURE', 401);
        }

        $eventId = (string) data_get($payload, 'data.id');
        $type = (string) data_get($payload, 'data.attributes.type');

        try {
            $event = PaymentEvent::query()->where('provider', 'paymongo')->where('provider_event_id', $eventId)->first()
                ?? PaymentEvent::forceCreate([
                    'provider' => 'paymongo',
                    'provider_event_id' => $eventId,
                    'event_type' => $type,
                    'payload' => $payload,
                ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']); // the same event arriving twice at once
        }

        if ($event->processed_at !== null) {
            return response()->json(['status' => 'duplicate']);
        }

        if ($type === self::PAID_EVENT) {
            $checkout = (array) data_get($payload, 'data.attributes.data', []);
            $transaction = SubscriptionTransaction::query()
                ->where('provider_checkout_id', (string) ($checkout['id'] ?? ''))
                ->first();

            if ($transaction) {
                $subscriptions->activate($transaction, PayMongoGateway::statusFromCheckout($checkout), 'webhook');
            } else {
                Log::warning('PayMongo webhook for an unknown checkout', ['event_id' => $eventId]);
            }
        }
        // Other event types are logged and ignored.

        $event->forceFill(['processed_at' => now()])->save();

        return response()->json(['status' => 'ok']);
    }
}

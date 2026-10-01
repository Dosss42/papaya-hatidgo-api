<?php

namespace App\Payments;

/**
 * Proves a webhook really came from PayMongo (https://developers.paymongo.com/docs/creating-webhook).
 *
 * PayMongo sends a header like:  Paymongo-Signature: t=1700000000,te=<hex>,li=<hex>
 *   t  = when PayMongo signed it
 *   te = HMAC-SHA256 of "{t}.{raw body}" with the webhook secret, for TEST mode events
 *   li = the same for LIVE mode events
 *
 * Only someone who knows the webhook secret (PayMongo and our .env) can produce a matching
 * signature, so a forged "paid" event is refused before anything is read from it.
 */
final class PayMongoSignature
{
    public static function sign(string $rawBody, string $secret, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    public static function verify(string $rawBody, ?string $header, ?string $secret, bool $livemode): bool
    {
        if (! $secret || ! $header) {
            return false; // no secret configured = no webhook can be trusted
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $given = $parts[$livemode ? 'li' : 'te'] ?? '';
        if (! ctype_digit($timestamp) || $given === '') {
            return false;
        }

        // hash_equals: compares in constant time, so the signature can't be guessed byte by byte.
        return hash_equals(self::sign($rawBody, $secret, (int) $timestamp), $given);
    }
}

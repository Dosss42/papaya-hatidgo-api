<?php

namespace App\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends one push to one phone through Firebase Cloud Messaging, HTTP v1 (Phase 13, decision #1).
 *
 * How Google knows the message is really from us:
 *   1. We sign a short "JWT" (who we are + what we want: firebase.messaging) with the private key
 *      in the service-account file. Only someone holding that file can make this signature.
 *   2. Google checks the signature and returns an access token valid for 1 hour (cached here).
 *   3. Each push is one HTTPS POST carrying that access token.
 *
 * Neither the private key nor any token is ever logged.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'fcm.access_token';

    public const SENT = 'sent';

    /** Google says this phone's token no longer exists (app removed, data cleared): delete it. */
    public const GONE = 'gone';

    public const FAILED = 'failed';

    /** @var array<string, string>|null */
    private ?array $credentials = null;

    public function __construct(private readonly ?string $credentialsPath) {}

    /** No key configured (tests, another PC): pushes are skipped, never an error (decision #12). */
    public function enabled(): bool
    {
        return $this->credentialsPath !== null && $this->credentialsPath !== '' && is_readable($this->credentialsPath);
    }

    /**
     * @param  array<string, mixed>  $message  FCM's message object without the token
     *                                         (notification, data, android)
     * @return self::SENT|self::GONE|self::FAILED
     */
    public function send(string $token, array $message): string
    {
        $credentials = $this->credentials();
        $response = Http::withToken($this->accessToken($credentials))
            ->connectTimeout(3)->timeout(5)
            ->post(
                "https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send",
                ['message' => ['token' => $token] + $message],
            );

        if ($response->successful()) {
            return self::SENT;
        }

        $error = collect($response->json('error.details', []))->pluck('errorCode')->filter()->first()
            ?? $response->json('error.status');
        if ($response->status() === 404 || $error === 'UNREGISTERED') {
            return self::GONE;
        }
        if ($response->status() === 401) {
            Cache::forget(self::CACHE_KEY); // the access token was refused: get a new one next time
        }
        Log::warning('Push not sent', ['status' => $response->status(), 'error' => $error]);

        return self::FAILED;
    }

    /** Step 1 + 2: the signed JWT exchanged for a 1-hour access token, reused for 50 minutes. */
    private function accessToken(array $credentials): string
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(50), function () use ($credentials) {
            $now = time();
            $jwt = self::jwt([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $credentials['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key']);

            $response = Http::asForm()->connectTimeout(3)->timeout(5)->post($credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException("Google refused the push login (HTTP {$response->status()})");
            }

            return $response->json('access_token');
        });
    }

    /** A JWT signed with RS256: base64url(header) . base64url(claims) . base64url(signature). */
    private static function jwt(array $claims, string $privateKey): string
    {
        $encode = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode($claims));

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the push login (check the Firebase key file)');
        }

        return $unsigned.'.'.$encode($signature);
    }

    /** @return array<string, string> project_id, client_email, private_key, token_uri */
    private function credentials(): array
    {
        if ($this->credentials === null) {
            $data = json_decode((string) file_get_contents($this->credentialsPath), true);
            foreach (['project_id', 'client_email', 'private_key', 'token_uri'] as $key) {
                if (! is_string($data[$key] ?? null)) {
                    throw new RuntimeException("The Firebase key file has no {$key}");
                }
            }
            $this->credentials = $data;
        }

        return $this->credentials;
    }
}

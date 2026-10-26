<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Push\FcmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Laravel notification channel "push": one FCM message to each of the user's logged-in phones.
 *
 * The sending happens AFTER the response has gone to the app (defer, decision #2): accepting a
 * ride never waits for Google, and a Google outage can't fail a ride step. The ride screens poll
 * anyway, so a lost push costs nothing but a few seconds (phase-0 § J).
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $fcm) {}

    /** After a failure (Google or the internet down), pushes pause this long instead of each one waiting. */
    public const PAUSE_SECONDS = 60;

    private const PAUSED = 'fcm.paused';

    public function send(User $notifiable, AppNotification $notification): void
    {
        if (! $this->fcm->enabled() || Cache::has(self::PAUSED)) {
            return;
        }
        $devices = $notifiable->deviceTokens()->get();
        if ($devices->isEmpty()) {
            return;
        }

        defer(function () use ($devices, $notification) {
            foreach ($devices as $device) {
                if (! $this->deliver($device, $notification)) {
                    return; // Google unreachable: don't make every other phone wait for it too
                }
            }
        });
    }

    /** @return bool false = Google could not be reached (pushes pause for a minute) */
    private function deliver(DeviceToken $device, AppNotification $notification): bool
    {
        try {
            $result = $this->fcm->send($device->token, $notification->toFcm($device->locale));
        } catch (Throwable $e) {
            // No internet on the server, a bad key file…: logged (never the token), never thrown.
            Log::warning('Push failed; pausing pushes', ['device_token_id' => $device->id, 'kind' => $notification->kind, 'error' => $e->getMessage()]);
            Cache::put(self::PAUSED, true, self::PAUSE_SECONDS);

            return false;
        }

        if ($result === FcmClient::GONE) {
            $device->delete(); // the app was uninstalled or its data cleared (N11)
        } elseif ($result === FcmClient::SENT) {
            $device->forceFill(['last_used_at' => now()])->save();
        }

        return true;
    }
}

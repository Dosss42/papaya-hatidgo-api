<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Traits\Localizable;

/**
 * Every notification the app sends (Phase 13, decision #3). WHAT it says lives in
 * lang/{en,fil}/notifications.php under its kind; this class only carries the facts.
 *
 * Stored in the notifications table (type = kind, data = kind + params + target + ref), so the
 * text is written when the list is READ, in the app's language at that moment. The push text is
 * written for each phone in the language saved with its device token.
 */
class AppNotification extends Notification
{
    use Localizable;

    /** Android channels, created by the app (decision #6). */
    public const RIDES = 'rides';

    public const GENERAL = 'general';

    /**
     * @param  string  $kind  e.g. ride_cancelled: the key in lang/../notifications.php
     * @param  array<string, string|int|float>  $params  filled into the text (:reason, :days…)
     * @param  ?string  $target  where a tap leads in the app: ride:12 · requirement:drivers_license ·
     *                           requirements · subscription · home · alerts
     * @param  array<string, int|string>  $ref  what it is about, so a reminder is sent only once
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $params = [],
        public readonly ?string $target = null,
        public readonly bool $inList = true,
        public readonly bool $push = true,
        public readonly string $channel = self::GENERAL,
        public readonly ?int $ttlSeconds = null,
        public readonly ?string $tag = null,
        public readonly array $ref = [],
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            $this->inList ? 'database' : null,
            $this->push ? FcmChannel::class : null,
        ]));
    }

    /** The notifications.type column: the kind, so "was this reminder sent?" is a simple query. */
    public function databaseType(object $notifiable): string
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return ['params' => $this->params, 'target' => $this->target, 'ref' => $this->ref];
    }

    /**
     * FCM's message (without the token) for one phone. `data` values must all be strings.
     *
     * @return array<string, mixed>
     */
    public function toFcm(string $locale): array
    {
        $ride = $this->channel === self::RIDES;

        return [
            'notification' => self::text($this->kind, $this->params, $locale),
            'data' => [
                'kind' => $this->kind,
                'target' => (string) $this->target,
                'notification_id' => $this->inList ? (string) $this->id : '', // the stored row: a tap marks it read
            ],
            'android' => array_filter([
                'priority' => $ride ? 'HIGH' : 'NORMAL',
                'ttl' => $this->ttlSeconds !== null ? max(0, $this->ttlSeconds).'s' : null,
                'notification' => array_filter([
                    'channel_id' => $this->channel,
                    'tag' => $this->tag, // a newer push for the same ride replaces the older one
                    'sound' => $ride ? 'default' : null,
                ]),
            ]),
        ];
    }

    /**
     * The title and body of a notification in a language. Known codes inside the params (a
     * cancel reason, a requirement) are translated too; free text (an admin's reason) stays as typed.
     *
     * @param  array<string, mixed>  $params
     * @return array{title: string, body: string}
     */
    public static function text(string $kind, array $params, ?string $locale = null): array
    {
        return (new self($kind))->withLocale($locale ?? app()->getLocale(), function () use ($kind, $params) {
            if (isset($params['reason']) && Lang::has("notifications.reasons.{$params['reason']}")) {
                $params['reason'] = __("notifications.reasons.{$params['reason']}");
            }
            if (isset($params['requirement'])) {
                // A seeded paper has a name in both languages; one the admin added later keeps its own.
                $params['requirement'] = Lang::has("requirements.{$params['requirement']}.name")
                    ? __("requirements.{$params['requirement']}.name")
                    : ($params['requirement_name'] ?? $params['requirement']);
            }
            if (($params['pickup'] ?? null) === '') {
                $params['pickup'] = __('notifications.pickup_unnamed'); // a pin without a landmark
            }
            $body = isset($params['reason']) && Lang::has("notifications.{$kind}.body_reason")
                ? "notifications.{$kind}.body_reason"
                : "notifications.{$kind}.body";

            return ['title' => __("notifications.{$kind}.title", $params), 'body' => __($body, $params)];
        });
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POST /device-tokens (Phase 13): "push notifications for this login go to this phone".
 *
 * The app sends its FCM token after every login and app start, and again when the language
 * changes. The token is stored with the current LOGIN (decision #5), so logging out deletes it
 * with the login: there is no "remove my phone" call to forget. A token that already exists
 * (the same phone, someone else logged in before) simply moves to this user.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'in:android'],
            'locale' => ['nullable', 'in:en,fil'],
        ]);
        $login = $request->user()->currentAccessToken();

        $device = DeviceToken::firstOrNew(['token' => $data['token']]);
        $device->fill([
            'platform' => $data['platform'] ?? 'android',
            'locale' => $data['locale'] ?? app()->getLocale(),
        ]);
        $device->forceFill([
            'user_id' => $request->user()->id,
            'personal_access_token_id' => $login instanceof PersonalAccessToken ? $login->id : null,
            'last_used_at' => now(),
        ])->save();

        return response()->json(['data' => ['registered' => true]], $device->wasRecentlyCreated ? 201 : 200);
    }
}

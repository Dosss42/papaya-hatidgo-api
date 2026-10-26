<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The Alerts list (Phase 13, phase-0 § G): the logged-in user's own notifications, newest first.
 * The text is written now, in the language of THIS request (decision #3), so switching the app
 * to English also turns old notifications into English.
 */
class NotificationController extends Controller
{
    /** GET /notifications?page= : 20 per page, plus how many are unread (for the badge). */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        $page = $user->notifications()->paginate(20); // Laravel orders them newest first

        return response()->json([
            'data' => $page->getCollection()->map(fn (DatabaseNotification $n) => $this->item($n)),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'unread' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /** GET /notifications/unread-count : the badge on the Alerts tab (decision #9). */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $request->user()->unreadNotifications()->count()]]);
    }

    /** PATCH /notifications/{id}/read : someone else's id is a 404, never a peek. */
    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['data' => $this->item($notification)]);
    }

    /** POST /notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread' => 0]]);
    }

    /** @return array<string, mixed> */
    private function item(DatabaseNotification $n): array
    {
        $text = AppNotification::text($n->type, $n->data['params'] ?? []);

        return [
            'id' => $n->id,
            'kind' => $n->type,
            'title' => $text['title'],
            'body' => $text['body'],
            'target' => $n->data['target'] ?? null,
            'read_at' => $n->read_at?->toIso8601ZuluString(),
            'created_at' => $n->created_at?->toIso8601ZuluString(),
        ];
    }
}

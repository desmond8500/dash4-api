<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** Notifications de l'utilisateur connecté (relances des demandes clients, puis autres modules). */
class NotificationController extends Controller
{
    /** GET /api/v1/notifications — `unread=1` pour les non lues ; meta.unread_count. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = ($request->boolean('unread') ? $user->unreadNotifications() : $user->notifications())
            ->paginate(min(max((int) $request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                ...$n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /** POST /api/v1/notifications/{id}/read */
    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json(['message' => 'Notification lue.']);
    }

    /** POST /api/v1/notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Toutes les notifications sont lues.']);
    }
}

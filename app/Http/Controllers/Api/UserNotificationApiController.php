<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserNotificationApiController extends Controller
{
    /**
     * List notifications for current user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = max(10, min((int) $request->integer('per_page', 20), 50));

        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->with('ticket:id,ticket_number,subject');

        if ($request->boolean('unread_only')) {
            $query->where('is_read', false);
        }

        $notifications = $query
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => collect($notifications->items())
                ->map(fn (UserNotification $notification) => $this->serializeNotification($notification))
                ->all(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => UserNotification::query()
                    ->where('user_id', $user->id)
                    ->where('is_read', false)
                    ->count(),
            ],
        ]);
    }

    /**
     * Return unread notifications count for current user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'data' => [
                'unread_count' => $count,
            ],
        ]);
    }

    /**
     * Mark one notification as read.
     */
    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        $this->ensureOwnership($request, $notification);

        if (! $notification->is_read) {
            $notification->forceFill([
                'is_read' => true,
                'read_at' => now(),
            ])->save();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => $this->serializeNotification($notification->fresh('ticket')),
        ]);
    }

    /**
     * Mark all notifications as read for current user.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }

    /**
     * Ensure notification belongs to current user.
     */
    private function ensureOwnership(Request $request, UserNotification $notification): void
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(404);
        }
    }

    /**
     * Serialize notification for frontend.
     *
     * @return array<string, mixed>
     */
    private function serializeNotification(UserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'event_key' => $notification->event_key,
            'title' => $notification->title,
            'message' => $notification->message,
            'payload' => $notification->payload ?? [],
            'is_read' => (bool) $notification->is_read,
            'read_at' => optional($notification->read_at)?->toIso8601String(),
            'created_at' => optional($notification->created_at)?->toIso8601String(),
            'ticket' => $notification->ticket ? [
                'id' => $notification->ticket->id,
                'ticket_number' => $notification->ticket->ticket_number,
                'subject' => $notification->ticket->subject,
            ] : null,
        ];
    }
}

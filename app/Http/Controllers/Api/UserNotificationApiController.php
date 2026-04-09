<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class UserNotificationApiController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'open' => 'aberto',
        'in_progress' => 'em tratamento',
        'pending' => 'aguardando cliente',
        'closed' => 'fechado',
        'cancelled' => 'cancelado',
    ];

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

        $this->applyClientVisibility($query, $user);

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
                    ->tap(fn (Builder $query) => $this->applyClientVisibility($query, $user))
                    ->count(),
            ],
        ]);
    }

    /**
     * Return unread notifications count for current user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = UserNotification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->tap(fn (Builder $query) => $this->applyClientVisibility($query, $user))
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
        $user = $request->user();
        UserNotification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->tap(fn (Builder $query) => $this->applyClientVisibility($query, $user))
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read.',
        ]);
    }

    /**
     * Mark all notifications for a specific ticket as read.
     */
    public function markTicketRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'ticket_id' => ['required', 'integer', 'min:1'],
        ]);

        UserNotification::query()
            ->where('user_id', $user->id)
            ->where('ticket_id', $data['ticket_id'])
            ->where('is_read', false)
            ->tap(fn (Builder $query) => $this->applyClientVisibility($query, $user))
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'Ticket notifications marked as read.',
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
     * Restrict client notifications to their own tickets.
     */
    private function applyClientVisibility(Builder $query, $user): void
    {
        if (! $user->isClient()) {
            return;
        }

        $query->whereHas('ticket', function (Builder $ticketQuery) use ($user): void {
            $ticketQuery->where('created_by_user_id', $user->id)
                ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('user_id', $user->id));
        });
    }

    /**
     * Serialize notification for frontend.
     *
     * @return array<string, mixed>
     */
    private function serializeNotification(UserNotification $notification): array
    {
        $payload = is_array($notification->payload) ? $notification->payload : [];
        $ticketNumber = (string) ($payload['ticket_number'] ?? '');
        $eventKey = (string) $notification->event_key;

        return [
            'id' => $notification->id,
            'event_key' => $eventKey,
            'title' => $this->localizeTitle($eventKey, (string) $notification->title, $ticketNumber),
            'message' => $this->localizeMessage($eventKey, (string) $notification->message, $payload),
            'payload' => $payload,
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

    /**
     * Localize notification title to PT-PT.
     */
    private function localizeTitle(string $eventKey, string $title, string $ticketNumber): string
    {
        return match ($eventKey) {
            'ticket_created' => $ticketNumber !== '' ? "Ticket criado {$ticketNumber}" : 'Ticket criado',
            'ticket_replied' => $ticketNumber !== '' ? "Nova resposta no {$ticketNumber}" : 'Nova resposta',
            'ticket_assignment_updated' => $ticketNumber !== '' ? "Atribuição alterada {$ticketNumber}" : 'Atribuição alterada',
            'ticket_status_updated' => $ticketNumber !== '' ? "Estado atualizado {$ticketNumber}" : 'Estado atualizado',
            'ticket_knowledge_updated' => $ticketNumber !== '' ? "Conhecimento atualizado {$ticketNumber}" : 'Conhecimento atualizado',
            default => $title !== '' ? $title : 'Notificação',
        };
    }

    /**
     * Localize notification message to PT-PT.
     *
     * @param array<string, mixed> $payload
     */
    private function localizeMessage(string $eventKey, string $message, array $payload): string
    {
        if ($eventKey === 'ticket_assignment_updated') {
            $operator = trim((string) ($payload['assigned_operator'] ?? ''));

            if ($operator !== '') {
                return "Operador atribuído: {$operator}";
            }

            $legacy = str_contains($message, ':')
                ? trim((string) substr($message, strpos($message, ':') + 1))
                : trim($message);

            if ($legacy !== '' && strcasecmp($legacy, $message) !== 0) {
                return "Operador atribuído: {$legacy}";
            }

            return 'Atribuição de operador atualizada.';
        }

        if ($eventKey === 'ticket_status_updated') {
            $status = $this->statusLabel((string) ($payload['status_label'] ?? $payload['status'] ?? ''));

            if ($status !== '') {
                return "Estado atual: {$status}";
            }

            $legacy = str_contains($message, ':')
                ? trim((string) substr($message, strpos($message, ':') + 1))
                : trim($message);

            if ($legacy !== '' && strcasecmp($legacy, $message) !== 0) {
                return 'Estado atual: '.$this->statusLabel($legacy);
            }

            return 'Estado do ticket atualizado.';
        }

        if ($eventKey === 'ticket_knowledge_updated') {
            return 'Utilizadores em conhecimento foram atualizados.';
        }

        if (strcasecmp($message, 'Knowledge recipients were updated.') === 0) {
            return 'Utilizadores em conhecimento foram atualizados.';
        }

        return $message;
    }

    /**
     * Convert status key into PT-PT label.
     */
    private function statusLabel(string $status): string
    {
        $normalized = strtolower(trim($status));

        if ($normalized === '') {
            return '';
        }

        return self::STATUS_LABELS[$normalized] ?? $normalized;
    }
}

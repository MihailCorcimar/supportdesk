<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserPinnedTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationApiController extends Controller
{
    /**
     * List recent visible tickets for sidebar conversations.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = max(3, min((int) $request->integer('limit', 6), 12));
        $pinnedTicketIds = UserPinnedTicket::query()
            ->where('user_id', $user->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('ticket_id')
            ->map(fn (mixed $id) => (int) $id)
            ->values()
            ->all();

        $pinnedTickets = collect();
        if ($pinnedTicketIds !== []) {
            $pinnedQuery = Ticket::query()
                ->select(['id', 'ticket_number', 'subject', 'entity_id', 'last_activity_at', 'created_at'])
                ->with('entity:id,name');

            $this->applyVisibilityScope($pinnedQuery, $user);
            $pinnedQuery->whereIn('id', $pinnedTicketIds);
            $this->applyPinnedOrder($pinnedQuery, $pinnedTicketIds);

            $pinnedTickets = $pinnedQuery->get();
        }

        $recentQuery = Ticket::query()
            ->select(['id', 'ticket_number', 'subject', 'entity_id', 'last_activity_at', 'created_at'])
            ->with('entity:id,name');

        $this->applyVisibilityScope($recentQuery, $user);

        if ($pinnedTicketIds !== []) {
            $recentQuery->whereNotIn('id', $pinnedTicketIds);
        }

        $recentTickets = $recentQuery
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => [
                'pinned' => $pinnedTickets->map(fn (Ticket $ticket) => $this->serializeConversation($ticket, true))->all(),
                'recent' => $recentTickets->map(fn (Ticket $ticket) => $this->serializeConversation($ticket, false))->all(),
            ],
        ]);
    }

    /**
     * Pin one conversation ticket for current user.
     */
    public function pin(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $user = $request->user();
        $alreadyPinned = UserPinnedTicket::query()
            ->where('user_id', $user->id)
            ->where('ticket_id', $ticket->id)
            ->exists();

        if (! $alreadyPinned) {
            $nextPosition = (int) UserPinnedTicket::query()
                ->where('user_id', $user->id)
                ->max('position') + 1;

            UserPinnedTicket::query()->create([
                'user_id' => $user->id,
                'ticket_id' => $ticket->id,
                'position' => $nextPosition,
            ]);
        }

        return response()->json([
            'message' => 'Conversation pinned.',
        ]);
    }

    /**
     * Remove one pinned conversation ticket for current user.
     */
    public function unpin(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $user = $request->user();

        UserPinnedTicket::query()
            ->where('user_id', $user->id)
            ->where('ticket_id', $ticket->id)
            ->delete();

        $remaining = UserPinnedTicket::query()
            ->where('user_id', $user->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($remaining as $index => $pin) {
            $newPosition = $index + 1;
            if ((int) $pin->position !== $newPosition) {
                $pin->forceFill(['position' => $newPosition])->save();
            }
        }

        return response()->json([
            'message' => 'Conversation unpinned.',
        ]);
    }

    /**
     * Keep pinned rows sorted by user pin order.
     *
     * @param  list<int>  $pinnedTicketIds
     */
    private function applyPinnedOrder(Builder $query, array $pinnedTicketIds): void
    {
        if ($pinnedTicketIds === []) {
            return;
        }

        $clauses = [];
        $bindings = [];

        foreach ($pinnedTicketIds as $position => $ticketId) {
            $clauses[] = 'WHEN ? THEN ?';
            $bindings[] = $ticketId;
            $bindings[] = $position;
        }

        $query->orderByRaw('CASE id '.implode(' ', $clauses).' ELSE 99999 END', $bindings);
    }

    /**
     * Serialize ticket row for sidebar conversation panel.
     *
     * @return array<string, mixed>
     */
    private function serializeConversation(Ticket $ticket, bool $isPinned): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => Str::limit($ticket->subject, 44, '...'),
            'entity_name' => $ticket->entity?->name,
            'last_activity_at' => optional($ticket->last_activity_at ?? $ticket->created_at)?->toIso8601String(),
            'is_pinned' => $isPinned,
        ];
    }

    /**
     * Restrict ticket query to records visible by current user.
     */
    private function applyVisibilityScope(Builder $query, User $user): void
    {
        if ($user->isOperator()) {
            if ($user->isAdmin()) {
                return;
            }

            $inboxIds = $user->accessibleInboxes()->pluck('inboxes.id');

            if ($inboxIds->isEmpty()) {
                $query->whereRaw('1 = 0');
                return;
            }

            $query->whereIn('inbox_id', $inboxIds->all());

            return;
        }

        if ($user->isClient()) {
            $query->whereHas('entity.contacts', function (Builder $contactQuery) use ($user): void {
                $contactQuery
                    ->where('contacts.user_id', $user->id)
                    ->where('contacts.is_active', true);
            });

            return;
        }

        $query->whereRaw('1 = 0');
    }
}

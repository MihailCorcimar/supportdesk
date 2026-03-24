<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketLogApiController extends Controller
{
    /**
     * List ticket logs.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = TicketLog::query()
            ->with(['ticket:id,ticket_number,inbox_id,entity_id,subject', 'actorUser:id,name', 'actorContact:id,name'])
            ->orderByDesc('created_at');

        $this->applyVisibilityScope($query, $user);

        if ($request->filled('ticket_id')) {
            $query->where('ticket_id', $request->integer('ticket_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', (string) $request->string('action'));
        }

        if ($request->filled('actor_type')) {
            $query->where('actor_type', (string) $request->string('actor_type'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('action', 'like', "%{$search}%")
                    ->orWhereHas('ticket', fn (Builder $ticketQuery) => $ticketQuery
                        ->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->string('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->string('date_to'));
        }

        $logs = $query->paginate($request->integer('per_page', 30));

        return response()->json([
            'data' => $logs->getCollection()->map(fn (TicketLog $log) => $this->serializeLog($log))->all(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Show ticket log detail.
     */
    public function show(Request $request, TicketLog $ticketLog): JsonResponse
    {
        $query = TicketLog::query()->whereKey($ticketLog->id);
        $this->applyVisibilityScope($query, $request->user());

        $log = $query->with(['ticket:id,ticket_number,inbox_id,entity_id,subject', 'actorUser:id,name', 'actorContact:id,name'])
            ->firstOrFail();

        return response()->json([
            'data' => $this->serializeLog($log),
        ]);
    }

    /**
     * Apply visibility restrictions based on user role.
     */
    private function applyVisibilityScope(Builder $query, User $user): void
    {
        $query->whereHas('ticket', function (Builder $ticketQuery) use ($user): void {
            if ($user->isOperator()) {
                if ($user->isAdmin()) {
                    return;
                }

                $inboxIds = $user->accessibleInboxes()->pluck('inboxes.id');

                if ($inboxIds->isEmpty()) {
                    $ticketQuery->whereRaw('1 = 0');

                    return;
                }

                $ticketQuery->whereIn('inbox_id', $inboxIds);

                return;
            }

            $entityIds = $user->entities()->pluck('entities.id');

            if ($entityIds->isEmpty()) {
                $ticketQuery->whereRaw('1 = 0');

                return;
            }

            $ticketQuery->whereIn('entity_id', $entityIds);
        });
    }

    /**
     * Serialize log payload.
     *
     * @return array<string, mixed>
     */
    private function serializeLog(TicketLog $log): array
    {
        return [
            'id' => $log->id,
            'ticket_id' => $log->ticket_id,
            'action' => $log->action,
            'field' => $log->field,
            'old_value' => $log->old_value,
            'new_value' => $log->new_value,
            'metadata' => $log->metadata,
            'actor_type' => $log->actor_type,
            'created_at' => optional($log->created_at)?->toIso8601String(),
            'ticket' => $log->ticket ? [
                'id' => $log->ticket->id,
                'ticket_number' => $log->ticket->ticket_number,
                'subject' => $log->ticket->subject,
            ] : null,
            'actor_user' => $log->actorUser ? [
                'id' => $log->actorUser->id,
                'name' => $log->actorUser->name,
            ] : null,
            'actor_contact' => $log->actorContact ? [
                'id' => $log->actorContact->id,
                'name' => $log->actorContact->name,
            ] : null,
        ];
    }
}

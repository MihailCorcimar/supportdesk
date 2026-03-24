<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    /**
     * Return operational summary metrics.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Ticket::query()->with('inbox:id,name');

        $this->applyVisibilityScope($query, $user);

        $tickets = $query->get();

        $statusCounts = $tickets->groupBy('status')->map->count();

        $firstResponseDurations = $tickets
            ->filter(fn (Ticket $ticket) => $ticket->first_response_at !== null)
            ->map(fn (Ticket $ticket) => $ticket->created_at->diffInMinutes($ticket->first_response_at));

        $resolutionDurations = $tickets
            ->filter(fn (Ticket $ticket) => $ticket->status === 'closed')
            ->filter(fn (Ticket $ticket) => $ticket->closed_at !== null || $ticket->resolved_at !== null)
            ->map(fn (Ticket $ticket) => $ticket->created_at->diffInMinutes($ticket->closed_at ?? $ticket->resolved_at));

        $firstResponseSlaHours = (int) config('supportdesk.sla.first_response_hours', 4);
        $resolutionSlaHours = (int) config('supportdesk.sla.resolution_hours', 24);

        $firstResponseWithinSla = $tickets
            ->filter(fn (Ticket $ticket) => $ticket->first_response_at !== null)
            ->filter(fn (Ticket $ticket) => $ticket->created_at->diffInMinutes($ticket->first_response_at) <= ($firstResponseSlaHours * 60))
            ->count();

        $resolutionWithinSla = $tickets
            ->filter(fn (Ticket $ticket) => $ticket->status === 'closed')
            ->filter(fn (Ticket $ticket) => $ticket->closed_at !== null || $ticket->resolved_at !== null)
            ->filter(fn (Ticket $ticket) => $ticket->created_at->diffInMinutes($ticket->closed_at ?? $ticket->resolved_at) <= ($resolutionSlaHours * 60))
            ->count();

        $byInbox = $tickets
            ->groupBy('inbox_id')
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'inbox_id' => $first?->inbox_id,
                    'inbox_name' => $first?->inbox?->name ?? 'No Inbox',
                    'total' => $group->count(),
                    'open' => $group->where('status', 'open')->count(),
                    'in_progress' => $group->where('status', 'in_progress')->count(),
                    'pending' => $group->where('status', 'pending')->count(),
                    'closed' => $group->where('status', 'closed')->count(),
                    'cancelled' => $group->where('status', 'cancelled')->count(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'totals' => [
                    'all_tickets' => $tickets->count(),
                    'open' => (int) ($statusCounts['open'] ?? 0),
                    'in_progress' => (int) ($statusCounts['in_progress'] ?? 0),
                    'pending' => (int) ($statusCounts['pending'] ?? 0),
                    'closed' => (int) ($statusCounts['closed'] ?? 0),
                    'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
                ],
                'averages' => [
                    'first_response_minutes' => $firstResponseDurations->isEmpty() ? null : round((float) $firstResponseDurations->avg(), 2),
                    'resolution_minutes' => $resolutionDurations->isEmpty() ? null : round((float) $resolutionDurations->avg(), 2),
                ],
                'sla' => [
                    'first_response_hours' => $firstResponseSlaHours,
                    'resolution_hours' => $resolutionSlaHours,
                    'first_response_compliance_percent' => $firstResponseDurations->isEmpty()
                        ? null
                        : round(($firstResponseWithinSla / $firstResponseDurations->count()) * 100, 2),
                    'resolution_compliance_percent' => $resolutionDurations->isEmpty()
                        ? null
                        : round(($resolutionWithinSla / $resolutionDurations->count()) * 100, 2),
                ],
                'by_inbox' => $byInbox,
            ],
        ]);
    }

    /**
     * Apply visibility restrictions based on user role.
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

            $query->whereIn('inbox_id', $inboxIds);

            return;
        }

        $entityIds = $user->entities()->pluck('entities.id');

        if ($entityIds->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('entity_id', $entityIds);
    }
}

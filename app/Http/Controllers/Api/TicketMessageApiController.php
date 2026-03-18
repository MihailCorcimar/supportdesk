<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Support\TicketActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketMessageApiController extends Controller
{
    /**
     * Store a message for the given ticket.
     */
    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('reply', $ticket);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $contact = null;
        $isInternal = $user->isOperator() && $request->boolean('is_internal');

        if ($user->isClient()) {
            $contact = Contact::query()
                ->where('entity_id', $ticket->entity_id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->firstOrFail();
        }

        DB::transaction(function () use ($ticket, $validated, $user, $contact, $isInternal): void {
            TicketMessage::query()->create([
                'ticket_id' => $ticket->id,
                'author_type' => $user->isClient() ? 'contact' : 'user',
                'author_user_id' => $user->isOperator() ? $user->id : null,
                'author_contact_id' => $user->isClient() ? $contact?->id : null,
                'body' => $validated['body'],
                'is_internal' => $isInternal,
            ]);

            if (! $isInternal && in_array($ticket->status, ['resolved', 'closed'], true)) {
                $oldStatus = $ticket->status;

                $ticket->update([
                    'status' => 'open',
                    'resolved_at' => null,
                    'closed_at' => null,
                ]);

                TicketActivityLogger::log(
                    $ticket,
                    'status_updated',
                    'status',
                    $oldStatus,
                    'open',
                    $user->isClient() ? 'contact' : 'user',
                    $user->isOperator() ? $user->id : null,
                    $user->isClient() ? $contact?->id : null
                );
            }

            $ticket->update([
                'last_activity_at' => now(),
            ]);

            $actor = TicketActivityLogger::actorFor($user, $contact);

            TicketActivityLogger::log(
                $ticket,
                'message_added',
                null,
                null,
                null,
                $actor['actor_type'],
                $actor['actor_user_id'],
                $actor['actor_contact_id'],
                ['internal' => $isInternal]
            );
        });

        return response()->json([
            'message' => 'Mensagem enviada com sucesso.',
        ], 201);
    }
}

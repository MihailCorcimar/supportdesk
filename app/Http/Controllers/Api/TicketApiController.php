<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Entity;
use App\Models\Inbox;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\TicketActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketApiController extends Controller
{
    /**
     * List tickets visible to current user with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $ticketsQuery = Ticket::query()
            ->with(['inbox', 'entity', 'contact', 'assignedOperator'])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at');

        $this->applyVisibilityScope($ticketsQuery, $user);
        $this->applyFilters($ticketsQuery, $request);

        $tickets = $ticketsQuery->paginate(15)->withQueryString();

        return response()->json([
            'data' => collect($tickets->items())->map(fn (Ticket $ticket) => $this->serializeTicketListItem($ticket))->all(),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    /**
     * Return dropdown metadata required by Vue forms.
     */
    public function meta(Request $request): JsonResponse
    {
        $user = $request->user();
        $entities = $this->entitiesForUser($user);
        $entityIds = $entities->pluck('id');

        $contacts = Contact::query()
            ->whereIn('entity_id', $entityIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $operators = User::query()
            ->where('role', 'operator')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'current_user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                ],
                'inboxes' => $this->inboxesForUser($user)->map(fn (Inbox $inbox) => [
                    'id' => $inbox->id,
                    'name' => $inbox->name,
                ])->values(),
                'entities' => $entities->map(fn (Entity $entity) => [
                    'id' => $entity->id,
                    'name' => $entity->name,
                ])->values(),
                'contacts' => $contacts->map(fn (Contact $contact) => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'email' => $contact->email,
                    'entity_id' => $contact->entity_id,
                ])->values(),
                'operators' => $operators->map(fn (User $operator) => [
                    'id' => $operator->id,
                    'name' => $operator->name,
                ])->values(),
                'statuses' => ['open', 'pending', 'resolved', 'closed'],
                'create_statuses' => ['open', 'pending', 'resolved'],
                'priorities' => ['low', 'medium', 'high', 'urgent'],
                'types' => ['question', 'incident', 'request', 'task', 'other'],
            ],
        ]);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Ticket::class);

        $user = $request->user();

        $validated = $request->validate([
            'inbox_id' => ['required', 'integer', Rule::exists('inboxes', 'id')],
            'entity_id' => ['required', 'integer', Rule::exists('entities', 'id')],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'type' => ['required', Rule::in(['question', 'incident', 'request', 'task', 'other'])],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status' => ['nullable', Rule::in(['open', 'pending', 'resolved'])],
            'assigned_operator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'cc_emails' => ['nullable', 'string'],
        ]);

        if ($user->isOperator() && ! $user->hasInboxAccess((int) $validated['inbox_id'])) {
            abort(403);
        }

        if ($user->isClient() && ! $user->entities()->whereKey($validated['entity_id'])->exists()) {
            abort(403);
        }

        $contact = $this->resolveTicketContact($user, (int) $validated['entity_id'], $validated['contact_id'] ?? null);
        $assignedOperator = $this->resolveAssignedOperator($validated['assigned_operator_id'] ?? null, (int) $validated['inbox_id']);
        $ccEmails = $this->parseCcEmails($validated['cc_emails'] ?? null);
        $initialStatus = $user->isOperator() ? ($validated['status'] ?? 'open') : 'open';

        $ticket = DB::transaction(function () use (
            $user,
            $validated,
            $contact,
            $assignedOperator,
            $ccEmails,
            $initialStatus
        ) {
            $ticket = Ticket::query()->create([
                'ticket_number' => 'TMP-'.Str::uuid(),
                'inbox_id' => $validated['inbox_id'],
                'entity_id' => $validated['entity_id'],
                'contact_id' => $contact?->id,
                'created_by_user_id' => $user->id,
                'assigned_operator_id' => $assignedOperator?->id,
                'subject' => $validated['subject'],
                'description' => $validated['description'],
                'status' => $initialStatus,
                'priority' => $validated['priority'],
                'type' => $validated['type'],
                'cc_emails' => $ccEmails,
                'last_activity_at' => now(),
                'resolved_at' => $initialStatus === 'resolved' ? now() : null,
            ]);

            $ticket->update([
                'ticket_number' => sprintf('TC-%06d', $ticket->id),
            ]);

            if ($user->isClient()) {
                TicketMessage::query()->create([
                    'ticket_id' => $ticket->id,
                    'author_type' => 'contact',
                    'author_contact_id' => $contact?->id,
                    'body' => $validated['description'],
                    'is_internal' => false,
                ]);
            } else {
                TicketMessage::query()->create([
                    'ticket_id' => $ticket->id,
                    'author_type' => 'user',
                    'author_user_id' => $user->id,
                    'body' => $validated['description'],
                    'is_internal' => false,
                ]);
            }

            $actor = TicketActivityLogger::actorFor($user, $contact);

            TicketActivityLogger::log(
                $ticket,
                'ticket_created',
                null,
                null,
                $ticket->ticket_number,
                $actor['actor_type'],
                $actor['actor_user_id'],
                $actor['actor_contact_id']
            );

            TicketActivityLogger::log(
                $ticket,
                'message_added',
                null,
                null,
                null,
                $actor['actor_type'],
                $actor['actor_user_id'],
                $actor['actor_contact_id'],
                ['internal' => false, 'initial_message' => true]
            );

            if ($assignedOperator) {
                TicketActivityLogger::log(
                    $ticket,
                    'assignment_updated',
                    'assigned_operator_id',
                    null,
                    $assignedOperator->id,
                    $actor['actor_type'],
                    $actor['actor_user_id'],
                    $actor['actor_contact_id']
                );
            }

            return $ticket;
        });

        return response()->json([
            'message' => 'Ticket criado com sucesso.',
            'data' => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
            ],
        ], 201);
    }

    /**
     * Show ticket detail.
     */
    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        $ticket->load([
            'inbox',
            'entity',
            'contact',
            'creatorUser',
            'assignedOperator',
            'messages.authorUser',
            'messages.authorContact',
            'logs.actorUser',
            'logs.actorContact',
        ]);

        $operators = collect();

        if ($request->user()->isOperator()) {
            $operators = User::query()
                ->where('role', 'operator')
                ->where('is_active', true)
                ->whereHas('accessibleInboxes', fn (Builder $query) => $query->whereKey($ticket->inbox_id))
                ->orderBy('name')
                ->get();
        }

        return response()->json([
            'data' => $this->serializeTicketDetail($ticket, $operators),
        ]);
    }

    /**
     * Update ticket status.
     */
    public function updateStatus(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'pending', 'resolved', 'closed'])],
        ]);

        $oldStatus = $ticket->status;
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return response()->json([
                'message' => 'Estado sem alteracoes.',
                'data' => ['status' => $ticket->status],
            ]);
        }

        $ticket->update([
            'status' => $newStatus,
            'last_activity_at' => now(),
            'resolved_at' => in_array($newStatus, ['resolved', 'closed'], true) ? ($ticket->resolved_at ?? now()) : null,
            'closed_at' => $newStatus === 'closed' ? now() : null,
        ]);

        TicketActivityLogger::log(
            $ticket,
            'status_updated',
            'status',
            $oldStatus,
            $newStatus,
            'user',
            $request->user()->id
        );

        return response()->json([
            'message' => 'Estado atualizado.',
            'data' => ['status' => $ticket->status],
        ]);
    }

    /**
     * Update assigned operator.
     */
    public function updateAssignment(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('assign', $ticket);

        $validated = $request->validate([
            'assigned_operator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $oldOperatorId = $ticket->assigned_operator_id;
        $newOperator = $this->resolveAssignedOperator($validated['assigned_operator_id'] ?? null, $ticket->inbox_id);

        $ticket->update([
            'assigned_operator_id' => $newOperator?->id,
            'last_activity_at' => now(),
        ]);

        TicketActivityLogger::log(
            $ticket,
            'assignment_updated',
            'assigned_operator_id',
            $oldOperatorId,
            $newOperator?->id,
            'user',
            $request->user()->id
        );

        return response()->json([
            'message' => 'Atribuicao atualizada.',
            'data' => ['assigned_operator_id' => $ticket->assigned_operator_id],
        ]);
    }

    /**
     * Apply visibility restrictions based on role.
     */
    private function applyVisibilityScope(Builder $query, User $user): void
    {
        if ($user->isOperator()) {
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

    /**
     * Apply listing filters.
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('inbox_id')) {
            $query->where('inbox_id', $request->integer('inbox_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('assigned_operator_id')) {
            $query->where('assigned_operator_id', $request->integer('assigned_operator_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->integer('entity_id'));
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search'));

            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhereHas('entity', fn (Builder $entityQuery) => $entityQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('email', 'like', "%{$search}%"));
            });
        }
    }

    /**
     * Get inboxes available to user.
     */
    private function inboxesForUser(User $user)
    {
        if ($user->isOperator()) {
            return $user->accessibleInboxes()->where('is_active', true)->orderBy('name')->get();
        }

        return Inbox::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * Get entities available to user.
     */
    private function entitiesForUser(User $user)
    {
        if ($user->isOperator()) {
            return Entity::query()->where('is_active', true)->orderBy('name')->get();
        }

        return $user->entities()->where('entities.is_active', true)->orderBy('entities.name')->get();
    }

    /**
     * Resolve and validate contact for ticket creation.
     */
    private function resolveTicketContact(User $user, int $entityId, ?int $contactId): ?Contact
    {
        if ($contactId) {
            $contact = Contact::query()
                ->whereKey($contactId)
                ->where('entity_id', $entityId)
                ->where('is_active', true)
                ->firstOrFail();

            if ($user->isClient() && $contact->user_id !== $user->id) {
                abort(403);
            }

            return $contact;
        }

        if (! $user->isClient()) {
            return null;
        }

        return Contact::query()
            ->where('entity_id', $entityId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * Resolve assigned operator and ensure inbox permission.
     */
    private function resolveAssignedOperator(?int $operatorId, int $inboxId): ?User
    {
        if (! $operatorId) {
            return null;
        }

        return User::query()
            ->whereKey($operatorId)
            ->where('role', 'operator')
            ->where('is_active', true)
            ->whereHas('accessibleInboxes', fn (Builder $query) => $query->whereKey($inboxId))
            ->firstOrFail();
    }

    /**
     * Parse and validate CC email values.
     *
     * @return list<string>
     */
    private function parseCcEmails(?string $rawEmails): array
    {
        if (! $rawEmails) {
            return [];
        }

        $emails = collect(explode(',', $rawEmails))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->unique()
            ->values();

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'cc_emails' => "Email invalido em Conhecimento: {$email}",
                ]);
            }
        }

        return $emails->all();
    }

    /**
     * Serialize ticket list row.
     *
     * @return array<string, mixed>
     */
    private function serializeTicketListItem(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'type' => $ticket->type,
            'last_activity_at' => optional($ticket->last_activity_at ?? $ticket->updated_at)?->toIso8601String(),
            'inbox' => $ticket->inbox ? ['id' => $ticket->inbox->id, 'name' => $ticket->inbox->name] : null,
            'entity' => $ticket->entity ? ['id' => $ticket->entity->id, 'name' => $ticket->entity->name] : null,
            'contact' => $ticket->contact ? ['id' => $ticket->contact->id, 'name' => $ticket->contact->name, 'email' => $ticket->contact->email] : null,
            'assigned_operator' => $ticket->assignedOperator ? ['id' => $ticket->assignedOperator->id, 'name' => $ticket->assignedOperator->name] : null,
        ];
    }

    /**
     * Serialize ticket detail payload.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $operators
     * @return array<string, mixed>
     */
    private function serializeTicketDetail(Ticket $ticket, $operators): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'type' => $ticket->type,
            'cc_emails' => $ticket->cc_emails ?? [],
            'created_at' => optional($ticket->created_at)?->toIso8601String(),
            'last_activity_at' => optional($ticket->last_activity_at ?? $ticket->updated_at)?->toIso8601String(),
            'inbox' => $ticket->inbox ? ['id' => $ticket->inbox->id, 'name' => $ticket->inbox->name] : null,
            'entity' => $ticket->entity ? ['id' => $ticket->entity->id, 'name' => $ticket->entity->name] : null,
            'contact' => $ticket->contact ? ['id' => $ticket->contact->id, 'name' => $ticket->contact->name, 'email' => $ticket->contact->email] : null,
            'creator_user' => $ticket->creatorUser ? ['id' => $ticket->creatorUser->id, 'name' => $ticket->creatorUser->name] : null,
            'assigned_operator' => $ticket->assignedOperator ? ['id' => $ticket->assignedOperator->id, 'name' => $ticket->assignedOperator->name] : null,
            'messages' => $ticket->messages->sortByDesc('created_at')->values()->map(fn (TicketMessage $message) => [
                'id' => $message->id,
                'author_type' => $message->author_type,
                'author_user' => $message->authorUser ? ['id' => $message->authorUser->id, 'name' => $message->authorUser->name] : null,
                'author_contact' => $message->authorContact ? ['id' => $message->authorContact->id, 'name' => $message->authorContact->name] : null,
                'body' => $message->body,
                'is_internal' => $message->is_internal,
                'created_at' => optional($message->created_at)?->toIso8601String(),
            ])->all(),
            'logs' => $ticket->logs->sortByDesc('created_at')->values()->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'field' => $log->field,
                'old_value' => $log->old_value,
                'new_value' => $log->new_value,
                'actor_type' => $log->actor_type,
                'actor_user' => $log->actorUser ? ['id' => $log->actorUser->id, 'name' => $log->actorUser->name] : null,
                'actor_contact' => $log->actorContact ? ['id' => $log->actorContact->id, 'name' => $log->actorContact->name] : null,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ])->all(),
            'operators' => $operators->map(fn (User $operator) => [
                'id' => $operator->id,
                'name' => $operator->name,
            ])->values()->all(),
        ];
    }
}

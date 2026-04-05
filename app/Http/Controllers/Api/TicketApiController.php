<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Entity;
use App\Models\Inbox;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\TicketStatus;
use App\Models\TicketType;
use App\Models\User;
use App\Models\UserPinnedTicket;
use App\Services\TicketNotificationService;
use App\Support\TicketActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketApiController extends Controller
{
    public function __construct(
        private readonly TicketNotificationService $notificationService
    ) {
    }

    /**
     * List tickets visible to current user with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $ticketsQuery = Ticket::query()
            ->with(['inbox', 'entity', 'contact', 'assignedOperator', 'creatorUser', 'creatorContact']);

        $this->applyVisibilityScope($ticketsQuery, $user);
        $this->applyFilters($ticketsQuery, $request);
        $this->applySorting($ticketsQuery, $request);

        $tickets = $ticketsQuery->paginate(15)->withQueryString();
        $ticketItems = collect($tickets->items());
        $pinnedLookup = $this->pinnedTicketLookup(
            $user,
            $ticketItems
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all()
        );

        return response()->json([
            'data' => $ticketItems
                ->map(
                    fn (Ticket $ticket) => $this->serializeTicketListItem(
                        $ticket,
                        isset($pinnedLookup[(int) $ticket->id])
                    )
                )
                ->all(),
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
        $followers = $this->followerCandidatesForUser($user);
        $ticketStatuses = $this->ticketStatuses();
        $ticketTypes = $this->ticketTypes();
        $triageInbox = $this->triageInbox();

        $contacts = Contact::query()
            ->with('entities:id,name')
            ->whereHas('entities', fn (Builder $query) => $query->whereIn('entities.id', $entityIds))
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
                    'avatar_url' => $this->avatarUrlForUser($user),
                ],
                'triage_inbox' => $triageInbox ? [
                    'id' => $triageInbox->id,
                    'name' => $triageInbox->name,
                ] : null,
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
                    'entity_ids' => $contact->entities->pluck('id')->values()->all(),
                ])->values(),
                'operators' => $operators->map(fn (User $operator) => [
                    'id' => $operator->id,
                    'name' => $operator->name,
                    'email' => $operator->email,
                    'avatar_url' => $this->avatarUrlForUser($operator),
                ])->values(),
                'followers' => $followers->map(fn (User $follower) => [
                    'id' => $follower->id,
                    'name' => $follower->name,
                    'email' => $follower->email,
                    'role' => $follower->role,
                    'avatar_url' => $this->avatarUrlForUser($follower),
                    'inbox_ids' => $this->candidateInboxIdsForFollower($follower),
                ])->values(),
                'statuses' => $ticketStatuses->pluck('code')->values(),
                'create_statuses' => $ticketStatuses
                    ->where('is_initial', true)
                    ->pluck('code')
                    ->values(),
                'priorities' => ['low', 'medium', 'high', 'urgent'],
                'types' => $ticketTypes->pluck('code')->values(),
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
        $mustUseTriageInbox = $this->mustUseTriageInbox($user);

        $validated = $request->validate([
            'inbox_id' => ['required', 'integer', Rule::exists('inboxes', 'id')],
            'entity_id' => ['required', 'integer', Rule::exists('entities', 'id')],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'required_without:attachments'],
            'description_format' => ['nullable', Rule::in(['plain', 'html'])],
            'type' => ['required', Rule::exists('ticket_types', 'code')],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'status' => ['nullable', Rule::exists('ticket_statuses', 'code')->where(fn ($query) => $query->where('is_initial', true))],
            'assigned_operator_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'cc_emails' => ['nullable', 'string'],
            'follower_user_ids' => ['nullable', 'array'],
            'follower_user_ids.*' => ['integer', Rule::exists('users', 'id')],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,txt,csv,zip'],
        ]);

        $targetInboxId = (int) $validated['inbox_id'];
        if ($mustUseTriageInbox) {
            $triageInbox = $this->triageInbox();
            if (! $triageInbox) {
                throw ValidationException::withMessages([
                    'inbox_id' => 'Inbox Geral is not configured.',
                ]);
            }

            $targetInboxId = (int) $triageInbox->id;
        }

        if (
            $user->isOperator()
            && ! $mustUseTriageInbox
            && ! $user->hasInboxAccess($targetInboxId)
        ) {
            abort(403);
        }

        if ($user->isClient() && ! $user->entities()->whereKey($validated['entity_id'])->exists()) {
            abort(403);
        }

        if (
            ! empty($validated['assigned_operator_id'])
            && ! ($user->isAdmin() || $user->hasInboxManagementAccess($targetInboxId))
        ) {
            throw ValidationException::withMessages([
                'assigned_operator_id' => 'Only inbox managers can assign operators when creating tickets.',
            ]);
        }

        $contact = $this->resolveTicketContact($user, (int) $validated['entity_id'], $validated['contact_id'] ?? null);
        $assignedOperator = $this->resolveAssignedOperator($validated['assigned_operator_id'] ?? null, $targetInboxId);
        $ccEmails = $this->parseCcEmails($validated['cc_emails'] ?? null);
        $followerUserIds = $this->parseFollowerUserIds($validated['follower_user_ids'] ?? [], $user, $targetInboxId);
        $descriptionFormat = $this->normalizeMessageFormat($validated['description_format'] ?? null);
        $descriptionBody = $this->normalizeMessageBody($validated['description'] ?? '', $descriptionFormat);
        $initialStatus = $mustUseTriageInbox ? 'open' : ($validated['status'] ?? 'open');
        /** @var array<int, UploadedFile> $files */
        $files = $request->file('attachments', []);

        if ($descriptionBody === '' && $files === []) {
            throw ValidationException::withMessages([
                'description' => 'Message body is required when no attachments are provided.',
            ]);
        }

        $ticket = DB::transaction(function () use (
            $user,
            $validated,
            $contact,
            $assignedOperator,
            $ccEmails,
            $followerUserIds,
            $initialStatus,
            $descriptionFormat,
            $descriptionBody,
            $targetInboxId,
            $files
        ) {
            $ticket = Ticket::query()->create([
                'ticket_number' => 'TMP-'.(string) Str::ulid(),
                'inbox_id' => $targetInboxId,
                'entity_id' => $validated['entity_id'],
                'contact_id' => $contact?->id,
                'creator_contact_id' => $contact?->id,
                'created_by_user_id' => $user->id,
                'assigned_operator_id' => $assignedOperator?->id,
                'subject' => $validated['subject'],
                'description' => $descriptionBody,
                'status' => $initialStatus,
                'priority' => $validated['priority'],
                'type' => $validated['type'],
                'cc_emails' => $ccEmails,
                'last_activity_at' => now(),
                'first_response_at' => $user->isOperator() ? now() : null,
                'resolved_at' => null,
                'closed_at' => null,
            ]);

            $ticket->update([
                'ticket_number' => sprintf('TC-%06d', $ticket->id),
            ]);

            if ($followerUserIds !== []) {
                $ticket->followers()->sync($followerUserIds);
            }

            $message = TicketMessage::query()->create([
                'ticket_id' => $ticket->id,
                'author_type' => $user->isClient() ? 'contact' : 'user',
                'author_user_id' => $user->isOperator() ? $user->id : null,
                'author_contact_id' => $user->isClient() ? $contact?->id : null,
                'body' => $descriptionBody,
                'body_format' => $descriptionFormat,
                'is_internal' => false,
            ]);

            $attachmentsPayload = [];
            $disk = (string) config('supportdesk.attachments.disk', 'local');

            foreach ($files as $file) {
                $storedPath = $file->store("ticket-attachments/{$ticket->id}/{$message->id}", $disk);

                $attachment = TicketAttachment::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'ticket_id' => $ticket->id,
                    'ticket_message_id' => $message->id,
                    'disk' => $disk,
                    'path' => $storedPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by_user_id' => $user->isOperator() ? $user->id : null,
                    'uploaded_by_contact_id' => $user->isClient() ? $contact?->id : null,
                ]);

                $attachmentsPayload[] = [
                    'id' => $attachment->id,
                    'uuid' => $attachment->uuid,
                    'name' => $attachment->original_name,
                    'mime' => $attachment->mime_type,
                    'size' => $attachment->size,
                ];
            }

            if ($attachmentsPayload !== []) {
                $message->update([
                    'attachments' => $attachmentsPayload,
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
                ['internal' => false, 'initial_message' => true, 'message_id' => $message->id]
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

            if ($attachmentsPayload !== []) {
                TicketActivityLogger::log(
                    $ticket,
                    'attachments_added',
                    null,
                    null,
                    null,
                    $actor['actor_type'],
                    $actor['actor_user_id'],
                    $actor['actor_contact_id'],
                    [
                        'initial_message' => true,
                        'message_id' => $message->id,
                        'attachments' => array_map(fn (array $attachment) => $attachment['name'], $attachmentsPayload),
                    ]
                );
            }

            if ($followerUserIds !== []) {
                TicketActivityLogger::log(
                    $ticket,
                    'field_updated',
                    'follower_user_ids',
                    '',
                    implode(', ', $followerUserIds),
                    $actor['actor_type'],
                    $actor['actor_user_id'],
                    $actor['actor_contact_id']
                );
            }

            return $ticket;
        });

        $this->notificationService->notifyTicketCreated($ticket);

        return response()->json([
            'message' => 'Ticket created successfully.',
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
            'creatorContact',
            'creatorUser',
            'assignedOperator',
            'messages.authorUser',
            'messages.authorContact',
            'messages.attachmentsList',
            'logs.actorUser',
            'logs.actorContact',
            'followers',
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

        $availableInboxes = $this->inboxesForUser($request->user());
        $availableEntities = $this->entitiesForUser($request->user());
        $availableContacts = Contact::query()
            ->with('entities:id,name')
            ->whereHas('entities', fn (Builder $query) => $query->whereIn('entities.id', $availableEntities->pluck('id')))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $availableFollowers = $this->followerCandidatesForUser($request->user(), (int) $ticket->inbox_id);
        $navigation = $this->adjacentTickets($ticket, $request->user());
        $isPinned = UserPinnedTicket::query()
            ->where('user_id', $request->user()->id)
            ->where('ticket_id', $ticket->id)
            ->exists();

        return response()->json([
            'data' => $this->serializeTicketDetail(
                $ticket,
                $operators,
                $availableInboxes,
                $availableEntities,
                $availableContacts,
                $availableFollowers,
                $isPinned,
                $navigation,
                $request
            ),
        ]);
    }

    /**
     * Full update for ticket data.
     */
    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('updateMetadata', $ticket);

        $validated = $request->validate([
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'type' => ['sometimes', Rule::exists('ticket_types', 'code')],
            'status' => ['sometimes', Rule::exists('ticket_statuses', 'code')],
            'inbox_id' => ['sometimes', 'integer', Rule::exists('inboxes', 'id')],
            'entity_id' => ['sometimes', 'integer', Rule::exists('entities', 'id')],
            'contact_id' => ['sometimes', 'nullable', 'integer', Rule::exists('contacts', 'id')],
            'assigned_operator_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'cc_emails' => ['sometimes', 'nullable', 'string'],
            'follower_user_ids' => ['sometimes', 'array'],
            'follower_user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'No changes to apply.',
            ]);
        }

        $user = $request->user();
        $updates = [];
        $changes = [];
        $followerSyncIds = null;
        $statusChanged = false;
        $assignmentChanged = false;
        $knowledgeChanged = false;

        $targetInboxId = (int) ($validated['inbox_id'] ?? $ticket->inbox_id);
        $targetEntityId = (int) ($validated['entity_id'] ?? $ticket->entity_id);
        $inboxChanged = array_key_exists('inbox_id', $validated) && $targetInboxId !== (int) $ticket->inbox_id;

        if ($inboxChanged) {
            if (! $user->isAdmin()) {
                abort(403, 'Only admins can move tickets between inboxes.');
            }

            if (! $user->hasInboxAccess($targetInboxId)) {
                abort(403, 'You cannot move this ticket to an inbox you cannot access.');
            }

            $updates['inbox_id'] = $targetInboxId;
            $changes[] = ['field' => 'inbox_id', 'old' => $ticket->inbox_id, 'new' => $targetInboxId];
        }

        if (array_key_exists('entity_id', $validated) && $targetEntityId !== (int) $ticket->entity_id) {
            $updates['entity_id'] = $targetEntityId;
            $changes[] = ['field' => 'entity_id', 'old' => $ticket->entity_id, 'new' => $targetEntityId];
        }

        if (array_key_exists('subject', $validated) && $validated['subject'] !== $ticket->subject) {
            $updates['subject'] = $validated['subject'];
            $changes[] = ['field' => 'subject', 'old' => $ticket->subject, 'new' => $validated['subject']];
        }

        if (array_key_exists('description', $validated) && $validated['description'] !== $ticket->description) {
            $updates['description'] = $validated['description'];
            $changes[] = ['field' => 'description', 'old' => $ticket->description, 'new' => $validated['description']];
        }

        if (array_key_exists('priority', $validated) && $validated['priority'] !== $ticket->priority) {
            $updates['priority'] = $validated['priority'];
            $changes[] = ['field' => 'priority', 'old' => $ticket->priority, 'new' => $validated['priority']];
        }

        if (array_key_exists('type', $validated) && $validated['type'] !== $ticket->type) {
            $updates['type'] = $validated['type'];
            $changes[] = ['field' => 'type', 'old' => $ticket->type, 'new' => $validated['type']];
        }

        if (array_key_exists('cc_emails', $validated)) {
            $newCc = $this->parseCcEmails($validated['cc_emails']);
            $currentCc = collect($ticket->cc_emails ?? [])
                ->map(fn (string $email) => mb_strtolower(trim($email)))
                ->sort()
                ->values()
                ->all();
            $normalizedNewCc = collect($newCc)
                ->map(fn (string $email) => mb_strtolower(trim($email)))
                ->sort()
                ->values()
                ->all();

            if ($currentCc !== $normalizedNewCc) {
                $updates['cc_emails'] = $newCc;
                $changes[] = [
                    'field' => 'cc_emails',
                    'old' => implode(', ', $ticket->cc_emails ?? []),
                    'new' => implode(', ', $newCc),
                ];
                $knowledgeChanged = true;
            }
        }

        if (array_key_exists('follower_user_ids', $validated)) {
            $newFollowerIds = $this->parseFollowerUserIds($validated['follower_user_ids'], $user, $targetInboxId);
            $currentFollowerIds = $ticket->followers()
                ->pluck('users.id')
                ->map(fn (int|string $id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            $normalizedNewFollowerIds = collect($newFollowerIds)
                ->map(fn (int $id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($currentFollowerIds !== $normalizedNewFollowerIds) {
                $followerSyncIds = $newFollowerIds;
                $changes[] = [
                    'field' => 'follower_user_ids',
                    'old' => implode(', ', $currentFollowerIds),
                    'new' => implode(', ', $newFollowerIds),
                ];
                $knowledgeChanged = true;
            }
        }

        if ($inboxChanged && ! array_key_exists('follower_user_ids', $validated)) {
            [$newFollowerIds, $removedFollowerIds] = $this->filterFollowerIdsForInbox($ticket, $targetInboxId);

            if ($removedFollowerIds !== []) {
                $followerSyncIds = $newFollowerIds;
                $changes[] = [
                    'field' => 'follower_user_ids',
                    'old' => implode(', ', $ticket->followers()->pluck('users.id')->all()),
                    'new' => implode(', ', $newFollowerIds),
                ];
                $knowledgeChanged = true;
            }
        }

        if (array_key_exists('contact_id', $validated) || array_key_exists('entity_id', $validated)) {
            $newContactId = $validated['contact_id'] ?? $ticket->contact_id;

            if ($newContactId === null) {
                if ($ticket->contact_id !== null) {
                    $updates['contact_id'] = null;
                    $changes[] = ['field' => 'contact_id', 'old' => $ticket->contact_id, 'new' => null];
                }
            } else {
                $contact = Contact::query()
                    ->whereKey((int) $newContactId)
                    ->whereHas('entities', fn (Builder $query) => $query->whereKey($targetEntityId))
                    ->where('is_active', true)
                    ->firstOrFail();

                if ((int) $ticket->contact_id !== (int) $contact->id) {
                    $updates['contact_id'] = $contact->id;
                    $changes[] = ['field' => 'contact_id', 'old' => $ticket->contact_id, 'new' => $contact->id];
                }
            }
        }

        if (array_key_exists('assigned_operator_id', $validated) || $inboxChanged) {
            if (array_key_exists('assigned_operator_id', $validated) && ! $user->can('assign', $ticket)) {
                abort(403, 'Only admins or inbox managers can assign operators.');
            }

            $candidateOperatorId = array_key_exists('assigned_operator_id', $validated)
                ? $validated['assigned_operator_id']
                : $ticket->assigned_operator_id;

            if (
                $inboxChanged
                && ! array_key_exists('assigned_operator_id', $validated)
                && $ticket->assignedOperator
                && ! $ticket->assignedOperator->hasInboxAccess($targetInboxId)
            ) {
                $candidateOperatorId = null;
            }

            $newOperator = $this->resolveAssignedOperator(
                $candidateOperatorId,
                $targetInboxId
            );

            if ($ticket->assigned_operator_id !== $newOperator?->id) {
                $updates['assigned_operator_id'] = $newOperator?->id;
                $changes[] = [
                    'field' => 'assigned_operator_id',
                    'old' => $ticket->assigned_operator_id,
                    'new' => $newOperator?->id,
                ];
                $assignmentChanged = true;
            }
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== $ticket->status) {
            $newStatus = $validated['status'];

            if (
                in_array($newStatus, ['closed', 'cancelled'], true)
                && $ticket->assigned_operator_id
                && $ticket->assigned_operator_id !== $request->user()->id
            ) {
                abort(403, 'Only the assigned operator can close or cancel this ticket.');
            }

            $updates['status'] = $newStatus;

            foreach ($this->statusTimestampUpdates($ticket, $newStatus) as $field => $value) {
                $updates[$field] = $value;
            }

            $changes[] = ['field' => 'status', 'old' => $ticket->status, 'new' => $newStatus];
            $statusChanged = true;
        }

        if ($changes === []) {
            return response()->json([
                'message' => 'No changes to apply.',
                'data' => [
                    'ticket_id' => $ticket->id,
                ],
            ]);
        }

        $updates['last_activity_at'] = now();

        DB::transaction(function () use ($ticket, $updates, $changes, $followerSyncIds, $user): void {
            $ticket->update($updates);

            if (is_array($followerSyncIds)) {
                $ticket->followers()->sync($followerSyncIds);
            }

            foreach ($changes as $change) {
                $action = match ($change['field']) {
                    'status' => 'status_updated',
                    'assigned_operator_id' => 'assignment_updated',
                    default => 'field_updated',
                };

                TicketActivityLogger::log(
                    $ticket,
                    $action,
                    $change['field'],
                    $change['old'],
                    $change['new'],
                    'user',
                    $user->id
                );
            }
        });

        $ticket->refresh();

        if ($statusChanged) {
            $this->notificationService->notifyStatusUpdated($ticket);
        }

        if ($assignmentChanged) {
            $this->notificationService->notifyAssignmentUpdated($ticket);
        }

        if ($knowledgeChanged) {
            $this->notificationService->notifyKnowledgeUpdated($ticket);
        }

        return response()->json([
            'message' => 'Ticket updated successfully.',
            'data' => [
                'ticket_id' => $ticket->id,
            ],
        ]);
    }

    /**
     * Bulk update ticket status and/or assignment.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ticket_ids' => ['required', 'array', 'min:1', 'max:200'],
            'ticket_ids.*' => ['integer', 'distinct', Rule::exists('tickets', 'id')],
            'status' => ['nullable', Rule::exists('ticket_statuses', 'code')],
            'assigned_operator_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $applyStatus = array_key_exists('status', $validated)
            && $validated['status'] !== null
            && $validated['status'] !== '';
        $applyAssignment = array_key_exists('assigned_operator_id', $validated);

        if (! $applyStatus && ! $applyAssignment) {
            throw ValidationException::withMessages([
                'status' => 'Indica um estado e/ou operador para aplicar em lote.',
            ]);
        }

        $ticketIds = collect($validated['ticket_ids'])
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $ticketsById = Ticket::query()
            ->whereIn('id', $ticketIds)
            ->with(['assignedOperator'])
            ->get()
            ->keyBy('id');

        $updated = 0;
        $statusUpdated = 0;
        $assignmentUpdated = 0;
        $unchanged = 0;
        $skipped = [];

        foreach ($ticketIds as $ticketId) {
            /** @var Ticket|null $ticket */
            $ticket = $ticketsById->get($ticketId);

            if (! $ticket) {
                $skipped[] = [
                    'ticket_id' => $ticketId,
                    'ticket_number' => null,
                    'reason' => 'Ticket não encontrado.',
                ];
                continue;
            }

            $updates = [];
            $skipReasons = [];
            $statusChanged = false;
            $assignmentChanged = false;
            $oldStatus = null;
            $newStatus = null;
            $oldOperatorId = null;
            $newOperatorId = null;

            if ($applyStatus) {
                if (! $user->can('updateStatus', $ticket)) {
                    $skipReasons[] = 'Sem permissão para alterar estado.';
                } else {
                    $candidateStatus = (string) $validated['status'];

                    if ($ticket->status !== $candidateStatus) {
                        if (
                            in_array($candidateStatus, ['closed', 'cancelled'], true)
                            && $ticket->assigned_operator_id
                            && (int) $ticket->assigned_operator_id !== (int) $user->id
                        ) {
                            $skipReasons[] = 'Apenas o operador atribuído pode fechar/cancelar.';
                        } else {
                            $oldStatus = (string) $ticket->status;
                            $newStatus = $candidateStatus;
                            $updates['status'] = $candidateStatus;

                            foreach ($this->statusTimestampUpdates($ticket, $candidateStatus) as $field => $value) {
                                $updates[$field] = $value;
                            }

                            $statusChanged = true;
                        }
                    }
                }
            }

            if ($applyAssignment) {
                if (! $user->can('assign', $ticket)) {
                    $skipReasons[] = 'Sem permissão para alterar atribuição.';
                } else {
                    $newOperator = $this->resolveAssignedOperator(
                        $validated['assigned_operator_id'] ?? null,
                        (int) $ticket->inbox_id
                    );

                    if ((int) ($ticket->assigned_operator_id ?? 0) !== (int) ($newOperator?->id ?? 0)) {
                        $oldOperatorId = $ticket->assigned_operator_id;
                        $newOperatorId = $newOperator?->id;
                        $updates['assigned_operator_id'] = $newOperatorId;
                        $assignmentChanged = true;
                    }
                }
            }

            if ($updates === []) {
                if ($skipReasons !== []) {
                    $skipped[] = [
                        'ticket_id' => $ticket->id,
                        'ticket_number' => $ticket->ticket_number,
                        'reason' => implode(' ', array_unique($skipReasons)),
                    ];
                } else {
                    $unchanged++;
                }
                continue;
            }

            $updates['last_activity_at'] = now();

            DB::transaction(function () use (
                $ticket,
                $updates,
                $statusChanged,
                $oldStatus,
                $newStatus,
                $assignmentChanged,
                $oldOperatorId,
                $newOperatorId,
                $user
            ): void {
                $ticket->update($updates);

                if ($statusChanged) {
                    TicketActivityLogger::log(
                        $ticket,
                        'status_updated',
                        'status',
                        $oldStatus,
                        $newStatus,
                        'user',
                        $user->id
                    );
                }

                if ($assignmentChanged) {
                    TicketActivityLogger::log(
                        $ticket,
                        'assignment_updated',
                        'assigned_operator_id',
                        $oldOperatorId,
                        $newOperatorId,
                        'user',
                        $user->id
                    );
                }
            });

            $ticket->refresh();

            if ($statusChanged) {
                $this->notificationService->notifyStatusUpdated($ticket);
                $statusUpdated++;
            }

            if ($assignmentChanged) {
                $this->notificationService->notifyAssignmentUpdated($ticket);
                $assignmentUpdated++;
            }

            $updated++;
        }

        return response()->json([
            'message' => 'Atualização em lote concluída.',
            'data' => [
                'requested' => count($ticketIds),
                'updated' => $updated,
                'status_updated' => $statusUpdated,
                'assignment_updated' => $assignmentUpdated,
                'unchanged' => $unchanged,
                'skipped_count' => count($skipped),
                'skipped' => $skipped,
            ],
        ]);
    }

    /**
     * Cancel a ticket using the status lifecycle.
     */
    public function destroy(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('updateStatus', $ticket);

        if ($ticket->status === 'cancelled') {
            return response()->json([
                'message' => 'Ticket already cancelled.',
            ]);
        }

        if ($ticket->assigned_operator_id && $ticket->assigned_operator_id !== $request->user()->id) {
            abort(403, 'Only the assigned operator can close or cancel this ticket.');
        }

        $oldStatus = $ticket->status;

        $ticket->update(array_merge([
            'status' => 'cancelled',
            'last_activity_at' => now(),
        ], $this->statusTimestampUpdates($ticket, 'cancelled')));

        TicketActivityLogger::log(
            $ticket,
            'status_updated',
            'status',
            $oldStatus,
            'cancelled',
            'user',
            $request->user()->id
        );

        $this->notificationService->notifyStatusUpdated($ticket);

        return response()->json([
            'message' => 'Ticket cancelled successfully.',
            'data' => ['status' => $ticket->status],
        ]);
    }

    /**
     * Update ticket status.
     */
    public function updateStatus(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('updateStatus', $ticket);

        $validated = $request->validate([
            'status' => ['required', Rule::exists('ticket_statuses', 'code')],
        ]);

        $oldStatus = $ticket->status;
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return response()->json([
                'message' => 'Status unchanged.',
                'data' => ['status' => $ticket->status],
            ]);
        }

        if (
            in_array($newStatus, ['closed', 'cancelled'], true)
            && $ticket->assigned_operator_id
            && $ticket->assigned_operator_id !== $request->user()->id
        ) {
            abort(403, 'Only the assigned operator can close or cancel this ticket.');
        }

        $ticket->update(array_merge([
            'status' => $newStatus,
            'last_activity_at' => now(),
        ], $this->statusTimestampUpdates($ticket, $newStatus)));

        TicketActivityLogger::log(
            $ticket,
            'status_updated',
            'status',
            $oldStatus,
            $newStatus,
            'user',
            $request->user()->id
        );

        $this->notificationService->notifyStatusUpdated($ticket);

        return response()->json([
            'message' => 'Status updated.',
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

        if ($oldOperatorId === $newOperator?->id) {
            return response()->json([
                'message' => 'Assignment unchanged.',
                'data' => ['assigned_operator_id' => $ticket->assigned_operator_id],
            ]);
        }

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

        $this->notificationService->notifyAssignmentUpdated($ticket);

        return response()->json([
            'message' => 'Assignment updated.',
            'data' => ['assigned_operator_id' => $ticket->assigned_operator_id],
        ]);
    }

    /**
     * Update ticket metadata fields.
     */
    public function updateMetadata(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('updateMetadata', $ticket);

        $validated = $request->validate([
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'type' => ['sometimes', Rule::exists('ticket_types', 'code')],
            'inbox_id' => ['sometimes', 'integer', Rule::exists('inboxes', 'id')],
            'cc_emails' => ['sometimes', 'nullable', 'string'],
            'follower_user_ids' => ['sometimes', 'array'],
            'follower_user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'No changes to apply.',
            ]);
        }

        $user = $request->user();
        $updates = [];
        $changes = [];
        $followerSyncIds = null;
        $knowledgeChanged = false;
        $targetInboxId = (int) ($validated['inbox_id'] ?? $ticket->inbox_id);
        $inboxChanged = array_key_exists('inbox_id', $validated) && $targetInboxId !== (int) $ticket->inbox_id;

        if (array_key_exists('subject', $validated) && $validated['subject'] !== $ticket->subject) {
            $updates['subject'] = $validated['subject'];
            $changes[] = ['field' => 'subject', 'old' => $ticket->subject, 'new' => $validated['subject']];
        }

        if (array_key_exists('priority', $validated) && $validated['priority'] !== $ticket->priority) {
            $updates['priority'] = $validated['priority'];
            $changes[] = ['field' => 'priority', 'old' => $ticket->priority, 'new' => $validated['priority']];
        }

        if (array_key_exists('type', $validated) && $validated['type'] !== $ticket->type) {
            $updates['type'] = $validated['type'];
            $changes[] = ['field' => 'type', 'old' => $ticket->type, 'new' => $validated['type']];
        }

        if ($inboxChanged) {
            if (! $user->isAdmin()) {
                abort(403, 'Only admins can move tickets between inboxes.');
            }

            if (! $user->hasInboxAccess($targetInboxId)) {
                abort(403, 'You cannot move this ticket to an inbox you cannot access.');
            }

            $updates['inbox_id'] = $targetInboxId;
            $changes[] = ['field' => 'inbox_id', 'old' => $ticket->inbox_id, 'new' => $targetInboxId];

            if ($ticket->assignedOperator && ! $ticket->assignedOperator->hasInboxAccess($targetInboxId)) {
                $changes[] = [
                    'field' => 'assigned_operator_id',
                    'old' => $ticket->assigned_operator_id,
                    'new' => null,
                ];

                $updates['assigned_operator_id'] = null;
            }
        }

        if (array_key_exists('cc_emails', $validated)) {
            $newCc = $this->parseCcEmails($validated['cc_emails']);
            $currentCc = collect($ticket->cc_emails ?? [])->map(fn (string $email) => mb_strtolower(trim($email)))->sort()->values()->all();
            $normalizedNewCc = collect($newCc)->map(fn (string $email) => mb_strtolower(trim($email)))->sort()->values()->all();

            if ($currentCc !== $normalizedNewCc) {
                $updates['cc_emails'] = $newCc;
                $changes[] = [
                    'field' => 'cc_emails',
                    'old' => implode(', ', $ticket->cc_emails ?? []),
                    'new' => implode(', ', $newCc),
                ];
                $knowledgeChanged = true;
            }
        }

        if (array_key_exists('follower_user_ids', $validated)) {
            $newFollowerIds = $this->parseFollowerUserIds($validated['follower_user_ids'], $user, $targetInboxId);
            $currentFollowerIds = $ticket->followers()
                ->pluck('users.id')
                ->map(fn (int|string $id) => (int) $id)
                ->sort()
                ->values()
                ->all();
            $normalizedNewFollowerIds = collect($newFollowerIds)
                ->map(fn (int $id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            if ($currentFollowerIds !== $normalizedNewFollowerIds) {
                $followerSyncIds = $newFollowerIds;
                $changes[] = [
                    'field' => 'follower_user_ids',
                    'old' => implode(', ', $currentFollowerIds),
                    'new' => implode(', ', $newFollowerIds),
                ];
                $knowledgeChanged = true;
            }
        }

        if ($inboxChanged && ! array_key_exists('follower_user_ids', $validated)) {
            [$newFollowerIds, $removedFollowerIds] = $this->filterFollowerIdsForInbox($ticket, $targetInboxId);

            if ($removedFollowerIds !== []) {
                $followerSyncIds = $newFollowerIds;
                $changes[] = [
                    'field' => 'follower_user_ids',
                    'old' => implode(', ', $ticket->followers()->pluck('users.id')->all()),
                    'new' => implode(', ', $newFollowerIds),
                ];
                $knowledgeChanged = true;
            }
        }

        if ($changes === []) {
            return response()->json([
                'message' => 'No changes to apply.',
                'data' => [
                    'ticket_id' => $ticket->id,
                ],
            ]);
        }

        $updates['last_activity_at'] = now();

        DB::transaction(function () use ($ticket, $updates, $changes, $followerSyncIds, $user): void {
            $ticket->update($updates);

            if (is_array($followerSyncIds)) {
                $ticket->followers()->sync($followerSyncIds);
            }

            foreach ($changes as $change) {
                $action = $change['field'] === 'assigned_operator_id'
                    ? 'assignment_updated'
                    : 'field_updated';

                TicketActivityLogger::log(
                    $ticket,
                    $action,
                    $change['field'],
                    $change['old'],
                    $change['new'],
                    'user',
                    $user->id
                );
            }
        });

        $ticket->refresh();

        if ($knowledgeChanged) {
            $this->notificationService->notifyKnowledgeUpdated($ticket);
        }

        return response()->json([
            'message' => 'Metadata updated.',
            'data' => [
                'ticket_id' => $ticket->id,
            ],
        ]);
    }

    /**
     * Apply visibility restrictions based on role.
     */
    private function applyVisibilityScope(Builder $query, User $user): void
    {
        if ($user->isOperator()) {
            if ($user->isAdmin()) {
                return;
            }

            $inboxIds = $user->accessibleInboxes()
                ->pluck('inboxes.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $query->where(function (Builder $visibilityQuery) use ($user, $inboxIds): void {
                if ($inboxIds !== []) {
                    $visibilityQuery->whereIn('inbox_id', $inboxIds);
                    $visibilityQuery->orWhere('created_by_user_id', $user->id);
                    return;
                }

                $visibilityQuery->where('created_by_user_id', $user->id);
            });

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

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->integer('entity_id'));
        }

        if ($request->filled('created_by_user_id')) {
            $query->where('created_by_user_id', $request->integer('created_by_user_id'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));

            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhereHas('entity', fn (Builder $entityQuery) => $entityQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('email', 'like', "%{$search}%"));
            });
        }
    }

    /**
     * Apply listing sorting.
     */
    private function applySorting(Builder $query, Request $request): void
    {
        $allowedSortBy = [
            'ticket_number',
            'subject',
            'priority',
            'type',
            'status',
            'request_date',
            'last_activity_at',
            'client',
        ];

        $sortBy = trim((string) $request->string('sort_by', 'last_activity_at'));
        $sortDir = strtolower(trim((string) $request->string('sort_dir', 'desc'))) === 'asc' ? 'asc' : 'desc';

        if (! in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'last_activity_at';
        }

        switch ($sortBy) {
            case 'ticket_number':
            case 'subject':
            case 'priority':
            case 'type':
            case 'status':
            case 'last_activity_at':
                $query->orderBy($sortBy, $sortDir);
                break;

            case 'request_date':
                $query->orderBy('created_at', $sortDir);
                break;

            case 'client':
                $query->orderByRaw(
                    "COALESCE((SELECT `name` FROM `contacts` WHERE `contacts`.`id` = `tickets`.`contact_id` LIMIT 1), (SELECT `name` FROM `entities` WHERE `entities`.`id` = `tickets`.`entity_id` LIMIT 1)) {$sortDir}"
                );
                break;
        }

        // Stable tie-breakers keep pagination deterministic.
        if ($sortBy !== 'last_activity_at') {
            $query->orderByDesc('last_activity_at');
        }
        $query->orderByDesc('id');
    }

    /**
     * Get available ticket statuses.
     */
    private function ticketStatuses()
    {
        return TicketStatus::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get available ticket types.
     */
    private function ticketTypes()
    {
        return TicketType::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Determine whether user must create through Inbox Geral.
     */
    private function mustUseTriageInbox(User $user): bool
    {
        return $user->isClient();
    }

    /**
     * Resolve active Inbox Geral used for triage.
     */
    private function triageInbox(): ?Inbox
    {
        $bySlug = Inbox::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(slug) = ?', ['geral'])
            ->first();

        if ($bySlug) {
            return $bySlug;
        }

        return Inbox::query()
            ->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', ['geral'])
            ->first();
    }

    /**
     * Get inboxes available to user.
     */
    private function inboxesForUser(User $user)
    {
        if ($user->isOperator()) {
            if ($user->isAdmin()) {
                return Inbox::query()->where('is_active', true)->orderBy('name')->get();
            }

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
     * Get follower candidates available for the current user.
     */
    private function followerCandidatesForUser(User $user, ?int $inboxId = null)
    {
        $query = User::query()
            ->where('is_active', true);

        if ($user->isClient()) {
            $query->where(function (Builder $builder) use ($user): void {
                $builder
                    ->where('role', 'operator')
                    ->orWhereKey($user->id);
            });
        } elseif ($user->isOperator() && ! $user->isAdmin()) {
            $allowedInboxIds = $user->accessibleInboxes()->pluck('inboxes.id')->map(fn (int|string $id) => (int) $id)->all();
            if ($allowedInboxIds === []) {
                return collect();
            }

            if ($inboxId !== null) {
                if (! in_array((int) $inboxId, $allowedInboxIds, true)) {
                    return collect();
                }

                $allowedInboxIds = [(int) $inboxId];
            }

            $query->where(function (Builder $scope) use ($allowedInboxIds): void {
                $scope->where(function (Builder $operators) use ($allowedInboxIds): void {
                    $operators->where('role', 'operator')
                        ->whereHas('accessibleInboxes', fn (Builder $inboxes) => $inboxes->whereIn('inboxes.id', $allowedInboxIds));
                })->orWhere(function (Builder $clients) use ($allowedInboxIds): void {
                    $clients->where('role', 'client')
                        ->where(function (Builder $linked) use ($allowedInboxIds): void {
                            $linked->whereHas('createdTickets', fn (Builder $tickets) => $tickets->whereIn('inbox_id', $allowedInboxIds))
                                ->orWhereHas('contacts.entities.tickets', fn (Builder $tickets) => $tickets->whereIn('inbox_id', $allowedInboxIds));
                        });
                });
            });
        }

        if ($inboxId !== null) {
            $query->where(function (Builder $scope) use ($inboxId): void {
                $scope->where(function (Builder $operators) use ($inboxId): void {
                    $operators->where('role', 'operator')
                        ->where(function (Builder $operatorScope) use ($inboxId): void {
                            $operatorScope->where('is_admin', true)
                                ->orWhereHas('accessibleInboxes', fn (Builder $inboxes) => $inboxes->where('inboxes.id', $inboxId));
                        });
                })->orWhere('role', 'client');
            });
        }

        return $query
            ->orderBy('name')
            ->get();
    }

    /**
     * Resolve and validate contact for ticket creation.
     */
    private function resolveTicketContact(User $user, int $entityId, ?int $contactId): ?Contact
    {
        if ($contactId) {
            $contact = Contact::query()
                ->whereKey($contactId)
                ->whereHas('entities', fn (Builder $query) => $query->whereKey($entityId))
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
            ->whereHas('entities', fn (Builder $query) => $query->whereKey($entityId))
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
            ->unique(fn (string $email) => mb_strtolower($email))
            ->values();

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'cc_emails' => "Invalid CC email: {$email}",
                ]);
            }
        }

        return $emails->all();
    }

    /**
     * Parse and validate follower user ids.
     *
     * @param  array<int, mixed>|null  $rawIds
     * @return list<int>
     */
    private function parseFollowerUserIds(?array $rawIds, User $actor, ?int $inboxId = null): array
    {
        $ids = collect($rawIds ?? [])
            ->filter(fn (mixed $id) => is_numeric($id))
            ->map(fn (int|string $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $allowedIds = $this->followerCandidatesForUser($actor, $inboxId)
            ->pluck('id')
            ->map(fn (int|string $id) => (int) $id);
        $invalidId = $ids->first(fn (int $id) => ! $allowedIds->contains($id));

        if ($invalidId !== null) {
            throw ValidationException::withMessages([
                'follower_user_ids' => "Invalid follower user: {$invalidId}",
            ]);
        }

        if ($inboxId !== null) {
            $invalidOperatorIds = User::query()
                ->whereIn('id', $ids->all())
                ->where('role', 'operator')
                ->where('is_admin', false)
                ->whereDoesntHave('accessibleInboxes', fn (Builder $query) => $query->whereKey($inboxId))
                ->pluck('id')
                ->map(fn (int|string $id) => (int) $id)
                ->values()
                ->all();

            if ($invalidOperatorIds !== []) {
                throw ValidationException::withMessages([
                    'follower_user_ids' => 'Invalid follower operator for inbox: '.implode(', ', $invalidOperatorIds),
                ]);
            }
        }

        $activeIds = User::query()
            ->whereIn('id', $ids->all())
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn (int|string $id) => (int) $id)
            ->all();
        $activeLookup = array_fill_keys($activeIds, true);

        return $ids
            ->filter(fn (int $id) => isset($activeLookup[$id]))
            ->values()
            ->all();
    }

    /**
     * Remove follower operators without access to target inbox.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function filterFollowerIdsForInbox(Ticket $ticket, int $inboxId): array
    {
        $currentFollowerIds = $ticket->followers()
            ->pluck('users.id')
            ->map(fn (int|string $id) => (int) $id)
            ->values();

        if ($currentFollowerIds->isEmpty()) {
            return [[], []];
        }

        $invalidOperatorIds = User::query()
            ->whereIn('id', $currentFollowerIds->all())
            ->where('role', 'operator')
            ->where('is_admin', false)
            ->whereDoesntHave('accessibleInboxes', fn (Builder $query) => $query->whereKey($inboxId))
            ->pluck('id')
            ->map(fn (int|string $id) => (int) $id)
            ->values();

        if ($invalidOperatorIds->isEmpty()) {
            return [$currentFollowerIds->all(), []];
        }

        $filteredFollowerIds = $currentFollowerIds
            ->reject(fn (int $id) => $invalidOperatorIds->contains($id))
            ->values()
            ->all();

        return [$filteredFollowerIds, $invalidOperatorIds->all()];
    }

    /**
     * Determine inbox ids where the candidate is relevant as follower.
     *
     * @return list<int>
     */
    private function candidateInboxIdsForFollower(User $follower): array
    {
        if ($follower->isOperator()) {
            return $follower->accessibleInboxes()
                ->pluck('inboxes.id')
                ->map(fn (int|string $id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $createdInboxIds = Ticket::query()
            ->where('created_by_user_id', $follower->id)
            ->pluck('inbox_id')
            ->map(fn (int|string $id) => (int) $id);

        $entityInboxIds = Ticket::query()
            ->select('tickets.inbox_id')
            ->join('contact_entity', 'contact_entity.entity_id', '=', 'tickets.entity_id')
            ->join('contacts', 'contacts.id', '=', 'contact_entity.contact_id')
            ->where('contacts.user_id', $follower->id)
            ->distinct()
            ->pluck('tickets.inbox_id')
            ->map(fn (int|string $id) => (int) $id);

        return $createdInboxIds
            ->merge($entityInboxIds)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Normalize message format.
     */
    private function normalizeMessageFormat(?string $format): string
    {
        return $format === 'html' ? 'html' : 'plain';
    }

    /**
     * Normalize and sanitize message body.
     */
    private function normalizeMessageBody(string $body, string $format): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '';
        }

        if ($format !== 'html') {
            return $trimmed;
        }

        return trim(strip_tags(
            $trimmed,
            '<p><br><strong><b><em><i><u><ul><ol><li><a><blockquote><code><pre>'
        ));
    }

    /**
     * Build timestamp updates for a status transition.
     *
     * @return array<string, mixed>
     */
    private function statusTimestampUpdates(Ticket $ticket, string $newStatus): array
    {
        if ($newStatus === 'closed') {
            $timestamp = now();

            return [
                'resolved_at' => $ticket->resolved_at ?? $timestamp,
                'closed_at' => $ticket->closed_at ?? $timestamp,
            ];
        }

        return [
            'resolved_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Serialize ticket list row.
     *
     * @return array<string, mixed>
     */
    private function serializeTicketListItem(Ticket $ticket, bool $isPinned = false): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'is_pinned' => $isPinned,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'type' => $ticket->type,
            'created_at' => optional($ticket->created_at)?->toIso8601String(),
            'last_activity_at' => optional($ticket->last_activity_at ?? $ticket->updated_at)?->toIso8601String(),
            'inbox' => $ticket->inbox ? ['id' => $ticket->inbox->id, 'name' => $ticket->inbox->name] : null,
            'entity' => $ticket->entity ? ['id' => $ticket->entity->id, 'name' => $ticket->entity->name] : null,
            'contact' => $ticket->contact ? ['id' => $ticket->contact->id, 'name' => $ticket->contact->name, 'email' => $ticket->contact->email] : null,
            'creator_user' => $this->serializeUserReference($ticket->creatorUser),
            'creator_contact' => $ticket->creatorContact ? ['id' => $ticket->creatorContact->id, 'name' => $ticket->creatorContact->name, 'email' => $ticket->creatorContact->email] : null,
            'assigned_operator' => $this->serializeUserReference($ticket->assignedOperator),
        ];
    }

    /**
     * Serialize ticket detail payload.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $operators
     * @param  \Illuminate\Support\Collection<int, Inbox>  $availableInboxes
     * @param  \Illuminate\Support\Collection<int, Entity>  $availableEntities
     * @param  \Illuminate\Support\Collection<int, Contact>  $availableContacts
     * @param  \Illuminate\Support\Collection<int, User>  $availableFollowers
     * @param  bool  $isPinned
     * @param  array{previous:?array{id:int,ticket_number:string,subject:string},next:?array{id:int,ticket_number:string,subject:string}}  $navigation
     * @return array<string, mixed>
     */
    private function serializeTicketDetail(
        Ticket $ticket,
        $operators,
        $availableInboxes,
        $availableEntities,
        $availableContacts,
        $availableFollowers,
        bool $isPinned,
        array $navigation,
        Request $request
    ): array
    {

        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'is_pinned' => $isPinned,
            'description' => $ticket->description,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'type' => $ticket->type,
            'cc_emails' => $ticket->cc_emails ?? [],
            'followers' => $ticket->followers->map(fn (User $follower) => [
                'id' => $follower->id,
                'name' => $follower->name,
                'email' => $follower->email,
                'role' => $follower->role,
                'avatar_url' => $this->avatarUrlForUser($follower),
                'inbox_ids' => $this->candidateInboxIdsForFollower($follower),
            ])->values()->all(),
            'created_at' => optional($ticket->created_at)?->toIso8601String(),
            'last_activity_at' => optional($ticket->last_activity_at ?? $ticket->updated_at)?->toIso8601String(),
            'inbox' => $ticket->inbox ? ['id' => $ticket->inbox->id, 'name' => $ticket->inbox->name] : null,
            'entity' => $ticket->entity ? ['id' => $ticket->entity->id, 'name' => $ticket->entity->name] : null,
            'contact' => $ticket->contact ? ['id' => $ticket->contact->id, 'name' => $ticket->contact->name, 'email' => $ticket->contact->email] : null,
            'creator_contact' => $ticket->creatorContact ? ['id' => $ticket->creatorContact->id, 'name' => $ticket->creatorContact->name, 'email' => $ticket->creatorContact->email] : null,
            'creator_user' => $this->serializeUserReference($ticket->creatorUser),
            'assigned_operator' => $this->serializeUserReference($ticket->assignedOperator),
            'permissions' => [
                'can_reply' => $request->user()->can('reply', $ticket),
                'can_add_internal_note' => $request->user()->isOperator() && $request->user()->can('view', $ticket),
                'can_update_status' => $request->user()->can('updateStatus', $ticket),
                'can_assign' => $request->user()->can('assign', $ticket),
                'can_update_metadata' => $request->user()->can('updateMetadata', $ticket),
                'can_update' => $request->user()->can('updateMetadata', $ticket),
                'can_delete' => false,
                'can_manage_messages' => false,
            ],
            'messages' => $ticket->messages->sortByDesc('created_at')->values()->map(fn (TicketMessage $message) => [
                'id' => $message->id,
                'author_type' => $message->author_type,
                'author_user' => $this->serializeUserReference($message->authorUser),
                'author_contact' => $message->authorContact ? ['id' => $message->authorContact->id, 'name' => $message->authorContact->name] : null,
                'body' => $message->body,
                'body_format' => $message->body_format,
                'is_internal' => $message->is_internal,
                'created_at' => optional($message->created_at)?->toIso8601String(),
                'can_edit' => false,
                'can_delete' => false,
                'attachments' => $message->attachmentsList->map(fn (TicketAttachment $attachment) => [
                    'id' => $attachment->id,
                    'uuid' => $attachment->uuid,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                    'download_url' => route('app-api.attachments.download', $attachment),
                ])->values()->all(),
            ])->all(),
            'logs' => $ticket->logs->sortByDesc('created_at')->values()->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'field' => $log->field,
                'old_value' => $log->old_value,
                'new_value' => $log->new_value,
                'actor_type' => $log->actor_type,
                'actor_user' => $this->serializeUserReference($log->actorUser),
                'actor_contact' => $log->actorContact ? ['id' => $log->actorContact->id, 'name' => $log->actorContact->name] : null,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ])->all(),
            'operators' => $operators->map(fn (User $operator) => [
                'id' => $operator->id,
                'name' => $operator->name,
                'email' => $operator->email,
                'avatar_url' => $this->avatarUrlForUser($operator),
            ])->values()->all(),
            'available_inboxes' => $availableInboxes->map(fn (Inbox $inbox) => [
                'id' => $inbox->id,
                'name' => $inbox->name,
            ])->values()->all(),
            'available_entities' => $availableEntities->map(fn (Entity $entity) => [
                'id' => $entity->id,
                'name' => $entity->name,
            ])->values()->all(),
            'available_contacts' => $availableContacts->map(fn (Contact $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'entity_ids' => $contact->entities->pluck('id')->values()->all(),
            ])->values()->all(),
            'available_followers' => $availableFollowers->map(fn (User $follower) => [
                'id' => $follower->id,
                'name' => $follower->name,
                'email' => $follower->email,
                'role' => $follower->role,
                'avatar_url' => $this->avatarUrlForUser($follower),
                'inbox_ids' => $this->candidateInboxIdsForFollower($follower),
            ])->values()->all(),
            'navigation' => $navigation,
        ];
    }

    /**
     * Build a public avatar URL for user payloads.
     */
    private function avatarUrlForUser(User $user): string
    {
        $avatarPath = trim((string) ($user->avatar_path ?? ''));

        if ($avatarPath !== '') {
            return url('/storage/'.ltrim($avatarPath, '/'));
        }

        return url('/images/avatar-placeholder.svg');
    }

    /**
     * Serialize compact user reference including avatar.
     *
     * @return array<string, mixed>|null
     */
    private function serializeUserReference(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'avatar_url' => $this->avatarUrlForUser($user),
        ];
    }

    /**
     * Resolve previous and next tickets visible to the current user.
     *
     * @return array{previous:?array{id:int,ticket_number:string,subject:string},next:?array{id:int,ticket_number:string,subject:string}}
     */
    private function adjacentTickets(Ticket $ticket, User $user): array
    {
        $visibleTickets = Ticket::query()->select(['id', 'ticket_number', 'subject']);
        $this->applyVisibilityScope($visibleTickets, $user);

        $previous = (clone $visibleTickets)
            ->where('id', '<', $ticket->id)
            ->orderByDesc('id')
            ->first();

        $next = (clone $visibleTickets)
            ->where('id', '>', $ticket->id)
            ->orderBy('id')
            ->first();

        return [
            'previous' => $previous ? [
                'id' => $previous->id,
                'ticket_number' => $previous->ticket_number,
                'subject' => $previous->subject,
            ] : null,
            'next' => $next ? [
                'id' => $next->id,
                'ticket_number' => $next->ticket_number,
                'subject' => $next->subject,
            ] : null,
        ];
    }

    /**
     * @param  list<int>  $ticketIds
     * @return array<int, bool>
     */
    private function pinnedTicketLookup(User $user, array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $pinnedIds = UserPinnedTicket::query()
            ->where('user_id', $user->id)
            ->whereIn('ticket_id', $ticketIds)
            ->pluck('ticket_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_fill_keys($pinnedIds, true);
    }
}

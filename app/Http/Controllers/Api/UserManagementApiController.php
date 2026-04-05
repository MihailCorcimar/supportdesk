<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Entity;
use App\Models\Inbox;
use App\Models\User;
use App\Models\UserInvite;
use App\Support\UserActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserManagementApiController extends Controller
{
    /**
     * Return metadata used by user management screens.
     */
    public function meta(Request $request): JsonResponse
    {
        $actor = $request->user();

        $manageableInboxes = $actor->isAdmin()
            ? Inbox::query()->where('is_active', true)->orderBy('name')->get()
            : $actor->manageableInboxes()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

        $entities = Entity::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'manageable_inboxes' => $manageableInboxes->map(fn (Inbox $inbox) => [
                    'id' => $inbox->id,
                    'name' => $inbox->name,
                ])->values(),
                'entities' => $entities->map(fn (Entity $entity) => [
                    'id' => $entity->id,
                    'name' => $entity->name,
                ])->values(),
                'roles' => ['operator', 'client'],
                'can_set_admin' => $actor->isAdmin(),
            ],
        ]);
    }

    /**
     * List users for management.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $manageableInboxIds = $actor->isAdmin()
            ? Inbox::query()->pluck('id')->all()
            : $actor->manageableInboxes()->pluck('inboxes.id')->all();

        $query = User::query()
            ->withCount(['createdTickets as tickets_count'])
            ->with(['accessibleInboxes:id,name', 'contacts.entities:id,name']);

        if (! $actor->isAdmin()) {
            $query->where(function (Builder $inner) use ($manageableInboxIds): void {
                $inner->where(function (Builder $operators) use ($manageableInboxIds): void {
                    $operators->where('role', 'operator')
                        ->where('is_admin', false)
                        ->whereHas('accessibleInboxes', fn (Builder $inboxes) => $inboxes->whereIn('inboxes.id', $manageableInboxIds))
                        ->whereDoesntHave('accessibleInboxes', fn (Builder $inboxes) => $inboxes->whereNotIn('inboxes.id', $manageableInboxIds));
                })->orWhere(function (Builder $clients) use ($manageableInboxIds): void {
                    $clients->where('role', 'client')
                        ->where(function (Builder $scope) use ($manageableInboxIds): void {
                            $scope->whereHas('createdTickets', fn (Builder $tickets) => $tickets->whereIn('inbox_id', $manageableInboxIds))
                                ->orWhereHas('contacts.entities.tickets', fn (Builder $tickets) => $tickets->whereIn('inbox_id', $manageableInboxIds));
                        });
                });
            });
        }

        if ($request->filled('role')) {
            $query->where('role', (string) $request->string('role'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $this->applySorting($query, $request);

        $users = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user) => $this->serializeUser($user))->all(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Show one user details for management.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->assertCanManageTargetUser($actor, $user);

        $user->load([
            'accessibleInboxes:id,name',
            'contacts.entities:id,name',
        ]);
        $user->loadCount([
            'createdTickets as tickets_count',
            'assignedTickets as assigned_tickets_count',
            'contacts as contacts_count',
        ]);

        $createdTickets = $user->createdTickets()
            ->with(['inbox:id,name', 'entity:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($ticket) => [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'inbox' => $ticket->inbox ? [
                    'id' => $ticket->inbox->id,
                    'name' => $ticket->inbox->name,
                ] : null,
                'entity' => $ticket->entity ? [
                    'id' => $ticket->entity->id,
                    'name' => $ticket->entity->name,
                ] : null,
                'created_at' => optional($ticket->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => [
                ...$this->serializeUser($user),
                'assigned_tickets_count' => (int) ($user->assigned_tickets_count ?? 0),
                'contacts_count' => (int) ($user->contacts_count ?? 0),
                'created_tickets' => $createdTickets,
            ],
        ]);
    }

    /**
     * Create an operator or client user.
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['operator', 'client'])],
            'is_active' => ['sometimes', 'boolean'],
            'entity_id' => ['nullable', 'integer', Rule::exists('entities', 'id')],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'inbox_ids' => ['nullable', 'array'],
            'inbox_ids.*' => ['integer', Rule::exists('inboxes', 'id')],
            'manager_inbox_ids' => ['nullable', 'array'],
            'manager_inbox_ids.*' => ['integer'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $inboxIds = collect($validated['inbox_ids'] ?? [])->map(fn (mixed $id) => (int) $id)->values();
        $managerInboxIds = collect($validated['manager_inbox_ids'] ?? [])->map(fn (mixed $id) => (int) $id)->values();
        $isAdmin = $validated['role'] === 'operator' && (bool) ($validated['is_admin'] ?? false);

        if ($validated['role'] === 'operator' && ! $isAdmin && $inboxIds->isEmpty()) {
            return response()->json([
                'message' => 'At least one inbox is required for operators.',
            ], 422);
        }

        if ($validated['role'] === 'client' && empty($validated['entity_id'])) {
            return response()->json([
                'message' => 'Entity is required for clients.',
            ], 422);
        }

        if ($isAdmin && ! $actor->isAdmin()) {
            abort(403, 'Only admins can grant admin access.');
        }

        if ($validated['role'] === 'operator' && ! $isAdmin) {
            $this->assertInboxManagementScope($actor, $inboxIds->all(), $managerInboxIds->all());
        }

        $password = Str::password(24);

        $user = DB::transaction(function () use ($validated, $password, $inboxIds, $managerInboxIds, $isAdmin, $actor): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => mb_strtolower(trim((string) $validated['email'])),
                'password' => Hash::make($password),
                'role' => $validated['role'],
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'is_admin' => $isAdmin,
            ]);

            if ($validated['role'] === 'operator') {
                $this->syncOperatorInboxes($user, $inboxIds->all(), $managerInboxIds->all());
            }

            if ($validated['role'] === 'client') {
                $this->upsertClientPrimaryContact(
                    $user,
                    (int) $validated['entity_id'],
                    (string) ($validated['contact_name'] ?? $validated['name'])
                );
            }

            UserActivityLogger::log(
                $actor,
                $user,
                'user_created',
                [
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                    'inbox_ids' => $inboxIds->all(),
                    'manager_inbox_ids' => $managerInboxIds->all(),
                    'is_admin' => $isAdmin,
                ]
            );

            return $user;
        });

        $invitePayload = $this->createInvite($user, $actor);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => [
                'user' => $this->serializeUser($user->load(['accessibleInboxes:id,name', 'contacts.entities:id,name'])),
                'invite' => $invitePayload,
            ],
        ], 201);
    }

    /**
     * Update user profile and status.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->assertCanManageTargetUser($actor, $user);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'No changes to apply.',
            ]);
        }

        $changes = [];

        if (array_key_exists('name', $validated) && $validated['name'] !== $user->name) {
            $changes['name'] = ['old' => $user->name, 'new' => $validated['name']];
            $user->name = $validated['name'];
        }

        if (array_key_exists('is_active', $validated) && (bool) $validated['is_active'] !== (bool) $user->is_active) {
            $changes['is_active'] = ['old' => (bool) $user->is_active, 'new' => (bool) $validated['is_active']];
            $user->is_active = (bool) $validated['is_active'];
        }

        if (array_key_exists('is_admin', $validated)) {
            if (! $user->isOperator()) {
                abort(422, 'Admin permission is only available for operators.');
            }

            $requestedSpecial = (bool) $validated['is_admin'];

            if ($requestedSpecial && ! $actor->isAdmin()) {
                abort(403, 'Only admins can grant admin access.');
            }

            if ($requestedSpecial !== $user->isAdmin()) {
                $changes['is_admin'] = ['old' => (bool) $user->is_admin, 'new' => $requestedSpecial];
                $user->is_admin = $requestedSpecial;
            }
        }

        if ($user->isClient() && array_key_exists('contact_name', $validated)) {
            $contact = Contact::query()->where('user_id', $user->id)->orderBy('id')->first();
            if ($contact && $validated['contact_name'] && $contact->name !== $validated['contact_name']) {
                $changes['contact_name'] = ['old' => $contact->name, 'new' => (string) $validated['contact_name']];
                $contact->name = (string) $validated['contact_name'];
                $contact->save();
            }
        }

        if ($changes === []) {
            return response()->json([
                'message' => 'No changes to apply.',
            ]);
        }

        $user->save();

        UserActivityLogger::log($actor, $user, 'user_updated', ['changes' => $changes]);

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $this->serializeUser($user->load(['accessibleInboxes:id,name', 'contacts.entities:id,name'])),
        ]);
    }

    /**
     * Update inbox access for an operator.
     */
    public function updateInboxes(Request $request, User $user): JsonResponse
    {
        if (! $user->isOperator()) {
            abort(422, 'Inbox access can only be changed for operators.');
        }

        $actor = $request->user();
        $this->assertCanManageTargetUser($actor, $user);

        $validated = $request->validate([
            'inbox_ids' => ['required', 'array'],
            'inbox_ids.*' => ['integer', Rule::exists('inboxes', 'id')],
            'manager_inbox_ids' => ['nullable', 'array'],
            'manager_inbox_ids.*' => ['integer'],
        ]);

        $inboxIds = collect($validated['inbox_ids'])->map(fn (mixed $id) => (int) $id)->values();
        $managerInboxIds = collect($validated['manager_inbox_ids'] ?? [])->map(fn (mixed $id) => (int) $id)->values();

        $this->assertInboxManagementScope($actor, $inboxIds->all(), $managerInboxIds->all());

        if (! $user->isAdmin() && $inboxIds->isEmpty()) {
            abort(422, 'At least one inbox is required for operators.');
        }

        $oldInboxes = $user->accessibleInboxes()->pluck('inboxes.id')->all();

        $this->syncOperatorInboxes($user, $inboxIds->all(), $managerInboxIds->all());

        UserActivityLogger::log(
            $actor,
            $user,
            'operator_inboxes_updated',
            [
                'old_inbox_ids' => $oldInboxes,
                'new_inbox_ids' => $inboxIds->all(),
                'manager_inbox_ids' => $managerInboxIds->all(),
            ]
        );

        return response()->json([
            'message' => 'Operator inbox permissions updated.',
            'data' => $this->serializeUser($user->load(['accessibleInboxes:id,name', 'contacts.entities:id,name'])),
        ]);
    }

    /**
     * Create a fresh invite for a user.
     */
    public function invite(Request $request, User $user): JsonResponse
    {
        $this->assertCanManageTargetUser($request->user(), $user);

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Cannot create invite for inactive user.',
            ], 422);
        }

        $invite = $this->createInvite($user, $request->user());

        return response()->json([
            'message' => 'Invitation generated successfully.',
            'data' => $invite,
        ]);
    }

    /**
     * Delete user account.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $this->assertCanManageTargetUser($actor, $user);

        if ($actor->id === $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        UserActivityLogger::log($actor, $user, 'user_deleted');

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Serialize user for frontend.
     *
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $primaryContact = $user->contacts->first();
        $primaryEntity = $primaryContact?->entities?->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => url('/images/avatar-placeholder.svg'),
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'is_admin' => (bool) $user->is_admin,
            'can_manage_users' => $user->canManageUsers(),
            'tickets_count' => (int) ($user->tickets_count ?? $user->created_tickets_count ?? 0),
            'inboxes' => $user->accessibleInboxes->map(fn (Inbox $inbox) => [
                'id' => $inbox->id,
                'name' => $inbox->name,
                'can_manage_users' => (bool) $inbox->pivot?->can_manage_users,
            ])->values()->all(),
            'primary_contact' => $primaryContact ? [
                'id' => $primaryContact->id,
                'name' => $primaryContact->name,
                'entity' => $primaryEntity ? [
                    'id' => $primaryEntity->id,
                    'name' => $primaryEntity->name,
                ] : null,
            ] : null,
        ];
    }

    /**
     * Ensure inbox operations stay inside actor management scope.
     *
     * @param  list<int>  $inboxIds
     * @param  list<int>  $managerInboxIds
     */
    private function assertInboxManagementScope(User $actor, array $inboxIds, array $managerInboxIds): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $allowedIds = $actor->manageableInboxes()->pluck('inboxes.id')->all();

        foreach (array_unique([...$inboxIds, ...$managerInboxIds]) as $inboxId) {
            if (! in_array((int) $inboxId, $allowedIds, true)) {
                abort(403, 'You cannot manage users for one or more selected inboxes.');
            }
        }

        foreach ($managerInboxIds as $managerInboxId) {
            if (! in_array($managerInboxId, $inboxIds, true)) {
                abort(422, 'Manager inboxes must be included in inbox access list.');
            }
        }
    }

    /**
     * Sync operator inbox assignments.
     *
     * @param  list<int>  $inboxIds
     * @param  list<int>  $managerInboxIds
     */
    private function syncOperatorInboxes(User $user, array $inboxIds, array $managerInboxIds): void
    {
        $sync = [];

        foreach (array_unique($inboxIds) as $inboxId) {
            $sync[(int) $inboxId] = [
                'can_manage_users' => in_array((int) $inboxId, $managerInboxIds, true),
            ];
        }

        $user->accessibleInboxes()->sync($sync);
    }

    /**
     * Upsert primary contact for client user.
     */
    private function upsertClientPrimaryContact(User $user, int $entityId, string $contactName): void
    {
        $entity = Entity::query()->whereKey($entityId)->where('is_active', true)->firstOrFail();

        $contact = Contact::query()
            ->where('email', $user->email)
            ->first();

        if ($contact && $contact->user_id && $contact->user_id !== $user->id) {
            abort(422, 'A contact with this email already belongs to another user.');
        }

        $contact ??= new Contact([
            'email' => $user->email,
        ]);

        $contact->fill([
            'user_id' => $user->id,
            'name' => $contactName,
            'is_active' => true,
        ]);

        $contact->save();
        $contact->entities()->syncWithoutDetaching([$entity->id]);
    }

    /**
     * Apply users list sorting.
     */
    private function applySorting(Builder $query, Request $request): void
    {
        $allowedSortBy = [
            'name',
            'email',
            'role',
            'is_active',
            'tickets_count',
        ];

        $sortBy = trim((string) $request->string('sort_by', 'name'));
        $sortDir = strtolower(trim((string) $request->string('sort_dir', 'asc'))) === 'desc' ? 'desc' : 'asc';

        if (! in_array($sortBy, $allowedSortBy, true)) {
            $sortBy = 'name';
        }

        $query->orderBy($sortBy, $sortDir);

        if ($sortBy !== 'name') {
            $query->orderBy('name', 'asc');
        }

        $query->orderBy('id', 'asc');
    }

    /**
     * Generate a fresh invite and invalidate previous pending tokens.
     *
     * @return array<string, mixed>
     */
    private function createInvite(User $targetUser, User $actor): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addHours((int) config('supportdesk.invites.expiration_hours', 72));

        UserInvite::query()
            ->where('user_id', $targetUser->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $invite = UserInvite::query()->create([
            'user_id' => $targetUser->id,
            'created_by_user_id' => $actor->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        UserActivityLogger::log(
            $actor,
            $targetUser,
            'invite_created',
            [
                'invite_id' => $invite->id,
                'expires_at' => $expiresAt->toISOString(),
            ]
        );

        return [
            'url' => url('/accept-invite?token='.$token),
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    /**
     * Ensure actor has scope to manage the target user.
     */
    private function assertCanManageTargetUser(User $actor, User $target): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $allowedInboxIds = $actor->manageableInboxes()->pluck('inboxes.id')->all();

        if ($target->isOperator()) {
            $targetInboxIds = $target->accessibleInboxes()->pluck('inboxes.id')->all();

            if ($target->isAdmin()) {
                abort(403, 'You cannot manage an admin operator.');
            }

            if ($targetInboxIds === []) {
                abort(403, 'You cannot manage this operator.');
            }

            foreach ($targetInboxIds as $inboxId) {
                if (! in_array((int) $inboxId, $allowedInboxIds, true)) {
                    abort(403, 'You cannot manage this operator.');
                }
            }

            return;
        }

        if ($target->isClient() && ! $this->isClientInsideInboxScope($target, $allowedInboxIds)) {
            abort(403, 'You cannot manage this client.');
        }
    }

    /**
     * Check whether a client belongs to at least one manageable inbox scope.
     *
     * @param  list<int>  $allowedInboxIds
     */
    private function isClientInsideInboxScope(User $client, array $allowedInboxIds): bool
    {
        if ($allowedInboxIds === []) {
            return false;
        }

        if ($client->createdTickets()->whereIn('inbox_id', $allowedInboxIds)->exists()) {
            return true;
        }

        return Contact::query()
            ->where('user_id', $client->id)
            ->whereHas('entities.tickets', fn (Builder $tickets) => $tickets->whereIn('inbox_id', $allowedInboxIds))
            ->exists();
    }
}

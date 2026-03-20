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

        $manageableInboxes = $actor->manageableInboxes()
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
            ],
        ]);
    }

    /**
     * List users for management.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $manageableInboxIds = $actor->manageableInboxes()->pluck('inboxes.id')->all();

        $query = User::query()
            ->with(['accessibleInboxes:id,name', 'contacts.entity:id,name'])
            ->orderBy('name');

        $query->where(function (Builder $inner) use ($manageableInboxIds): void {
            $inner->where('role', 'client')
                ->orWhere(function (Builder $operators) use ($manageableInboxIds): void {
                    $operators->where('role', 'operator')
                        ->where(function (Builder $scope) use ($manageableInboxIds): void {
                            $scope->whereDoesntHave('accessibleInboxes')
                                ->orWhereHas('accessibleInboxes', fn (Builder $inboxes) => $inboxes->whereIn('inboxes.id', $manageableInboxIds));
                        });
                });
        });

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
        ]);

        $inboxIds = collect($validated['inbox_ids'] ?? [])->map(fn (mixed $id) => (int) $id)->values();
        $managerInboxIds = collect($validated['manager_inbox_ids'] ?? [])->map(fn (mixed $id) => (int) $id)->values();

        if ($validated['role'] === 'operator' && $inboxIds->isEmpty()) {
            return response()->json([
                'message' => 'At least one inbox is required for operators.',
            ], 422);
        }

        if ($validated['role'] === 'client' && empty($validated['entity_id'])) {
            return response()->json([
                'message' => 'Entity is required for clients.',
            ], 422);
        }

        if ($validated['role'] === 'operator') {
            $this->assertInboxManagementScope($actor, $inboxIds->all(), $managerInboxIds->all());
        }

        $password = Str::password(24);

        $user = DB::transaction(function () use ($validated, $password, $inboxIds, $managerInboxIds, $actor): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => mb_strtolower(trim((string) $validated['email'])),
                'password' => Hash::make($password),
                'role' => $validated['role'],
                'is_active' => (bool) ($validated['is_active'] ?? true),
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
                ]
            );

            return $user;
        });

        $invitePayload = $this->createInvite($user, $actor);

        return response()->json([
            'message' => 'User created successfully.',
            'data' => [
                'user' => $this->serializeUser($user->load(['accessibleInboxes:id,name', 'contacts.entity:id,name'])),
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

        if ($user->isClient() && array_key_exists('contact_name', $validated)) {
            $contact = Contact::query()->where('user_id', $user->id)->orderByDesc('is_primary')->first();
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
            'data' => $this->serializeUser($user->load(['accessibleInboxes:id,name', 'contacts.entity:id,name'])),
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
            'inbox_ids' => ['required', 'array', 'min:1'],
            'inbox_ids.*' => ['integer', Rule::exists('inboxes', 'id')],
            'manager_inbox_ids' => ['nullable', 'array'],
            'manager_inbox_ids.*' => ['integer'],
        ]);

        $inboxIds = collect($validated['inbox_ids'])->map(fn (mixed $id) => (int) $id)->values();
        $managerInboxIds = collect($validated['manager_inbox_ids'] ?? [])->map(fn (mixed $id) => (int) $id)->values();

        $this->assertInboxManagementScope($actor, $inboxIds->all(), $managerInboxIds->all());

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
            'data' => $this->serializeUser($user->load(['accessibleInboxes:id,name', 'contacts.entity:id,name'])),
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
        $primaryContact = $user->contacts->sortByDesc(fn (Contact $contact) => $contact->is_primary)->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'can_manage_users' => $user->canManageUsers(),
            'inboxes' => $user->accessibleInboxes->map(fn (Inbox $inbox) => [
                'id' => $inbox->id,
                'name' => $inbox->name,
                'can_manage_users' => (bool) $inbox->pivot?->can_manage_users,
            ])->values()->all(),
            'primary_contact' => $primaryContact ? [
                'id' => $primaryContact->id,
                'name' => $primaryContact->name,
                'entity' => $primaryContact->entity ? [
                    'id' => $primaryContact->entity->id,
                    'name' => $primaryContact->entity->name,
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
            ->where('entity_id', $entity->id)
            ->where('email', $user->email)
            ->first();

        if ($contact && $contact->user_id && $contact->user_id !== $user->id) {
            abort(422, 'A contact with this email already belongs to another user in the selected entity.');
        }

        $contact ??= new Contact([
            'entity_id' => $entity->id,
            'email' => $user->email,
        ]);

        $contact->fill([
            'user_id' => $user->id,
            'name' => $contactName,
            'is_primary' => true,
            'is_active' => true,
        ]);

        $contact->save();
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
        if (! $target->isOperator()) {
            return;
        }

        $allowedInboxIds = $actor->manageableInboxes()->pluck('inboxes.id')->all();
        $targetInboxIds = $target->accessibleInboxes()->pluck('inboxes.id')->all();

        foreach ($targetInboxIds as $inboxId) {
            if (! in_array((int) $inboxId, $allowedInboxIds, true)) {
                abort(403, 'You cannot manage this operator.');
            }
        }
    }
}

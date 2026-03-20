<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactApiController extends Controller
{
    /**
     * List contacts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Contact::query()
            ->with(['entity:id,name', 'user:id,name,email'])
            ->orderBy('name');

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->integer('entity_id'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function (Builder $inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('entity', fn (Builder $entityQuery) => $entityQuery->where('name', 'like', "%{$search}%"));
            });
        }

        $contacts = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $contacts->getCollection()->map(fn (Contact $contact) => $this->serializeContact($contact))->all(),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
            ],
        ]);
    }

    /**
     * Create contact.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => ['required', 'integer', Rule::exists('entities', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $entity = Entity::query()->whereKey((int) $validated['entity_id'])->firstOrFail();

        if (! $entity->is_active) {
            abort(422, 'Cannot create contacts in inactive entities.');
        }

        if (Contact::query()->where('entity_id', $entity->id)->where('email', $validated['email'])->exists()) {
            return response()->json([
                'message' => 'A contact with this email already exists in the selected entity.',
            ], 422);
        }

        $linkedUser = null;

        if (! empty($validated['user_id'])) {
            $linkedUser = User::query()->whereKey((int) $validated['user_id'])->firstOrFail();
        }

        if (($validated['is_primary'] ?? false) === true) {
            Contact::query()->where('entity_id', $entity->id)->update(['is_primary' => false]);
        }

        $contact = Contact::query()->create([
            'entity_id' => $entity->id,
            'user_id' => $linkedUser?->id,
            'name' => trim((string) $validated['name']),
            'email' => mb_strtolower(trim((string) $validated['email'])),
            'phone' => $validated['phone'] ?? null,
            'job_title' => $validated['job_title'] ?? null,
            'is_primary' => (bool) ($validated['is_primary'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $contact->load(['entity:id,name', 'user:id,name,email']);

        return response()->json([
            'message' => 'Contact created successfully.',
            'data' => $this->serializeContact($contact),
        ], 201);
    }

    /**
     * Update contact.
     */
    public function update(Request $request, Contact $contact): JsonResponse
    {
        $validated = $request->validate([
            'entity_id' => ['sometimes', 'integer', Rule::exists('entities', 'id')],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No changes to apply.']);
        }

        $newEntityId = (int) ($validated['entity_id'] ?? $contact->entity_id);
        $newEmail = mb_strtolower(trim((string) ($validated['email'] ?? $contact->email)));

        $entity = Entity::query()->whereKey($newEntityId)->firstOrFail();

        if (! $entity->is_active) {
            abort(422, 'Cannot assign contact to inactive entities.');
        }

        $duplicate = Contact::query()
            ->where('entity_id', $newEntityId)
            ->where('email', $newEmail)
            ->where('id', '!=', $contact->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'A contact with this email already exists in the selected entity.',
            ], 422);
        }

        if (($validated['is_primary'] ?? false) === true) {
            Contact::query()->where('entity_id', $newEntityId)->where('id', '!=', $contact->id)->update(['is_primary' => false]);
        }

        if (array_key_exists('entity_id', $validated)) {
            $contact->entity_id = $newEntityId;
        }

        if (array_key_exists('user_id', $validated)) {
            $contact->user_id = $validated['user_id'] ? (int) $validated['user_id'] : null;
        }

        if (array_key_exists('name', $validated)) {
            $contact->name = trim((string) $validated['name']);
        }

        if (array_key_exists('email', $validated)) {
            $contact->email = $newEmail;
        }

        if (array_key_exists('phone', $validated)) {
            $contact->phone = $validated['phone'];
        }

        if (array_key_exists('job_title', $validated)) {
            $contact->job_title = $validated['job_title'];
        }

        if (array_key_exists('is_primary', $validated)) {
            $contact->is_primary = (bool) $validated['is_primary'];
        }

        if (array_key_exists('is_active', $validated)) {
            $contact->is_active = (bool) $validated['is_active'];
        }

        $contact->save();
        $contact->load(['entity:id,name', 'user:id,name,email']);

        return response()->json([
            'message' => 'Contact updated successfully.',
            'data' => $this->serializeContact($contact),
        ]);
    }

    /**
     * Delete contact.
     */
    public function destroy(Contact $contact): JsonResponse
    {
        if ($contact->tickets()->exists()) {
            return response()->json([
                'message' => 'Cannot delete contact with associated tickets.',
            ], 422);
        }

        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully.',
        ]);
    }

    /**
     * Serialize contact payload.
     *
     * @return array<string, mixed>
     */
    private function serializeContact(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'entity_id' => $contact->entity_id,
            'user_id' => $contact->user_id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'job_title' => $contact->job_title,
            'is_primary' => (bool) $contact->is_primary,
            'is_active' => (bool) $contact->is_active,
            'entity' => $contact->entity ? [
                'id' => $contact->entity->id,
                'name' => $contact->entity->name,
            ] : null,
            'user' => $contact->user ? [
                'id' => $contact->user->id,
                'name' => $contact->user->name,
                'email' => $contact->user->email,
            ] : null,
        ];
    }
}
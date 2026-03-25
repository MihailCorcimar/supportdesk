<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactFunction;
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
            ->with(['entities:id,name', 'contactFunction:id,name', 'user:id,name,email'])
            ->orderBy('name');

        if ($request->filled('entity_id')) {
            $entityId = $request->integer('entity_id');
            $query->whereHas('entities', fn (Builder $entityQuery) => $entityQuery->whereKey($entityId));
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
                    ->orWhere('mobile_phone', 'like', "%{$search}%")
                    ->orWhereHas('entities', fn (Builder $entityQuery) => $entityQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('contactFunction', fn (Builder $functionQuery) => $functionQuery->where('name', 'like', "%{$search}%"));
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
            'options' => [
                'functions' => ContactFunction::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (ContactFunction $function) => [
                        'id' => $function->id,
                        'name' => $function->name,
                    ])->values(),
            ],
        ]);
    }

    /**
     * Create contact.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_ids' => ['required', 'array', 'min:1'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
            'function_id' => ['nullable', 'integer', Rule::exists('contact_functions', 'id')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile_phone' => ['nullable', 'string', 'max:50'],
            'internal_notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $entityIds = collect($validated['entity_ids'])->map(fn (mixed $id) => (int) $id)->unique()->values();
        $activeEntitiesCount = Entity::query()
            ->whereIn('id', $entityIds->all())
            ->where('is_active', true)
            ->count();

        if ($activeEntitiesCount !== $entityIds->count()) {
            abort(422, 'Cannot create contacts in inactive entities.');
        }

        $email = mb_strtolower(trim((string) $validated['email']));

        if (Contact::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json([
                'message' => 'A contact with this email already exists.',
            ], 422);
        }

        $linkedUser = null;
        if (! empty($validated['user_id'])) {
            $linkedUser = User::query()->whereKey((int) $validated['user_id'])->firstOrFail();
        }

        $contact = Contact::query()->create([
            'function_id' => isset($validated['function_id']) ? (int) $validated['function_id'] : null,
            'user_id' => $linkedUser?->id,
            'name' => trim((string) $validated['name']),
            'email' => $email,
            'phone' => $validated['phone'] ?? null,
            'mobile_phone' => $validated['mobile_phone'] ?? null,
            'internal_notes' => $validated['internal_notes'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $contact->entities()->sync($entityIds->all());
        $contact->load(['entities:id,name', 'contactFunction:id,name', 'user:id,name,email']);

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
            'entity_ids' => ['sometimes', 'array', 'min:1'],
            'entity_ids.*' => ['integer', Rule::exists('entities', 'id')],
            'function_id' => ['sometimes', 'nullable', 'integer', Rule::exists('contact_functions', 'id')],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mobile_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'internal_notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No changes to apply.']);
        }

        if (array_key_exists('entity_ids', $validated)) {
            $entityIds = collect($validated['entity_ids'])->map(fn (mixed $id) => (int) $id)->unique()->values();

            $activeEntitiesCount = Entity::query()
                ->whereIn('id', $entityIds->all())
                ->where('is_active', true)
                ->count();

            if ($activeEntitiesCount !== $entityIds->count()) {
                abort(422, 'Cannot assign contact to inactive entities.');
            }

            $contact->entities()->sync($entityIds->all());
        }

        if (array_key_exists('email', $validated)) {
            $newEmail = mb_strtolower(trim((string) $validated['email']));
            $duplicate = Contact::query()
                ->whereRaw('LOWER(email) = ?', [$newEmail])
                ->where('id', '!=', $contact->id)
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'message' => 'A contact with this email already exists.',
                ], 422);
            }

            $contact->email = $newEmail;
        }

        if (array_key_exists('function_id', $validated)) {
            $contact->function_id = $validated['function_id'] ? (int) $validated['function_id'] : null;
        }

        if (array_key_exists('user_id', $validated)) {
            $contact->user_id = $validated['user_id'] ? (int) $validated['user_id'] : null;
        }

        if (array_key_exists('name', $validated)) {
            $contact->name = trim((string) $validated['name']);
        }

        if (array_key_exists('phone', $validated)) {
            $contact->phone = $validated['phone'];
        }

        if (array_key_exists('mobile_phone', $validated)) {
            $contact->mobile_phone = $validated['mobile_phone'];
        }

        if (array_key_exists('internal_notes', $validated)) {
            $contact->internal_notes = $validated['internal_notes'];
        }

        if (array_key_exists('is_active', $validated)) {
            $contact->is_active = (bool) $validated['is_active'];
        }

        $contact->save();
        $contact->load(['entities:id,name', 'contactFunction:id,name', 'user:id,name,email']);

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
            'entity_ids' => $contact->entities->pluck('id')->values()->all(),
            'user_id' => $contact->user_id,
            'function_id' => $contact->function_id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'mobile_phone' => $contact->mobile_phone,
            'internal_notes' => $contact->internal_notes,
            'is_active' => (bool) $contact->is_active,
            'entities' => $contact->entities->map(fn (Entity $entity) => [
                'id' => $entity->id,
                'name' => $entity->name,
            ])->values()->all(),
            'function' => $contact->contactFunction ? [
                'id' => $contact->contactFunction->id,
                'name' => $contact->contactFunction->name,
            ] : null,
            'user' => $contact->user ? [
                'id' => $contact->user->id,
                'name' => $contact->user->name,
                'email' => $contact->user->email,
            ] : null,
        ];
    }
}

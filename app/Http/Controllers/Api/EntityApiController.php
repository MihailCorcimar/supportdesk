<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EntityApiController extends Controller
{
    /**
     * List entities.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Entity::query()
            ->withCount(['contacts', 'tickets'])
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('mobile_phone', 'like', "%{$search}%")
                    ->orWhere('tax_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->string('type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $entities = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $entities->getCollection()->map(fn (Entity $entity) => $this->serializeEntity($entity))->all(),
            'meta' => [
                'current_page' => $entities->currentPage(),
                'last_page' => $entities->lastPage(),
                'per_page' => $entities->perPage(),
                'total' => $entities->total(),
            ],
        ]);
    }

    /**
     * Show one entity details.
     */
    public function show(Entity $entity): JsonResponse
    {
        $entity->loadCount(['contacts', 'tickets']);
        $entity->load([
            'contacts' => fn ($query) => $query
                ->with(['user:id,name,email', 'contactFunction:id,name'])
                ->orderBy('name'),
            'tickets' => fn ($query) => $query
                ->with(['inbox:id,name', 'creatorUser:id,name', 'creatorContact:id,name'])
                ->latest(),
        ]);

        return response()->json([
            'data' => array_merge(
                $this->serializeEntity($entity),
                [
                    'contacts' => $entity->contacts->map(fn ($contact) => [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                        'mobile_phone' => $contact->mobile_phone,
                        'function' => $contact->contactFunction?->name,
                        'is_active' => (bool) $contact->is_active,
                        'user' => $contact->user ? [
                            'id' => $contact->user->id,
                            'name' => $contact->user->name,
                            'email' => $contact->user->email,
                        ] : null,
                    ])->values()->all(),
                    'associated_tickets' => $entity->tickets->map(fn ($ticket) => [
                        'id' => $ticket->id,
                        'ticket_number' => $ticket->ticket_number,
                        'subject' => $ticket->subject,
                        'status' => $ticket->status,
                        'priority' => $ticket->priority,
                        'created_at' => optional($ticket->created_at)?->toIso8601String(),
                        'inbox' => $ticket->inbox ? [
                            'id' => $ticket->inbox->id,
                            'name' => $ticket->inbox->name,
                        ] : null,
                        'creator_user' => $ticket->creatorUser ? [
                            'id' => $ticket->creatorUser->id,
                            'name' => $ticket->creatorUser->name,
                        ] : null,
                        'creator_contact' => $ticket->creatorContact ? [
                            'id' => $ticket->creatorContact->id,
                            'name' => $ticket->creatorContact->name,
                        ] : null,
                    ])->values()->all(),
                ]
            ),
        ]);
    }

    /**
     * Create entity.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['internal', 'external'])],
            'name' => ['required', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile_phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $name = trim((string) $validated['name']);

        $entity = Entity::query()->create([
            'type' => $validated['type'],
            'name' => $name,
            'slug' => $this->resolveSlug($name),
            'tax_number' => $validated['tax_number'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'mobile_phone' => $validated['mobile_phone'] ?? null,
            'website' => $validated['website'] ?? null,
            'address_line' => $validated['address_line'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'city' => $validated['city'] ?? null,
            'country' => strtoupper((string) ($validated['country'] ?? 'PT')),
            'notes' => $validated['notes'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $entity->loadCount(['contacts', 'tickets']);

        return response()->json([
            'message' => 'Entity created successfully.',
            'data' => $this->serializeEntity($entity),
        ], 201);
    }

    /**
     * Update entity.
     */
    public function update(Request $request, Entity $entity): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['internal', 'external'])],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'mobile_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No changes to apply.']);
        }

        if (array_key_exists('type', $validated)) {
            $entity->type = $validated['type'];
        }

        if (array_key_exists('name', $validated)) {
            $entity->name = trim((string) $validated['name']);
            $entity->slug = $this->resolveSlug($entity->name, $entity->id);
        }

        foreach (['tax_number', 'email', 'phone', 'mobile_phone', 'website', 'address_line', 'postal_code', 'city', 'notes'] as $field) {
            if (array_key_exists($field, $validated)) {
                $entity->{$field} = $validated[$field];
            }
        }

        if (array_key_exists('country', $validated)) {
            $entity->country = strtoupper((string) ($validated['country'] ?: 'PT'));
        }

        if (array_key_exists('is_active', $validated)) {
            $entity->is_active = (bool) $validated['is_active'];
        }

        $entity->save();
        $entity->loadCount(['contacts', 'tickets']);

        return response()->json([
            'message' => 'Entity updated successfully.',
            'data' => $this->serializeEntity($entity),
        ]);
    }

    /**
     * Delete entity.
     */
    public function destroy(Entity $entity): JsonResponse
    {
        if ($entity->tickets()->exists()) {
            return response()->json([
                'message' => 'Cannot delete entity with existing tickets.',
            ], 422);
        }

        if ($entity->contacts()->exists()) {
            return response()->json([
                'message' => 'Cannot delete entity with existing contacts.',
            ], 422);
        }

        $entity->delete();

        return response()->json([
            'message' => 'Entity deleted successfully.',
        ]);
    }

    /**
     * Serialize entity.
     *
     * @return array<string, mixed>
     */
    private function serializeEntity(Entity $entity): array
    {
        return [
            'id' => $entity->id,
            'type' => $entity->type,
            'name' => $entity->name,
            'slug' => $entity->slug,
            'tax_number' => $entity->tax_number,
            'email' => $entity->email,
            'phone' => $entity->phone,
            'mobile_phone' => $entity->mobile_phone,
            'website' => $entity->website,
            'address_line' => $entity->address_line,
            'postal_code' => $entity->postal_code,
            'city' => $entity->city,
            'country' => $entity->country,
            'notes' => $entity->notes,
            'is_active' => (bool) $entity->is_active,
            'contacts_count' => (int) ($entity->contacts_count ?? 0),
            'tickets_count' => (int) ($entity->tickets_count ?? 0),
        ];
    }

    /**
     * Resolve unique slug value.
     */
    private function resolveSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'entity';

        $slug = $base;
        $suffix = 2;

        while (Entity::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix += 1;
        }

        return $slug;
    }
}

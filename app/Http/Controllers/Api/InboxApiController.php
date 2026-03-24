<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InboxApiController extends Controller
{
    /**
     * List inboxes.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Inbox::query()
            ->withCount(['tickets', 'operators'])
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $inboxes = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $inboxes->getCollection()->map(fn (Inbox $inbox) => $this->serializeInbox($inbox))->all(),
            'meta' => [
                'current_page' => $inboxes->currentPage(),
                'last_page' => $inboxes->lastPage(),
                'per_page' => $inboxes->perPage(),
                'total' => $inboxes->total(),
            ],
        ]);
    }

    /**
     * Create inbox.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $name = trim((string) $validated['name']);

        $inbox = Inbox::query()->create([
            'name' => $name,
            'slug' => $this->resolveSlug($name),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $inbox->loadCount(['tickets', 'operators']);

        return response()->json([
            'message' => 'Inbox created successfully.',
            'data' => $this->serializeInbox($inbox),
        ], 201);
    }

    /**
     * Update inbox.
     */
    public function update(Request $request, Inbox $inbox): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No changes to apply.']);
        }

        if (array_key_exists('name', $validated)) {
            $inbox->name = trim((string) $validated['name']);
            $inbox->slug = $this->resolveSlug($inbox->name, $inbox->id);
        }

        if (array_key_exists('is_active', $validated)) {
            $inbox->is_active = (bool) $validated['is_active'];
        }

        $inbox->save();
        $inbox->loadCount(['tickets', 'operators']);

        return response()->json([
            'message' => 'Inbox updated successfully.',
            'data' => $this->serializeInbox($inbox),
        ]);
    }

    /**
     * Delete inbox.
     */
    public function destroy(Inbox $inbox): JsonResponse
    {
        if ($inbox->tickets()->exists()) {
            return response()->json([
                'message' => 'Cannot delete inbox with existing tickets.',
            ], 422);
        }

        $inbox->operators()->detach();
        $inbox->delete();

        return response()->json([
            'message' => 'Inbox deleted successfully.',
        ]);
    }

    /**
     * Serialize inbox.
     *
     * @return array<string, mixed>
     */
    private function serializeInbox(Inbox $inbox): array
    {
        return [
            'id' => $inbox->id,
            'name' => $inbox->name,
            'slug' => $inbox->slug,
            'is_active' => (bool) $inbox->is_active,
            'tickets_count' => (int) ($inbox->tickets_count ?? 0),
            'operators_count' => (int) ($inbox->operators_count ?? 0),
        ];
    }

    /**
     * Resolve unique slug value.
     */
    private function resolveSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'inbox';

        $slug = $base;
        $suffix = 2;

        while (Inbox::query()
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix += 1;
        }

        return $slug;
    }
}

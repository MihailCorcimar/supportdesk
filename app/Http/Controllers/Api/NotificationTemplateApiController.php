<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationTemplateApiController extends Controller
{
    /**
     * List notification templates.
     */
    public function index(): JsonResponse
    {
        $eventKeys = $this->supportedEventKeys();
        $templates = NotificationTemplate::query()
            ->whereIn('event_key', $eventKeys)
            ->get()
            ->keyBy('event_key');

        return response()->json([
            'data' => collect($eventKeys)
                ->map(fn (string $eventKey) => $this->serializeTemplate($eventKey, $templates->get($eventKey)))
                ->values()
                ->all(),
            'meta' => [
                'placeholders' => $this->placeholderKeys(),
            ],
        ]);
    }

    /**
     * Update one notification template by event key.
     */
    public function update(Request $request, string $eventKey): JsonResponse
    {
        if (! in_array($eventKey, $this->supportedEventKeys(), true)) {
            abort(404);
        }

        $validated = $request->validate([
            'subject_template' => ['sometimes', 'required', 'string', 'max:255'],
            'title_template' => ['sometimes', 'required', 'string', 'max:255'],
            'body_template' => ['sometimes', 'required', 'string', 'max:10000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'No changes to apply.',
                'data' => $this->serializeTemplate(
                    $eventKey,
                    NotificationTemplate::query()->where('event_key', $eventKey)->first()
                ),
            ]);
        }

        $template = NotificationTemplate::query()->firstOrNew(['event_key' => $eventKey]);

        foreach (['subject_template', 'title_template', 'body_template', 'is_enabled'] as $field) {
            if (array_key_exists($field, $validated)) {
                $template->{$field} = $validated[$field];
            }
        }

        $template->updated_by_user_id = $request->user()->id;
        $template->save();

        return response()->json([
            'message' => 'Notification template updated.',
            'data' => $this->serializeTemplate($eventKey, $template),
        ]);
    }

    /**
     * @return list<string>
     */
    private function supportedEventKeys(): array
    {
        return [
            'ticket_created',
            'ticket_replied',
            'ticket_assignment_updated',
            'ticket_status_updated',
        ];
    }

    /**
     * @return list<string>
     */
    private function placeholderKeys(): array
    {
        return [
            '{ticket_number}',
            '{subject}',
            '{status}',
            '{priority}',
            '{type}',
            '{inbox}',
            '{entity}',
            '{contact}',
            '{creator_name}',
            '{assigned_operator}',
            '{author_name}',
            '{message_preview}',
            '{cc_emails}',
            '{ticket_url}',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTemplate(string $eventKey, ?NotificationTemplate $template): array
    {
        $subjectDefault = (string) config("supportdesk.email.subjects.{$eventKey}", '[Supportdesk] Ticket {ticket_number}');
        $titleDefault = (string) config("supportdesk.email.templates.{$eventKey}.title", 'Ticket update');
        $bodyDefault = (string) config("supportdesk.email.templates.{$eventKey}.body", 'Ticket {ticket_number}');

        return [
            'event_key' => $eventKey,
            'subject_template' => $template?->subject_template ?: $subjectDefault,
            'title_template' => $template?->title_template ?: $titleDefault,
            'body_template' => $template?->body_template ?: $bodyDefault,
            'is_enabled' => $template?->is_enabled ?? true,
            'available_placeholders' => $this->placeholderKeys(),
            'updated_at' => optional($template?->updated_at)->toIso8601String(),
            'updated_by_user_id' => $template?->updated_by_user_id,
        ];
    }
}

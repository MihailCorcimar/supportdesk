<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InboxNotificationTemplate;
use App\Models\NotificationTemplate;
use App\Models\SupportdeskSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationTemplateApiController extends Controller
{
    /**
     * List notification templates.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inbox_id' => ['nullable', 'integer', Rule::exists('inboxes', 'id')],
        ]);
        $inboxId = $this->resolveScopedInboxId($request, $validated);

        $eventKeys = $this->supportedEventKeys();
        $globalTemplates = NotificationTemplate::query()
            ->whereIn('event_key', $eventKeys)
            ->get()
            ->keyBy('event_key');
        $inboxTemplates = $inboxId === null
            ? collect()
            : InboxNotificationTemplate::query()
                ->where('inbox_id', $inboxId)
                ->whereIn('event_key', $eventKeys)
                ->get()
                ->keyBy('event_key');

        return response()->json([
            'data' => collect($eventKeys)
                ->map(function (string $eventKey) use ($globalTemplates, $inboxTemplates, $inboxId): array {
                    $template = $inboxId === null
                        ? $globalTemplates->get($eventKey)
                        : ($inboxTemplates->get($eventKey) ?? $globalTemplates->get($eventKey));
                    $inheritedFromGlobal = $inboxId !== null
                        && ! $inboxTemplates->has($eventKey)
                        && $globalTemplates->has($eventKey);

                    return $this->serializeTemplate($eventKey, $template, $inboxId, $inheritedFromGlobal);
                })
                ->values()
                ->all(),
            'meta' => [
                'placeholders' => $this->placeholderKeys(),
                'email_style' => $this->resolveEmailStyle(),
                'email_style_defaults' => $this->styleDefaults(),
                'inbox_id' => $inboxId,
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
            'inbox_id' => ['nullable', 'integer', Rule::exists('inboxes', 'id')],
            'subject_template' => ['sometimes', 'required', 'string', 'max:255'],
            'title_template' => ['sometimes', 'required', 'string', 'max:255'],
            'body_template' => ['sometimes', 'required', 'string', 'max:10000'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $inboxId = $this->resolveScopedInboxId($request, $validated);
        $templateFields = ['subject_template', 'title_template', 'body_template', 'is_enabled'];

        if (! collect($templateFields)->contains(fn (string $field) => array_key_exists($field, $validated))) {
            [$currentTemplate, $inheritedFromGlobal] = $this->resolveTemplateRecord($eventKey, $inboxId);

            return response()->json([
                'message' => 'Sem alteracoes para aplicar.',
                'data' => $this->serializeTemplate(
                    $eventKey,
                    $currentTemplate,
                    $inboxId,
                    $inheritedFromGlobal
                ),
            ]);
        }

        $template = $inboxId === null
            ? NotificationTemplate::query()->firstOrNew(['event_key' => $eventKey])
            : InboxNotificationTemplate::query()->firstOrNew([
                'event_key' => $eventKey,
                'inbox_id' => $inboxId,
            ]);

        foreach ($templateFields as $field) {
            if (array_key_exists($field, $validated)) {
                $template->{$field} = $validated[$field];
            }
        }

        $template->updated_by_user_id = $request->user()->id;
        $template->save();

        return response()->json([
            'message' => 'Template de notificacao atualizado.',
            'data' => $this->serializeTemplate($eventKey, $template, $inboxId, false),
        ]);
    }

    /**
     * Update email visual style.
     */
    public function updateStyle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand_name' => ['required', 'string', 'max:120'],
            'header_background' => ['required', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'accent_color' => ['required', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'button_text' => ['required', 'string', 'max:120'],
            'footer_text' => ['required', 'string', 'max:300'],
            'show_ticket_link' => ['required', 'boolean'],
        ]);

        $style = [
            'brand_name' => trim($validated['brand_name']) ?: $this->styleDefaults()['brand_name'],
            'header_background' => strtoupper($validated['header_background']),
            'accent_color' => strtoupper($validated['accent_color']),
            'button_text' => trim($validated['button_text']) ?: $this->styleDefaults()['button_text'],
            'footer_text' => trim($validated['footer_text']) ?: $this->styleDefaults()['footer_text'],
            'show_ticket_link' => (bool) $validated['show_ticket_link'],
        ];

        $setting = SupportdeskSetting::query()->firstOrNew(['key' => 'email_style']);
        $setting->value = $style;
        $setting->updated_by_user_id = $request->user()->id;
        $setting->save();

        return response()->json([
            'message' => 'Aspeto do email atualizado.',
            'data' => $style,
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
            'ticket_knowledge_updated',
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
    private function serializeTemplate(
        string $eventKey,
        NotificationTemplate|InboxNotificationTemplate|null $template,
        ?int $inboxId = null,
        bool $inheritedFromGlobal = false
    ): array
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
            'inbox_id' => $inboxId,
            'inherited_from_global' => $inheritedFromGlobal,
        ];
    }

    /**
     * Resolve and authorize optional inbox scope.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveScopedInboxId(Request $request, array $validated): ?int
    {
        $user = $request->user();

        if (! array_key_exists('inbox_id', $validated) || $validated['inbox_id'] === null) {
            if ($user->isAdmin()) {
                return null;
            }

            $defaultInboxId = $user->manageableInboxes()
                ->orderBy('inboxes.id')
                ->value('inboxes.id');

            if (! $defaultInboxId) {
                abort(403, 'You cannot manage notification templates.');
            }

            return (int) $defaultInboxId;
        }

        $inboxId = (int) $validated['inbox_id'];

        if (! $user->isAdmin() && ! $user->hasInboxManagementAccess($inboxId)) {
            abort(403, 'You cannot manage notification templates for this inbox.');
        }

        return $inboxId;
    }

    /**
     * Resolve effective template record for a scope.
     *
     * @return array{0: NotificationTemplate|InboxNotificationTemplate|null, 1: bool}
     */
    private function resolveTemplateRecord(string $eventKey, ?int $inboxId): array
    {
        if ($inboxId === null) {
            return [NotificationTemplate::query()->where('event_key', $eventKey)->first(), false];
        }

        $inboxTemplate = InboxNotificationTemplate::query()
            ->where('inbox_id', $inboxId)
            ->where('event_key', $eventKey)
            ->first();

        if ($inboxTemplate) {
            return [$inboxTemplate, false];
        }

        $globalTemplate = NotificationTemplate::query()->where('event_key', $eventKey)->first();

        return [$globalTemplate, $globalTemplate !== null];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveEmailStyle(): array
    {
        $defaults = $this->styleDefaults();
        $style = SupportdeskSetting::query()->where('key', 'email_style')->value('value');

        if (! is_array($style)) {
            return $defaults;
        }

        return [
            'brand_name' => trim((string) ($style['brand_name'] ?? $defaults['brand_name'])) ?: $defaults['brand_name'],
            'header_background' => $this->normalizeHexColor((string) ($style['header_background'] ?? $defaults['header_background']), $defaults['header_background']),
            'accent_color' => $this->normalizeHexColor((string) ($style['accent_color'] ?? $defaults['accent_color']), $defaults['accent_color']),
            'button_text' => trim((string) ($style['button_text'] ?? $defaults['button_text'])) ?: $defaults['button_text'],
            'footer_text' => trim((string) ($style['footer_text'] ?? $defaults['footer_text'])) ?: $defaults['footer_text'],
            'show_ticket_link' => array_key_exists('show_ticket_link', $style)
                ? (bool) $style['show_ticket_link']
                : (bool) $defaults['show_ticket_link'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function styleDefaults(): array
    {
        return [
            'brand_name' => (string) config('supportdesk.email.style.brand_name', config('app.name', 'Supportdesk')),
            'header_background' => strtoupper((string) config('supportdesk.email.style.header_background', '#0f766e')),
            'accent_color' => strtoupper((string) config('supportdesk.email.style.accent_color', '#0f766e')),
            'button_text' => (string) config('supportdesk.email.style.button_text', 'Aceder ao ticket'),
            'footer_text' => (string) config('supportdesk.email.style.footer_text', 'Mensagem automatica enviada pelo Supportdesk.'),
            'show_ticket_link' => (bool) config('supportdesk.email.style.show_ticket_link', true),
        ];
    }

    private function normalizeHexColor(string $value, string $fallback): string
    {
        $trimmed = trim($value);

        if (preg_match('/^#[A-Fa-f0-9]{6}$/', $trimmed) === 1) {
            return strtoupper($trimmed);
        }

        return strtoupper($fallback);
    }
}

<?php

namespace App\Services;

use App\Mail\TicketEventMail;
use App\Models\InboxNotificationTemplate;
use App\Models\NotificationTemplate;
use App\Models\SupportdeskSetting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class TicketNotificationService
{
    /**
     * @var array<string, array{subject_template:string,title_template:string,body_template:string,is_enabled:bool}>
     */
    private array $templateCache = [];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $emailStyleCache = null;
    private ?bool $hasInboxTemplatesTable = null;
    private ?bool $hasGlobalTemplatesTable = null;

    /**
     * Send notification after a ticket is created.
     */
    public function notifyTicketCreated(Ticket $ticket): void
    {
        $recipients = $this->collectRecipients($ticket);

        $this->sendToRecipients(
            $ticket,
            $recipients,
            [
                'event_key' => 'ticket_created',
            ]
        );

        $this->createInAppNotifications(
            $ticket,
            'ticket_created',
            "Novo ticket {$ticket->ticket_number}",
            $ticket->subject
        );
    }

    /**
     * Send notification after a message is added.
     */
    public function notifyTicketReplied(Ticket $ticket, TicketMessage $message): void
    {
        $actorEmail = $message->author_type === 'user'
            ? $message->authorUser?->email
            : $message->authorContact?->email;

        $recipients = collect($this->collectRecipients($ticket))
            ->reject(fn (string $email) => $actorEmail && strcasecmp($email, $actorEmail) === 0)
            ->values()
            ->all();

        $preview = mb_substr(trim((string) $message->body), 0, 240);
        $authorName = $message->author_type === 'user'
            ? ($message->authorUser?->name ?? 'Operador')
            : ($message->authorContact?->name ?? 'Cliente');

        $this->sendToRecipients(
            $ticket,
            $recipients,
            [
                'event_key' => 'ticket_replied',
                'author_name' => $authorName,
                'message_preview' => $preview !== '' ? $preview : 'Resposta com anexos sem texto.',
            ]
        );

        $actorUserId = $message->author_type === 'user'
            ? $message->author_user_id
            : $message->authorContact?->user_id;

        $this->createInAppNotifications(
            $ticket,
            'ticket_replied',
            "Nova resposta no {$ticket->ticket_number}",
            $preview !== '' ? $preview : 'Resposta com anexos sem texto.',
            $actorUserId,
            [
                'author_name' => $authorName,
            ]
        );
    }

    /**
     * Send notification after assignment update.
     */
    public function notifyAssignmentUpdated(Ticket $ticket): void
    {
        $recipients = $this->collectRecipients($ticket);

        $this->sendToRecipients(
            $ticket,
            $recipients,
            [
                'event_key' => 'ticket_assignment_updated',
            ]
        );

        $assignedName = $ticket->assignedOperator?->name ?? 'Sem atribuiÃ§Ã£o';

        $this->createInAppNotifications(
            $ticket,
            'ticket_assignment_updated',
            "AtribuiÃ§Ã£o atualizada em {$ticket->ticket_number}",
            "Operador atribuÃ­do: {$assignedName}",
            null,
            [
                'assigned_operator' => $assignedName,
            ]
        );
    }

    /**
     * Send notification after status update.
     */
    public function notifyStatusUpdated(Ticket $ticket): void
    {
        $recipients = $this->collectRecipients($ticket);

        $this->sendToRecipients(
            $ticket,
            $recipients,
            [
                'event_key' => 'ticket_status_updated',
            ]
        );

        $this->createInAppNotifications(
            $ticket,
            'ticket_status_updated',
            "Estado atualizado em {$ticket->ticket_number}",
            "Estado atual: {$this->statusLabel($ticket->status)}",
            null,
            [
                'status_label' => $this->statusLabel($ticket->status),
            ]
        );
    }

    /**
     * Send notification after knowledge/followers update.
     */
    public function notifyKnowledgeUpdated(Ticket $ticket): void
    {
        $recipients = $this->collectRecipients($ticket);

        $this->sendToRecipients(
            $ticket,
            $recipients,
            [
                'event_key' => 'ticket_knowledge_updated',
            ]
        );

        $this->createInAppNotifications(
            $ticket,
            'ticket_knowledge_updated',
            "Conhecimento atualizado em {$ticket->ticket_number}",
            'Utilizadores em conhecimento foram atualizados.'
        );
    }

    /**
     * Build notification recipient list.
     *
     * @return list<string>
     */
    private function collectRecipients(Ticket $ticket): array
    {
        $ticket->loadMissing(['creatorUser', 'contact', 'assignedOperator', 'followers', 'inbox.operators']);
        $followerEmails = $ticket->followers
            ->pluck('email')
            ->filter(fn (mixed $email) => is_string($email) && trim($email) !== '')
            ->values()
            ->all();
        $inboxOperatorEmails = ($ticket->inbox?->operators ?? collect())
            ->where('is_active', true)
            ->pluck('email')
            ->filter(fn (mixed $email) => is_string($email) && trim($email) !== '')
            ->values()
            ->all();

        return collect([
            $ticket->creatorUser?->email,
            $ticket->contact?->email,
            $ticket->assignedOperator?->email,
            ...($ticket->cc_emails ?? []),
            ...$followerEmails,
            ...$inboxOperatorEmails,
        ])
            ->filter()
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique(fn (string $email) => mb_strtolower($email))
            ->values()
            ->all();
    }

    /**
     * Build in-app notification recipients.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function collectRecipientUsers(Ticket $ticket): Collection
    {
        $ticket->loadMissing(['creatorUser', 'contact.user', 'assignedOperator', 'followers', 'inbox.operators']);

        $ccEmails = collect($ticket->cc_emails ?? [])
            ->filter(fn (mixed $email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        $usersByCc = $ccEmails->isEmpty()
            ? collect()
            : User::query()
                ->whereRaw('LOWER(email) in ('.implode(',', array_fill(0, $ccEmails->count(), '?')).')', $ccEmails->all())
                ->where('is_active', true)
                ->get();

        $recipients = collect([
            $ticket->creatorUser,
            $ticket->contact?->user,
            $ticket->assignedOperator,
        ])
            ->merge($ticket->followers)
            ->merge(($ticket->inbox?->operators ?? collect()))
            ->merge($usersByCc)
            ->filter(fn (mixed $user) => $user instanceof User && $user->is_active)
            ->unique(fn (User $user) => $user->id)
            ->values();

        if ($recipients->isEmpty()) {
            return $recipients;
        }

        $creatorId = (int) ($ticket->creatorUser?->id ?? 0);
        $contactUserId = (int) ($ticket->contact?->user?->id ?? 0);

        return $recipients
            ->reject(function (User $user) use ($creatorId, $contactUserId) {
                if (! $user->isClient()) {
                    return false;
                }

                return $user->id !== $creatorId && $user->id !== $contactUserId;
            })
            ->values();
    }

    /**
     * Persist in-app notifications.
     *
     * @param  array<string, mixed>  $extraPayload
     */
    private function createInAppNotifications(
        Ticket $ticket,
        string $eventKey,
        string $title,
        ?string $message = null,
        ?int $excludeUserId = null,
        array $extraPayload = []
    ): void {
        $recipients = $this->collectRecipientUsers($ticket)
            ->reject(fn (User $user) => $excludeUserId !== null && $user->id === $excludeUserId)
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $basePayload = [
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'type' => $ticket->type,
        ];

        $payload = array_merge($basePayload, $extraPayload);

        foreach ($recipients as $recipient) {
            UserNotification::query()->create([
                'user_id' => $recipient->id,
                'ticket_id' => $ticket->id,
                'event_key' => $eventKey,
                'title' => $title,
                'message' => $message ?: $ticket->subject,
                'payload' => $payload,
            ]);
        }
    }

    /**
     * Send message to recipients.
     *
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $payload
     */
    private function sendToRecipients(Ticket $ticket, array $recipients, array $payload): void
    {
        if (! config('supportdesk.email.enabled') || $recipients === []) {
            return;
        }

        $eventKey = (string) Arr::get($payload, 'event_key', '');
        if ($eventKey === '') {
            return;
        }

        $template = $this->resolveTemplate($eventKey, (int) $ticket->inbox_id);
        if (! $template['is_enabled']) {
            return;
        }

        $placeholders = $this->buildPlaceholders($ticket, $payload);
        $subjectLine = $this->renderTemplate($template['subject_template'], $placeholders);
        $title = $this->renderTemplate($template['title_template'], $placeholders);
        $body = $this->renderTemplate($template['body_template'], $placeholders);
        $lines = $this->renderBodyLines($body, $ticket);

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(
                    new TicketEventMail($ticket, $subjectLine, $title, $lines, $this->resolveEmailStyle())
                );
            } catch (\Throwable $exception) {
                Log::warning('Supportdesk notification failed', [
                    'ticket_id' => $ticket->id,
                    'recipient' => $recipient,
                    'subject_key' => $eventKey,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Resolve template from database with config fallback.
     *
     * @return array{subject_template:string,title_template:string,body_template:string,is_enabled:bool}
     */
    private function resolveTemplate(string $eventKey, ?int $inboxId = null): array
    {
        $cacheKey = "{$eventKey}:".($inboxId ?: 'global');
        if (isset($this->templateCache[$cacheKey])) {
            return $this->templateCache[$cacheKey];
        }

        $template = null;
        if ($inboxId && $this->canUseInboxTemplatesTable()) {
            $template = InboxNotificationTemplate::query()
                ->where('inbox_id', $inboxId)
                ->where('event_key', $eventKey)
                ->first();
        }

        if (! $template && $this->canUseGlobalTemplatesTable()) {
            $template = NotificationTemplate::query()
                ->where('event_key', $eventKey)
                ->first();
        }

        $subjectDefault = (string) config("supportdesk.email.subjects.{$eventKey}", '[Supportdesk] Ticket {ticket_number}');
        $titleDefault = (string) config("supportdesk.email.templates.{$eventKey}.title", 'Ticket updated');
        $bodyDefault = (string) config("supportdesk.email.templates.{$eventKey}.body", 'Ticket {ticket_number}');

        $resolved = [
            'subject_template' => $template?->subject_template ?: $subjectDefault,
            'title_template' => $template?->title_template ?: $titleDefault,
            'body_template' => $template?->body_template ?: $bodyDefault,
            'is_enabled' => $template?->is_enabled ?? true,
        ];

        $this->templateCache[$cacheKey] = $resolved;

        return $resolved;
    }

    private function canUseInboxTemplatesTable(): bool
    {
        if ($this->hasInboxTemplatesTable === null) {
            $this->hasInboxTemplatesTable = Schema::hasTable('inbox_notification_templates');
        }

        return $this->hasInboxTemplatesTable;
    }

    private function canUseGlobalTemplatesTable(): bool
    {
        if ($this->hasGlobalTemplatesTable === null) {
            $this->hasGlobalTemplatesTable = Schema::hasTable('notification_templates');
        }

        return $this->hasGlobalTemplatesTable;
    }

    /**
     * Resolve email style from database with config fallback.
     *
     * @return array{brand_name:string,header_background:string,accent_color:string,button_text:string,footer_text:string,show_ticket_link:bool}
     */
    private function resolveEmailStyle(): array
    {
        if (is_array($this->emailStyleCache)) {
            return $this->emailStyleCache;
        }

        $defaults = [
            'brand_name' => (string) config('supportdesk.email.style.brand_name', config('app.name', 'Supportdesk')),
            'header_background' => (string) config('supportdesk.email.style.header_background', '#0f766e'),
            'accent_color' => (string) config('supportdesk.email.style.accent_color', '#0f766e'),
            'button_text' => (string) config('supportdesk.email.style.button_text', 'Aceder ao ticket'),
            'footer_text' => (string) config('supportdesk.email.style.footer_text', 'Mensagem automatica enviada pelo Supportdesk.'),
            'show_ticket_link' => (bool) config('supportdesk.email.style.show_ticket_link', true),
        ];

        $record = SupportdeskSetting::query()->where('key', 'email_style')->first();
        $stored = is_array($record?->value) ? $record->value : [];

        $resolved = [
            'brand_name' => trim((string) ($stored['brand_name'] ?? $defaults['brand_name'])) ?: $defaults['brand_name'],
            'header_background' => $this->normalizeHexColor((string) ($stored['header_background'] ?? $defaults['header_background']), $defaults['header_background']),
            'accent_color' => $this->normalizeHexColor((string) ($stored['accent_color'] ?? $defaults['accent_color']), $defaults['accent_color']),
            'button_text' => trim((string) ($stored['button_text'] ?? $defaults['button_text'])) ?: $defaults['button_text'],
            'footer_text' => trim((string) ($stored['footer_text'] ?? $defaults['footer_text'])) ?: $defaults['footer_text'],
            'show_ticket_link' => array_key_exists('show_ticket_link', $stored)
                ? (bool) $stored['show_ticket_link']
                : (bool) $defaults['show_ticket_link'],
        ];

        $this->emailStyleCache = $resolved;

        return $resolved;
    }

    private function normalizeHexColor(string $value, string $fallback): string
    {
        $trimmed = trim($value);
        if (preg_match('/^#[A-Fa-f0-9]{6}$/', $trimmed) === 1) {
            return strtoupper($trimmed);
        }

        return strtoupper($fallback);
    }
    /**
     * Convert status key to PT-PT label.
     */
    private function statusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'open'        => 'Aberto',
            'in_progress' => 'Em tratamento',
            'pending'     => 'Aguarda cliente',
            'closed'      => 'Fechado',
            'cancelled'   => 'Cancelado',
            default       => ucfirst(strtolower(trim($status))),
        };
    }

    /**
     * Convert priority key to PT-PT label.
     */
    private function priorityLabel(string $priority): string
    {
        return match (strtolower(trim($priority))) {
            'low'    => 'Baixa',
            'medium' => 'MÃ©dia',
            'high'   => 'Alta',
            'urgent' => 'Urgente',
            default  => ucfirst(strtolower(trim($priority))),
        };
    }

    /**
     * Convert type key to PT-PT label.
     */
    private function typeLabel(string $type): string
    {
        return match (strtolower(trim($type))) {
            'incident' => 'Incidente',
            'request'  => 'Pedido',
            'question' => 'QuestÃ£o',
            'task'     => 'Tarefa',
            default    => ucfirst(strtolower(trim($type))),
        };
    }

    /**
     * Build placeholders for subject/title/body templates.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function buildPlaceholders(Ticket $ticket, array $payload): array
    {
        $ticket->loadMissing(['inbox', 'entity', 'contact', 'creatorUser', 'creatorContact', 'assignedOperator']);

        $creatorName = $ticket->creatorUser?->name
            ?? $ticket->creatorContact?->name
            ?? $ticket->contact?->name
            ?? 'Desconhecido';

        $assignedOperator = $ticket->assignedOperator?->name ?? 'Sem atribuicao';
        $contactName = $ticket->contact?->name ?? '-';
        $entityName = $ticket->entity?->name ?? '-';
        $inboxName = $ticket->inbox?->name ?? '-';
        $ccEmails = implode(', ', $ticket->cc_emails ?? []);
        $messagePreview = trim((string) Arr::get($payload, 'message_preview', '-'));
        $authorName = trim((string) Arr::get($payload, 'author_name', $creatorName));

        if ($messagePreview === '') {
            $messagePreview = '-';
        }

        if ($authorName === '') {
            $authorName = $creatorName;
        }

        return [
            '{ticket_number}'     => (string) $ticket->ticket_number,
            '{subject}'           => (string) $ticket->subject,
            '{status}'            => $this->statusLabel((string) $ticket->status),
            '{priority}'          => $this->priorityLabel((string) $ticket->priority),
            '{type}'              => $this->typeLabel((string) $ticket->type),
            '{inbox}'             => $inboxName,
            '{entity}'            => $entityName,
            '{contact}'           => $contactName,
            '{creator_name}'      => $creatorName,
            '{assigned_operator}' => $assignedOperator,
            '{author_name}'       => $authorName,
            '{message_preview}'   => $messagePreview,
            '{cc_emails}'         => $ccEmails !== '' ? $ccEmails : '-',
            '{ticket_url}'        => url('/tickets/'.$ticket->id),
        ];
    }

    /**
     * Replace placeholders in template text.
     *
     * @param  array<string, string>  $placeholders
     */
    private function renderTemplate(string $template, array $placeholders): string
    {
        return trim(strtr($template, $placeholders));
    }

    /**
     * Build the mail body lines from template body.
     *
     * @return list<string>
     */
    private function renderBodyLines(string $body, Ticket $ticket): array
    {
        $lines = collect(preg_split('/\R/u', $body) ?: [])
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '')
            ->values()
            ->all();

        if ($lines === []) {
            return ["Ticket {$ticket->ticket_number} atualizado."];
        }

        return $lines;
    }
}


<?php

namespace App\Services;

use App\Mail\TicketEventMail;
use App\Models\NotificationTemplate;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TicketNotificationService
{
    /**
     * @var array<string, array{subject_template:string,title_template:string,body_template:string,is_enabled:bool}>
     */
    private array $templateCache = [];

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
            "New ticket {$ticket->ticket_number}",
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
            ? ($message->authorUser?->name ?? 'Operator')
            : ($message->authorContact?->name ?? 'Client');

        $this->sendToRecipients(
            $ticket,
            $recipients,
            [
                'event_key' => 'ticket_replied',
                'author_name' => $authorName,
                'message_preview' => $preview !== '' ? $preview : 'Reply with attachments only.',
            ]
        );

        $actorUserId = $message->author_type === 'user'
            ? $message->author_user_id
            : $message->authorContact?->user_id;

        $this->createInAppNotifications(
            $ticket,
            'ticket_replied',
            "New reply on {$ticket->ticket_number}",
            $preview !== '' ? $preview : 'Reply with attachments only.',
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

        $assignedName = $ticket->assignedOperator?->name ?? 'Unassigned';

        $this->createInAppNotifications(
            $ticket,
            'ticket_assignment_updated',
            "Assignment updated on {$ticket->ticket_number}",
            "Assigned operator: {$assignedName}"
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
            "Status updated on {$ticket->ticket_number}",
            "Current status: {$ticket->status}"
        );
    }

    /**
     * Build notification recipient list.
     *
     * @return list<string>
     */
    private function collectRecipients(Ticket $ticket): array
    {
        $ticket->loadMissing(['creatorUser', 'contact', 'assignedOperator', 'followers']);
        $followerEmails = $ticket->followers
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
        $ticket->loadMissing(['creatorUser', 'contact.user', 'assignedOperator', 'followers']);

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

        return collect([
            $ticket->creatorUser,
            $ticket->contact?->user,
            $ticket->assignedOperator,
        ])
            ->merge($ticket->followers)
            ->merge($usersByCc)
            ->filter(fn (mixed $user) => $user instanceof User && $user->is_active)
            ->unique(fn (User $user) => $user->id)
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

        $template = $this->resolveTemplate($eventKey);
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
                Mail::to($recipient)->send(new TicketEventMail($ticket, $subjectLine, $title, $lines));
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
    private function resolveTemplate(string $eventKey): array
    {
        if (isset($this->templateCache[$eventKey])) {
            return $this->templateCache[$eventKey];
        }

        $template = NotificationTemplate::query()
            ->where('event_key', $eventKey)
            ->first();

        $subjectDefault = (string) config("supportdesk.email.subjects.{$eventKey}", '[Supportdesk] Ticket {ticket_number}');
        $titleDefault = (string) config("supportdesk.email.templates.{$eventKey}.title", 'Ticket updated');
        $bodyDefault = (string) config("supportdesk.email.templates.{$eventKey}.body", 'Ticket {ticket_number}');

        $resolved = [
            'subject_template' => $template?->subject_template ?: $subjectDefault,
            'title_template' => $template?->title_template ?: $titleDefault,
            'body_template' => $template?->body_template ?: $bodyDefault,
            'is_enabled' => $template?->is_enabled ?? true,
        ];

        $this->templateCache[$eventKey] = $resolved;

        return $resolved;
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
            ?? 'Unknown';

        $assignedOperator = $ticket->assignedOperator?->name ?? 'Unassigned';
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
            '{ticket_number}' => (string) $ticket->ticket_number,
            '{subject}' => (string) $ticket->subject,
            '{status}' => (string) $ticket->status,
            '{priority}' => (string) $ticket->priority,
            '{type}' => (string) $ticket->type,
            '{inbox}' => $inboxName,
            '{entity}' => $entityName,
            '{contact}' => $contactName,
            '{creator_name}' => $creatorName,
            '{assigned_operator}' => $assignedOperator,
            '{author_name}' => $authorName,
            '{message_preview}' => $messagePreview,
            '{cc_emails}' => $ccEmails !== '' ? $ccEmails : '-',
            '{ticket_url}' => url('/tickets/'.$ticket->id),
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
            return ["Ticket {$ticket->ticket_number} updated."];
        }

        return $lines;
    }
}

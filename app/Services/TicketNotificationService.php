<?php

namespace App\Services;

use App\Mail\TicketEventMail;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TicketNotificationService
{
    /**
     * Send notification after a ticket is created.
     */
    public function notifyTicketCreated(Ticket $ticket): void
    {
        $recipients = $this->collectRecipients($ticket);

        $this->sendToRecipients(
            $ticket,
            $recipients,
            'ticket_created',
            'New ticket created',
            [
                "Ticket {$ticket->ticket_number} was created.",
                "Current status: {$ticket->status}.",
                "Priority: {$ticket->priority}.",
            ]
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

        $recipients = $this->collectRecipients($ticket)
            ->reject(fn (string $email) => $actorEmail && strcasecmp($email, $actorEmail) === 0)
            ->values();

        $preview = mb_substr(trim($message->body), 0, 240);

        $this->sendToRecipients(
            $ticket,
            $recipients->all(),
            'ticket_replied',
            'New ticket reply',
            [
                "A new reply was added to ticket {$ticket->ticket_number}.",
                $preview !== '' ? "Preview: {$preview}" : 'The reply includes attachments.',
            ]
        );
    }

    /**
     * Send notification after assignment update.
     */
    public function notifyAssignmentUpdated(Ticket $ticket): void
    {
        $recipients = $this->collectRecipients($ticket);

        $assignedOperator = $ticket->assignedOperator?->name ?? 'Unassigned';

        $this->sendToRecipients(
            $ticket,
            $recipients,
            'ticket_assignment_updated',
            'Assignment updated',
            [
                "Ticket {$ticket->ticket_number} assignment was updated.",
                "Current operator: {$assignedOperator}.",
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
            'ticket_status_updated',
            'Status updated',
            [
                "The status of ticket {$ticket->ticket_number} was updated.",
                "Current status: {$ticket->status}.",
            ]
        );
    }

    /**
     * Build notification recipient list.
     *
     * @return list<string>
     */
    private function collectRecipients(Ticket $ticket): array
    {
        $ticket->loadMissing(['creatorUser', 'contact', 'assignedOperator']);

        return collect([
            $ticket->creatorUser?->email,
            $ticket->contact?->email,
            $ticket->assignedOperator?->email,
            ...($ticket->cc_emails ?? []),
        ])
            ->filter()
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique(fn (string $email) => mb_strtolower($email))
            ->values()
            ->all();
    }

    /**
     * Send message to recipients.
     *
     * @param  list<string>  $recipients
     * @param  list<string>  $lines
     */
    private function sendToRecipients(
        Ticket $ticket,
        array $recipients,
        string $subjectKey,
        string $title,
        array $lines
    ): void {
        if (! config('supportdesk.email.enabled') || $recipients === []) {
            return;
        }

        $subjectTemplate = config("supportdesk.email.subjects.{$subjectKey}", '[Supportdesk] Ticket {ticket_number}');
        $subjectLine = $this->formatSubject((string) $subjectTemplate, $ticket);

        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient)->send(new TicketEventMail($ticket, $subjectLine, $title, $lines));
            } catch (\Throwable $exception) {
                Log::warning('Supportdesk notification failed', [
                    'ticket_id' => $ticket->id,
                    'recipient' => $recipient,
                    'subject_key' => $subjectKey,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * Replace placeholders in subject template.
     */
    private function formatSubject(string $template, Ticket $ticket): string
    {
        return strtr($template, [
            '{ticket_number}' => $ticket->ticket_number,
            '{subject}' => $ticket->subject,
            '{status}' => $ticket->status,
        ]);
    }
}
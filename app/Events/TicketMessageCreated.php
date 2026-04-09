<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\TicketMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\InteractsWithSockets;

class TicketMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int, TicketLog>  $logs
     */
    public function __construct(
        public Ticket $ticket,
        public TicketMessage $message,
        public array $logs = []
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('tickets.'.$this->ticket->id)];
    }

    public function broadcastAs(): string
    {
        return 'ticket.message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'message' => $this->serializeMessage($this->message),
            'logs' => array_values(array_filter(array_map(fn ($log) => $this->serializeLog($log), $this->logs))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(TicketMessage $message): array
    {
        $message->loadMissing(['authorUser', 'authorContact', 'attachmentsList']);

        return [
            'id' => $message->id,
            'author_type' => $message->author_type,
            'author_user' => $message->authorUser
                ? [
                    'id' => $message->authorUser->id,
                    'name' => $message->authorUser->name,
                    'email' => $message->authorUser->email,
                    'avatar_url' => $message->authorUser->avatar_url ?? null,
                ]
                : null,
            'author_contact' => $message->authorContact
                ? [
                    'id' => $message->authorContact->id,
                    'name' => $message->authorContact->name,
                ]
                : null,
            'body' => $message->body,
            'body_format' => $message->body_format,
            'is_internal' => (bool) $message->is_internal,
            'created_at' => optional($message->created_at)?->toIso8601String(),
            'can_edit' => false,
            'can_delete' => false,
            'attachments' => $message->attachmentsList->map(fn (TicketAttachment $attachment) => [
                'id' => $attachment->id,
                'uuid' => $attachment->uuid,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'download_url' => route('app-api.attachments.download', $attachment),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeLog(?TicketLog $log): ?array
    {
        if (! $log) {
            return null;
        }

        $log->loadMissing(['actorUser', 'actorContact']);

        return [
            'id' => $log->id,
            'action' => $log->action,
            'field' => $log->field,
            'old_value' => $log->old_value,
            'new_value' => $log->new_value,
            'actor_type' => $log->actor_type,
            'actor_user' => $log->actorUser
                ? [
                    'id' => $log->actorUser->id,
                    'name' => $log->actorUser->name,
                    'email' => $log->actorUser->email,
                    'avatar_url' => $log->actorUser->avatar_url ?? null,
                ]
                : null,
            'actor_contact' => $log->actorContact
                ? [
                    'id' => $log->actorContact->id,
                    'name' => $log->actorContact->name,
                ]
                : null,
            'created_at' => optional($log->created_at)?->toIso8601String(),
        ];
    }
}

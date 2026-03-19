<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Services\TicketNotificationService;
use App\Support\TicketActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketMessageApiController extends Controller
{
    public function __construct(
        private readonly TicketNotificationService $notificationService
    ) {
    }

    /**
     * Store a message for the given ticket.
     */
    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $this->authorize('reply', $ticket);

        $maxFiles = (int) config('supportdesk.attachments.max_files', 5);
        $maxFileSizeKb = (int) config('supportdesk.attachments.max_file_size_kb', 10240);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'required_without:attachments'],
            'is_internal' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', "max:{$maxFiles}"],
            'attachments.*' => ['file', "max:{$maxFileSizeKb}", 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,txt,csv,zip'],
        ]);

        $user = $request->user();
        $contact = null;
        $isInternal = $user->isOperator() && $request->boolean('is_internal');

        if ($user->isClient()) {
            $contact = Contact::query()
                ->where('entity_id', $ticket->entity_id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->firstOrFail();
        }

        /** @var array<int, UploadedFile> $files */
        $files = $request->file('attachments', []);

        $message = DB::transaction(function () use ($ticket, $validated, $user, $contact, $isInternal, $files): TicketMessage {
            $message = TicketMessage::query()->create([
                'ticket_id' => $ticket->id,
                'author_type' => $user->isClient() ? 'contact' : 'user',
                'author_user_id' => $user->isOperator() ? $user->id : null,
                'author_contact_id' => $user->isClient() ? $contact?->id : null,
                'body' => $validated['body'] ?? '',
                'is_internal' => $isInternal,
            ]);

            $attachmentsPayload = [];
            $disk = (string) config('supportdesk.attachments.disk', 'local');

            foreach ($files as $file) {
                $storedPath = $file->store("ticket-attachments/{$ticket->id}/{$message->id}", $disk);

                $attachment = TicketAttachment::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'ticket_id' => $ticket->id,
                    'ticket_message_id' => $message->id,
                    'disk' => $disk,
                    'path' => $storedPath,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by_user_id' => $user->isOperator() ? $user->id : null,
                    'uploaded_by_contact_id' => $user->isClient() ? $contact?->id : null,
                ]);

                $attachmentsPayload[] = [
                    'id' => $attachment->id,
                    'uuid' => $attachment->uuid,
                    'name' => $attachment->original_name,
                    'mime' => $attachment->mime_type,
                    'size' => $attachment->size,
                ];
            }

            if ($attachmentsPayload !== []) {
                $message->update([
                    'attachments' => $attachmentsPayload,
                ]);
            }

            if (! $isInternal && in_array($ticket->status, ['resolved', 'closed'], true)) {
                $oldStatus = $ticket->status;

                $ticket->update([
                    'status' => 'open',
                    'resolved_at' => null,
                    'closed_at' => null,
                ]);

                TicketActivityLogger::log(
                    $ticket,
                    'status_updated',
                    'status',
                    $oldStatus,
                    'open',
                    $user->isClient() ? 'contact' : 'user',
                    $user->isOperator() ? $user->id : null,
                    $user->isClient() ? $contact?->id : null
                );
            }

            $ticketUpdates = [
                'last_activity_at' => now(),
            ];

            if ($user->isOperator() && ! $isInternal && $ticket->first_response_at === null) {
                $ticketUpdates['first_response_at'] = now();
            }

            $ticket->update($ticketUpdates);

            $actor = TicketActivityLogger::actorFor($user, $contact);

            TicketActivityLogger::log(
                $ticket,
                'message_added',
                null,
                null,
                null,
                $actor['actor_type'],
                $actor['actor_user_id'],
                $actor['actor_contact_id'],
                [
                    'internal' => $isInternal,
                    'message_id' => $message->id,
                    'attachments_count' => count($attachmentsPayload),
                ]
            );

            if ($attachmentsPayload !== []) {
                TicketActivityLogger::log(
                    $ticket,
                    'attachments_added',
                    null,
                    null,
                    null,
                    $actor['actor_type'],
                    $actor['actor_user_id'],
                    $actor['actor_contact_id'],
                    [
                        'message_id' => $message->id,
                        'attachments' => array_map(fn (array $attachment) => $attachment['name'], $attachmentsPayload),
                    ]
                );
            }

            return $message;
        });

        $message->loadMissing(['authorUser', 'authorContact']);

        if (! $isInternal) {
            $this->notificationService->notifyTicketReplied($ticket, $message);
        }

        return response()->json([
            'message' => 'Message sent successfully.',
        ], 201);
    }
}


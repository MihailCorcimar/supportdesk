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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

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
        $user = $request->user();
        $isInternalRequest = $user->isOperator() && $request->boolean('is_internal');

        if ($isInternalRequest) {
            $this->authorize('view', $ticket);
        } else {
            $this->authorize('reply', $ticket);
        }

        $maxFiles = (int) config('supportdesk.attachments.max_files', 5);
        $maxFileSizeKb = (int) config('supportdesk.attachments.max_file_size_kb', 10240);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'required_without:attachments'],
            'body_format' => ['nullable', 'in:plain,html'],
            'is_internal' => ['nullable', 'boolean'],
            'attachments' => ['nullable', 'array', "max:{$maxFiles}"],
            'attachments.*' => ['file', "max:{$maxFileSizeKb}", 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,txt,csv,zip'],
        ]);

        $contact = null;
        $isInternal = $user->isOperator() && (bool) ($validated['is_internal'] ?? false);

        if ($user->isClient()) {
            $contact = Contact::query()
                ->whereHas('entities', fn (Builder $query) => $query->whereKey($ticket->entity_id))
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->firstOrFail();
        }

        /** @var array<int, UploadedFile> $files */
        $files = $request->file('attachments', []);
        $bodyFormat = $this->normalizeMessageFormat($validated['body_format'] ?? null);
        $body = $this->normalizeMessageBody($validated['body'] ?? '', $bodyFormat);

        if ($body === '' && $files === []) {
            throw ValidationException::withMessages([
                'body' => 'Message body is required when no attachments are provided.',
            ]);
        }

        $message = DB::transaction(function () use ($ticket, $user, $contact, $isInternal, $files, $body, $bodyFormat): TicketMessage {
            $message = TicketMessage::query()->create([
                'ticket_id' => $ticket->id,
                'author_type' => $user->isClient() ? 'contact' : 'user',
                'author_user_id' => $user->isOperator() ? $user->id : null,
                'author_contact_id' => $user->isClient() ? $contact?->id : null,
                'body' => $body,
                'body_format' => $bodyFormat,
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

    /**
     * Normalize message format.
     */
    private function normalizeMessageFormat(?string $format): string
    {
        return $format === 'html' ? 'html' : 'plain';
    }

    /**
     * Normalize and sanitize message body.
     */
    private function normalizeMessageBody(string $body, string $format): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '';
        }

        if ($format !== 'html') {
            return $trimmed;
        }

        return trim(strip_tags(
            $trimmed,
            '<p><br><strong><b><em><i><u><ul><ol><li><a><blockquote><code><pre>'
        ));
    }

    /**
     * Messages are immutable for audit history.
     */
    public function update(Request $request, Ticket $ticket, TicketMessage $message): JsonResponse
    {
        if ($message->ticket_id !== $ticket->id) {
            abort(404);
        }

        $this->authorize('view', $ticket);

        return response()->json([
            'message' => 'Ticket messages are immutable and cannot be edited.',
        ], 405);
    }

    /**
     * Messages are immutable for audit history.
     */
    public function destroy(Request $request, Ticket $ticket, TicketMessage $message): JsonResponse
    {
        if ($message->ticket_id !== $ticket->id) {
            abort(404);
        }

        $this->authorize('view', $ticket);

        return response()->json([
            'message' => 'Ticket messages are immutable and cannot be deleted.',
        ], 405);
    }
}

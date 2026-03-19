<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentApiController extends Controller
{
    /**
     * Download attachment file if user can view the parent ticket.
     */
    public function download(Request $request, TicketAttachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('ticket');

        $this->authorize('view', $attachment->ticket);

        if (! Storage::disk($attachment->disk)->exists($attachment->path)) {
            abort(404, 'Attachment not found.');
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream']
        );
    }
}

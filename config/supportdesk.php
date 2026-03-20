<?php

return [
    'attachments' => [
        'disk' => env('SUPPORTDESK_ATTACHMENTS_DISK', 'local'),
        'max_files' => (int) env('SUPPORTDESK_ATTACHMENTS_MAX_FILES', 5),
        'max_file_size_kb' => (int) env('SUPPORTDESK_ATTACHMENTS_MAX_FILE_SIZE_KB', 10240),
    ],

    'email' => [
        'enabled' => (bool) env('SUPPORTDESK_EMAIL_ENABLED', true),
        'subjects' => [
            'ticket_created' => env('SUPPORTDESK_SUBJECT_TICKET_CREATED', '[Supportdesk] New ticket {ticket_number}'),
            'ticket_replied' => env('SUPPORTDESK_SUBJECT_TICKET_REPLIED', '[Supportdesk] New reply on ticket {ticket_number}'),
            'ticket_assignment_updated' => env('SUPPORTDESK_SUBJECT_TICKET_ASSIGNMENT', '[Supportdesk] Assignment updated on ticket {ticket_number}'),
            'ticket_status_updated' => env('SUPPORTDESK_SUBJECT_TICKET_STATUS', '[Supportdesk] Status updated on ticket {ticket_number}'),
        ],
    ],

    'sla' => [
        'first_response_hours' => (int) env('SUPPORTDESK_SLA_FIRST_RESPONSE_HOURS', 4),
        'resolution_hours' => (int) env('SUPPORTDESK_SLA_RESOLUTION_HOURS', 24),
    ],

    'invites' => [
        'expiration_hours' => (int) env('SUPPORTDESK_INVITE_EXPIRATION_HOURS', 72),
    ],
];

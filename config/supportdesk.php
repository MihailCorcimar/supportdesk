<?php

return [
    'attachments' => [
        'disk' => env('SUPPORTDESK_ATTACHMENTS_DISK', 'local'),
        'max_files' => (int) env('SUPPORTDESK_ATTACHMENTS_MAX_FILES', 5),
        'max_file_size_kb' => (int) env('SUPPORTDESK_ATTACHMENTS_MAX_FILE_SIZE_KB', 10240),
    ],

    'email' => [
        'enabled' => (bool) env('SUPPORTDESK_EMAIL_ENABLED', true),
        'style' => [
            'brand_name' => env('SUPPORTDESK_EMAIL_BRAND_NAME', 'Supportdesk'),
            'header_background' => env('SUPPORTDESK_EMAIL_HEADER_BACKGROUND', '#0f766e'),
            'accent_color' => env('SUPPORTDESK_EMAIL_ACCENT_COLOR', '#0f766e'),
            'button_text' => env('SUPPORTDESK_EMAIL_BUTTON_TEXT', 'Aceder ao ticket'),
            'footer_text' => env('SUPPORTDESK_EMAIL_FOOTER_TEXT', 'Mensagem automatica enviada pelo Supportdesk.'),
            'show_ticket_link' => (bool) env('SUPPORTDESK_EMAIL_SHOW_TICKET_LINK', true),
        ],
        'subjects' => [
            'ticket_created' => env('SUPPORTDESK_SUBJECT_TICKET_CREATED', '[Supportdesk] Novo ticket {ticket_number}'),
            'ticket_replied' => env('SUPPORTDESK_SUBJECT_TICKET_REPLIED', '[Supportdesk] Nova resposta no ticket {ticket_number}'),
            'ticket_assignment_updated' => env('SUPPORTDESK_SUBJECT_TICKET_ASSIGNMENT', '[Supportdesk] Atribuicao atualizada no ticket {ticket_number}'),
            'ticket_status_updated' => env('SUPPORTDESK_SUBJECT_TICKET_STATUS', '[Supportdesk] Estado atualizado no ticket {ticket_number}'),
            'ticket_knowledge_updated' => env('SUPPORTDESK_SUBJECT_TICKET_KNOWLEDGE', '[Supportdesk] Conhecimento atualizado no ticket {ticket_number}'),
        ],
        'templates' => [
            'ticket_created' => [
                'title' => 'Novo ticket criado',
                'body' => "Foi criado um novo ticket.\nNumero: {ticket_number}\nAssunto: {subject}\nEstado: {status}\nPrioridade: {priority}",
            ],
            'ticket_replied' => [
                'title' => 'Nova resposta no ticket',
                'body' => "Foi adicionada uma nova resposta ao ticket {ticket_number}.\nAutor: {author_name}\nResumo: {message_preview}",
            ],
            'ticket_assignment_updated' => [
                'title' => 'Atribuicao atualizada',
                'body' => "A atribuicao do ticket {ticket_number} foi atualizada.\nOperador responsavel: {assigned_operator}",
            ],
            'ticket_status_updated' => [
                'title' => 'Estado do ticket atualizado',
                'body' => "O estado do ticket {ticket_number} foi atualizado.\nEstado atual: {status}",
            ],
            'ticket_knowledge_updated' => [
                'title' => 'Conhecimento do ticket atualizado',
                'body' => "A lista de utilizadores em conhecimento do ticket {ticket_number} foi atualizada.\nAssunto: {subject}\nUtilizadores em conhecimento: {cc_emails}\nPode acompanhar o ticket atraves do link abaixo.",
            ],
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

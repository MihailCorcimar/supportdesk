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
            'ticket_created' => env('SUPPORTDESK_SUBJECT_TICKET_CREATED', '[Supportdesk] Novo ticket {ticket_number} — {subject}'),
            'ticket_replied' => env('SUPPORTDESK_SUBJECT_TICKET_REPLIED', '[Supportdesk] Nova resposta — {ticket_number}'),
            'ticket_assignment_updated' => env('SUPPORTDESK_SUBJECT_TICKET_ASSIGNMENT', '[Supportdesk] Atribuição atualizada — {ticket_number}'),
            'ticket_status_updated' => env('SUPPORTDESK_SUBJECT_TICKET_STATUS', '[Supportdesk] Estado atualizado — {ticket_number}'),
            'ticket_knowledge_updated' => env('SUPPORTDESK_SUBJECT_TICKET_KNOWLEDGE', '[Supportdesk] Conhecimento atualizado — {ticket_number}'),
        ],
        'templates' => [
            'ticket_created' => [
                'title' => 'Novo ticket criado',
                'body' => "Foi criado um novo ticket que necessita de atenção.\nEntidade: {entity} | Contacto: {contact}\nCriado por: {creator_name}",
            ],
            'ticket_replied' => [
                'title' => 'Nova resposta adicionada',
                'body' => "Foi adicionada uma nova resposta por {author_name}.\n{message_preview}",
            ],
            'ticket_assignment_updated' => [
                'title' => 'Atribuição atualizada',
                'body' => "O ticket foi atribuído ao operador {assigned_operator}.",
            ],
            'ticket_status_updated' => [
                'title' => 'Estado atualizado',
                'body' => "O estado do ticket foi alterado para {status}.",
            ],
            'ticket_knowledge_updated' => [
                'title' => 'Lista de conhecimento atualizada',
                'body' => "A lista de utilizadores em conhecimento foi atualizada.\nUtilizadores em conhecimento: {cc_emails}\nPode acompanhar a evolução do ticket através do link abaixo.",
            ],
        ],
    ],

    'sla' => [
        'first_response_hours' => (int) env('SUPPORTDESK_SLA_FIRST_RESPONSE_HOURS', 4),
        'resolution_hours' => (int) env('SUPPORTDESK_SLA_RESOLUTION_HOURS', 24),
    ],

    'dashboard' => [
        'critical_backlog_hours' => (int) env('SUPPORTDESK_DASHBOARD_CRITICAL_BACKLOG_HOURS', 24),
        'daily_flow_days' => (int) env('SUPPORTDESK_DASHBOARD_DAILY_FLOW_DAYS', 14),
    ],

    'invites' => [
        'expiration_hours' => (int) env('SUPPORTDESK_INVITE_EXPIRATION_HOURS', 72),
    ],
];

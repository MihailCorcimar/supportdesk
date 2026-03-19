<?php

return [
    'required' => 'O campo :attribute e obrigatorio.',
    'email' => 'O campo :attribute deve ser um email valido.',
    'exists' => 'O valor selecionado para :attribute e invalido.',
    'in' => 'O valor selecionado para :attribute e invalido.',
    'max' => [
        'string' => 'O campo :attribute nao pode ter mais de :max caracteres.',
    ],

    'attributes' => [
        'email' => 'email',
        'password' => 'palavra-passe',
        'inbox_id' => 'inbox',
        'entity_id' => 'entidade',
        'contact_id' => 'contacto',
        'subject' => 'assunto',
        'description' => 'descricao',
        'type' => 'tipo',
        'priority' => 'prioridade',
        'status' => 'estado',
        'assigned_operator_id' => 'operador',
        'cc_emails' => 'conhecimento',
        'body' => 'mensagem',
        'attachments' => 'anexos',
        'attachments.*' => 'anexo',
    ],
];

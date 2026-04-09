@php
    $brandName    = trim((string) ($style['brand_name'] ?? config('app.name', 'Supportdesk')));
    $headerBg     = (string) ($style['header_background'] ?? '#0f766e');
    $accentColor  = (string) ($style['accent_color'] ?? '#0f766e');
    $buttonText   = trim((string) ($style['button_text'] ?? 'Aceder ao ticket'));
    $footerText   = trim((string) ($style['footer_text'] ?? 'Mensagem automática enviada pelo Supportdesk.'));
    $showLink     = array_key_exists('show_ticket_link', $style) ? (bool) $style['show_ticket_link'] : true;
    $ticketUrl    = url('/tickets/'.$ticket->id);
    $brandInitial = mb_strtoupper(mb_substr($brandName !== '' ? $brandName : 'S', 0, 1, 'UTF-8'));

    $statusLabels = [
        'open'        => 'Aberto',
        'in_progress' => 'Em tratamento',
        'pending'     => 'Aguarda cliente',
        'closed'      => 'Fechado',
        'cancelled'   => 'Cancelado',
    ];
    $priorityLabels = [
        'low'    => 'Baixa',
        'medium' => 'Média',
        'high'   => 'Alta',
        'urgent' => 'Urgente',
    ];
    $statusLabel   = $statusLabels[strtolower((string) $ticket->status)]   ?? ucfirst((string) $ticket->status);
    $priorityLabel = $priorityLabels[strtolower((string) $ticket->priority)] ?? ucfirst((string) $ticket->priority);
@endphp
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f1f5f9;color:#0f172a;font-family:'Segoe UI',Arial,sans-serif;line-height:1.5;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;">

    {{-- Cabeçalho da marca --}}
    <tr>
        <td style="background:{{ $headerBg }};padding:18px 24px;border-radius:12px 12px 0 0;">
            <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="vertical-align:middle;">
                        <span style="display:inline-block;width:38px;height:38px;line-height:38px;text-align:center;border-radius:9px;background:rgba(255,255,255,0.22);font-size:17px;font-weight:700;color:#fff;vertical-align:middle;">{{ $brandInitial }}</span>
                    </td>
                    <td style="vertical-align:middle;padding-left:10px;">
                        <span style="font-size:17px;font-weight:700;color:#ffffff;letter-spacing:0.2px;">{{ $brandName !== '' ? $brandName : 'Supportdesk' }}</span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Corpo principal --}}
    <tr>
        <td style="background:#ffffff;padding:28px 24px 8px;border-left:1px solid #dde3ec;border-right:1px solid #dde3ec;">
            <h1 style="margin:0 0 18px;font-size:20px;font-weight:700;color:#0f172a;line-height:1.3;">{{ $title }}</h1>

            {{-- Cartão de metadados do ticket --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                   style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:20px;">
                <tr>
                    <td style="padding:14px 16px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="width:50%;padding-bottom:8px;vertical-align:top;">
                                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Número</span>
                                    <span style="font-size:14px;font-weight:700;color:#0f172a;">{{ $ticket->ticket_number }}</span>
                                </td>
                                <td style="width:50%;padding-bottom:8px;vertical-align:top;">
                                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Estado</span>
                                    <span style="font-size:14px;font-weight:600;color:#0f172a;">{{ $statusLabel }}</span>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" style="padding-bottom:8px;vertical-align:top;">
                                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Assunto</span>
                                    <span style="font-size:14px;color:#0f172a;">{{ $ticket->subject }}</span>
                                </td>
                            </tr>
                            <tr>
                                <td style="vertical-align:top;">
                                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Prioridade</span>
                                    <span style="font-size:14px;color:#0f172a;">{{ $priorityLabel }}</span>
                                </td>
                                @if ($ticket->inbox?->name)
                                <td style="vertical-align:top;">
                                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">Caixa de entrada</span>
                                    <span style="font-size:14px;color:#0f172a;">{{ $ticket->inbox->name }}</span>
                                </td>
                                @endif
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Linhas do template (conteúdo específico do evento) --}}
    @if (count($lines) > 0)
    <tr>
        <td style="background:#ffffff;padding:0 24px 20px;border-left:1px solid #dde3ec;border-right:1px solid #dde3ec;">
            @foreach ($lines as $line)
                <p style="margin:0 0 10px;font-size:14px;color:#334155;line-height:1.6;">{{ $line }}</p>
            @endforeach
        </td>
    </tr>
    @endif

    {{-- Botão de acesso --}}
    @if ($showLink)
    <tr>
        <td style="background:#ffffff;padding:4px 24px 24px;border-left:1px solid #dde3ec;border-right:1px solid #dde3ec;">
            <a href="{{ $ticketUrl }}"
               style="display:inline-block;background:{{ $accentColor }};color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:8px;font-size:14px;font-weight:600;letter-spacing:0.2px;">
                {{ $buttonText !== '' ? $buttonText : 'Aceder ao ticket' }}
            </a>
        </td>
    </tr>
    @endif

    {{-- Rodapé --}}
    <tr>
        <td style="background:#f8fafc;padding:14px 24px 16px;border:1px solid #dde3ec;border-top:1px solid #e2e8f0;border-radius:0 0 12px 12px;">
            <p style="margin:0 0 4px;font-size:12px;color:#94a3b8;">{{ $footerText !== '' ? $footerText : 'Mensagem automática enviada pelo Supportdesk.' }}</p>
            <p style="margin:0;font-size:11px;color:#cbd5e1;word-break:break-all;">{{ $ticketUrl }}</p>
        </td>
    </tr>

</table>

</body>
</html>

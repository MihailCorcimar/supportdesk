@php
    $brandName = trim((string) ($style['brand_name'] ?? config('app.name', 'Supportdesk')));
    $headerBackground = (string) ($style['header_background'] ?? '#1F4E79');
    $accentColor = (string) ($style['accent_color'] ?? '#1F4E79');
    $buttonText = trim((string) ($style['button_text'] ?? 'Aceder ao ticket'));
    $footerText = trim((string) ($style['footer_text'] ?? 'Mensagem automatica enviada pelo Supportdesk.'));
    $showTicketLink = array_key_exists('show_ticket_link', $style) ? (bool) $style['show_ticket_link'] : true;
    $ticketUrl = url('/tickets/'.$ticket->id);
@endphp
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:24px;background:#f1f5f9;color:#0f172a;font-family:'Segoe UI',Arial,sans-serif;line-height:1.45;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #dbe4ee;border-radius:14px;overflow:hidden;">
        <tr>
            <td style="padding:0;background:{{ $headerBackground }};color:#ffffff;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="padding:16px 20px;vertical-align:middle;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;background:rgba(255,255,255,0.2);font-weight:700;letter-spacing:0.4px;">SD</span>
                            <span style="margin-left:10px;font-size:18px;font-weight:700;vertical-align:middle;">{{ $brandName !== '' ? $brandName : 'Supportdesk' }}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        <tr>
            <td style="padding:20px 22px 8px;">
                <h1 style="margin:0 0 10px;font-size:22px;color:#0f172a;">{{ $title }}</h1>
                <p style="margin:0;color:#334155;">
                    Ticket: <strong>{{ $ticket->ticket_number }}</strong><br>
                    Assunto: <strong>{{ $ticket->subject }}</strong>
                </p>
            </td>
        </tr>

        <tr>
            <td style="padding:8px 22px 10px;">
                @foreach ($lines as $line)
                    <p style="margin:0 0 12px;color:#334155;">{{ $line }}</p>
                @endforeach
            </td>
        </tr>

        @if ($showTicketLink)
            <tr>
                <td style="padding:6px 22px 16px;">
                    <a href="{{ $ticketUrl }}"
                       style="display:inline-block;background:{{ $accentColor }};color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:10px;font-weight:600;">
                        {{ $buttonText !== '' ? $buttonText : 'Aceder ao ticket' }}
                    </a>
                </td>
            </tr>
        @endif

        <tr>
            <td style="padding:14px 22px 20px;border-top:1px solid #e2e8f0;">
                <p style="margin:0 0 6px;font-size:12px;color:#64748b;">{{ $footerText !== '' ? $footerText : 'Mensagem automatica enviada pelo Supportdesk.' }}</p>
                <p style="margin:0;font-size:12px;color:#64748b;">{{ $ticketUrl }}</p>
            </td>
        </tr>
    </table>
</body>
</html>

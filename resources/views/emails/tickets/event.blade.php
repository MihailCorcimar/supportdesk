<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
</head>
<body style="font-family:Segoe UI,Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:24px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;">
        <tr>
            <td style="padding:18px 18px 8px;">
                <h1 style="margin:0 0 10px;font-size:19px;">{{ $title }}</h1>
                <p style="margin:0;color:#334155;">
                    Ticket: <strong>{{ $ticket->ticket_number }}</strong><br>
                    Assunto: <strong>{{ $ticket->subject }}</strong>
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding:8px 18px 18px;">
                @foreach ($lines as $line)
                    <p style="margin:0 0 10px;color:#334155;">{{ $line }}</p>
                @endforeach

                <p style="margin:16px 0 0;color:#0f766e;">
                    Aceder ao ticket: <a href="{{ url('/tickets/'.$ticket->id) }}">{{ url('/tickets/'.$ticket->id) }}</a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $payload->subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
{{-- Preheader invisible: texto corto que muchos clientes muestran al lado del asunto --}}
@if(!empty($payload->preheader))
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
        {{ $payload->preheader }}
    </div>
@endif
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f4f4f5;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding:0;">
                            @include('emails.commerciocity.partials.header')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px 24px 28px;">
                            @include('emails.commerciocity.partials.body')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 28px 32px 28px;">
                            @include('emails.commerciocity.partials.footer')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

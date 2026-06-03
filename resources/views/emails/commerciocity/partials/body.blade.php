@php
    // Color de acento reutilizado en enlaces y detalles
    $accent = config('commerciocity.header_background', '#0ea5e9');
    // Flag para saber si se renderiza el bloque superior tipo "tarjeta de presentación"
    $showPresentationCard = method_exists($payload, 'has_presentation_card') && $payload->has_presentation_card();
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    @if($showPresentationCard)
    {{-- Bloque tarjeta de presentación: avatar, nombre/rol, hero, video, CTA --}}
    <tr>
        <td style="padding-bottom:8px;">
            @include('emails.commerciocity.partials.presentation_card')
        </td>
    </tr>
    @endif

    <tr>
        <td style="padding-bottom:8px;">
            <h1 style="margin:0;font-size:22px;line-height:1.35;font-weight:700;color:#111827;">{{ $payload->title }}</h1>
        </td>
    </tr>

    @foreach($payload->paragraphs as $paragraph)
    <tr>
        <td style="padding-top:16px;">
            <p style="margin:0;font-size:15px;line-height:1.6;color:#374151;">{{ $paragraph }}</p>
        </td>
    </tr>
    @endforeach

    @if(count($payload->detail_lines) > 0)
    <tr>
        <td style="padding-top:20px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                @foreach($payload->detail_lines as $line)
                @php
                    // Cada fila expone label/value y un flag opcional para negrita del label
                    $label = $line['label'] ?? '';
                    $value = $line['value'] ?? '';
                    $boldLabel = array_key_exists('bold_label', $line) ? (bool) $line['bold_label'] : true;
                @endphp
                <tr>
                    <td style="padding:6px 0;font-size:15px;line-height:1.55;color:#374151;">
                        <span style="color:#9ca3af;">- </span>
                        @if($boldLabel)
                            <strong style="color:#111827;">{{ $label }}:</strong>
                        @else
                            <span style="color:#111827;">{{ $label }}:</span>
                        @endif
                        {{ $value }}
                    </td>
                </tr>
                @endforeach
            </table>
        </td>
    </tr>
    @endif

    @if(count($payload->links) > 0)
    <tr>
        <td style="padding-top:24px;" align="center">
            @foreach($payload->links as $link)
                @php
                    // Cada link se normaliza como botón para mantener coherencia visual con el CTA principal.
                    $text = $link['text'] ?? '';
                    $url = $link['url'] ?? '#';
                @endphp
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 10px auto;">
                    <tr>
                        <td align="center" style="border-radius:8px;background-color:{{ $accent }};">
                            <a href="{{ $url }}"
                               style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;border-radius:8px;background-color:{{ $accent }};">
                                {{ $text }}
                            </a>
                        </td>
                    </tr>
                </table>
            @endforeach
        </td>
    </tr>
    @endif

    @if(!empty($payload->cta))
    {{-- Botón principal (CTA): se renderiza con tabla para compatibilidad con Outlook --}}
    <tr>
        <td style="padding-top:28px;" align="center">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">
                <tr>
                    <td align="center" style="border-radius:8px;background-color:{{ $accent }};">
                        <a href="{{ $payload->cta['url'] }}"
                           style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;border-radius:8px;background-color:{{ $accent }};">
                            {{ $payload->cta['text'] }}
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @endif

    @if(!empty($payload->closing))
    <tr>
        <td style="padding-top:28px;">
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0 0 20px 0;" />
            <p align="center" style="margin:0;font-size:15px;line-height:1.6;color:#374151;">{{ $payload->closing }}</p>
        </td>
    </tr>
    @endif
</table>

@php
    // Header: banda superior con color de marca y logo o texto de marca
    $brand = config('commerciocity.brand_name', 'ComercioCity');
    $headerBg = config('commerciocity.header_background', '#0068D4');
    $logoUrl = config('commerciocity.logo_url');
    // Medidas del logo, de config y no fijas acá: el isotipo de ComercioCity es CUADRADO y con las
    // medidas apaisadas que estaban escritas a mano (120x55) se deformaba. Salen de config para que
    // cambiar el logo por uno de otra proporción no obligue a tocar este partial, que lo comparten
    // todos los mails del sistema.
    $logoWidth = (int) config('commerciocity.logo_width', 72);
    $logoHeight = (int) config('commerciocity.logo_height', 72);
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:linear-gradient(180deg,{{ $headerBg }} 0%,{{ $headerBg }} 100%);background-color:{{ $headerBg }};">
    <tr>
        <td align="center" style="padding:28px 20px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">
                <tr>
                    @if(!empty($logoUrl))
                    {{-- Sin padding lateral: la celda es la única de una tabla centrada, así que un
                         padding a un solo lado corría el logo del centro. --}}
                    <td valign="middle">
                        <img src="{{ $logoUrl }}" alt="{{ $brand }}" width="{{ $logoWidth }}" height="{{ $logoHeight }}" style="display:block;width:{{ $logoWidth }}px;height:{{ $logoHeight }}px;border:0;border-radius:8px;" />
                    </td>
                    @else
                    <td valign="middle">
                        <span style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.02em;">{{ $brand }}</span>
                    </td>
                    @endif
                </tr>
            </table>
        </td>
    </tr>
</table>

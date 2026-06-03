@php
    // Header: banda superior con color de marca y logo o texto de marca
    $brand = config('commerciocity.brand_name', 'ComercioCity');
    $headerBg = config('commerciocity.header_background', '#0068D4');
    $logoUrl = config('commerciocity.logo_url');
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:linear-gradient(180deg,{{ $headerBg }} 0%,{{ $headerBg }} 100%);background-color:{{ $headerBg }};">
    <tr>
        <td align="center" style="padding:28px 20px;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">
                <tr>
                    @if(!empty($logoUrl))
                    <td valign="middle" style="padding-right:12px;">
                        <img src="{{ $logoUrl }}" alt="{{ $brand }}" width="120" height="55" style="display:block;width:120px;height:55px;border:0;border-radius:8px;" />
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

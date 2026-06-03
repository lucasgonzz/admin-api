@php
    // Footer: línea con íconos clickeables y texto legal opcional
    $legal = config('commerciocity.footer_legal_html');
    $footerLinks = isset($payload->footer_links) && is_array($payload->footer_links) ? $payload->footer_links : [];
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td align="center" style="padding-top:24px;border-top:1px solid #e5e7eb;">
            @if(count($footerLinks) > 0)
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto 16px auto;">
                <tr>
                    @foreach($footerLinks as $footerLink)
                    @php
                        $imgUrl = $footerLink['img_url'] ?? '';
                        $linkUrl = $footerLink['link_url'] ?? '#';
                    @endphp
                    <td style="padding:0 10px;vertical-align:middle;">
                        <a href="{{ $linkUrl }}" style="text-decoration:none;">
                            <img src="{{ $imgUrl }}" alt="" width="40" height="40" style="display:block;width:40px;height:40px;border:0;border-radius:50%;" />
                        </a>
                    </td>
                    @endforeach
                </tr>
            </table>
            @endif
            @if(!empty($legal))
            <p style="margin:0;font-size:12px;line-height:1.5;color:#9ca3af;">{!! $legal !!}</p>
            @endif
        </td>
    </tr>
</table>

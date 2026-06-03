@php
    // Datos del presentador y video. Cada bloque se renderiza solo si sus campos están presentes.
    $avatarUrl = $payload->avatar_url ?? null;
    $presenterName = $payload->presenter_name ?? null;
    $presenterRole = $payload->presenter_role ?? null;
    $heroImageUrl = $payload->hero_image_url ?? null;
    $videoUrl = $payload->video_url ?? null;
    $videoThumb = $payload->video_thumbnail_url ?? null;
    $videoCaption = $payload->video_caption ?? null;
    $videoSecondaryCta = $payload->video_secondary_cta ?? null;
    // Color de acento reutilizado para detalles visuales (bordes, iconos)
    $accent = config('commerciocity.header_background', '#0068D4');
@endphp

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    {{-- Avatar + nombre + rol --}}
    @if($avatarUrl || $presenterName || $presenterRole)
    <tr>
        <td align="center" style="padding:4px 0 20px 0;">
            @if($avatarUrl)
            <img src="{{ $avatarUrl }}"
                 alt="{{ $presenterName ?? '' }}"
                 width="96" height="96"
                 style="display:block;width:96px;height:96px;border:3px solid #ffffff;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,0.12);margin:0 auto 12px auto;object-fit:cover;" />
            @endif
            @if($presenterName)
            <div style="margin:0;font-size:18px;line-height:1.3;font-weight:700;color:#111827;text-align:center;">
                {{ $presenterName }}
            </div>
            @endif
            @if($presenterRole)
            <div style="margin-top:2px;font-size:13px;line-height:1.4;color:#6b7280;text-align:center;letter-spacing:0.02em;">
                {{ $presenterRole }}
            </div>
            @endif
        </td>
    </tr>
    @endif

    {{-- Imagen hero opcional arriba del video --}}
    @if($heroImageUrl)
    <tr>
        <td align="center" style="padding-bottom:16px;">
            <img src="{{ $heroImageUrl }}" alt=""
                 width="560"
                 style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:12px;" />
        </td>
    </tr>
    @endif

    {{-- Bloque de video: miniatura con overlay de play enlazada al video_url --}}
    @if($videoUrl || $videoThumb)
    <tr>
        <td align="center" style="padding:8px 0 4px 0;">
            @php
                // Fallback: si no hay miniatura explícita usamos un placeholder neutro.
                $thumb = $videoThumb ?: 'https://api.comerciocity.com/public/storage/video-placeholder.png';
                $href = $videoUrl ?: '#';
            @endphp
            <a href="{{ $href }}" style="text-decoration:none;display:inline-block;position:relative;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;border-collapse:separate;">
                    <tr>
                        <td align="center"
                            background="{{ $thumb }}"
                            style="background-image:url('{{ $thumb }}');background-size:cover;background-position:center;background-repeat:no-repeat;width:100%;max-width:560px;border-radius:12px;overflow:hidden;">
                            {{-- Altura simulada con padding-top para que funcione en todos los clientes --}}
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:rgba(17,24,39,0.25);border-radius:12px;">
                                <tr>
                                    <td align="center" valign="middle" style="padding:70px 20px;">
                                        {{-- Círculo de play --}}
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">
                                            <tr>
                                                <td align="center" valign="middle"
                                                    style="width:64px;height:64px;background-color:#ffffff;border-radius:50%;box-shadow:0 4px 14px rgba(0,0,0,0.25);">
                                                    <span style="display:inline-block;font-size:0;line-height:0;">
                                                        {{-- Triángulo de play con caracteres Unicode para compatibilidad amplia --}}
                                                        <span style="font-size:26px;line-height:1;color:{{ $accent }};font-family:Arial,sans-serif;">&#9654;</span>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </a>
        </td>
    </tr>
    @if($videoCaption)
    <tr>
        <td align="center" style="padding:8px 0 4px 0;">
            <p style="margin:0;font-size:13px;line-height:1.5;color:#6b7280;">{{ $videoCaption }}</p>
        </td>
    </tr>
    @endif
    @if(!empty($videoSecondaryCta))
    {{-- Botón secundario debajo del caption del video (misma estética que CTA principal). --}}
    <tr>
        <td align="center" style="padding:12px 0 4px 0;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">
                <tr>
                    <td align="center" style="border-radius:8px;background-color:{{ $accent }};">
                        <a href="{{ $videoSecondaryCta['url'] }}"
                           style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;line-height:1;color:#ffffff;text-decoration:none;border-radius:8px;background-color:{{ $accent }};">
                            {{ $videoSecondaryCta['text'] }}
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @endif
    @endif

    {{-- Separador visual antes del título y párrafos del mail --}}
    <tr>
        <td style="padding:16px 0 0 0;">
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:0;" />
        </td>
    </tr>
</table>

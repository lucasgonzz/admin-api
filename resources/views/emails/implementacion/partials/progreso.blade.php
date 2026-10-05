{{--
  La línea de progreso de la implementación: el rótulo con "Etapa N de 8", la barra y las ocho etapas
  con su estado REAL al momento de mandar el mail.

  Variable: $progreso, armado por `ImplementacionMailHelper::progreso()`:
    etapa_actual, porcentaje, barra y etapas (numero, nombre, estado, subpasos).
  Estado de cada etapa: completada (círculo azul con tilde), en_curso (círculo con el punto de
  degradé y la etiqueta "En curso"), pendiente (anillo gris) y salteada (gris con guion y "no aplica").
  Los subpasos, si los hay, son los de la etapa 4 y salen debajo de su nombre.

  Hereda $font de `hito.blade.php`.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#f8f9fc;border-radius:16px;">
  <tr>
    <td style="padding:24px 24px 6px 24px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td style="font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">Tu implementación</td>
          <td align="right" style="font-family:{!! $font !!};font-size:13px;line-height:16px;font-weight:600;color:#1c2333;">Etapa {{ $progreso['etapa_actual'] }} de 8</td>
        </tr>
      </table>
      {{-- La barra: lo hecho en degradé y el resto en gris. Con todo hecho, la parte gris no existe --}}
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 0 0;">
        <tr>
          <td width="{{ $progreso['barra'] }}%" style="height:6px;line-height:6px;font-size:0;background-color:#0b84f8;background-image:linear-gradient(90deg, #0b84f8 0%, #3a31fc 100%);border-radius:{{ $progreso['barra'] >= 100 ? '6px' : '6px 0 0 6px' }};">&nbsp;</td>
          @if($progreso['barra'] < 100)
          <td style="height:6px;line-height:6px;font-size:0;background-color:#e4e7f0;border-radius:0 6px 6px 0;">&nbsp;</td>
          @endif
        </tr>
      </table>
    </td>
  </tr>
  <tr>
    <td style="padding:8px 24px 18px 24px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        @foreach($progreso['etapas'] as $etapa)
        <tr>
          {{-- El indicador de la etapa --}}
          <td width="24" valign="top" style="width:24px;padding:9px 0 9px 0;">
            @if($etapa['estado'] === 'completada')
              <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="24" height="24" align="center" valign="middle" style="width:24px;height:24px;border-radius:12px;background-color:#0b84f8;color:#ffffff;font-family:{!! $font !!};font-size:13px;line-height:24px;font-weight:700;">&#10003;</td></tr></table>
            @elseif($etapa['estado'] === 'en_curso')
              <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="24" height="24" align="center" valign="middle" style="width:24px;height:24px;border-radius:12px;background-color:#e6f0fe;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td width="10" height="10" style="width:10px;height:10px;border-radius:5px;background-color:#0b84f8;background-image:linear-gradient(135deg, #0b84f8 0%, #3a31fc 100%);font-size:0;line-height:0;">&nbsp;</td></tr></table></td></tr></table>
            @elseif($etapa['estado'] === 'salteada')
              <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="24" height="24" align="center" valign="middle" style="width:24px;height:24px;border-radius:12px;background-color:#eceef4;color:#b0b7c6;font-family:{!! $font !!};font-size:14px;line-height:24px;">&ndash;</td></tr></table>
            @else
              <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td width="20" height="20" style="width:20px;height:20px;border-radius:12px;border:2px solid #d9dde8;font-size:0;line-height:0;">&nbsp;</td></tr></table>
            @endif
          </td>
          {{-- El nombre de la etapa, con su etiqueta y, en la 4, los subpasos --}}
          <td valign="top" style="padding:10px 0 9px 14px;font-family:{!! $font !!};font-size:15px;line-height:22px;">
            @if($etapa['estado'] === 'completada')
              <span style="color:#1c2333;">{{ $etapa['nombre'] }}</span>
            @elseif($etapa['estado'] === 'en_curso')
              <span style="color:#1c2333;font-weight:600;">{{ $etapa['nombre'] }}</span>&nbsp;&nbsp;<span style="display:inline-block;padding:2px 8px;border-radius:999px;background-color:#eef0ff;color:#3a31fc;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;vertical-align:1px;">En curso</span>
            @elseif($etapa['estado'] === 'salteada')
              <span style="color:#b0b7c6;">{{ $etapa['nombre'] }} &middot; no aplica</span>
            @else
              <span style="color:#8a93a6;">{{ $etapa['nombre'] }}</span>
            @endif
            @if(count($etapa['subpasos']) > 0)
              <div style="margin:6px 0 0 0;font-family:{!! $font !!};font-size:13px;line-height:20px;">
                @foreach($etapa['subpasos'] as $subpaso)
                  @if(! $loop->first) &nbsp; @endif
                  @if($subpaso['estado'] === 'hecho')
                    <span style="white-space:nowrap;color:#0b84f8;font-weight:600;">&#10003;&nbsp;{{ $subpaso['texto'] }}</span>
                  @elseif($subpaso['estado'] === 'en_curso')
                    <span style="white-space:nowrap;color:#3a31fc;font-weight:600;">&#9679;&nbsp;{{ $subpaso['texto'] }}</span>
                  @else
                    <span style="white-space:nowrap;color:#8a93a6;">&#9675;&nbsp;{{ $subpaso['texto'] }}</span>
                  @endif
                @endforeach
              </div>
            @endif
          </td>
        </tr>
        @endforeach
      </table>
    </td>
  </tr>
</table>

{{--
  Hito "listo": el sistema está listo para operar.

  Lleva el resumen de lo que quedó cargado (solo los rubros que vinieron con un número mayor que
  cero), el botón para entrar al sistema, el botón secundario con los tutoriales, lo que sigue
  (videollamada, ARCA y soporte) y el agradecimiento.

  Variables de este hito: $resumen (numero y leyenda de cada rubro) y $recursos_url. Hereda de
  `hito.blade.php`: $font y $url_sistema.
--}}
@php
    // Lo que sigue después de que el sistema queda listo. Constantes de este mismo archivo, salvo la línea de ARCA: va solo si
    // aplica ($con_arca; `datos.arca: false` la saca). A un cliente que no factura electrónicamente no se le dice que se la conectamos.
    $lo_que_sigue = array_values(array_filter([
        'Una videollamada con vos y tu equipo para resolver dudas y dejarlos operando.',
        $con_arca ? 'Conectamos la facturación electrónica con ARCA.' : null,
        'Soporte por WhatsApp, siempre con una persona del otro lado.',
    ]));
@endphp
@if(count($resumen) > 0)
<tr>
  <td class="cc-lado" style="padding:36px 48px 0 48px;">
    <p style="margin:0 0 14px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">Lo que quedó cargado</p>
    {{-- Las cifras de a dos por fila; en teléfono se apilan solas --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0 0;">
      @foreach(array_chunk($resumen, 2) as $fila)
      <tr>
        @foreach($fila as $rubro)
        <td width="50%" valign="top" style="width:50%;padding:16px 0;">
          <p style="margin:0;font-family:{!! $font !!};font-size:30px;line-height:34px;font-weight:700;letter-spacing:-0.02em;color:#1c2333;">{{ $rubro['numero'] }}</p>
          <p style="margin:4px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:20px;color:#566078;">{{ $rubro['leyenda'] }}</p>
        </td>
        @endforeach
      </tr>
      @endforeach
    </table>
  </td>
</tr>
@endif
<tr>
  <td class="cc-lado" style="padding:28px 48px 0 48px;">
    @if($url_sistema !== '')
      @include('emails.implementacion.partials.boton', ['texto' => 'Entrar a mi sistema', 'url' => $url_sistema])
    @endif
    {{-- El botón secundario: el centro de recursos con los tutoriales --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="cc-boton-tabla" style="margin:12px 0 0 0;">
      <tr>
        <td style="border-radius:12px;background-color:#ffffff;border:1px solid #0b84f8;">
          <a href="{{ $recursos_url }}" target="_blank" rel="noopener noreferrer" class="cc-boton"
             style="display:inline-block;padding:14px 30px;font-family:{!! $font !!};font-size:16px;line-height:20px;font-weight:600;color:#0b84f8;text-decoration:none;border-radius:12px;">Ver los tutoriales</a>
        </td>
      </tr>
    </table>
  </td>
</tr>
<tr>
  <td class="cc-lado" style="padding:36px 48px 0 48px;">
    <p style="margin:0 0 14px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">Lo que sigue</p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      @foreach($lo_que_sigue as $paso)
      <tr>
        <td width="18" valign="top" style="width:18px;padding:7px 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#0b84f8;">&#8226;</td>
        <td style="padding:7px 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#566078;">{{ $paso }}</td>
      </tr>
      @endforeach
    </table>
  </td>
</tr>
<tr>
  <td class="cc-lado" style="padding:24px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#1c2333;">Gracias por elegirnos para acompañar a tu negocio.</p>
  </td>
</tr>

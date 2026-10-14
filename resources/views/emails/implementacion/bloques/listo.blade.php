{{--
  Hito "listo": la entrega. El sistema del cliente, con sus artículos y sus fotos ya cargados.

  Desde el orden nuevo de `/implementar` (Lucas, 14/10/2026) es la PRIMERA vez que el cliente escucha
  que su sistema está instalado, y sale con la primera tanda de artículos: puede que todavía le queden
  artículos por mandar y que ARCA siga pendiente.

  Lleva el resumen de lo que quedó cargado (solo los rubros que vinieron con un número mayor que
  cero), la tarjeta para entrar (la de `partials/tarjeta_de_acceso`: dirección, usuario = documento y
  contraseña = la del WhatsApp; el mail NUNCA lleva la contraseña), el botón para entrar al sistema, el
  botón secundario con los tutoriales (el centro de recursos sale recién acá), lo que sigue (terminar
  el catálogo, ARCA y soporte) y el agradecimiento.

  🔴 La videollamada no se nombra: es la regla de Lucas del 14/10/2026 (solo si el cliente la pide, y
  eso no es un mail). Antes era el primer renglón de "lo que sigue".

  Variables de este hito: $resumen (numero y leyenda de cada rubro), $recursos_url y $con_arca. Hereda
  de `hito.blade.php`: $font, $url_sistema y $direccion_sistema.
--}}
@php
    // Lo que sigue después de la entrega. Constantes de este mismo archivo, salvo la línea de ARCA: va solo si aplica
    // ($con_arca; `datos.arca: false` la saca). A un cliente que no factura electrónicamente no se le dice que se la conectamos.
    // La primera vale tanto si le quedan artículos por mandar como si no ("las que falten"), y tanto si sus artículos ya traían
    // categorías como si no.
    $lo_que_sigue = array_values(array_filter([
        'Cuando tengamos todos tus artículos, terminamos tu catálogo: las categorías, las fotos y las descripciones que falten.',
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
@if($url_sistema !== '')
<tr>
  <td class="cc-lado" style="padding:{{ count($resumen) > 0 ? '28' : '36' }}px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">Cómo entrar</p>
    {{-- La tarjeta de accesos: dirección, usuario y contraseña. En teléfono cada par se apila --}}
    @include('emails.implementacion.partials.tarjeta_de_acceso', ['margen' => 14])
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
    @if($url_sistema !== '')
      <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:22px;color:#566078;">Cuando entres, cambiá la contraseña desde la configuración.</p>
    @endif
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

{{--
  Hito "imagenes": cuántos artículos ya tienen foto y cuántas esperan el visto bueno del cliente.

  Lleva la cifra "X de Y", la barra con el porcentaje, el aviso de las fotos por revisar (solo si hay
  alguna) y el botón "Revisar las fotos", que abre la pantalla de alertas de imágenes del sistema
  del cliente. Si no hay nada por revisar, el botón no sale: mandar al cliente a una pantalla vacía
  no tiene sentido. Si el dato no vino, no se sabe y el botón queda.

  Variables de este hito: $con_foto_texto, $total_texto, $fotos_leyenda, $porcentaje, $aviso_titulo,
  $aviso_detalle, $mostrar_boton, $url_fotos y $fuentes. Hereda de `hito.blade.php`: $font.
--}}
<tr>
  <td class="cc-lado" style="padding:36px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:44px;line-height:48px;font-weight:700;letter-spacing:-0.03em;color:#1c2333;">{{ $con_foto_texto }} de {{ $total_texto }}</p>
    <p style="margin:6px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#566078;">{{ $fotos_leyenda }}</p>
    {{-- La barra del porcentaje. Con el 100 %, la parte gris no existe --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0 0;">
      <tr>
        <td width="{{ max(min($porcentaje, 100), 2) }}%" style="height:8px;line-height:8px;font-size:0;background-color:#0b84f8;background-image:linear-gradient(90deg, #0b84f8 0%, #3a31fc 100%);border-radius:{{ $porcentaje >= 100 ? '8px' : '8px 0 0 8px' }};">&nbsp;</td>
        @if($porcentaje < 100)
        <td style="height:8px;line-height:8px;font-size:0;background-color:#e4e7f0;border-radius:0 8px 8px 0;">&nbsp;</td>
        @endif
      </tr>
    </table>
    <p style="margin:10px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:20px;color:#566078;">El {{ $porcentaje }}&nbsp;% de tu catálogo ya tiene foto.</p>
    @if($fuentes !== '')
      <p style="margin:4px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:20px;color:#566078;word-break:break-word;">Fuentes: {{ $fuentes }}</p>
    @endif
  </td>
</tr>
@if($aviso_titulo !== '')
<tr>
  <td class="cc-lado" style="padding:24px 48px 0 48px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 0 0;">
      <tr>
        <td style="padding:16px 18px;background-color:#eef5ff;border-radius:12px;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#1c2333;"><strong style="font-weight:600;">{{ $aviso_titulo }}</strong> {{ $aviso_detalle }}</td>
      </tr>
    </table>
  </td>
</tr>
@endif
@if($mostrar_boton && $url_fotos !== '')
<tr>
  <td class="cc-lado" style="padding:28px 48px 0 48px;">
    @include('emails.implementacion.partials.boton', ['texto' => 'Revisar las fotos', 'url' => $url_fotos])
    <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:22px;color:#566078;">Las que apruebes quedan en tu sistema, y en tu tienda online si tenés una.</p>
  </td>
</tr>
@endif

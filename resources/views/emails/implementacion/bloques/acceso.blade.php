{{--
  Hito "acceso": la primera tanda de artículos ya está cargada y el cliente puede entrar a su sistema.

  Lleva la cifra de artículos cargados, la tarjeta con la dirección de su sistema (y cómo entrar) y el
  botón. La dirección y el botón salen de la dirección del sistema del cliente ($url_sistema); sin
  ella no se muestran y la previa lo avisa en `faltan`.

  Usuario y contraseña son texto fijo a propósito: el mail NUNCA lleva la contraseña. El usuario es el
  número de documento y la contraseña se la mandó Lucas por WhatsApp. La tarjeta vive en
  `partials/tarjeta_de_acceso.blade.php`, que comparte con `listo` (la entrega).

  Desde el orden nuevo de `/implementar` (14/10/2026) la skill no lo manda más: la entrega es `listo`,
  que lleva esta misma tarjeta. Queda aceptado por compatibilidad.

  Variables de este hito: $articulos_texto y $articulos_leyenda. Hereda de `hito.blade.php`: $font,
  $url_sistema y $direccion_sistema.
--}}
<tr>
  <td class="cc-lado" style="padding:36px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:44px;line-height:48px;font-weight:700;letter-spacing:-0.03em;color:#1c2333;">{{ $articulos_texto }}</p>
    <p style="margin:6px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#566078;">{{ $articulos_leyenda }}</p>
    @if($url_sistema !== '')
      {{-- La tarjeta de accesos: dirección, usuario y contraseña. En teléfono cada par se apila --}}
      @include('emails.implementacion.partials.tarjeta_de_acceso')
    @endif
  </td>
</tr>
@if($url_sistema !== '')
<tr>
  <td class="cc-lado" style="padding:28px 48px 0 48px;">
    @include('emails.implementacion.partials.boton', ['texto' => 'Entrar a mi sistema', 'url' => $url_sistema])
    <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:22px;color:#566078;">Cuando entres, cambiá la contraseña desde la configuración.</p>
  </td>
</tr>
@endif
<tr>
  <td class="cc-lado" style="padding:28px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#566078;">Mientras tanto seguimos con las fotos de tus productos.</p>
  </td>
</tr>

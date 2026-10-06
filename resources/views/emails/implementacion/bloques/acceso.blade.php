{{--
  Hito "acceso": la primera tanda de artículos ya está cargada y el cliente puede entrar a su sistema.

  Lleva la cifra de artículos cargados, la tarjeta con la dirección de su sistema (y cómo entrar) y el
  botón. La dirección y el botón salen de la dirección del sistema del cliente ($url_sistema); sin
  ella no se muestran y la previa lo avisa en `faltan`.

  Usuario y contraseña son texto fijo a propósito: el mail NUNCA lleva la contraseña. El usuario es el
  número de documento y la contraseña se la mandó Lucas por WhatsApp.

  Variables de este hito: $articulos_texto y $articulos_leyenda. Hereda de `hito.blade.php`: $font,
  $url_sistema y $direccion_sistema.
--}}
<tr>
  <td class="cc-lado" style="padding:36px 48px 0 48px;">
    <p style="margin:0;font-family:{!! $font !!};font-size:44px;line-height:48px;font-weight:700;letter-spacing:-0.03em;color:#1c2333;">{{ $articulos_texto }}</p>
    <p style="margin:6px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#566078;">{{ $articulos_leyenda }}</p>
    @if($url_sistema !== '')
      {{-- La tarjeta de accesos: dirección, usuario y contraseña. En teléfono cada par se apila --}}
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0 0;background-color:#f8f9fc;border-radius:14px;">
        <tr><td style="padding:6px 20px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
              <td width="110" class="cc-par cc-par-etiqueta" style="width:110px;padding:13px 0;font-family:{!! $font !!};font-size:14px;line-height:20px;color:#566078;white-space:nowrap;">Dirección</td>
              <td class="cc-par cc-par-valor" style="padding:13px 0;font-family:{!! $font !!};font-size:15px;line-height:20px;color:#1c2333;word-break:break-word;"><a href="{{ $url_sistema }}" style="color:#0b84f8;text-decoration:none;font-weight:600;">{{ $direccion_sistema }}</a></td>
            </tr>
            <tr>
              <td width="110" class="cc-par cc-par-etiqueta" style="border-top:1px solid #eceef4;width:110px;padding:13px 0;font-family:{!! $font !!};font-size:14px;line-height:20px;color:#566078;white-space:nowrap;">Usuario</td>
              <td class="cc-par cc-par-valor" style="border-top:1px solid #eceef4;padding:13px 0;font-family:{!! $font !!};font-size:15px;line-height:20px;color:#1c2333;word-break:break-word;">Tu número de documento</td>
            </tr>
            <tr>
              <td width="110" class="cc-par cc-par-etiqueta" style="border-top:1px solid #eceef4;width:110px;padding:13px 0;font-family:{!! $font !!};font-size:14px;line-height:20px;color:#566078;white-space:nowrap;">Contraseña</td>
              <td class="cc-par cc-par-valor" style="border-top:1px solid #eceef4;padding:13px 0;font-family:{!! $font !!};font-size:15px;line-height:20px;color:#1c2333;word-break:break-word;">La que te mandamos por WhatsApp</td>
            </tr>
          </table>
        </td></tr>
      </table>
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

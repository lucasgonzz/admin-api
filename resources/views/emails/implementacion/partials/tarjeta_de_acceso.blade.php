{{--
  La tarjeta de accesos: dirección, usuario y contraseña. La comparten `acceso` (el hito de antes) y
  `listo` (la entrega, desde el orden nuevo de `/implementar`, 14/10/2026). Es la tabla que tenía
  `bloques/acceso.blade.php`, sin cambios; va adentro de una celda de la tarjeta y quien la incluye
  decide el margen de arriba ($margen, en px; 24 si no se pasa).

  Usuario y contraseña son texto fijo a propósito: el mail NUNCA lleva la contraseña. El usuario es el
  número de documento y la contraseña se la mandamos por WhatsApp.

  Solo tiene sentido con la dirección del sistema: quien la incluye la muestra con $url_sistema no
  vacío. En teléfono cada par se apila (`cc-par`, en el <head> de `hito.blade.php`).

  Hereda de `hito.blade.php`: $font, $url_sistema y $direccion_sistema.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:{{ isset($margen) ? (int) $margen : 24 }}px 0 0 0;background-color:#f8f9fc;border-radius:14px;">
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

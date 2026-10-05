{{--
  El botón principal de los mails de la implementación: el degradé de marca (azul a violeta, 135°).

  Variables: $texto (lo que dice) y $url (adónde lleva). Hereda $font de `hito.blade.php`.

  `background-color` es el respaldo para Outlook, que no dibuja degradés. En teléfono la tabla pasa a
  ancho completo y el link a bloque centrado (clases `cc-boton-tabla` y `cc-boton` del <head>).
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="cc-boton-tabla" style="margin:0;">
  <tr>
    <td style="border-radius:12px;background-color:#0b84f8;background-image:linear-gradient(135deg, #0b84f8 0%, #3a31fc 100%);">
      <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="cc-boton"
         style="display:inline-block;padding:15px 30px;font-family:{!! $font !!};font-size:16px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">{{ $texto }}</a>
    </td>
  </tr>
</table>

{{--
  La tarjeta del mail de cada hito de la implementación (misión implementar-cliente, 5/10/2026).

  Los seis hitos —bienvenida, instalado, acceso, imagenes, categorias, listo— comparten esta tarjeta y
  cambian solo el bloque del medio (`bloques/<hito>.blade.php`, que se incluye acá) y la línea de
  progreso (`partials/progreso.blade.php`), que es la misma en todos pero refleja el estado real de
  las ocho etapas al momento de mandar.

  Es el diseño del prototipo aprobado y comparte lenguaje con la carta de acceso a la demo
  (`emails/lead/demo_acceso.blade.php`): Geist con el fallback de sistema, fondo #f8f9fc, tarjeta
  blanca de 560 px con radio 20, azul #0b84f8, violeta #3a31fc, degradé de 135° solo en el botón
  principal, texto #1c2333 y #566078. Naranja #ff6a3d UNA sola vez, en el acento del titular (regla
  de marca: apenas se repite deja de ser acento).

  Maquetado con tablas y estilos inline para Gmail y Outlook. Los pocos estilos del <head> son solo
  Geist (que Apple Mail carga y el resto ignora sin romper nada) y los ajustes de ancho para
  teléfono. Nada del mail depende de que el <head> sobreviva.

  Qué ve esta vista (lo arma `ImplementacionMailHelper::vista()`): $hito, $asunto, $preheader,
  $titular, $intro, $nota (lista de párrafos), $nombre, $negocio, $logo_url, $firma_nombre,
  $firma_rol, $progreso, $form_link, $url_sistema, $direccion_sistema y lo propio de cada hito.

  Todo lo que viene de datos (nombre, negocio, nota, cifras, opciones) se imprime con {{ }}, escapado.
  {!! !!} se usa SOLO para $font, que es una constante de este mismo archivo.

  Los bloques y los partials que se incluyen heredan $font y las variables de esta vista.
--}}
@php
    // Tipografía única de la marca, con el fallback de sistema. Es una constante de este mismo
    // archivo, por eso va con {!! !!}: {{ }} le escaparía las comillas simples.
    $font = "'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $asunto }}</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap');

  @media only screen and (max-width: 600px) {
    .cc-marco { padding: 20px 10px !important; }
    .cc-lado { padding-left: 24px !important; padding-right: 24px !important; }
    .cc-titular { font-size: 27px !important; line-height: 33px !important; }
    .cc-boton-tabla { width: 100% !important; }
    .cc-boton { display: block !important; text-align: center !important; }
    .cc-par { display: block !important; width: 100% !important; }
    .cc-par-etiqueta { padding-bottom: 0 !important; }
    .cc-par-valor { border-top: 0 !important; padding-top: 2px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f8f9fc;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

{{-- Preheader: la línea que el cliente de mail muestra al lado del asunto. No se ve en el cuerpo. --}}
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#f8f9fc;mso-hide:all;">
  {{ $preheader }}
  &zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f8f9fc;">
  <tr>
    <td align="center" class="cc-marco" style="padding:48px 16px;">

      {{-- La tarjeta: blanca, centrada, 560px, bordes generosos, sin sombra pesada --}}
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background-color:#ffffff;border-radius:20px;">

        {{-- Header: el isotipo, chico y centrado sobre blanco; sin logo cargado, el nombre en texto --}}
        <tr>
          <td align="center" class="cc-lado" style="padding:40px 48px 0 48px;">
            @if($logo_url !== '')
              <img src="{{ $logo_url }}" alt="ComercioCity" width="56" height="56" style="display:block;width:56px;height:56px;border:0;">
            @else
              <span style="font-family:{!! $font !!};font-size:15px;line-height:20px;font-weight:600;letter-spacing:0.02em;color:#1c2333;">ComercioCity</span>
            @endif
          </td>
        </tr>

        {{-- Rótulo con el negocio, saludo, titular del hito, acento naranja, introducción y nota --}}
        <tr>
          <td class="cc-lado" style="padding:32px 48px 0 48px;">
            <p style="margin:0 0 14px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#0b84f8;word-break:break-word;">Implementación{{ $negocio !== '' ? ' · ' . $negocio : '' }}</p>
            <p style="margin:0 0 10px 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#566078;word-break:break-word;">Hola{{ $nombre !== '' ? ', ' . $nombre : '' }}.</p>
            <h1 class="cc-titular" style="margin:0;font-family:{!! $font !!};font-size:32px;line-height:38px;font-weight:700;letter-spacing:-0.02em;color:#1c2333;">{{ $titular }}</h1>
            {{-- El único acento naranja de todo el mail --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0 0;">
              <tr><td style="width:36px;height:3px;line-height:3px;font-size:3px;background-color:#ff6a3d;border-radius:3px;">&nbsp;</td></tr>
            </table>
            <p style="margin:22px 0 0 0;font-family:{!! $font !!};font-size:16px;line-height:26px;color:#566078;">{{ $intro }}</p>
            {{-- La nota personal, si hay: un párrafo por cada salto de línea, escapado --}}
            @foreach($nota as $parrafo)
              <p style="margin:16px 0 0 0;font-family:{!! $font !!};font-size:16px;line-height:26px;color:#566078;word-break:break-word;">{{ $parrafo }}</p>
            @endforeach
          </td>
        </tr>

        {{-- El bloque propio del hito: una o más filas de la tarjeta --}}
        @include('emails.implementacion.bloques.' . $hito)

        {{-- La línea de progreso: las ocho etapas con su estado real --}}
        <tr>
          <td class="cc-lado" style="padding:40px 48px 0 48px;">
            @include('emails.implementacion.partials.progreso')
          </td>
        </tr>

        {{-- Separador fino --}}
        <tr>
          <td class="cc-lado" style="padding:36px 48px 0 48px;">
            <div style="height:1px;line-height:1px;font-size:1px;background-color:#eceef4;">&nbsp;</div>
          </td>
        </tr>

        {{-- Cierre y firma --}}
        <tr>
          <td class="cc-lado" style="padding:24px 48px 40px 48px;">
            <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#566078;">Cualquier duda, escribinos por WhatsApp, en el mismo chat donde venimos hablando.</p>
            <p style="margin:20px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:22px;font-weight:600;color:#1c2333;">{{ $firma_nombre }}</p>
            <p style="margin:2px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:20px;color:#566078;">{{ $firma_rol }}</p>
          </td>
        </tr>

      </table>
      {{-- Fin de la tarjeta --}}

      {{-- Nota al pie, afuera de la tarjeta: por qué le llega este mail --}}
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;">
        <tr>
          <td align="center" style="padding:20px 24px 0 24px;font-family:{!! $font !!};font-size:12px;line-height:18px;color:#8a93a6;word-break:break-word;">
            Te escribimos porque estamos implementando ComercioCity en {{ $negocio !== '' ? $negocio : 'tu negocio' }}.<br>
            <a href="https://comerciocity.com" target="_blank" rel="noopener noreferrer" style="color:#8a93a6;text-decoration:underline;">comerciocity.com</a>
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>

</body>
</html>

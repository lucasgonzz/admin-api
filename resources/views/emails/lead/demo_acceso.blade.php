{{--
  La "carta de acceso" a la demo (misión demo-agendado-directo). Dos llaves: la página de
  experiencia del sistema de gestión y la tienda online conectada a esa misma instancia.

  Identidad visual copiada de la página de experiencia (admin-spa/src/assets/scss/demo-experiencia.scss):
  Geist, azul ancla #0b84f8, violeta #3a31fc, degradé 135deg azul→violeta, fondo #f8f9fc, texto
  #1c2333 y #566078. Naranja #ff6a3d una sola vez (regla de marca: apenas se repite deja de ser acento).

  Maquetado con tablas y estilos inline, como demo_mail.blade.php, para Gmail y Outlook. Los pocos
  estilos del <head> son solo Geist (que Apple Mail carga y el resto ignora sin romper nada) y el
  ajuste de anchos para teléfono. Nada del mail depende de que el <head> sobreviva.
--}}
@php
    // Tipografía única de la página de experiencia, con el fallback de sistema. Es una constante
    // de este mismo archivo, por eso va con {!! !!}: {{ }} le escaparía las comillas simples.
    $font = "'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>Tus llaves de acceso a ComercioCity</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap');

  @media only screen and (max-width: 600px) {
    .cc-marco { padding: 20px 12px !important; }
    .cc-lado { padding-left: 28px !important; padding-right: 28px !important; }
    .cc-titular { font-size: 27px !important; line-height: 33px !important; }
    .cc-boton-tabla { width: 100% !important; }
    .cc-boton { display: block !important; text-align: center !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f8f9fc;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

{{-- Preheader: la línea que el cliente de mail muestra al lado del asunto. No se ve en el cuerpo. --}}
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#f8f9fc;mso-hide:all;">
  Con estos dos accesos entrás a tu demo desde cualquier computadora.
  &zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f8f9fc;">
  <tr>
    <td align="center" class="cc-marco" style="padding:48px 16px;">

      {{-- La tarjeta: blanca, centrada, 560px, bordes generosos, sin sombra pesada --}}
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background-color:#ffffff;border-radius:20px;">

        {{-- Header: logo chico y centrado sobre blanco; sin logo cargado, el nombre en texto --}}
        <tr>
          <td align="center" class="cc-lado" style="padding:44px 48px 0 48px;">
            @if($logo_url)
              <img src="{{ $logo_url }}" alt="ComercioCity" width="120" style="display:block;width:120px;max-width:120px;height:auto;border:0;">
            @else
              <span style="font-family:{!! $font !!};font-size:15px;line-height:20px;font-weight:600;letter-spacing:0.02em;color:#1c2333;">ComercioCity</span>
            @endif
          </td>
        </tr>

        {{-- Saludo, titular y el párrafo que explica las dos llaves --}}
        <tr>
          <td class="cc-lado" style="padding:40px 48px 0 48px;">
            <p style="margin:0 0 10px 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#566078;">
              Hola{{ $nombre !== '' ? ' ' . $nombre : '' }}.
            </p>
            <h1 class="cc-titular" style="margin:0;font-family:{!! $font !!};font-size:32px;line-height:38px;font-weight:700;letter-spacing:-0.02em;color:#1c2333;">
              Estas son tus llaves.
            </h1>
            {{-- El único acento naranja de todo el mail --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0 0;">
              <tr>
                <td style="width:36px;height:3px;line-height:3px;font-size:3px;background-color:#ff6a3d;border-radius:3px;">&nbsp;</td>
              </tr>
            </table>
            <p style="margin:22px 0 0 0;font-family:{!! $font !!};font-size:16px;line-height:26px;color:#566078;">
              Son dos accesos. El primero abre la demo del sistema de gestión, preparada según tu negocio.
              El segundo abre la tienda online que viene conectada a ese mismo sistema: lo que cargás en uno se ve en la otra.
            </p>
          </td>
        </tr>

        {{-- Llave 1: el sistema de gestión, botón con el degradé de marca --}}
        <tr>
          <td class="cc-lado" style="padding:40px 48px 0 48px;">
            <p style="margin:0 0 14px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">
              Sistema de gestión
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="cc-boton-tabla">
              <tr>
                {{-- background-color es el respaldo para Outlook, que no dibuja degradés --}}
                <td style="border-radius:12px;background-color:#0b84f8;background-image:linear-gradient(135deg, #0b84f8 0%, #3a31fc 100%);">
                  <a href="{{ $url_experiencia }}" target="_blank" rel="noopener noreferrer" class="cc-boton"
                     style="display:inline-block;padding:15px 30px;font-family:{!! $font !!};font-size:16px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">
                    Entrar a la demo
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:22px;color:#566078;">
              Al entrar, mirá el video corto de introducción y después entrá con el botón. No hay usuario ni contraseña.
            </p>
          </td>
        </tr>

        {{-- Llave 2: la tienda online, botón secundario. Solo si la instancia tiene tienda --}}
        @if($url_tienda)
        <tr>
          <td class="cc-lado" style="padding:36px 48px 0 48px;">
            <p style="margin:0 0 14px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">
              Tienda online
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="cc-boton-tabla">
              <tr>
                <td style="border-radius:12px;background-color:#ffffff;border:1px solid #0b84f8;">
                  <a href="{{ $url_tienda }}" target="_blank" rel="noopener noreferrer" class="cc-boton"
                     style="display:inline-block;padding:14px 30px;font-family:{!! $font !!};font-size:16px;line-height:20px;font-weight:600;color:#0b84f8;text-decoration:none;border-radius:12px;">
                    Ver la tienda
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:22px;color:#566078;">
              Es la tienda conectada a esa misma demo.
            </p>
          </td>
        </tr>
        @endif

        {{-- Separador fino --}}
        <tr>
          <td class="cc-lado" style="padding:40px 48px 0 48px;">
            <div style="height:1px;line-height:1px;font-size:1px;background-color:#eceef4;">&nbsp;</div>
          </td>
        </tr>

        {{-- Cierre y firma --}}
        <tr>
          <td class="cc-lado" style="padding:28px 48px 44px 48px;">
            <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#566078;">
              Cualquier duda mientras la recorrés,
              @if($url_whatsapp)
                <a href="{{ $url_whatsapp }}" target="_blank" rel="noopener noreferrer" style="color:#0b84f8;text-decoration:none;font-weight:500;">escribinos por WhatsApp</a>.
              @else
                escribinos por WhatsApp.
              @endif
            </p>
            <p style="margin:24px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:22px;font-weight:600;color:#1c2333;">
              {{ $presenter_name }}
            </p>
            <p style="margin:2px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:20px;color:#566078;">
              {{ $presenter_role }} &middot; ComercioCity
            </p>
          </td>
        </tr>

      </table>
      {{-- Fin de la tarjeta --}}

      {{-- Nota al pie, afuera de la tarjeta: para qué sirve guardar este mail --}}
      <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;">
        <tr>
          <td align="center" style="padding:20px 24px 0 24px;font-family:{!! $font !!};font-size:12px;line-height:18px;color:#8a93a8;">
            Guardá este mail: es tu llave para entrar a la demo desde la computadora.
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>

</body>
</html>

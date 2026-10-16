{{--
  La "carta de acceso" a la demo (misión demo-agendado-directo), rediseñada en la misión
  mail-demo-turno-y-asistente (16/10/2026). Lleva, en este orden:

    1. el TURNO real de la demo (día, hora de inicio y de fin) en una tarjeta con el degradé de marca;
    2. qué es lo que se reservó: una instancia completa (gestión, tienda online, sistema de agentes);
    3. las dos llaves (la página de experiencia y la tienda);
    4. cómo aprovecharla (las recomendaciones de `contexto/demo_pagina.md` §5, más las del recorrido);
    5. el asistente personal por WhatsApp durante el turno — SOLO si el número está listo;
    6. el cierre y la firma.

  Sin turno (un reenvío cuando el turno ya terminó, un lead sin hora cargada) el bloque 1 no aparece y
  el titular vuelve a ser "Estas son tus llaves.": acá no se inventa una hora. Sin tienda no aparece la
  llave 2 ni su fila en el bloque 2. Sin asistente disponible no aparece el bloque 5 ni se lo nombra en
  ninguna otra parte del mail.

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

    // Hay turno cuando el helper pudo armar día y horas legibles; si no, el mail sale "de llaves".
    $hay_turno = is_array($turno) && ! empty($turno['hora_inicio']) && ! empty($turno['hora_fin']);

    // El preheader es la línea que el cliente de mail muestra al lado del asunto.
    $preheader = $hay_turno
        ? 'Tu demo es ' . $turno['cuando_para_el_asunto'] . '. Acá tenés el acceso y cómo aprovecharla.'
        : 'Con estos dos accesos entrás a tu demo desde cualquier computadora.';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $hay_turno ? 'Tu demo de ComercioCity' : 'Tus llaves de acceso a ComercioCity' }}</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap');

  @media only screen and (max-width: 600px) {
    .cc-marco { padding: 20px 12px !important; }
    .cc-lado { padding-left: 24px !important; padding-right: 24px !important; }
    .cc-titular { font-size: 27px !important; line-height: 33px !important; }
    .cc-hora { font-size: 34px !important; line-height: 38px !important; }
    .cc-turno { padding: 24px 22px !important; }
    .cc-boton-tabla { width: 100% !important; }
    .cc-boton { display: block !important; text-align: center !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f8f9fc;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

{{-- Preheader: no se ve en el cuerpo, solo al lado del asunto en la bandeja de entrada. --}}
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#f8f9fc;mso-hide:all;">
  {{ $preheader }}
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

        {{-- Saludo y titular --}}
        <tr>
          <td class="cc-lado" style="padding:40px 48px 0 48px;">
            <p style="margin:0 0 10px 0;font-family:{!! $font !!};font-size:15px;line-height:22px;color:#566078;">
              Hola{{ $nombre !== '' ? ' ' . $nombre : '' }}.
            </p>
            <h1 class="cc-titular" style="margin:0;font-family:{!! $font !!};font-size:32px;line-height:38px;font-weight:700;letter-spacing:-0.02em;color:#1c2333;">
              @if($hay_turno)
                Tu demo está reservada.
              @else
                Estas son tus llaves.
              @endif
            </h1>
            {{-- El único acento naranja de todo el mail --}}
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0 0;">
              <tr>
                <td style="width:36px;height:3px;line-height:3px;font-size:3px;background-color:#ff6a3d;border-radius:3px;">&nbsp;</td>
              </tr>
            </table>
            <p style="margin:22px 0 0 0;font-family:{!! $font !!};font-size:16px;line-height:26px;color:#566078;">
              @if($hay_turno)
                No es un video ni una presentación: es <strong style="color:#1c2333;font-weight:600;">una instancia completa de la plataforma</strong>,
                funcionando de verdad y preparada para vos. Es para que la uses con tus manos.
              @else
                Son dos accesos. El primero abre la demo del sistema de gestión, preparada según tu negocio.
                El segundo abre la tienda online que viene conectada a ese mismo sistema: lo que cargás en uno se ve en la otra.
              @endif
            </p>
          </td>
        </tr>

        {{-- 1. EL TURNO: la tarjeta con el degradé de marca. background-color es el respaldo de Outlook --}}
        @if($hay_turno)
        <tr>
          <td class="cc-lado" style="padding:32px 48px 0 48px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:16px;background-color:#0b84f8;background-image:linear-gradient(135deg, #0b84f8 0%, #3a31fc 100%);">
              <tr>
                <td class="cc-turno" style="padding:28px 30px;">
                  <p style="margin:0 0 6px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#cfe3ff;">
                    Tu turno
                  </p>
                  <p style="margin:0;font-family:{!! $font !!};font-size:16px;line-height:24px;font-weight:500;color:#ffffff;">
                    {{ $turno['es_hoy'] ? 'Hoy' : ucfirst($turno['fecha_larga']) }}@if($turno['es_hoy']), {{ $turno['fecha_larga'] }}@endif
                  </p>
                  <p class="cc-hora" style="margin:6px 0 0 0;font-family:{!! $font !!};font-size:40px;line-height:44px;font-weight:700;letter-spacing:-0.02em;color:#ffffff;">
                    {{ $turno['hora_inicio'] }} <span style="font-weight:500;color:#cfe3ff;">a</span> {{ $turno['hora_fin'] }}
                  </p>
                  <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:22px;color:#e4efff;">
                    @if(! empty($turno['reserva']))
                      La instancia queda reservada para vos {{ $turno['reserva'] }}. La demo en sí dura {{ $turno['duracion'] }}.
                    @else
                      La instancia queda reservada para vos hasta las {{ $turno['hora_fin'] }}. La demo en sí dura {{ $turno['duracion'] }}.
                    @endif
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        {{-- Llave 1: el sistema de gestión, botón con el degradé de marca --}}
        <tr>
          <td class="cc-lado" style="padding:{{ $hay_turno ? '28px' : '40px' }} 48px 0 48px;">
            @if(! $hay_turno)
            <p style="margin:0 0 14px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">
              Sistema de gestión
            </p>
            @endif
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

        {{-- 2. QUÉ SE RESERVÓ: la instancia completa. Va siempre, con o sin turno --}}
        <tr>
          <td class="cc-lado" style="padding:44px 48px 0 48px;">
            <p style="margin:0 0 6px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">
              Qué vas a probar
            </p>
            <h2 style="margin:0;font-family:{!! $font !!};font-size:22px;line-height:28px;font-weight:700;letter-spacing:-0.01em;color:#1c2333;">
              Todo ComercioCity, en una sola instancia.
            </h2>

            {{-- Fila: sistema de gestión --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0 0;">
              <tr>
                <td width="4" style="width:4px;background-color:#0b84f8;border-radius:4px;font-size:1px;line-height:1px;">&nbsp;</td>
                <td style="padding:2px 0 2px 16px;">
                  <p style="margin:0;font-family:{!! $font !!};font-size:16px;line-height:24px;font-weight:600;color:#1c2333;">Sistema de gestión</p>
                  <p style="margin:2px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:23px;color:#566078;">
                    Stock, precios, ventas, facturación y cuentas corrientes, armado según cómo trabajás.
                  </p>
                </td>
              </tr>
            </table>

            {{-- Fila: tienda online. Solo si la instancia tiene tienda --}}
            @if($url_tienda)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0 0;">
              <tr>
                <td width="4" style="width:4px;background-color:#3a31fc;border-radius:4px;font-size:1px;line-height:1px;">&nbsp;</td>
                <td style="padding:2px 0 2px 16px;">
                  <p style="margin:0;font-family:{!! $font !!};font-size:16px;line-height:24px;font-weight:600;color:#1c2333;">Tienda online</p>
                  <p style="margin:2px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:23px;color:#566078;">
                    Conectada a ese mismo sistema: lo que cargás en uno se ve en la otra, sin cargarlo dos veces.
                  </p>
                  {{-- La llave 2: botón secundario, junto a la explicación de lo que abre --}}
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="cc-boton-tabla" style="margin:12px 0 0 0;">
                    <tr>
                      <td style="border-radius:12px;background-color:#ffffff;border:1px solid #0b84f8;">
                        <a href="{{ $url_tienda }}" target="_blank" rel="noopener noreferrer" class="cc-boton"
                           style="display:inline-block;padding:11px 24px;font-family:{!! $font !!};font-size:15px;line-height:20px;font-weight:600;color:#0b84f8;text-decoration:none;border-radius:12px;">
                          Ver la tienda
                        </a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
            @endif

            {{-- Fila: sistema de agentes --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 0 0;">
              <tr>
                <td width="4" style="width:4px;background-color:#8f5cf6;border-radius:4px;font-size:1px;line-height:1px;">&nbsp;</td>
                <td style="padding:2px 0 2px 16px;">
                  <p style="margin:0;font-family:{!! $font !!};font-size:16px;line-height:24px;font-weight:600;color:#1c2333;">Sistema de agentes</p>
                  <p style="margin:2px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:23px;color:#566078;">
                    Un agente de WhatsApp que atiende a tus clientes con los datos reales de tu negocio, y un asistente
                    personal al que le pedís cosas a tu sistema como si hablaras con un encargado.
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        {{-- 3. CÓMO APROVECHARLA --}}
        <tr>
          <td class="cc-lado" style="padding:44px 48px 0 48px;">
            <p style="margin:0 0 6px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#566078;">
              Para que te rinda
            </p>
            <h2 style="margin:0 0 6px 0;font-family:{!! $font !!};font-size:22px;line-height:28px;font-weight:700;letter-spacing:-0.01em;color:#1c2333;">
              Cinco recomendaciones.
            </h2>

            @php
                // Cada recomendación: título corto + una línea. Lo de la computadora y el rato tranquilo
                // es el copy aprobado de contexto/demo_pagina.md §5. Con turno, la reserva sale del turno
                // real; sin turno, solo la duración.
                $recomendaciones = [
                    ['Entrá desde una computadora.', 'Vas a operar el sistema, no mirarlo. Desde el celular podés ver la introducción, pero para probarlo hace falta la compu.'],
                    ['Buscá un rato tranquilo.', $hay_turno
                        ? 'La demo dura ' . $turno['duracion'] . ' y la tenés reservada ' . (! empty($turno['reserva']) ? $turno['reserva'] : 'hasta las ' . $turno['hora_fin']) . '.'
                        : 'Dura menos de una hora, y la tenés reservada un buen rato.'],
                    ['Respondé el formulario corto.', 'Al entrar te hacemos unas preguntas sobre cómo trabajás: con eso se arma la demo a tu medida. Después viene el video de introducción.'],
                    ['Probala pensando en tu negocio.', $url_tienda
                        ? 'Cargá un artículo en el sistema y buscalo en la tienda: es la misma base de datos, se carga una sola vez.'
                        : 'Cargá un artículo, hacé una venta y mirá cómo se mueve el stock: es tu negocio, pero con datos de ficción.'],
                    ['Anotá tus dudas.', 'Cuando termines, te escribimos por WhatsApp para ver cómo se aplicaría a tu negocio.'],
                ];
            @endphp

            @foreach($recomendaciones as $i => $recomendacion)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:{{ $i === 0 ? '20px' : '16px' }} 0 0 0;">
              <tr>
                <td width="34" valign="top" style="width:34px;padding:1px 0 0 0;">
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td align="center" valign="middle" width="26" height="26" style="width:26px;height:26px;border-radius:13px;background-color:#eef4ff;font-family:{!! $font !!};font-size:13px;line-height:26px;font-weight:700;color:#0b84f8;">{{ $i + 1 }}</td>
                    </tr>
                  </table>
                </td>
                <td valign="top" style="padding:0 0 0 4px;">
                  <p style="margin:0;font-family:{!! $font !!};font-size:15px;line-height:24px;font-weight:600;color:#1c2333;">{{ $recomendacion[0] }}</p>
                  <p style="margin:1px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:23px;color:#566078;">{{ $recomendacion[1] }}</p>
                </td>
              </tr>
            </table>
            @endforeach
          </td>
        </tr>

        {{-- 4. EL ASISTENTE PERSONAL. Solo si el número está listo: nunca se promete algo que no funciona --}}
        @if($mostrar_asistente)
        <tr>
          <td class="cc-lado" style="padding:44px 48px 0 48px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-radius:16px;background-color:#f1f6ff;border:1px solid #d8e6ff;">
              <tr>
                <td class="cc-turno" style="padding:28px 30px;">
                  <p style="margin:0 0 6px 0;font-family:{!! $font !!};font-size:12px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#3a31fc;">
                    Asistente personal &middot; durante tu demo
                  </p>
                  <h2 style="margin:0;font-family:{!! $font !!};font-size:22px;line-height:28px;font-weight:700;letter-spacing:-0.01em;color:#1c2333;">
                    Probalo desde tu WhatsApp.
                  </h2>
                  <p style="margin:12px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#566078;">
                    @if($hay_turno)
                      Mientras dure tu turno
                    @else
                      Mientras dure tu demo
                    @endif
                    podés escribirle al asistente de tu demo, igual que lo haría el dueño de un negocio con su sistema.
                    Escribile <strong style="color:#1c2333;font-weight:600;">desde este mismo WhatsApp</strong>, el que usás con nosotros, al
                    <strong style="color:#1c2333;font-weight:600;white-space:nowrap;">{{ $asistente_numero }}</strong>.
                  </p>
                  <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#566078;">
                    Hacele preguntas y pedidos sobre los datos de tu demo, por ejemplo:
                  </p>
                  <ul style="margin:8px 0 0 0;padding:0 0 0 20px;font-family:{!! $font !!};font-size:15px;line-height:24px;color:#1c2333;">
                    <li style="margin:0 0 4px 0;">&laquo;&iquest;Cu&aacute;nto vendimos hoy?&raquo;</li>
                    <li style="margin:0 0 4px 0;">&laquo;&iquest;Cu&aacute;nto stock queda de este art&iacute;culo?&raquo;</li>
                    <li style="margin:0 0 4px 0;">&laquo;Carg&aacute; un cliente nuevo&raquo;</li>
                    <li style="margin:0;">&laquo;Carg&aacute; un gasto de combustible&raquo;</li>
                  </ul>
                  <p style="margin:14px 0 0 0;font-family:{!! $font !!};font-size:14px;line-height:22px;color:#566078;">
                    Son datos de ficción: probá lo que quieras. Te contesta solo mientras dura tu turno.
                  </p>
                  <table role="presentation" cellpadding="0" cellspacing="0" border="0" class="cc-boton-tabla" style="margin:20px 0 0 0;">
                    <tr>
                      <td style="border-radius:12px;background-color:#1c2333;">
                        <a href="{{ $asistente_url }}" target="_blank" rel="noopener noreferrer" class="cc-boton"
                           style="display:inline-block;padding:14px 28px;font-family:{!! $font !!};font-size:16px;line-height:20px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">
                          Abrir el chat en WhatsApp
                        </a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
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
            @if($hay_turno)
              Guardá este mail: tiene tu turno y es tu llave para entrar a la demo desde la computadora.
            @else
              Guardá este mail: es tu llave para entrar a la demo desde la computadora.
            @endif
          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>

</body>
</html>

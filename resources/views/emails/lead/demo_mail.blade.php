<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>Tu demo de ComercioCity está lista</title>
</head>
<body style="margin:0;padding:0;background-color:#F5F7FA;font-family:Arial,Helvetica,sans-serif;">

{{-- Wrapper externo centrado --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F5F7FA;">
  <tr>
    <td align="center" style="padding:24px 16px;">

      {{-- Contenedor principal 600px --}}
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">

        {{-- ================================================================
             0. LINK "VER EN EL NAVEGADOR" (prompt 213/02): respaldo de la
             landing pública, arriba de todo y en letra chica/tenue, siguiendo
             la convención habitual de cualquier newsletter. Solo se muestra si
             el lead tiene `uuid` cargado (registros viejos no tienen landing).
             ================================================================ --}}
        @if($url_landing)
        <tr>
          <td style="padding:10px 32px 0 32px;text-align:center;">
            <a href="{{ $url_landing }}" target="_blank" rel="noopener noreferrer"
               style="font-size:11px;color:#9AA5B1;text-decoration:underline;">
              ¿No se ve bien este mail? Abrí todo desde acá
            </a>
          </td>
        </tr>
        @endif

        {{-- ================================================================
             1. HEADER: logo + fondo azul oscuro
             ================================================================ --}}
        <tr>
          <td style="background-color:#1A1A2E;padding:28px 32px;text-align:center;">
            @if($logo_url)
              <img src="{{ $logo_url }}" alt="ComercioCity" width="180" style="max-width:180px;height:auto;display:inline-block;">
            @else
              <span style="color:#ffffff;font-size:22px;font-weight:bold;letter-spacing:1px;">ComercioCity</span>
            @endif
          </td>
        </tr>

        {{-- ================================================================
             2. SALUDO PERSONALIZADO
             ================================================================ --}}
        <tr>
          <td style="padding:36px 32px 8px 32px;">
            <p style="margin:0;font-size:26px;font-weight:bold;color:#1A1A2E;line-height:1.3;">
              Hola {{ $nombre }},
            </p>
            <p style="margin:12px 0 0 0;font-size:15px;color:#444444;line-height:1.6;">
              Tu acceso a la demo de ComercioCity está listo. Te preparamos todo para que puedas sacarle el máximo provecho.
            </p>
          </td>
        </tr>

        {{-- ================================================================
             3. BLOQUE DESTACADO: día y horario de la demo
             ================================================================ --}}
        @if($dia || $hora_inicio || $hora_fin)
        <tr>
          <td style="padding:20px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#EBF3FF;border-left:4px solid #1A56C4;border-radius:4px;padding:18px 20px;">
                  <p style="margin:0;font-size:13px;font-weight:bold;color:#1A56C4;text-transform:uppercase;letter-spacing:0.5px;">
                    Tu demo asignada
                  </p>
                  <p style="margin:6px 0 0 0;font-size:18px;font-weight:bold;color:#1A1A2E;">
                    @if($dia){{ ucfirst($dia) }}@endif
                    @if($hora_inicio && $hora_fin)
                      &nbsp;·&nbsp; {{ $hora_inicio }} a {{ $hora_fin }} hs
                    @elseif($hora_inicio)
                      &nbsp;·&nbsp; desde las {{ $hora_inicio }} hs
                    @endif
                  </p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        {{-- ================================================================
             4. SECCIÓN "ANTES DE ENTRAR": botón video introductorio
             ================================================================ --}}
        @if($video_intro)
        <tr>
          <td style="padding:8px 32px 20px 32px;">
            <p style="margin:0 0 12px 0;font-size:16px;font-weight:bold;color:#1A1A2E;">
              Antes de entrar
            </p>
            <p style="margin:0 0 14px 0;font-size:14px;color:#555555;line-height:1.6;">
              Te recomendamos ver este video corto que te da contexto del sistema antes de la sesión. Solo te lleva unos minutos y vale la pena.
            </p>
            <table cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#1A56C4;border-radius:5px;">
                  <a href="{{ $video_intro }}" target="_blank" rel="noopener noreferrer"
                     style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">
                    ▶&nbsp; Ver video introductorio
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        {{-- Separador --}}
        <tr>
          <td style="padding:0 32px;">
            <hr style="border:none;border-top:1px solid #E8ECF0;margin:4px 0;">
          </td>
        </tr>

        {{-- ================================================================
             5. SECCIÓN "VIDEOS TUTORIALES": 4 filas
             ================================================================ --}}
        <tr>
          <td style="padding:20px 32px 8px 32px;">
            <p style="margin:0 0 12px 0;font-size:16px;font-weight:bold;color:#1A1A2E;">
              Videos tutoriales
            </p>
            <p style="margin:0 0 16px 0;font-size:14px;color:#555555;line-height:1.6;">
              Estos videos cortos te muestran las funciones clave del sistema para que llegues preparado a la demo.
            </p>
          </td>
        </tr>

        {{-- Fila: Video Stock --}}
        @if($video_stock)
        <tr>
          <td style="padding:0 32px 2px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#F5F7FA;border-radius:4px;padding:13px 16px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td width="36" style="vertical-align:middle;">
                        <span style="display:inline-block;width:32px;height:32px;background-color:#1A56C4;border-radius:50%;text-align:center;line-height:32px;font-size:15px;color:#fff;">📦</span>
                      </td>
                      <td style="padding-left:12px;vertical-align:middle;">
                        <span style="font-size:14px;font-weight:bold;color:#1A1A2E;">Gestión de stock</span>
                        <span style="font-size:13px;color:#666666;"> — cómo cargar y manejar tus productos</span>
                      </td>
                      <td align="right" style="vertical-align:middle;">
                        <a href="{{ $video_stock }}" target="_blank" rel="noopener noreferrer"
                           style="font-size:13px;color:#1A56C4;text-decoration:none;font-weight:bold;white-space:nowrap;">
                          Ver video →
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

        {{-- Fila: Video Ventas --}}
        @if($video_ventas)
        <tr>
          <td style="padding:4px 32px 2px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#F5F7FA;border-radius:4px;padding:13px 16px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td width="36" style="vertical-align:middle;">
                        <span style="display:inline-block;width:32px;height:32px;background-color:#1A56C4;border-radius:50%;text-align:center;line-height:32px;font-size:15px;color:#fff;">💰</span>
                      </td>
                      <td style="padding-left:12px;vertical-align:middle;">
                        <span style="font-size:14px;font-weight:bold;color:#1A1A2E;">Ventas y caja</span>
                        <span style="font-size:13px;color:#666666;"> — registrar ventas, tickets y cobros</span>
                      </td>
                      <td align="right" style="vertical-align:middle;">
                        <a href="{{ $video_ventas }}" target="_blank" rel="noopener noreferrer"
                           style="font-size:13px;color:#1A56C4;text-decoration:none;font-weight:bold;white-space:nowrap;">
                          Ver video →
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

        {{-- Fila: Video Ecommerce --}}
        @if($video_ecommerce)
        <tr>
          <td style="padding:4px 32px 2px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#F5F7FA;border-radius:4px;padding:13px 16px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td width="36" style="vertical-align:middle;">
                        <span style="display:inline-block;width:32px;height:32px;background-color:#1A56C4;border-radius:50%;text-align:center;line-height:32px;font-size:15px;color:#fff;">🛒</span>
                      </td>
                      <td style="padding-left:12px;vertical-align:middle;">
                        <span style="font-size:14px;font-weight:bold;color:#1A1A2E;">Tienda online</span>
                        <span style="font-size:13px;color:#666666;"> — tu ecommerce conectado al sistema</span>
                      </td>
                      <td align="right" style="vertical-align:middle;">
                        <a href="{{ $video_ecommerce }}" target="_blank" rel="noopener noreferrer"
                           style="font-size:13px;color:#1A56C4;text-decoration:none;font-weight:bold;white-space:nowrap;">
                          Ver video →
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

        {{-- Fila: Video Cierre --}}
        @if($video_cierre)
        <tr>
          <td style="padding:4px 32px 20px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#F5F7FA;border-radius:4px;padding:13px 16px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td width="36" style="vertical-align:middle;">
                        <span style="display:inline-block;width:32px;height:32px;background-color:#1A56C4;border-radius:50%;text-align:center;line-height:32px;font-size:15px;color:#fff;">🎯</span>
                      </td>
                      <td style="padding-left:12px;vertical-align:middle;">
                        <span style="font-size:14px;font-weight:bold;color:#1A1A2E;">Cómo dar el paso</span>
                        <span style="font-size:13px;color:#666666;"> — opciones y siguientes pasos</span>
                      </td>
                      <td align="right" style="vertical-align:middle;">
                        <a href="{{ $video_cierre }}" target="_blank" rel="noopener noreferrer"
                           style="font-size:13px;color:#1A56C4;text-decoration:none;font-weight:bold;white-space:nowrap;">
                          Ver video →
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

        {{-- ================================================================
             5b. SECCIÓN "TUTORIALES PERSONALIZADOS" (solo si hay filas en el lead)
             ================================================================ --}}
        @if(!empty($personalized_demo_videos))
        <tr>
          <td style="padding:12px 32px 8px 32px;">
            <p style="margin:0 0 8px 0;font-size:16px;font-weight:bold;color:#1A1A2E;">
              Tutoriales personalizados
            </p>
            <p style="margin:0 0 16px 0;font-size:14px;color:#555555;line-height:1.6;">
              Estos videos están pensados para tu caso en particular. Incluso podemos decirte que
              <strong>están hechos para vos, {{ $nombre }}</strong>, para que llegues con lo que más te interesa resuelto.
            </p>
          </td>
        </tr>
        @foreach($personalized_demo_videos as $pv)
        <tr>
          <td style="padding:4px 32px 2px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#F5F7FA;border-radius:4px;padding:13px 16px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td width="36" style="vertical-align:middle;">
                        <span style="display:inline-block;width:32px;height:32px;background-color:#1A56C4;border-radius:50%;text-align:center;line-height:32px;font-size:15px;color:#fff;">🎬</span>
                      </td>
                      <td style="padding-left:12px;vertical-align:middle;">
                        <span style="font-size:14px;font-weight:bold;color:#1A1A2E;">{{ $pv['title'] }}</span>
                        @if(!empty($pv['description']))
                        <span style="font-size:13px;color:#666666;"> — {{ $pv['description'] }}</span>
                        @endif
                      </td>
                      <td align="right" style="vertical-align:middle;">
                        <a href="{{ $pv['video_url'] }}" target="_blank" rel="noopener noreferrer"
                           style="font-size:13px;color:#1A56C4;text-decoration:none;font-weight:bold;white-space:nowrap;">
                          Ver video →
                        </a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endforeach
        <tr>
          <td style="padding:8px 32px 0 32px;"></td>
        </tr>
        @endif

        {{-- Separador --}}
        <tr>
          <td style="padding:0 32px;">
            <hr style="border:none;border-top:1px solid #E8ECF0;margin:4px 0;">
          </td>
        </tr>

        {{-- ================================================================
             6. SECCIÓN "TU ACCESO": caja oscura con credenciales
             ================================================================ --}}
        @if($url_demo || $usuario || $password || $doc_number)
        <tr>
          <td style="padding:20px 32px 8px 32px;">
            <p style="margin:0 0 12px 0;font-size:16px;font-weight:bold;color:#1A1A2E;">
              Tu acceso al sistema
            </p>
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="background-color:#1A1A2E;border-radius:6px;padding:20px 22px;">
                  @if($url_demo)
                  <p style="margin:0 0 8px 0;font-size:12px;color:#8899AA;text-transform:uppercase;letter-spacing:0.5px;">URL del sistema</p>
                  <table cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;">
                    <tr>
                      <td style="background-color:#1A56C4;border-radius:5px;">
                        <a href="{{ $url_demo }}" target="_blank" rel="noopener noreferrer"
                           style="display:inline-block;padding:11px 18px;font-size:13px;font-weight:bold;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">
                          Ir al sistema demo
                        </a>
                      </td>
                    </tr>
                  </table>
                  @endif

                  @if($doc_number)
                  <p style="margin:0 0 8px 0;font-size:12px;color:#8899AA;text-transform:uppercase;letter-spacing:0.5px;">Número de documento</p>
                  <p style="margin:0 0 16px 0;font-family:Courier New,Courier,monospace;font-size:14px;color:#F0F4F8;">
                    {{ $doc_number }}
                  </p>
                  @endif

                  @if($usuario)
                  <p style="margin:0 0 8px 0;font-size:12px;color:#8899AA;text-transform:uppercase;letter-spacing:0.5px;">Usuario</p>
                  <p style="margin:0 0 16px 0;font-family:Courier New,Courier,monospace;font-size:14px;color:#F0F4F8;">
                    {{ $usuario }}
                  </p>
                  @endif

                  @if($password)
                  <p style="margin:0 0 8px 0;font-size:12px;color:#8899AA;text-transform:uppercase;letter-spacing:0.5px;">Contraseña</p>
                  <p style="margin:0;font-family:Courier New,Courier,monospace;font-size:14px;color:#F0F4F8;">
                    {{ $password }}
                  </p>
                  @endif
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        {{-- ================================================================
             7. SECCIÓN "TIENDA DEMO": link a tienda online
             ================================================================ --}}
        @if($url_tienda)
        <tr>
          <td style="padding:16px 32px 8px 32px;">
            <p style="margin:0 0 10px 0;font-size:16px;font-weight:bold;color:#1A1A2E;">
              Tienda online demo
            </p>
            <p style="margin:0 0 10px 0;font-size:14px;color:#555555;line-height:1.6;">
              El sistema viene con una tienda online conectada. Podés verla desde acá:
            </p>
            <table cellpadding="0" cellspacing="0" border="0" style="margin:0;">
              <tr>
                <td style="background-color:#1A56C4;border-radius:5px;">
                  <a href="{{ $url_tienda }}" target="_blank" rel="noopener noreferrer"
                     style="display:inline-block;padding:11px 18px;font-size:13px;font-weight:bold;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">
                    Abrir tienda demo
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        @endif

        {{-- Separador --}}
        <tr>
          <td style="padding:16px 32px 0 32px;">
            <hr style="border:none;border-top:1px solid #E8ECF0;margin:0;">
          </td>
        </tr>

        {{-- ================================================================
             8. SECCIÓN "¿TENÉS DUDAS?"
             ================================================================ --}}
        <tr>
          <td style="padding:20px 32px;">
            <p style="margin:0 0 6px 0;font-size:15px;font-weight:bold;color:#1A1A2E;">
              ¿Tenés dudas?
            </p>
            <p style="margin:0;font-size:14px;color:#555555;line-height:1.6;">
              Antes o después de la demo, escribinos por WhatsApp y te respondemos al toque.
              @if($url_whatsapp)
              <a href="{{ $url_whatsapp }}" target="_blank" rel="noopener noreferrer"
                 style="color:#1A56C4;font-weight:bold;text-decoration:none;">
                Escribir por WhatsApp →
              </a>
              @endif
            </p>
          </td>
        </tr>

        {{-- ================================================================
             9. FOOTER
             ================================================================ --}}
        <tr>
          <td style="background-color:#1A1A2E;padding:22px 32px;text-align:center;">
            <p style="margin:0 0 4px 0;font-size:14px;font-weight:bold;color:#ffffff;">
              {{ $presenter_name }}
            </p>
            <p style="margin:0 0 4px 0;font-size:13px;color:#8899AA;">
              {{ $presenter_role }} &mdash; ComercioCity
            </p>
            @if($url_whatsapp)
            <p style="margin:10px 0 0 0;">
              <a href="{{ $url_whatsapp }}" target="_blank" rel="noopener noreferrer"
                 style="font-size:13px;color:#56E0C0;text-decoration:none;">
                WhatsApp →
              </a>
            </p>
            @endif
          </td>
        </tr>

      </table>
      {{-- Fin contenedor principal --}}

    </td>
  </tr>
</table>

</body>
</html>

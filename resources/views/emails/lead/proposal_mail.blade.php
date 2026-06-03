<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Propuesta ComercioCity</title>
</head>
<body style="margin:0;padding:0;background-color:#F5F7FA;font-family:Arial,Helvetica,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F5F7FA;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">

                    {{-- 1. Header: logo ComercioCity sobre fondo azul oscuro. --}}
                    <tr>
                        <td style="background-color:#1A1A2E;padding:28px 32px;text-align:center;">
                            @if($logo_url)
                                <img src="{{ $logo_url }}" alt="ComercioCity" width="180" style="max-width:180px;height:auto;display:inline-block;">
                            @else
                                <span style="color:#ffffff;font-size:22px;font-weight:bold;letter-spacing:1px;">ComercioCity</span>
                            @endif
                        </td>
                    </tr>

                    {{-- 2. Saludo personalizado con nombre del lead. --}}
                    <tr>
                        <td style="padding:30px 32px 12px 32px;">
                            <p style="margin:0;font-size:24px;font-weight:bold;color:#1A1A2E;line-height:1.35;">
                                Hola {{ $nombre }}, gracias por probar la plataforma.
                            </p>
                        </td>
                    </tr>

                    {{-- 3. Bajada: contexto previo al detalle del servicio. --}}
                    <tr>
                        <td style="padding:6px 32px 22px 32px;">
                            <p style="margin:0;font-size:15px;color:#374151;line-height:1.65;">
                                Lo que viste es solo una parte de lo que ComercioCity puede hacer por tu negocio. Acá te detallo todo lo que incluye el servicio para que puedas tomar una decisión con toda la información.
                            </p>
                        </td>
                    </tr>

                    {{-- 4. Lista de ítems incluidos en el servicio, con check verde por ítem. --}}
                    <tr>
                        <td style="padding:6px 32px 18px 32px;">
                            <p style="margin:0 0 12px 0;font-size:17px;font-weight:bold;color:#1A1A2E;">¿Qué incluye el servicio?</p>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;">
                                @foreach($lista_items_que_incluye_el_servicio as $item_incluido)
                                    <tr>
                                        <td width="26" style="vertical-align:top;padding:8px 0 8px 0;">
                                            <span style="display:inline-block;width:18px;height:18px;background:#16A34A;border-radius:50%;text-align:center;line-height:18px;color:#ffffff;font-size:12px;">✓</span>
                                        </td>
                                        <td style="vertical-align:top;padding:6px 0 8px 0;font-size:14px;color:#1A1A2E;line-height:1.5;">
                                            {{ $item_incluido }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>

                    {{--
                        5. Diferencial: conexión precableada con científico de datos.
                           Explica qué viene incluido (el nexo) vs. qué se presupuesta aparte (el trabajo).
                    --}}
                    <tr>
                        <td style="padding:8px 32px 18px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#EBF5FF;border:1px solid #93C5FD;border-radius:8px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 14px 0;font-size:18px;font-weight:bold;color:#1A56C4;line-height:1.35;">
                                            Un diferencial que no tenés en ningún otro sistema
                                        </p>
                                        <p style="margin:0 0 12px 0;font-size:14px;color:#1A1A2E;line-height:1.6;">
                                            El sistema ya te muestra márgenes reales, rentabilidad por producto y las métricas clave de tu operación. Eso lo tenés desde el día uno.
                                        </p>
                                        <p style="margin:0 0 12px 0;font-size:14px;color:#1A1A2E;line-height:1.6;">
                                            Pero cuando llegue el momento en que querés dar un salto — abrir una sucursal, cambiar tu mix de productos, entrar a un canal nuevo, redefinir tu estrategia de precios — ahí es donde entra el científico de datos.
                                        </p>
                                        <p style="margin:0 0 12px 0;font-size:14px;color:#1A1A2E;line-height:1.6;">
                                            ComercioCity tiene conexión directa con un especialista en análisis de datos para negocios comerciales. Su trabajo es ayudarte a tomar esas decisiones estratégicas apoyándote en reportes completamente personalizados para tu negocio, no dashboards genéricos.
                                        </p>
                                        <p style="margin:0 0 16px 0;font-size:14px;color:#1A1A2E;line-height:1.6;">
                                            El nexo ya está hecho. El científico conoce el sistema y sabe cómo trabajar con tu información. Cuando estés listo para ese salto, agendás una reunión y presupuestamos ese servicio según lo que necesites.
                                        </p>
                                        <p style="margin:0;font-size:13px;color:#6B7280;font-style:italic;line-height:1.55;">
                                            <strong style="color:#4B5563;">Aclaración:</strong> el trabajo del científico de datos no está incluido en el precio base. Lo que sí está incluido es el acceso y la conexión al sistema, sin costos adicionales de integración.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{--
                        6. Comparativa de valor: contexto de mercado previo al precio final.
                           Permite que el lead comprenda el valor antes de ver el número.
                    --}}
                    <tr>
                        <td style="padding:8px 32px 18px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F3F4F6;border-radius:8px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0 0 12px 0;font-size:17px;font-weight:bold;color:#111827;">¿Cuánto vale lo que estás a punto de ver?</p>
                                        <p style="margin:0 0 14px 0;font-size:14px;color:#374151;line-height:1.6;">
                                            Para que tengas contexto de lo que incluye este servicio:
                                        </p>
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td width="18" style="vertical-align:top;padding:5px 8px 5px 0;">
                                                    <span style="color:#374151;font-size:14px;">•</span>
                                                </td>
                                                <td style="vertical-align:top;padding:5px 0;font-size:14px;color:#374151;line-height:1.5;">
                                                    Un desarrollador que te arme una tienda online integrada con tu sistema: <strong>USD 800 a USD 2.000</strong>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="18" style="vertical-align:top;padding:5px 8px 5px 0;">
                                                    <span style="color:#374151;font-size:14px;">•</span>
                                                </td>
                                                <td style="vertical-align:top;padding:5px 0;font-size:14px;color:#374151;line-height:1.5;">
                                                    Un sistema de gestión con soporte humano real: <strong>desde USD 80/mes</strong> (sin implementación incluida)
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="18" style="vertical-align:top;padding:5px 8px 5px 0;">
                                                    <span style="color:#374151;font-size:14px;">•</span>
                                                </td>
                                                <td style="vertical-align:top;padding:5px 0;font-size:14px;color:#374151;line-height:1.5;">
                                                    Un analista de datos por proyecto puntual: <strong>desde USD 300</strong>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td width="18" style="vertical-align:top;padding:5px 8px 5px 0;">
                                                    <span style="color:#374151;font-size:14px;">•</span>
                                                </td>
                                                <td style="vertical-align:top;padding:5px 0;font-size:14px;color:#374151;line-height:1.5;">
                                                    La implementación y migración de datos por separado: <strong>desde USD 500</strong>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin:14px 0 0 0;font-size:14px;font-weight:bold;color:#111827;line-height:1.5;">
                                            ComercioCity incluye todo eso integrado, implementado y funcionando desde el día uno.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{--
                        7. Precio del servicio: bono de acción rápida al contado.
                           Sin cuotas ni financiación — precio único USD 500 mientras dure la ventana.
                    --}}
                    <tr>
                        <td style="padding:8px 32px 16px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="background-color:#1A1A2E;border-radius:6px;padding:22px 20px;">
                                        <p style="margin:0 0 16px 0;color:#ffffff;font-size:15px;font-weight:bold;">Precio del servicio</p>

                                        {{-- Precio de lista tachado. --}}
                                        <p style="margin:0 0 16px 0;color:#AEB7C2;font-size:38px;font-weight:bold;line-height:1.05;text-decoration:line-through;">
                                            USD {{ $precio_base }}
                                        </p>

                                        {{-- Separador visual entre precio de lista y precio final. --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px 0;">
                                            <tr>
                                                <td style="height:1px;background-color:#374151;font-size:0;line-height:0;">&nbsp;</td>
                                            </tr>
                                        </table>

                                        {{-- Badge identificador del bono. --}}
                                        <p style="margin:0 0 10px 0;color:#FBBF24;font-size:13px;font-weight:bold;letter-spacing:0.4px;text-transform:uppercase;">
                                            Bono de acción rápida
                                        </p>

                                        {{-- Precio final con bono. --}}
                                        <p style="margin:0 0 8px 0;color:#ffffff;font-size:32px;font-weight:bold;line-height:1.15;">
                                            USD {{ $precio_descuento }}
                                        </p>

                                        {{-- Ahorro respecto al precio de lista. --}}
                                        <p style="margin:0 0 10px 0;color:#56E0C0;font-size:15px;font-weight:bold;line-height:1.45;">
                                            Ahorrás USD {{ $ahorro }} respecto al precio de lista
                                        </p>

                                        {{-- Fecha exacta de vencimiento del bono, visible junto al precio. --}}
                                        <p style="margin:0 0 18px 0;color:#9CA3AF;font-size:13px;line-height:1.5;">
                                            Válido hasta el {{ $fecha_vencimiento }}
                                        </p>

                                        {{-- Módulo de ecommerce bonificado incluido en esta ventana. --}}
                                        <p style="margin:0 0 18px 0;color:#D1D5DB;font-size:13px;line-height:1.55;">
                                            Este bono incluye además <strong style="color:#A7F3D0;">1 módulo de ecommerce gratis</strong> — los módulos de ecommerce (ComercioCity, Tienda Nube o Mercado Libre) se cotizan aparte a <strong style="color:#F9FAFB;">USD 250 cada uno</strong>. Si cerrás dentro de la ventana promocional, te bonificamos uno sin costo adicional.
                                        </p>

                                        {{-- Urgencia interna dentro del bloque de precio. --}}
                                        <p style="margin:0;color:#FDE68A;font-size:13px;line-height:1.5;font-weight:bold;">
                                            No dejes pasar la ventana: cada hora cuenta para el ahorro en el precio y el módulo de ecommerce gratis.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- 8. Bloque de urgencia: refuerza el vencimiento del bono con fondo naranja. --}}
                    <tr>
                        <td style="padding:0 32px 16px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="background:#D97706;border-radius:6px;padding:14px 16px;">
                                        <p style="margin:0;color:#ffffff;font-size:14px;font-weight:bold;line-height:1.55;">
                                            El bono de acción rápida vence el <strong>{{ $fecha_vencimiento }}</strong>. Después volvés al precio de lista de USD {{ $precio_base }} sin el módulo de ecommerce bonificado.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- 9. Opción de seña para quienes no pueden pagar el total ahora. --}}
                    <tr>
                        <td style="padding:0 32px 20px 32px;">
                            <p style="margin:0 0 6px 0;font-size:16px;font-weight:bold;color:#1A1A2E;">Si no tenés el total ahora</p>
                            <p style="margin:0;font-size:14px;color:#374151;line-height:1.6;">
                                Podés señar <strong>USD 100</strong> para congelar este precio y coordinar el saldo sin perder la promoción.
                            </p>
                        </td>
                    </tr>

                    {{-- 10. CTA principal: botón verde hacia WhatsApp. --}}
                    <tr>
                        <td style="padding:0 32px 28px 32px;text-align:center;">
                            <table cellpadding="0" cellspacing="0" border="0" align="center">
                                <tr>
                                    <td style="background-color:#16A34A;border-radius:6px;">
                                        <a href="{{ $url_whatsapp }}" target="_blank" rel="noopener noreferrer"
                                           style="display:inline-block;padding:14px 30px;font-size:18px;font-weight:bold;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;">
                                            Quiero arrancar
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- 11. Testimonios: prueba social de negocios que ya eligieron ComercioCity. --}}
                    <tr>
                        <td style="padding:8px 32px 16px 32px;">
                            <p style="margin:0 0 8px 0;font-size:17px;font-weight:bold;color:#1A1A2E;">Testimonios</p>
                            <p style="margin:0 0 18px 0;font-size:14px;color:#4B5563;line-height:1.55;">
                                Estos negocios también tuvieron que tomar una decisión al elegir una plataforma. Hoy están contentos y en paz de haber elegido ComercioCity.
                            </p>
                            @foreach($lista_testimonios as $testimonio)
                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:{{ $loop->last ? '0' : '14px' }};background-color:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;">
                                    <tr>
                                        <td style="padding:18px 20px;">
                                            <p style="margin:0 0 14px 0;font-size:16px;font-weight:bold;color:#1A1A2E;line-height:1.35;">
                                                {{ $testimonio['business_name'] }}
                                            </p>
                                            @if(!empty($testimonio['video_url']))
                                                <p style="margin:0 0 10px 0;font-size:14px;line-height:1.5;">
                                                    <a href="{{ $testimonio['video_url'] }}" target="_blank" rel="noopener noreferrer"
                                                       style="color:#1A56C4;font-weight:bold;text-decoration:none;">
                                                        Ver testimonio en video →
                                                    </a>
                                                </p>
                                            @endif
                                            @if(!empty($testimonio['instagram_url']))
                                                <p style="margin:0;font-size:14px;line-height:1.5;">
                                                    <a href="{{ $testimonio['instagram_url'] }}" target="_blank" rel="noopener noreferrer"
                                                       style="color:#1A56C4;font-weight:bold;text-decoration:none;">
                                                        Instagram del negocio →
                                                    </a>
                                                </p>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            @endforeach
                        </td>
                    </tr>

                    {{-- 12. Suscripción mensual: detalle coordinado por WhatsApp según uso real. --}}
                    <tr>
                        <td style="padding:0 32px 24px 32px;">
                            <p style="margin:0 0 6px 0;font-size:16px;font-weight:bold;color:#1A1A2E;">Suscripción mensual</p>
                            <p style="margin:0;font-size:14px;color:#374151;line-height:1.6;">
                                Luego de la implementación hay una mensualidad por mantenimiento, soporte e infraestructura. Los detalles los coordinamos por WhatsApp según la cantidad de usuarios y módulos de tu negocio.
                            </p>
                        </td>
                    </tr>

                    {{-- 13. Footer: firma del equipo con enlace a WhatsApp. --}}
                    <tr>
                        <td style="background-color:#1A1A2E;padding:22px 32px;text-align:center;">
                            <p style="margin:0 0 4px 0;font-size:14px;font-weight:bold;color:#ffffff;">
                                {{ $presenter_name }}
                            </p>
                            <p style="margin:0 0 6px 0;font-size:13px;color:#AEB7C2;">
                                {{ $presenter_role }} &mdash; ComercioCity
                            </p>
                            <a href="{{ $url_whatsapp }}" target="_blank" rel="noopener noreferrer"
                               style="font-size:13px;color:#56E0C0;text-decoration:none;font-weight:bold;">
                                WhatsApp →
                            </a>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>

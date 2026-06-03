<?php

namespace App\Mail\Helpers;

use App\Mail\LeadProposalMail;
use App\Models\Lead;

/**
 * Helper que arma el Mailable de Mail 2 (propuesta) para leads.
 *
 * Este correo se envía luego de la demo para cerrar la venta de la
 * solución base con urgencia comercial y CTA directo a WhatsApp.
 * No incluye financiación ni cuotas — precio único al contado con bono.
 */
class LeadFollowupMailHelper
{
    /**
     * Construye el Mailable de propuesta (Mail 2) con template dedicado.
     *
     * @param Lead $lead Prospecto al que se le enviará el correo
     *
     * @return LeadProposalMail Mailable listo para Mail::to()->send()
     */
    public static function build(Lead $lead)
    {
        // Nombre visible para personalizar el saludo del correo.
        $nombre = self::pick_display_name($lead);

        // Lista de valor incluida en la solución base según el copy aprobado.
        $lista_items_que_incluye_el_servicio = config('commerciocity.proposal_mail.items', [
            'Implementación inicial completa en tu negocio',
            'Migración de toda tu información (productos, clientes, proveedores)',
            'Capacitación práctica para operar desde el día uno',
            'Soporte humano real de lunes a sábados, de por vida',
            'Actualizaciones del sistema cada 2 meses',
            'Infraestructura en la nube — sin servidores, sin mantenimiento técnico',
            '1 módulo de ecommerce integrado (ComercioCity, Tienda Nube o Mercado Libre)',
        ]);

        // Variables comerciales del bloque de precio (sin cuotas).
        $precio_base = (int) config('commerciocity.proposal_mail.pricing.base_price', 700);
        $precio_descuento = (int) config('commerciocity.proposal_mail.pricing.discount_price', 500);
        $ahorro = (int) config('commerciocity.proposal_mail.pricing.saving_amount', 200);

        // CTA principal. La fecha de vencimiento se calcula internamente en el Mailable
        // usando config('commerciocity.proposal_mail.urgency_hours') al momento del envío.
        $url_whatsapp = (string) config('commerciocity.proposal_mail.whatsapp_url', 'https://api.whatsapp.com/send?phone=3444622139');

        // Branding y firma del correo.
        $logo_url = (string) config('commerciocity.logo_url', '');
        $presenter_name = (string) config('commerciocity.proposal_mail.presenter_name', 'Equipo ComercioCity');
        $presenter_role = (string) config('commerciocity.proposal_mail.presenter_role', 'Fundador');

        // Casos de éxito (2 tarjetas): completá business_name, video_url e instagram_url por negocio.
        // Podés mover estos datos a config/commerciocity.php bajo proposal_mail.testimonials si preferís no tocar código.
        $lista_testimonios = config('commerciocity.proposal_mail.testimonials', self::default_proposal_testimonials());

        return new LeadProposalMail(
            $nombre,
            $lista_items_que_incluye_el_servicio,
            $precio_base,
            $precio_descuento,
            $ahorro,
            $url_whatsapp,
            $logo_url,
            $presenter_name,
            $presenter_role,
            self::normalize_proposal_testimonials($lista_testimonios)
        );
    }

    /**
     * Testimonios por defecto del mail de propuesta (editar URLs y nombres acá).
     *
     * Cada fila: business_name (visible), video_url (testimonio en video), instagram_url (perfil del negocio).
     *
     * @return array<int, array<string, string>>
     */
    private static function default_proposal_testimonials()
    {
        return [
            [
                'business_name' => 'Completar: nombre del negocio 1',
                'video_url' => '',
                'instagram_url' => '',
            ],
            [
                'business_name' => 'Completar: nombre del negocio 2',
                'video_url' => '',
                'instagram_url' => '',
            ],
        ];
    }

    /**
     * Normaliza filas de testimonios a claves esperadas y recorta a dos ítems para el layout del mail.
     *
     * @param mixed $lista_testimonios Entrada desde config u otro origen.
     *
     * @return array<int, array<string, string>>
     */
    private static function normalize_proposal_testimonials($lista_testimonios)
    {
        if (!is_array($lista_testimonios)) {
            return self::default_proposal_testimonials();
        }

        // Lista final con como máximo dos tarjetas en el correo.
        $normalizados = [];
        foreach ($lista_testimonios as $fila_testimonio) {
            if (!is_array($fila_testimonio)) {
                continue;
            }
            $normalizados[] = [
                'business_name' => (string) ($fila_testimonio['business_name'] ?? ''),
                'video_url' => (string) ($fila_testimonio['video_url'] ?? ''),
                'instagram_url' => (string) ($fila_testimonio['instagram_url'] ?? ''),
            ];
            if (count($normalizados) >= 2) {
                break;
            }
        }

        if (count($normalizados) === 0) {
            return self::default_proposal_testimonials();
        }

        return $normalizados;
    }

    /**
     * Elige el nombre más representativo para personalizar el correo.
     *
     * @param Lead $lead
     *
     * @return string Nombre a mostrar; fallback final: "Hola"
     */
    private static function pick_display_name(Lead $lead)
    {
        if (!empty($lead->contact_name)) {
            return trim($lead->contact_name);
        }
        if (!empty($lead->company_name)) {
            return trim($lead->company_name);
        }

        return 'Hola';
    }
}

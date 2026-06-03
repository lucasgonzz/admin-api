<?php

namespace App\Mail\Helpers;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Models\Lead;

/**
 * Helper que arma el Mailable "tarjeta de presentación" que se envía
 * manualmente a un prospecto (Lead) antes o después de la reunión de venta.
 *
 * Separamos la construcción del payload en un helper para:
 * - Mantener los controllers limpios.
 * - Poder reutilizar el armado desde otros puntos (p. ej. un job a futuro).
 * - Seguir la misma filosofía de helpers que usa empresa-api.
 */
class LeadPresentationMailHelper
{
    /**
     * Construye el Mailable ComercioCity con el contenido de la tarjeta de
     * presentación personalizada para el prospecto recibido.
     *
     * @param Lead $lead Prospecto al que se le va a enviar el correo
     *
     * @return ComercioCityMail Mailable listo para pasar a Mail::to()->send()
     */
    public static function build(Lead $lead)
    {
        // Nombre preferido para saludar: usamos contact_name, o company_name como fallback
        $display_name = self::pick_display_name($lead);

        // Identidad del presentador y datos del video configurables por env
        $presenter_name = config('commerciocity.presenter.name');
        $presenter_role = config('commerciocity.presenter.role');
        $avatar_url = config('commerciocity.presenter.avatar_url');

        $video_url = config('commerciocity.presentation_video.url');
        $video_thumb = config('commerciocity.presentation_video.thumbnail_url');
        $video_caption = config('commerciocity.presentation_video.caption');

        $cta_text = config('commerciocity.presentation_cta.text');
        $cta_url = config('commerciocity.presentation_cta.url');

        // Asunto con el nombre del prospecto para que se vea personal en la bandeja
        $subject = '¡Gracias por tu interés, ' . $display_name . '!';

        // Párrafos que explican el motivo del mail y nutren al prospecto antes de la reunión
        $paragraphs = [
            'Te queríamos dejar este mail como una tarjeta de presentación digital, para que antes de la reunión puedas conocernos un poco más.',
            'Dentro del video te contamos cómo trabajamos, qué tipo de soluciones ofrecemos y qué podés esperar de nosotros durante el proceso.',
            'Cualquier consulta que tengas, respondé este mismo mail o escribinos por WhatsApp — estamos para ayudarte.',
        ];

        // Detalles opcionales: si el lead tiene empresa la mostramos como dato de personalización
        $detail_lines = [];
        if (!empty($lead->company_name)) {
            $detail_lines[] = [
                'label' => 'Empresa',
                'value' => $lead->company_name,
                'bold_label' => true,
            ];
        }
        if (!empty($lead->meeting_scheduled_at)) {
            $detail_lines[] = [
                'label' => 'Reunión agendada',
                'value' => $lead->meeting_scheduled_at instanceof \DateTimeInterface
                    ? $lead->meeting_scheduled_at->format('d/m/Y H:i')
                    : (string) $lead->meeting_scheduled_at,
                'bold_label' => true,
            ];
        }

        // Ensamblamos el payload con todas las secciones que el layout sabe renderizar.
        // Los campos vacíos simplemente no se dibujan en el HTML.
        $payload = new ComercioCityMailPayload([
            'subject' => $subject,
            'preheader' => 'Conocenos antes de la reunión: video de presentación adentro.',
            'title' => '¡Hola ' . $display_name . '!',
            'paragraphs' => $paragraphs,
            'detail_lines' => $detail_lines,
            'closing' => 'Nos vemos en la reunión.',
            'avatar_url' => $avatar_url ?: null,
            'presenter_name' => $presenter_name ?: null,
            'presenter_role' => $presenter_role ?: null,
            'video_url' => $video_url ?: null,
            'video_thumbnail_url' => $video_thumb ?: null,
            'video_caption' => $video_caption ?: null,
            'cta' => ($cta_text && $cta_url) ? ['text' => $cta_text, 'url' => $cta_url] : null,
        ]);

        return new ComercioCityMail($payload);
    }

    /**
     * Elige el nombre para personalizar el saludo, priorizando el nombre de
     * contacto y cayendo al nombre de empresa si el primero no está cargado.
     *
     * @param Lead $lead
     *
     * @return string Nombre a mostrar, nunca vacío (último fallback: "Hola")
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

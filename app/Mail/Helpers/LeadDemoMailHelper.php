<?php

namespace App\Mail\Helpers;

use App\Mail\LeadDemoMail;
use App\Models\Lead;
use Carbon\Carbon;

/**
 * Helper que arma el Mailable "Mail 1 - DEMO" para un prospecto.
 *
 * Concentra el mapeo Lead → variables del blade para mantener el
 * controlador limpio y reutilizar la construcción del correo
 * desde cualquier punto del sistema (job, controller, etc.).
 *
 * Datos del acceso:
 * - URL y tienda vienen de la Demo asignada al lead.
 * - Usuario viene del campo `user_name` del lead (asignado en demo setup).
 * - Número de documento y contraseña se toman del `doc_number` del lead.
 * - Videos y WhatsApp vienen de config para ser configurables por entorno.
 */
class LeadDemoMailHelper
{
    /**
     * Construye el LeadDemoMail listo para pasar a Mail::to()->send().
     *
     * @param Lead $lead Prospecto al que se envía el correo de demo.
     *
     * @return LeadDemoMail Mailable listo para enviar.
     */
    public static function build(Lead $lead): LeadDemoMail
    {
        // Nombre preferido para personalizar el saludo.
        $nombre = self::pick_display_name($lead);

        // Formateo del día de la demo en español (ej: "viernes 16 de mayo de 2025").
        $dia = self::format_demo_date($lead->demo_date);

        // Horas de inicio y fin de la demo (texto libre guardado en el lead).
        $hora_inicio = (string) ($lead->demo_start_time ?? '');
        $hora_fin    = (string) ($lead->demo_end_time ?? '');

        // URL del sistema demo y tienda demo vienen de la Demo asignada.
        // Se normalizan para asegurar protocolo absoluto (http/https) en clientes de mail.
        $url_demo_raw   = $lead->demo ? ((string) $lead->demo->erp_spa_url) : '';
        $url_tienda_raw = $lead->demo ? ((string) $lead->demo->ecommerce_spa_url) : '';
        $url_demo       = self::normalize_mail_url($url_demo_raw);
        $url_tienda     = self::normalize_mail_url($url_tienda_raw);

        // Usuario de acceso: campo `user_name` del lead (seteado en demo setup).
        $usuario = (string) ($lead->user_name ?? '');

        // Número de documento del lead: se usa como credencial de ingreso y como contraseña.
        $doc_number = (string) ($lead->doc_number ?? '');
        $password   = $doc_number;
        $url_whatsapp   = (string) config('commerciocity.demo_mail.whatsapp_url', '');
        $presenter_name = (string) config('commerciocity.demo_mail.presenter_name', 'Equipo ComercioCity');
        $presenter_role = (string) config('commerciocity.demo_mail.presenter_role', 'Fundador');
        $logo_url       = (string) config('commerciocity.logo_url', '');

        // URLs de los videos tutoriales desde config.
        $video_intro     = (string) config('commerciocity.demo_mail.video_intro', '');
        $video_stock     = (string) config('commerciocity.demo_mail.video_stock', '');
        $video_ventas    = (string) config('commerciocity.demo_mail.video_ventas', '');
        $video_ecommerce = (string) config('commerciocity.demo_mail.video_ecommerce', '');
        $video_cierre    = (string) config('commerciocity.demo_mail.video_cierre', '');

        // Tutoriales personalizados cargados en el lead (orden ya definido en la relación).
        $lead->loadMissing('personalized_demo_videos');
        $personalized_demo_videos = [];
        foreach ($lead->personalized_demo_videos as $video_row) {
            $normalized_video_url = self::normalize_mail_url((string) ($video_row->video_url ?? ''));
            if ($normalized_video_url === '') {
                continue;
            }
            $title_for_mail = trim((string) ($video_row->title ?? ''));
            if ($title_for_mail === '') {
                $title_for_mail = 'Video personalizado';
            }
            $personalized_demo_videos[] = [
                'title'         => $title_for_mail,
                'description'   => trim((string) ($video_row->description ?? '')),
                'video_url'     => $normalized_video_url,
            ];
        }

        return new LeadDemoMail(
            $nombre,
            $dia,
            $hora_inicio,
            $hora_fin,
            $url_demo,
            $usuario,
            $password,
            $doc_number,
            $url_tienda,
            $url_whatsapp,
            $video_intro,
            $video_stock,
            $video_ventas,
            $video_ecommerce,
            $video_cierre,
            $logo_url,
            $presenter_name,
            $presenter_role,
            $personalized_demo_videos
        );
    }

    /**
     * Elige el nombre para el saludo: contact_name o company_name como fallback.
     *
     * @param Lead $lead
     *
     * @return string Nombre a mostrar, nunca vacío.
     */
    private static function pick_display_name(Lead $lead): string
    {
        if (!empty($lead->contact_name)) {
            return trim($lead->contact_name);
        }
        if (!empty($lead->company_name)) {
            return trim($lead->company_name);
        }

        return 'Cliente';
    }

    /**
     * Formatea la fecha de la demo en español largo.
     * Ejemplo: "viernes 16 de mayo de 2025".
     *
     * @param mixed $demo_date Fecha del lead (Carbon, string ISO o null).
     *
     * @return string Fecha formateada o cadena vacía si no hay fecha.
     */
    private static function format_demo_date($demo_date): string
    {
        if (empty($demo_date)) {
            return '';
        }

        try {
            // Convertir a Carbon si viene como string.
            $carbon = $demo_date instanceof \DateTimeInterface
                ? Carbon::instance($demo_date)
                : Carbon::parse($demo_date);

            // Locale en español para nombres de días y meses.
            $carbon->locale('es');

            return $carbon->isoFormat('dddd D [de] MMMM [de] YYYY');
        } catch (\Throwable $e) {
            // Fallback: devolver el valor crudo si no se puede formatear.
            return (string) $demo_date;
        }
    }

    /**
     * Normaliza URLs para uso en HTML de email.
     *
     * Reglas:
     * - trim de espacios
     * - si está vacía, devuelve cadena vacía
     * - si ya tiene esquema absoluto (http/https), se respeta
     * - si no tiene esquema, se antepone https://
     *
     * @param string $raw_url URL cruda proveniente de DB o config.
     *
     * @return string URL apta para href en clientes de correo.
     */
    private static function normalize_mail_url(string $raw_url): string
    {
        // Normalizar espacios para evitar href inválidos.
        $normalized_url = trim($raw_url);
        if ($normalized_url === '') {
            return '';
        }

        // Si ya es absoluta (http/https), devolverla tal cual.
        if (preg_match('/^https?:\/\//i', $normalized_url)) {
            return $normalized_url;
        }

        // Fallback seguro para enlaces sin protocolo.
        return 'https://' . ltrim($normalized_url, '/');
    }
}

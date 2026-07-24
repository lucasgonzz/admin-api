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
     * Delega todo el mapeo Lead -> variables en build_view_data() para que el mail y la
     * landing pública (prompt 213) compartan una única fuente de datos y no se puedan
     * desincronizar entre sí.
     *
     * @param Lead $lead Prospecto al que se envía el correo de demo.
     *
     * @return LeadDemoMail Mailable listo para enviar.
     */
    public static function build(Lead $lead): LeadDemoMail
    {
        // Mapeo Lead -> array asociativo, reusado también por la landing pública.
        $data = self::build_view_data($lead);

        return new LeadDemoMail(
            $data['nombre'],
            $data['dia'],
            $data['hora_inicio'],
            $data['hora_fin'],
            $data['url_demo'],
            $data['usuario'],
            $data['password'],
            $data['doc_number'],
            $data['url_tienda'],
            $data['url_whatsapp'],
            $data['video_intro'],
            $data['video_stock'],
            $data['video_ventas'],
            $data['video_ecommerce'],
            $data['video_cierre'],
            $data['logo_url'],
            $data['presenter_name'],
            $data['presenter_role'],
            $data['personalized_demo_videos'],
            $data['url_landing']
        );
    }

    /**
     * Mapea el Lead a un array asociativo con exactamente las mismas claves que hoy
     * expone `LeadDemoMail` como propiedades públicas.
     *
     * Fuente única de datos: la usan tanto `build()` (Mail 1) como
     * `DemoLandingController` (landing pública del prompt 213), para que ambos canales
     * muestren siempre la misma información.
     *
     * @param Lead $lead Prospecto del que se arma la data.
     *
     * @return array<string, mixed> Datos listos para el blade del mail o de la landing.
     */
    public static function build_view_data(Lead $lead): array
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
        // URL de la landing pública de la demo (prompt 213/02): link de respaldo dentro del
        // Mail 1 y dato mas del bloque de acceso para el agente de WhatsApp. Si el lead no
        // tiene `uuid` cargado (registros viejos, previos a la migración), queda vacía y no
        // rompe: tanto el blade como el contexto del agente la ocultan con un chequeo simple.
        $url_landing = !empty($lead->uuid) ? route('demo.landing', ['uuid' => $lead->uuid]) : '';
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

        return [
            'nombre'                   => $nombre,
            'dia'                      => $dia,
            'hora_inicio'              => $hora_inicio,
            'hora_fin'                 => $hora_fin,
            'url_demo'                 => $url_demo,
            'usuario'                  => $usuario,
            'password'                 => $password,
            'doc_number'               => $doc_number,
            'url_tienda'               => $url_tienda,
            'url_whatsapp'             => $url_whatsapp,
            'video_intro'              => $video_intro,
            'video_stock'              => $video_stock,
            'video_ventas'             => $video_ventas,
            'video_ecommerce'          => $video_ecommerce,
            'video_cierre'             => $video_cierre,
            'logo_url'                 => $logo_url,
            'presenter_name'           => $presenter_name,
            'presenter_role'           => $presenter_role,
            'personalized_demo_videos' => $personalized_demo_videos,
            'url_landing'              => $url_landing,
        ];
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

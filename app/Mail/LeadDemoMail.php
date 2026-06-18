<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Correo "Mail 1 - DEMO" enviado al prospecto cuando su demo está lista.
 *
 * Contiene acceso al sistema, links de tutoriales, tienda demo y datos de
 * contacto del equipo. Tiene su propio blade template con diseño específico
 * (no usa ComercioCityMail/ComercioCityMailPayload por tener estructura propia).
 *
 * Todas las propiedades son públicas para que el blade las acceda directamente
 * sin necesidad de pasarlas por `with()`.
 */
class LeadDemoMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var string Nombre del prospecto para el saludo personalizado. */
    public $nombre;

    /** @var string Día de la demo formateado en español (ej: "viernes 16 de mayo"). */
    public $dia;

    /** @var string Hora de inicio de la demo (ej: "09:00"). */
    public $hora_inicio;

    /** @var string Hora de fin de la demo (ej: "10:00"). */
    public $hora_fin;

    /** @var string URL del sistema demo (ERP SPA). */
    public $url_demo;

    /** @var string Usuario de acceso al sistema demo. */
    public $usuario;

    /** @var string Contraseña de acceso al sistema demo. */
    public $password;

    /** @var string Número de documento del lead para ingresar al sistema demo. */
    public $doc_number;

    /** @var string URL de la tienda ecommerce demo. */
    public $url_tienda;

    /** @var string URL de WhatsApp del equipo para consultas. */
    public $url_whatsapp;

    /** @var string URL del video introductorio (botón destacado). */
    public $video_intro;

    /** @var string URL del video tutorial de stock. */
    public $video_stock;

    /** @var string URL del video tutorial de ventas. */
    public $video_ventas;

    /** @var string URL del video tutorial de ecommerce. */
    public $video_ecommerce;

    /** @var string URL del video tutorial de cierre. */
    public $video_cierre;

    /** @var string URL del logo ComercioCity para el header. */
    public $logo_url;

    /** @var string Nombre del firmante del mail. */
    public $presenter_name;

    /** @var string Cargo del firmante del mail. */
    public $presenter_role;

    /**
     * Tutoriales personalizados para el prospecto (título, descripción, URL).
     *
     * @var array<int, array{title: string, description: string, video_url: string}>
     */
    public $personalized_demo_videos;

    /**
     * @param string $nombre         Nombre del prospecto.
     * @param string $dia            Día formateado de la demo.
     * @param string $hora_inicio    Hora de inicio.
     * @param string $hora_fin       Hora de fin.
     * @param string $url_demo       URL del sistema demo.
     * @param string $usuario        Usuario de acceso.
     * @param string $password       Contraseña de acceso.
     * @param string $doc_number     Número de documento del lead (usuario de ingreso).
     * @param string $url_tienda     URL de la tienda demo.
     * @param string $url_whatsapp   URL de WhatsApp.
     * @param string $video_intro    URL del video intro.
     * @param string $video_stock    URL del video stock.
     * @param string $video_ventas   URL del video ventas.
     * @param string $video_ecommerce URL del video ecommerce.
     * @param string $video_cierre   URL del video cierre.
     * @param string $logo_url       URL del logo.
     * @param string $presenter_name Nombre del firmante.
     * @param string $presenter_role Cargo del firmante.
     * @param array<int, array{title: string, description: string, video_url: string}> $personalized_demo_videos Filas para la sección de tutoriales a medida.
     */
    public function __construct(
        string $nombre,
        string $dia,
        string $hora_inicio,
        string $hora_fin,
        string $url_demo,
        string $usuario,
        string $password,
        string $doc_number,
        string $url_tienda,
        string $url_whatsapp,
        string $video_intro,
        string $video_stock,
        string $video_ventas,
        string $video_ecommerce,
        string $video_cierre,
        string $logo_url,
        string $presenter_name,
        string $presenter_role,
        array $personalized_demo_videos = []
    ) {
        $this->nombre          = $nombre;
        $this->dia             = $dia;
        $this->hora_inicio     = $hora_inicio;
        $this->hora_fin        = $hora_fin;
        $this->url_demo        = $url_demo;
        $this->usuario         = $usuario;
        $this->password        = $password;
        $this->doc_number      = $doc_number;
        $this->url_tienda      = $url_tienda;
        $this->url_whatsapp    = $url_whatsapp;
        $this->video_intro     = $video_intro;
        $this->video_stock     = $video_stock;
        $this->video_ventas    = $video_ventas;
        $this->video_ecommerce = $video_ecommerce;
        $this->video_cierre    = $video_cierre;
        $this->logo_url        = $logo_url;
        $this->presenter_name  = $presenter_name;
        $this->presenter_role  = $presenter_role;
        $this->personalized_demo_videos = $personalized_demo_videos;
    }

    /**
     * Construye el Mailable fijando el asunto y delegando render al blade dedicado.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Tu demo de ComercioCity está lista, ' . $this->nombre . ' 🚀')
            ->view('emails.lead.demo_mail');
    }
}

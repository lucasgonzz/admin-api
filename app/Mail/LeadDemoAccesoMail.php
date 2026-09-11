<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * La "carta de acceso" a la demo: el mail con las dos llaves del lead.
 *
 * Sale cuando el lead acepta hacer la demo por WhatsApp (misión demo-agendado-directo). Por el
 * chat ya le llegan los dos links, pero el lead suele estar en el teléfono y después se sienta en
 * la computadora sin tener cómo entrar: este mail es la llave que se lleva a la compu. Lleva
 * exactamente dos accesos —la página de experiencia de su demo (`/experiencia/{clave}` de
 * admin-spa) y la tienda online conectada a esa misma instancia— y nada más.
 *
 * 🔴 POR QUÉ NO REUTILIZA `LeadDemoMail`. Ese es el "Mail 1 - DEMO" de la dinámica anterior:
 * fecha y hora de la demo, usuario y contraseña, cuatro videos tutoriales y los personalizados.
 * En esta dinámica no hay nada de eso —no hay turno, no hay credenciales (se entra desde la página
 * de experiencia con un botón) y no hay lista de videos—, así que reusarlo era vaciar diecinueve
 * parámetros para mostrar dos. Y el diseño es otro a propósito: Lucas pidió que sea "parte de la
 * experiencia", con la identidad de la página de experiencia (Geist, azul `#0b84f8`, degradé
 * azul→violeta, estilo Apple), no el header azul oscuro con caja de credenciales del mail viejo.
 *
 * Todas las propiedades son públicas para que el blade las lea directo, mismo criterio que
 * `LeadDemoMail`. El mapeo Lead → propiedades vive en `LeadDemoAccesoMailHelper::build()`.
 */
class LeadDemoAccesoMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var string Primer nombre del lead para el saludo. Vacío = el blade saluda "Hola." a secas. */
    public $nombre;

    /** @var string URL de la página de experiencia de la demo (llave 1: sistema de gestión). */
    public $url_experiencia;

    /** @var string URL de la tienda online demo (llave 2). Vacía = la llave 2 no se muestra. */
    public $url_tienda;

    /** @var string URL de WhatsApp del equipo para consultas. */
    public $url_whatsapp;

    /** @var string URL del logo para el header; vacía = se muestra el nombre en texto. */
    public $logo_url;

    /** @var string Nombre del firmante del mail. */
    public $presenter_name;

    /** @var string Cargo del firmante del mail. */
    public $presenter_role;

    /**
     * @param string $nombre          Primer nombre del lead (puede venir vacío).
     * @param string $url_experiencia URL de la página de experiencia de la demo.
     * @param string $url_tienda      URL de la tienda demo, o '' si la instancia no tiene tienda.
     * @param string $url_whatsapp    URL de WhatsApp del equipo.
     * @param string $logo_url        URL del logo, o '' para caer al nombre en texto.
     * @param string $presenter_name  Nombre del firmante.
     * @param string $presenter_role  Cargo del firmante.
     */
    public function __construct(
        string $nombre,
        string $url_experiencia,
        string $url_tienda,
        string $url_whatsapp,
        string $logo_url,
        string $presenter_name,
        string $presenter_role
    ) {
        $this->nombre          = $nombre;
        $this->url_experiencia = $url_experiencia;
        $this->url_tienda      = $url_tienda;
        $this->url_whatsapp    = $url_whatsapp;
        $this->logo_url        = $logo_url;
        $this->presenter_name  = $presenter_name;
        $this->presenter_role  = $presenter_role;
    }

    /**
     * Fija el asunto y delega el render al blade dedicado.
     *
     * El asunto lleva el nombre solo si hay nombre: "Tus llaves de acceso a ComercioCity, Guillermo".
     * Sin nombre queda "Tus llaves de acceso a ComercioCity", sin una coma colgando.
     *
     * @return $this
     */
    public function build()
    {
        $asunto = 'Tus llaves de acceso a ComercioCity';
        if ($this->nombre !== '') {
            $asunto .= ', ' . $this->nombre;
        }

        return $this->subject($asunto)
            ->view('emails.lead.demo_acceso');
    }
}

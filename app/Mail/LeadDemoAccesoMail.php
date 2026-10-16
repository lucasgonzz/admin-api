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
 * En esta dinámica no hay nada de eso —no hay turno agendado, no hay credenciales (se entra desde la
 * página de experiencia con un botón) y no hay lista de videos—, así que reusarlo era vaciar
 * diecinueve parámetros para mostrar dos. Y el diseño es otro a propósito: Lucas pidió que sea "parte
 * de la experiencia", con la identidad de la página de experiencia (Geist, azul `#0b84f8`, degradé
 * azul→violeta, estilo Apple), no el header azul oscuro con caja de credenciales del mail viejo.
 *
 * ## Rediseño del 16/10/2026 (misión mail-demo-turno-y-asistente)
 *
 * El mail dejó de ser solo "las dos llaves": ahora lleva (1) el TURNO real de la demo —día, hora de
 * inicio y hora de fin, los mismos que el agente le dijo por el chat—, (2) qué es la instancia
 * (completa: gestión, tienda online y sistema de agentes), (3) cómo aprovecharla, y (4) si el
 * número del asistente personal está disponible, cómo probarlo durante el turno. Los tres datos
 * nuevos son OPCIONALES al final del constructor: un llamador viejo que arme el mail con los siete
 * argumentos de antes obtiene el mail de siempre, sin turno y sin asistente.
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
     * @var array|null Turno de la demo en palabras (`DemoTurnoTexto::de_lead()`): `fecha_larga`,
     *                 `es_hoy`, `hora_inicio`, `hora_fin`, `reserva`, `duracion`,
     *                 `cuando_para_el_asunto`. Null = el lead no tiene un turno vigente y legible:
     *                 el mail sale sin bloque de turno y con el asunto de siempre.
     */
    public $turno;

    /** @var string Número del asistente personal como se lee. Vacío = el bloque del asistente no se muestra. */
    public $asistente_numero;

    /** @var string Enlace `wa.me` al asistente. Vacío = el bloque del asistente no se muestra. */
    public $asistente_url;

    /**
     * @var bool Si el blade muestra el bloque del asistente personal: hace falta el número Y el enlace.
     *           Un número sin enlace (o al revés) es un bloque a medias que el lead no puede usar, y se
     *           omite entero. Se calcula una sola vez, acá, y el blade solo lo lee.
     */
    public $mostrar_asistente;

    /**
     * @param string     $nombre           Primer nombre del lead (puede venir vacío).
     * @param string     $url_experiencia  URL de la página de experiencia de la demo.
     * @param string     $url_tienda       URL de la tienda demo, o '' si la instancia no tiene tienda.
     * @param string     $url_whatsapp     URL de WhatsApp del equipo.
     * @param string     $logo_url         URL del logo, o '' para caer al nombre en texto.
     * @param string     $presenter_name   Nombre del firmante.
     * @param string     $presenter_role   Cargo del firmante.
     * @param array|null $turno            Turno en palabras (`DemoTurnoTexto::de_lead()`), o null.
     * @param string     $asistente_numero Número del asistente como se lee, o '' si no está disponible.
     * @param string     $asistente_url    Enlace `wa.me` al asistente, o '' si no está disponible.
     */
    public function __construct(
        string $nombre,
        string $url_experiencia,
        string $url_tienda,
        string $url_whatsapp,
        string $logo_url,
        string $presenter_name,
        string $presenter_role,
        ?array $turno = null,
        string $asistente_numero = '',
        string $asistente_url = ''
    ) {
        $this->nombre           = $nombre;
        $this->url_experiencia  = $url_experiencia;
        $this->url_tienda       = $url_tienda;
        $this->url_whatsapp     = $url_whatsapp;
        $this->logo_url         = $logo_url;
        $this->presenter_name   = $presenter_name;
        $this->presenter_role   = $presenter_role;
        $this->turno            = $turno;
        $this->asistente_numero = $asistente_numero;
        $this->asistente_url    = $asistente_url;

        $this->mostrar_asistente = trim($asistente_numero) !== '' && trim($asistente_url) !== '';
    }

    /**
     * Fija el asunto y delega el render al blade dedicado.
     *
     * Con turno, el asunto es el del mail de agendamiento aprobado (`contexto/demo_pagina.md` §5):
     * "Tu demo de ComercioCity — hoy a las 15:10". El día y la hora son lo que el lead busca cuando
     * vuelve a su casilla, y no llevan el nombre.
     *
     * Sin turno (un reenvío cuando el turno ya terminó, o un lead sin hora cargada) se conserva el
     * asunto de siempre, que lleva el nombre solo si hay nombre: "Tus llaves de acceso a
     * ComercioCity, Guillermo". Sin nombre queda "Tus llaves de acceso a ComercioCity", sin una coma
     * colgando.
     *
     * @return $this
     */
    public function build()
    {
        if (is_array($this->turno) && ! empty($this->turno['cuando_para_el_asunto'])) {
            $asunto = 'Tu demo de ComercioCity — ' . $this->turno['cuando_para_el_asunto'];
        } else {
            $asunto = 'Tus llaves de acceso a ComercioCity';
            if ($this->nombre !== '') {
                $asunto .= ', ' . $this->nombre;
            }
        }

        return $this->subject($asunto)
            ->view('emails.lead.demo_acceso');
    }
}

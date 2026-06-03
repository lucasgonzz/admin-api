<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable del "Mail 2 - Propuesta" orientado a cierre comercial.
 *
 * Este correo usa un blade dedicado con estructura visual específica para
 * presentar valor, comparativa de mercado, precio con urgencia y CTA
 * principal por WhatsApp. No incluye financiación ni cuotas.
 */
class LeadProposalMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var string Nombre del lead para saludo personalizado. */
    public $nombre;

    /** @var array<int, string> Lista de ítems incluidos en el servicio. */
    public $lista_items_que_incluye_el_servicio;

    /** @var int Precio de lista de referencia (tachado). */
    public $precio_base;

    /** @var int Precio final con el bono de acción rápida. */
    public $precio_descuento;

    /** @var int Ahorro obtenido respecto al precio de lista. */
    public $ahorro;

    /** @var string URL de WhatsApp para CTA principal y footer. */
    public $url_whatsapp;

    /**
     * Fecha y hora de vencimiento del bono en formato legible en español.
     * Calculada al momento del envío: now() + urgency_hours (config) en zona Buenos Aires.
     * Ejemplo: "viernes 16 de mayo a las 23:59 hs".
     *
     * @var string
     */
    public $fecha_vencimiento;

    /** @var string URL del logo de marca. */
    public $logo_url;

    /** @var string Nombre del firmante en footer. */
    public $presenter_name;

    /** @var string Cargo del firmante en footer. */
    public $presenter_role;

    /**
     * Testimonios en formato tarjeta: nombre del negocio, video e Instagram.
     *
     * @var array<int, array<string, string>>
     */
    public $lista_testimonios;

    /**
     * @param string $nombre Nombre del lead.
     * @param array<int, string> $lista_items_que_incluye_el_servicio Ítems incluidos.
     * @param int $precio_base Precio de lista (tachado).
     * @param int $precio_descuento Precio con bono de acción rápida.
     * @param int $ahorro Ahorro total respecto al precio de lista.
     * @param string $url_whatsapp Link de acción.
     * @param string $logo_url Logo de marca.
     * @param string $presenter_name Nombre del firmante.
     * @param string $presenter_role Cargo del firmante.
     * @param array<int, array<string, string>> $lista_testimonios Filas con business_name, video_url, instagram_url.
     */
    public function __construct(
        string $nombre,
        array $lista_items_que_incluye_el_servicio,
        int $precio_base,
        int $precio_descuento,
        int $ahorro,
        string $url_whatsapp,
        string $logo_url,
        string $presenter_name,
        string $presenter_role,
        array $lista_testimonios
    ) {
        $this->nombre = $nombre;
        $this->lista_items_que_incluye_el_servicio = $lista_items_que_incluye_el_servicio;
        $this->precio_base = $precio_base;
        $this->precio_descuento = $precio_descuento;
        $this->ahorro = $ahorro;
        $this->url_whatsapp = $url_whatsapp;
        $this->logo_url = $logo_url;
        $this->presenter_name = $presenter_name;
        $this->presenter_role = $presenter_role;
        $this->lista_testimonios = $lista_testimonios;

        /*
         * Calcular fecha de vencimiento del bono al momento exacto del envío.
         * Se toman las horas de urgencia desde config (default 48) y se suman a now()
         * en zona horaria Argentina para mostrar la fecha exacta en el correo.
         */
        $horas_urgencia = (int) config('commerciocity.proposal_mail.urgency_hours', 48);
        $this->fecha_vencimiento = now('America/Argentina/Buenos_Aires')
            ->addHours($horas_urgencia)
            ->locale('es')
            ->translatedFormat('l j \d\e F \a \l\a\s H:i \h\s');
    }

    /**
     * Construye el mailable con asunto de cierre y vista dedicada.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Propuesta final para implementar ComercioCity')
            ->view('emails.lead.proposal_mail');
    }
}

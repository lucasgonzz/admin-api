<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

/**
 * El mail de un hito de la implementación: lo que el cliente recibe en cada paso del arranque de su
 * sistema (misión implementar-cliente, 5/10/2026).
 *
 * Hay seis hitos —bienvenida, instalado, acceso, imagenes, categorias, listo— y los seis salen con
 * el mismo diseño y la misma línea de progreso de las ocho etapas. El diseño es el del prototipo
 * aprobado y comparte lenguaje con la carta de acceso a la demo (`LeadDemoAccesoMail`): Geist,
 * tarjeta blanca de 560 px, azul y violeta de la marca, el degradé solo en el botón principal y el
 * naranja una sola vez, en el acento del titular.
 *
 * OJO: este mailable NO arma nada: recibe la vista ya hecha. Quien decide qué lleva cada hito, de
 * dónde sale cada dato y cómo se formatea es `ImplementacionMailHelper::vista()`, y quien lo manda y
 * lo registra es `ImplementacionMailService`. Acá solo se eligen las dos vistas y se fija el asunto.
 *
 * Las propiedades son públicas por el mismo motivo que en `LeadDemoAccesoMail`: Laravel pasa las
 * públicas a la vista. Lo que ve la vista es el contenido de `$vista`, que se pasa además con
 * `with()` para que cada clave sea una variable suelta (`$titular`, `$progreso`, ...).
 *
 * Sale siempre por el mailer `admin` (`admin@comerciocity.com`): lo elige quien lo manda, con
 * `Mail::mailer('admin')`, no el Mailable.
 */
class ImplementacionMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var string Hito del mail: bienvenida | instalado | acceso | imagenes | categorias | listo. */
    public $hito;

    /** @var string Asunto con el que sale. */
    public $asunto;

    /**
     * Todo lo que ven las vistas, ya formateado (ver `ImplementacionMailHelper::vista()`).
     *
     * @var array<string, mixed>
     */
    public $vista;

    /**
     * @param string               $hito   Hito del mail.
     * @param string               $asunto Asunto con el que sale.
     * @param array<string, mixed> $vista  Lo que ven las vistas.
     */
    public function __construct(string $hito, string $asunto, array $vista)
    {
        $this->hito   = $hito;
        $this->asunto = $asunto;
        $this->vista  = $vista;
    }

    /**
     * Fija el asunto y las dos versiones del mail: la HTML de la tarjeta y la de texto plano.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject($this->asunto)
            ->view('emails.implementacion.hito')
            ->text($this->texto_plano())
            ->with($this->vista);
    }

    /**
     * La versión de texto plano, ya renderizada.
     *
     * OJO: por qué se renderiza acá y no se le pasa el nombre de la vista a `text()`: la vista de texto
     * imprime todo con `{{ }}`, igual que la HTML, así que ningún dato sale sin escapar. Pero en un
     * texto plano el escape de HTML es un defecto: "Pinturas & Más" saldría como "Pinturas &amp;
     * Más". El `html_entity_decode` deshace EXACTAMENTE lo que hizo el escape y nada más (la vista
     * de texto no tiene entidades propias), así que lo que llega es el texto tal cual se cargó.
     *
     * @return HtmlString
     */
    private function texto_plano(): HtmlString
    {
        $renderizado = view('emails.implementacion.hito_texto', $this->vista)->render();

        return new HtmlString(html_entity_decode($renderizado, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}

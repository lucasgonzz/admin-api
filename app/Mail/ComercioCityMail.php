<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Correo HTML reutilizable con layout ComercioCity (header / body / footer).
 *
 * Replicado 1 a 1 del mismo Mailable disponible en empresa-api, de modo que
 * cualquier mail nuevo que se dispare desde admin-api herede el mismo
 * look-and-feel corporativo y podamos mantener un único patrón en todo el
 * workspace.
 *
 * Uso típico:
 *
 *   use Illuminate\Support\Facades\Mail;
 *
 *   if ($lead->email) {
 *       Mail::to($lead->email)->send(new ComercioCityMail(
 *           new ComercioCityMailPayload([
 *               'subject' => 'Hola!',
 *               'title' => 'Titulo del mail',
 *               'paragraphs' => ['Parrafo 1', 'Parrafo 2'],
 *           ])
 *       ));
 *   }
 */
class ComercioCityMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @var ComercioCityMailPayload Contenido estructurado del correo */
    public $payload;

    /**
     * @param ComercioCityMailPayload $payload Payload ya armado con el contenido del correo
     */
    public function __construct(ComercioCityMailPayload $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Construye el Mailable fijando el asunto tomado del payload y
     * delegando el render al layout ComercioCity.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject($this->payload->subject)
            ->view('emails.commerciocity.layout');
    }
}

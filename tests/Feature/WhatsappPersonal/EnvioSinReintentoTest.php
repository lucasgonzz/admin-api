<?php

namespace Tests\Feature\WhatsappPersonal;

use App\Services\WhatsappSendService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * CA11 (ronda 2) — Un envío por el número personal es UN solo POST a Kapso: ni un timeout de lectura ni un 5xx
 * lo repiten, porque Kapso pudo haber aceptado el mensaje y el reintento lo duplicaría en el WhatsApp del
 * cliente. El resto de los senders conserva su reintento de siempre.
 */
class EnvioSinReintentoTest extends BaseDelNumeroPersonal
{
    /**
     * Principal y personal listos, un agente y la ventana de 24 h abierta.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_principal();
        $this->crear_personal();
        $this->iniciar_agente();
        $this->sembrar_mensaje(['chat_telefono' => self::TEL_CLIENTE, 'sent_at' => now()->subMinutes(30)]);
    }

    /**
     * Un timeout de lectura hace exactamente una llamada a Kapso y deja la fila `incierto`.
     *
     * @return void
     */
    public function test_timeout_hace_una_sola_llamada_y_queda_incierto(): void
    {
        // Una excepción lanzada por el fake no queda en Http::recorded(), así que las llamadas se cuentan acá.
        $llamadas = 0;
        $this->fakear_http([
            $this->url_de_envio(self::PNI_PERSONAL) => function () use (&$llamadas) {
                $llamadas++;

                throw new ConnectionException('cURL error 28: timeout de lectura');
            },
        ]);

        $this->enviar($this->cuerpo_de_envio())->assertStatus(502);

        $this->assertSame(1, $llamadas, 'Un timeout no se reintenta: el mensaje pudo haber salido.');
        $this->assertSame('incierto', DB::table('whatsapp_personal_messages')->where('direccion', 'out')->value('estado'));
    }

    /**
     * Un 500 hace exactamente una llamada a Kapso y deja la fila `incierto`.
     *
     * @return void
     */
    public function test_un_500_hace_una_sola_llamada_y_queda_incierto(): void
    {
        $this->fakear_http([
            $this->url_de_envio(self::PNI_PERSONAL) => Http::response(['error' => 'boom'], 500),
        ]);

        $this->enviar($this->cuerpo_de_envio())->assertStatus(502);

        Http::assertSentCount(1);
        $this->assertSame('incierto', DB::table('whatsapp_personal_messages')->where('direccion', 'out')->value('estado'));
    }

    /**
     * Lo mismo para una plantilla (fuera de la ventana de 24 h).
     *
     * @return void
     */
    public function test_plantilla_con_500_hace_una_sola_llamada(): void
    {
        $this->fakear_http([
            $this->url_de_envio(self::PNI_PERSONAL) => Http::response(['error' => 'boom'], 500),
        ]);

        $sender = new WhatsappSendService();
        $sender->para(\App\Models\WhatsappConfig::delProposito(\App\Models\WhatsappConfig::PROPOSITO_PERSONAL));
        $sender->sin_reintentos();
        $sender->send_template('5493412345678', 'plantilla_x', [], 'es_AR', null);

        Http::assertSentCount(1);
    }

    /**
     * Por defecto el servicio sigue reintentando (el resto de los senders no cambia).
     *
     * @return void
     */
    public function test_por_defecto_el_servicio_sigue_reintentando(): void
    {
        $this->fakear_http([
            $this->url_de_envio(self::PNI_PRINCIPAL) => Http::response(['error' => 'boom'], 500),
        ]);

        (new WhatsappSendService())->send_text('5493412345678', 'Hola', null, true);

        $this->assertGreaterThan(1, count(Http::recorded()), 'Sin pedirlo, send_text reintenta como siempre.');
    }
}

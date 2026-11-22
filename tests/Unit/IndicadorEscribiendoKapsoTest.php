<?php

namespace Tests\Unit;

use App\Models\WhatsappConfig;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El pedido del indicador "escribiendo…" a Kapso (misión asistente-whatsapp-velocidad, etapa 1).
 *
 * El método `WhatsappSendService::send_typing_indicator()` marca el mensaje del dueño como leído y
 * muestra "escribiendo…" con UN `POST` al mismo endpoint que ya se usa para mandar. Es best-effort:
 * sin reintento, sin `context` y sin romper nada si Kapso falla.
 *
 * Va con `Tests\TestCase` (y no con el de PHPUnit pelado) porque necesita la app: la `WhatsappConfig`
 * se lee de la base y el pedido sale por la fábrica HTTP de Laravel, que acá está fakeada.
 */
class IndicadorEscribiendoKapsoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * `phone_number_id` de la config activa de las pruebas.
     */
    const PHONE_NUMBER_ID = '123456789';

    /**
     * Nada sale a la red: se cambia la fábrica HTTP por una limpia, con una respuesta 200 por defecto.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.x']]], 200)]);
    }

    /**
     * Crea la configuración de Kapso con la que sale el indicador.
     *
     * @param bool $test_mode Si el número está en modo de prueba (no manda nada de verdad).
     *
     * @return WhatsappConfig
     */
    private function crear_config(bool $test_mode = false): WhatsappConfig
    {
        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-kapso-de-prueba';
        $config->phone_number_id = self::PHONE_NUMBER_ID;
        $config->webhook_secret  = 'secreto';
        $config->is_active       = true;
        $config->test_mode       = $test_mode;
        $config->save();

        return $config;
    }

    /**
     * Deja un fallo claro (y no un error fatal) si el método todavía no existe.
     *
     * @return void
     */
    private function exigir_el_metodo(): void
    {
        $this->assertTrue(
            method_exists(WhatsappSendService::class, 'send_typing_indicator'),
            'WhatsappSendService todavía no tiene send_typing_indicator().'
        );
    }

    /**
     * CA5. El pedido es el que documenta Kapso: URL, cuerpo exacto, clave y nada más.
     *
     * @return void
     */
    public function test_el_pedido_es_el_documentado_por_kapso(): void
    {
        $this->exigir_el_metodo();
        $this->crear_config();

        $resultado = (new WhatsappSendService())->send_typing_indicator('wamid.ABC');

        $this->assertTrue($resultado, 'Con un 200 de Kapso devuelve true.');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.kapso.ai/meta/whatsapp/v24.0/' . self::PHONE_NUMBER_ID . '/messages'
                && $request->hasHeader('X-API-Key', 'clave-kapso-de-prueba')
                && $request->body() === '{"messaging_product":"whatsapp","status":"read","message_id":"wamid.ABC","typing_indicator":{"type":"text"}}'
                && ! array_key_exists('context', $request->data());
        });
    }

    /**
     * CA5. Sin contexto de envío (modo de prueba) o sin wamid no sale a la red.
     *
     * @return void
     */
    public function test_sin_contexto_no_sale_a_la_red(): void
    {
        $this->exigir_el_metodo();

        /* Modo de prueba: no se manda nada de verdad. */
        $config = $this->crear_config(true);

        $this->assertFalse((new WhatsappSendService())->send_typing_indicator('wamid.ABC'));
        Http::assertNothingSent();

        /* Config activa y real, pero sin wamid al que aplicarlo. */
        $config->test_mode = false;
        $config->save();

        $this->assertFalse((new WhatsappSendService())->send_typing_indicator(''));
        Http::assertNothingSent();
    }

    /**
     * CA5. Un 500 de Kapso no se reintenta: un solo pedido y devuelve false.
     *
     * El indicador es opcional y el turno no puede esperar por él: el pedido tiene timeout corto y
     * ningún `retry()`.
     *
     * @return void
     */
    public function test_un_500_no_se_reintenta(): void
    {
        $this->exigir_el_metodo();
        $this->crear_config();

        Http::swap(new Factory());
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->assertFalse((new WhatsappSendService())->send_typing_indicator('wamid.ABC'));

        Http::assertSentCount(1);
    }
}

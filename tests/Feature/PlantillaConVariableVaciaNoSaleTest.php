<?php

namespace Tests\Feature;

use App\Models\WhatsappConfig;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El guard central: una plantilla con una variable vacía no sale a la red.
 *
 * `if (! empty($variables))` parecía cubrir el caso y no lo cubría: `['']` tiene un elemento, así
 * que pasaba el chequeo, se armaba el componente `body` y viajaba un parámetro de texto vacío. Para
 * Meta un parámetro de texto vacío ES un parámetro que falta — responde
 * `(#131008) Required parameter is missing` y descarta el mensaje.
 *
 * Este es el punto único por el que pasan TODOS los llamadores de plantillas (seguimientos,
 * comandos de demo, recordatorios, envíos de Claude, envíos desde el panel), así que la guarda se
 * verifica acá y no en cada llamador.
 */
class PlantillaConVariableVaciaNoSaleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Deja una configuración de WhatsApp activa y REAL (no test_mode).
     *
     * Es a propósito: si el corte dependiera de la configuración, el test estaría midiendo otra
     * cosa. Con la configuración activa, lo único que puede frenar el envío es la guarda.
     *
     * 🔴 El `Http::fake()` NO va acá. Los stubs de la factory se resuelven por el primero que
     * matchea, así que un catch-all registrado en el setUp le gana a cualquier respuesta que el
     * test registre después y todos los envíos vuelven con body vacío. Cada test arma el suyo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        WhatsappConfig::query()->update(['is_active' => false]);

        $config                  = new WhatsappConfig();
        $config->kapso_api_key   = 'clave-de-prueba';
        $config->phone_number_id = '1234567890';
        $config->webhook_secret  = 'secreto-de-prueba';
        $config->is_active       = true;
        $config->test_mode       = false;
        $config->save();
    }

    /**
     * Una variable vacía corta el envío antes de salir y deja el motivo escrito.
     *
     * @return void
     */
    public function test_una_variable_vacia_corta_el_envio_antes_de_la_red()
    {
        Http::fake();

        $sender = new WhatsappSendService();

        $resultado = $sender->send_template('+5493411112233', 'cc_seg_nuevo_d2', ['']);

        $this->assertNull($resultado, 'Con una variable vacía el envío no puede devolver un message_id.');
        Http::assertNothingSent();
    }

    /**
     * El motivo es legible y nombra la plantilla y la posición del placeholder que llegó vacío.
     *
     * Ese texto termina en `lead_messages.whatsapp_send_error`: es lo único que le queda al humano
     * (y a Claude) para entender por qué el seguimiento no salió.
     *
     * @return void
     */
    public function test_el_motivo_nombra_la_plantilla_y_el_placeholder()
    {
        Http::fake();

        $sender = new WhatsappSendService();

        $sender->send_template('+5493411112233', 'cc_seg_nuevo_d2', ['']);

        $this->assertNotNull($sender->last_send_error, 'La guarda tiene que dejar el motivo del fallo.');
        $this->assertStringContainsString('cc_seg_nuevo_d2', (string) $sender->last_send_error);
        $this->assertStringContainsString('{{1}}', (string) $sender->last_send_error);
        $this->assertStringContainsString('131008', (string) $sender->last_send_error);
    }

    /**
     * Un valor de puros espacios es tan vacío como `''` para Meta: también corta.
     *
     * @return void
     */
    public function test_una_variable_de_puros_espacios_tambien_corta()
    {
        Http::fake();

        $sender = new WhatsappSendService();

        $this->assertNull($sender->send_template('+5493411112233', 'cc_seg_nuevo_d2', ['   ']));
        Http::assertNothingSent();
    }

    /**
     * La guarda apunta a la posición real: si el hueco es el {{2}}, el motivo dice {{2}}.
     *
     * @return void
     */
    public function test_el_motivo_apunta_a_la_posicion_real_del_hueco()
    {
        Http::fake();

        $sender = new WhatsappSendService();

        $sender->send_template('+5493411112233', 'cc_recuperacion_motivo', ['Marina', '']);

        $this->assertStringContainsString('{{2}}', (string) $sender->last_send_error);
    }

    /**
     * Con todas las variables cargadas la guarda no se mete: el envío sigue su camino normal.
     *
     * @return void
     */
    public function test_con_las_variables_cargadas_la_guarda_no_corta()
    {
        Http::fake([
            '*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200),
        ]);

        $sender = new WhatsappSendService();

        $resultado = $sender->send_template('+5493411112233', 'cc_seg_nuevo_d2', ['Marina']);

        $this->assertSame('wamid.OK', $resultado);
        $this->assertNull($sender->last_send_error);
    }

    /**
     * Una plantilla sin variables tampoco se ve afectada: no hay nada que validar.
     *
     * @return void
     */
    public function test_una_plantilla_sin_variables_sigue_saliendo()
    {
        Http::fake([
            '*' => Http::response(['messages' => [['id' => 'wamid.SINVARS']]], 200),
        ]);

        $sender = new WhatsappSendService();

        $this->assertSame('wamid.SINVARS', $sender->send_template('+5493411112233', 'cc_aviso_simple', []));
    }
}

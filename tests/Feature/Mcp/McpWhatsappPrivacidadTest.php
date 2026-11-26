<?php

namespace Tests\Feature\Mcp;

use Illuminate\Support\Facades\DB;
use Tests\Feature\WhatsappPersonal\BaseDelNumeroPersonal;

/**
 * CA10 de la misión dotin-whatsapp-admin: ni los logs ni la tabla de códigos de confirmación guardan el texto de
 * los mensajes, el pedido literal de Lucas ni el teléfono.
 *
 * El texto de un mensaje de WhatsApp es dato personal: viaja en la respuesta a ChatGPT y en la tabla propia de
 * mensajes del número personal, y en ningún lado más.
 */
class McpWhatsappPrivacidadTest extends BaseDelNumeroPersonal
{
    use McpFixture, McpWhatsappFixture;

    /**
     * Interruptor prendido, el personal listo con su mensaje de prueba y Kapso falso que acepta.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_whatsapp_mcp(true);
        $this->sembrar_cuentas_y_chat_personal();
        $this->kapso_que_acepta();
    }

    /**
     * CA10: preparar, confirmar, reintentar y leer; después ningún log ni la fila del código contienen el texto
     * enviado, el literal de Lucas, el texto recibido ni el teléfono; `request_hash` es el sha256 del literal.
     *
     * @return void
     */
    public function test_ni_logs_ni_codigos_guardan_texto(): void
    {
        $envio   = $this->token_whatsapp($this->admin_de_whatsapp, 'envio')['access_token'];
        $lectura = $this->token_whatsapp($this->admin_de_whatsapp, 'lectura')['access_token'];

        $this->capturar_logs();

        $codigo = $this->preparar_y_obtener_codigo($envio);
        $enviado = $this->datos_en('/api/mcp/whatsapp-envio', $envio, 'whatsapp_confirm_send', $this->confirmacion_de($this->envio_de_prueba(), $codigo));
        $this->assertTrue($enviado['ok']);
        $this->datos_en('/api/mcp/whatsapp-envio', $envio, 'whatsapp_confirm_send', $this->confirmacion_de($this->envio_de_prueba(), $codigo));
        $leido = $this->datos_en('/api/mcp/whatsapp', $lectura, 'whatsapp_get_messages', ['account' => 'personal', 'phone' => '3412345678']);
        $this->assertStringContainsString('Prueba Dotin PERSONAL 4821', json_encode($leido, JSON_UNESCAPED_UNICODE), 'Control: la lectura sí devuelve el texto al cliente.');

        // Los logs: no vacíos (si no se capturó nada la prueba no prueba nada) y sin datos personales.
        $logs = $this->logs_capturados();
        $this->assertStringContainsString('whatsapp_confirm_send', $logs, 'El conector tiene que loguear qué herramienta se llamó.');
        foreach (['Hola, te confirmo el pedido', 'mandale a Juan que le confirmo el pedido', 'PERSONAL 4821', '3412345678', $codigo] as $secreto) {
            $this->assertStringNotContainsString($secreto, $logs, 'Los logs filtran: ' . $secreto);
        }

        // La fila del código: sólo hashes y metadatos, nunca el texto ni el literal; ni el código en claro.
        $filas = DB::table('mcp_whatsapp_confirmations')->get();
        $this->assertCount(1, $filas);
        $json = json_encode($filas[0], JSON_UNESCAPED_UNICODE);
        foreach (['Hola, te confirmo el pedido', 'mandale a Juan que le confirmo el pedido', 'PERSONAL 4821', $codigo] as $secreto) {
            $this->assertStringNotContainsString($secreto, $json, 'La tabla de códigos guarda: ' . $secreto);
        }
        $this->assertSame(hash('sha256', 'mandale a Juan que le confirmo el pedido'), $filas[0]->request_hash);
        $this->assertSame(64, strlen((string) $filas[0]->code_hash), 'Del código sólo se guarda un sha256.');
        $this->assertNotNull($filas[0]->used_at);
    }
}

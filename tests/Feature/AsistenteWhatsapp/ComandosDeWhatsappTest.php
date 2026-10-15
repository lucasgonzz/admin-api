<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AdminSetting;
use App\Models\ClientTemplate;
use App\Models\WhatsappConfig;
use App\Services\AsistenteWhatsappSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * `whatsapp:estado` y `whatsapp:registrar-webhook`: los dos comandos con los que Lucas hace el alta del
 * segundo número y verifica cada paso.
 *
 * Lo que más importa de los dos es lo que NO imprimen: ni la clave de Kapso, ni el secreto del webhook,
 * ni la URL completa de un webhook ajeno (que a veces lleva un token en el path). Son comandos que se
 * corren en una terminal cuya salida queda en el historial.
 */
class ComandosDeWhatsappTest extends BaseDelCanal
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->crear_config_whatsapp();
    }

    /**
     * Corre un comando y devuelve su salida y su código.
     *
     * @param string               $comando
     * @param array<string, mixed> $parametros
     *
     * @return array{0: int, 1: string}
     */
    private function correr(string $comando, array $parametros = []): array
    {
        $codigo = Artisan::call($comando, $parametros);

        return [$codigo, Artisan::output()];
    }

    /**
     * Fakea la API de plataforma de Kapso: health, lista de webhooks del número y el borrado.
     *
     * @param array<int, array<string, mixed>> $webhooks_existentes Lo que Kapso dice que el número ya tiene.
     *
     * @return void
     */
    private function fakear_kapso(array $webhooks_existentes = []): void
    {
        $this->fakear_http([
            '*/webhooks/*' => Http::response('', 204),
            '*/webhooks*'  => Http::response(['data' => $webhooks_existentes], 200),
            '*/health'     => Http::response(['status' => 'healthy'], 200),
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // whatsapp:estado
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin el número del asistente listo, dice qué le falta y que todo sigue por el principal.
     *
     * @return void
     */
    public function test_el_estado_dice_que_falta_y_no_imprime_secretos(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);

        list($codigo, $salida) = $this->correr('whatsapp:estado', ['--sin-red' => true]);

        $this->assertSame(1, $codigo, 'El asistente no está listo: el código de salida lo dice.');
        $this->assertStringContainsString('NO está listo. Falta: webhook_secret', $salida);
        $this->assertStringContainsString('leads y clientes (principal)', $salida);

        foreach (['clave-kapso-de-prueba', 'clave-kapso-del-asistente', self::SECRETO, self::SECRETO_DEL_ASISTENTE] as $secreto) {
            $this->assertStringNotContainsString($secreto, $salida, 'El estado no imprime claves ni secretos.');
        }
    }

    /**
     * Con todo cargado, dice «listo» y por dónde sale cada cosa.
     *
     * @return void
     */
    public function test_el_estado_con_el_asistente_listo(): void
    {
        $this->crear_config_asistente();

        list($codigo, $salida) = $this->correr('whatsapp:estado', ['--sin-red' => true]);

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('LISTO', $salida);
        $this->assertStringContainsString('informes de la mañana (08:30) ............ agente personal (asistente)', $salida);
        $this->assertStringContainsString('recibe un aviso con el número del asistente', $salida);
    }

    /**
     * El estado avisa si la plantilla del informe está cargada pero no existe en `client_templates`.
     *
     * @return void
     */
    public function test_el_estado_avisa_la_plantilla_de_informes_que_no_sirve(): void
    {
        AdminSetting::set(AsistenteWhatsappSettings::KEY_INFORME_TEMPLATE_NAME, 'cc_informe_diario');

        list(, $salida) = $this->correr('whatsapp:estado', ['--sin-red' => true]);
        $this->assertStringContainsString('NO EXISTE', $salida);

        $plantilla                = new ClientTemplate();
        $plantilla->template_name = 'cc_informe_diario';
        $plantilla->language_code = 'es_AR';
        $plantilla->categoria     = 'informes';
        $plantilla->titulo        = 'Informe';
        $plantilla->body_template = 'Hola {{1}}';
        $plantilla->activa        = true;
        $plantilla->save();

        list(, $salida) = $this->correr('whatsapp:estado', ['--sin-red' => true]);
        $this->assertStringContainsString('client_templates : activa', $salida);
    }

    // ---------------------------------------------------------------------------------------------
    // whatsapp:registrar-webhook
    // ---------------------------------------------------------------------------------------------

    /**
     * `--listar` muestra solo el HOST de cada webhook y marca el que apunta a otro lado; nunca la URL
     * entera ni el secreto.
     *
     * @return void
     */
    public function test_listar_muestra_solo_el_host_y_marca_el_ajeno(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);
        $this->fakear_kapso([[
            'id'         => '8d3f1a9e-0000-0000-0000-000000000001',
            'url'        => 'https://abc123.ngrok-free.app/ruta/con-token-SECRETO-DE-PATH/api/webhook/whatsapp',
            'events'     => ['whatsapp.message.received'],
            'active'     => true,
            'secret_key' => 'secreto-del-webhook-viejo',
        ]]);

        list($codigo, $salida) = $this->correr('whatsapp:registrar-webhook', ['--proposito' => 'asistente', '--listar' => true]);

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('abc123.ngrok-free.app', $salida);
        $this->assertStringContainsString('APUNTA A OTRO HOST', $salida);
        $this->assertStringNotContainsString('SECRETO-DE-PATH', $salida, 'El path puede llevar un token: no se imprime.');
        $this->assertStringNotContainsString('secreto-del-webhook-viejo', $salida);

        Http::assertNotSent(function ($request) {
            return in_array($request->method(), ['POST', 'DELETE'], true);
        });
    }

    /**
     * 🔴 Si el número ya tiene un webhook, sin `--reemplazar` el comando corta y NO crea otro encima.
     *
     * @return void
     */
    public function test_no_crea_un_webhook_encima_de_otro(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);
        $this->fakear_kapso([[
            'id'     => '8d3f1a9e-0000-0000-0000-000000000001',
            'url'    => 'https://abc123.ngrok-free.app/api/webhook/whatsapp',
            'events' => ['whatsapp.message.received'],
            'active' => true,
        ]]);

        list($codigo, $salida) = $this->correr('whatsapp:registrar-webhook', ['--proposito' => 'asistente']);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('--reemplazar', $salida);

        Http::assertNotSent(function ($request) {
            return in_array($request->method(), ['POST', 'DELETE'], true);
        });
        $this->assertNull(WhatsappConfig::porProposito('asistente')->webhook_secret, 'No se guardó ningún secreto.');
    }

    /**
     * Con `--reemplazar`: borra el ajeno, da de alta el nuestro, guarda el secreto en la fila y NO lo
     * imprime.
     *
     * @return void
     */
    public function test_reemplazar_borra_el_ajeno_registra_el_nuestro_y_no_imprime_el_secreto(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);
        $this->fakear_kapso([[
            'id'     => '8d3f1a9e-0000-0000-0000-000000000001',
            'url'    => 'https://abc123.ngrok-free.app/api/webhook/whatsapp',
            'events' => ['whatsapp.message.received'],
            'active' => true,
        ]]);

        list($codigo, $salida) = $this->correr('whatsapp:registrar-webhook', [
            '--proposito'  => 'asistente',
            '--reemplazar' => true,
            '--url'        => 'https://api-admin.ejemplo.test/public/api/webhook/whatsapp',
        ]);

        $this->assertSame(0, $codigo);

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && strpos($request->url(), '/webhooks/8d3f1a9e-0000-0000-0000-000000000001') !== false;
        });
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['url'] === 'https://api-admin.ejemplo.test/public/api/webhook/whatsapp'
                && in_array('whatsapp.message.received', $request['events'], true)
                && is_string($request['secret_key']) && strlen($request['secret_key']) >= 32;
        });

        $secreto = WhatsappConfig::porProposito('asistente')->webhook_secret;
        $this->assertNotEmpty($secreto, 'El secreto quedó guardado en la fila.');
        $this->assertStringNotContainsString($secreto, $salida, 'Y no se imprimió.');
    }

    /**
     * Si Kapso no deja borrar el webhook viejo, no se registra nada nuevo ni se toca la fila.
     *
     * @return void
     */
    public function test_si_no_se_puede_borrar_no_se_registra_nada(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);
        $this->fakear_http([
            '*/webhooks/*' => Http::response(['error' => 'no'], 404),
            '*/webhooks*'  => Http::response(['data' => [[
                'id' => '8d3f1a9e-0000-0000-0000-000000000001', 'url' => 'https://x.ngrok-free.app/a', 'events' => [], 'active' => true,
            ]]], 200),
            '*/health'     => Http::response(['status' => 'healthy'], 200),
        ]);

        list($codigo) = $this->correr('whatsapp:registrar-webhook', ['--proposito' => 'asistente', '--reemplazar' => true]);

        $this->assertSame(1, $codigo);
        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST';
        });
        $this->assertNull(WhatsappConfig::porProposito('asistente')->webhook_secret);
    }

    /**
     * Sin webhooks previos, el alta va directo y deja la fila lista para atender.
     *
     * @return void
     */
    public function test_el_alta_sin_webhooks_previos_deja_el_numero_listo(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);
        $this->fakear_kapso([]);

        $this->assertNull(WhatsappConfig::canalListo('asistente'), 'Precondición: todavía no está listo.');

        list($codigo) = $this->correr('whatsapp:registrar-webhook', [
            '--proposito' => 'asistente',
            '--url'       => 'https://api-admin.ejemplo.test/public/api/webhook/whatsapp',
        ]);

        $this->assertSame(0, $codigo);
        $this->assertNotNull(WhatsappConfig::canalListo('asistente'), 'Con el secreto guardado el número queda listo.');
    }
}

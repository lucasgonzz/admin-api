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
        $secreto = WhatsappConfig::porProposito('asistente')->webhook_secret;
        $this->assertNotEmpty($secreto, 'El secreto quedó guardado en la fila.');
        $this->assertStringNotContainsString($secreto, $salida, 'Y no se imprimió.');
        Http::assertSent(function ($request) use ($secreto) {
            return $request->method() === 'POST' && json_decode($request->body(), true) == ['whatsapp_webhook' => [
                'url' => 'https://api-admin.ejemplo.test/public/api/webhook/whatsapp', 'secret_key' => $secreto,
                'events' => ['whatsapp.message.received', 'whatsapp.message.sent', 'whatsapp.message.delivered', 'whatsapp.message.read', 'whatsapp.message.failed'],
            ]];
        });
        $this->assertSame(['GET', 'GET', 'POST', 'DELETE'], Http::recorded()->map(function ($par) { return $par[0]->method(); })->all(),
            'El webhook anterior sólo se borra después de que el alta fue aceptada.');
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

    /**
     * 🔴 Primero se da de alta el nuestro y recién DESPUÉS se borra el viejo: si el alta falla, el número
     * sigue como estaba y no se borró nada.
     *
     * @dataProvider rechazos_del_alta
     * @return void
     */
    public function test_si_el_alta_falla_no_se_borra_nada(int $http, array $respuesta): void
    {
        $this->crear_config_asistente(['webhook_secret' => 'secreto-anterior-sintetico']);
        $antes = WhatsappConfig::porProposito('asistente')->getAttributes();

        $this->fakear_http([
            '*/webhooks/*' => Http::response('', 204),
            '*/webhooks*'  => Http::sequence()
                ->push(['data' => [[
                    'id' => '8d3f1a9e-0000-0000-0000-000000000001', 'url' => 'https://x.ngrok-free.app/a', 'events' => [], 'active' => true, 'kind' => 'kapso',
                ]]], 200)
                ->push($respuesta, $http),
            '*/health'     => Http::response(['status' => 'healthy'], 200),
        ]);

        list($codigo) = $this->correr('whatsapp:registrar-webhook', [
            '--proposito'  => 'asistente',
            '--reemplazar' => true,
            '--url'        => 'https://api-admin.ejemplo.test/public/api/webhook/whatsapp',
        ]);

        $this->assertSame(1, $codigo);
        Http::assertNotSent(function ($request) {
            return $request->method() === 'DELETE';
        });
        $this->assertSame($antes, WhatsappConfig::porProposito('asistente')->getAttributes(), 'El rechazo conserva la fila completa y el secreto anterior.');
    }

    public function rechazos_del_alta(): array
    {
        return [[400, ['message' => 'missing_parameter']], [422, ['error' => 'invalid']]];
    }

    /**
     * 🔴 `--reemplazar` conserva el webhook de eventos crudos de Meta (`kind: meta`, el de la atribución de
     * los anuncios) y cualquier webhook de este admin con otra ruta; borra el de otro host y el viejo de
     * mensajes de este admin.
     *
     * @return void
     */
    public function test_reemplazar_conserva_lo_que_no_es_de_mensajes(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);

        $nuestro = 'https://api-admin.ejemplo.test/public/api/webhook/whatsapp';

        $this->fakear_kapso([
            ['id' => 'aaaaaaaa-0000-0000-0000-000000000001', 'url' => 'https://abc.ngrok-free.app/api/webhook/whatsapp', 'kind' => 'kapso', 'events' => [], 'active' => true],
            ['id' => 'bbbbbbbb-0000-0000-0000-000000000002', 'url' => $nuestro, 'kind' => 'kapso', 'events' => [], 'active' => true],
            ['id' => 'cccccccc-0000-0000-0000-000000000003', 'url' => 'https://api-admin.ejemplo.test/public/api/webhook/meta-raw/TOKEN', 'kind' => 'meta', 'events' => [], 'active' => true],
            ['id' => 'dddddddd-0000-0000-0000-000000000004', 'url' => 'https://api-admin.ejemplo.test/public/api/otra/ruta', 'kind' => 'kapso', 'events' => [], 'active' => true],
        ]);

        list($codigo, $salida) = $this->correr('whatsapp:registrar-webhook', [
            '--proposito'  => 'asistente',
            '--reemplazar' => true,
            '--url'        => $nuestro,
        ]);

        $this->assertSame(0, $codigo);

        foreach (['aaaaaaaa', 'bbbbbbbb'] as $borrado) {
            Http::assertSent(function ($request) use ($borrado) {
                return $request->method() === 'DELETE' && strpos($request->url(), '/webhooks/' . $borrado) !== false;
            });
        }

        foreach (['cccccccc', 'dddddddd'] as $conservado) {
            Http::assertNotSent(function ($request) use ($conservado) {
                return $request->method() === 'DELETE' && strpos($request->url(), '/webhooks/' . $conservado) !== false;
            });
        }

        $this->assertStringNotContainsString('TOKEN', $salida, 'El token del path de un webhook ajeno no se imprime.');
    }

    /**
     * Si Kapso no deja borrar uno viejo después del alta, el comando lo dice y sale con error, pero el
     * secreto nuevo ya quedó guardado (el nuestro está registrado).
     *
     * @return void
     */
    public function test_si_no_se_puede_borrar_el_viejo_el_alta_queda_y_se_avisa(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);

        $this->fakear_http([
            '*/webhooks/*' => Http::response(['error' => 'no'], 500),
            '*/webhooks*'  => Http::response(['data' => [[
                'id' => '8d3f1a9e-0000-0000-0000-000000000001', 'url' => 'https://x.ngrok-free.app/a', 'events' => [], 'active' => true, 'kind' => 'kapso',
            ]]], 200),
            '*/health'     => Http::response(['status' => 'healthy'], 200),
        ]);

        list($codigo, $salida) = $this->correr('whatsapp:registrar-webhook', [
            '--proposito'  => 'asistente',
            '--reemplazar' => true,
            '--url'        => 'https://api-admin.ejemplo.test/public/api/webhook/whatsapp',
        ]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('queda VIVO', $salida);
        $this->assertNotNull(WhatsappConfig::porProposito('asistente')->webhook_secret);
    }

    /**
     * 🔴 `--reemplazar` no existe para el número de leads y clientes: su webhook no se toca desde este comando.
     *
     * @return void
     */
    public function test_reemplazar_se_niega_para_el_principal(): void
    {
        $this->fakear_kapso([['id' => 'x', 'url' => 'https://a.test/api/webhook/whatsapp', 'kind' => 'kapso', 'events' => [], 'active' => true]]);

        list($codigo, $salida) = $this->correr('whatsapp:registrar-webhook', ['--proposito' => 'principal', '--reemplazar' => true]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('solo se usa con --proposito=asistente', $salida);
        Http::assertNothingSent();
    }

    /**
     * El estado avisa de un webhook de PROYECTO (los que no cuelgan de un número): recibiría los mensajes de
     * los dos.
     *
     * @return void
     */
    public function test_el_estado_avisa_de_un_webhook_de_proyecto(): void
    {
        $this->fakear_http([
            '*/phone_numbers/*' => Http::response(['data' => [], 'status' => 'ok'], 200),
            '*/whatsapp/webhooks*' => Http::response(['data' => [
                ['id' => 'p1', 'url' => 'https://api-admin.ejemplo.test/public/api/webhook/whatsapp', 'kind' => 'kapso', 'active' => true, 'phone_number_id' => null],
                ['id' => 'n1', 'url' => 'https://otro.test/x', 'kind' => 'kapso', 'active' => true, 'phone_number_id' => self::PHONE_NUMBER_ID_PRINCIPAL],
            ]], 200),
        ]);

        list(, $salida) = $this->correr('whatsapp:estado');

        $this->assertStringContainsString('webhook de PROYECTO: api-admin.ejemplo.test', $salida);
        $this->assertStringNotContainsString('otro.test', $salida, 'Los de número no se listan como de proyecto.');
    }

    // ---------------------------------------------------------------------------------------------
    // whatsapp:probar-envio
    // ---------------------------------------------------------------------------------------------

    /**
     * Sin `--enviar` no sale nada: solo muestra qué se mandaría.
     *
     * @return void
     */
    public function test_probar_envio_sin_enviar_es_un_ensayo(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);
        Http::fake();

        list($codigo, $salida) = $this->correr('whatsapp:probar-envio', [
            'telefono'    => '+5493444123456',
            '--plantilla' => 'cc_sistema_actualizado',
            '--variable'  => ['Lucas'],
        ]);

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('Ensayo: no se mandó nada', $salida);
        Http::assertNothingSent();
    }

    /**
     * Con `--enviar` sale por el número pedido aunque todavía no esté «listo» (envía, aunque no reciba), y
     * dice que aceptado no es entregado.
     *
     * @return void
     */
    public function test_probar_envio_sale_por_el_numero_del_asistente(): void
    {
        $this->crear_config_asistente(['webhook_secret' => null]);
        $this->fakear_http([
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.PRUEBA']]], 200),
        ]);

        list($codigo, $salida) = $this->correr('whatsapp:probar-envio', [
            'telefono'    => '+5493444123456',
            '--plantilla' => 'cc_sistema_actualizado',
            '--variable'  => ['Lucas'],
            '--enviar'    => true,
        ]);

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('wamid.PRUEBA', $salida);
        $this->assertStringContainsString('Aceptado no es entregado', $salida);
        Http::assertSent(function ($request) {
            return strpos($request->url(), self::PHONE_NUMBER_ID_ASISTENTE . '/messages') !== false
                && $request['type'] === 'template'
                && $request['template']['name'] === 'cc_sistema_actualizado';
        });
    }

    /**
     * Si Meta lo rechaza, sale con error y dice el motivo.
     *
     * @return void
     */
    public function test_probar_envio_dice_cuando_meta_lo_rechaza(): void
    {
        $this->crear_config_asistente();
        $this->fakear_http([
            '*/messages' => Http::response(['error' => ['message' => 'Re-engagement message']], 400),
        ]);

        list($codigo, $salida) = $this->correr('whatsapp:probar-envio', [
            'telefono' => '+5493444123456',
            '--texto'  => 'Hola',
            '--enviar' => true,
        ]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('NO aceptó', $salida);
    }

    /**
     * Hay que elegir una sola cosa para mandar.
     *
     * @return void
     */
    public function test_probar_envio_pide_plantilla_o_texto(): void
    {
        $this->crear_config_asistente();
        Http::fake();

        list($codigo) = $this->correr('whatsapp:probar-envio', ['telefono' => '+5493444123456', '--enviar' => true]);

        $this->assertSame(1, $codigo);
        Http::assertNothingSent();
    }
}

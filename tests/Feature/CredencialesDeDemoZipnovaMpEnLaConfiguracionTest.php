<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\ImplementationSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las credenciales de PRUEBA de Zipnova y de Mercado Pago de las demos en la configuración del
 * admin (misión cruzada credenciales-demo-zipnova-mp, 14/10/2026).
 *
 * Calcada de la clave de Serper en el camino, pero con las cuatro diferencias que son justamente lo
 * que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Nada en claro en la base ni en las respuestas.** Token, secret y access token se guardan
 *     cifrados; ningún GET, PUT ni "probar" devuelve el valor (solo estado y la cola de 4).
 *  2. 🔴 **Una credencial a medias no se guarda.** Zipnova necesita token Y secret; Mercado Pago,
 *     access token Y public key.
 *  3. **Un valor ilegible (APP_KEY distinta) no explota ni se devuelve cifrado**: se lee como vacío y
 *     el GET avisa `ilegible`.
 *  4. **"Probar" es una lectura contra el servicio real** (acá, con Http::fake) y no filtra nada.
 *
 * Los valores de abajo son de relleno INVENTADO: no son ni se parecen a credenciales reales. Cada
 * test que sale a la red usa Http::fake.
 *
 * El memo de admin_settings es estático y sobrevive al rollback de la transacción, así que se
 * vacía antes y después de cada test.
 */
class CredencialesDeDemoZipnovaMpEnLaConfiguracionTest extends TestCase
{
    use DatabaseTransactions;

    /** API Token de Zipnova de relleno (23 caracteres: alcanza para mostrar la cola). */
    const ZP_TOKEN = 'zp-token-de-prueba-0001';

    /** API Secret de Zipnova de relleno. */
    const ZP_SECRET = 'zp-secret-de-prueba-0001';

    /** Access Token de Mercado Pago de relleno, con el prefijo de prueba. */
    const MP_ACCESS = 'TEST-relleno-de-prueba-0001-aaaaaaaa';

    /** Public Key de Mercado Pago de relleno, con el prefijo de prueba. */
    const MP_PUBLIC = 'TEST-relleno-publica-0001-bbbbbbbb';

    const RUTA_ZIPNOVA = '/api/admin/settings/implementation-zipnova-demo';

    const RUTA_MERCADOPAGO = '/api/admin/settings/implementation-mercadopago-demo';

    /**
     * Arranca sin ninguna credencial cargada, sea cual sea el estado de la base del slot.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->limpiar_credenciales();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        AdminSetting::flush_memo();

        parent::tearDown();
    }

    /**
     * Borra las cinco keys. El borrado masivo no dispara los eventos del modelo: el flush del memo va explícito.
     *
     * @return void
     */
    private function limpiar_credenciales(): void
    {
        AdminSetting::whereIn('key', array_merge(
            array_values(ImplementationSettings::KEYS_ZIPNOVA_DEMO),
            array_values(ImplementationSettings::KEYS_MERCADOPAGO_DEMO)
        ))->delete();

        AdminSetting::flush_memo();
    }

    /**
     * Admin autenticado del panel.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Valor crudo guardado en admin_settings (sin memo, a propósito: importa lo que quedó en la base).
     *
     * @param string $key
     *
     * @return string|null
     */
    private function guardado(string $key)
    {
        return AdminSetting::where('key', $key)->value('value');
    }

    /**
     * Guarda las credenciales de Zipnova directo por el modelo (sin pasar por el PUT).
     *
     * @return void
     */
    private function sembrar_zipnova(): void
    {
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_token'], Crypt::encryptString(self::ZP_TOKEN));
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_secret'], Crypt::encryptString(self::ZP_SECRET));
    }

    /**
     * Guarda las credenciales de Mercado Pago directo por el modelo.
     *
     * @return void
     */
    private function sembrar_mercadopago(): void
    {
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['access_token'], Crypt::encryptString(self::MP_ACCESS));
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['public_key'], self::MP_PUBLIC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Estado inicial
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sin nada cargado, los dos GET dicen "sin cargar" (el estado de un admin recién desplegado).
     *
     * @return void
     */
    public function test_sin_nada_cargado_los_dos_estados_vienen_sin_cargar(): void
    {
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_ZIPNOVA)
            ->assertStatus(200)
            ->assertExactJson([
                'cargada'            => false,
                'api_token_cola'     => null,
                'api_secret_cargado' => false,
                'account_id'         => '',
                'ilegible'           => false,
            ]);

        $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_MERCADOPAGO)
            ->assertStatus(200)
            ->assertExactJson([
                'cargada'            => false,
                'access_token_cola'  => null,
                'public_key_cola'    => null,
                'public_key_cargada' => false,
                'tipo_access_token'  => null,
                'ilegible'           => false,
            ]);
    }

    /**
     * Sin sesión, ninguna de las seis rutas responde: es el mismo middleware que las de Serper.
     *
     * @return void
     */
    public function test_las_rutas_exigen_sesion(): void
    {
        $this->getJson(self::RUTA_ZIPNOVA)->assertStatus(401);
        $this->putJson(self::RUTA_ZIPNOVA, ['api_token' => self::ZP_TOKEN, 'api_secret' => self::ZP_SECRET])->assertStatus(401);
        $this->postJson(self::RUTA_ZIPNOVA . '/probar')->assertStatus(401);
        $this->getJson(self::RUTA_MERCADOPAGO)->assertStatus(401);
        $this->putJson(self::RUTA_MERCADOPAGO, ['access_token' => self::MP_ACCESS, 'public_key' => self::MP_PUBLIC])->assertStatus(401);
        $this->postJson(self::RUTA_MERCADOPAGO . '/probar')->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Zipnova: guardar, cifrado, nunca el valor
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Guardar deja el token y el secret CIFRADOS en la base (nada en claro), el lector los
     * descifra, y NI el PUT NI el GET devuelven el valor: solo estado y la cola de 4.
     *
     * @return void
     */
    public function test_zipnova_se_guarda_cifrada_y_ninguna_respuesta_trae_el_valor(): void
    {
        $admin = $this->crear_admin();

        $put = $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, [
            'api_token'  => self::ZP_TOKEN,
            'api_secret' => self::ZP_SECRET,
            'account_id' => '98765',
        ]);

        $put->assertStatus(200)->assertExactJson([
            'cargada'            => true,
            'api_token_cola'     => '0001',
            'api_secret_cargado' => true,
            'account_id'         => '98765',
            'ilegible'           => false,
        ]);

        // En la base: cifrados. Ni el token ni el secret aparecen en claro.
        $token_guardado  = $this->guardado(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_token']);
        $secret_guardado = $this->guardado(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_secret']);

        $this->assertNotNull($token_guardado);
        $this->assertStringNotContainsString(self::ZP_TOKEN, $token_guardado);
        $this->assertStringNotContainsString(self::ZP_SECRET, $secret_guardado);
        $this->assertSame(self::ZP_TOKEN, Crypt::decryptString($token_guardado));
        $this->assertSame(self::ZP_SECRET, Crypt::decryptString($secret_guardado));

        // El id de cuenta no es secreto: va en claro.
        $this->assertSame('98765', $this->guardado(ImplementationSettings::KEYS_ZIPNOVA_DEMO['account_id']));

        // El lector descifra.
        $this->assertSame(
            ['api_token' => self::ZP_TOKEN, 'api_secret' => self::ZP_SECRET, 'account_id' => '98765'],
            ImplementationSettings::get_zipnova_demo_credenciales()
        );

        // Ninguna respuesta (PUT, GET, lote) contiene el token ni el secret.
        $get = $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_ZIPNOVA);
        $lote = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/implementation');

        foreach ([$put, $get, $lote] as $respuesta) {
            $this->assertStringNotContainsString(self::ZP_TOKEN, $respuesta->getContent());
            $this->assertStringNotContainsString(self::ZP_SECRET, $respuesta->getContent());
        }
    }

    /**
     * Guardar de nuevo con solo uno de los dos conserva el otro; con account_id solo, también.
     *
     * @return void
     */
    public function test_zipnova_cambiar_un_solo_campo_conserva_el_resto(): void
    {
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();

        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, ['api_token' => 'zp-token-nuevo-de-prueba-0002'])
            ->assertStatus(200);

        $this->assertSame(
            ['api_token' => 'zp-token-nuevo-de-prueba-0002', 'api_secret' => self::ZP_SECRET, 'account_id' => ''],
            ImplementationSettings::get_zipnova_demo_credenciales()
        );

        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, ['account_id' => '555'])->assertStatus(200);

        $this->assertSame('zp-token-nuevo-de-prueba-0002', ImplementationSettings::get_zipnova_demo_credenciales()['api_token']);
        $this->assertSame('555', ImplementationSettings::get_zipnova_demo_credenciales()['account_id']);
    }

    /**
     * 🔴 La primera vez hacen falta token Y secret: uno solo (o el id de cuenta solo) se rechaza con
     * 422 y no escribe NADA en la base.
     *
     * @return void
     */
    public function test_zipnova_un_par_a_medias_se_rechaza_y_no_guarda_nada(): void
    {
        $admin = $this->crear_admin();

        foreach ([['api_token' => self::ZP_TOKEN], ['api_secret' => self::ZP_SECRET], ['account_id' => '123']] as $cuerpo) {
            $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, $cuerpo)->assertStatus(422);
        }

        foreach (ImplementationSettings::KEYS_ZIPNOVA_DEMO as $key) {
            $this->assertNull($this->guardado($key), "Se escribió {$key} con un par a medias.");
        }
    }

    /**
     * Un PUT sin ningún dato es un error, no un borrado.
     *
     * @return void
     */
    public function test_zipnova_un_put_vacio_no_borra_ni_guarda(): void
    {
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();

        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, [])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, ['api_token' => null, 'api_secret' => null])->assertStatus(422);

        $this->assertTrue(ImplementationSettings::estado_zipnova_demo()['cargada']);
    }

    /**
     * Las credenciales con espacios o saltos de línea en el medio, demasiado largas o con un id de
     * cuenta no numérico se rechazan. Los espacios de los costados los saca antes TrimStrings.
     *
     * @return void
     */
    public function test_zipnova_validaciones_de_formato(): void
    {
        $admin = $this->crear_admin();

        $malos = [
            ['api_token' => 'zp token con espacios', 'api_secret' => self::ZP_SECRET],
            ['api_token' => self::ZP_TOKEN, 'api_secret' => "zp-secret\nde-dos-lineas"],
            ['api_token' => str_repeat('a', 256), 'api_secret' => self::ZP_SECRET],
            ['api_token' => self::ZP_TOKEN, 'api_secret' => self::ZP_SECRET, 'account_id' => 'abc'],
        ];

        foreach ($malos as $cuerpo) {
            $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, $cuerpo)->assertStatus(422);
        }

        $this->assertFalse(ImplementationSettings::estado_zipnova_demo()['cargada']);

        // Con espacios a los costados (pegado desde el portapapeles) se guarda limpio.
        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, [
            'api_token'  => '  ' . self::ZP_TOKEN . ' ',
            'api_secret' => self::ZP_SECRET,
        ])->assertStatus(200);

        $this->assertSame(self::ZP_TOKEN, ImplementationSettings::get_zipnova_demo_credenciales()['api_token']);
    }

    /**
     * Un mensaje de validación no repite el valor que se mandó.
     *
     * @return void
     */
    public function test_el_mensaje_de_validacion_no_repite_la_credencial(): void
    {
        $admin = $this->crear_admin();

        $respuesta = $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, [
            'api_token'  => 'zp token-secreto con espacios',
            'api_secret' => self::ZP_SECRET,
        ])->assertStatus(422);

        $this->assertStringNotContainsString('token-secreto', $respuesta->getContent());
        $this->assertStringNotContainsString(self::ZP_SECRET, $respuesta->getContent());
    }

    /**
     * Borrar vacía las tres keys (y gana sobre el resto del cuerpo).
     *
     * @return void
     */
    public function test_zipnova_borrar_deja_todo_sin_cargar(): void
    {
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['account_id'], '123');

        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, ['borrar' => true, 'api_token' => self::ZP_TOKEN])
            ->assertStatus(200)
            ->assertJson(['cargada' => false, 'api_token_cola' => null, 'api_secret_cargado' => false, 'account_id' => '']);

        foreach (ImplementationSettings::KEYS_ZIPNOVA_DEMO as $key) {
            $this->assertNull($this->guardado($key));
        }

        $this->assertSame(['api_token' => '', 'api_secret' => '', 'account_id' => ''], ImplementationSettings::get_zipnova_demo_credenciales());

        // Borrar lo ya borrado no es un error.
        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, ['borrar' => true])->assertStatus(200);
    }

    /**
     * 🔴 Un valor guardado que no se puede descifrar (APP_KEY distinta, o texto plano escrito a
     * mano) se lee como vacío —ni explota ni devuelve el texto cifrado— y el GET avisa `ilegible`.
     *
     * @return void
     */
    public function test_un_valor_ilegible_se_lee_vacio_y_se_avisa(): void
    {
        $admin = $this->crear_admin();

        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_token'], 'esto-no-es-un-valor-cifrado');
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_secret'], Crypt::encryptString(self::ZP_SECRET));

        $this->assertSame('', ImplementationSettings::get_zipnova_demo_credenciales()['api_token']);

        $respuesta = $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_ZIPNOVA)
            ->assertStatus(200)
            ->assertJson(['cargada' => false, 'ilegible' => true, 'api_secret_cargado' => true]);

        $this->assertStringNotContainsString('esto-no-es-un-valor-cifrado', $respuesta->getContent());

        // Con lo ilegible, guardar un solo campo no alcanza: el par tiene que quedar completo.
        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, ['api_secret' => self::ZP_SECRET])->assertStatus(422);

        // Con los dos campos nuevos se repara.
        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_ZIPNOVA, ['api_token' => self::ZP_TOKEN, 'api_secret' => self::ZP_SECRET])
            ->assertStatus(200)
            ->assertJson(['cargada' => true, 'ilegible' => false]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mercado Pago
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Mercado Pago: el access token queda cifrado, la public key en claro, y ninguna respuesta
     * trae el access token. Se informa el tipo (TEST / APP_USR) y las colas.
     *
     * @return void
     */
    public function test_mercadopago_se_guarda_y_ninguna_respuesta_trae_el_access_token(): void
    {
        $admin = $this->crear_admin();

        $put = $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_MERCADOPAGO, [
            'access_token' => self::MP_ACCESS,
            'public_key'   => self::MP_PUBLIC,
        ]);

        $put->assertStatus(200)->assertExactJson([
            'cargada'            => true,
            'access_token_cola'  => 'aaaa',
            'public_key_cola'    => 'bbbb',
            'public_key_cargada' => true,
            'tipo_access_token'  => 'TEST',
            'ilegible'           => false,
        ]);

        $access_guardado = $this->guardado(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['access_token']);
        $this->assertStringNotContainsString(self::MP_ACCESS, $access_guardado);
        $this->assertSame(self::MP_ACCESS, Crypt::decryptString($access_guardado));
        $this->assertSame(self::MP_PUBLIC, $this->guardado(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['public_key']));

        $this->assertSame(
            ['access_token' => self::MP_ACCESS, 'public_key' => self::MP_PUBLIC],
            ImplementationSettings::get_mercadopago_demo_credenciales()
        );

        $get  = $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_MERCADOPAGO);
        $lote = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/implementation');

        foreach ([$put, $get, $lote] as $respuesta) {
            $this->assertStringNotContainsString(self::MP_ACCESS, $respuesta->getContent());
            $this->assertStringNotContainsString(self::MP_PUBLIC, $respuesta->getContent());
        }
    }

    /**
     * Formato: sin prefijo TEST-/APP_USR-, corto, o con espacios, se rechaza; con APP_USR- se acepta.
     *
     * @return void
     */
    public function test_mercadopago_validaciones_de_formato(): void
    {
        $admin = $this->crear_admin();

        $malos = [
            ['access_token' => 'sin-prefijo-pero-largo-0000000000', 'public_key' => self::MP_PUBLIC],
            ['access_token' => 'TEST-corto', 'public_key' => self::MP_PUBLIC],
            ['access_token' => 'TEST-con espacios en el medio-00000', 'public_key' => self::MP_PUBLIC],
            ['access_token' => self::MP_ACCESS, 'public_key' => 'APP_USR-corta'],
            ['access_token' => self::MP_ACCESS, 'public_key' => 'PROD-relleno-publica-0001-bbbbbbbb'],
        ];

        foreach ($malos as $cuerpo) {
            $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_MERCADOPAGO, $cuerpo)->assertStatus(422);
        }

        $this->assertFalse(ImplementationSettings::estado_mercadopago_demo()['cargada']);

        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_MERCADOPAGO, [
            'access_token' => 'APP_USR-relleno-de-produccion-0001-cccccc',
            'public_key'   => 'APP_USR-relleno-publica-0001-dddddddd',
        ])->assertStatus(200)->assertJson(['cargada' => true, 'tipo_access_token' => 'APP_USR']);
    }

    /**
     * 🔴 Un par a medias no se guarda: el access token solo, o la public key sola, dan 422 y no escriben nada.
     *
     * @return void
     */
    public function test_mercadopago_un_par_a_medias_se_rechaza_y_no_guarda_nada(): void
    {
        $admin = $this->crear_admin();

        foreach ([['access_token' => self::MP_ACCESS], ['public_key' => self::MP_PUBLIC], []] as $cuerpo) {
            $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_MERCADOPAGO, $cuerpo)->assertStatus(422);
        }

        foreach (ImplementationSettings::KEYS_MERCADOPAGO_DEMO as $key) {
            $this->assertNull($this->guardado($key));
        }

        // Ya cargado el par, se puede cambiar uno solo y borrar todo.
        $this->sembrar_mercadopago();

        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_MERCADOPAGO, ['public_key' => 'TEST-relleno-publica-0002-eeeeeeee'])
            ->assertStatus(200);
        $this->assertSame(self::MP_ACCESS, ImplementationSettings::get_mercadopago_demo_credenciales()['access_token']);
        $this->assertSame('TEST-relleno-publica-0002-eeeeeeee', ImplementationSettings::get_mercadopago_demo_credenciales()['public_key']);

        $this->actingAs($admin, 'sanctum')->putJson(self::RUTA_MERCADOPAGO, ['borrar' => true])
            ->assertStatus(200)
            ->assertJson(['cargada' => false]);

        foreach (ImplementationSettings::KEYS_MERCADOPAGO_DEMO as $key) {
            $this->assertNull($this->guardado($key));
        }
    }

    /**
     * El lote de GET /settings/implementation trae las dos entradas, con el mismo cuerpo que el GET de a uno.
     *
     * @return void
     */
    public function test_el_lote_trae_las_dos_entradas_con_el_mismo_cuerpo_que_los_get(): void
    {
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();
        $this->sembrar_mercadopago();

        $lote = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/implementation')
            ->assertStatus(200)
            ->json('settings');

        $this->assertSame(
            $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_ZIPNOVA)->json(),
            $lote['implementation-zipnova-demo']
        );
        $this->assertSame(
            $this->actingAs($admin, 'sanctum')->getJson(self::RUTA_MERCADOPAGO)->json(),
            $lote['implementation-mercadopago-demo']
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Probar
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sin credenciales, probar no sale a la red y dice "no_cargada".
     *
     * @return void
     */
    public function test_probar_sin_credenciales_no_sale_a_la_red(): void
    {
        Http::fake();
        $admin = $this->crear_admin();

        $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_ZIPNOVA . '/probar')
            ->assertStatus(200)
            ->assertJson(['cargada' => false, 'autentica' => false, 'motivo' => 'no_cargada']);

        $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_MERCADOPAGO . '/probar')
            ->assertStatus(200)
            ->assertJson(['cargada' => false, 'autentica' => false, 'motivo' => 'no_cargada']);

        Http::assertNothingSent();
    }

    /**
     * Zipnova autentica: se pega GET /v2/accounts con Basic base64(token:secret) y se muestra el nombre de la cuenta.
     *
     * @return void
     */
    public function test_probar_zipnova_autentica_y_muestra_la_cuenta(): void
    {
        Http::fake(['api.zipnova.com.ar/*' => Http::response(['data' => [['id' => 4321, 'name' => 'Cuenta Demo de Relleno']]], 200)]);
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_ZIPNOVA . '/probar')
            ->assertStatus(200)
            ->assertJson(['cargada' => true, 'autentica' => true, 'cuenta' => 'Cuenta Demo de Relleno', 'motivo' => null]);

        Http::assertSent(function (HttpRequest $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.zipnova.com.ar/v2/accounts'
                && $request->header('Authorization')[0] === 'Basic ' . base64_encode(self::ZP_TOKEN . ':' . self::ZP_SECRET);
        });

        $this->assertStringNotContainsString(self::ZP_TOKEN, $respuesta->getContent());
        $this->assertStringNotContainsString(self::ZP_SECRET, $respuesta->getContent());
    }

    /**
     * Si se cargó un id de cuenta y Zipnova no lo devuelve, autentica igual pero con un aviso.
     *
     * @return void
     */
    public function test_probar_zipnova_avisa_si_el_id_de_cuenta_no_coincide(): void
    {
        Http::fake(['api.zipnova.com.ar/*' => Http::response(['data' => [['id' => 4321, 'name' => 'Cuenta Demo de Relleno']]], 200)]);
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['account_id'], '999');

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_ZIPNOVA . '/probar')
            ->assertStatus(200)
            ->assertJson(['autentica' => true, 'cuenta' => 'Cuenta Demo de Relleno']);

        $this->assertStringContainsString('no coincide', (string) $respuesta->json('aviso'));
    }

    /**
     * Zipnova rechaza (401): no autentica, con el motivo cerrado, y la respuesta no repite nada de
     * lo que contestó Zipnova.
     *
     * @return void
     */
    public function test_probar_zipnova_con_credenciales_rechazadas(): void
    {
        Http::fake(['api.zipnova.com.ar/*' => Http::response(['message' => 'detalle-que-no-debe-salir'], 401)]);
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_ZIPNOVA . '/probar')
            ->assertStatus(200)
            ->assertJson(['cargada' => true, 'autentica' => false, 'cuenta' => null, 'motivo' => 'credenciales_rechazadas']);

        $this->assertStringNotContainsString('detalle-que-no-debe-salir', $respuesta->getContent());
    }

    /**
     * Zipnova no responde (timeout o conexión caída): no autentica, motivo "sin_respuesta", y el
     * mensaje de la excepción no sale.
     *
     * @return void
     */
    public function test_probar_zipnova_sin_respuesta(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: timeout de relleno');
        });
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_ZIPNOVA . '/probar')
            ->assertStatus(200)
            ->assertJson(['cargada' => true, 'autentica' => false, 'motivo' => 'sin_respuesta']);

        $this->assertStringNotContainsString('cURL', $respuesta->getContent());
    }

    /**
     * Otro código de error (500): motivo con el código.
     *
     * @return void
     */
    public function test_probar_zipnova_con_error_del_servicio(): void
    {
        Http::fake(['api.zipnova.com.ar/*' => Http::response('cuerpo-de-error', 503)]);
        $admin = $this->crear_admin();
        $this->sembrar_zipnova();

        $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_ZIPNOVA . '/probar')
            ->assertStatus(200)
            ->assertJson(['autentica' => false, 'motivo' => 'error_http_503']);
    }

    /**
     * Mercado Pago autentica: GET /users/me con Bearer, y de la cuenta solo se muestra el apodo
     * (no el mail ni nada más).
     *
     * @return void
     */
    public function test_probar_mercadopago_autentica_y_muestra_solo_el_apodo(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(['nickname' => 'TESTUSER0001', 'email' => 'dato-personal@example.test', 'id' => 1], 200)]);
        $admin = $this->crear_admin();
        $this->sembrar_mercadopago();

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_MERCADOPAGO . '/probar')
            ->assertStatus(200)
            ->assertJson(['cargada' => true, 'autentica' => true, 'cuenta' => 'TESTUSER0001', 'motivo' => null, 'aviso' => null]);

        Http::assertSent(function (HttpRequest $request) {
            return $request->method() === 'GET'
                && $request->url() === 'https://api.mercadopago.com/users/me'
                && $request->header('Authorization')[0] === 'Bearer ' . self::MP_ACCESS;
        });

        $this->assertStringNotContainsString(self::MP_ACCESS, $respuesta->getContent());
        $this->assertStringNotContainsString('dato-personal', $respuesta->getContent());
    }

    /**
     * Un access token de PRODUCCIÓN autentica pero trae un aviso: en las demos cobraría de verdad.
     *
     * @return void
     */
    public function test_probar_mercadopago_avisa_si_el_access_token_es_de_produccion(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response(['nickname' => 'CUENTAREAL'], 200)]);
        $admin = $this->crear_admin();

        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['access_token'], Crypt::encryptString('APP_USR-relleno-de-produccion-0001-cccccc'));
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['public_key'], 'APP_USR-relleno-publica-0001-dddddddd');

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_MERCADOPAGO . '/probar')
            ->assertStatus(200)
            ->assertJson(['autentica' => true]);

        $this->assertStringContainsString('PRODUCCIÓN', (string) $respuesta->json('aviso'));
    }

    /**
     * Mercado Pago rechaza (401): no autentica y la respuesta no repite lo que contestó.
     *
     * @return void
     */
    public function test_probar_mercadopago_rechazado(): void
    {
        $admin = $this->crear_admin();
        $this->sembrar_mercadopago();

        Http::fake(['api.mercadopago.com/*' => Http::response(['message' => 'invalid_token'], 401)]);

        $respuesta = $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_MERCADOPAGO . '/probar')
            ->assertStatus(200)
            ->assertJson(['autentica' => false, 'motivo' => 'credenciales_rechazadas']);

        $this->assertStringNotContainsString('invalid_token', $respuesta->getContent());
    }

    /**
     * Mercado Pago no responde (timeout o conexión caída).
     *
     * Va en un test aparte del 401: dos Http::fake() en el mismo test se apilan y gana el primero.
     *
     * @return void
     */
    public function test_probar_mercadopago_sin_respuesta(): void
    {
        $admin = $this->crear_admin();
        $this->sembrar_mercadopago();

        Http::fake(function () {
            throw new ConnectionException('timeout de relleno');
        });

        $this->actingAs($admin, 'sanctum')->postJson(self::RUTA_MERCADOPAGO . '/probar')
            ->assertStatus(200)
            ->assertJson(['autentica' => false, 'motivo' => 'sin_respuesta']);
    }
}

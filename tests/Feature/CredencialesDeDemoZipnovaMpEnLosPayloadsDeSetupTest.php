<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\Demo;
use App\Models\DemoInstallation;
use App\Models\Lead;
use App\Services\DemoInstallationService;
use App\Services\ImplementationActionService;
use App\Services\ImplementationSettings;
use App\Services\ImplementationUserSetupService;
use App\Services\RunDemoSetupService;
use App\Services\RunUserSetupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Las credenciales de prueba de Zipnova y de Mercado Pago viajan en los DOS armados del demo-setup
 * y NUNCA en el user-setup de un cliente real; y no se filtran por ningún camino de error (misión
 * cruzada credenciales-demo-zipnova-mp, 14/10/2026).
 *
 * Contrato con empresa-api: `zipnova_api_token`, `zipnova_api_secret`, `zipnova_account_id`,
 * `mercadopago_access_token` y `mercadopago_public_key` son campos NUEVOS, OPCIONALES y ADITIVOS.
 * Viajan solo si están cargados en el admin y de a pares completos. Si no viajan, empresa-api se
 * comporta exactamente como antes (restaura la foto o cae a su .env). Un empresa-api viejo los ignora.
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Que lleguen por el cable**: los casos principales entran por `run()` con `Http::fake()` y
 *     miran el cuerpo del POST que efectivamente salió.
 *  2. 🔴 **Que ningún valor secreto salga por un error**: ni a `leads.demo_setup_last_error`, ni al
 *     log, ni en la excepción de la instalación de la demo, ni en el preview del panel.
 *  3. 🔴 **Que el user-setup de un cliente real jamás las lleve**, ni siquiera si un `setup_data`
 *     editado a mano trae esos nombres.
 *  4. **Aditivo**: sin credenciales cargadas, el payload es el de siempre; con ellas, es el de
 *     siempre MÁS los campos nuevos, sin que ningún otro cambie de nombre, de valor ni de orden.
 *
 * Los valores son de relleno INVENTADO. Cada test que sale a la red usa Http::fake.
 */
class CredencialesDeDemoZipnovaMpEnLosPayloadsDeSetupTest extends TestCase
{
    use DatabaseTransactions;

    const ZP_TOKEN  = 'zp-token-de-prueba-0001';
    const ZP_SECRET = 'zp-secret-de-prueba-0001';
    const MP_ACCESS = 'TEST-relleno-de-prueba-0001-aaaaaaaa';
    const MP_PUBLIC = 'TEST-relleno-publica-0001-bbbbbbbb';

    /** Los cinco nombres de campo del contrato. */
    const CAMPOS_NUEVOS = [
        'zipnova_api_token',
        'zipnova_api_secret',
        'zipnova_account_id',
        'mercadopago_access_token',
        'mercadopago_public_key',
    ];

    /**
     * Arranca sin ninguna credencial cargada, sea cual sea el estado de la base del slot.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::whereIn('key', array_merge(
            array_values(ImplementationSettings::KEYS_ZIPNOVA_DEMO),
            array_values(ImplementationSettings::KEYS_MERCADOPAGO_DEMO)
        ))->delete();

        AdminSetting::flush_memo();
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
     * Carga el par completo de Zipnova (cifrado, como lo deja el PUT).
     *
     * @param string|null $account_id Id de cuenta opcional.
     *
     * @return void
     */
    private function cargar_zipnova($account_id = null): void
    {
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_token'], Crypt::encryptString(self::ZP_TOKEN));
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_secret'], Crypt::encryptString(self::ZP_SECRET));

        if ($account_id !== null) {
            AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['account_id'], $account_id);
        }
    }

    /**
     * Carga el par completo de Mercado Pago.
     *
     * @return void
     */
    private function cargar_mercadopago(): void
    {
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['access_token'], Crypt::encryptString(self::MP_ACCESS));
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['public_key'], self::MP_PUBLIC);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // demo-setup con lead (build_payload, el que dispara run())
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Con las dos integraciones cargadas, el POST del demo-setup de un lead lleva los cinco campos.
     *
     * @return void
     */
    public function test_el_demo_setup_de_un_lead_manda_las_credenciales_por_el_cable(): void
    {
        $this->cargar_zipnova('4321');
        $this->cargar_mercadopago();

        $enviado = $this->payload_enviado_por_el_demo_setup();

        $this->assertSame(self::ZP_TOKEN, $enviado['zipnova_api_token']);
        $this->assertSame(self::ZP_SECRET, $enviado['zipnova_api_secret']);
        $this->assertSame('4321', $enviado['zipnova_account_id']);
        $this->assertSame(self::MP_ACCESS, $enviado['mercadopago_access_token']);
        $this->assertSame(self::MP_PUBLIC, $enviado['mercadopago_public_key']);
    }

    /**
     * 🔴 Sin nada cargado, ninguno de los cinco campos viaja (ni vacío ni en null): del otro lado,
     * un campo presente y vacío podría pisar la foto restaurada o el respaldo del .env.
     *
     * @return void
     */
    public function test_sin_credenciales_el_demo_setup_de_un_lead_no_manda_ningun_campo(): void
    {
        $enviado = $this->payload_enviado_por_el_demo_setup();

        foreach (self::CAMPOS_NUEVOS as $campo) {
            $this->assertArrayNotHasKey($campo, $enviado, "Viajó {$campo} sin credenciales cargadas.");
        }
    }

    /**
     * El id de cuenta es opcional: sin él viajan los otros cuatro, sin la clave.
     *
     * @return void
     */
    public function test_sin_id_de_cuenta_el_campo_no_viaja(): void
    {
        $this->cargar_zipnova();
        $this->cargar_mercadopago();

        $enviado = $this->payload_enviado_por_el_demo_setup();

        $this->assertArrayHasKey('zipnova_api_token', $enviado);
        $this->assertArrayNotHasKey('zipnova_account_id', $enviado);
    }

    /**
     * 🔴 Un par a medias NO viaja, ni siquiera el que sí está: token sin secret (o al revés), access
     * token sin public key (o al revés). Y una integración completa viaja aunque la otra esté a medias.
     *
     * @return void
     */
    public function test_un_par_a_medias_no_viaja(): void
    {
        // Solo el token de Zipnova (a mano por el modelo: el PUT no deja guardarlo así), y MP completo.
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_token'], Crypt::encryptString(self::ZP_TOKEN));
        $this->cargar_mercadopago();

        $lead = $this->crear_lead_con_demo();
        $con_mp_solo = $this->build_payload_del_demo_setup($lead);

        $this->assertArrayNotHasKey('zipnova_api_token', $con_mp_solo);
        $this->assertArrayNotHasKey('zipnova_api_secret', $con_mp_solo);
        $this->assertSame(self::MP_ACCESS, $con_mp_solo['mercadopago_access_token']);

        // Al revés: Zipnova completo y de Mercado Pago solo la public key.
        AdminSetting::whereIn('key', array_values(ImplementationSettings::KEYS_MERCADOPAGO_DEMO))->delete();
        AdminSetting::flush_memo();
        $this->cargar_zipnova();
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['public_key'], self::MP_PUBLIC);

        $con_zp_solo = $this->build_payload_del_demo_setup($lead);

        $this->assertSame(self::ZP_TOKEN, $con_zp_solo['zipnova_api_token']);
        $this->assertArrayNotHasKey('mercadopago_access_token', $con_zp_solo);
        $this->assertArrayNotHasKey('mercadopago_public_key', $con_zp_solo);

        // El id de cuenta solo (sin el par) tampoco viaja.
        AdminSetting::whereIn('key', array_values(ImplementationSettings::KEYS_ZIPNOVA_DEMO))->delete();
        AdminSetting::flush_memo();
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['account_id'], '4321');

        $this->assertArrayNotHasKey('zipnova_account_id', $this->build_payload_del_demo_setup($lead));
    }

    /**
     * Un valor ilegible (APP_KEY distinta) cuenta como no cargado: no viaja el texto cifrado ni nada.
     *
     * @return void
     */
    public function test_un_valor_ilegible_no_viaja(): void
    {
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_token'], 'texto-que-no-es-un-cifrado');
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_secret'], Crypt::encryptString(self::ZP_SECRET));

        $payload = $this->build_payload_del_demo_setup($this->crear_lead_con_demo());

        foreach (self::CAMPOS_NUEVOS as $campo) {
            $this->assertArrayNotHasKey($campo, $payload);
        }
    }

    /**
     * 🔴 Aditivo en el armado con lead: con las credenciales, el payload es el de siempre más los
     * campos nuevos. Ningún otro campo cambia de nombre, de valor ni de orden — y el resultado sin
     * credenciales es, campo por campo, el que había antes de esta misión.
     *
     * @return void
     */
    public function test_sumar_las_credenciales_no_cambia_ningun_otro_campo_del_demo_setup_de_un_lead(): void
    {
        $lead = $this->crear_lead_con_demo();

        $sin = $this->build_payload_del_demo_setup($lead);

        $this->cargar_zipnova('4321');
        $this->cargar_mercadopago();

        $con = $this->build_payload_del_demo_setup($lead);

        foreach (self::CAMPOS_NUEVOS as $campo) {
            $this->assertArrayHasKey($campo, $con);
            unset($con[$campo]);
        }

        $this->assertSame($sin, $con, 'Sumar las credenciales cambió otro campo del payload del demo-setup de un lead.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // demo-setup SIN lead (payload_de_defaults, el de la instalación de una demo)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El armado sin lead también las lleva, con las mismas reglas.
     *
     * @return void
     */
    public function test_el_payload_de_defaults_manda_las_credenciales(): void
    {
        $this->cargar_zipnova('4321');
        $this->cargar_mercadopago();

        $payload = (new RunDemoSetupService())->payload_de_defaults($this->crear_demo());

        $this->assertSame(self::ZP_TOKEN, $payload['zipnova_api_token']);
        $this->assertSame(self::ZP_SECRET, $payload['zipnova_api_secret']);
        $this->assertSame('4321', $payload['zipnova_account_id']);
        $this->assertSame(self::MP_ACCESS, $payload['mercadopago_access_token']);
        $this->assertSame(self::MP_PUBLIC, $payload['mercadopago_public_key']);
    }

    /**
     * Sin nada cargado, o con un par a medias, el armado sin lead no manda ninguno.
     *
     * @return void
     */
    public function test_sin_credenciales_o_a_medias_el_payload_de_defaults_no_manda_ningun_campo(): void
    {
        $demo = $this->crear_demo();

        $vacio = (new RunDemoSetupService())->payload_de_defaults($demo);

        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_secret'], Crypt::encryptString(self::ZP_SECRET));
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['access_token'], Crypt::encryptString(self::MP_ACCESS));

        $a_medias = (new RunDemoSetupService())->payload_de_defaults($demo);

        foreach (self::CAMPOS_NUEVOS as $campo) {
            $this->assertArrayNotHasKey($campo, $vacio);
            $this->assertArrayNotHasKey($campo, $a_medias);
        }

        $this->assertSame($vacio, $a_medias);
    }

    /**
     * Aditivo en el armado sin lead.
     *
     * @return void
     */
    public function test_sumar_las_credenciales_no_cambia_ningun_otro_campo_del_payload_de_defaults(): void
    {
        $demo = $this->crear_demo();

        $sin = (new RunDemoSetupService())->payload_de_defaults($demo);

        $this->cargar_zipnova();
        $this->cargar_mercadopago();

        $con = (new RunDemoSetupService())->payload_de_defaults($demo);

        foreach (['zipnova_api_token', 'zipnova_api_secret', 'mercadopago_access_token', 'mercadopago_public_key'] as $campo) {
            $this->assertArrayHasKey($campo, $con);
            unset($con[$campo]);
        }

        $this->assertSame($sin, $con, 'Sumar las credenciales cambió otro campo del payload de defaults.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // user-setup: NUNCA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 El user-setup de un cliente real (las dos vías: el del lead promovido y el de la Etapa 3 de
     * la implementación) no lleva ninguna credencial de demo, aunque estén cargadas en el admin.
     *
     * @return void
     */
    public function test_el_user_setup_de_un_cliente_real_nunca_las_lleva(): void
    {
        $this->cargar_zipnova('4321');
        $this->cargar_mercadopago();

        // Vía del lead promovido: RunUserSetupService::build_payload (protected).
        $lead               = new Lead();
        $lead->contact_name = 'Cliente Real';
        $lead->company_name = 'Comercio Real';

        $client          = new Client();
        $client->user_id = 12300;

        $metodo = new ReflectionMethod(RunUserSetupService::class, 'build_payload');
        $metodo->setAccessible(true);
        $del_lead = $metodo->invoke(new RunUserSetupService(), $lead, $client);

        // Vía de la implementación: ImplementationUserSetupService::build_payload.
        $de_la_implementacion = (new ImplementationUserSetupService())->build_payload($this->crear_cliente());

        foreach ([$del_lead, $de_la_implementacion] as $payload) {
            foreach (self::CAMPOS_NUEVOS as $campo) {
                $this->assertArrayNotHasKey($campo, $payload, "El user-setup de un cliente real llevó {$campo}.");
            }
        }
    }

    /**
     * 🔴 Ni siquiera si un `setup_data` editado a mano trae esos nombres: se desparrama entero en el
     * payload de la implementación, y se descartan.
     *
     * @return void
     */
    public function test_un_setup_data_con_esos_nombres_no_los_cuela_en_el_user_setup(): void
    {
        $setup_data_con_credenciales = [];
        foreach (self::CAMPOS_NUEVOS as $campo) {
            $setup_data_con_credenciales[$campo] = 'valor-colado-por-setup-data';
        }

        $payload = (new ImplementationUserSetupService())->build_payload($this->crear_cliente($setup_data_con_credenciales));

        foreach (self::CAMPOS_NUEVOS as $campo) {
            $this->assertArrayNotHasKey($campo, $payload);
        }

        $this->assertSame('Comercio Demo S.R.L.', $payload['company_name']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fugas: ningún camino de error guarda ni loguea los valores
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Si empresa-api devolviera un error que repite las credenciales, `leads.demo_setup_last_error`
     * (lo que muestra el panel) y el log NO las contienen.
     *
     * @return void
     */
    public function test_el_error_http_del_demo_setup_no_guarda_las_credenciales(): void
    {
        $this->cargar_zipnova('4321');
        $this->cargar_mercadopago();

        $log = $this->capturar_logs();

        Http::fake(['*' => Http::response(
            'Error de relleno con ' . self::ZP_TOKEN . ' y ' . self::ZP_SECRET . ' y ' . self::MP_ACCESS . ' y ' . self::MP_PUBLIC,
            500
        )]);

        $lead = (new RunDemoSetupService())->run($this->crear_lead_con_demo());

        $this->assertSame('fallido', $lead->demo_setup_status);
        $this->assertStringContainsString('HTTP 500', (string) $lead->demo_setup_last_error);
        $this->assertStringContainsString('[credencial oculta]', (string) $lead->demo_setup_last_error);
        $this->assertSinCredenciales((string) $lead->demo_setup_last_error);
        $this->assertSinCredenciales($log->todo());
    }

    /**
     * 🔴 Una excepción cuyo mensaje trae las credenciales (de la red o del cliente HTTP) tampoco las deja
     * en `demo_setup_last_error` ni en el log.
     *
     * @return void
     */
    public function test_la_excepcion_del_demo_setup_no_guarda_las_credenciales(): void
    {
        $this->cargar_zipnova();
        $this->cargar_mercadopago();

        $log = $this->capturar_logs();

        Http::fake(function () {
            throw new \RuntimeException('Fallo de relleno: ' . self::ZP_TOKEN . ' / ' . self::MP_ACCESS);
        });

        $lead = (new RunDemoSetupService())->run($this->crear_lead_con_demo());

        $this->assertSame('fallido', $lead->demo_setup_status);
        $this->assertStringContainsString('Excepción', (string) $lead->demo_setup_last_error);
        $this->assertSinCredenciales((string) $lead->demo_setup_last_error);
        $this->assertSinCredenciales($log->todo());
    }

    /**
     * El timeout (ConnectionException) no deja las credenciales en el log aunque el mensaje las traiga.
     *
     * @return void
     */
    public function test_el_timeout_del_demo_setup_no_loguea_las_credenciales(): void
    {
        $this->cargar_zipnova();
        $this->cargar_mercadopago();

        $log = $this->capturar_logs();

        Http::fake(function () {
            throw new ConnectionException('cURL error 28 con ' . self::ZP_SECRET . ' y ' . self::MP_PUBLIC);
        });

        $lead = (new RunDemoSetupService())->run($this->crear_lead_con_demo());

        $this->assertSame('sin_confirmar', $lead->demo_setup_status);
        $this->assertSinCredenciales((string) $lead->demo_setup_last_error);
        $this->assertSinCredenciales($log->todo());
    }

    /**
     * 🔴 La instalación de una demo (que arma el payload sin lead) no deja las credenciales en el
     * mensaje de la excepción —que termina guardado en su log— cuando el demo-setup responde un error
     * que las repite, ni cuando el POST se corta con un mensaje que las trae.
     *
     * @return void
     */
    public function test_el_error_del_demo_setup_de_la_instalacion_de_una_demo_no_las_guarda(): void
    {
        $this->cargar_zipnova();
        $this->cargar_mercadopago();

        $servicio = $this->crear_servicio_de_instalacion_de_demo();

        // Respuesta no exitosa que repite las credenciales.
        Http::fake(['*' => Http::response('Cuerpo de relleno: ' . self::ZP_TOKEN . ' ' . self::MP_ACCESS, 500)]);

        $mensaje = $this->mensaje_de_la_excepcion_del_paso($servicio);

        $this->assertStringContainsString('HTTP 500', $mensaje);
        $this->assertSinCredenciales($mensaje);
    }

    /**
     * Y el corte del POST (ConnectionException) con un mensaje que trae las credenciales.
     *
     * @return void
     */
    public function test_el_corte_del_demo_setup_de_la_instalacion_de_una_demo_no_las_guarda(): void
    {
        $this->cargar_zipnova();
        $this->cargar_mercadopago();

        $servicio = $this->crear_servicio_de_instalacion_de_demo();

        Http::fake(function () {
            throw new ConnectionException('Corte de relleno: ' . self::ZP_SECRET . ' ' . self::MP_PUBLIC);
        });

        $mensaje = $this->mensaje_de_la_excepcion_del_paso($servicio);

        $this->assertStringContainsString('Sin respuesta del demo-setup', $mensaje);
        $this->assertSinCredenciales($mensaje);
    }

    /**
     * El tapador de texto: reemplaza solo lo cargado, deja el resto del mensaje, y sin nada cargado
     * devuelve el texto tal cual.
     *
     * @return void
     */
    public function test_el_tapador_reemplaza_solo_lo_cargado(): void
    {
        $texto = 'antes ' . self::ZP_TOKEN . ' medio ' . self::MP_PUBLIC . ' fin';

        $this->assertSame($texto, ImplementationSettings::tapar_credenciales_de_demo($texto));

        $this->cargar_zipnova();
        $this->cargar_mercadopago();

        $this->assertSame(
            'antes [credencial oculta] medio [credencial oculta] fin',
            ImplementationSettings::tapar_credenciales_de_demo($texto)
        );

        $this->assertSame('', ImplementationSettings::tapar_credenciales_de_demo(''));
    }

    /**
     * El tapador no deja a medias un valor que contiene a otro (el token dentro del secret) y tapa también
     * la forma escapada en JSON de una credencial con barra (así la trae un cuerpo de error JSON).
     *
     * @return void
     */
    public function test_el_tapador_tapa_valores_solapados_y_la_forma_escapada_en_json(): void
    {
        $token  = 'tok-de-prueba-abcdef-123456';
        $secret = 'sec-' . $token . '-xyz';
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_token'], Crypt::encryptString($token));
        AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_secret'], Crypt::encryptString($secret));

        $this->assertSame(
            'x [credencial oculta] y',
            ImplementationSettings::tapar_credenciales_de_demo('x ' . $secret . ' y'),
            'El valor largo se tapa entero: el corto que lleva adentro no puede dejarlo a medias.'
        );

        $access = 'TEST-relleno/obvio-12345678901234567890';
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['access_token'], Crypt::encryptString($access));
        AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['public_key'], 'TEST-relleno-publica-12345678901234567890');

        $cuerpo = json_encode(['error' => 'token ' . $access . ' invalido']);
        $this->assertStringNotContainsString('obvio-1234', ImplementationSettings::tapar_credenciales_de_demo($cuerpo));
        $this->assertStringContainsString('[credencial oculta]', ImplementationSettings::tapar_credenciales_de_demo($cuerpo));
    }

    /**
     * El preview del panel del user-setup tapa los cuatro nombres si algún día los trajera el payload
     * (hoy no los trae, pero el preview ya nace tapándolos).
     *
     * @return void
     */
    public function test_el_preview_del_panel_tapa_los_nombres_de_las_credenciales(): void
    {
        $payload = [
            'company_name'             => 'Comercio',
            'zipnova_api_token'        => self::ZP_TOKEN,
            'zipnova_api_secret'       => self::ZP_SECRET,
            'mercadopago_access_token' => self::MP_ACCESS,
            'mercadopago_public_key'   => self::MP_PUBLIC,
        ];

        $metodo = new ReflectionMethod(ImplementationActionService::class, 'tapar_claves_del_payload');
        $metodo->setAccessible(true);
        $tapado = $metodo->invoke(new ImplementationActionService(), $payload);

        $this->assertSame('Comercio', $tapado['company_name']);

        foreach (['zipnova_api_token', 'zipnova_api_secret', 'mercadopago_access_token', 'mercadopago_public_key'] as $campo) {
            $this->assertStringStartsWith('cargada', $tapado[$campo]);
            $this->assertStringNotContainsString($payload[$campo], $tapado[$campo]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Falla si el texto contiene alguno de los cuatro valores sensibles.
     *
     * @param string $texto
     *
     * @return void
     */
    private function assertSinCredenciales(string $texto): void
    {
        foreach ([self::ZP_TOKEN, self::ZP_SECRET, self::MP_ACCESS, self::MP_PUBLIC] as $valor) {
            $this->assertStringNotContainsString($valor, $texto, 'Una credencial de demo apareció en un texto que se guarda o se loguea.');
        }
    }

    /**
     * Captura todo lo que se loguea durante el test (mensaje + contexto) para poder revisarlo.
     *
     * @return object Con un método todo() que devuelve el texto completo de lo logueado.
     */
    private function capturar_logs()
    {
        $acumulador = new \stdClass();
        $acumulador->lineas = [];

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($acumulador) {
            $acumulador->lineas[] = $evento->message . ' ' . json_encode($evento->context);
        });

        return new class($acumulador) {
            private $acumulador;

            public function __construct($acumulador)
            {
                $this->acumulador = $acumulador;
            }

            public function todo(): string
            {
                return implode("\n", $this->acumulador->lineas);
            }
        };
    }

    /**
     * Corre el demo-setup de un lead entero contra una instancia falsa y devuelve el cuerpo del POST.
     *
     * @return array<string, mixed>
     */
    private function payload_enviado_por_el_demo_setup(): array
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new RunDemoSetupService())->run($this->crear_lead_con_demo());

        $cuerpo = null;

        Http::assertSent(function ($request) use (&$cuerpo) {
            if ($request->method() !== 'POST' || ! Str::contains($request->url(), '/api/admin-sync/demo-setup')) {
                return false;
            }

            $cuerpo = $request->data();

            return true;
        });

        $this->assertIsArray($cuerpo, 'No salió ningún POST al demo-setup.');

        return $cuerpo;
    }

    /**
     * `RunDemoSetupService::build_payload()` por reflexión (es protected).
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    private function build_payload_del_demo_setup(Lead $lead): array
    {
        $metodo = new ReflectionMethod(RunDemoSetupService::class, 'build_payload');
        $metodo->setAccessible(true);

        return $metodo->invoke(new RunDemoSetupService(), $lead);
    }

    /**
     * Demo con las URLs que el service necesita para resolver el destino del POST.
     *
     * @return Demo
     */
    private function crear_demo(): Demo
    {
        $demo                    = new Demo();
        $demo->uuid              = (string) Str::uuid();
        $demo->erp_spa_url       = 'https://demo-credenciales.test';
        $demo->erp_api_url       = 'https://api-demo-credenciales.test';
        $demo->ecommerce_spa_url = 'https://tienda-demo-credenciales.test';
        $demo->ecommerce_api_url = 'https://api-tienda-demo-credenciales.test';
        $demo->save();

        return $demo;
    }

    /**
     * Lead con demo asignada y turno agendado, listo para que run() arme y mande el payload.
     *
     * @return Lead
     */
    private function crear_lead_con_demo(): Lead
    {
        $demo = $this->crear_demo();

        $lead               = new Lead();
        $lead->uuid         = (string) Str::uuid();
        $lead->contact_name = 'Lead Credenciales';
        $lead->company_name = 'Empresa Credenciales';
        $lead->status       = 'demo_agendada';
        $lead->save();

        // Después del save: el hook `creating` del modelo estampa la dinámica por defecto.
        $lead->demo_id           = $demo->id;
        $lead->demo_date         = '2026-10-14';
        $lead->demo_start_time   = '09:00';
        $lead->demo_end_time     = '23:00';
        $lead->demo_setup_status = 'pendiente';
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Cliente con su empresa-api activa y un setup_data mínimo (el que deja el formulario de la Etapa 1).
     *
     * @param array<string, mixed> $setup_data_extra Claves a sumar a setup_data.
     *
     * @return Client
     */
    private function crear_cliente(array $setup_data_extra = []): Client
    {
        $client               = new Client();
        $client->name         = 'Comercio Demo';
        $client->company_name = 'Comercio Demo S.R.L.';
        $client->phone        = '+5493410000000';
        $client->is_active    = true;
        $client->user_id      = 12300;
        $client->setup_data   = array_merge([
            'company_name' => 'Comercio Demo S.R.L.',
            'email'        => 'credenciales@test.local',
            'doc_number'   => '20304050607',
        ], $setup_data_extra);
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-comercio-credenciales.test';
        $api->path         = 'comercio-credenciales/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

        return $client->refresh();
    }

    /**
     * Servicio de instalación de una demo con host real (no local), para ejercitar su etapa
     * `run_demo_setup`. No corre el pipeline entero: solo se invoca esa etapa por reflexión.
     *
     * @return DemoInstallationService
     */
    private function crear_servicio_de_instalacion_de_demo(): DemoInstallationService
    {
        $demo = $this->crear_demo();

        // Host de producción de verdad: con ".test" el normalizador de URLs lo tomaría por local y
        // la etapa se saltearía sin disparar nada.
        $demo->erp_api_url = 'https://api-demo-credenciales.comerciocity.com';
        $demo->save();

        $instalacion          = new DemoInstallation();
        $instalacion->uuid    = (string) Str::uuid();
        $instalacion->demo_id = $demo->id;
        $instalacion->status  = 'instalando';
        $instalacion->save();

        /* Sin pasar por el constructor: exige una versión y una credencial SSH que esta etapa no usa,
         * y armarlas sería ruido. Se le setean por reflexión los tres datos que la etapa lee. */
        $reflexion = new \ReflectionClass(DemoInstallationService::class);
        $servicio  = $reflexion->newInstanceWithoutConstructor();

        foreach (['demo' => $demo, 'installation' => $instalacion, 'path_resolver' => new \App\Services\DemoPathResolver()] as $propiedad => $valor) {
            $campo = $reflexion->getProperty($propiedad);
            $campo->setAccessible(true);
            $campo->setValue($servicio, $valor);
        }

        return $servicio;
    }

    /**
     * Corre la etapa `run_demo_setup` y devuelve el mensaje de la excepción con la que corta.
     * Si no corta, el test falla acá.
     *
     * @param DemoInstallationService $servicio
     *
     * @return string
     */
    private function mensaje_de_la_excepcion_del_paso(DemoInstallationService $servicio): string
    {
        $metodo = new ReflectionMethod(DemoInstallationService::class, 'step_run_demo_setup');
        $metodo->setAccessible(true);

        try {
            $metodo->invoke($servicio);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('La etapa run_demo_setup no cortó con una excepción.');
    }
}

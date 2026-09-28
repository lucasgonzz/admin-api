<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\RunDemoSetupService;
use App\Services\RunUserSetupService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * La clave de Serper viaja en el user-setup y en los DOS armados del demo-setup (misión
 * serper-en-user-setup, 28/9/2026).
 *
 * Contrato con empresa-api: `serper_api_key` es un campo NUEVO, OPCIONAL y ADITIVO. Viaja solo si
 * hay una clave cargada en el admin; si no viaja, empresa-api deja users.serper_api_key en null y
 * el sistema usa la SERPER_API_KEY de su .env, como antes. Un empresa-api viejo lo ignora.
 *
 * Lo que estas pruebas protegen, en orden de importancia:
 *
 *  1. 🔴 **Que la clave llegue por el cable**, no solo al array: los casos principales entran por
 *     `run()` con `Http::fake()` y miran el cuerpo del POST que efectivamente salió.
 *  2. **Que sin clave cargada el campo NO viaje** (ni vacío ni en null): del otro lado, un campo
 *     presente y vacío podría pisar el respaldo del .env.
 *  3. **Cada setup lleva la suya**: un cliente real, la de clientes; una demo, la de demos y, si
 *     está vacía, la de clientes. La de demos nunca llega a un cliente real.
 *  4. **Aditivo**: sumar la clave no cambia ni el nombre ni el valor de ningún otro campo.
 *
 * El memo de admin_settings es estático y sobrevive al rollback de la transacción, así que se
 * vacía antes y después de cada test.
 */
class ClaveDeSerperEnLosPayloadsDeSetupTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Clave de clientes de ejemplo: 40 caracteres hexadecimales, la forma de las claves de Serper.
     */
    const CLAVE_CLIENTES = '0123456789abcdef0123456789abcdef01234567';

    /**
     * Clave de demos de ejemplo, distinta de la de clientes para poder distinguir cuál viajó.
     */
    const CLAVE_DEMOS = 'fedcba9876543210fedcba9876543210fedcba98';

    /**
     * Arranca sin ninguna clave de Serper cargada, sea cual sea el estado de la base del slot.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Borrado masivo: no dispara los eventos del modelo, por eso el flush va explícito después.
        AdminSetting::whereIn('key', [
            'implementation_serper_api_key_default',
            'implementation_serper_api_key_demo',
        ])->delete();

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

    // ─────────────────────────────────────────────────────────────────────────
    // user-setup
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Con la clave de clientes cargada, el POST del user-setup la lleva en `serper_api_key`.
     *
     * @return void
     */
    public function test_el_user_setup_manda_la_clave_de_clientes(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $enviado = $this->payload_enviado_por_el_user_setup();

        $this->assertArrayHasKey('serper_api_key', $enviado, 'El user-setup no mandó la clave de Serper.');
        $this->assertSame(self::CLAVE_CLIENTES, $enviado['serper_api_key']);
    }

    /**
     * Sin ninguna clave cargada, el campo no viaja: ni vacío ni en null.
     *
     * @return void
     */
    public function test_sin_clave_el_user_setup_no_manda_el_campo(): void
    {
        $enviado = $this->payload_enviado_por_el_user_setup();

        $this->assertArrayNotHasKey('serper_api_key', $enviado);
    }

    /**
     * 🔴 La clave de demos no llega nunca a un cliente real, aunque sea la única cargada.
     *
     * La caída es de demos a clientes y no al revés: una demo puede usar la de clientes (hay una
     * sola cuenta), pero un cliente que paga no puede quedar atado a la clave de las demos.
     *
     * @return void
     */
    public function test_la_clave_de_demos_no_viaja_en_el_user_setup(): void
    {
        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);

        $payload = $this->build_payload_del_user_setup();

        $this->assertArrayNotHasKey('serper_api_key', $payload);
    }

    /**
     * Una clave guardada con espacios a los costados (por fuera del PUT, que ya los saca) viaja
     * recortada: con un espacio pegado, Serper la rechaza.
     *
     * @return void
     */
    public function test_la_clave_viaja_recortada(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', '  ' . self::CLAVE_CLIENTES . ' ');

        $payload = $this->build_payload_del_user_setup();

        $this->assertSame(self::CLAVE_CLIENTES, $payload['serper_api_key']);
    }

    /**
     * Aditivo: sumar la clave agrega `serper_api_key` y NADA más. Todos los demás campos, incluida
     * la API key de Google que viaja al lado, quedan con el mismo nombre, el mismo valor y el
     * mismo orden.
     *
     * @return void
     */
    public function test_sumar_la_clave_no_cambia_ningun_otro_campo_del_user_setup(): void
    {
        AdminSetting::set('implementation_google_api_key_default', 'AIza' . str_repeat('g', 35));

        $sin = $this->build_payload_del_user_setup();

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $con = $this->build_payload_del_user_setup();

        $this->assertSame(self::CLAVE_CLIENTES, $con['serper_api_key']);
        $this->assertSame('AIza' . str_repeat('g', 35), $con['google_custom_search_api_key']);

        unset($con['serper_api_key']);
        $this->assertSame($sin, $con, 'Sumar la clave de Serper cambió otro campo del payload del user-setup.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // demo-setup: el armado con lead (build_payload, el que dispara run())
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 Con una clave propia de demos, el POST del demo-setup de un lead lleva ESA (y no la de
     * clientes, aunque también esté cargada).
     *
     * @return void
     */
    public function test_el_demo_setup_de_un_lead_manda_la_clave_de_demos(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);
        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);

        $enviado = $this->payload_enviado_por_el_demo_setup();

        $this->assertArrayHasKey('serper_api_key', $enviado, 'El demo-setup no mandó la clave de Serper.');
        $this->assertSame(self::CLAVE_DEMOS, $enviado['serper_api_key']);
    }

    /**
     * 🔴 La caída: sin clave propia de demos, el demo-setup de un lead manda la de clientes.
     *
     * @return void
     */
    public function test_sin_clave_de_demos_el_demo_setup_de_un_lead_manda_la_de_clientes(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $enviado = $this->payload_enviado_por_el_demo_setup();

        $this->assertSame(self::CLAVE_CLIENTES, $enviado['serper_api_key']);
    }

    /**
     * Sin ninguna de las dos, el demo-setup de un lead no manda el campo.
     *
     * @return void
     */
    public function test_sin_ninguna_clave_el_demo_setup_de_un_lead_no_manda_el_campo(): void
    {
        $enviado = $this->payload_enviado_por_el_demo_setup();

        $this->assertArrayNotHasKey('serper_api_key', $enviado);
    }

    /**
     * Aditivo en el armado con lead: con la clave, el payload es el de siempre más `serper_api_key`.
     *
     * @return void
     */
    public function test_sumar_la_clave_no_cambia_ningun_otro_campo_del_demo_setup_de_un_lead(): void
    {
        AdminSetting::set('implementation_google_api_key_demo', 'AIza' . str_repeat('d', 35));

        $lead = $this->crear_lead_con_demo();

        $sin = $this->build_payload_del_demo_setup($lead);

        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);

        $con = $this->build_payload_del_demo_setup($lead);

        $this->assertSame(self::CLAVE_DEMOS, $con['serper_api_key']);
        $this->assertSame('AIza' . str_repeat('d', 35), $con['google_custom_search_api_key']);

        unset($con['serper_api_key']);
        $this->assertSame($sin, $con, 'Sumar la clave de Serper cambió otro campo del payload del demo-setup de un lead.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // demo-setup: el armado SIN lead (payload_de_defaults, el de la instalación de una demo)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El armado sin lead también lleva la clave de demos.
     *
     * @return void
     */
    public function test_el_payload_de_defaults_manda_la_clave_de_demos(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);
        AdminSetting::set('implementation_serper_api_key_demo', self::CLAVE_DEMOS);

        $payload = (new RunDemoSetupService())->payload_de_defaults($this->crear_demo());

        $this->assertSame(self::CLAVE_DEMOS, $payload['serper_api_key']);
    }

    /**
     * La caída también vale en el armado sin lead: sin clave propia de demos, va la de clientes.
     *
     * @return void
     */
    public function test_sin_clave_de_demos_el_payload_de_defaults_manda_la_de_clientes(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $payload = (new RunDemoSetupService())->payload_de_defaults($this->crear_demo());

        $this->assertSame(self::CLAVE_CLIENTES, $payload['serper_api_key']);
    }

    /**
     * Sin ninguna de las dos, el armado sin lead no manda el campo.
     *
     * @return void
     */
    public function test_sin_ninguna_clave_el_payload_de_defaults_no_manda_el_campo(): void
    {
        $payload = (new RunDemoSetupService())->payload_de_defaults($this->crear_demo());

        $this->assertArrayNotHasKey('serper_api_key', $payload);
    }

    /**
     * Aditivo en el armado sin lead: con la clave, el payload es el de siempre más `serper_api_key`.
     *
     * @return void
     */
    public function test_sumar_la_clave_no_cambia_ningun_otro_campo_del_payload_de_defaults(): void
    {
        $demo = $this->crear_demo();

        $sin = (new RunDemoSetupService())->payload_de_defaults($demo);

        AdminSetting::set('implementation_serper_api_key_default', self::CLAVE_CLIENTES);

        $con = (new RunDemoSetupService())->payload_de_defaults($demo);

        $this->assertSame(self::CLAVE_CLIENTES, $con['serper_api_key']);

        unset($con['serper_api_key']);
        $this->assertSame($sin, $con, 'Sumar la clave de Serper cambió otro campo del payload de defaults.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Corre el user-setup entero contra una instancia falsa y devuelve el cuerpo del POST que salió.
     *
     * `Http::fake()` va ANTES de crear nada: la cola del testing es `sync`, así que cualquier cosa
     * que se dispare en el camino corre en línea y tampoco puede salir a la red.
     *
     * @return array<string, mixed>
     */
    private function payload_enviado_por_el_user_setup(): array
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $lead               = new Lead();
        $lead->contact_name = 'Cliente Serper';
        $lead->company_name = 'Comercio Serper ' . uniqid();
        $lead->email        = 'serper@test.local';
        $lead->status       = 'cerrado_ganado';
        // Destino del POST: sin ClientApi activa ni api_url en el Client, el service cae a la del lead.
        $lead->api_url      = 'https://api-cliente-serper.test';
        $lead->save();

        app(RunUserSetupService::class)->run($lead->refresh());

        return $this->cuerpo_del_post('/api/admin-sync/user-setup');
    }

    /**
     * Corre el demo-setup de un lead entero contra una instancia falsa y devuelve el cuerpo del
     * POST que salió.
     *
     * @return array<string, mixed>
     */
    private function payload_enviado_por_el_demo_setup(): array
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new RunDemoSetupService())->run($this->crear_lead_con_demo());

        return $this->cuerpo_del_post('/api/admin-sync/demo-setup');
    }

    /**
     * Cuerpo del POST que se mandó a una ruta del admin-sync. Si no salió ninguno, el test falla
     * acá, con un mensaje que lo dice, en vez de más adelante con un índice inexistente.
     *
     * @param string $ruta Tramo final de la URL (ej: '/api/admin-sync/user-setup').
     *
     * @return array<string, mixed>
     */
    private function cuerpo_del_post(string $ruta): array
    {
        $cuerpo = null;

        Http::assertSent(function ($request) use ($ruta, &$cuerpo) {
            if ($request->method() !== 'POST' || ! Str::contains($request->url(), $ruta)) {
                return false;
            }

            $cuerpo = $request->data();

            return true;
        });

        $this->assertIsArray($cuerpo, "No salió ningún POST a {$ruta}.");

        return $cuerpo;
    }

    /**
     * `RunUserSetupService::build_payload()` es protected: se invoca por reflexión, igual que hace
     * PayloadDelDemoSetupSinLeadTest con el del demo-setup. Lead y Client en memoria: el armado solo
     * lee sus atributos y los settings.
     *
     * @return array<string, mixed>
     */
    private function build_payload_del_user_setup(): array
    {
        $lead               = new Lead();
        $lead->contact_name = 'Cliente Serper';
        $lead->company_name = 'Comercio Serper';

        $client          = new Client();
        $client->user_id = 12300;

        $metodo = new ReflectionMethod(RunUserSetupService::class, 'build_payload');
        $metodo->setAccessible(true);

        return $metodo->invoke(new RunUserSetupService(), $lead, $client);
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
        $demo->erp_spa_url       = 'https://demo-serper.test';
        $demo->erp_api_url       = 'https://api-demo-serper.test';
        $demo->ecommerce_spa_url = 'https://tienda-demo-serper.test';
        $demo->ecommerce_api_url = 'https://api-tienda-demo-serper.test';
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
        $lead->contact_name = 'Lead Serper';
        $lead->company_name = 'Empresa Serper';
        $lead->status       = 'demo_agendada';
        $lead->save();

        // Después del save: el hook `creating` del modelo estampa la dinámica por defecto.
        $lead->demo_id           = $demo->id;
        $lead->demo_date         = '2026-09-28';
        $lead->demo_start_time   = '09:00';
        $lead->demo_end_time     = '23:00';
        $lead->demo_setup_status = 'pendiente';
        $lead->save();

        return $lead->refresh();
    }
}

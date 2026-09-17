<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /settings/implementation: los nueve settings de implementación en una sola respuesta.
 *
 * La pantalla de configuración hacía 9 GET al montarse, uno por setting, todos contra la misma
 * tabla admin_settings.
 *
 * 🔴 Se AGREGA, no reemplaza: los nueve GET de a uno siguen existiendo y devolviendo exactamente
 * lo mismo, porque admin-spa los mantiene como camino de respaldo. Por eso el test central de
 * este archivo no compara contra valores escritos a mano: consulta los nueve endpoints viejos y
 * verifica que el nuevo devuelva, entrada por entrada, EXACTAMENTE lo mismo. Así el día que a
 * alguno se le cambie un fallback, los dos caminos no pueden desincronizarse sin que esto se
 * ponga en rojo.
 */
class SettingsDeImplementacionEnUnaSolaRespuestaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Las nueve entradas, con la ruta individual de la que sale cada una.
     *
     * @var array<string, string>
     */
    private $rutas_individuales = [
        'implementation-assigned-admin'         => '/api/admin/settings/implementation-assigned-admin',
        'implementation-file-wait'              => '/api/admin/settings/implementation-file-wait',
        'implementation-employees-wait'         => '/api/admin/settings/implementation-employees-wait',
        'implementation-form-contact-delay'     => '/api/admin/settings/implementation-form-contact-delay',
        'implementation-form-url'               => '/api/admin/settings/implementation-form-url',
        'implementation-google-cuota-default'   => '/api/admin/settings/implementation-google-cuota-default',
        'implementation-google-api-key-default' => '/api/admin/settings/implementation-google-api-key-default',
        'implementation-google-api-key-demo'    => '/api/admin/settings/implementation-google-api-key-demo',
        'implementation-google-cuota-demo'      => '/api/admin/settings/implementation-google-cuota-demo',
    ];

    /**
     * El memo de admin_settings es estático: se limpia para que un test no contamine al siguiente.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

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
     * Deja los nueve settings cargados con valores distintos de los fallbacks.
     *
     * @param Admin $admin Admin a asignar como responsable de implementaciones.
     *
     * @return void
     */
    private function cargar_los_nueve_settings(Admin $admin): void
    {
        AdminSetting::set('implementation_assigned_admin_id', (string) $admin->id);
        AdminSetting::set('implementation_file_wait_seconds', '42');
        AdminSetting::set('implementation_employees_wait_seconds', '77');
        AdminSetting::set('implementation_form_contact_delay_seconds', '120');
        AdminSetting::set('implementation_form_url', 'https://admin.comerciocity.com/configuracion');
        AdminSetting::set('implementation_google_cuota_default', '555');
        AdminSetting::set('implementation_google_api_key_default', 'AIza' . str_repeat('a', 35));
        AdminSetting::set('implementation_google_api_key_demo', 'AIza' . str_repeat('b', 35));
        AdminSetting::set('implementation_google_cuota_demo', '222');

        AdminSetting::flush_memo();
    }

    /**
     * 🔴 La prueba central: el endpoint en lote devuelve, entrada por entrada, lo MISMO que los
     * nueve de a uno.
     *
     * No se comparan valores escritos a mano a propósito: se consultan los nueve endpoints viejos
     * en el mismo test y se compara contra ellos. Si mañana alguien cambia un fallback en un solo
     * lugar, los dos caminos se desincronizan y esto se pone en rojo.
     *
     * @return void
     */
    public function test_devuelve_exactamente_lo_mismo_que_los_nueve_endpoints_de_a_uno(): void
    {
        $admin = $this->crear_admin();
        $this->cargar_los_nueve_settings($admin);

        $en_lote = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/settings/implementation')
            ->assertStatus(200)
            ->json('settings');

        $this->assertIsArray($en_lote, 'La respuesta tiene que venir bajo la clave "settings".');
        $this->assertSame(
            array_keys($this->rutas_individuales),
            array_keys($en_lote),
            'Tienen que venir las nueve entradas, con la misma clave que usa cada ruta individual.'
        );

        foreach ($this->rutas_individuales as $clave => $ruta) {
            $de_a_uno = $this->actingAs($admin, 'sanctum')->getJson($ruta)->assertStatus(200)->json();

            $this->assertSame(
                $de_a_uno,
                $en_lote[$clave],
                "La entrada '{$clave}' del endpoint en lote no coincide con lo que devuelve {$ruta}."
            );
        }
    }

    /**
     * Sin ningún setting cargado, el endpoint en lote aplica los mismos fallbacks que los de a uno
     * (15, 30, 60, 300, 100, cadena vacía y admin_id nulo). Es el estado de un admin recién
     * instalado, así que no puede reventar ni inventar valores.
     *
     * @return void
     */
    public function test_sin_nada_cargado_devuelve_los_mismos_fallbacks_que_los_de_a_uno(): void
    {
        $admin = $this->crear_admin();

        AdminSetting::whereIn('key', [
            'implementation_assigned_admin_id',
            'implementation_file_wait_seconds',
            'implementation_employees_wait_seconds',
            'implementation_form_contact_delay_seconds',
            'implementation_form_url',
            'implementation_google_cuota_default',
            'implementation_google_api_key_default',
            'implementation_google_api_key_demo',
            'implementation_google_cuota_demo',
        ])->delete();
        AdminSetting::flush_memo();

        $en_lote = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/settings/implementation')
            ->assertStatus(200)
            ->json('settings');

        $this->assertNull($en_lote['implementation-assigned-admin']['admin_id'], 'Sin admin asignado tiene que dar null.');
        $this->assertSame(15, $en_lote['implementation-file-wait']['seconds']);
        $this->assertSame(30, $en_lote['implementation-employees-wait']['seconds']);
        $this->assertSame(60, $en_lote['implementation-form-contact-delay']['seconds']);
        $this->assertSame('', $en_lote['implementation-form-url']['url']);
        $this->assertSame(300, $en_lote['implementation-google-cuota-default']['cuota']);
        $this->assertSame('', $en_lote['implementation-google-api-key-default']['api_key']);
        $this->assertSame('', $en_lote['implementation-google-api-key-demo']['api_key']);
        $this->assertSame(100, $en_lote['implementation-google-cuota-demo']['cuota']);

        // Y lo mismo, entrada por entrada, que los de a uno.
        foreach ($this->rutas_individuales as $clave => $ruta) {
            $de_a_uno = $this->actingAs($admin, 'sanctum')->getJson($ruta)->assertStatus(200)->json();
            $this->assertSame($de_a_uno, $en_lote[$clave], "Sin nada cargado, '{$clave}' difiere de {$ruta}.");
        }
    }

    /**
     * 🔴 Los nueve endpoints de a uno siguen existiendo y respondiendo 200.
     *
     * El SPA los mantiene como respaldo del nuevo, así que si alguno se rompió, el respaldo no
     * sirve. Se prueban por su ruta real: el orden de las rutas también es parte de esto (la
     * literal `settings/implementation` no puede comerse a `settings/implementation-form-url`).
     *
     * @return void
     */
    public function test_los_nueve_endpoints_de_a_uno_siguen_en_pie(): void
    {
        $admin = $this->crear_admin();
        $this->cargar_los_nueve_settings($admin);

        foreach ($this->rutas_individuales as $clave => $ruta) {
            $this->actingAs($admin, 'sanctum')
                ->getJson($ruta)
                ->assertStatus(200);
        }

        // Y devuelven lo suyo, no lo del endpoint en lote.
        $this->assertSame(
            'https://admin.comerciocity.com/configuracion',
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/implementation-form-url')->json('url')
        );
        $this->assertSame(
            42,
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/implementation-file-wait')->json('seconds')
        );
    }

    /**
     * El endpoint no queda más abierto que los de a uno: sin sesión, 401.
     *
     * Devuelve API keys de Google, así que esto no es un detalle.
     *
     * @return void
     */
    public function test_sin_sesion_no_se_pueden_leer_los_settings(): void
    {
        $this->getJson('/api/admin/settings/implementation')->assertStatus(401);
        $this->getJson('/api/admin/settings/implementation-google-api-key-default')->assertStatus(401);
    }

    /**
     * Las nueve entradas salen de UNA sola consulta a admin_settings, no de nueve.
     *
     * @return void
     */
    public function test_las_nueve_entradas_salen_de_una_sola_consulta(): void
    {
        $admin = $this->crear_admin();
        $this->cargar_los_nueve_settings($admin);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/implementation')->assertStatus(200);

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        $contra_settings = 0;
        foreach ($consultas as $consulta) {
            if (strpos($consulta['query'], 'admin_settings') !== false) {
                $contra_settings++;
            }
        }

        $this->assertSame(
            1,
            $contra_settings,
            "Se esperaba una sola consulta a admin_settings y hubo {$contra_settings}."
        );
    }

    /**
     * Guardar un setting y volver a pedir el lote en el mismo request devuelve el valor nuevo.
     *
     * Es la guarda del memo aplicada a esta pantalla: es exactamente lo que pasa cuando el admin
     * guarda un valor y el SPA recarga la sección.
     *
     * @return void
     */
    public function test_guardar_un_setting_y_volver_a_pedir_el_lote_devuelve_el_valor_nuevo(): void
    {
        $admin = $this->crear_admin();
        $this->cargar_los_nueve_settings($admin);

        $this->assertSame(
            42,
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/implementation')->json('settings.implementation-file-wait.seconds')
        );

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/settings/implementation-file-wait', ['seconds' => 99])
            ->assertStatus(200);

        $this->assertSame(
            99,
            $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings/implementation')->json('settings.implementation-file-wait.seconds'),
            'Después de guardar, el lote tiene que devolver el valor nuevo.'
        );
    }
}

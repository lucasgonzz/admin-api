<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoInstallationController;
use App\Jobs\RunDemoInstallationJob;
use App\Models\ClientSshCredential;
use App\Models\Demo;
use App\Models\DemoInstallation;
use App\Models\EnvTemplate;
use App\Models\Version;
use App\Services\DemoInstallationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Instalación desde cero del SISTEMA (ERP) de una demo.
 *
 * Lo que se prueba acá NO es que el SSH funcione —eso pasa en el servidor de la demo— sino las dos
 * cosas que sí se pueden equivocar de este lado y que nadie ve fallar:
 *
 *   1. Que crear la corrida la deje en `pendiente` y encole el job UNA vez. El pipeline es
 *      destructivo (su etapa `run_demo_setup` le hace `migrate:fresh` a la base de la demo), así
 *      que "se disparó dos veces" no es un detalle de eficiencia.
 *   2. Que el .env que se va a escribir tenga las cuatro claves que ninguna plantilla puede
 *      aportar, porque dependen de ESTA demo: APP_URL, USER_ID y las dos SANCTUM_*. Si alguna sale
 *      mal, la demo bootea igual y falla después: sin SANCTUM_* devuelve 419 en cada request con
 *      sesión, y con un USER_ID que no coincide el demo-setup siembra los datos colgando de un
 *      usuario y la tienda le pide su configuración a otro.
 *
 * Se invoca el controlador directo y no por HTTP porque las rutas de este recurso las agrega el
 * coordinador de la misión en un commit aparte.
 */
class InstalacionDesdeCeroDeUnaDemoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_crear_una_instalacion_la_deja_pendiente_y_encola_el_job_una_sola_vez(): void
    {
        Queue::fake();

        $demo    = $this->crear_demo();
        $version = $this->crear_version();

        $response = (new DemoInstallationController())->store_json(Request::create(
            '/demo-installation',
            'POST',
            [
                'demo_id'           => $demo->id,
                'version_id'        => $version->id,
                'env_manual_values' => ['DB_DATABASE' => 'demo_s11_test'],
            ]
        ));

        $this->assertSame(201, $response->getStatusCode());

        $model = $response->getData(true)['model'];
        $this->assertSame(DemoInstallation::STATUS_PENDIENTE, $model['status']);
        $this->assertSame($demo->id, $model['demo_id']);
        $this->assertSame($version->id, $model['version_id']);
        $this->assertNotEmpty($model['uuid']);

        // Los valores manuales quedan guardados: son las credenciales de la base que cargó el
        // operador y sin ellas la etapa write_env escribiría un .env sin conexión a la base.
        $installation = DemoInstallation::find($model['id']);
        $this->assertSame(['DB_DATABASE' => 'demo_s11_test'], $installation->env_manual_values);

        // Una sola vez. Ver el docblock de la clase.
        Queue::assertPushed(RunDemoInstallationJob::class, 1);
    }

    public function test_no_se_puede_borrar_una_instalacion_que_esta_corriendo(): void
    {
        $installation = $this->crear_instalacion(['status' => DemoInstallation::STATUS_INSTALANDO]);

        $response = (new DemoInstallationController())->destroy_json($installation->id);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotNull(DemoInstallation::find($installation->id));
    }

    public function test_el_env_de_la_demo_lleva_app_url_user_id_y_las_dos_sanctum(): void
    {
        $this->asegurar_credencial_ssh();

        /* Plantilla base: una variable común y una manual. La manual sólo se escribe si el operador
         * cargó su valor, que es lo que separa "la plantilla dice qué claves hay" de "el operador
         * dice cuáles son los secretos de ESTA demo". */
        EnvTemplate::create([
            'key'                 => 'APP_NAME_S11_TEST',
            'value'               => 'ComercioCity',
            'group'               => 'app',
            'scope'               => 'empresa',
            'is_manual_on_create' => false,
            'sort_order'          => 1,
        ]);
        EnvTemplate::create([
            'key'                 => 'DB_PASSWORD_S11_TEST',
            'value'               => '',
            'group'               => 'database',
            'scope'               => 'empresa',
            'is_manual_on_create' => true,
            'sort_order'          => 2,
        ]);
        /* Scope 'tienda': NO tiene que aparecer en el .env de un ERP. La tabla env_templates tiene
         * las dos plantillas conviviendo desde el prompt 580 y mezclarlas genera un .env con
         * variables de tienda-api adentro de empresa-api. */
        EnvTemplate::create([
            'key'                 => 'SOLO_DE_LA_TIENDA_S11_TEST',
            'value'               => 'no-va',
            'group'               => 'app',
            'scope'               => 'tienda',
            'is_manual_on_create' => false,
            'sort_order'          => 3,
        ]);

        $demo = $this->crear_demo([
            'erp_api_url' => 'https://api-demo9.comerciocity.com',
            'erp_spa_url' => 'https://demo9.comerciocity.com',
            'user_id'     => 4321,
        ]);

        $installation = $this->crear_instalacion([
            'demo_id'           => $demo->id,
            'env_manual_values' => ['DB_PASSWORD_S11_TEST' => 'un-secreto'],
        ]);

        $vars = (new DemoInstallationService($installation))->build_env_vars_to_write();

        /* 🔴 APP_URL va CRUDA, sin `/public`. La regla del /public es de VUE_APP_API_URL (lo que el
         * navegador le pide a la API), no de APP_URL (lo que Laravel cree que es su propia base).
         * Es el mismo criterio que InstallationService para un cliente. */
        $this->assertSame('https://api-demo9.comerciocity.com', $vars['APP_URL']);

        // USER_ID: el mismo número que el "ID de comercio" del catálogo, como string.
        $this->assertSame('4321', $vars['USER_ID']);

        // Sanctum: el HOST pelado en DOMAINS y la URL completa en CORS.
        $this->assertSame('demo9.comerciocity.com', $vars['SANCTUM_STATEFUL_DOMAINS']);
        $this->assertSame('https://demo9.comerciocity.com', $vars['SANCTUM_STATEFUL_CORS']);

        // La plantilla de empresa aporta sus claves; la de tienda no se mezcla.
        $this->assertSame('ComercioCity', $vars['APP_NAME_S11_TEST']);
        $this->assertArrayNotHasKey('SOLO_DE_LA_TIENDA_S11_TEST', $vars);

        // El valor manual del operador pisa el de la plantilla.
        $this->assertSame('un-secreto', $vars['DB_PASSWORD_S11_TEST']);
    }

    public function test_una_demo_sin_esquema_en_la_url_del_spa_igual_resuelve_las_sanctum(): void
    {
        $this->asegurar_credencial_ssh();

        /* El caso real: «ERP SPA URL» es texto libre del modal de Demos y se carga sin esquema muy
         * seguido. Medido con PHP 7.4.33, `parse_url('demo9.comerciocity.com', PHP_URL_HOST)`
         * devuelve NULL —sin puerto no reconoce host—, así que sin normalizar antes,
         * SANCTUM_STATEFUL_DOMAINS quedaba vacío. La demo bootea igual y devuelve 419 en cada
         * request con sesión, sin que nada del pipeline lo denuncie. */
        $demo = $this->crear_demo([
            'erp_spa_url' => 'demo9.comerciocity.com',
            'erp_api_url' => 'https://api-demo9.comerciocity.com',
        ]);

        $installation = $this->crear_instalacion(['demo_id' => $demo->id]);

        $vars = (new DemoInstallationService($installation))->build_env_vars_to_write();

        $this->assertSame('demo9.comerciocity.com', $vars['SANCTUM_STATEFUL_DOMAINS']);
        $this->assertSame('https://demo9.comerciocity.com', $vars['SANCTUM_STATEFUL_CORS']);
    }

    public function test_una_instalacion_sin_version_no_arranca_el_pipeline(): void
    {
        $this->asegurar_credencial_ssh();

        /* Se falla en el CONSTRUCTOR y no en la etapa que compila: sin tag no hay nada que
         * compilar ni empaquetar, y llegar hasta compile_spa significaría haber creado ya los
         * directorios y subido public/ a un subdominio para nada. */
        $installation = $this->crear_instalacion(['version_id' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tiene versión asociada/u');

        new DemoInstallationService($installation);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $atributos
     * @return Demo
     */
    private function crear_demo(array $atributos = []): Demo
    {
        return Demo::create(array_merge([
            'erp_spa_url'       => 'https://demo-s11.comerciocity.com',
            'erp_api_url'       => 'https://api-demo-s11.comerciocity.com',
            'ecommerce_spa_url' => 'https://tienda-demo-s11.comerciocity.com',
            'ecommerce_api_url' => 'https://api-tienda-demo-s11.comerciocity.com',
        ], $atributos));
    }

    /**
     * @return Version
     */
    private function crear_version(): Version
    {
        return Version::create([
            'version' => '9.9.' . random_int(1000, 999999),
            'title'   => 'Versión de prueba del slot 11',
            'status'  => 'published',
        ]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @return DemoInstallation
     */
    private function crear_instalacion(array $atributos = []): DemoInstallation
    {
        $defaults = [
            'demo_id'    => null,
            'version_id' => null,
            'status'     => DemoInstallation::STATUS_PENDIENTE,
        ];

        if (! array_key_exists('demo_id', $atributos) || $atributos['demo_id'] === null) {
            $defaults['demo_id'] = $this->crear_demo()->id;
        }

        // version_id se resuelve aparte: null es un valor válido y significativo para el test que
        // prueba justamente que sin versión no se arranca.
        if (! array_key_exists('version_id', $atributos)) {
            $defaults['version_id'] = $this->crear_version()->id;
        }

        return DemoInstallation::create(array_merge($defaults, $atributos));
    }

    /**
     * El constructor del service exige una credencial SSH del tipo de hosting de la demo. Se crea
     * sólo si el entorno de testing todavía no tiene una, para no ensuciar los datos del slot.
     *
     * @return void
     */
    private function asegurar_credencial_ssh(): void
    {
        if (ClientSshCredential::where('type', 'shared_hosting')->exists()) {
            return;
        }

        ClientSshCredential::create([
            'type'     => 'shared_hosting',
            'host'     => 'hosting.invalido.local',
            'port'     => 65002,
            'username' => 'usuario-de-prueba',
            'password' => 'no-se-usa-' . Str::random(8),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\RunClientInstallationGroupJob;
use App\Jobs\RunClientInstallationJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\DeploymentLog;
use App\Models\EnvTemplate;
use App\Models\Version;
use App\Services\EnvSshService;
use App\Services\HostingProvisioningService;
use App\Services\HostingerApiClient;
use App\Services\RemoteCommandRunner;
use App\Services\SharedHostingProvisioning;
use App\Services\VpsDatabaseProvisioner;
use App\Services\VpsHostingProvisioning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\EnvSshServiceFake;
use Tests\Fakes\HostingerApiClientFake;
use Tests\Fakes\RemoteCommandRunnerFake;
use Tests\TestCase;

/**
 * Aprovisionamiento del hosting del cliente desde el admin.
 *
 * Cubre las columnas nuevas (U2), el aprovisionamiento del hosting compartido (U3), el enganche en
 * el pipeline de instalación (U4) y el cron y el certificado del final (U5): el camino feliz de
 * punta a punta, la idempotencia del reintento, el fallo temprano sin token, la guarda G1 (en shared
 * no existe el PUT de DNS), las guardas de derivación del slug, el orden del pipeline, las DB_* del
 * .env, la guarda de Redis del VPS y los dos comandos exactos del cron.
 *
 * 🔴 Ningún test de este archivo sale a la red. HostingerApiClientFake sobreescribe UN método
 * —request(), que es transporte puro— así que todo lo que importa corre de verdad: el armado del
 * `directory` de cada subdominio, el payload exacto de cada POST y la clasificación de errores.
 */
class AprovisionamientoDeHostingDelClienteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Reemplazo en memoria del cliente HTTP de Hostinger, bindeado para toda la prueba.
     *
     * @var HostingerApiClientFake
     */
    private $hostinger;

    /**
     * Líneas que el proveedor mandó al panel de operaciones: ['step', 'linea', 'level'].
     *
     * @var array<int, array<string, string>>
     */
    private $lineas = [];

    /**
     * Reemplazo en memoria del servicio SSH que escribe el .env del cliente.
     *
     * @var EnvSshServiceFake|null
     */
    private $env_fake = null;

    /**
     * Reemplazo en memoria del runner de comandos remotos. Se crea perezosamente porque necesita la
     * credencial que el propio servicio resuelve.
     *
     * @var RemoteCommandRunnerFake|null
     */
    private $runner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostinger = new HostingerApiClientFake();
        $this->app->instance(HostingerApiClient::class, $this->hostinger);

        /*
         * 🔴 bind() con closure y no instance(): HostingProvisioningService::runner() resuelve el
         * runner con makeWith(['credential' => ...]), y el container ignora un binding de tipo
         * instance() cuando la resolución lleva parámetros (needsContextualBuild). Con el closure,
         * el fake recibe la credencial de verdad y devuelve siempre el mismo objeto, así que un
         * test puede leer todos los comandos de la corrida en un solo lugar.
         *
         * Sin esto, provision_sites() abriría un SSH real contra 127.0.0.1 en cada test.
         */
        $this->app->bind(RemoteCommandRunner::class, function ($app, $parametros) {
            if ($this->runner === null) {
                $this->runner = new RemoteCommandRunnerFake($parametros['credential']);
            }

            return $this->runner;
        });

        /*
         * La config se fija en el test y no se hereda del .env: si mañana admin_testing_s6 corre en
         * una máquina con HOSTINGER_API_TOKEN cargado, el test 3 (sin token) dejaría de probar nada.
         */
        config([
            'services.hostinger.api_token'        => 'token-de-prueba',
            'services.hostinger.account_username' => 'u767360347',
            'services.hostinger.domain'           => 'comerciocity.com',
            'services.hostinger.database_prefix'  => 'u767360347_',
            'services.hostinger.subdomain_directory_template'        => '{path}',
            'services.hostinger.subdomain_is_using_public_directory' => false,
            /* 0 = una sola lectura de la zona y sin sleep, para no colgar la suite. El test que
               prueba la espera lo sube a mano. */
            'services.hostinger.zone_wait_seconds'                   => 0,
            /* 0 = sin pausa entre los dos intentos de certificado (en produccion son 30 s). */
            'services.hostinger.ssl_retry_seconds'                   => 0,
        ]);
    }

    public function test_los_secretos_del_aprovisionamiento_quedan_cifrados_en_la_base(): void
    {
        $api = $this->crear_api_de_cliente();

        $api->provisioning_secrets = [
            'db_name'     => 'u767360347_lacava',
            'db_user'     => 'u767360347_lacava',
            'db_password' => 'Cl4ve-Secreta-De-La-Base',
        ];
        $api->hosting_provisioned_at = now();
        $api->save();

        /*
         * 🔴 Lo que este test protege es el cast. Si alguien lo saca —o lo cambia a 'array' porque
         * "el contenido es un array"—, la contraseña de la base de cada cliente queda en texto
         * plano en la base del admin, y nada más se pone en rojo: todo lo demás sigue funcionando
         * igual. Por eso se mira la columna cruda y no el modelo.
         */
        $crudo = DB::table('client_apis')->where('id', $api->id)->value('provisioning_secrets');

        $this->assertNotEmpty($crudo);
        $this->assertStringNotContainsString('Cl4ve-Secreta-De-La-Base', $crudo);
        $this->assertStringNotContainsString('u767360347_lacava', $crudo);

        /* Y el ida y vuelta tiene que devolver el array tal cual entró. */
        $recargada = ClientApi::find($api->id);

        $this->assertSame('Cl4ve-Secreta-De-La-Base', $recargada->provisioning_secrets['db_password']);
        $this->assertSame('u767360347_lacava', $recargada->provisioning_secrets['db_name']);
        $this->assertNotNull($recargada->hosting_provisioned_at);
        $this->assertSame('2026', $recargada->hosting_provisioned_at->format('Y'));
    }

    public function test_los_secretos_no_salen_nunca_serializados(): void
    {
        $api = $this->crear_api_de_cliente();

        $api->provisioning_secrets = ['db_password' => 'Cl4ve-Secreta-De-La-Base'];
        $api->save();

        /*
         * Esta relación viaja en el index y en el show de instalaciones, de upgrades y de clientes.
         * Si el $hidden no está, la contraseña sale descifrada en todos esos payloads.
         */
        $serializada = ClientApi::find($api->id)->toArray();

        $this->assertArrayNotHasKey('provisioning_secrets', $serializada);
        $this->assertStringNotContainsString('Cl4ve-Secreta-De-La-Base', json_encode($serializada));
    }

    public function test_la_columna_de_secretos_es_text_y_no_json(): void
    {
        /*
         * Mecánico a propósito: `json` parece el tipo correcto y no lo es. El cast encrypted:array
         * guarda el string de Laravel Crypt, que MySQL rechazaría en una columna json con "Invalid
         * JSON text" —y fallaría justo después de haber creado la base en Hostinger, que es el peor
         * momento para perder una contraseña que la API no deja volver a leer.
         */
        $columna = DB::selectOne("SHOW COLUMNS FROM client_apis LIKE 'provisioning_secrets'");

        $this->assertSame('text', strtolower($columna->Type));
        $this->assertSame('YES', $columna->Null);
    }

    public function test_una_instalacion_nueva_no_aprovisiona_nada_por_defecto(): void
    {
        $api = $this->crear_api_de_cliente();

        $instalacion = ClientInstallation::create([
            'client_id'     => $api->client_id,
            'client_api_id' => $api->id,
            'status'        => 'pendiente',
        ]);

        /*
         * El default null es lo que deja a todas las filas viejas —y a toda fila nueva creada por
         * un SPA que no manda el campo— corriendo el pipeline de siempre, sin backfill.
         */
        $this->assertNull($instalacion->fresh()->provision_hosting_type);

        $instalacion->provision_hosting_type = ClientInstallation::PROVISION_SHARED_HOSTING;
        $instalacion->save();

        $this->assertSame('shared_hosting', $instalacion->fresh()->provision_hosting_type);
        $this->assertSame(
            ['shared_hosting', 'vps'],
            ClientInstallation::PROVISION_HOSTING_TYPES
        );
        $this->assertSame(
            ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
            ClientInstallation::CLAVES_ENV_APROVISIONADAS
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // U3 — APROVISIONAMIENTO DEL HOSTING COMPARTIDO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 1 de §7: el camino feliz completo del hosting compartido.
     */
    public function test_camino_feliz_crea_los_cuatro_subdominios_y_la_base(): void
    {
        $datos     = $this->preparar_cliente_aprovisionable();
        $slug      = $datos['slug'];
        $proveedor = $datos['proveedor'];

        $this->responder_zona_completa($slug);
        $this->hostinger->responder('/databases', [], 'GET');
        $this->hostinger->responder('/databases', ['name' => 'u767360347_' . $slug], 'POST');

        $proveedor->provision_check();
        $proveedor->provision_sites();
        $proveedor->provision_dns();
        $proveedor->provision_db();

        /*
         * 🔴 El aserto que importa de este test es el `directory` de la API: '<slug>/api' y NUNCA
         * '<slug>/api/public'. Con '/public' ahí, ClientEmpresaApiUrlResolver le agrega otro
         * '/public' a la URL y el SPA pide '.../public/api/...' sobre un docroot que ya es public/:
         * 404 en todo el sistema. Es un bug que no rompe ningún otro test.
         */
        $posts_de_subdominio = $this->posts_a('/subdomains');
        $this->assertCount(4, $posts_de_subdominio);

        $esperados = [
            ['api-' . $slug, $slug . '/api'],
            [$slug, $slug . '/spa'],
            ['api-' . $slug . '2', $slug . '2/api'],
            [$slug . '2', $slug . '2/spa'],
        ];

        foreach ($esperados as $indice => $par) {
            $this->assertSame($par[0], $posts_de_subdominio[$indice]['body']['subdomain']);
            $this->assertSame($par[1], $posts_de_subdominio[$indice]['body']['directory']);
            $this->assertFalse($posts_de_subdominio[$indice]['body']['is_using_public_directory']);
            $this->assertStringNotContainsString(
                '/public',
                $posts_de_subdominio[$indice]['body']['directory']
            );
        }

        /* Una sola base, con el prefijo de la cuenta y el mismo nombre para base y usuario. */
        $posts_de_base = $this->posts_a('/databases');
        $this->assertCount(1, $posts_de_base);
        $this->assertSame('u767360347_' . $slug, $posts_de_base[0]['body']['name']);
        $this->assertSame('u767360347_' . $slug, $posts_de_base[0]['body']['user']);
        $this->assertSame('comerciocity.com', $posts_de_base[0]['body']['website_domain']);

        /* Las credenciales quedaron en las DOS ClientApi: comparten la base. */
        $password = $posts_de_base[0]['body']['password'];
        $this->assertSame(24, strlen($password));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9._-]+$/', $password));

        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $secretos = ClientApi::find($api->id)->provisioning_secrets;
            $this->assertSame($password, $secretos['db_password']);
            $this->assertSame('u767360347_' . $slug, $secretos['db_name']);
            $this->assertNotNull(ClientApi::find($api->id)->hosting_provisioned_at);
        }
    }

    /**
     * Test 2 de §7: con todo "ya existente" la corrida termina bien y no crea nada.
     *
     * Es el flujo normal ante una instalación fallida: el operador reintenta.
     */
    public function test_reintento_con_todo_ya_existente_no_crea_nada_nuevo(): void
    {
        $datos     = $this->preparar_cliente_aprovisionable();
        $slug      = $datos['slug'];
        $proveedor = $datos['proveedor'];

        /* La corrida anterior ya dejó la base creada y su contraseña guardada. */
        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $api->provisioning_secrets = [
                'db_name'     => 'u767360347_' . $slug,
                'db_user'     => 'u767360347_' . $slug,
                'db_password' => 'Contrasenia-Vieja-24-chars',
            ];
            $api->save();
        }

        $this->responder_zona_completa($slug);
        $this->hostinger->responder('/databases', [['name' => 'u767360347_' . $slug]], 'GET');
        $this->hostinger->fallar_con('/subdomains', 422, '{"message":"Subdomain already exists"}', 'POST');

        $proveedor->provision_check();
        $proveedor->provision_sites();
        $proveedor->provision_dns();
        $proveedor->provision_db();

        /* Se intentaron los 4 POST (la idempotencia se verifica contra el proveedor, no contra una
         * columna del admin: alguien pudo haber borrado un subdominio a mano). */
        $this->assertCount(4, $this->posts_a('/subdomains'));

        /* Pero la base NO se volvió a crear: se reusó la contraseña guardada. */
        $this->assertCount(0, $this->posts_a('/databases'));
        $this->assertCount(5, $proveedor->result()->ya_existian());
        $this->assertCount(0, $proveedor->result()->creados());
    }

    /**
     * Test 2b: la base ya existe y NO tenemos su contraseña → falla con el mensaje textual.
     *
     * No hay reintento posible: la API de Hostinger no deja leer ni resetear esa contraseña.
     */
    public function test_base_existente_sin_secreto_guardado_falla_con_el_mensaje_del_plan(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $slug  = $datos['slug'];

        $this->hostinger->responder('/databases', [['name' => 'u767360347_' . $slug]], 'GET');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no permite leerla ni resetearla');

        $datos['proveedor']->provision_db();
    }

    /**
     * Test 3 de §7: sin token no se escribe NADA. Es la decisión 4 del plan.
     */
    public function test_sin_token_falla_en_el_preflight_y_no_escribe_nada(): void
    {
        config(['services.hostinger.api_token' => '']);

        $datos = $this->preparar_cliente_aprovisionable();

        try {
            $datos['proveedor']->provision_check();
            $this->fail('El preflight tenía que fallar sin token.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString('HOSTINGER_API_TOKEN', $excepcion->getMessage());
        }

        $this->assertSame([], $this->hostinger->escrituras());
    }

    /**
     * Test 4 de §7 — GUARDA G1: en hosting compartido NO existe el PUT de DNS.
     *
     * Se verifica de las dos maneras a propósito. La primera (ninguna llamada PUT) prueba la
     * corrida; la segunda (el código fuente no nombra put_dns_zone) prueba que no hay ninguna rama
     * que pueda llegar ahí en un caso que este test no ejercitó. Ese PUT va sobre la zona donde
     * viven los subdominios de los ~40 clientes activos.
     */
    public function test_en_hosting_compartido_no_hay_ninguna_escritura_de_dns(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $this->responder_zona_completa($datos['slug']);
        $this->hostinger->responder('/databases', [], 'GET');

        $datos['proveedor']->provision_check();
        $datos['proveedor']->provision_sites();
        $datos['proveedor']->provision_dns();
        $datos['proveedor']->provision_db();

        $this->assertSame([], $this->hostinger->llamadas_de('PUT'));

        foreach ($this->hostinger->llamadas as $llamada) {
            if (strpos($llamada['ruta'], '/dns/') !== false) {
                $this->assertSame('GET', $llamada['metodo'], 'El DNS del shared es de SOLO LECTURA.');
            }
        }

        /* Se busca la LLAMADA ('->put_dns_zone(') y no el nombre pelado: el docblock de la clase
         * nombra el método justamente para explicar por qué no se usa, y esa mención tiene que
         * poder quedarse. */
        $archivos = ['Services/SharedHostingProvisioning.php', 'Services/SharedHostingSubdomains.php'];

        foreach ($archivos as $archivo) {
            $fuente = file_get_contents(app_path($archivo));
            $this->assertStringNotContainsString('->put_dns_zone(', $fuente, $archivo);
            $this->assertStringNotContainsString('->create_dns_snapshot(', $fuente, $archivo);
        }
    }

    /**
     * Test 4b: si falta un A record en la zona, el paso FALLA y dice cuál. No lo escribe.
     */
    public function test_si_falta_un_a_record_el_paso_falla_y_nombra_el_que_falta(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $slug  = $datos['slug'];

        /* Están tres de los cuatro: falta <slug>2. */
        $this->hostinger->responder('/api/dns/v1/zones/', [
            ['name' => 'api-' . $slug, 'type' => 'A'],
            ['name' => $slug, 'type' => 'A'],
            ['name' => 'api-' . $slug . '2', 'type' => 'A'],
        ], 'GET');

        try {
            $datos['proveedor']->provision_dns();
            $this->fail('Tenía que fallar: falta un A record.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString($slug . '2', $excepcion->getMessage());
        }

        $this->assertSame([], $this->hostinger->escrituras());
    }

    /**
     * Test 13 de §7: un par de APIs con nombres no estándar frena en el preflight, sin escribir.
     */
    public function test_slug_inconsistente_frena_el_preflight_sin_escribir_nada(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();

        /* La segunda API deja de ser "<slug>2" y pasa a ser otra cosa. */
        $datos['api2']->spa_url = 'https://otracosa2.comerciocity.com';
        $datos['api2']->url     = 'https://api-otracosa2.comerciocity.com';
        $datos['api2']->save();

        try {
            $datos['proveedor']->provision_check();
            $this->fail('Tenía que frenar: el par no es <slug> / <slug>2.');
        } catch (\RuntimeException $excepcion) {
            $this->assertStringContainsString('par estándar', $excepcion->getMessage());
        }

        $this->assertSame([], $this->hostinger->escrituras());
    }

    /**
     * Guarda 1 de §1.4: con una sola ClientApi no hay forma de derivar los 4 subdominios.
     */
    public function test_un_cliente_con_una_sola_api_no_se_aprovisiona(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $datos['api2']->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exactamente 2');

        $datos['proveedor']->provision_check();
    }

    /**
     * El alfabeto de las contraseñas generadas es el acotado de §3.2, y no por gusto: estos valores
     * viajan por línea de comando SSH y por el `sed -i` de EnvSshService.
     */
    public function test_las_contrasenias_generadas_no_traen_caracteres_que_rompan_el_shell(): void
    {
        $datos  = $this->preparar_cliente_aprovisionable();
        $metodo = new \ReflectionMethod($datos['proveedor'], 'generar_password');
        $metodo->setAccessible(true);

        for ($i = 0; $i < 40; $i++) {
            $password = $metodo->invoke($datos['proveedor']);

            $this->assertSame(24, strlen($password));
            $this->assertSame(1, preg_match('/^[A-Za-z0-9._-]{24}$/', $password), $password);
            $this->assertSame(1, preg_match('/[A-Z]/', $password), $password);
            $this->assertSame(1, preg_match('/[a-z]/', $password), $password);
            $this->assertSame(1, preg_match('/[0-9]/', $password), $password);
        }
    }

    /**
     * La fábrica devuelve el proveedor del hosting compartido y rechaza el resto.
     */
    public function test_la_fabrica_elige_el_proveedor_por_el_tipo_de_la_fila(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();

        $this->assertInstanceOf(SharedHostingProvisioning::class, $datos['proveedor']);

        $datos['installation']->provision_hosting_type = null;
        $datos['installation']->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no pide aprovisionar');

        HostingProvisioningService::para(
            $datos['installation']->fresh(),
            $datos['api1'],
            function ($step, $linea, $level) {
                // Sin log en este caso: la fábrica falla antes de instanciar nada.
            }
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // U4 — ENGANCHE EN EL PIPELINE DE INSTALACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El pipeline de una fila real con aprovisionamiento arranca con los 4 pasos nuevos y termina
     * con el cron y el certificado.
     *
     * 🔴 provision_check ANTES de compile_spa no es orden estético: es el paso que puede pasar las
     * ClientApi a hosting_type='vps', y compile_spa compila el bundle agregándole '/public' a la URL
     * cuando el hosting es compartido. Con el flip llegando tarde, el SPA queda pidiendo
     * '.../public' contra un VPS cuyo docroot ya es public/ → 404 en todo el sistema.
     */
    public function test_el_pipeline_real_con_aprovisionamiento_arranca_por_el_preflight(): void
    {
        $datos   = $this->preparar_cliente_aprovisionable();
        $service = new \App\Services\InstallationService($datos['installation']);

        $this->assertSame(
            [
                'provision_check',
                'provision_sites',
                'provision_dns',
                'provision_db',
                'compile_spa',
                'upload_spa',
                'upload_api',
                'write_env',
                'finalize_api',
                'provision_cron',
                'provision_ssl',
            ],
            $this->steps_de($service)
        );
    }

    /**
     * El esqueleto aprovisiona igual (los 4 subdominios son de las dos instancias) pero NO crea el
     * cron ni pide el certificado: no tiene vendor/ ni sistema.
     */
    public function test_el_pipeline_del_esqueleto_aprovisiona_pero_no_crea_cron_ni_certificado(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();

        $esqueleto = ClientInstallation::create([
            'client_id'              => $datos['client']->id,
            'client_api_id'          => $datos['api2']->id,
            'kind'                   => ClientInstallation::KIND_ESQUELETO,
            'status'                 => 'pendiente',
            'provision_hosting_type' => ClientInstallation::PROVISION_SHARED_HOSTING,
        ]);

        $steps = $this->steps_de(new \App\Services\InstallationService($esqueleto));

        $this->assertSame(
            ['provision_check', 'provision_sites', 'provision_dns', 'provision_db',
             'prepare_dirs', 'upload_public', 'write_env', 'finalize_skeleton'],
            $steps
        );
        $this->assertNotContains('provision_cron', $steps);
        $this->assertNotContains('provision_ssl', $steps);
    }

    /**
     * 🔴 Contrato viejo intacto: sin provision_hosting_type el pipeline es el de siempre, byte por
     * byte. Es lo que hace que las filas ya existentes —y las que cree un SPA que no manda el
     * campo— no cambien de comportamiento.
     */
    public function test_sin_aprovisionamiento_el_pipeline_es_exactamente_el_de_siempre(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();

        $datos['installation']->provision_hosting_type = null;
        $datos['installation']->save();

        $service = new \App\Services\InstallationService($datos['installation']->fresh());

        $this->assertSame(
            ['compile_spa', 'upload_spa', 'upload_api', 'write_env', 'finalize_api'],
            $this->steps_de($service)
        );
    }

    /**
     * step_write_env() saca las DB_* de los secretos del aprovisionamiento, no de env_manual_values.
     */
    public function test_el_env_se_escribe_con_las_db_del_aprovisionamiento(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $slug  = $datos['slug'];
        $this->crear_templates_de_env();

        /* Lo que dejó provision_db: las dos ClientApi comparten la misma base. */
        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $api->provisioning_secrets = [
                'db_name'     => 'u767360347_' . $slug,
                'db_user'     => 'u767360347_' . $slug,
                'db_password' => 'Un4-Clave-Generada-Xyz12',
            ];
            $api->save();
        }

        /* Y lo que el operador dejó cargado a mano de una vez anterior: NO tiene que ganar. */
        $datos['installation']->env_manual_values = ['DB_DATABASE' => 'base_vieja_a_mano'];
        $datos['installation']->save();

        $this->correr_write_env($datos['installation']->fresh());

        $escrito = $this->env_fake->escrituras[$datos['api1']->id];

        $this->assertSame('u767360347_' . $slug, $escrito['DB_DATABASE']);
        $this->assertSame('u767360347_' . $slug, $escrito['DB_USERNAME']);
        $this->assertSame('Un4-Clave-Generada-Xyz12', $escrito['DB_PASSWORD']);
    }

    /**
     * Si la fila pide aprovisionar y no hay secretos guardados, write_env FALLA.
     *
     * Escribir un .env con DB_DATABASE vacío deja un sistema instalado que no bootea, y eso se
     * descubre con el cliente adentro.
     */
    public function test_sin_secretos_guardados_el_write_env_falla_en_vez_de_escribir_vacio(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $this->crear_templates_de_env();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no tiene guardado el secreto');

        $this->correr_write_env($datos['installation']);
    }

    /**
     * Test 14 de §7 — guarda de Redis: hosting vps + CACHE_DRIVER=redis sin prefijo derivable → la
     * etapa falla.
     *
     * El VPS tiene UN SOLO Redis para todos los clientes. Sin prefijo, las claves de este cliente
     * viven en el mismo keyspace que las de los demás.
     */
    public function test_vps_con_redis_y_sin_prefijo_derivable_falla_la_etapa(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $this->crear_templates_de_env();
        $this->crear_template_de_env('CACHE_DRIVER', 'redis');

        /* Desde U9 el constructor del servicio resuelve la credencial por el hosting del destino. */
        $this->crear_credencial_vps();

        /* spa_url fuera del dominio de config: el label no se puede derivar y queda vacío. */
        $datos['api1']->hosting_type = 'vps';
        $datos['api1']->vps_path     = $datos['slug'];
        $datos['api1']->spa_url      = 'https://cliente.otrodominio.com';
        $datos['api1']->save();

        $datos['installation']->provision_hosting_type = null;
        $datos['installation']->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('un solo Redis');

        $this->correr_write_env($datos['installation']->fresh());
    }

    /**
     * Con hosting vps y un spa_url normal, los dos prefijos se escriben siempre, aunque el .env de
     * hoy no use Redis: deja el terreno listo y es inofensivo con file/database.
     */
    public function test_vps_escribe_siempre_los_prefijos_de_redis(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $slug  = $datos['slug'];
        $this->crear_templates_de_env();

        $this->crear_credencial_vps();

        $datos['api1']->hosting_type = 'vps';
        $datos['api1']->vps_path     = $slug;
        $datos['api1']->save();

        $datos['installation']->provision_hosting_type = null;
        $datos['installation']->save();

        $this->correr_write_env($datos['installation']->fresh());

        $escrito = $this->env_fake->escrituras[$datos['api1']->id];

        /* Con guion bajo al final: sin él, 'lacava' + '2:foo' y 'lacava2' + ':foo' son la misma
         * clave, y las dos instancias del mismo cliente se pisarían entre sí. */
        $this->assertSame($slug . '_', $escrito['CACHE_PREFIX']);
        $this->assertSame($slug . '_', $escrito['REDIS_PREFIX']);
    }

    /**
     * En hosting compartido no se toca nada de Redis: es una sola instalación por carpeta y no hay
     * Redis compartido que colisionar. Y tampoco se escribe la bandera VPS (ver el test de abajo).
     */
    public function test_en_hosting_compartido_no_se_escriben_prefijos_de_redis(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $this->crear_templates_de_env();

        $datos['installation']->provision_hosting_type = null;
        $datos['installation']->save();

        $this->correr_write_env($datos['installation']->fresh());

        $escrito = $this->env_fake->escrituras[$datos['api1']->id];

        $this->assertArrayNotHasKey('CACHE_PREFIX', $escrito);
        $this->assertArrayNotHasKey('REDIS_PREFIX', $escrito);
    }

    /**
     * 🔴 HALLAZGO A — una instalación en VPS escribe VPS=true en el .env del cliente, y una en
     * hosting compartido NO escribe esa clave de ninguna forma.
     *
     * Verificado el 31/8/2026 contra `origin/develop` de empresa-api. Sin esa variable pasan las
     * dos cosas a la vez, y ninguna avisa:
     *
     *   • `config/filesystems.php:44` le agrega '/public' a la URL del disco público salvo que
     *     env('VPS') sea verdadera. En el VPS el docroot YA es empresa-api/public, así que toda
     *     imagen y todo adjunto del cliente sale como .../public/storage/... → 404 en TODOS los
     *     archivos. Los clientes migrados a mano (demo2, demo3, grupolimp) la tienen puesta a mano
     *     por esto (§F5 del informe del 26/8).
     *   • `app/Console/Kernel.php` saltea el queue:work del scheduler cuando VPS=true, que es de
     *     donde sale el hallazgo B.
     *
     * Y no se escribe en el compartido —ni como 'false'— porque hoy no existe en el .env de ninguno
     * de los ~40 clientes de esa cuenta y el default de env('VPS') ya es false: sería tocarle el
     * .env a 40 clientes para dejar todo exactamente igual.
     */
    public function test_en_vps_el_env_lleva_la_bandera_vps_y_en_compartido_no(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $this->crear_templates_de_env();
        $this->crear_credencial_vps();

        $datos['api1']->hosting_type = 'vps';
        $datos['api1']->vps_path     = $datos['slug'];
        $datos['api1']->save();

        $datos['installation']->provision_hosting_type = null;
        $datos['installation']->save();

        $this->correr_write_env($datos['installation']->fresh());

        /* 'true' y no '1': es el literal que el dotenv de Laravel castea a booleano. */
        $escrito_en_vps = $this->env_fake->escrituras[$datos['api1']->id];
        $this->assertSame('true', $escrito_en_vps['VPS']);

        /* El mismo cliente en compartido: la clave no aparece. (correr_write_env() reemplaza el
         * fake, así que lo de arriba se lee ANTES de la segunda corrida.) */
        $otros = $this->preparar_cliente_aprovisionable();
        $otros['installation']->provision_hosting_type = null;
        $otros['installation']->save();

        $this->correr_write_env($otros['installation']->fresh());

        $this->assertArrayNotHasKey('VPS', $this->env_fake->escrituras[$otros['api1']->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // U5 — CRON Y CERTIFICADO AL FINAL DEL PIPELINE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test 11 de §7 — el comando del cron, string por string.
     *
     * 🔴 Las dos reglas salen del README de crons-hostinger y no se escriben de memoria:
     *  - Kernel optimizado → `schedule:run` SIN flock, porque el Kernel ya usa withoutOverlapping(75);
     *  - Kernel viejo → `queue:work --stop-when-empty` CON `flock -n /tmp/queue-<slug>.lock`, porque
     *    sin flock una cola que tarda más de un minuto apila workers, que es el problema que este
     *    cron viene a evitar.
     *
     * Y la ruta va ABSOLUTA (/home/<cuenta>/domains/...), como los crons de producción: es además lo
     * que hace que crons_for_api_path() encuentre después este mismo cron, porque esa función busca
     * '/<api_path>/artisan' con la barra adelante.
     */
    public function test_el_comando_del_cron_es_exactamente_el_del_informe(): void
    {
        $datos     = $this->preparar_cliente_aprovisionable();
        $slug      = $datos['slug'];
        $api_path  = 'domains/comerciocity.com/public_html/' . $slug . '/api';
        $absoluta  = '/home/u767360347/' . $api_path;
        $proveedor = $datos['proveedor'];

        $this->assertSame(
            '/usr/bin/php ' . $absoluta . '/artisan schedule:run',
            $proveedor->comando_de_cron($api_path, true)
        );
        $this->assertStringNotContainsString('flock', $proveedor->comando_de_cron($api_path, true));

        $this->assertSame(
            '/usr/bin/flock -n /tmp/queue-' . $slug . '.lock /usr/bin/php '
                . $absoluta . '/artisan queue:work --stop-when-empty',
            $proveedor->comando_de_cron($api_path, false)
        );
    }

    /**
     * Test 12 de §7 — un solo cron, y solo en la instancia que recibió la instalación real.
     *
     * El esqueleto no tiene vendor/ ni sistema: un schedule:run ahí escupe un fatal de PHP una vez
     * por minuto, para siempre, contra un servidor que ya está a load 14.
     */
    public function test_se_crea_un_solo_cron_y_solo_en_la_instancia_real(): void
    {
        $datos    = $this->preparar_cliente_aprovisionable();
        $slug     = $datos['slug'];
        $api_path = 'domains/comerciocity.com/public_html/' . $slug . '/api';

        /* La cuenta tiene crons de otros clientes y comandos de negocio: ninguno es de esta ruta. */
        $this->hostinger->responder('/cron-jobs', [
            ['uid' => 'aaa', 'time' => '* * * * *', 'command' => '/usr/bin/php /home/u767360347/domains/comerciocity.com/public_html/otro/api/artisan schedule:run'],
            ['uid' => 'bbb', 'time' => '0 3 * * *', 'command' => '/usr/bin/php /home/u767360347/domains/comerciocity.com/public_html/' . $slug . '/api/artisan check_stocks'],
        ], 'GET');
        $this->hostinger->responder('/cron-jobs', ['uid' => 'nuevo-uid'], 'POST');

        $datos['proveedor']->provision_cron($api_path, true);

        $posts = $this->posts_a('/cron-jobs');
        $this->assertCount(1, $posts);
        $this->assertSame('* * * * *', $posts[0]['body']['time']);
        $this->assertStringContainsString('schedule:run', $posts[0]['body']['command']);

        /* El uid queda guardado: es lo único con lo que después se puede mover o borrar este cron. */
        $this->assertSame('nuevo-uid', ClientApi::find($datos['api1']->id)->provisioning_secrets['cron_uid']);

        /* Y el pipeline del esqueleto ni siquiera tiene la etapa (ya fijado arriba), así que un
         * grupo de dos filas hace exactamente un POST de cron. */
        $esqueleto = ClientInstallation::create([
            'client_id'              => $datos['client']->id,
            'client_api_id'          => $datos['api2']->id,
            'kind'                   => ClientInstallation::KIND_ESQUELETO,
            'status'                 => 'pendiente',
            'provision_hosting_type' => ClientInstallation::PROVISION_SHARED_HOSTING,
        ]);

        $this->assertNotContains(
            'provision_cron',
            $this->steps_de(new \App\Services\InstallationService($esqueleto))
        );
    }

    /**
     * Si esa instancia ya tiene un cron de cola, no se crea otro: warning y se sigue.
     *
     * Dos crons de cola sobre la misma ruta no rompen nada (el flock los serializa) pero duplican
     * carga en un servidor que ya está al límite.
     */
    public function test_si_ya_hay_un_cron_de_cola_no_se_crea_otro(): void
    {
        $datos    = $this->preparar_cliente_aprovisionable();
        $slug     = $datos['slug'];
        $api_path = 'domains/comerciocity.com/public_html/' . $slug . '/api';

        $this->hostinger->responder('/cron-jobs', [
            ['uid' => 'ya-estaba', 'time' => '* * * * *', 'command' => '/usr/bin/php /home/u767360347/' . $api_path . '/artisan schedule:run'],
        ], 'GET');

        $datos['proveedor']->provision_cron($api_path, true);

        $this->assertCount(0, $this->posts_a('/cron-jobs'));
        $this->assertSame('ya-estaba', $datos['proveedor']->result()->ya_existian()[0]['nombre']);
    }

    /**
     * Los crons de comandos de negocio de la misma instancia NO cuentan como cron de cola.
     *
     * Al 26/8/2026 había 47 cronjobs de ese tipo en la cuenta (set_company_performances,
     * check_stocks, etc.) y ninguno está en el Kernel.php: tratarlos como reemplazables apagaría
     * funcionalidad sin reemplazo.
     */
    public function test_un_cron_de_negocio_no_impide_crear_el_de_la_cola(): void
    {
        $datos    = $this->preparar_cliente_aprovisionable();
        $slug     = $datos['slug'];
        $api_path = 'domains/comerciocity.com/public_html/' . $slug . '/api';

        $this->hostinger->responder('/cron-jobs', [
            ['uid' => 'negocio', 'time' => '0 2 * * *', 'command' => '/usr/bin/php /home/u767360347/' . $api_path . '/artisan set_company_performances'],
        ], 'GET');
        $this->hostinger->responder('/cron-jobs', ['uid' => 'uid-nuevo'], 'POST');

        $datos['proveedor']->provision_cron($api_path, false);

        $this->assertCount(1, $this->posts_a('/cron-jobs'));
        $this->assertStringContainsString('flock -n /tmp/queue-' . $slug . '.lock', $this->posts_a('/cron-jobs')[0]['body']['command']);
    }

    /**
     * En hosting compartido el certificado es un no-op: Hostinger lo emite por su cuenta.
     */
    public function test_el_certificado_en_shared_no_llama_a_nadie(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();

        $datos['proveedor']->provision_ssl();

        $this->assertSame([], $this->hostinger->llamadas);
        $this->assertStringContainsString('Hostinger emite el certificado', $this->ultima_linea('provision_ssl'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // U8 — APROVISIONAMIENTO EN EL VPS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El pendiente que dejó U3: el `mkdir -p` del directorio va ANTES del POST del subdominio.
     *
     * No se pudo verificar si la API de Hostinger crea sola la carpeta que recibe en `directory`
     * (§10.1). Si no la crea, el subdominio queda apuntando a la nada y el error recién aparece
     * quince minutos después, en upload_spa.
     */
    public function test_en_shared_el_directorio_se_crea_por_ssh_antes_del_post(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $slug  = $datos['slug'];

        $this->responder_zona_completa($slug);
        $datos['proveedor']->provision_sites();

        $mkdirs = $this->runner_fake()->crudos_con('mkdir -p');
        $this->assertCount(4, $mkdirs);

        /* 🔴 Con las comillas puestas: lo que se fija acá es el comando Y su escapado. */
        $raiz = 'domains/comerciocity.com/public_html/';
        $this->assertSame('mkdir -p ' . RemoteCommandRunner::escapar_argumento($raiz . $slug . '/api'), $mkdirs[0]);
        $this->assertSame('mkdir -p ' . RemoteCommandRunner::escapar_argumento($raiz . $slug . '/spa'), $mkdirs[1]);
        $this->assertSame('mkdir -p ' . RemoteCommandRunner::escapar_argumento($raiz . $slug . '2/api'), $mkdirs[2]);
        $this->assertSame('mkdir -p ' . RemoteCommandRunner::escapar_argumento($raiz . $slug . '2/spa'), $mkdirs[3]);

        /* Y los 4 POST se hicieron igual: el mkdir se suma, no reemplaza nada. */
        $this->assertCount(4, $this->posts_a('/subdomains'));
    }

    /**
     * La fábrica devuelve el proveedor del VPS cuando la fila lo pide.
     */
    public function test_la_fabrica_devuelve_el_proveedor_del_vps(): void
    {
        $datos = $this->preparar_cliente_vps();

        $this->assertInstanceOf(VpsHostingProvisioning::class, $datos['proveedor']);
    }

    /**
     * El preflight del VPS verifica los binarios y NO ESCRIBE NADA. El flip a hosting_type='vps' lo
     * hace provision_sites, cuando los 4 sitios ya existen.
     *
     * 🔴 Hasta el 31/8/2026 este test afirmaba lo contrario —que el preflight dejaba las dos
     * ClientApi en 'vps'— y esa era exactamente la forma del hallazgo D: el paso documentado como
     * "preflight, no escribe nada" escribía en nuestra base la columna que DeploymentService usa
     * para resolver credencial, api_path y docroot de TODO upgrade futuro del cliente. Si después
     * fallaba provision_sites, las dos filas quedaban diciendo 'vps' sin que existiera un solo sitio
     * del otro lado, el cliente seguía sirviendo desde el compartido y el admin ya no sabía llegar
     * ahí. La aserción cambió porque cambió el comportamiento, y el comportamiento nuevo es el
     * correcto: el estado guardado no puede mentir.
     *
     * 🔴 Y sigue sin tocar client_apis.path. Un path vacío en una ClientApi de VPS es lo que hace
     * que build_spa_hosting_deploy_shell() arme el docroot en la raíz de la cuenta compartida y el
     * `find . -mindepth 1 -delete` vacíe el public_html de los ~40 clientes activos.
     */
    public function test_el_preflight_del_vps_no_escribe_y_el_flip_llega_con_los_sitios(): void
    {
        $datos     = $this->preparar_cliente_vps();
        $slug      = $datos['slug'];
        $path_1    = $datos['api1']->path;
        $path_2    = $datos['api2']->path;

        $datos['proveedor']->provision_check();

        $crudos = $this->runner_fake()->crudos;
        $this->assertContains('command -v clpctl', $crudos);
        $this->assertContains('command -v supervisorctl', $crudos);

        /* 🔴 El preflight no dejó ni una escritura en nuestra base. */
        $this->assertSame('shared_hosting', ClientApi::find($datos['api1']->id)->hosting_type);
        $this->assertSame('shared_hosting', ClientApi::find($datos['api2']->id)->hosting_type);
        $this->assertNull(ClientApi::find($datos['api1']->id)->vps_path);

        $datos['proveedor']->provision_sites();

        $api1 = ClientApi::find($datos['api1']->id);
        $api2 = ClientApi::find($datos['api2']->id);

        $this->assertSame('vps', $api1->hosting_type);
        $this->assertSame('vps', $api2->hosting_type);
        $this->assertSame($slug, $api1->vps_path);
        $this->assertSame($slug . '2', $api2->vps_path);

        /* 🔴 El path viejo sigue intacto. */
        $this->assertSame($path_1, $api1->path);
        $this->assertSame($path_2, $api2->path);
    }

    /**
     * 🔴 HALLAZGO D — si el aprovisionamiento se cae ANTES de que los sitios existan, las ClientApi
     * NO quedan mintiendo.
     *
     * Es el test que fija el arreglo. El `readlink` del segundo docroot devuelve otra cosa (lo que
     * pasa cuando el `rmdir` falló porque el directorio tenía contenido), así que provision_sites
     * revienta a mitad de camino: con el flip en el preflight, las dos filas ya estaban en 'vps' y
     * ahí se quedaban para siempre. Ahora siguen en 'shared_hosting', que es donde el cliente está
     * sirviendo de verdad, y el próximo upgrade lo encuentra.
     */
    public function test_si_provision_sites_falla_las_apis_no_quedan_marcadas_como_vps(): void
    {
        /* Antes de preparar: en el fake gana la primera regla que matchea. */
        $this->runner_fake()->responder('readlink', '/otra/cosa');

        $datos = $this->preparar_cliente_vps();

        $this->assertStringContainsString(
            'no quedó apuntando a',
            $this->mensaje_de_error(function () use ($datos) {
                $datos['proveedor']->provision_check();
                $datos['proveedor']->provision_sites();
            })
        );

        $this->assertSame('shared_hosting', ClientApi::find($datos['api1']->id)->hosting_type);
        $this->assertSame('shared_hosting', ClientApi::find($datos['api2']->id)->hosting_type);
        $this->assertNull(ClientApi::find($datos['api1']->id)->vps_path);
        $this->assertNull(ClientApi::find($datos['api2']->id)->vps_path);
    }

    /**
     * Sin clpctl en el VPS, el preflight falla y no marca nada.
     */
    public function test_sin_clpctl_el_preflight_del_vps_falla(): void
    {
        /* Antes de preparar: en el fake gana la primera regla que matchea. */
        $this->runner_fake()->responder('command -v clpctl', '');

        $datos = $this->preparar_cliente_vps();

        $this->assertStringContainsString(
            'clpctl',
            $this->mensaje_de_error(function () use ($datos) {
                $datos['proveedor']->provision_check();
            })
        );

        $this->assertSame('shared_hosting', ClientApi::find($datos['api1']->id)->hosting_type);
    }

    /**
     * Los 4 sitios de CloudPanel, con el vhost Generic, y el symlink del docroot de las dos APIs.
     *
     * 🔴 `rmdir` y NUNCA `rm -rf`: en un reintento sobre un sitio ya instalado, un `rm -rf` acá
     * borra el docroot de un cliente que está sirviendo producción.
     */
    public function test_los_sitios_del_vps_usan_rmdir_y_nunca_rm_rf(): void
    {
        $datos = $this->preparar_cliente_vps();
        $slug  = $datos['slug'];

        $datos['proveedor']->provision_sites();

        $sitios = $this->runner_fake()->crudos_con('clpctl site:add:php');
        $this->assertCount(4, $sitios);

        $primero = $sitios[0];
        $this->assertStringContainsString(
            '--domainName=' . RemoteCommandRunner::escapar_argumento('api-' . $slug . '.comerciocity.com'),
            $primero
        );
        $this->assertStringContainsString('--phpVersion=' . RemoteCommandRunner::escapar_argumento('7.4'), $primero);
        $this->assertStringContainsString('--vhostTemplate=' . RemoteCommandRunner::escapar_argumento('Generic'), $primero);
        $this->assertStringContainsString('--siteUser=' . RemoteCommandRunner::escapar_argumento('api-' . $slug), $primero);

        /* 🔴 El aserto que importa de este test. */
        $this->assertCount(2, $this->runner_fake()->crudos_con('rmdir '));
        $this->assertSame([], $this->runner_fake()->crudos_con('rm -rf'));
        $this->assertSame([], $this->runner_fake()->crudos_con('rm -r'));

        /* El symlink es solo de las dos APIs; el docroot del SPA es htdocs/<dominio> tal cual. */
        $enlaces = $this->runner_fake()->crudos_con('ln -sfn');
        $this->assertCount(2, $enlaces);
        $this->assertSame(
            'ln -sfn ' . RemoteCommandRunner::escapar_argumento('/home/api-' . $slug . '/empresa-api/public')
                . ' ' . RemoteCommandRunner::escapar_argumento('/home/api-' . $slug . '/htdocs/api-' . $slug . '.comerciocity.com'),
            $enlaces[0]
        );
    }

    /**
     * Si el docroot no quedó siendo el symlink, la etapa falla y NO borra nada.
     */
    public function test_un_docroot_con_contenido_hace_fallar_la_etapa_sin_borrar(): void
    {
        /* Antes de preparar: en el fake gana la primera regla que matchea. */
        $this->runner_fake()->responder('readlink', '');

        $datos = $this->preparar_cliente_vps();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no quedó apuntando a');

        $datos['proveedor']->provision_sites();
    }

    /**
     * La base del VPS va SIN el prefijo u767360347_ (§F3 del informe de migración).
     */
    public function test_la_base_del_vps_no_lleva_el_prefijo_de_la_cuenta_compartida(): void
    {
        $datos = $this->preparar_cliente_vps();
        $slug  = $datos['slug'];

        $datos['proveedor']->provision_db();

        $comandos = $this->runner_fake()->crudos_con('clpctl db:add');
        $this->assertCount(1, $comandos);

        $comando = $comandos[0];
        $this->assertStringContainsString('--databaseName=' . RemoteCommandRunner::escapar_argumento($slug), $comando);
        $this->assertStringContainsString('--databaseUserName=' . RemoteCommandRunner::escapar_argumento($slug), $comando);
        $this->assertStringNotContainsString('u767360347_', $comando);

        $this->assertSame($slug, ClientApi::find($datos['api1']->id)->provisioning_secrets['db_name']);
    }

    /**
     * El cron del VPS usa el patrón idempotente del plan y es SIEMPRE `schedule:run`.
     */
    public function test_el_cron_del_vps_es_idempotente_y_siempre_schedule_run(): void
    {
        $datos    = $this->preparar_cliente_vps();
        $slug     = $datos['slug'];
        $api_path = '/home/api-' . $slug . '/empresa-api';

        $datos['proveedor']->provision_cron($api_path, true);

        $crons = $this->runner_fake()->crudos_con('crontab');
        $this->assertCount(1, $crons);

        $comando = $crons[0];
        $this->assertStringContainsString(
            'crontab -u ' . RemoteCommandRunner::escapar_argumento('api-' . $slug) . ' -l >"$TMP" 2>"$ERR"',
            $comando
        );
        $this->assertStringContainsString('grep -qF', $comando);

        /* 🔴 La escritura es `crontab -u USUARIO ARCHIVO`, nunca más el `| crontab -u USUARIO -`
           que reemplazaba el crontab entero cuando la lectura previa fallaba en silencio. */
        $this->assertStringContainsString(
            'crontab -u ' . RemoteCommandRunner::escapar_argumento('api-' . $slug) . ' "$TMP"',
            $comando
        );
        $this->assertStringNotContainsString('| crontab -u', $comando);
        $this->assertStringContainsString($api_path . '/artisan schedule:run', $comando);
        $this->assertStringNotContainsString('flock', $comando);
    }

    /**
     * 🔴 HALLAZGO B — EN EL VPS EL SUPERVISOR SE CREA SIEMPRE, AUNQUE EL GREP DIGA QUE EL KERNEL ES
     * NUEVO. Es el test más importante de esta ronda de arreglos.
     *
     * Hasta el 31/8/2026 este archivo tenía dos tests que afirmaban lo contrario: con
     * $kernel_optimizado=true no se creaba supervisor, y con false se creaba junto a un cron de
     * `flock ... queue:work`. Esa regla venía del README de crons-hostinger, que es ANTERIOR al
     * commit del 26/8/2026 de empresa-api. Desde ese commit el `queue:work --stop-when-empty` del
     * scheduler vive adentro de un `if (! config('app.VPS'))`, así que en un VPS —donde el .env
     * lleva VPS=true, que es lo que ahora escribe el arreglo del hallazgo A— pasaba esto:
     *
     *   • el `grep -c stop-when-empty Kernel.php` SEGUÍA dando > 0 (la cadena está en el archivo,
     *     solo que adentro del `if`);
     *   • el scheduler NO programaba la cola;
     *   • el código concluía "Kernel optimizado" y NO creaba el worker;
     *   • nadie procesaba la cola del cliente. Sin error, sin aviso, sin una línea en el log.
     *
     * Las aserciones cambiaron porque fijaban un comportamiento que dejó de ser correcto. Lo que
     * este test fija ahora es la regla nueva: en el VPS el supervisor no depende del grep.
     */
    public function test_en_vps_el_supervisor_se_crea_aunque_el_grep_diga_que_el_kernel_es_nuevo(): void
    {
        $datos    = $this->preparar_cliente_vps();
        $slug     = $datos['slug'];
        $api_path = '/home/api-' . $slug . '/empresa-api';

        /* true = el grep contó 'stop-when-empty' en el Kernel.php. Da igual: es VPS. */
        $datos['proveedor']->provision_cron($api_path, true);

        $conf = $this->runner_fake()->crudos_con('/etc/supervisor/conf.d/api-' . $slug . '-queue.conf');
        $this->assertNotEmpty($conf);

        /* 🔴 El archivo lleva guiones y el programa guiones BAJOS: así está en §F8 del informe de
           migración, que describe los workers que hoy corren en producción. Esta aserción cambió el
           31/8/2026 porque el código emitía `api-<slug>-queue` en los dos lugares y un
           `supervisorctl status api_<slug>_queue` copiado del runbook no encontraba nada. */
        $this->assertStringContainsString('[program:api_' . $slug . '_queue]', $conf[0]);
        $this->assertStringContainsString('user=api-' . $slug, $conf[0]);
        $this->assertStringContainsString($api_path . '/artisan queue:work', $conf[0]);

        $this->assertNotEmpty($this->runner_fake()->crudos_con('supervisorctl reread'));
        $this->assertNotEmpty($this->runner_fake()->crudos_con('supervisorctl update'));
    }

    /**
     * Con Kernel viejo el resultado es el MISMO: supervisor, y el cron sigue siendo schedule:run.
     *
     * 🔴 El cron ya no manda `flock ... queue:work --stop-when-empty` en el VPS, y esa aserción
     * también cambió a propósito: con el supervisor creado siempre, ese cron sería un segundo
     * proceso tomando de la misma cola que el worker de larga vida — exactamente la competencia que
     * el `withoutOverlapping(75)` del Kernel viene a evitar (§2.2 del informe de migración).
     * `schedule:run` hace falta igual, para las tareas de negocio que no son la cola.
     */
    public function test_con_kernel_viejo_el_vps_llega_al_mismo_lugar(): void
    {
        $datos    = $this->preparar_cliente_vps();
        $slug     = $datos['slug'];
        $api_path = '/home/api-' . $slug . '/empresa-api';

        $datos['proveedor']->provision_cron($api_path, false);

        $cron = $this->runner_fake()->crudos_con('crontab')[0];
        $this->assertStringContainsString($api_path . '/artisan schedule:run', $cron);
        $this->assertStringNotContainsString('flock', $cron);
        $this->assertStringNotContainsString('queue:work', $cron);

        $conf = $this->runner_fake()->crudos_con('/etc/supervisor/conf.d/api-' . $slug . '-queue.conf');
        $this->assertNotEmpty($conf);
        $this->assertStringContainsString('[program:api_' . $slug . '_queue]', $conf[0]);
    }

    /**
     * 🔴 Y en hosting compartido el grep SIGUE decidiendo, sin cambiar una coma.
     *
     * Es la otra mitad del hallazgo B y va acá para que quede pegada a la del VPS: la regla vieja no
     * se rompió, se acotó. En el compartido no hay VPS=true en el .env, así que el Kernel nuevo sí
     * programa la cola y el `schedule:run` alcanza; con Kernel viejo va el queue:work con flock. Y
     * el compartido no tiene supervisor de ninguna manera.
     */
    public function test_en_hosting_compartido_el_grep_sigue_decidiendo_las_dos_ramas(): void
    {
        $datos     = $this->preparar_cliente_aprovisionable();
        $slug      = $datos['slug'];
        $api_path  = 'domains/comerciocity.com/public_html/' . $slug . '/api';
        $proveedor = $datos['proveedor'];

        $this->assertStringContainsString('schedule:run', $proveedor->comando_de_cron($api_path, true));
        $this->assertStringNotContainsString('flock', $proveedor->comando_de_cron($api_path, true));

        $viejo = $proveedor->comando_de_cron($api_path, false);
        $this->assertStringContainsString('flock -n /tmp/queue-' . $slug . '.lock', $viejo);
        $this->assertStringContainsString('queue:work --stop-when-empty', $viejo);
    }

    /**
     * El certificado es FATAL y el mensaje trae los 4 comandos exactos para correr a mano.
     */
    public function test_si_falla_el_certificado_el_mensaje_trae_los_cuatro_comandos(): void
    {
        $datos = $this->preparar_cliente_vps();
        $slug  = $datos['slug'];

        $this->runner_fake()->fallar_con('lets-encrypt', 'Could not issue certificate', 1);

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor']->provision_ssl();
        });

        $this->assertStringContainsString('TODO LO DEMÁS YA QUEDÓ HECHO', $mensaje);

        foreach ([$slug, 'api-' . $slug, $slug . '2', 'api-' . $slug . '2'] as $label) {
            $this->assertStringContainsString(
                'clpctl lets-encrypt:install:certificate --domainName=' . $label . '.comerciocity.com',
                $mensaje
            );
        }

        /* Dos intentos por dominio, y el primero que falla corta: 2 llamadas, no 8. */
        $this->assertCount(2, $this->runner_fake()->crudos_con('lets-encrypt'));
    }

    /**
     * Un sitio que ya existía no rompe el reintento, pero NO se guarda una contraseña que no es.
     *
     * Guardar la generada sería peor que no tener ninguna: el sitio se creó con otra, y nadie
     * volvería a mirar una credencial que figura guardada.
     */
    public function test_un_sitio_que_ya_existia_no_guarda_una_contrasenia_que_no_es(): void
    {
        $this->runner_fake()->fallar_con('clpctl site:add:php', 'Site already exists.', 1);

        $datos = $this->preparar_cliente_vps();

        $datos['proveedor']->provision_sites();

        $secretos = ClientApi::find($datos['api1']->id)->provisioning_secrets;
        $this->assertTrue($secretos === null || ! isset($secretos['api_site_password']));

        $this->assertCount(4, $datos['proveedor']->result()->ya_existian());
        $this->assertStringContainsString('ya existía', $this->linea_que_contiene('ya existía'));
    }

    /**
     * Una base que ya existe y de la que no tenemos la contraseña FRENA la etapa: reusarla a ciegas
     * dejaría un .env con una contraseña que no es, y el sistema no bootearía.
     */
    public function test_una_base_del_vps_que_ya_existe_sin_secreto_frena_la_etapa(): void
    {
        $this->runner_fake()->fallar_con('clpctl db:add', 'Database already exists.', 1);

        $datos = $this->preparar_cliente_vps();

        $this->assertStringContainsString(
            'ya existe en el VPS y no tengo su contraseña',
            $this->mensaje_de_error(function () use ($datos) {
                $datos['proveedor']->provision_db();
            })
        );
    }

    /**
     * Un error de clpctl que NO se puede clasificar como "ya existe" hace fallar la etapa.
     *
     * Nunca se adivina: dar por bueno un "ya existe" que no fue deja el pipeline creyendo que el
     * sitio está, y el error aparece quince minutos después en un paso que no tiene nada que ver.
     */
    public function test_un_error_desconocido_de_clpctl_no_se_toma_como_ya_existe(): void
    {
        $this->runner_fake()->fallar_con('clpctl site:add:php', 'Something went sideways.', 1);

        $datos = $this->preparar_cliente_vps();

        $this->assertStringContainsString(
            'Something went sideways',
            $this->mensaje_de_error(function () use ($datos) {
                $datos['proveedor']->provision_sites();
            })
        );
    }

    // ── Test 5 de §7: las guardas del PUT de DNS. Es la parte irreversible. ──────────────

    /**
     * Test 5 (a) — con dns_write_enabled en false la etapa falla y NO se llama a nadie.
     */
    public function test_guarda_g2_con_el_flag_apagado_no_se_toca_la_zona(): void
    {
        $datos = $this->preparar_cliente_vps();
        config(['services.hostinger.dns_write_enabled' => false]);

        $this->assertStringContainsString(
            'HOSTINGER_DNS_WRITE_ENABLED',
            $this->mensaje_de_error(function () use ($datos) {
                $datos['proveedor']->provision_dns();
            })
        );

        /* 🔴 Ni una escritura, y ni siquiera el GET: la guarda es lo PRIMERO del método. */
        $this->assertSame([], $this->hostinger->escrituras());
        $this->assertSame([], $this->hostinger->llamadas);
    }

    /**
     * Test 5 (b) — el cuerpo tiene exactamente los 4 nombres, overwrite=false, y el snapshot se
     * pidió ANTES del PUT.
     */
    public function test_guardas_g4_g6_g7_el_cuerpo_del_put_y_el_orden_del_snapshot(): void
    {
        $datos = $this->preparar_cliente_vps();
        $slug  = $datos['slug'];

        /* La zona antes no tiene ninguno de los 4; después los tiene, más los de otros clientes. */
        $this->hostinger->responder_secuencia('/api/dns/v1/zones/', [
            [['name' => 'otrocliente', 'type' => 'A', 'content' => '1.2.3.4']],
            [
                ['name' => 'otrocliente', 'type' => 'A', 'content' => '1.2.3.4'],
                ['name' => 'api-' . $slug, 'type' => 'A', 'content' => '76.13.171.147'],
                ['name' => $slug, 'type' => 'A', 'content' => '76.13.171.147'],
                ['name' => 'api-' . $slug . '2', 'type' => 'A', 'content' => '76.13.171.147'],
                ['name' => $slug . '2', 'type' => 'A', 'content' => '76.13.171.147'],
            ],
        ], 'GET');
        $this->hostinger->responder('/api/dns/v1/snapshots/', ['id' => 'snap-123'], 'POST');

        $datos['proveedor']->provision_dns();

        $puts = $this->hostinger->llamadas_de('PUT');
        $this->assertCount(1, $puts);

        /* 🔴 G6: overwrite en false, siempre. El literal true no existe en el código. */
        $this->assertFalse($puts[0]['body']['overwrite']);

        /* G4 + G3: exactamente los 4 nombres del cliente, todos type A. */
        $nombres = [];
        foreach ($puts[0]['body']['zone'] as $registro) {
            $nombres[] = $registro['name'];
            $this->assertSame('A', $registro['type']);
            $this->assertSame('76.13.171.147', $registro['records'][0]['content']);
        }

        sort($nombres);
        $esperados = ['api-' . $slug, 'api-' . $slug . '2', $slug, $slug . '2'];
        sort($esperados);
        $this->assertSame($esperados, $nombres);

        /* 🔴 G7: el snapshot se pidió ANTES del PUT. Sin snapshot no hay vuelta atrás. */
        $this->assertLessThan(
            $this->indice_de_llamada('PUT', '/api/dns/v1/zones/'),
            $this->indice_de_llamada('POST', '/api/dns/v1/snapshots/')
        );

        $this->assertStringContainsString('snap-123', $this->linea_que_contiene('snap-123'));
    }

    /**
     * Test 5 (c) — G3 y G4: un nombre fuera de la lista blanca revienta ANTES de llamar a nadie.
     *
     * Se fuerza por reflexión a propósito: hoy registros_a_escribir() no puede armar un nombre así,
     * y este test existe justamente para el día en que alguien lo cambie.
     */
    public function test_guarda_g3_un_nombre_fuera_de_la_lista_blanca_no_llega_a_la_zona(): void
    {
        $datos     = $this->preparar_cliente_vps();
        $proveedor = $datos['proveedor'];

        $prohibidos = [
            [['name' => '@', 'type' => 'A']],
            [['name' => '*', 'type' => 'A']],
            [['name' => 'www', 'type' => 'A']],
            [['name' => 'otrocliente', 'type' => 'A']],
            [['name' => 'api-' . $datos['slug'] . '.comerciocity.com', 'type' => 'A']],
            [['name' => 'api-' . $datos['slug'], 'type' => 'CNAME']],
        ];

        foreach ($prohibidos as $registros) {
            $exploto = false;

            try {
                $this->invocar($proveedor, 'assert_lista_blanca', [$registros]);
            } catch (\RuntimeException $excepcion) {
                $exploto = true;
            }

            $this->assertTrue($exploto, 'Pasó la lista blanca: ' . json_encode($registros));
        }

        /* G4 — cardinalidad: ni 0 registros ni 5. */
        foreach ([[], array_fill(0, 5, ['name' => 'x', 'type' => 'A'])] as $cuerpo) {
            $exploto = false;

            try {
                $this->invocar($proveedor, 'assert_cardinalidad', [$cuerpo]);
            } catch (\RuntimeException $excepcion) {
                $exploto = true;
            }

            $this->assertTrue($exploto, 'Pasó la cardinalidad con ' . count($cuerpo) . ' registro(s).');
        }

        $this->assertSame([], $this->hostinger->escrituras());
    }

    /**
     * Test 5 (d) — 🔴 G8: si el GET posterior devuelve menos registros, la etapa falla, loguea el id
     * del snapshot y NO intenta restaurar sola.
     */
    public function test_guarda_g8_si_la_zona_perdio_registros_la_etapa_falla_con_el_snapshot(): void
    {
        $datos = $this->preparar_cliente_vps();
        $slug  = $datos['slug'];

        $this->hostinger->responder_secuencia('/api/dns/v1/zones/', [
            [
                ['name' => 'clienteviejo', 'type' => 'A', 'content' => '1.2.3.4'],
                ['name' => 'api-clienteviejo', 'type' => 'A', 'content' => '1.2.3.4'],
            ],
            /* El PUT se llevó puesto a clienteviejo: es el escenario que G8 existe para detectar. */
            [
                ['name' => 'api-clienteviejo', 'type' => 'A', 'content' => '1.2.3.4'],
            ],
        ], 'GET');
        $this->hostinger->responder('/api/dns/v1/snapshots/', ['id' => 'snap-456'], 'POST');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor']->provision_dns();
        });

        $this->assertStringContainsString('PERDIÓ REGISTROS', $mensaje);
        $this->assertStringContainsString('clienteviejo|A', $mensaje);
        $this->assertStringContainsString('snap-456', $mensaje);
        $this->assertStringContainsString('NO restaura solo', $mensaje);

        /* La línea del panel va en nivel error, no info. */
        $this->assertSame('error', $this->nivel_de_la_ultima_linea('provision_dns'));

        /* 🔴 Y no se intentó restaurar: un restore automático sobre una zona a medio arreglar es
         * peor que el problema. La única escritura posterior al PUT no existe. */
        $escrituras = $this->hostinger->escrituras();
        $this->assertSame('PUT', $escrituras[count($escrituras) - 1]['metodo']);
    }

    /**
     * Un A record que ya existe apuntando a OTRA IP hace fallar la etapa: no se repunta.
     */
    public function test_un_a_record_que_apunta_a_otra_ip_no_se_repunta(): void
    {
        $datos = $this->preparar_cliente_vps();
        $slug  = $datos['slug'];

        $this->hostinger->responder('/api/dns/v1/zones/', [
            ['name' => 'api-' . $slug, 'type' => 'A', 'content' => '190.0.0.9'],
        ], 'GET');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor']->provision_dns();
        });

        $this->assertStringContainsString('190.0.0.9', $mensaje);
        $this->assertStringContainsString('NO se repunta', $mensaje);

        $this->assertSame([], $this->hostinger->escrituras());
    }

    /**
     * Si los 4 ya apuntaban al VPS no se escribe nada y ni siquiera se pide el snapshot.
     */
    public function test_si_los_a_records_ya_apuntaban_al_vps_no_se_escribe_nada(): void
    {
        $datos = $this->preparar_cliente_vps();
        $slug  = $datos['slug'];

        $registros = [];
        foreach (['api-' . $slug, $slug, 'api-' . $slug . '2', $slug . '2'] as $nombre) {
            $registros[] = ['name' => $nombre, 'type' => 'A', 'content' => '76.13.171.147'];
        }
        $this->hostinger->responder('/api/dns/v1/zones/', $registros, 'GET');

        $datos['proveedor']->provision_dns();

        $this->assertSame([], $this->hostinger->escrituras());
    }

    /**
     * 🔴 Si el snapshot falla, NO SE ESCRIBE (guarda G7).
     */
    public function test_guarda_g7_sin_snapshot_no_se_escribe_la_zona(): void
    {
        $datos = $this->preparar_cliente_vps();

        $this->hostinger->responder('/api/dns/v1/zones/', [], 'GET');
        $this->hostinger->fallar_con('/api/dns/v1/snapshots/', 500, 'internal error', 'POST');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $datos['proveedor']->provision_dns();
        });

        $this->assertStringContainsString('snapshot', $mensaje);
        $this->assertStringContainsString('NO se escribe nada', $mensaje);

        $this->assertSame([], $this->hostinger->llamadas_de('PUT'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEGUNDA RONDA DE ARREGLOS (31/8/2026) — los 🟡 y los ⚪ del chequeo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 HALLAZGO E — TODO comando remoto sale con comillas SIMPLES, en cualquier sistema.
     *
     * Este test es el candado que faltaba, y su ausencia es lo que volvía el bug permanente: hasta
     * el 31/8/2026 este mismo archivo tenía un helper sin_comillas() que le sacaba las comillas al
     * comando antes de asertar, con el comentario "lo que los tests fijan es el comando, no el
     * escapado". O sea que la suite borraba a mano justamente la diferencia que distingue el
     * escapado bueno del malo: alguien podía volver a escapeshellarg() —que en el WAMP de esta
     * máquina emite comillas DOBLES, adentro de las cuales el `sh` remoto expande `$`, backticks y
     * la barra invertida— y ningún test se enteraba.
     *
     * Se verifica de las tres maneras a propósito: la función sola, el código fuente de las clases
     * de aprovisionamiento, y los comandos de una corrida de verdad.
     */
    public function test_todo_comando_remoto_del_aprovisionamiento_va_con_comillas_simples(): void
    {
        /* 1. La función: comillas simples, y la comilla simple de adentro cerrada, escapada y reabierta. */
        $this->assertSame("'lacava'", RemoteCommandRunner::escapar_argumento('lacava'));
        $this->assertSame("'a'\\''b'", RemoteCommandRunner::escapar_argumento("a'b"));

        /* Lo peligroso queda literal: nada de esto lo puede expandir el shell del otro lado. */
        $this->assertSame(
            "'x\$(id)`id`%PATH%'",
            RemoteCommandRunner::escapar_argumento('x$(id)`id`%PATH%')
        );

        /* 2. Ninguna de las clases de aprovisionamiento vuelve a llamar a escapeshellarg(). */
        $clases = [
            'Services/SharedHostingSubdomains.php',
            'Services/SharedHostingProvisioning.php',
            'Services/VpsCertificateProvisioner.php',
            'Services/VpsSiteProvisioner.php',
            'Services/VpsDatabaseProvisioner.php',
            'Services/VpsHostingProvisioning.php',
        ];

        foreach ($clases as $clase) {
            $this->assertStringNotContainsString(
                'escapeshellarg(',
                (string) file_get_contents(app_path($clase)),
                $clase . ' volvió a escapar con escapeshellarg(), que escapa según el sistema donde '
                    . 'corre PHP y no según el `sh` que ejecuta el comando.'
            );
        }

        /* 3. Y en una corrida de verdad: ni una comilla doble alrededor de un argumento. */
        $datos = $this->preparar_cliente_vps();
        $datos['proveedor']->provision_sites();
        $datos['proveedor']->provision_db();

        foreach ($this->runner_fake()->crudos as $comando) {
            $this->assertStringNotContainsString('"', $comando, 'Comando con comillas dobles: ' . $comando);
        }
    }

    /**
     * 🔴 HALLAZGO G — un `crontab -l` que falla por un motivo inesperado FRENA, no escribe.
     *
     * El comando viejo leía el crontab con `2>/dev/null` y, si esa lectura fallaba por algo que no
     * fuera "no crontab for user", el subshell emitía solo la línea nueva y el `crontab -`
     * reemplazaba el crontab entero del usuario con una sola línea — devolviendo exit 0 y con el
     * paso logueando "el cron está".
     */
    public function test_si_no_se_puede_leer_el_crontab_la_etapa_frena_sin_escribir(): void
    {
        $datos    = $this->preparar_cliente_vps();
        $slug     = $datos['slug'];
        $api_path = '/home/api-' . $slug . '/empresa-api';

        $this->runner_fake()->fallar_con(
            'crontab',
            VpsDatabaseProvisioner::MARCA_CRONTAB_ILEGIBLE . "\ncrontab: cannot open spool",
            1
        );

        $mensaje = $this->mensaje_de_error(function () use ($datos, $api_path) {
            $datos['proveedor']->provision_cron($api_path, true);
        });

        $this->assertStringContainsString(
            VpsDatabaseProvisioner::MARCA_CRONTAB_ILEGIBLE,
            $mensaje,
            'La etapa tenía que cortar con el motivo del cron adentro del mensaje.'
        );

        /* Y no siguió de largo: ni supervisor, ni la línea de éxito. */
        $this->assertSame([], $this->runner_fake()->crudos_con('supervisorctl'));
        $this->assertStringNotContainsString('está.', $this->ultima_linea('provision_cron'));
    }

    /**
     * 🔴 HALLAZGO F (1) — sin `dig` no se espera nada, y se DICE.
     *
     * Con el VPS sin dnsutils, `dig +short` devolvía vacío en cada sonda (must_succeed=false, así
     * que ni fallaba) y los 4 dominios esperaban el tope completo —hasta 12 minutos de sleep
     * adentro de un job— sin una sola línea del log que dijera qué estaba pasando.
     */
    public function test_sin_dig_no_se_sondea_la_propagacion_y_queda_escrito_en_el_log(): void
    {
        /* El fake gana la primera regla que matchea: esta se registra antes que la del VPS sano. */
        $this->runner_fake()->responder('command -v dig', '');

        $datos = $this->preparar_cliente_vps();

        $datos['proveedor']->provision_ssl();

        $this->assertSame([], $this->runner_fake()->crudos_con('dig +short'));
        $this->assertStringContainsString('dnsutils', $this->linea_que_contiene('dnsutils'));

        /* Y los 4 certificados se piden igual: la falta de dig no frena la etapa. */
        $this->assertCount(4, $this->runner_fake()->crudos_con('lets-encrypt'));
    }

    /**
     * 🔴 HALLAZGO F (2) — la IP se compara por línea EXACTA y no por substring.
     *
     * 76.13.171.147 (la del VPS) es substring de 176.13.171.147, que es otro servidor: con
     * strpos() el paso daba "ya resuelve" para un dominio apuntado a cualquier lado.
     */
    public function test_una_ip_que_solo_es_substring_no_cuenta_como_propagada(): void
    {
        $this->runner_fake()->responder('dig +short', "176.13.171.147\n");

        $datos = $this->preparar_cliente_vps();

        $datos['proveedor']->provision_ssl();

        $this->assertStringContainsString(
            'todavía no resuelve',
            $this->linea_que_contiene('todavía no resuelve'),
            '176.13.171.147 no es 76.13.171.147: la comparación por substring las confundía.'
        );
    }

    /**
     * 🔴 HALLAZGO F (3) — el timeout del job cubre la espera de propagación del peor caso.
     *
     * Los dos números quedan atados acá: si alguien sube dns_wait_seconds sin subir el timeout del
     * job, la instalación se muere en el último paso con TODO lo demás ya hecho.
     */
    public function test_el_timeout_del_job_cubre_la_espera_de_propagacion_del_dns(): void
    {
        /* El default de config (180 s por dominio, 4 dominios), no el 0 que ponen los tests. */
        $espera_del_peor_caso = 4 * 180;
        $pipeline_sin_esperas = 1800;

        $suelto = new RunClientInstallationJob('uuid-de-prueba');
        $grupo  = new RunClientInstallationGroupJob(['uno', 'dos']);

        $this->assertGreaterThanOrEqual(
            $espera_del_peor_caso + $pipeline_sin_esperas,
            $suelto->timeout,
            'provision_ssl espera hasta 180 s por cada uno de los 4 dominios: con este timeout el '
                . 'worker mata la instalación en el último paso, con los 4 sitios, el DNS, la base '
                . 'y el cron ya hechos.'
        );

        $this->assertGreaterThanOrEqual(
            $suelto->timeout * 2,
            $grupo->timeout,
            'El job de grupo corre DOS pipelines adentro del mismo handle().'
        );
    }

    /**
     * 🔴 HALLAZGO H — la zona del hosting compartido se relee antes de dar por faltante un registro.
     *
     * provision_sites hace los 4 POST y este paso corre en el instante siguiente: que Hostinger
     * publique el A record en la lectura de la zona en ese mismo instante es §10.4 del plan,
     * explícitamente no verificado. Si tardaba unos segundos, la instalación quedaba 'fallida' con
     * los 4 subdominios creados y el operador entraba a hPanel y los veía a los cuatro.
     */
    public function test_la_zona_del_compartido_se_reintenta_antes_de_dar_por_faltante_un_record(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $slug  = $datos['slug'];

        /* 2 segundos de tope: alcanza para una sonda de más y no cuelga la suite. */
        config(['services.hostinger.zone_wait_seconds' => 2]);

        $completa = [];
        foreach (['api-' . $slug, $slug, 'api-' . $slug . '2', $slug . '2'] as $nombre) {
            $completa[] = ['name' => $nombre, 'type' => 'A'];
        }

        /* Primera lectura: falta el último. Segunda: ya están los 4. */
        $this->hostinger->responder_secuencia('/api/dns/v1/zones/', [
            array_slice($completa, 0, 3),
            $completa,
        ], 'GET');

        $datos['proveedor']->provision_dns();

        $this->assertCount(2, $this->hostinger->llamadas_de('GET'));
        $this->assertStringContainsString('Los 4 A records', $this->ultima_linea('provision_dns'));

        /* 🔴 Y sigue sin escribir NADA: la espera acota el falso negativo, no habilita el PUT. */
        $this->assertSame([], $this->hostinger->escrituras());
    }

    /**
     * 🔴 HALLAZGO L — si el GET posterior al PUT falla, el mensaje dice que el PUT YA PASÓ.
     *
     * Era el único camino ciego de G8: subía la excepción cruda del transporte ("La API de
     * Hostinger respondió 502") y el operador la leía en provision_dns concluyendo, con toda razón,
     * que no se había escrito nada — cuando la escritura ya se había ejecutado.
     *
     * Se fuerza por reflexión porque el fake no puede hacer fallar el segundo GET de la zona y no
     * el primero: los dos comparten ruta y verbo.
     */
    public function test_si_la_verificacion_posterior_no_puede_leer_la_zona_lo_dice_sin_mentir(): void
    {
        $datos = $this->preparar_cliente_vps();

        $this->hostinger->fallar_con('/api/dns/v1/zones/', 502, 'Bad gateway', 'GET');

        $mensaje = $this->mensaje_de_error(function () use ($datos) {
            $this->invocar($datos['proveedor'], 'assert_no_se_perdio_nada', [['lacava|A'], 'snap-777']);
        });

        $this->assertStringContainsString('YA SE EJECUTÓ', $mensaje);
        $this->assertStringContainsString('snap-777', $mensaje);
        $this->assertStringContainsString('502', $mensaje);
    }

    /**
     * 🔴 HALLAZGO O — el bloque de supervisor es el de §F8 del informe de migración, que es el que
     * describe los workers que HOY corren en producción.
     *
     * Desde que el supervisor se crea siempre en el VPS, este bloque dejó de ser código muerto: sale
     * en cada instalación. Las tres diferencias que tenía no eran cosméticas —`--tries=3` reintenta
     * lo que producción no reintenta, `--max-time` deja el timeout POR JOB en el default de 60 s, y
     * el log adentro de storage/ se borra en cada upgrade del cliente—.
     */
    public function test_el_bloque_de_supervisor_es_el_de_f8_del_informe(): void
    {
        $datos    = $this->preparar_cliente_vps();
        $slug     = $datos['slug'];
        $api_path = '/home/api-' . $slug . '/empresa-api';

        $datos['proveedor']->provision_cron($api_path, true);

        $conf = $this->runner_fake()->crudos_con('/etc/supervisor/conf.d/api-' . $slug . '-queue.conf')[0];

        $this->assertStringContainsString(
            'queue:work --sleep=3 --tries=1 --timeout=3600 --memory=512 --max-jobs=50',
            $conf
        );
        $this->assertStringContainsString(
            'stdout_logfile=/home/api-' . $slug . '/logs/queue-worker.log',
            $conf
        );
        $this->assertStringContainsString('stopwaitsecs=3600', $conf);

        /* Lo que se fue, se fue: son los tres valores que diferían del informe. */
        $this->assertStringNotContainsString('--tries=3', $conf);
        $this->assertStringNotContainsString('--max-time', $conf);
        $this->assertStringNotContainsString('storage/logs/queue-worker.log', $conf);

        /* El directorio del log se crea antes: si no, supervisor no puede abrir el archivo. */
        $this->assertNotEmpty($this->runner_fake()->crudos_con(
            'mkdir -p ' . RemoteCommandRunner::escapar_argumento('/home/api-' . $slug . '/logs')
        ));
    }

    /**
     * 🔴 HALLAZGO P — EL ORDEN DE LOS PASOS ES UN CONTRATO CON EL SPA, y este test es el candado.
     *
     * La otra copia de este array vive en admin-spa:
     * `src/components/installation/extra-props/OperationsPanel.vue`
     * (PASOS_APROVISIONAMIENTO_INICIO / PASOS_APROVISIONAMIENTO_FINAL + LOG_STEPS_ORDER_*), y ese
     * lado NO tiene tests. Su propio comentario avisa por qué importa: get_step_status() decide
     * "completado" mirando si el paso SIGUIENTE del array ya tiene logs, así que un array
     * desalineado deja etapas en gris para siempre, sin ningún error y sin que nada lo denuncie.
     *
     * 🔴 Si este test se pone rojo porque cambiaste el pipeline, el arreglo NO es actualizar el
     * array de acá: es actualizar los dos y dejarlos alineados.
     */
    public function test_el_orden_de_los_pasos_es_el_contrato_con_el_operations_panel_del_spa(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();

        /* Fila real con aprovisionamiento: 4 adelante, el pipeline de siempre, 2 atrás. */
        $this->assertSame(
            [
                'provision_check', 'provision_sites', 'provision_dns', 'provision_db',
                'compile_spa', 'upload_spa', 'upload_api', 'write_env', 'finalize_api',
                'provision_cron', 'provision_ssl',
            ],
            $this->steps_de(new \App\Services\InstallationService($datos['installation']->fresh()))
        );

        /* Esqueleto con aprovisionamiento: los mismos 4 adelante y NADA atrás. */
        $esqueleto = ClientInstallation::create([
            'client_id'              => $datos['client']->id,
            'client_api_id'          => $datos['api2']->id,
            'kind'                   => ClientInstallation::KIND_ESQUELETO,
            'status'                 => 'pendiente',
            'provision_hosting_type' => ClientInstallation::PROVISION_SHARED_HOSTING,
        ]);

        $this->assertSame(
            [
                'provision_check', 'provision_sites', 'provision_dns', 'provision_db',
                'prepare_dirs', 'upload_public', 'write_env', 'finalize_skeleton',
            ],
            $this->steps_de(new \App\Services\InstallationService($esqueleto))
        );

        /* Y sin aprovisionamiento, las dos listas de siempre, byte por byte. */
        $datos['installation']->provision_hosting_type = null;
        $datos['installation']->save();

        $this->assertSame(
            ['compile_spa', 'upload_spa', 'upload_api', 'write_env', 'finalize_api'],
            $this->steps_de(new \App\Services\InstallationService($datos['installation']->fresh()))
        );

        $esqueleto->provision_hosting_type = null;
        $esqueleto->save();

        $this->assertSame(
            ['prepare_dirs', 'upload_public', 'write_env', 'finalize_skeleton'],
            $this->steps_de(new \App\Services\InstallationService($esqueleto->fresh()))
        );
    }

    // ── Test 6 de §7: ninguna contraseña en el log. ─────────────────────────────────────

    /**
     * Test 6 de §7 — 🔴 NINGUNA CONTRASEÑA GENERADA APARECE EN NINGÚN DeploymentLog.
     *
     * Es el test que impide que alguien "simplifique" RemoteCommandRunner y vuelva a
     * exec_hosting_ssh(), que loguea el comando entero: ahí la contraseña del sitio de CloudPanel y
     * la de la base del cliente quedan escritas en claro en deployment_logs y a la vista en el panel
     * de operaciones que Lucas comparte en pantalla (§0.5 del plan).
     */
    public function test_ninguna_contrasenia_generada_llega_a_los_deployment_logs(): void
    {
        $datos = $this->preparar_cliente_vps(null, true);

        $datos['proveedor']->provision_sites();
        $datos['proveedor']->provision_db();

        $secretos     = ClientApi::find($datos['api1']->id)->provisioning_secrets;
        $contrasenias = [
            $secretos['api_site_password'],
            $secretos['spa_site_password'],
            $secretos['api2_site_password'],
            $secretos['spa2_site_password'],
            $secretos['db_password'],
        ];

        $lineas = DeploymentLog::where('client_installation_id', $datos['installation']->id)
            ->pluck('line')
            ->implode("\n");

        $this->assertNotEmpty($lineas, 'La corrida no logueó nada: el test sería vacío.');

        foreach ($contrasenias as $contrasenia) {
            $this->assertNotEmpty($contrasenia);

            /* 🔴 El aserto de este test. */
            $this->assertStringNotContainsString($contrasenia, $lineas);

            /* Y el comando que se EJECUTÓ sí la lleva: si no, el test sería vacío. */
            $this->assertStringContainsString($contrasenia, implode("\n", $this->runner_fake()->crudos));

            /* Lo que el runner manda al log, en cambio, la trae redactada. */
            $this->assertStringNotContainsString($contrasenia, $this->runner_fake()->texto_redactado());
        }

        $this->assertStringContainsString('***', $this->runner_fake()->texto_redactado());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // U6 — ALTA DE INSTALACIÓN CON APROVISIONAMIENTO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El alta acepta provision_hosting_type y lo copia a las DOS filas del grupo.
     *
     * Las dos lo llevan porque los 4 subdominios y la base son del CLIENTE, no de una instancia: si
     * solo la real lo tuviera, el esqueleto correría el pipeline viejo contra un subdominio que
     * todavía no existe.
     */
    public function test_el_alta_copia_el_tipo_de_aprovisionamiento_a_las_dos_filas(): void
    {
        $datos   = $this->preparar_cliente_aprovisionable();
        $version = $this->crear_version_publicada();

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->postJson('/api/admin/installations', [
            'client_id'              => $datos['client']->id,
            'version_id'             => $version->id,
            'provision_hosting_type' => 'shared_hosting',
            'targets'                => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
                ['client_api_id' => $datos['api2']->id, 'kind' => 'esqueleto'],
            ],
        ]);

        $respuesta->assertStatus(201);

        $filas = $respuesta->json('models');
        $this->assertCount(2, $filas);
        $this->assertSame('shared_hosting', $filas[0]['provision_hosting_type']);
        $this->assertSame('shared_hosting', $filas[1]['provision_hosting_type']);
    }

    /**
     * 🔴 HALLAZGO C — el escenario cruzado: elegir un hosting teniendo la ClientApi en el otro.
     *
     * El caso concreto, y es el flujo de reintento normal: una instalación en VPS falla, el operador
     * borra la fila fallida y crea otra, esta vez tildando "Hosting compartido" — pero las dos
     * ClientApi ya quedaron en 'vps'. Sin guarda, el aprovisionamiento crea los 4 subdominios y la
     * base EN LA CUENTA COMPARTIDA (SharedHostingProvisioning fuerza la credencial 'shared_hosting')
     * mientras el pipeline sube el código AL VPS (get_api_path, la credencial y el SFTP salen del
     * hosting_type de la fila, vía ClientApiPathResolver). Recursos creados de los dos lados y un
     * DB_HOST=127.0.0.1 apuntando a una base que vive en el MySQL del otro servidor.
     *
     * La guarda frena en el alta, con 422 y los dos valores en el mensaje.
     */
    public function test_pedir_compartido_sobre_una_api_en_vps_se_rechaza_en_el_alta(): void
    {
        $datos   = $this->preparar_cliente_aprovisionable();
        $version = $this->crear_version_publicada();

        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $api->hosting_type = 'vps';
            $api->vps_path     = $datos['slug'];
            $api->save();
        }

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->postJson('/api/admin/installations', [
            'client_id'              => $datos['client']->id,
            'version_id'             => $version->id,
            'provision_hosting_type' => 'shared_hosting',
            'targets'                => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('shared_hosting', $respuesta->json('error'));
        $this->assertStringContainsString('vps', $respuesta->json('error'));

        /* Y no quedó ni una fila creada. */
        $this->assertSame(
            0,
            ClientInstallation::where('client_id', $datos['client']->id)
                ->where('id', '!=', $datos['installation']->id)
                ->count()
        );
    }

    /**
     * 🔴 El camino inverso —pedir VPS sobre APIs que todavía dicen 'shared_hosting'— es el alta
     * normal de la primera vez y TIENE que seguir funcionando: el flip a 'vps' lo hace el propio
     * aprovisionamiento, al final de provision_sites.
     */
    public function test_pedir_vps_sobre_apis_en_compartido_sigue_siendo_el_camino_normal(): void
    {
        $datos   = $this->preparar_cliente_aprovisionable();
        $version = $this->crear_version_publicada();

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->postJson('/api/admin/installations', [
            'client_id'              => $datos['client']->id,
            'version_id'             => $version->id,
            'provision_hosting_type' => 'vps',
            'targets'                => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
            ],
        ]);

        $respuesta->assertStatus(201);
        $this->assertSame('vps', $respuesta->json('model.provision_hosting_type'));
    }

    /**
     * 🔴 Y la guarda de verdad está en el preflight, no solo en el alta: frena aunque la fila se
     * haya creado por otro camino, o aunque el hosting_type haya cambiado entre el alta y la
     * corrida. Frena ANTES de la primera escritura del proveedor.
     */
    public function test_el_preflight_frena_si_el_hosting_de_la_api_no_es_el_que_se_pidio(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();

        foreach ([$datos['api1'], $datos['api2']] as $api) {
            $api->hosting_type = 'vps';
            $api->vps_path     = $datos['slug'];
            $api->save();
        }

        $this->assertStringContainsString(
            'No se toca nada',
            $this->mensaje_de_error(function () use ($datos) {
                $datos['proveedor']->provision_check();
            })
        );

        /* Ni un POST ni un PUT: la guarda corre antes de crear el primer subdominio. */
        $this->assertSame([], $this->hostinger->llamadas_de('POST'));
        $this->assertSame([], $this->hostinger->llamadas_de('PUT'));
    }

    /**
     * Test 9 de §7, la mitad que vive de este lado: un POST SIN el campo deja la fila en null.
     *
     * 🔴 La otra mitad —que el payload viejo con client_api_id suelto sigue creando una completa—
     * la fija InstalacionEsqueletoEnElSubdominioSecundarioTest, que es donde ese contrato nació el
     * 24/8. Ahí se extendió con la aserción de provision_hosting_type en vez de duplicarlo acá.
     */
    public function test_un_alta_sin_el_campo_nuevo_deja_la_fila_sin_aprovisionamiento(): void
    {
        $datos   = $this->preparar_cliente_aprovisionable();
        $version = $this->crear_version_publicada();

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
            ],
        ]);

        $respuesta->assertStatus(201);
        $this->assertNull($respuesta->json('model.provision_hosting_type'));

        /* Y el pipeline de esa fila es exactamente el de siempre, sin un paso nuevo. */
        $fila = ClientInstallation::find($respuesta->json('model.id'));
        $this->assertSame(
            ['compile_spa', 'upload_spa', 'upload_api', 'write_env', 'finalize_api'],
            $this->steps_de(new \App\Services\InstallationService($fila))
        );
    }

    /**
     * Test 10 de §7, con el sentido que le dio U9 (y el apéndice A1 del plan).
     *
     * 🔴 Hasta U9 este test fijaba un 422: el alta con provision_hosting_type='vps' se rechazaba
     * porque entre U8 y U9 el aprovisionamiento del VPS ya funcionaba y el pipeline de instalación
     * todavía no. En esa ventana, provision_check pasaba las ClientApi a hosting_type='vps' y
     * build_spa_hosting_deploy_shell() seguía armando el docroot como
     * 'domains/comerciocity.com/public_html/' . get_spa_path() — o sea, la raíz de la cuenta
     * compartida— y le corría adentro un `find . -mindepth 1 -delete`.
     *
     * U9 hizo hosting-aware al pipeline, así que la guarda del controlador se levantó y este test
     * fija el comportamiento nuevo. 🔴 Lo que NO desapareció es la protección contra ese borrado:
     * se mudó a donde de verdad corresponde, pegada al `find -delete`, y la fija
     * InstalacionSobreVpsTest::test_con_un_path_vacio_el_deploy_del_spa_no_llega_a_borrar_nada().
     */
    public function test_el_alta_en_vps_ya_no_se_rechaza_y_crea_las_filas(): void
    {
        $datos   = $this->preparar_cliente_aprovisionable();
        $version = $this->crear_version_publicada();

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->postJson('/api/admin/installations', [
            'client_id'              => $datos['client']->id,
            'version_id'             => $version->id,
            'provision_hosting_type' => 'vps',
            'targets'                => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
                ['client_api_id' => $datos['api2']->id, 'kind' => 'esqueleto'],
            ],
        ]);

        $respuesta->assertStatus(201);

        $filas = $respuesta->json('models');
        $this->assertCount(2, $filas);
        $this->assertSame('vps', $filas[0]['provision_hosting_type']);
        $this->assertSame('vps', $filas[1]['provision_hosting_type']);

        /* Y el esqueleto sobre VPS entra en el mismo grupo, que era la otra mitad del rechazo. */
        $this->assertSame('completa', $filas[0]['kind']);
        $this->assertSame('esqueleto', $filas[1]['kind']);
    }

    /**
     * Un tipo de hosting que no existe se rechaza con la regla `in:`, en castellano.
     */
    public function test_un_tipo_de_aprovisionamiento_desconocido_se_rechaza(): void
    {
        $datos   = $this->preparar_cliente_aprovisionable();
        $version = $this->crear_version_publicada();

        $this->actingAs($this->crear_admin(), 'sanctum')->postJson('/api/admin/installations', [
            'client_id'              => $datos['client']->id,
            'version_id'             => $version->id,
            'provision_hosting_type' => 'cpanel',
            'targets'                => [['client_api_id' => $datos['api1']->id, 'kind' => 'completa']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provision_hosting_type');
    }

    /**
     * Test 8 de §7, primera mitad: con aprovisionamiento tildado, start() NO exige las DB_*.
     *
     * 🔴 Sin esto el botón "Iniciar" del modal queda gris para siempre (§0.4): esas tres claves son
     * is_manual_on_create, las genera provision_db y no hay forma de que el operador las complete.
     */
    public function test_start_no_exige_las_db_cuando_la_fila_tiene_aprovisionamiento(): void
    {
        Queue::fake();

        $datos = $this->preparar_cliente_aprovisionable();
        $this->crear_templates_manuales_completos();

        /* Solo las tres que SÍ tipea el operador. Las DB_* quedan sin cargar a propósito. */
        $datos['installation']->update([
            'env_manual_values' => ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306'],
        ]);

        $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/client-installations/' . $datos['installation']->id . '/start')
            ->assertStatus(200);

        $this->assertSame('instalando', $datos['installation']->fresh()->status);
    }

    /**
     * Test 8 de §7, segunda mitad: sin aprovisionamiento las DB_* se siguen exigiendo igual que
     * siempre. Es el camino viejo, y es el que no se puede aflojar por el arreglo de arriba.
     */
    public function test_start_sigue_exigiendo_las_db_sin_aprovisionamiento(): void
    {
        Queue::fake();

        $datos = $this->preparar_cliente_aprovisionable();
        $this->crear_templates_manuales_completos();

        $datos['installation']->update([
            'provision_hosting_type' => null,
            'env_manual_values'      => ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306'],
        ]);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/client-installations/' . $datos['installation']->id . '/start');

        $respuesta->assertStatus(422);
        $this->assertContains('DB_DATABASE', $respuesta->json('missing_keys'));
        $this->assertContains('DB_PASSWORD', $respuesta->json('missing_keys'));

        Queue::assertNothingPushed();
        $this->assertSame('pendiente', $datos['installation']->fresh()->status);
    }

    /**
     * Con aprovisionamiento, una variable manual que NO es de las tres del aprovisionamiento sigue
     * siendo obligatoria: la excepción es de tres claves, no de la validación entera.
     */
    public function test_con_aprovisionamiento_las_otras_variables_manuales_se_siguen_exigiendo(): void
    {
        Queue::fake();

        $datos = $this->preparar_cliente_aprovisionable();
        $this->crear_templates_manuales_completos();

        $datos['installation']->update([
            'env_manual_values' => ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1'],
        ]);

        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/client-installations/' . $datos['installation']->id . '/start');

        $respuesta->assertStatus(422);
        $this->assertSame(['DB_PORT'], $respuesta->json('missing_keys'));
    }

    /**
     * El endpoint de credenciales devuelve los secretos descifrados, y solo por ahí.
     */
    public function test_las_credenciales_del_hosting_salen_por_su_endpoint_y_no_por_el_show(): void
    {
        $datos = $this->preparar_cliente_aprovisionable();
        $admin = $this->crear_admin();

        $datos['api1']->provisioning_secrets = [
            'db_name'     => 'u767360347_' . $datos['slug'],
            'db_password' => 'Cl4ve-Que-No-Sale-En-El-Show',
        ];
        $datos['api1']->hosting_provisioned_at = now();
        $datos['api1']->save();

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/client-apis/' . $datos['api1']->id . '/hosting-credentials');

        $respuesta->assertStatus(200);
        $this->assertSame(
            'Cl4ve-Que-No-Sale-En-El-Show',
            $respuesta->json('provisioning_secrets.db_password')
        );
        $this->assertNotNull($respuesta->json('hosting_provisioned_at'));

        /*
         * 🔴 Y el show de la instalación —que carga client_api— NO trae la contraseña. Es el $hidden
         * del modelo, y es la razón por la que este endpoint existe aparte.
         */
        $show = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/client-installations/' . $datos['installation']->id);

        $show->assertStatus(200);
        $this->assertStringNotContainsString('Cl4ve-Que-No-Sale-En-El-Show', $show->getContent());
    }

    /**
     * Una API sin aprovisionar devuelve el array vacío, no un 500.
     */
    public function test_las_credenciales_de_una_api_sin_aprovisionar_son_un_array_vacio(): void
    {
        $api = $this->crear_api_de_cliente();

        $this->actingAs($this->crear_admin(), 'sanctum')
            ->getJson('/api/admin/client-apis/' . $api->id . '/hosting-credentials')
            ->assertStatus(200)
            ->assertJson(['provisioning_secrets' => [], 'hosting_provisioned_at' => null]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Última línea que el proveedor mandó al panel para una etapa.
     *
     * @param  string  $step
     * @return string
     */
    private function ultima_linea(string $step): string
    {
        $encontrada = '';

        foreach ($this->lineas as $linea) {
            if ($linea['step'] === $step) {
                $encontrada = $linea['linea'];
            }
        }

        return $encontrada;
    }

    /**
     * Corre la etapa write_env con el servicio SSH falseado.
     *
     * @param  ClientInstallation  $installation
     * @return void
     */
    private function correr_write_env(ClientInstallation $installation): void
    {
        $this->env_fake = new EnvSshServiceFake();
        $this->app->instance(EnvSshService::class, $this->env_fake);

        $service = new \App\Services\InstallationService($installation);
        $metodo  = new \ReflectionMethod($service, 'step_write_env');
        $metodo->setAccessible(true);
        $metodo->invoke($service);
    }

    /**
     * Pipeline de etapas de un servicio ya construido.
     *
     * @param  \App\Services\InstallationService  $service
     * @return array<int, string>
     */
    private function steps_de($service): array
    {
        $propiedad = new \ReflectionProperty($service, 'steps');
        $propiedad->setAccessible(true);

        return $propiedad->getValue($service);
    }

    /**
     * Plantilla mínima de .env. admin_testing_s6 tiene env_templates vacía.
     *
     * @return void
     */
    private function crear_templates_de_env(): void
    {
        $filas = [
            ['APP_NAME', 'ComercioCity', false],
            ['APP_URL', null, false],
            ['DB_DATABASE', null, true],
            ['DB_USERNAME', null, true],
            ['DB_PASSWORD', null, true],
        ];

        foreach ($filas as $indice => $fila) {
            $this->crear_template_de_env($fila[0], $fila[1], $fila[2], $indice + 1);
        }
    }

    /**
     * @param  string       $key
     * @param  string|null  $value
     * @param  bool         $manual
     * @param  int          $orden
     * @return void
     */
    private function crear_template_de_env(string $key, $value = null, bool $manual = false, int $orden = 50): void
    {
        $template                      = new EnvTemplate();
        $template->key                 = $key;
        $template->value               = $value;
        $template->group               = 'app';
        $template->scope               = 'empresa';
        $template->is_common           = false;
        $template->is_manual_on_create = $manual;
        $template->sort_order          = $orden;
        $template->save();
    }


    /**
     * Cliente con sus dos ClientApi estándar, una instalación con aprovisionamiento tildado y el
     * proveedor de hosting compartido ya instanciado.
     *
     * @param  string|null  $slug  Slug a usar; por defecto uno al azar para no chocar con la base.
     * @return array<string, mixed>
     */
    private function preparar_cliente_aprovisionable($slug = null): array
    {
        if ($slug === null) {
            $slug = 'prov' . strtolower(Str::random(8));
        }

        $this->crear_credencial_ssh();

        $client                  = new Client();
        $client->name            = 'Cliente ' . $slug;
        $client->slug            = $slug;
        $client->api_url         = 'https://api-' . $slug . '.comerciocity.com';
        $client->api_key         = Str::random(20);
        $client->inbound_api_key = Str::random(20);
        $client->save();

        $api1 = $this->crear_client_api($client->id, $slug);
        $api2 = $this->crear_client_api($client->id, $slug . '2');

        $installation = ClientInstallation::create([
            'client_id'              => $client->id,
            'client_api_id'          => $api1->id,
            'kind'                   => ClientInstallation::KIND_COMPLETA,
            'status'                 => 'pendiente',
            'provision_hosting_type' => ClientInstallation::PROVISION_SHARED_HOSTING,
        ]);

        $lineas    = &$this->lineas;
        $proveedor = HostingProvisioningService::para(
            $installation,
            $api1,
            function ($step, $linea, $level) use (&$lineas) {
                $lineas[] = ['step' => $step, 'linea' => $linea, 'level' => $level];
            }
        );

        return [
            'slug'         => $slug,
            'client'       => $client,
            'api1'         => $api1,
            'api2'         => $api2,
            'installation' => $installation,
            'proveedor'    => $proveedor,
        ];
    }

    /**
     * @param  int     $client_id
     * @param  string  $label
     * @return ClientApi
     */
    private function crear_client_api(int $client_id, string $label): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client_id;
        $api->url          = 'https://api-' . $label . '.comerciocity.com';
        $api->spa_url      = 'https://' . $label . '.comerciocity.com';
        $api->path         = $label . '/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }

    /**
     * Deja la zona DNS respondiendo con los 4 A records puestos, que es lo que Hostinger hace sola
     * al crear cada subdominio.
     *
     * @param  string  $slug
     * @return void
     */
    private function responder_zona_completa(string $slug): void
    {
        $this->hostinger->responder('/api/dns/v1/zones/', [
            ['name' => 'api-' . $slug, 'type' => 'A'],
            ['name' => $slug, 'type' => 'A'],
            ['name' => 'api-' . $slug . '2', 'type' => 'A'],
            ['name' => $slug . '2', 'type' => 'A'],
        ], 'GET');
    }

    /**
     * POSTs registrados contra una ruta.
     *
     * @param  string  $ruta_parcial
     * @return array<int, array<string, mixed>>
     */
    private function posts_a(string $ruta_parcial): array
    {
        $encontrados = [];

        foreach ($this->hostinger->llamadas_de('POST') as $llamada) {
            if (strpos($llamada['ruta'], $ruta_parcial) !== false) {
                $encontrados[] = $llamada;
            }
        }

        return $encontrados;
    }

    /**
     * Cliente con las dos ClientApi estándar y el proveedor del VPS ya instanciado, con el VPS
     * respondiendo lo que un servidor sano respondería.
     *
     * @param  string|null  $slug
     * @param  bool  $log_real  true = las líneas van a DeploymentLog de verdad (lo necesita el test 6).
     * @return array<string, mixed>
     */
    private function preparar_cliente_vps($slug = null, bool $log_real = false): array
    {
        $datos = $this->preparar_cliente_aprovisionable($slug);

        $datos['installation']->provision_hosting_type = ClientInstallation::PROVISION_VPS;
        $datos['installation']->save();

        $this->crear_credencial_vps();

        config([
            'services.hostinger.vps_ip'            => '76.13.171.147',
            'services.hostinger.dns_write_enabled' => true,
            /* 0 = una sola sonda de propagación y sin sleep, para no colgar la suite 3 minutos. */
            'services.hostinger.dns_wait_seconds'  => 0,
        ]);

        $lineas       = &$this->lineas;
        $installation = $datos['installation']->fresh();

        $datos['installation'] = $installation;
        $datos['proveedor']    = HostingProvisioningService::para(
            $installation,
            $datos['api1'],
            function ($step, $linea, $level) use (&$lineas, $installation, $log_real) {
                $lineas[] = ['step' => $step, 'linea' => $linea, 'level' => $level];

                if ($log_real) {
                    /* Mismo shape que InstallationService::log(), sin el evento de broadcast. */
                    DeploymentLog::create([
                        'client_installation_id'    => $installation->id,
                        'client_version_upgrade_id' => null,
                        'step'                      => $step,
                        'line'                      => $linea,
                        'level'                     => $level,
                        'created_at'                => now(),
                    ]);
                }
            }
        );

        $this->responder_como_un_vps_sano($datos['slug']);

        return $datos;
    }

    /**
     * Salidas de los comandos que el VPS contesta en el camino feliz.
     *
     * @param  string  $slug
     * @return void
     */
    private function responder_como_un_vps_sano(string $slug): void
    {
        $runner = $this->runner_fake();

        $runner->responder('command -v clpctl', '/usr/bin/clpctl');
        $runner->responder('command -v supervisorctl', '/usr/bin/supervisorctl');
        $runner->responder('command -v dig', '/usr/bin/dig');
        $runner->responder('dig +short', '76.13.171.147');

        foreach (['api-' . $slug, 'api-' . $slug . '2'] as $label) {
            $docroot = '/home/' . $label . '/htdocs/' . $label . '.comerciocity.com';

            /* 🔴 escapar_argumento() y NO escapeshellarg(): la regla del fake tiene que matchear el
               comando REAL, y el comando real sale con comillas simples en cualquier sistema. Con
               escapeshellarg() acá, esta regla no matcheaba nada en Windows. */
            $runner->responder(
                'readlink ' . RemoteCommandRunner::escapar_argumento($docroot),
                '/home/' . $label . '/empresa-api/public'
            );
        }
    }

    /**
     * El fake del runner, forzando su creación si todavía no se resolvió del container.
     *
     * @return RemoteCommandRunnerFake
     */
    private function runner_fake(): RemoteCommandRunnerFake
    {
        if ($this->runner === null) {
            $this->runner = new RemoteCommandRunnerFake($this->crear_credencial_vps());
        }

        return $this->runner;
    }

    /**
     * Credencial SSH del VPS (el preflight la exige y admin_testing_s6 no la tiene).
     *
     * @return ClientSshCredential
     */
    private function crear_credencial_vps(): ClientSshCredential
    {
        $credential = ClientSshCredential::where('type', 'vps')->first();

        if ($credential !== null) {
            return $credential;
        }

        $credential           = new ClientSshCredential();
        $credential->type     = 'vps';
        $credential->host     = '127.0.0.1';
        $credential->port     = 22;
        $credential->username = 'root';
        $credential->password = 'test';
        $credential->save();

        return $credential;
    }

    /**
     * Índice de la primera llamada de un verbo contra una ruta, o -1.
     *
     * @param  string  $metodo
     * @param  string  $ruta_parcial
     * @return int
     */
    private function indice_de_llamada(string $metodo, string $ruta_parcial): int
    {
        foreach ($this->hostinger->llamadas as $indice => $llamada) {
            if ($llamada['metodo'] === strtoupper($metodo)
                && strpos($llamada['ruta'], $ruta_parcial) !== false) {
                return $indice;
            }
        }

        return -1;
    }

    /**
     * Primera línea del panel que contiene un texto, o ''.
     *
     * @param  string  $texto
     * @return string
     */
    private function linea_que_contiene(string $texto): string
    {
        foreach ($this->lineas as $linea) {
            if (strpos($linea['linea'], $texto) !== false) {
                return $linea['linea'];
            }
        }

        return '';
    }

    /**
     * Nivel de la última línea de una etapa.
     *
     * @param  string  $step
     * @return string
     */
    private function nivel_de_la_ultima_linea(string $step): string
    {
        $nivel = '';

        foreach ($this->lineas as $linea) {
            if ($linea['step'] === $step) {
                $nivel = $linea['level'];
            }
        }

        return $nivel;
    }

    /**
     * Corre algo que TIENE que fallar y devuelve el mensaje del error, o '' si no falló.
     *
     * 🔴 Existe porque $this->fail() de PHPUnit tira una AssertionFailedError que hereda de
     * \RuntimeException: puesto adentro de un `try { ... } catch (\RuntimeException)`, el propio
     * catch se lo come y el test pasa igual aunque el código no haya fallado nunca. Con esto, un
     * método que no explota devuelve '' y la aserción de contenido se pone en rojo sola.
     *
     * @param  \Closure  $accion
     * @return string
     */
    private function mensaje_de_error(\Closure $accion): string
    {
        try {
            $accion();
        } catch (\Throwable $excepcion) {
            return $excepcion->getMessage();
        }

        return '';
    }

    /**
     * Invoca un método privado por reflexión.
     *
     * Se usa solo para las guardas del PUT de DNS que hoy no son alcanzables desde afuera: existen
     * para el día en que alguien cambie el armado del cuerpo, y probarlas es justamente el punto.
     *
     * @param  object  $objeto
     * @param  string  $metodo
     * @param  array<int, mixed>  $argumentos
     * @return mixed
     */
    private function invocar($objeto, string $metodo, array $argumentos)
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objeto, $argumentos);
    }

    /**
     * Las SEIS variables is_manual_on_create de EnvTemplateSeeder, que es lo que start() valida.
     *
     * crear_templates_de_env() siembra solo las que necesita step_write_env; acá hacen falta también
     * DB_CONNECTION, DB_HOST y DB_PORT, que son las tres que el operador SÍ tiene que tipear aunque
     * el aprovisionamiento esté tildado.
     *
     * @return void
     */
    private function crear_templates_manuales_completos(): void
    {
        $this->crear_template_de_env('DB_CONNECTION', 'mysql', true, 1);
        $this->crear_template_de_env('DB_HOST', '127.0.0.1', true, 2);
        $this->crear_template_de_env('DB_PORT', '3306', true, 3);
        $this->crear_template_de_env('DB_DATABASE', null, true, 4);
        $this->crear_template_de_env('DB_USERNAME', null, true, 5);
        $this->crear_template_de_env('DB_PASSWORD', null, true, 6);
    }

    /**
     * Versión publicada: sin una, store_global() responde 422 antes de crear nada.
     *
     * @return Version
     */
    private function crear_version_publicada(): Version
    {
        $version          = new Version();
        $version->version = '9.9.' . random_int(1000, 9999);
        $version->status  = 'published';
        $version->save();

        return $version;
    }

    /**
     * Admin para autenticar las requests del módulo.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'aprovisionamiento-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Credencial de hosting compartido: el preflight la exige y admin_testing_s6 no la tiene.
     *
     * @return void
     */
    private function crear_credencial_ssh(): void
    {
        if (ClientSshCredential::where('type', 'shared_hosting')->first() !== null) {
            return;
        }

        $credential           = new ClientSshCredential();
        $credential->type     = 'shared_hosting';
        $credential->host     = '127.0.0.1';
        $credential->port     = 22;
        $credential->username = 'test';
        $credential->password = 'test';
        $credential->save();
    }

    /**
     * Crea un cliente con una ClientApi propia.
     *
     * @return ClientApi
     */
    private function crear_api_de_cliente(): ClientApi
    {
        $sufijo = Str::random(8);

        $client                  = new Client();
        $client->name            = 'Cliente de prueba ' . $sufijo;
        $client->slug            = Str::slug('cliente-prueba-' . $sufijo);
        $client->api_url         = 'https://api-' . $sufijo . '.comerciocity.com';
        $client->api_key         = Str::random(20);
        $client->inbound_api_key = Str::random(20);
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $sufijo . '.comerciocity.com';
        $api->spa_url      = 'https://' . $sufijo . '.comerciocity.com';
        $api->path         = $sufijo . '/api';
        $api->hosting_type = 'shared_hosting';
        $api->save();

        return $api;
    }
}

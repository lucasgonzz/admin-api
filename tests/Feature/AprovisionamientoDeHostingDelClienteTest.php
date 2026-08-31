<?php

namespace Tests\Feature;

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

        /* spa_url fuera del dominio de config: el label no se puede derivar y queda vacío. */
        $datos['api1']->hosting_type = 'vps';
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

        $datos['api1']->hosting_type = 'vps';
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
     * Redis compartido que colisionar.
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

        $raiz = 'domains/comerciocity.com/public_html/';
        $this->assertStringContainsString($raiz . $slug . '/api', $this->sin_comillas($mkdirs[0]));
        $this->assertStringContainsString($raiz . $slug . '/spa', $this->sin_comillas($mkdirs[1]));
        $this->assertStringContainsString($raiz . $slug . '2/api', $this->sin_comillas($mkdirs[2]));
        $this->assertStringContainsString($raiz . $slug . '2/spa', $this->sin_comillas($mkdirs[3]));

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
     * El preflight del VPS verifica los binarios y pasa las 2 ClientApi a hosting_type='vps'.
     *
     * 🔴 Y NO toca client_apis.path. Un path vacío en una ClientApi de VPS es lo que hace que
     * build_spa_hosting_deploy_shell() arme el docroot en la raíz de la cuenta compartida y el
     * `find . -mindepth 1 -delete` vacíe el public_html de los ~40 clientes activos.
     */
    public function test_el_preflight_del_vps_marca_las_apis_sin_tocar_el_path(): void
    {
        $datos     = $this->preparar_cliente_vps();
        $slug      = $datos['slug'];
        $path_1    = $datos['api1']->path;
        $path_2    = $datos['api2']->path;

        $datos['proveedor']->provision_check();

        $crudos = $this->runner_fake()->crudos;
        $this->assertContains('command -v clpctl', $crudos);
        $this->assertContains('command -v supervisorctl', $crudos);

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

        $primero = $this->sin_comillas($sitios[0]);
        $this->assertStringContainsString('--domainName=api-' . $slug . '.comerciocity.com', $primero);
        $this->assertStringContainsString('--phpVersion=7.4', $primero);
        $this->assertStringContainsString('--vhostTemplate=Generic', $primero);
        $this->assertStringContainsString('--siteUser=api-' . $slug, $primero);

        /* 🔴 El aserto que importa de este test. */
        $this->assertCount(2, $this->runner_fake()->crudos_con('rmdir '));
        $this->assertSame([], $this->runner_fake()->crudos_con('rm -rf'));
        $this->assertSame([], $this->runner_fake()->crudos_con('rm -r'));

        /* El symlink es solo de las dos APIs; el docroot del SPA es htdocs/<dominio> tal cual. */
        $enlaces = $this->runner_fake()->crudos_con('ln -sfn');
        $this->assertCount(2, $enlaces);
        $this->assertStringContainsString(
            '/home/api-' . $slug . '/empresa-api/public /home/api-' . $slug . '/htdocs/api-' . $slug . '.comerciocity.com',
            $this->sin_comillas($enlaces[0])
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

        $comando = $this->sin_comillas($comandos[0]);
        $this->assertStringContainsString('--databaseName=' . $slug, $comando);
        $this->assertStringContainsString('--databaseUserName=' . $slug, $comando);
        $this->assertStringNotContainsString('u767360347_', $comando);

        $this->assertSame($slug, ClientApi::find($datos['api1']->id)->provisioning_secrets['db_name']);
    }

    /**
     * El cron del VPS usa el patrón idempotente del plan, y con Kernel nuevo NO crea supervisor.
     */
    public function test_el_cron_del_vps_es_idempotente_y_sin_supervisor_con_kernel_nuevo(): void
    {
        $datos    = $this->preparar_cliente_vps();
        $slug     = $datos['slug'];
        $api_path = '/home/api-' . $slug . '/empresa-api';

        $datos['proveedor']->provision_cron($api_path, true);

        $crons = $this->runner_fake()->crudos_con('crontab');
        $this->assertCount(1, $crons);

        $comando = $this->sin_comillas($crons[0]);
        $this->assertStringContainsString('crontab -u api-' . $slug . ' -l', $comando);
        $this->assertStringContainsString('grep -qF', $comando);
        $this->assertStringContainsString('| crontab -u api-' . $slug . ' -', $comando);
        $this->assertStringContainsString($api_path . '/artisan schedule:run', $comando);
        $this->assertStringNotContainsString('flock', $comando);

        /* 🔴 Con Kernel nuevo el supervisor competiría por los mismos jobs que el scheduler. */
        $this->assertSame([], $this->runner_fake()->crudos_con('supervisorctl'));
        $this->assertSame([], $this->runner_fake()->crudos_con('/etc/supervisor'));
    }

    /**
     * Con Kernel viejo va el queue:work con flock Y el worker de supervisor.
     */
    public function test_con_kernel_viejo_el_vps_agrega_el_worker_de_supervisor(): void
    {
        $datos    = $this->preparar_cliente_vps();
        $slug     = $datos['slug'];
        $api_path = '/home/api-' . $slug . '/empresa-api';

        $datos['proveedor']->provision_cron($api_path, false);

        $cron = $this->sin_comillas($this->runner_fake()->crudos_con('crontab')[0]);
        $this->assertStringContainsString('flock -n /tmp/queue-' . $slug . '.lock', $cron);
        $this->assertStringContainsString('queue:work --stop-when-empty', $cron);

        $conf = $this->runner_fake()->crudos_con('/etc/supervisor/conf.d/api-' . $slug . '-queue.conf');
        $this->assertNotEmpty($conf);
        $this->assertStringContainsString('[program:api-' . $slug . '-queue]', $conf[0]);
        $this->assertStringContainsString('user=api-' . $slug, $conf[0]);

        $this->assertNotEmpty($this->runner_fake()->crudos_con('supervisorctl reread'));
        $this->assertNotEmpty($this->runner_fake()->crudos_con('supervisorctl update'));
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
     * Test 10 de §7 — 🔴 la guarda contra el `find . -mindepth 1 -delete`.
     *
     * Mientras U9 no exista, el pipeline de instalación resuelve la credencial SSH, el path de la
     * API y el del SPA asumiendo hosting compartido. Con las ClientApi ya pasadas a
     * hosting_type='vps' por provision_check, build_spa_hosting_deploy_shell() arma el docroot como
     * 'domains/comerciocity.com/public_html/' . get_spa_path() y ese `find -delete` vacía el
     * public_html de los ~40 clientes activos. Este 422 es lo único que lo impide, y U9 es la única
     * unidad autorizada a sacarlo.
     */
    public function test_el_alta_en_vps_se_rechaza_con_422_y_en_castellano(): void
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

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('todavía no está soportada', $respuesta->json('error'));
        $this->assertStringContainsString('hosting compartido', $respuesta->json('error'));

        /* 🔴 Y no se creó ni una fila: el rechazo va antes de tocar la base. */
        $this->assertSame(
            0,
            ClientInstallation::where('client_id', $datos['client']->id)
                ->where('id', '!=', $datos['installation']->id)
                ->count()
        );
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
        $runner->responder('dig +short', '76.13.171.147');

        foreach (['api-' . $slug, 'api-' . $slug . '2'] as $label) {
            $docroot = '/home/' . $label . '/htdocs/' . $label . '.comerciocity.com';
            $runner->responder('readlink ' . escapeshellarg($docroot), '/home/' . $label . '/empresa-api/public');
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
     * Saca las comillas de un comando para poder afirmar su contenido sin depender del sistema.
     *
     * ⚠️ escapeshellarg() es dependiente del sistema operativo: en Linux —donde corre admin-api en
     * producción— envuelve en comillas simples, y en el Windows de esta máquina en dobles. Lo que
     * los tests fijan es el comando, no el escapado.
     *
     * @param  string  $comando
     * @return string
     */
    private function sin_comillas(string $comando): string
    {
        return str_replace(['"', "'"], '', $comando);
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

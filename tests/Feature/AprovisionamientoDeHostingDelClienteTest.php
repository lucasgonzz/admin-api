<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Services\HostingProvisioningService;
use App\Services\HostingerApiClient;
use App\Services\SharedHostingProvisioning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\HostingerApiClientFake;
use Tests\TestCase;

/**
 * Aprovisionamiento del hosting del cliente desde el admin.
 *
 * Cubre las columnas nuevas (U2) y el aprovisionamiento del hosting compartido (U3): el camino
 * feliz de punta a punta, la idempotencia del reintento, el fallo temprano sin token, la guarda G1
 * (en shared no existe el PUT de DNS) y las guardas de derivación del slug.
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostinger = new HostingerApiClientFake();
        $this->app->instance(HostingerApiClient::class, $this->hostinger);

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
        $fuente = file_get_contents(app_path('Services/SharedHostingProvisioning.php'));
        $this->assertStringNotContainsString('->put_dns_zone(', $fuente);
        $this->assertStringNotContainsString('->create_dns_snapshot(', $fuente);
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
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

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

<?php

namespace Tests\Feature;

use App\Jobs\RunClientInstallationGroupJob;
use App\Jobs\RunClientInstallationJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\EnvTemplate;
use App\Models\Version;
use App\Services\EnvSshService;
use App\Services\InstallationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\EnvSshServiceFake;
use Tests\TestCase;

/**
 * El esqueleto que deja el subdominio secundario listo para que un upgrade corra entero.
 *
 * Lo que se protege acá no es que los comandos SSH funcionen —eso pasa en el hosting del cliente—
 * sino las cuatro cosas que, si alguien las "simplifica", se llevan puesto un sistema que ya está
 * en producción:
 *
 *   1. El esqueleto es NO DESTRUCTIVO: sobre un .env que ya existe escribe SOLO las claves que
 *      faltan. El subdominio secundario puede estar sirviendo producción hoy mismo, porque el
 *      blue/green alterna cuál de los dos es la activa.
 *   2. El .env del esqueleto lleva la MISMA base del cliente que la instalación real, pero
 *      APP_URL y las SANCTUM_* del subdominio propio de esa ClientApi, no de la hermana. Es
 *      exactamente lo que pidió Lucas y es lo primero que se rompe si alguien unifica las dos.
 *   3. clients.active_client_api_id NO lo toca ninguna instalación, ni la real ni el esqueleto:
 *      lo mueve solo el pipeline de actualización. El test existe para que nadie lo agregue
 *      "para que quede prolijo".
 *   4. El contrato viejo sigue andando: sin 'targets' el endpoint devuelve exactamente lo de
 *      antes, y una instalación suelta encola el job de siempre.
 *
 * 🔴 phpunit.xml fija QUEUE_CONNECTION=sync: todo test que llegue a /start tiene que hacer
 * Queue::fake() ANTES, o el pipeline sale a abrir SSH de verdad contra el hosting de un cliente.
 *
 * ⚠️ admin_testing_s4 tiene la tabla env_templates VACÍA (el seeder nunca se corrió ahí). Las
 * filas is_manual_on_create las siembra cada test con crear_templates_de_env(): sin eso, start()
 * nunca devuelve missing_keys y update_env_values() filtra todo a un array vacío, así que los
 * tests pasan sin probar nada.
 */
class InstalacionEsqueletoEnElSubdominioSecundarioTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Reemplazo en memoria del servicio SSH, bindeado en el container para toda la prueba.
     *
     * @var EnvSshServiceFake
     */
    private $ssh_fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ssh_fake = new EnvSshServiceFake();

        // step_write_env() resuelve el servicio con app(), justamente para poder reemplazarlo acá.
        $this->app->instance(EnvSshService::class, $this->ssh_fake);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREACIÓN: el par real + esqueleto
    // ─────────────────────────────────────────────────────────────────────────

    public function test_crear_con_dos_destinos_deja_una_real_y_un_esqueleto_del_mismo_grupo(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $client  = $datos['client'];
        $api1    = $datos['api1'];
        $api2    = $datos['api2'];
        $version = $this->crear_version_publicada();

        $activa_antes = $client->active_client_api_id;

        // Los targets van a propósito en el orden "equivocado": el backend tiene que reordenarlos.
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $client->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $api2->id, 'kind' => 'esqueleto'],
                ['client_api_id' => $api1->id, 'kind' => 'completa'],
            ],
        ]);

        $response->assertStatus(201);

        $models = $response->json('models');
        $this->assertCount(2, $models);

        // La real primero: es la larga y es la que el operador mira en el log en vivo.
        $this->assertSame('completa', $models[0]['kind']);
        $this->assertSame($api1->id, $models[0]['client_api_id']);
        $this->assertSame('esqueleto', $models[1]['kind']);
        $this->assertSame($api2->id, $models[1]['client_api_id']);

        // Mismo grupo, y no null: es lo que después hace que start() las arranque juntas.
        $this->assertNotNull($models[0]['group_uuid']);
        $this->assertSame($models[0]['group_uuid'], $models[1]['group_uuid']);

        // 'model' se conserva y es la real: el SPA viejo lee esa clave y no puede quedarse sin ella.
        $this->assertSame($models[0]['id'], $response->json('model.id'));

        // Las dos filas quedan pendientes y con la versión pedida.
        $this->assertSame('pendiente', $models[0]['status']);
        $this->assertSame('pendiente', $models[1]['status']);
        $this->assertSame($version->id, $models[1]['version_id']);

        // 🔴 Crear una instalación NO mueve la API activa del cliente: eso lo hace solo el pipeline
        // de actualización cuando termina.
        $this->assertSame($activa_antes, $client->fresh()->active_client_api_id);
    }

    public function test_sin_instalacion_real_se_crea_solo_el_esqueleto_y_no_se_toca_la_api_activa(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $client  = $datos['client'];
        $api2    = $datos['api2'];
        $version = $this->crear_version_publicada();

        $activa_antes = $client->active_client_api_id;

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $client->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $api2->id, 'kind' => 'esqueleto'],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertCount(1, $response->json('models'));
        $this->assertSame('esqueleto', $response->json('model.kind'));

        // Una sola fila NO forma grupo: es lo que hace que start(), show() y update_env_values()
        // la traten de a una, exactamente como a cualquier instalación vieja.
        $this->assertNull($response->json('model.group_uuid'));

        // 🔴 El caso de uso inmediato de Lucas: clientes que YA están en producción sobre su
        // subdominio principal. Si esto moviera active_client_api_id, el ERP del cliente pasaría a
        // apuntar a un subdominio que todavía no tiene ni el código de la API adentro.
        $this->assertSame($activa_antes, $client->fresh()->active_client_api_id);
    }

    public function test_el_payload_viejo_de_un_client_api_id_suelto_sigue_creando_una_completa(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $client  = $datos['client'];
        $api1    = $datos['api1'];
        $version = $this->crear_version_publicada();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'     => $client->id,
            'client_api_id' => $api1->id,
            'version_id'    => $version->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame('completa', $response->json('model.kind'));
        $this->assertNull($response->json('model.group_uuid'));
        $this->assertSame($api1->id, $response->json('model.client_api_id'));

        // Un consumidor viejo del endpoint tiene que seguir recibiendo una sola fila.
        $this->assertCount(1, $response->json('models'));
    }

    public function test_el_endpoint_por_cliente_sigue_creando_una_completa_con_el_kind_en_la_respuesta(): void
    {
        $admin  = $this->crear_admin();
        $datos  = $this->crear_cliente_con_dos_apis();
        $client = $datos['client'];
        $this->crear_version_publicada();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/clients/' . $client->id . '/installations', []);

        $response->assertStatus(201);

        // 🔴 assertSame y no assertNotNull: este endpoint devolvía kind=null porque Eloquent no
        // relee el default de MySQL después de create(). Lo resuelve $attributes en el modelo, y es
        // una regresión fácil de reintroducir borrando esa propiedad "porque ya está en la base".
        $this->assertSame('completa', $response->json('model.kind'));
        $this->assertNull($response->json('model.group_uuid'));

        // El shape viejo no tiene 'models' y no lo tiene que empezar a tener.
        $this->assertArrayNotHasKey('models', $response->json());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREACIÓN: los rechazos, todos sin dejar filas a medias
    // ─────────────────────────────────────────────────────────────────────────

    public function test_una_api_de_otro_cliente_es_rechazada_en_targets(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $ajeno   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        $antes = ClientInstallation::count();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
                ['client_api_id' => $ajeno['api1']->id, 'kind' => 'esqueleto'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'La API indicada no pertenece al cliente seleccionado.']);

        // 🔴 Ni siquiera la primera, que era válida: un pedido inválido no puede dejar media
        // instalación creada, porque esa fila huérfana después arranca sola desde el listado.
        $this->assertSame($antes, ClientInstallation::count());
    }

    public function test_dos_destinos_sobre_la_misma_api_son_rechazados(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        $antes = ClientInstallation::count();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
                ['client_api_id' => $datos['api1']->id, 'kind' => 'esqueleto'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame($antes, ClientInstallation::count());
    }

    public function test_dos_instalaciones_reales_son_rechazadas(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        $antes = ClientInstallation::count();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
                ['client_api_id' => $datos['api2']->id, 'kind' => 'completa'],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame($antes, ClientInstallation::count());
    }

    public function test_targets_vacio_es_rechazado(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        $antes = ClientInstallation::count();

        // El SPA ya deshabilita el botón con las dos destildadas; esto es el freno del backend.
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [],
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error' => 'Elegí al menos una API destino.']);
        $this->assertSame($antes, ClientInstallation::count());
    }

    public function test_mas_de_dos_destinos_se_rechazan_en_castellano_y_diciendo_que_hacer(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        // Client::client_apis() es un hasMany sin límite y hay endpoints vivos para agregar una
        // tercera API a un cliente, así que este payload sale del modal —que tilda todas por
        // default— sin que el operador haya hecho nada raro.
        $api3               = new ClientApi();
        $api3->client_id    = $datos['client']->id;
        $api3->url          = rtrim($datos['api1']->url, '/') . '3';
        $api3->spa_url      = rtrim($datos['api1']->spa_url, '/') . '3';
        $api3->path         = $datos['api1']->path . '3';
        $api3->hosting_type = 'shared_hosting';
        $api3->save();

        $antes = ClientInstallation::count();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
                ['client_api_id' => $datos['api2']->id, 'kind' => 'esqueleto'],
                ['client_api_id' => $api3->id, 'kind' => 'esqueleto'],
            ],
        ]);

        $response->assertStatus(422);

        // 🔴 config/app.php tiene locale 'en': sin mensaje propio, acá salía el default de Laravel
        // en inglés, en un endpoint donde todos los demás 422 están en castellano y explicados.
        $mensaje = $response->json('errors.targets.0');
        $this->assertStringContainsString('Solo se pueden instalar dos APIs', $mensaje);
        $this->assertStringContainsString('Destildá', $mensaje);
        $this->assertStringNotContainsString('may not have more than', $mensaje);

        $this->assertSame($antes, ClientInstallation::count());
    }

    public function test_un_esqueleto_sobre_una_api_en_vps_es_rechazado(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $api2    = $datos['api2'];
        $version = $this->crear_version_publicada();

        $api2->hosting_type = 'vps';
        $api2->save();

        $antes = ClientInstallation::count();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $api2->id, 'kind' => 'esqueleto'],
            ],
        ]);

        // El pipeline del esqueleto resuelve las rutas asumiendo hosting compartido: dejarlo correr
        // sobre una API en VPS crearía los directorios y el .env en el servidor equivocado y
        // devolvería éxito. Es el mismo bug que ya está documentado en EnvSshService.
        $response->assertStatus(422);
        $this->assertSame($antes, ClientInstallation::count());
    }

    public function test_una_instalacion_completa_sobre_una_api_en_vps_sigue_permitida(): void
    {
        $admin   = $this->crear_admin();
        $datos   = $this->crear_cliente_con_dos_apis();
        $api2    = $datos['api2'];
        $version = $this->crear_version_publicada();

        $api2->hosting_type = 'vps';
        $api2->save();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $api2->id, 'kind' => 'completa'],
            ],
        ]);

        // 🔴 La restricción de VPS es SOLO del esqueleto. El pipeline completo sobre VPS es lo que
        // hay hoy en producción: si alguien mueve la guarda al nivel del target, lo rompe.
        $response->assertStatus(201);
        $this->assertSame('completa', $response->json('model.kind'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VARIABLES MANUALES
    // ─────────────────────────────────────────────────────────────────────────

    public function test_las_variables_manuales_se_guardan_en_las_dos_filas_del_grupo(): void
    {
        $admin = $this->crear_admin();
        $this->crear_templates_de_env();
        $filas = $this->crear_grupo_pendiente($admin);

        $valores = $this->valores_manuales();

        $response = $this->actingAs($admin, 'sanctum')->putJson(
            '/api/admin/client-installations/' . $filas['real']->id . '/env-values',
            ['values' => $valores]
        );

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('models'));

        // Se cargan una vez y valen para las dos filas: los dos subdominios sirven la MISMA base
        // del cliente, que es lo que hace que la alternancia blue/green funcione.
        //
        // ⚠️ assertEquals y no assertSame: env_manual_values es una columna JSON de MySQL, y MySQL
        // reordena las claves de todo objeto JSON (primero por largo, después alfabéticamente) al
        // guardarlo. El orden que vuelve NO es el que se mandó y no es parte del contrato: lo que
        // importa es que estén las seis claves con su valor.
        $this->assertEquals($valores, $filas['real']->fresh()->env_manual_values);
        $this->assertEquals($valores, $filas['esqueleto']->fresh()->env_manual_values);
    }

    public function test_las_variables_manuales_de_una_instalacion_suelta_no_devuelven_models(): void
    {
        $admin = $this->crear_admin();
        $this->crear_templates_de_env();
        $datos   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        $suelta = ClientInstallation::create([
            'client_id'     => $datos['client']->id,
            'client_api_id' => $datos['api1']->id,
            'version_id'    => $version->id,
            'status'        => 'pendiente',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson(
            '/api/admin/client-installations/' . $suelta->id . '/env-values',
            ['values' => $this->valores_manuales()]
        );

        $response->assertStatus(200);

        // Compatibilidad hacia atrás: sin grupo, el JSON es el mismo que devolvía antes.
        $this->assertArrayNotHasKey('models', $response->json());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INICIO DEL PIPELINE
    // ─────────────────────────────────────────────────────────────────────────

    public function test_iniciar_encola_una_sola_corrida_con_la_real_primero(): void
    {
        Queue::fake();

        $admin = $this->crear_admin();
        $this->crear_templates_de_env();
        $filas = $this->crear_grupo_pendiente($admin);

        $this->cargar_valores_manuales($admin, $filas['real']);

        // Se arranca desde la fila del ESQUELETO a propósito: el operador puede apretar el botón
        // en cualquiera de las dos y el orden de la corrida no puede depender de eso.
        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/client-installations/' . $filas['esqueleto']->id . '/start');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('models'));

        $this->assertSame('instalando', $filas['real']->fresh()->status);
        $this->assertSame('instalando', $filas['esqueleto']->fresh()->status);

        // 🔴 Un solo job para las dos: dos dispatch saldrían a la cola sin orden garantizado y
        // abrirían dos sesiones SSH en paralelo contra el mismo hosting compartido.
        Queue::assertNotPushed(RunClientInstallationJob::class);

        $uuid_real      = $filas['real']->uuid;
        $uuid_esqueleto = $filas['esqueleto']->uuid;
        Queue::assertPushed(RunClientInstallationGroupJob::class, function ($job) use ($uuid_real, $uuid_esqueleto) {
            $uuids = $this->uuids_del_job($job);

            return $uuids === [$uuid_real, $uuid_esqueleto];
        });
    }

    public function test_el_segundo_start_sobre_el_par_no_encola_una_segunda_corrida(): void
    {
        Queue::fake();

        $admin = $this->crear_admin();
        $this->crear_templates_de_env();
        $filas = $this->crear_grupo_pendiente($admin);

        $this->cargar_valores_manuales($admin, $filas['real']);

        // En la pestaña del cliente las dos filas del par están en pantalla, cada una con su botón
        // "Iniciar" y su propio flag `starting`: deshabilitar uno no deshabilita el otro, así que
        // dos clics seguidos son alcanzables con el mouse. Lo que no puede pasar es que salgan DOS
        // pipelines sobre el mismo hosting —el `find . -mindepth 1 -delete` del public_html del SPA
        // de uno contra el `unzip` del otro—, y menos todavía un 500.
        $primero = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/client-installations/' . $filas['real']->id . '/start');
        $primero->assertStatus(200);

        $segundo = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/client-installations/' . $filas['esqueleto']->id . '/start');

        $segundo->assertStatus(422);
        // El estado que se le muestra es el de AHORA, no el que la fila tenía cuando se cargó.
        $this->assertStringContainsString('instalando', $segundo->json('error'));

        Queue::assertPushed(RunClientInstallationGroupJob::class, 1);
        Queue::assertNotPushed(RunClientInstallationJob::class);
    }

    public function test_iniciar_una_instalacion_suelta_sigue_encolando_el_job_de_siempre(): void
    {
        Queue::fake();

        $admin = $this->crear_admin();
        $this->crear_templates_de_env();
        $datos   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        $suelta = ClientInstallation::create([
            'client_id'         => $datos['client']->id,
            'client_api_id'     => $datos['api1']->id,
            'version_id'        => $version->id,
            'status'            => 'pendiente',
            'env_manual_values' => $this->valores_manuales(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/client-installations/' . $suelta->id . '/start');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('models', $response->json());

        Queue::assertPushed(RunClientInstallationJob::class);
        Queue::assertNotPushed(RunClientInstallationGroupJob::class);

        // Y el segundo start sobre una fila que ya arrancó responde con el mensaje exacto de
        // siempre: es texto que el SPA muestra tal cual.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/client-installations/' . $suelta->id . '/start')
            ->assertStatus(422)
            ->assertJson(['error' => "No se puede iniciar una instalación en estado 'instalando'."]);
    }

    public function test_iniciar_sin_las_variables_manuales_no_encola_nada_y_deja_las_filas_pendientes(): void
    {
        Queue::fake();

        $admin = $this->crear_admin();
        $this->crear_templates_de_env();
        $filas = $this->crear_grupo_pendiente($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/client-installations/' . $filas['real']->id . '/start');

        $response->assertStatus(422);
        $response->assertJsonStructure(['error', 'missing_keys']);
        $this->assertContains('DB_DATABASE', $response->json('missing_keys'));

        Queue::assertNothingPushed();

        // 🔴 Y las dos siguen en 'pendiente': una fila que quedó en 'instalando' sin job atrás no se
        // puede reiniciar nunca más (start() exige 'pendiente') y hay que borrarla a mano.
        $this->assertSame('pendiente', $filas['real']->fresh()->status);
        $this->assertSame('pendiente', $filas['esqueleto']->fresh()->status);
    }

    public function test_reiniciar_un_grupo_a_medias_arranca_solo_la_fila_que_quedo_pendiente(): void
    {
        Queue::fake();

        $admin = $this->crear_admin();
        $this->crear_templates_de_env();
        $filas = $this->crear_grupo_pendiente($admin);

        $this->cargar_valores_manuales($admin, $filas['real']);

        // La real ya terminó bien y solo falta el esqueleto: es el flujo de reintento.
        $filas['real']->update(['status' => 'completada']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/client-installations/' . $filas['esqueleto']->id . '/start');

        $response->assertStatus(200);

        // La que ya estaba completada no se vuelve a correr.
        $this->assertSame('completada', $filas['real']->fresh()->status);
        $this->assertSame('instalando', $filas['esqueleto']->fresh()->status);

        // Con una sola fila a correr se usa el job de siempre, no el del grupo.
        Queue::assertPushed(RunClientInstallationJob::class);
        Queue::assertNotPushed(RunClientInstallationGroupJob::class);
    }

    public function test_show_de_una_fila_del_grupo_trae_a_la_hermana(): void
    {
        $admin = $this->crear_admin();
        $this->crear_templates_de_env();
        $filas = $this->crear_grupo_pendiente($admin);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/client-installations/' . $filas['esqueleto']->id);

        $response->assertStatus(200);

        // El polling del SPA corre cada 3 segundos: sin esto necesitaría un request por fila para
        // ver que la otra ya terminó.
        $this->assertCount(2, $response->json('models'));
        $this->assertSame($filas['esqueleto']->id, $response->json('model.id'));
        $this->assertSame('completa', $response->json('models.0.kind'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EL .ENV DEL ESQUELETO (sin red: el servicio SSH está reemplazado por el fake)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_el_esqueleto_no_escribe_ninguna_clave_si_el_destino_ya_tiene_env(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        // El subdominio secundario ya tiene un sistema andando: su .env es el bueno.
        $this->ssh_fake->envs[$filas['api2']->id] = "DB_DATABASE=la_base_buena\nAPP_URL=https://otra.comerciocity.com\n";

        $service = new InstallationService($filas['esqueleto']);

        $resultado = $service->filter_env_vars_to_write($this->ssh_fake, [
            'DB_DATABASE'       => 'lo_que_tipeo_el_operador',
            'APP_URL'           => 'https://nueva.comerciocity.com',
            'DB_USERNAME'       => 'usuario_nuevo',
            'SESSION_DOMAIN'    => '.comerciocity.com',
            'QUEUE_CONNECTION'  => 'database',
        ]);

        // 🔴 NI UNA clave. Ni las que ya están ni las que faltan.
        //
        // Que las ausentes tampoco se escriban no es exceso de celo: el modal tilda las dos APIs por
        // default, así que el destino más probable de un esqueleto sobre un cliente en producción es
        // el subdominio que está sirviendo HOY. Ahí, SESSION_DOMAIN y QUEUE_CONNECTION —dos claves
        // que la plantilla global sumó después de que ese cliente se instaló, y que por lo tanto le
        // "faltan"— entran solas y lo rompen en silencio: la primera desloguea a todos los usuarios,
        // la segunda manda los jobs a una cola que nadie consume.
        $this->assertSame([], $resultado);
    }

    public function test_el_esqueleto_avisa_en_el_log_que_no_toco_el_env_que_ya_estaba(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        $this->ssh_fake->envs[$filas['api2']->id] = "APP_URL=https://vieja.comerciocity.com\n";

        $service = new InstallationService($filas['esqueleto']);
        $service->filter_env_vars_to_write($this->ssh_fake, [
            'APP_URL' => 'https://nueva.comerciocity.com',
        ]);

        // No escribir es la decisión correcta, pero tiene que ser VISIBLE: el operador está mirando
        // el log en vivo y si no dice nada, va a creer que el .env quedó configurado.
        $warnings = $filas['esqueleto']->deployment_logs()
            ->where('level', 'warning')
            ->get()
            ->pluck('line')
            ->implode(' | ');

        $this->assertStringContainsString('YA tenía un .env', $warnings);
        $this->assertStringContainsString('no le escribió ninguna clave', $warnings);
        // Y le dice qué hacer si de verdad falta algo, en vez de dejarlo adivinando.
        $this->assertStringContainsString('a mano', $warnings);
    }

    public function test_el_esqueleto_escribe_el_env_completo_si_el_destino_no_tiene_ninguno(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        // Sin entrada en el fake, el .env no existe en el destino: subdominio virgen.
        $service = new InstallationService($filas['esqueleto']);

        $vars = [
            'DB_CONNECTION'            => 'mysql',
            'DB_HOST'                  => '127.0.0.1',
            'DB_PORT'                  => '3306',
            'DB_DATABASE'              => 'base_del_cliente',
            'DB_USERNAME'              => 'usuario',
            'DB_PASSWORD'              => 'secreto',
            'APP_URL'                  => 'https://api-dos.comerciocity.com',
            'SANCTUM_STATEFUL_DOMAINS' => 'dos.comerciocity.com',
            'SANCTUM_STATEFUL_CORS'    => 'https://dos.comerciocity.com',
        ];

        $resultado = $service->filter_env_vars_to_write($this->ssh_fake, $vars);

        $this->assertSame($vars, $resultado);
    }

    public function test_la_instalacion_real_escribe_todo_aunque_el_destino_ya_tenga_env(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        $this->ssh_fake->envs[$filas['api1']->id] = "DB_DATABASE=lo_que_habia\n";

        $service = new InstallationService($filas['real']);

        $vars      = ['DB_DATABASE' => 'la_nueva', 'APP_URL' => 'https://api-uno.comerciocity.com'];
        $resultado = $service->filter_env_vars_to_write($this->ssh_fake, $vars);

        // 🔴 El filtro es SOLO del esqueleto. Una instalación real es desde cero y el .env es de
        // ella: si el filtro se aplicara también acá, un reintento dejaría media configuración
        // vieja adentro y el sistema arrancaría contra la base equivocada.
        $this->assertSame($vars, $resultado);
    }

    public function test_el_env_del_esqueleto_lleva_la_base_del_cliente_y_el_subdominio_propio(): void
    {
        $this->crear_templates_de_env();
        $filas = $this->crear_grupo_para_el_servicio();

        $valores = $this->valores_manuales();
        $filas['real']->update(['env_manual_values' => $valores]);
        $filas['esqueleto']->update(['env_manual_values' => $valores]);

        $this->correr_write_env($filas['real']->fresh());
        $this->correr_write_env($filas['esqueleto']->fresh());

        $escrito_en_la_real      = $this->ssh_fake->escrituras[$filas['api1']->id];
        $escrito_en_el_esqueleto = $this->ssh_fake->escrituras[$filas['api2']->id];

        // 1. Las variables de base de datos son IDÉNTICAS en los dos subdominios: sirven la misma
        //    base del cliente, que es justamente lo que hace que la alternancia blue/green ande.
        foreach (array_keys($valores) as $clave_de_base) {
            $this->assertSame(
                $escrito_en_la_real[$clave_de_base],
                $escrito_en_el_esqueleto[$clave_de_base],
                'La clave ' . $clave_de_base . ' tiene que ser la misma en los dos subdominios.'
            );
        }
        $this->assertSame($valores['DB_DATABASE'], $escrito_en_el_esqueleto['DB_DATABASE']);

        // 2. 🔴 Pero APP_URL y las SANCTUM_* son las del subdominio PROPIO de cada ClientApi. Es lo
        //    que Lucas pidió explícito y es lo primero que se rompe si alguien "simplifica" el
        //    esqueleto para que copie el .env de la hermana: el subdominio secundario quedaría
        //    respondiendo con las cookies y el CORS del principal.
        $this->assertSame(rtrim($filas['api1']->url, '/'), $escrito_en_la_real['APP_URL']);
        $this->assertSame(rtrim($filas['api2']->url, '/'), $escrito_en_el_esqueleto['APP_URL']);
        $this->assertNotSame($escrito_en_la_real['APP_URL'], $escrito_en_el_esqueleto['APP_URL']);

        $this->assertSame(
            parse_url($filas['api2']->spa_url, PHP_URL_HOST),
            $escrito_en_el_esqueleto['SANCTUM_STATEFUL_DOMAINS']
        );
        $this->assertSame(rtrim($filas['api2']->spa_url, '/'), $escrito_en_el_esqueleto['SANCTUM_STATEFUL_CORS']);
        $this->assertNotSame(
            $escrito_en_la_real['SANCTUM_STATEFUL_DOMAINS'],
            $escrito_en_el_esqueleto['SANCTUM_STATEFUL_DOMAINS']
        );
    }

    public function test_write_env_del_esqueleto_no_toca_un_env_que_ya_existe(): void
    {
        $this->crear_templates_de_env();
        $filas = $this->crear_grupo_para_el_servicio();

        $filas['esqueleto']->update(['env_manual_values' => $this->valores_manuales()]);

        // El subdominio secundario ya tiene sistema: su .env trae la base buena y su propia APP_URL.
        $env_original = "DB_DATABASE=la_base_de_produccion\nAPP_URL=" . rtrim($filas['api2']->url, '/') . "\n";

        $this->ssh_fake->envs[$filas['api2']->id] = $env_original;

        $this->correr_write_env($filas['esqueleto']->fresh());

        // Es el caso borde que preocupaba a Lucas, probado de punta a punta y no solo en el filtro:
        // no hay NI UNA escritura contra ese subdominio, y el archivo queda byte por byte como
        // estaba. Si algún día vuelve a aparecer una entrada acá, alguien repuso el filtro por clave
        // y le está tocando el .env a un servidor que puede estar en producción.
        $this->assertArrayNotHasKey($filas['api2']->id, $this->ssh_fake->escrituras);
        $this->assertSame($env_original, $this->ssh_fake->envs[$filas['api2']->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EL PIPELINE DEL ESQUELETO
    // ─────────────────────────────────────────────────────────────────────────

    public function test_las_rutas_que_exige_el_esqueleto_incluyen_public_y_el_symlink_de_storage(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        $service = new InstallationService($filas['esqueleto']);

        $metodo = new \ReflectionMethod($service, 'required_skeleton_paths');
        $metodo->setAccessible(true);
        $paths = $metodo->invoke($service);

        // 🔴 Candado contra el "esto sobra, lo simplifico". Cada una de estas rutas es algo que el
        // ZIP del upgrade excluye (DeploymentService, el zip de step_upload_api) y que el upgrade
        // NO repone por su cuenta: sin ellas el subdominio no bootea ni después de un upgrade que
        // terminó en verde.
        $this->assertContains('public/index.php', $paths);
        $this->assertContains('public/.htaccess', $paths);
        $this->assertContains('public/storage', $paths);
        $this->assertContains('.env', $paths);
        $this->assertContains('bootstrap/cache', $paths);
        $this->assertContains('storage/framework/views', $paths);

        // Lo que NO tiene que estar, porque el upgrade sí lo repone solo y pedirlo haría fallar
        // todo esqueleto contra un subdominio virgen.
        $this->assertNotContains('vendor/autoload.php', $paths);
    }

    public function test_la_verificacion_exige_que_public_storage_sea_un_symlink_y_no_un_directorio(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        $service = new InstallationService($filas['esqueleto']);

        $metodo = new \ReflectionMethod($service, 'build_skeleton_verify_command');
        $metodo->setAccessible(true);
        $comando = $metodo->invoke($service);

        // 🔴 public/storage se verifica con -L (SER un symlink), no con -e (existir). Si en esa ruta
        // hay un directorio común, la guarda del ln -s no crea el enlace —bien, el esqueleto no
        // borra nada— y con -e la verificación lo daba por bueno: /storage/... de ese cliente
        // devuelve 404 para siempre y ningún upgrade lo arregla, porque no hay un solo storage:link
        // en DeploymentService.
        $this->assertStringContainsString("[ -L 'public/storage' ]", $comando);
        $this->assertStringContainsString('NO_SYMLINK public/storage', $comando);

        // Y no queda mezclado en el for de existencia, que es de donde salía el falso verde.
        $this->assertStringNotContainsString("for P in 'public/storage'", $comando);
        $this->assertStringNotContainsString("'public/storage' '.env'", $comando);

        // Las demás rutas siguen verificándose por existencia: son archivos y directorios comunes.
        $this->assertStringContainsString('[ -e "$P" ] || echo "FALTA $P"', $comando);
        $this->assertStringContainsString("'public/index.php'", $comando);
    }

    public function test_un_directorio_comun_en_public_storage_hace_fallar_la_etapa_con_instrucciones(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        $service = new InstallationService($filas['esqueleto']);

        $metodo = new \ReflectionMethod($service, 'interpret_skeleton_verify_output');
        $metodo->setAccessible(true);

        try {
            $metodo->invoke($service, "NO_SYMLINK public/storage\nVERIFY_DONE\n");
            $this->fail('Un directorio común en public/storage tiene que hacer fallar la etapa.');
        } catch (\RuntimeException $e) {
            $mensaje = $e->getMessage();
        }

        // El mensaje tiene que decir qué encontró y qué hacer: es un arreglo manual por SSH y el
        // operador no tiene por qué saberlo de memoria.
        $this->assertStringContainsString('public/storage', $mensaje);
        $this->assertStringContainsString('NO es un symlink', $mensaje);
        $this->assertStringContainsString('ln -s ../storage/app/public public/storage', $mensaje);

        // Y una salida limpia sigue pasando, obvio.
        $metodo->invoke($service, "VERIFY_DONE\n");
    }

    public function test_el_timeout_del_job_de_grupo_cubre_las_dos_corridas(): void
    {
        $del_grupo = new RunClientInstallationGroupJob(['uno', 'dos']);
        $suelto    = new RunClientInstallationJob('uno');

        // 🔴 El job de grupo corre DOS pipelines adentro del mismo handle(). Con el timeout de uno
        // solo, una instalación real de 28 minutos deja al esqueleto arrancando en el 28 y el worker
        // lo mata en el 30, con su fila clavada en 'instalando'.
        $this->assertGreaterThanOrEqual($suelto->timeout * 2, $del_grupo->timeout);
    }

    public function test_el_pipeline_del_esqueleto_no_sube_la_api_ni_compila_el_spa(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        $esqueleto = new InstallationService($filas['esqueleto']);
        $real      = new InstallationService($filas['real']);

        $this->assertSame(
            ['prepare_dirs', 'upload_public', 'write_env', 'finalize_skeleton'],
            $this->steps_de($esqueleto)
        );

        // La instalación real conserva su pipeline de siempre, sin una etapa de más ni de menos.
        $this->assertSame(
            ['compile_spa', 'upload_spa', 'upload_api', 'write_env', 'finalize_api'],
            $this->steps_de($real)
        );
    }

    public function test_el_servicio_rechaza_un_esqueleto_sobre_una_api_en_vps(): void
    {
        $filas = $this->crear_grupo_para_el_servicio();

        $filas['api2']->hosting_type = 'vps';
        $filas['api2']->save();

        // Segunda barrera, además del 422 del controlador: una fila creada antes de que existiera
        // la validación, o metida a mano en la base, tampoco puede llegar a escribir.
        $this->expectException(\RuntimeException::class);

        new InstallationService($filas['esqueleto']->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Corre step_write_env() de una instalación sin salir a la red.
     *
     * Es privado en el servicio y se invoca por Reflection a propósito: es la única forma de probar
     * QUÉ .env queda escrito sin un hosting del otro lado, y el .env es el corazón de esta misión.
     *
     * @param  ClientInstallation  $installation
     * @return void
     */
    private function correr_write_env(ClientInstallation $installation): void
    {
        $service = new InstallationService($installation);

        $metodo = new \ReflectionMethod($service, 'step_write_env');
        $metodo->setAccessible(true);
        $metodo->invoke($service);
    }

    /**
     * Lista de etapas que va a correr un servicio ya construido.
     *
     * @param  InstallationService  $service
     * @return array<int, string>
     */
    private function steps_de(InstallationService $service): array
    {
        $propiedad = new \ReflectionProperty($service, 'steps');
        $propiedad->setAccessible(true);

        return $propiedad->getValue($service);
    }

    /**
     * UUIDs que lleva adentro un RunClientInstallationGroupJob.
     *
     * @param  RunClientInstallationGroupJob  $job
     * @return array<int, string>
     */
    private function uuids_del_job($job): array
    {
        $propiedad = new \ReflectionProperty($job, 'installation_uuids');
        $propiedad->setAccessible(true);

        return $propiedad->getValue($job);
    }

    /**
     * Crea el par real + esqueleto pasando por el endpoint, que es como lo crea el operador.
     *
     * @param  Admin  $admin
     * @return array{real: ClientInstallation, esqueleto: ClientInstallation, api1: ClientApi, api2: ClientApi}
     */
    private function crear_grupo_pendiente(Admin $admin): array
    {
        $datos   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/installations', [
            'client_id'  => $datos['client']->id,
            'version_id' => $version->id,
            'targets'    => [
                ['client_api_id' => $datos['api1']->id, 'kind' => 'completa'],
                ['client_api_id' => $datos['api2']->id, 'kind' => 'esqueleto'],
            ],
        ]);

        $response->assertStatus(201);

        return [
            'real'      => ClientInstallation::findOrFail($response->json('models.0.id')),
            'esqueleto' => ClientInstallation::findOrFail($response->json('models.1.id')),
            'api1'      => $datos['api1'],
            'api2'      => $datos['api2'],
        ];
    }

    /**
     * Igual que crear_grupo_pendiente() pero sin pasar por HTTP y con la credencial SSH cargada,
     * que es lo que exige el constructor de InstallationService.
     *
     * @return array{real: ClientInstallation, esqueleto: ClientInstallation, api1: ClientApi, api2: ClientApi}
     */
    private function crear_grupo_para_el_servicio(): array
    {
        $this->crear_credencial_ssh();

        $datos   = $this->crear_cliente_con_dos_apis();
        $version = $this->crear_version_publicada();

        $group_uuid = (string) Str::uuid();

        $real = ClientInstallation::create([
            'client_id'     => $datos['client']->id,
            'client_api_id' => $datos['api1']->id,
            'version_id'    => $version->id,
            'kind'          => ClientInstallation::KIND_COMPLETA,
            'group_uuid'    => $group_uuid,
            'status'        => 'pendiente',
        ]);

        $esqueleto = ClientInstallation::create([
            'client_id'     => $datos['client']->id,
            'client_api_id' => $datos['api2']->id,
            'version_id'    => $version->id,
            'kind'          => ClientInstallation::KIND_ESQUELETO,
            'group_uuid'    => $group_uuid,
            'status'        => 'pendiente',
        ]);

        return [
            'real'      => $real,
            'esqueleto' => $esqueleto,
            'api1'      => $datos['api1'],
            'api2'      => $datos['api2'],
        ];
    }

    /**
     * Carga las variables manuales por el endpoint, como las carga el operador en el modal.
     *
     * @param  Admin  $admin
     * @param  ClientInstallation  $installation
     * @return void
     */
    private function cargar_valores_manuales(Admin $admin, ClientInstallation $installation): void
    {
        $this->actingAs($admin, 'sanctum')->putJson(
            '/api/admin/client-installations/' . $installation->id . '/env-values',
            ['values' => $this->valores_manuales()]
        )->assertStatus(200);
    }

    /**
     * Los valores que el operador tipea en el modal: las seis variables de base de datos.
     *
     * @return array<string, string>
     */
    private function valores_manuales(): array
    {
        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST'       => '127.0.0.1',
            'DB_PORT'       => '3306',
            'DB_DATABASE'   => 'base_del_cliente',
            'DB_USERNAME'   => 'usuario_del_cliente',
            'DB_PASSWORD'   => 'secreto',
        ];
    }

    /**
     * Siembra las filas de env_templates que el flujo real da por existentes.
     *
     * ⚠️ admin_testing_s4 tiene la tabla VACÍA: el EnvTemplateSeeder nunca corrió sobre la base del
     * slot. Sin estas filas, start() nunca junta missing_keys y update_env_values() filtra todo a
     * un array vacío, así que los tests de variables manuales pasan sin probar absolutamente nada.
     *
     * Son las mismas seis is_manual_on_create de EnvTemplateSeeder, más las dos de app que
     * step_write_env() usa como base.
     *
     * @return void
     */
    private function crear_templates_de_env(): void
    {
        $filas = [
            ['key' => 'APP_NAME', 'value' => 'ComercioCity', 'group' => 'app', 'is_manual_on_create' => false, 'sort_order' => 1],
            ['key' => 'APP_URL', 'value' => null, 'group' => 'app', 'is_manual_on_create' => false, 'sort_order' => 5],
            ['key' => 'DB_CONNECTION', 'value' => 'mysql', 'group' => 'db', 'is_manual_on_create' => true, 'sort_order' => 1],
            ['key' => 'DB_HOST', 'value' => '127.0.0.1', 'group' => 'db', 'is_manual_on_create' => true, 'sort_order' => 2],
            ['key' => 'DB_PORT', 'value' => '3306', 'group' => 'db', 'is_manual_on_create' => true, 'sort_order' => 3],
            ['key' => 'DB_DATABASE', 'value' => null, 'group' => 'db', 'is_manual_on_create' => true, 'sort_order' => 4],
            ['key' => 'DB_USERNAME', 'value' => null, 'group' => 'db', 'is_manual_on_create' => true, 'sort_order' => 5],
            ['key' => 'DB_PASSWORD', 'value' => null, 'group' => 'db', 'is_manual_on_create' => true, 'sort_order' => 6],
        ];

        foreach ($filas as $fila) {
            $template                      = new EnvTemplate();
            $template->key                 = $fila['key'];
            $template->value               = $fila['value'];
            $template->group               = $fila['group'];
            $template->scope               = 'empresa';
            $template->is_common           = false;
            $template->is_manual_on_create = $fila['is_manual_on_create'];
            $template->sort_order          = $fila['sort_order'];
            $template->save();
        }
    }

    /**
     * Cliente con sus DOS ClientApi (el par que crea PromoteLeadToClientService al promover un
     * lead), las dos en hosting compartido y con la primera como activa.
     *
     * @return array{client: Client, api1: ClientApi, api2: ClientApi}
     */
    private function crear_cliente_con_dos_apis(): array
    {
        // Str::random para no chocar con los datos que ya viven en admin_testing_s4.
        $sufijo = Str::random(8);

        $client                  = new Client();
        $client->name            = 'Cliente ' . $sufijo;
        $client->slug            = 'cliente-' . strtolower($sufijo);
        $client->api_url         = 'https://api-' . strtolower($sufijo) . '.comerciocity.com';
        $client->api_key         = Str::random(20);
        $client->inbound_api_key = Str::random(20);
        $client->save();

        $api1               = new ClientApi();
        $api1->client_id    = $client->id;
        $api1->url          = 'https://api-' . strtolower($sufijo) . '.comerciocity.com';
        $api1->spa_url      = 'https://' . strtolower($sufijo) . '.comerciocity.com';
        $api1->path         = strtolower($sufijo) . '/api';
        $api1->hosting_type = 'shared_hosting';
        $api1->save();

        $api2               = new ClientApi();
        $api2->client_id    = $client->id;
        $api2->url          = 'https://api-' . strtolower($sufijo) . '2.comerciocity.com';
        $api2->spa_url      = 'https://' . strtolower($sufijo) . '2.comerciocity.com';
        $api2->path         = strtolower($sufijo) . '2/api';
        $api2->hosting_type = 'shared_hosting';
        $api2->save();

        $client->active_client_api_id = $api1->id;
        $client->save();

        return ['client' => $client->fresh(), 'api1' => $api1, 'api2' => $api2];
    }

    /**
     * Versión publicada: los archivos de public/ del esqueleto salen del tag, así que sin versión
     * no hay esqueleto posible.
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
     * Credencial de hosting compartido: el constructor de InstallationService la exige con
     * firstOrFail(), y admin_testing_s4 no tiene ninguna cargada.
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
     * Admin para autenticar las requests.
     *
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'esqueleto-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }
}

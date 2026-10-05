<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarInstalacionDeImplementacionJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\EnvTemplate;
use App\Models\Implementation;
use Illuminate\Support\Facades\Queue;

/**
 * La instalación del sistema por `claude/*`: `POST claude/implementations/{id}/install`.
 *
 * Es la ruta que, desde la skill, crea los cuatro subdominios, la base y el cron del cliente en
 * Hostinger, sube el SPA y la API por SFTP y escribe su `.env`. Lo que se protege, en orden de
 * importancia:
 *
 *  1. 🔴 `dry_run` por defecto: no crea ni encola NADA y muestra los ocho chequeos.
 *  2. 🔴 Que sin el token de Hostinger no se instale (con el mensaje que manda a `/instalar-cliente`), y
 *     que se use SIEMPRE la última versión publicada, no la que quedó fijada al promover.
 *  3. 🔴 Que una instalación EN CURSO sea 409 y una COMPLETADA 422: no se pisa un pipeline vivo ni el
 *     `.env` de un negocio que ya anda.
 *  4. 🔴 Que el job vaya a la conexión `database` y con un `$timeout` POR DEBAJO del `retry_after` de la
 *     cola (el job de grupo del panel declara 5700 y ahí terminaría en `failed_jobs` sin haber fallado).
 *  5. Que se reutilice la fila pendiente que dejó el panel (con la versión corregida) y que una fallida
 *     no se toque: se crea un par nuevo.
 *  6. Los frenos de nombre y de lista blanca de siempre.
 */
class InstalacionDeImplementacionPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * Arranca con las plantillas de variables del `.env` como las siembra `EnvTemplateSeeder` (las seis
     * manuales) y sin credenciales SSH ni token, para que cada test arme lo que necesita.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hostinger.api_token' => '']);

        EnvTemplate::query()->delete();
        $manuales = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST'       => '127.0.0.1',
            'DB_PORT'       => '3306',
            'DB_DATABASE'   => null,
            'DB_USERNAME'   => null,
            'DB_PASSWORD'   => null,
        ];
        foreach ($manuales as $clave => $valor) {
            EnvTemplate::create(['key' => $clave, 'value' => $valor, 'group' => 'db', 'scope' => 'empresa', 'is_manual_on_create' => true]);
        }
        EnvTemplate::create(['key' => 'APP_ENV', 'value' => 'production', 'group' => 'app', 'scope' => 'empresa', 'is_manual_on_create' => false]);

        ClientSshCredential::query()->delete();
    }

    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Una implementación LISTA PARA INSTALAR: etapa 2, formulario enviado, las dos APIs estándar, una
     * versión publicada, credencial SSH del compartido y el token de Hostinger.
     *
     * @param array<string, mixed> $opciones `hosting`: hosting_type de las APIs; `etapa`: etapa actual;
     *                                       `formulario`: false = sin enviar; `token`, `credencial`: false =
     *                                       no cargarlos.
     *
     * @return array{cliente: Client, implementacion: Implementation, version: \App\Models\Version}
     */
    private function escenario(array $opciones = []): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente = $this->crear_las_dos_apis($cliente, 'panchito', isset($opciones['hosting']) ? $opciones['hosting'] : 'shared_hosting');

        $implementacion = $this->crear_implementacion($cliente);
        $implementacion = $this->llevar_a_la_etapa($implementacion, isset($opciones['etapa']) ? $opciones['etapa'] : 2);

        if (! isset($opciones['formulario']) || $opciones['formulario'] !== false) {
            $implementacion->form_submitted_at = now();
            $implementacion->save();
        }

        $version = $this->crear_version();

        if (! isset($opciones['credencial']) || $opciones['credencial'] !== false) {
            $this->cargar_la_credencial_del_compartido();
        }

        if (! isset($opciones['token']) || $opciones['token'] !== false) {
            config(['services.hostinger.api_token' => 'token-de-prueba']);
        }

        return ['cliente' => $cliente, 'implementacion' => $implementacion->refresh(), 'version' => $version];
    }

    /**
     * La credencial SSH del hosting compartido.
     *
     * @return void
     */
    private function cargar_la_credencial_del_compartido(): void
    {
        $credencial           = new ClientSshCredential();
        $credencial->type     = 'shared_hosting';
        $credencial->host     = 'compartido.ejemplo.test';
        $credencial->port     = 65002;
        $credencial->username = 'deploy';
        $credencial->password = 'secreta';
        $credencial->save();
    }

    /**
     * El POST de instalar.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $cuerpo         Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function instalar(Implementation $implementation, array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/install', $cuerpo, $this->headers());
    }

    /**
     * Cuántas instalaciones tiene el cliente.
     *
     * @param Client $cliente El cliente.
     *
     * @return int
     */
    private function instalaciones_de(Client $cliente): int
    {
        return ClientInstallation::where('client_id', $cliente->id)->count();
    }

    /**
     * El chequeo con ese nombre de la respuesta.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta La respuesta (dry-run o 422).
     * @param string                           $nombre    El chequeo.
     *
     * @return array<string, mixed>
     */
    private function chequeo($respuesta, string $nombre): array
    {
        foreach ($respuesta->json('chequeos') as $chequeo) {
            if ($chequeo['chequeo'] === $nombre) {
                return $chequeo;
            }
        }

        $this->fail('La respuesta no trae el chequeo ' . $nombre);
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos del pedido
     |----------------------------------------------------------------------------------------- */

    /**
     * Sin la clave, 401.
     *
     * @return void
     */
    public function test_sin_clave_devuelve_401(): void
    {
        $this->postJson('/api/claude/implementations/1/install', [])->assertStatus(401);
    }

    /**
     * Un parámetro desconocido es 422 y no instala nada: una `version`, un `provision_hosting_type` o un
     * `force` suelen ser alguien esperando elegir lo que está fijado a propósito.
     *
     * @return void
     */
    public function test_un_parametro_desconocido_es_422_y_no_instala_nada(): void
    {
        Queue::fake();
        $e = $this->escenario();

        foreach (['version' => '9.9.9', 'provision_hosting_type' => 'vps', 'force' => true, 'kind' => 'completa'] as $campo => $valor) {
            $respuesta = $this->instalar($e['implementacion'], [$campo => $valor, 'dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

            $respuesta->assertStatus(422);
            $this->assertStringContainsString($campo, $this->cuerpo($respuesta));
        }

        $this->assertSame(0, $this->instalaciones_de($e['cliente']));
        Queue::assertNothingPushed();
    }

    /**
     * Una implementación inexistente es 404.
     *
     * @return void
     */
    public function test_una_implementacion_inexistente_es_404(): void
    {
        $this->instalar(new Implementation(['id' => 99999999]), [])->assertStatus(404);
    }

    /**
     * `dry_run` tiene que ser booleano y, con false, falta el nombre: 422 en español.
     *
     * @return void
     */
    public function test_los_tipos_de_los_parametros(): void
    {
        $e = $this->escenario();

        $this->instalar($e['implementacion'], ['dry_run' => 'quizás'])->assertStatus(422);

        $sin = $this->instalar($e['implementacion'], ['dry_run' => false]);
        $sin->assertStatus(422);
        $this->assertStringContainsString('es obligatorio cuando', $this->cuerpo($sin));
    }

    /* ------------------------------------------------------------------------------------------
     | Dry-run
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. 🔴 Sin `dry_run` explícito no se crea ni se encola NADA, y con todo en orden los ocho chequeos
     * salen en true.
     *
     * @return void
     */
    public function test_por_defecto_es_dry_run_y_no_crea_ni_encola_nada(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $respuesta = $this->instalar($e['implementacion'], []);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('listo', true);
        $this->assertCount(8, $respuesta->json('chequeos'));

        foreach ($respuesta->json('chequeos') as $chequeo) {
            $this->assertTrue($chequeo['ok'], 'El chequeo ' . $chequeo['chequeo'] . ' salió en false: ' . $chequeo['detalle']);
            $this->assertNotSame('', trim($chequeo['detalle']));
        }

        $this->assertSame(0, $this->instalaciones_de($e['cliente']));
        Queue::assertNothingPushed();
    }

    /**
     * El dry-run dice qué crearía: la real en la API activa, el esqueleto en la otra, el hosting
     * compartido y la última versión publicada.
     *
     * @return void
     */
    public function test_el_dry_run_dice_lo_que_crearia(): void
    {
        $e       = $this->escenario();
        $vieja   = $e['version'];
        $ultima  = $this->crear_version();
        $apis    = ClientApi::where('client_id', $e['cliente']->id)->orderBy('id')->get();

        $respuesta = $this->instalar($e['implementacion'], []);

        $respuesta->assertJsonPath('se_crearia.modo', 'crea_el_par');
        $respuesta->assertJsonPath('se_crearia.provision_hosting_type', 'shared_hosting');
        $respuesta->assertJsonPath('se_crearia.version.id', (int) $ultima->id);
        $this->assertNotSame((int) $vieja->id, (int) $respuesta->json('se_crearia.version.id'));
        $respuesta->assertJsonPath('se_crearia.instalaciones.0.kind', 'completa');
        $respuesta->assertJsonPath('se_crearia.instalaciones.0.client_api_id', (int) $apis[0]->id);
        $respuesta->assertJsonPath('se_crearia.instalaciones.0.url', 'https://api-panchito.comerciocity.com');
        $respuesta->assertJsonPath('se_crearia.instalaciones.1.kind', 'esqueleto');
        $respuesta->assertJsonPath('se_crearia.instalaciones.1.client_api_id', (int) $apis[1]->id);
        $respuesta->assertJsonPath('se_crearia.instalaciones.1.url', 'https://api-panchito2.comerciocity.com');
    }

    /**
     * Las variables del `.env`: las tres de la base las genera el aprovisionamiento (exentas) y las tres
     * de conexión se completan con el valor de la plantilla; el dry-run lo muestra.
     *
     * @return void
     */
    public function test_el_dry_run_muestra_las_variables_del_env(): void
    {
        $e = $this->escenario();

        $respuesta = $this->instalar($e['implementacion'], []);

        $respuesta->assertJsonPath('variables_env.exentas_por_el_aprovisionamiento', ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);
        $respuesta->assertJsonPath('variables_env.se_completan_con', ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306']);
        $respuesta->assertJsonPath('variables_env.faltan', []);
    }

    /**
     * Si la plantilla no trae el valor de una variable de conexión, se completa con el estándar del
     * hosting compartido.
     *
     * @return void
     */
    public function test_sin_valor_en_la_plantilla_se_usa_el_estandar_del_compartido(): void
    {
        EnvTemplate::where('key', 'DB_HOST')->update(['value' => null]);
        EnvTemplate::where('key', 'DB_PORT')->update(['value' => '']);
        $e = $this->escenario();

        $this->instalar($e['implementacion'], [])
            ->assertJsonPath('variables_env.se_completan_con.DB_HOST', '127.0.0.1')
            ->assertJsonPath('variables_env.se_completan_con.DB_PORT', '3306');
    }

    /**
     * Cada chequeo que falla sale en false con su detalle, `listo` en false, y no se escribe nada.
     *
     * @return void
     */
    public function test_cada_chequeo_que_falla_sale_en_false(): void
    {
        $casos = [
            ['chequeo' => 'etapa_y_formulario', 'escenario' => ['etapa' => 1], 'texto' => 'tiene que estar en la 2'],
            ['chequeo' => 'etapa_y_formulario', 'escenario' => ['formulario' => false], 'texto' => 'no envió el formulario'],
            ['chequeo' => 'credencial_ssh_shared', 'escenario' => ['credencial' => false], 'texto' => 'Falta la credencial SSH'],
            ['chequeo' => 'token_de_hostinger', 'escenario' => ['token' => false], 'texto' => 'sin token de Hostinger en el admin: instalar con /instalar-cliente'],
            ['chequeo' => 'estructura_del_hosting', 'escenario' => ['hosting' => 'vps'], 'texto' => 'marcada como "vps"'],
        ];

        foreach ($casos as $caso) {
            /* Cada caso arranca limpio: la credencial y el token de uno no pueden tapar la falta del otro. */
            ClientSshCredential::query()->delete();
            config(['services.hostinger.api_token' => '']);

            $e = $this->escenario($caso['escenario']);

            $respuesta = $this->instalar($e['implementacion'], []);

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('listo', false);
            $chequeo = $this->chequeo($respuesta, $caso['chequeo']);
            $this->assertFalse($chequeo['ok'], 'El chequeo ' . $caso['chequeo'] . ' tendría que haber fallado.');
            $this->assertStringContainsString($caso['texto'], $chequeo['detalle'], $caso['chequeo']);
            $this->assertSame(0, $this->instalaciones_de($e['cliente']));
        }
    }

    /**
     * El formulario cuenta como enviado con `form_submitted_at` lleno, o con la etapa 1 completada Y
     * datos del formulario ya mapeados (Lucas cargando las respuestas desde el panel). La etapa 1
     * completada a secas NO alcanza: es lo que deja "Avanzar etapa" sin que el cliente haya cargado nada.
     *
     * @return void
     */
    public function test_la_etapa_1_completada_a_secas_no_cuenta_como_formulario_enviado(): void
    {
        $sin_datos = $this->escenario(['formulario' => false]);
        $this->assertFalse($this->chequeo($this->instalar($sin_datos['implementacion'], []), 'etapa_y_formulario')['ok']);

        $con_datos = $this->escenario(['formulario' => false]);
        $con_datos['cliente']->setup_data = ['company_name' => 'Panchito S.A.', 'price_lists' => 'Minorista'];
        $con_datos['cliente']->save();
        $this->assertTrue($this->chequeo($this->instalar($con_datos['implementacion'], []), 'etapa_y_formulario')['ok']);
    }

    /**
     * 2. 🔴 Sin el token de Hostinger el chequeo dice exactamente a dónde ir.
     *
     * @return void
     */
    public function test_sin_token_de_hostinger_manda_a_instalar_cliente(): void
    {
        $e = $this->escenario(['token' => false]);

        $chequeo = $this->chequeo($this->instalar($e['implementacion'], []), 'token_de_hostinger');

        $this->assertFalse($chequeo['ok']);
        $this->assertSame('sin token de Hostinger en el admin: instalar con /instalar-cliente (crea los subdominios y la base desde la máquina de Lucas).', $chequeo['detalle']);
    }

    /**
     * Sin ninguna versión publicada el chequeo falla.
     *
     * @return void
     */
    public function test_sin_version_publicada_el_chequeo_falla(): void
    {
        $e = $this->escenario();
        $e['version']->status = 'draft';
        $e['version']->save();

        $chequeo = $this->chequeo($this->instalar($e['implementacion'], []), 'version_publicada');

        $this->assertFalse($chequeo['ok']);
        $this->assertStringContainsString('No hay ninguna versión publicada', $chequeo['detalle']);
    }

    /**
     * Las URLs en http no sirven: con http el servidor redirige y la redirección convierte el PUT final
     * en GET.
     *
     * @return void
     */
    public function test_las_urls_tienen_que_ser_https(): void
    {
        $e = $this->escenario();
        ClientApi::where('client_id', $e['cliente']->id)->orderBy('id')->first()->update(['spa_url' => 'http://panchito.comerciocity.com']);

        $chequeo = $this->chequeo($this->instalar($e['implementacion'], []), 'urls_https');

        $this->assertFalse($chequeo['ok']);
        $this->assertStringContainsString('http://panchito.comerciocity.com', $chequeo['detalle']);
    }

    /**
     * Las guardas de la estructura: con una sola ClientApi (o con tres) no hay forma de saber cuáles son
     * los cuatro subdominios.
     *
     * @return void
     */
    public function test_la_estructura_del_hosting_exige_exactamente_dos_apis(): void
    {
        $e = $this->escenario();
        ClientApi::where('client_id', $e['cliente']->id)->orderByDesc('id')->first()->delete();

        $chequeo = $this->chequeo($this->instalar($e['implementacion'], []), 'estructura_del_hosting');

        $this->assertFalse($chequeo['ok']);
        $this->assertStringContainsString('necesita exactamente 2', $chequeo['detalle']);
    }

    /**
     * Un par con nombres que no son <slug> / <slug>2 no se aprovisiona solo.
     *
     * @return void
     */
    public function test_un_par_con_nombres_no_estandar_no_pasa(): void
    {
        $e = $this->escenario();
        ClientApi::where('client_id', $e['cliente']->id)->orderByDesc('id')->first()->update(['spa_url' => 'https://otro-nombre.comerciocity.com']);

        $chequeo = $this->chequeo($this->instalar($e['implementacion'], []), 'estructura_del_hosting');

        $this->assertFalse($chequeo['ok']);
        $this->assertStringContainsString('par estándar', $chequeo['detalle']);
    }

    /**
     * Sin una API activa no se sabe en cuál instalar.
     *
     * @return void
     */
    public function test_sin_api_activa_el_chequeo_falla(): void
    {
        $e = $this->escenario();
        $e['cliente']->active_client_api_id = null;
        $e['cliente']->save();

        $chequeo = $this->chequeo($this->instalar($e['implementacion'], []), 'estructura_del_hosting');

        $this->assertFalse($chequeo['ok']);
        $this->assertStringContainsString('active_client_api_id', $chequeo['detalle']);
    }

    /**
     * Cualquier variable manual que no sea de las tres de conexión ni de las tres de la base y no tenga
     * valor frena, igual que el botón "Iniciar" del panel.
     *
     * @return void
     */
    public function test_una_variable_manual_sin_valor_frena(): void
    {
        EnvTemplate::create(['key' => 'CLAVE_PROPIA_DEL_NEGOCIO', 'value' => null, 'group' => 'misc', 'scope' => 'empresa', 'is_manual_on_create' => true]);
        $e = $this->escenario();

        $respuesta = $this->instalar($e['implementacion'], []);

        $respuesta->assertJsonPath('listo', false);
        $respuesta->assertJsonPath('variables_env.faltan', ['CLAVE_PROPIA_DEL_NEGOCIO']);
        $this->assertStringContainsString('CLAVE_PROPIA_DEL_NEGOCIO', $this->chequeo($respuesta, 'variables_manuales')['detalle']);
    }

    /* ------------------------------------------------------------------------------------------
     | Las instalaciones previas
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. 🔴 Una instalación EN CURSO es 409 (también en el real) y no se crea nada: no se pisa un
     * pipeline vivo.
     *
     * @return void
     */
    public function test_una_instalacion_en_curso_es_409(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->crear_instalacion($e['cliente'], ['status' => 'instalando']);

        $this->assertFalse($this->chequeo($this->instalar($e['implementacion'], []), 'instalaciones_previas')['ok']);

        $respuesta = $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(409);
        $this->assertNotEmpty($respuesta->json('ids_instalando'));
        $this->assertSame(1, $this->instalaciones_de($e['cliente']));
        Queue::assertNothingPushed();
    }

    /**
     * 3. 🔴 Una instalación COMPLETADA es 422: reinstalar le pisaría el `.env` a un negocio que ya anda.
     *
     * @return void
     */
    public function test_una_instalacion_completada_es_422(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->crear_instalacion($e['cliente'], ['status' => 'completada']);

        $respuesta = $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('instalaciones_previas', $this->cuerpo($respuesta));
        $this->assertStringContainsString('ya está instalado', $this->chequeo($respuesta, 'instalaciones_previas')['detalle']);
        $this->assertSame(1, $this->instalaciones_de($e['cliente']));
        Queue::assertNothingPushed();
    }

    /**
     * Una instalación pendiente sin arrancar (la que deja el panel) se reutiliza: el dry-run lo dice.
     *
     * @return void
     */
    public function test_una_pendiente_se_reutiliza_y_el_dry_run_lo_dice(): void
    {
        $e = $this->escenario();
        $pendiente = $this->crear_instalacion($e['cliente'], ['status' => 'pendiente']);

        $respuesta = $this->instalar($e['implementacion'], []);

        $respuesta->assertJsonPath('se_crearia.modo', 'reutiliza_las_pendientes_y_completa_el_par');
        $respuesta->assertJsonPath('se_crearia.instalaciones_pendientes_que_se_reutilizan', [(int) $pendiente->id]);
        $this->assertTrue($this->chequeo($respuesta, 'instalaciones_previas')['ok']);
    }

    /* ------------------------------------------------------------------------------------------
     | El alta real
     |----------------------------------------------------------------------------------------- */

    /**
     * 6. Un `confirm_client_name` equivocado es 422, no crea ni encola nada y no revela el nombre.
     *
     * @return void
     */
    public function test_el_nombre_equivocado_no_crea_ni_encola_nada_ni_revela_el_nombre(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $respuesta = $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Otro Negocio']);

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Panchito Gómez', $this->cuerpo($respuesta));
        $this->assertSame(0, $this->instalaciones_de($e['cliente']));
        Queue::assertNothingPushed();
    }

    /**
     * Con un chequeo en false el real es 422 con la lista completa, y no crea ni encola nada.
     *
     * @return void
     */
    public function test_con_un_chequeo_en_false_el_real_es_422(): void
    {
        Queue::fake();
        $e = $this->escenario(['token' => false]);

        $respuesta = $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('token_de_hostinger', $this->cuerpo($respuesta));
        $this->assertCount(8, $respuesta->json('chequeos'));
        $this->assertSame(0, $this->instalaciones_de($e['cliente']));
        Queue::assertNothingPushed();
    }

    /**
     * 2, 4. 🔴 El camino feliz: 202, el par creado (la real en la API activa y el esqueleto en la otra,
     * en el mismo grupo, en `instalando`, con el aprovisionamiento del compartido y la ÚLTIMA versión
     * publicada), las variables del `.env` completadas y el job encolado en la conexión `database` con
     * la real primero.
     *
     * @return void
     */
    public function test_el_camino_feliz_deja_el_par_y_encola_en_database(): void
    {
        Queue::fake();
        $e      = $this->escenario();
        $ultima = $this->crear_version();
        $apis   = ClientApi::where('client_id', $e['cliente']->id)->orderBy('id')->get();

        $respuesta = $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('dry_run', false);
        $respuesta->assertJsonPath('implementation_id', (int) $e['implementacion']->id);
        $respuesta->assertJsonPath('version.id', (int) $ultima->id);
        $respuesta->assertJsonPath('conexion_de_cola', 'database');
        $respuesta->assertJsonPath('latencia_maxima_segundos', 60);
        $this->assertCount(2, $respuesta->json('instalaciones'));

        $filas = ClientInstallation::where('client_id', $e['cliente']->id)->orderBy('id')->get();
        $this->assertCount(2, $filas);

        $real      = $filas->firstWhere('kind', 'completa');
        $esqueleto = $filas->firstWhere('kind', 'esqueleto');
        $this->assertNotNull($real);
        $this->assertNotNull($esqueleto);

        $this->assertSame((int) $apis[0]->id, (int) $real->client_api_id, 'La real va en la API activa.');
        $this->assertSame((int) $apis[1]->id, (int) $esqueleto->client_api_id, 'El esqueleto va en la otra.');
        $this->assertNotNull($real->group_uuid);
        $this->assertSame($real->group_uuid, $esqueleto->group_uuid);
        $this->assertSame($real->group_uuid, $respuesta->json('group_uuid'));

        foreach ([$real, $esqueleto] as $fila) {
            $this->assertSame('instalando', $fila->status);
            $this->assertSame('shared_hosting', $fila->provision_hosting_type);
            $this->assertSame((int) $ultima->id, (int) $fila->version_id, 'Tiene que ser la ÚLTIMA versión publicada.');
            /* assertEquals y no assertSame: la columna es JSON y MySQL reordena las claves (por largo). */
            $this->assertEquals(['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306'], $fila->env_manual_values);
        }

        /* El job: en la conexión database, con la real primero. */
        Queue::assertPushed(EjecutarInstalacionDeImplementacionJob::class, 1);
        Queue::assertPushed(EjecutarInstalacionDeImplementacionJob::class, function ($job) use ($real, $esqueleto) {
            $uuids = (new \ReflectionProperty($job, 'installation_uuids'));
            $uuids->setAccessible(true);

            return $job->connection === 'database' && $uuids->getValue($job) === [$real->uuid, $esqueleto->uuid];
        });
    }

    /**
     * 2. Aunque el cliente tenga fijada una versión vieja (la de la promoción), se instala la última
     * publicada.
     *
     * @return void
     */
    public function test_la_version_fijada_al_promover_no_cuenta(): void
    {
        Queue::fake();
        $e                              = $this->escenario();
        $e['cliente']->current_version_id = $e['version']->id;
        $e['cliente']->save();
        $ultima = $this->crear_version();

        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);

        $this->assertSame([(int) $ultima->id], ClientInstallation::where('client_id', $e['cliente']->id)->pluck('version_id')->map(function ($v) {
            return (int) $v;
        })->unique()->values()->all());
    }

    /**
     * 5. La fila pendiente que dejó el panel al avanzar a la etapa 2 (con una versión vieja y sin
     * aprovisionamiento) se REUTILIZA: se corrige y se le crea la hermana.
     *
     * @return void
     */
    public function test_la_pendiente_del_panel_se_reutiliza_y_se_corrige(): void
    {
        Queue::fake();
        $e        = $this->escenario();
        $apis     = ClientApi::where('client_id', $e['cliente']->id)->orderBy('id')->get();
        $pendiente = $this->crear_instalacion($e['cliente'], [
            'client_api_id' => $apis[0]->id,
            'version_id'    => $e['version']->id,
            'status'        => 'pendiente',
        ]);
        $ultima = $this->crear_version();

        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);

        $this->assertSame(2, $this->instalaciones_de($e['cliente']), 'Tiene que reutilizar la pendiente y crear solo la hermana.');

        $pendiente->refresh();
        $this->assertSame('instalando', $pendiente->status);
        $this->assertSame((int) $ultima->id, (int) $pendiente->version_id, 'La versión de la fila reutilizada se corrige a la última.');
        $this->assertSame('shared_hosting', $pendiente->provision_hosting_type);
        $this->assertNotNull($pendiente->group_uuid);

        $hermana = ClientInstallation::where('client_id', $e['cliente']->id)->where('kind', 'esqueleto')->first();
        $this->assertNotNull($hermana);
        $this->assertSame($pendiente->group_uuid, $hermana->group_uuid);
        $this->assertSame((int) $apis[1]->id, (int) $hermana->client_api_id);
    }

    /**
     * 5. Las dos pendientes de un par ya armado (con su grupo) se reutilizan y conservan el grupo.
     *
     * @return void
     */
    public function test_el_par_pendiente_conserva_su_grupo(): void
    {
        Queue::fake();
        $e     = $this->escenario();
        $apis  = ClientApi::where('client_id', $e['cliente']->id)->orderBy('id')->get();
        $grupo = '22222222-2222-2222-2222-222222222222';

        $real      = $this->crear_instalacion($e['cliente'], ['client_api_id' => $apis[0]->id, 'kind' => 'completa', 'group_uuid' => $grupo, 'status' => 'pendiente']);
        $esqueleto = $this->crear_instalacion($e['cliente'], ['client_api_id' => $apis[1]->id, 'kind' => 'esqueleto', 'group_uuid' => $grupo, 'status' => 'pendiente']);

        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202)->assertJsonPath('group_uuid', $grupo);

        $this->assertSame(2, $this->instalaciones_de($e['cliente']));
        $this->assertSame('instalando', $real->refresh()->status);
        $this->assertSame('instalando', $esqueleto->refresh()->status);
    }

    /**
     * 5. Una instalación FALLIDA no se toca: queda de historial y se crea un par nuevo.
     *
     * @return void
     */
    public function test_una_fallida_queda_de_historial_y_se_crea_un_par_nuevo(): void
    {
        Queue::fake();
        $e       = $this->escenario();
        $fallida = $this->crear_instalacion($e['cliente'], ['status' => 'fallida', 'failure_reason' => 'se cortó el SSH']);

        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);

        $this->assertSame(3, $this->instalaciones_de($e['cliente']));

        $fallida->refresh();
        $this->assertSame('fallida', $fallida->status);
        $this->assertSame('se cortó el SSH', $fallida->failure_reason);
        $this->assertNull($fallida->group_uuid);
    }

    /**
     * 3. Un segundo POST real, ya con las filas en `instalando`, es 409 y no encola un segundo job.
     *
     * @return void
     */
    public function test_el_segundo_post_real_es_409_y_no_encola_otro_job(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);
        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(409);

        $this->assertSame(2, $this->instalaciones_de($e['cliente']));
        Queue::assertPushed(EjecutarInstalacionDeImplementacionJob::class, 1);
    }

    /**
     * La confirmación acepta el nombre con otras mayúsculas y espacios.
     *
     * @return void
     */
    public function test_la_confirmacion_ignora_mayusculas_y_espacios(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => '  PANCHITO gómez '])->assertStatus(202);
    }

    /**
     * La instalación creada se lee por el estado: `instalaciones[]` con su versión y en `instalando`.
     *
     * @return void
     */
    public function test_lo_que_crea_se_lee_por_el_estado(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);

        $estado = $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers());

        $this->assertCount(2, $estado->json('instalaciones'));
        $this->assertSame('instalando', $estado->json('instalaciones.0.status'));
        $this->assertSame('shared_hosting', $estado->json('instalaciones.0.provision_hosting_type'));
        $this->assertNotNull($estado->json('instalaciones.0.version.version'));
    }

    /**
     * 4. 🔴 El job tiene un `$timeout` POR DEBAJO del `retry_after` de la conexión `database`: ese es el
     * invariante que el job de grupo del panel (5700) no cumple y por el que existe este job.
     *
     * @return void
     */
    public function test_el_timeout_del_job_queda_por_debajo_del_retry_after(): void
    {
        $retry_after = (int) config('queue.connections.database.retry_after');
        $timeout     = (new \ReflectionClass(EjecutarInstalacionDeImplementacionJob::class))->getDefaultProperties()['timeout'];

        $this->assertLessThan($retry_after, $timeout, 'El job tiene que cortarse antes de que la cola lo dé por perdido.');
        $this->assertGreaterThan(1800, $timeout, 'Una instalación real tarda ~15 minutos y la peor medida ~28: el techo tiene que cubrirlas.');
        $this->assertSame(1, (new \ReflectionClass(EjecutarInstalacionDeImplementacionJob::class))->getDefaultProperties()['tries']);
    }

    /* ------------------------------------------------------------------------------------------
     | El job
     |----------------------------------------------------------------------------------------- */

    /**
     * El job delega TODO en el job de grupo del panel: sin credencial SSH, el pipeline ni arranca y
     * la fila queda `fallida` con el motivo real (la misma que escribe el job de grupo).
     *
     * @return void
     */
    public function test_el_job_delega_en_el_de_grupo_y_deja_el_motivo_en_la_fila(): void
    {
        $e          = $this->escenario(['credencial' => false]);
        $instalacion = $this->crear_instalacion($e['cliente'], ['status' => 'instalando', 'provision_hosting_type' => 'shared_hosting']);

        (new EjecutarInstalacionDeImplementacionJob([$instalacion->uuid]))->handle();

        $instalacion->refresh();
        $this->assertSame('fallida', $instalacion->status);
        $this->assertStringContainsString('No hay ninguna credencial SSH de tipo "shared_hosting"', (string) $instalacion->failure_reason);
        $this->assertNotNull($instalacion->finished_at);
    }

    /**
     * 4. `failed()` deja `fallida` lo que SIGUE en `instalando` (el worker lo cortó) y no toca lo que ya
     * terminó.
     *
     * @return void
     */
    public function test_failed_deja_fallida_solo_la_que_sigue_instalando(): void
    {
        $e          = $this->escenario();
        $en_curso   = $this->crear_instalacion($e['cliente'], ['status' => 'instalando']);
        $terminada  = $this->crear_instalacion($e['cliente'], ['status' => 'completada']);
        $ajena      = $this->crear_instalacion($this->crear_cliente('Otro Cliente'), ['status' => 'instalando']);

        (new EjecutarInstalacionDeImplementacionJob([$en_curso->uuid, $terminada->uuid]))->failed(new \RuntimeException('timeout de 2300 s'));

        $en_curso->refresh();
        $this->assertSame('fallida', $en_curso->status);
        $this->assertStringContainsString('timeout de 2300 s', (string) $en_curso->failure_reason);
        $this->assertStringContainsString('revisá los logs', (string) $en_curso->failure_reason);
        $this->assertNotNull($en_curso->finished_at);

        $this->assertSame('completada', $terminada->refresh()->status, 'No se pisa una que ya terminó.');
        $this->assertSame('instalando', $ajena->refresh()->status, 'No se toca una instalación que no es del par.');
    }

    /**
     * El job acepta filas además de uuids (como el job de grupo) y guarda solo los uuids.
     *
     * @return void
     */
    public function test_el_job_acepta_filas_y_uuids(): void
    {
        $e    = $this->escenario();
        $fila = $this->crear_instalacion($e['cliente']);

        $job   = new EjecutarInstalacionDeImplementacionJob([$fila, 'abc']);
        $uuids = new \ReflectionProperty($job, 'installation_uuids');
        $uuids->setAccessible(true);

        $this->assertSame([$fila->uuid, 'abc'], $uuids->getValue($job));
    }
}

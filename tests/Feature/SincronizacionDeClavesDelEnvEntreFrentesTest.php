<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ClaudeClientOpsController;
use App\Jobs\RunDeploymentJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\Version;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión `optimizacion-vps-fase1` (10/9/2026) — el deploy completa el `.env` del frente DESTINO
 * con las claves que el frente ACTIVO tiene y a él le faltan (etapa `sync_env_keys`).
 *
 * El caso que lo motivó: en el VPS, `api-ferretotal2` (el frente activo) no tenía `OPENAI_API_KEY`
 * mientras `api-ferretotal` sí. El upgrade nunca escribe el `.env` del destino —el zip lo excluye y
 * ninguna etapa lo toca—, así que una clave agregada a mano en un frente no llega nunca al otro. El
 * resultado fueron 35.324 jobs de embeddings fallidos sin un solo error visible para el cliente.
 *
 * Lo que se protege, en orden:
 *
 *  1. Que la etapa exista, esté ENTRE `upload_api` y `run_migrations` (las migraciones bootean con
 *     el `.env` del destino y tienen que encontrarlo completo) y tenga su `case` en el switch: un
 *     paso listado sin `case` no corre nunca y no falla nada.
 *  2. 🔴 La regla de qué se copia y qué no (`claves_a_sincronizar()`): sólo lo que falta en el
 *     destino, nunca lo que ya tiene (aunque el valor difiera), nunca las claves propias de cada
 *     frente (URL, DOMAIN, PREFIX, COOKIE, STATEFUL, APP_NAME) y nunca un valor vacío.
 *  3. Que `deploy/start` acepte `resume_from_step=sync_env_keys`.
 *  4. 🔴 Que el log nombre las CLAVES y nunca los VALORES: deployment_logs se lee desde el panel y
 *     por `claude/*`, y ahí no puede aparecer una API key.
 *  5. 🔴 Que la etapa NUNCA aborte el deploy: sin API activa, con la activa igual al destino, con
 *     hostings distintos o con el SSH caído, deja su línea y sigue.
 *
 * ⚠️ `ClientSshCredentialSeeder` deja en la base de testing las credenciales REALES del shared
 * hosting y del VPS. setUp() las pisa (dentro de la transacción, así vuelven solas) con un puerto
 * cerrado de localhost: ningún test de este archivo puede terminar abriendo una sesión contra
 * producción, ni siquiera si alguien saca una guarda del paso.
 */
class SincronizacionDeClavesDelEnvEntreFrentesTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta para la request a `deploy/start`. */
    const CLAVE_DE_INGESTA = 'clave-de-prueba-sync-env';

    /** Nombre del cliente del escenario: es lo que confirma `confirm_client_name`. */
    const NOMBRE = 'Ferretería Rioplatense';

    /** Password de la credencial SSH falsa: distintiva, para poder afirmar que nunca llega a un log. */
    const PASSWORD_SSH_FALSA = 'clave-ssh-falsa-0x9f';

    /** @var int Para que cada escenario tenga versiones con código distinto. */
    private $contador = 0;

    /**
     * Setea la clave de ingesta (el middleware es fail-closed) y pisa las credenciales SSH reales.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE_DE_INGESTA]);

        /* Ver el docblock de la clase: nunca contra producción. */
        $this->pisar_credencial('shared_hosting');
        $this->pisar_credencial('vps');
    }

    /* ------------------------------------------------------------------------------------------
     | Armado del escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Deja la credencial de ese tipo apuntando a un puerto cerrado de localhost. phpseclib lo
     * rechaza en el acto con UnableToConnectException, sin timeout y sin salir de la máquina.
     *
     * @param  string  $type  'shared_hosting' | 'vps'
     * @return void
     */
    private function pisar_credencial(string $type): void
    {
        $credencial = ClientSshCredential::where('type', $type)->first();
        if ($credencial === null) {
            $credencial       = new ClientSshCredential();
            $credencial->type = $type;
        }

        $credencial->host     = '127.0.0.1';
        $credencial->port     = 1;
        $credencial->username = 'nadie';
        $credencial->password = self::PASSWORD_SSH_FALSA;
        $credencial->save();
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE_DE_INGESTA,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Versión publicada del catálogo.
     *
     * @param  string  $codigo
     * @return Version
     */
    private function crear_version(string $codigo): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Versión ' . $codigo;
        $version->status       = 'published';
        $version->is_hotfix    = false;
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * API de un cliente en el hosting pedido.
     *
     * @param  Client  $client
     * @param  string  $hosting_type  'shared_hosting' | 'vps'
     * @return ClientApi
     */
    private function crear_api(Client $client, string $hosting_type): ClientApi
    {
        $sufijo = Str::random(6);

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $sufijo . '.ejemplo.test';
        $api->spa_url      = 'https://' . $sufijo . '.ejemplo.test';
        $api->path         = 'ejemplo/' . $sufijo;
        $api->hosting_type = $hosting_type;
        $api->vps_path     = $hosting_type === 'vps' ? 'ejemplo-' . $sufijo : null;
        $api->save();

        return $api;
    }

    /**
     * Escenario: cliente con una API activa y una API destino distinta, y el upgrade hacia la
     * destino. Con `$con_activa = false` el cliente queda sin API activa (instalación nueva).
     *
     * @param  string  $hosting_activa   Hosting de la API activa.
     * @param  string  $hosting_destino  Hosting de la API destino.
     * @param  bool    $con_activa       Si el cliente tiene API activa cargada.
     * @param  array<string, mixed>  $atributos_del_upgrade  Atributos a pisar en el upgrade.
     * @return array<string, mixed>
     */
    private function escenario(
        string $hosting_activa,
        string $hosting_destino,
        bool $con_activa = true,
        array $atributos_del_upgrade = []
    ): array {
        $this->contador += 2;

        $from = $this->crear_version('9.' . $this->contador . '.0');
        $to   = $this->crear_version('9.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = self::NOMBRE;
        $client->company_name       = 'Empresa ' . self::NOMBRE;
        $client->slug               = 'ferreteria-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $api_activa  = $this->crear_api($client, $hosting_activa);
        $api_destino = $this->crear_api($client, $hosting_destino);

        if ($con_activa) {
            $client->active_client_api_id = $api_activa->id;
            $client->save();
        }

        $upgrade = ClientVersionUpgrade::create(array_merge([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'scheduled_date'       => now()->toDateString(),
            'target_client_api_id' => $api_destino->id,
        ], $atributos_del_upgrade));

        return compact('client', 'api_activa', 'api_destino', 'upgrade');
    }

    /**
     * Invoca el paso real, sin mocks. Si intentara algo que no debe, reventaría o dejaría la
     * línea equivocada: las aserciones miran deployment_logs.
     *
     * @param  DeploymentService  $service
     * @return void
     */
    private function invocar_paso(DeploymentService $service): void
    {
        $metodo = new \ReflectionMethod($service, 'step_sync_env_keys');
        $metodo->setAccessible(true);
        $metodo->invoke($service);
    }

    /**
     * Líneas que el paso dejó en deployment_logs para ese upgrade, en orden.
     *
     * @param  ClientVersionUpgrade  $upgrade
     * @return \Illuminate\Support\Collection
     */
    private function lineas_del_paso(ClientVersionUpgrade $upgrade)
    {
        return DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->where('step', 'sync_env_keys')
            ->orderBy('id')
            ->get();
    }

    /**
     * Etapa de reanudación con la que se despachó un RunDeploymentJob (propiedad privada).
     *
     * @param  RunDeploymentJob  $job
     * @return string|null
     */
    private function etapa_del_job(RunDeploymentJob $job)
    {
        $propiedad = new \ReflectionProperty(RunDeploymentJob::class, 'resume_from_step');
        $propiedad->setAccessible(true);

        return $propiedad->getValue($job);
    }

    /* ------------------------------------------------------------------------------------------
     | Análisis de fuente
     |----------------------------------------------------------------------------------------- */

    /**
     * El código del servicio SIN comentarios: los candados miran lo que se ejecuta, no la prosa
     * que explica por qué (que menciona valores, throws y demás justamente para decir que no van).
     *
     * @return string
     */
    private function fuente_ejecutable(): string
    {
        $fuente     = (string) file_get_contents(app_path('Services/DeploymentService.php'));
        $ejecutable = '';

        foreach (token_get_all($fuente) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    /* Un salto de línea, para no pegar dos tokens que sólo separaba el comentario. */
                    $ejecutable .= "\n";
                    continue;
                }

                $ejecutable .= $token[1];
                continue;
            }

            $ejecutable .= $token;
        }

        return $ejecutable;
    }

    /**
     * Sólo el código ejecutable de step_sync_env_keys(): desde su declaración hasta la del método
     * que lo sigue en el archivo, sea cual sea su visibilidad.
     *
     * @return string
     */
    private function bloque_del_paso(): string
    {
        $fuente = $this->fuente_ejecutable();

        $desde = strpos($fuente, 'private function step_sync_env_keys');
        $this->assertNotFalse($desde, 'No existe el metodo step_sync_env_keys().');

        $bloque = substr($fuente, (int) $desde);

        $coincidencias = [];
        if (preg_match('/\n\s*(public|private|protected)(\s+static)?\s+function\s+/', $bloque, $coincidencias, PREG_OFFSET_CAPTURE, 10) === 1) {
            $bloque = substr($bloque, 0, (int) $coincidencias[0][1]);
        }

        return $bloque;
    }

    /**
     * Cada llamada a `$this->log(...)` del bloque, entera (paréntesis balanceados).
     *
     * @param  string  $bloque
     * @return array<int, string>
     */
    private function llamadas_al_log(string $bloque): array
    {
        $llamadas = [];
        $offset   = 0;
        $largo    = strlen($bloque);

        while (($inicio = strpos($bloque, '$this->log(', $offset)) !== false) {
            $abre  = $inicio + strlen('$this->log(') - 1;
            $nivel = 0;

            for ($i = $abre; $i < $largo; $i++) {
                if ($bloque[$i] === '(') {
                    $nivel++;
                } elseif ($bloque[$i] === ')') {
                    $nivel--;
                    if ($nivel === 0) {
                        break;
                    }
                }
            }

            $llamadas[] = substr($bloque, $inicio, $i - $inicio + 1);
            $offset     = $i + 1;
        }

        return $llamadas;
    }

    /* ==========================================================================================
     | 1) La etapa existe, en su lugar, y está enganchada
     |========================================================================================= */

    /**
     * Inmediatamente después de upload_api (recién ahí existe el código del destino y todavía no
     * corrió nada que lea su `.env`) e inmediatamente antes de run_migrations (`migrate` bootea
     * Laravel con ese `.env` y tiene que encontrarlo completo).
     */
    public function test_el_paso_va_entre_upload_api_y_run_migrations(): void
    {
        $e       = $this->escenario('shared_hosting', 'shared_hosting');
        $service = new DeploymentService($e['upgrade']);

        $propiedad = new \ReflectionProperty($service, 'steps');
        $propiedad->setAccessible(true);
        $steps = $propiedad->getValue($service);

        $this->assertContains('sync_env_keys', $steps, 'El paso no está en el pipeline.');

        $upload      = array_search('upload_api', $steps, true);
        $sync        = array_search('sync_env_keys', $steps, true);
        $migraciones = array_search('run_migrations', $steps, true);

        $this->assertSame(
            $upload + 1,
            $sync,
            'sync_env_keys va inmediatamente después de upload_api.'
        );
        $this->assertSame(
            $sync + 1,
            $migraciones,
            'run_migrations va inmediatamente después de sync_env_keys: migrate bootea con el .env del destino.'
        );
    }

    /**
     * 🔴 Candado: un paso listado en $steps sin su `case` en el switch no se ejecuta nunca y no
     * falla nada (es lo que le pasa a step_update_crons() desde que se escribió).
     */
    public function test_el_paso_esta_enganchado_en_el_switch_y_no_queda_muerto(): void
    {
        $fuente = $this->fuente_ejecutable();

        $this->assertStringContainsString(
            "case 'sync_env_keys':",
            $fuente,
            'El paso esta en $steps pero no tiene case en el switch: no se ejecutaria nunca.'
        );

        $this->assertStringContainsString(
            '$this->step_sync_env_keys();',
            $fuente,
            'El case existe pero no llama al metodo.'
        );
    }

    /**
     * 🔴 `ClaudeClientOpsController::PIPELINE_PRE_CIERRE` + `PIPELINE_POST_CIERRE` se sirven en la
     * ficha del cliente como las etapas reales del pipeline y se replican a mano de `$steps` (que
     * es privado a propósito). Estuvieron dos etapas atrás sin que nada lo dijera
     * (`restart_queue_workers` desde el 26/8 y `sync_env_keys` en esta misión): con este candado
     * la próxima etapa nueva no puede quedar sin replicar. El corte es `pause_for_crons`, la etapa
     * que deja el upgrade en `paused` y corta la pasada.
     */
    public function test_la_ficha_del_cliente_replica_las_etapas_reales_del_pipeline(): void
    {
        $e       = $this->escenario('shared_hosting', 'shared_hosting');
        $service = new DeploymentService($e['upgrade']);

        $propiedad = new \ReflectionProperty($service, 'steps');
        $propiedad->setAccessible(true);
        $steps = $propiedad->getValue($service);

        $pre  = ClaudeClientOpsController::PIPELINE_PRE_CIERRE;
        $post = ClaudeClientOpsController::PIPELINE_POST_CIERRE;

        $this->assertSame(
            $steps,
            array_merge($pre, $post),
            'PIPELINE_PRE_CIERRE + PIPELINE_POST_CIERRE tienen que ser exactamente $steps, en ese orden.'
        );
        $this->assertSame('pause_for_crons', $pre[count($pre) - 1], 'El pre-cierre termina en la pausa por los crons.');
        $this->assertContains('sync_env_keys', $pre);
        $this->assertContains('restart_queue_workers', $pre);
    }

    /* ==========================================================================================
     | 2) La regla: qué se copia y qué no
     |========================================================================================= */

    /**
     * 🔴 El caso de ferretotal, con todo lo que NO tiene que viajar alrededor: lo que el destino ya
     * tiene (aunque el valor difiera, como APP_KEY; aunque esté vacío, como ANTHROPIC_API_KEY), las
     * claves propias del frente, y un valor vacío en el origen.
     */
    public function test_claves_a_sincronizar_agrega_solo_lo_que_falta_y_no_es_propio_del_frente(): void
    {
        $activo = [
            'APP_NAME'                 => 'ferretotal',
            'APP_KEY'                  => 'base64:clave-del-activo',
            'APP_URL'                  => 'https://api-ferretotal.comerciocity.com',
            'DB_DATABASE'              => 'ferretotal',
            'DB_PASSWORD'              => 'secreta',
            'OPENAI_API_KEY'           => 'sk-openai',
            'ANTHROPIC_API_KEY'        => 'sk-ant',
            'SESSION_DOMAIN'           => '.ferretotal.comerciocity.com',
            'SESSION_COOKIE'           => 'ferretotal_session',
            'SANCTUM_STATEFUL_DOMAINS' => 'ferretotal.comerciocity.com',
            'REDIS_PREFIX'             => 'ferretotal_',
            'CACHE_PREFIX'             => 'ferretotal_cache',
            'SPA_URL'                  => 'https://ferretotal.comerciocity.com',
            'MAIL_PASSWORD'            => '',
        ];

        $destino = [
            'APP_NAME'          => 'ferretotal2',
            'APP_KEY'           => 'base64:otra-clave',
            'APP_URL'           => 'https://api-ferretotal2.comerciocity.com',
            'DB_DATABASE'       => 'ferretotal',
            'ANTHROPIC_API_KEY' => '',
        ];

        $resultado = DeploymentService::claves_a_sincronizar($activo, $destino);

        $this->assertSame(
            ['DB_PASSWORD' => 'secreta', 'OPENAI_API_KEY' => 'sk-openai'],
            $resultado,
            'Sólo lo que falta en el destino y no es propio del frente, en el orden del origen.'
        );

        $this->assertArrayNotHasKey('APP_KEY', $resultado, 'Una clave que el destino ya tiene se respeta aunque el valor difiera.');
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $resultado, 'Presente con valor vacío en el destino sigue siendo presente: no se pisa.');
        $this->assertArrayNotHasKey('MAIL_PASSWORD', $resultado, 'Un valor vacío en el origen no se copia: env() distingue ausente de vacío.');
    }

    /** Sobre un destino vacío se conservan APP_KEY, OPENAI_API_KEY y las DB_*, y se apartan las propias del frente. */
    public function test_sobre_un_destino_vacio_conserva_las_compartidas_y_aparta_las_propias_del_frente(): void
    {
        $activo = [
            'APP_NAME'              => 'ferretotal',
            'APP_KEY'               => 'base64:clave',
            'APP_URL'               => 'https://api-ferretotal.comerciocity.com',
            'DB_HOST'               => '127.0.0.1',
            'DB_DATABASE'           => 'ferretotal',
            'DB_USERNAME'           => 'ferretotal',
            'DB_PASSWORD'           => 'secreta',
            'OPENAI_API_KEY'        => 'sk-openai',
            'USER_ID'               => '2700',
            'SESSION_DOMAIN'        => '.ferretotal.comerciocity.com',
            'SANCTUM_STATEFUL_CORS' => 'ferretotal.comerciocity.com',
            'REDIS_PREFIX'          => 'ferretotal_',
            'SESSION_COOKIE'        => 'ferretotal_session',
            'VUE_APP_API_URL'       => 'https://api-ferretotal.comerciocity.com/public',
        ];

        $resultado = DeploymentService::claves_a_sincronizar($activo, []);

        $this->assertSame(
            ['APP_KEY', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'OPENAI_API_KEY', 'USER_ID'],
            array_keys($resultado)
        );

        foreach (['APP_NAME', 'APP_URL', 'SESSION_DOMAIN', 'SANCTUM_STATEFUL_CORS', 'REDIS_PREFIX', 'SESSION_COOKIE', 'VUE_APP_API_URL'] as $propia) {
            $this->assertArrayNotHasKey($propia, $resultado, "{$propia} es propia del frente y no se copia nunca.");
        }
    }

    /** La lista de exclusión mira el nombre de la clave, fragmento por fragmento. */
    public function test_es_clave_propia_del_frente_mira_el_nombre(): void
    {
        foreach (['APP_URL', 'SPA_URL', 'SESSION_DOMAIN', 'REDIS_PREFIX', 'CACHE_PREFIX', 'SESSION_COOKIE', 'SANCTUM_STATEFUL_DOMAINS', 'SANCTUM_STATEFUL_CORS', 'APP_NAME'] as $propia) {
            $this->assertTrue(DeploymentService::es_clave_propia_del_frente($propia), "{$propia} tiene que quedar apartada.");
        }

        foreach (['APP_KEY', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'DB_DATABASE', 'DB_PASSWORD', 'MAIL_HOST', 'PUSHER_APP_KEY', 'QUEUE_CONNECTION', 'USER_ID', 'SANCTUM_EXPIRATION'] as $compartida) {
            $this->assertFalse(DeploymentService::es_clave_propia_del_frente($compartida), "{$compartida} es la misma en los dos frentes y tiene que copiarse si falta.");
        }
    }

    /** Con los dos `.env` iguales no hay nada que agregar. */
    public function test_sin_nada_que_agregar_devuelve_vacio(): void
    {
        $env = ['APP_KEY' => 'k', 'OPENAI_API_KEY' => 'sk', 'DB_DATABASE' => 'd'];

        $this->assertSame([], DeploymentService::claves_a_sincronizar($env, $env));
        $this->assertSame([], DeploymentService::claves_a_sincronizar([], ['APP_KEY' => 'k']));
    }

    /* ==========================================================================================
     | 3) deploy/start acepta reanudar desde la etapa
     |========================================================================================= */

    /** Sobre un `failed`, `resume_from_step=sync_env_keys` encola desde ahí y conserva los logs. */
    public function test_deploy_start_acepta_reanudar_desde_sync_env_keys(): void
    {
        Queue::fake();

        $e       = $this->escenario('shared_hosting', 'shared_hosting', true, ['deployment_status' => 'failed']);
        $upgrade = $e['upgrade'];

        DeploymentLog::create([
            'client_version_upgrade_id' => $upgrade->id,
            'step'                      => 'run_migrations',
            'level'                     => 'error',
            'line'                      => 'Comando remoto falló (exit 1). migrate no pudo bootear',
        ]);

        $cuerpo = $this->postJson('/api/claude/upgrades/' . $upgrade->id . '/deploy/start', [
            'confirm_client_name' => self::NOMBRE,
            'resume_from_step'    => 'sync_env_keys',
        ], $this->headers())->assertStatus(202)->json();

        $this->assertTrue($cuerpo['encolado']);
        $this->assertSame('sync_env_keys', $cuerpo['desde_etapa']);
        $this->assertStringContainsString('conserv', $cuerpo['nota_logs']);

        $this->assertSame(
            2,
            DeploymentLog::where('client_version_upgrade_id', $upgrade->id)->count(),
            'El log del intento anterior se conserva y se agrega la línea de reanudación.'
        );

        $etapas = [];
        Queue::assertPushed(RunDeploymentJob::class, function ($job) use (&$etapas) {
            $etapas[] = $this->etapa_del_job($job);

            return $job->connection === 'database';
        });

        $this->assertSame(['sync_env_keys'], $etapas);
    }

    /* ==========================================================================================
     | 4) El log nombra claves, nunca valores
     |========================================================================================= */

    /**
     * 🔴 Ninguna llamada a `$this->log()` del paso puede tener a mano un valor: los dos `.env`
     * parseados quedan afuera de todo log, y el array de claves faltantes sólo entra envuelto en
     * `array_keys()` o `count()`.
     */
    public function test_el_log_del_paso_nombra_las_claves_y_nunca_los_valores(): void
    {
        $bloque = $this->bloque_del_paso();

        $this->assertStringContainsString(
            "implode(', ', array_keys(\$claves_faltantes))",
            $bloque,
            'Las claves agregadas se listan por nombre, con array_keys().'
        );

        $llamadas = $this->llamadas_al_log($bloque);
        $this->assertNotEmpty($llamadas, 'El paso tiene que dejar traza en deployment_logs.');

        foreach ($llamadas as $llamada) {
            foreach (['$env_origen', '$env_destino', '$valor', 'array_values(', 'json_encode(', 'print_r(', 'var_export(', 'serialize('] as $prohibido) {
                $this->assertStringNotContainsString(
                    $prohibido,
                    $llamada,
                    "Un log del paso no puede tocar {$prohibido}: por ahí entra un valor del .env al panel."
                );
            }

            $menciones = preg_match_all('/\$claves_faltantes/', $llamada);
            $envueltas = preg_match_all('/(array_keys|count)\(\$claves_faltantes\)/', $llamada);

            $this->assertSame(
                $menciones,
                $envueltas,
                'Toda mención al array de claves faltantes dentro de un log va envuelta en array_keys() o count().'
            );
        }
    }

    /* ==========================================================================================
     | 5) Nunca aborta el deploy
     |========================================================================================= */

    /** Candado de fuente: el paso atrapa Throwable, degrada a warning y no lanza nada. */
    public function test_el_paso_atrapa_throwable_y_no_lanza(): void
    {
        $bloque = $this->bloque_del_paso();

        $this->assertStringContainsString('catch (\Throwable', $bloque, 'Cualquier fallo tiene que caer en un catch de Throwable.');
        $this->assertStringContainsString("'warning'", $bloque, 'El fallo tiene que quedar como warning visible en deployment_logs.');
        $this->assertStringNotContainsString('throw ', $bloque, 'El paso no puede lanzar: abortaria un deploy que ya subio el codigo.');
    }

    /** Sin API activa previa (instalación nueva) no toca nada y lo dice. */
    public function test_sin_api_activa_previa_no_toca_nada_y_deja_traza(): void
    {
        $e       = $this->escenario('shared_hosting', 'shared_hosting', false);
        $service = new DeploymentService($e['upgrade']);

        $this->invocar_paso($service);

        $lineas = $this->lineas_del_paso($e['upgrade']);
        $this->assertCount(1, $lineas);
        $this->assertSame('info', $lineas->first()->level, 'No tener de dónde completar no es un error.');
        $this->assertStringContainsString('activa', $lineas->first()->line);
    }

    /** Si la activa es el destino (cliente con una sola ClientApi) es el mismo archivo: nada que hacer. */
    public function test_si_la_api_activa_es_el_destino_no_toca_nada(): void
    {
        $e = $this->escenario('shared_hosting', 'shared_hosting');

        $e['client']->active_client_api_id = $e['api_destino']->id;
        $e['client']->save();

        $service = new DeploymentService($e['upgrade']);

        $this->invocar_paso($service);

        $lineas = $this->lineas_del_paso($e['upgrade']);
        $this->assertCount(1, $lineas);
        $this->assertSame('info', $lineas->first()->level);
        $this->assertStringContainsString('mismo .env', $lineas->first()->line);
    }

    /** Origen y destino en hostings distintos es una migración, no una rotación: avisa y sigue. */
    public function test_con_hostings_distintos_avisa_y_sigue_sin_tocar_el_env(): void
    {
        $e       = $this->escenario('shared_hosting', 'vps');
        $service = new DeploymentService($e['upgrade']);

        $this->invocar_paso($service);

        $lineas = $this->lineas_del_paso($e['upgrade']);
        $this->assertCount(1, $lineas);
        $this->assertSame('warning', $lineas->first()->level, 'Tiene que quedar visible: alguien tiene que revisar ese .env a mano.');
        $this->assertStringContainsString('shared_hosting', $lineas->first()->line);
        $this->assertStringContainsString('vps', $lineas->first()->line);
    }

    /**
     * 🔴 Con el SSH caído (la credencial apunta a un puerto cerrado) el paso deja un warning con el
     * motivo y devuelve sin lanzar. Es el `catch` de verdad, no un candado de fuente. Y en esa línea
     * no puede aparecer la password de la credencial.
     */
    public function test_si_el_ssh_falla_deja_warning_y_no_aborta(): void
    {
        $e       = $this->escenario('shared_hosting', 'shared_hosting');
        $service = new DeploymentService($e['upgrade']);

        $this->invocar_paso($service);

        $lineas = $this->lineas_del_paso($e['upgrade']);
        $this->assertCount(1, $lineas, 'Un fallo de SSH deja exactamente una línea: el warning con el motivo.');
        $this->assertSame('warning', $lineas->first()->level);
        $this->assertStringContainsString('El deploy sigue', $lineas->first()->line);
        $this->assertStringContainsString('Detalle:', $lineas->first()->line);
        $this->assertStringNotContainsString(self::PASSWORD_SSH_FALSA, $lineas->first()->line);
    }
}

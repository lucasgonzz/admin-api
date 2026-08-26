<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\Version;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Misión restart-queue-workers-en-deploy — el deploy reinicia el worker de cola en el VPS.
 *
 * En el VPS el worker vive bajo supervisor y es un proceso de LARGA VIDA: carga las clases en
 * memoria al arrancar y no las recarga nunca. Sin este paso, después de cada deploy sigue
 * ejecutando el código viejo indefinidamente.
 *
 * Medido en producción el 26/8/2026 (demo2): deploy 13:10, worker arrancado 11:06, y a las 13:16
 * un job se cayó con "Undefined class constant 'CONDICION_MT'" — constante que existe en
 * User.php:28 con su `use` correcto. Lo que ve el negocio: cambia la cotización del dólar y nunca
 * le llega la notificación de que se procesó.
 *
 * En shared_hosting el problema NO existe: ahí el worker es el `queue:work --stop-when-empty` que
 * lanza el cron, arranca y muere cada minuto, y toma el código nuevo solo. Por eso el paso es
 * condicional, y por eso hay un test que protege que en shared no ejecute NADA.
 */
class ReinicioDelWorkerDeColaEnElDeploymentTest extends TestCase
{
    use DatabaseTransactions;

    /** @var int */
    private $contador = 0;

    /**
     * El constructor de DeploymentService resuelve la credencial SSH del hosting con firstOrFail(),
     * asi que tiene que existir antes de instanciarlo.
     *
     * @param  string  $type
     * @return void
     */
    private function sembrar_credencial(string $type): void
    {
        $credencial = ClientSshCredential::where('type', $type)->first();
        if ($credencial === null) {
            $credencial       = new ClientSshCredential();
            $credencial->type = $type;
        }

        $credencial->host     = '198.51.100.10';
        $credencial->port     = 22;
        $credencial->username = 'usuario-de-prueba';
        $credencial->password = 'secreto';
        $credencial->save();
    }

    /**
     * @param  string  $codigo
     * @return Version
     */
    private function crear_version(string $codigo): Version
    {
        $version               = new Version();
        $version->version      = $codigo;
        $version->title        = 'Version ' . $codigo;
        $version->status       = 'published';
        $version->published_at = now();
        $version->save();

        return $version;
    }

    /**
     * Arma un upgrade con su cliente y su ClientApi destino.
     *
     * @param  string  $hosting_type
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade(string $hosting_type): ClientVersionUpgrade
    {
        $this->contador += 2;

        $this->sembrar_credencial($hosting_type === 'vps' ? 'vps' : 'shared_hosting');

        $from = $this->crear_version('9.' . $this->contador . '.0');
        $to   = $this->crear_version('9.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente worker';
        $client->company_name       = 'Empresa worker';
        $client->slug               = 'cliente-worker-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-worker.ejemplo.test';
        $api->path         = 'worker/' . Str::random(6);
        $api->spa_url      = 'https://worker.ejemplo.test';
        $api->hosting_type = $hosting_type;
        $api->vps_path     = $hosting_type === 'vps' ? 'worker-vps' : null;
        $api->save();

        return ClientVersionUpgrade::create([
            'client_id'            => $client->id,
            'from_version_id'      => $from->id,
            'to_version_id'        => $to->id,
            'status'               => 'pendiente',
            'target_client_api_id' => $api->id,
        ]);
    }

    /**
     * El código fuente del servicio, para los candados estructurales.
     *
     * @return string
     */
    private function fuente_del_servicio(): string
    {
        return (string) file_get_contents(app_path('Services/DeploymentService.php'));
    }

    /**
     * El paso tiene que correr DESPUÉS de subir el código y migrar (para que el worker nuevo
     * arranque contra un esquema al día) y ANTES de pause_for_crons, que hace `return` y corta la
     * ejecución: si quedara después, no correría en la misma pasada y el worker seguiría viejo
     * durante toda la pausa.
     *
     * @return void
     */
    public function test_el_paso_va_entre_las_migraciones_y_la_pausa_de_crons()
    {
        $service = new DeploymentService($this->crear_upgrade('vps'));

        $propiedad = new \ReflectionProperty($service, 'steps');
        $propiedad->setAccessible(true);
        $steps = $propiedad->getValue($service);

        $this->assertContains('restart_queue_workers', $steps, 'El paso no está en el pipeline.');

        $posicion_migraciones = array_search('run_migrations', $steps, true);
        $posicion_reinicio    = array_search('restart_queue_workers', $steps, true);
        $posicion_pausa       = array_search('pause_for_crons', $steps, true);

        $this->assertGreaterThan(
            $posicion_migraciones,
            $posicion_reinicio,
            'El reinicio tiene que ir despues de run_migrations, o el worker arranca contra un esquema viejo.'
        );

        $this->assertLessThan(
            $posicion_pausa,
            $posicion_reinicio,
            'El reinicio tiene que ir ANTES de pause_for_crons: ese paso hace return y corta la ejecucion.'
        );
    }

    /**
     * 🔴 Candado: un paso listado en $steps pero sin su `case` en el switch no se ejecuta nunca y
     * no falla nada. Es exactamente lo que le pasa a step_update_crons(), que está definido en el
     * servicio y no está en el switch desde que se escribió.
     *
     * @return void
     */
    public function test_el_paso_esta_enganchado_en_el_switch_y_no_queda_muerto()
    {
        $fuente = $this->fuente_del_servicio();

        $this->assertStringContainsString(
            "case 'restart_queue_workers':",
            $fuente,
            'El paso esta en $steps pero no tiene case en el switch: no se ejecutaria nunca.'
        );

        $this->assertStringContainsString(
            'step_restart_queue_workers()',
            $fuente,
            'El case existe pero no llama al metodo.'
        );
    }

    /**
     * En shared_hosting no hay worker de larga vida, así que el paso no puede ejecutar NADA.
     *
     * Es el test que más protege: si algún día alguien saca la condición, el deploy de las ~36
     * instancias del shared se pondría a abrir sesiones y correr comandos para nada, y peor, a
     * fallar en servidores donde ese artisan puede no estar disponible.
     *
     * Se invoca el método real, sin mocks: si intentara ejecutar el comando, necesitaría la sesión
     * SSH (que en un test no existe) y reventaría. Que termine limpio ES la prueba.
     *
     * @return void
     */
    public function test_en_shared_hosting_no_ejecuta_nada_y_deja_traza()
    {
        $upgrade = $this->crear_upgrade('shared_hosting');
        $service = new DeploymentService($upgrade);

        $metodo = new \ReflectionMethod($service, 'step_restart_queue_workers');
        $metodo->setAccessible(true);
        $metodo->invoke($service);

        $lineas = DeploymentLog::where('client_version_upgrade_id', $upgrade->id)
            ->where('step', 'restart_queue_workers')
            ->get();

        $this->assertCount(1, $lineas, 'El paso tiene que dejar una linea explicando por que no hizo nada.');
        $this->assertStringContainsString('shared_hosting', $lineas->first()->line);
        $this->assertNotSame('error', $lineas->first()->level, 'No hacer nada en shared no es un error.');
    }

    /**
     * 🔴 Candado sobre CÓMO se reinicia.
     *
     * `queue:restart` es graceful: deja una marca que el worker lee entre job y job, así que
     * termina el que está procesando y recién ahí sale; supervisor lo relanza con el código nuevo.
     * `supervisorctl restart` lo corta en seco a mitad de un job — y además pide root, que la
     * sesión del deploy no necesariamente tiene.
     *
     * @return void
     */
    public function test_reinicia_con_queue_restart_y_nunca_con_supervisorctl()
    {
        $fuente = $this->fuente_del_servicio();

        $this->assertStringContainsString(
            'artisan queue:restart',
            $fuente,
            'El reinicio tiene que ser con queue:restart.'
        );

        // Se miran solo las lineas que ejecutan algo, no los comentarios: el metodo explica en
        // prosa por que NO se usa supervisorctl, y esa mencion no puede hacer fallar el candado.
        $ejecutables = array_filter(
            preg_split('/
?
/', $fuente),
            function ($linea) {
                return preg_match('/^\s*(\*|\/\/|\/\*)/', $linea) !== 1;
            }
        );

        $this->assertStringNotContainsString(
            'supervisorctl',
            implode("
", $ejecutables),
            'supervisorctl corta el job en curso y pide root: no va en el pipeline del deploy.'
        );
    }

    /**
     * Si el reinicio falla, el deploy NO se aborta.
     *
     * Llegado ese punto el código ya está subido y las migraciones corridas: cortar ahí dejaría el
     * deploy a medias, que es peor que un worker con código viejo. Tiene que degradar a warning.
     *
     * @return void
     */
    public function test_si_falla_el_reinicio_el_deploy_no_se_aborta()
    {
        $fuente = $this->fuente_del_servicio();

        $bloque = substr(
            $fuente,
            (int) strpos($fuente, 'private function step_restart_queue_workers')
        );
        $bloque = substr($bloque, 0, (int) strpos($bloque, 'private function step_pause_for_crons'));

        $this->assertStringContainsString(
            "'warning'",
            $bloque,
            'Un reinicio que no se pudo confirmar tiene que quedar como warning visible en deployment_logs.'
        );

        $this->assertStringNotContainsString(
            'throw ',
            $bloque,
            'El paso no puede lanzar: abortaria un deploy que ya subio codigo y corrio migraciones.'
        );
    }
}

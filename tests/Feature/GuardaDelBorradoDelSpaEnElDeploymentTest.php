<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La guarda del borrado recursivo del SPA, en el servicio de ACTUALIZACIONES.
 *
 * El agujero se descubrió el 31/8/2026 en InstallationService, construyendo el aprovisionamiento de
 * hosting, y ahí quedó tapado con ClientApiPathResolver::assert_directorio_de_spa_borrable(). Pero
 * la misma línea vive en DeploymentService::build_spa_hosting_deploy_shell():
 *
 *     cd "$SPA_DIR" || exit 1; find . -mindepth 1 -delete
 *
 * y en shared el directorio se arma concatenando 'domains/comerciocity.com/public_html/' con el path
 * de la ClientApi. Con ese path vacío, el find corre sobre la raíz de la cuenta compartida y vacía
 * las carpetas de los ~40 clientes activos de una sola pasada.
 *
 * Y no es hipotético: el relevamiento del 26/8/2026 (informes/20260826-plan-migracion-shared-a-vps.md,
 * §2.5) encontró en producción a los clientes 43 y 13 con hosting_type='vps' y vps_path NULL. Basta
 * que alguien les corra una actualización.
 *
 * DeploymentService es, además, el camino por el que el problema llegaría ANTES: corre en cada
 * actualización de cliente, mientras que InstallationService corre una vez por cliente nuevo.
 *
 * Estos dos tests son el candado. Si alguien saca la guarda "porque el resolver ya devuelve bien",
 * se ponen rojos.
 */
class GuardaDelBorradoDelSpaEnElDeploymentTest extends TestCase
{
    use DatabaseTransactions;

    /** @var int */
    private $contador = 0;

    /**
     * 🔴 Hosting compartido con `path` vacío: el comando no se arma.
     *
     * Sin la guarda, $spa_dir daba 'domains/comerciocity.com/public_html/' y el find de adentro
     * vaciaba la cuenta entera.
     *
     * @return void
     */
    public function test_en_shared_con_path_vacio_el_deploy_del_spa_no_arma_el_comando()
    {
        $upgrade = $this->crear_upgrade('shared_hosting');

        $api       = $upgrade->target_client_api;
        $api->path = '';
        $api->save();

        $service = new DeploymentService($upgrade->fresh());

        $mensaje = $this->mensaje_de_error(function () use ($service) {
            $this->invocar($service, 'build_spa_hosting_deploy_shell');
        });

        $this->assertNotSame('', $mensaje, 'La guarda no frenó: el comando de borrado se armó igual.');
        $this->assertStringContainsString('find . -mindepth 1 -delete', $mensaje);
    }

    /**
     * 🔴 La otra mitad: hosting_type='vps' con vps_path vacío, que es el estado real de los
     * clientes 43 y 13 en producción.
     *
     * @return void
     */
    public function test_en_vps_sin_vps_path_el_deploy_del_spa_tampoco_arma_el_comando()
    {
        $upgrade = $this->crear_upgrade('vps');

        $api           = $upgrade->target_client_api;
        $api->vps_path = '';
        $api->save();

        $service = new DeploymentService($upgrade->fresh());

        $mensaje = $this->mensaje_de_error(function () use ($service) {
            $this->invocar($service, 'build_spa_hosting_deploy_shell');
        });

        $this->assertNotSame('', $mensaje, 'La guarda no frenó: el comando de borrado se armó igual.');
        $this->assertStringContainsString('vps_path', $mensaje);
    }

    /**
     * El camino sano no se movió: con los datos completos el comando sale igual que siempre.
     *
     * Es la otra mitad del candado. Una guarda que además rompiera el deploy normal sería peor que
     * el problema que arregla.
     *
     * @return void
     */
    public function test_con_los_datos_completos_el_comando_se_arma_como_siempre()
    {
        $upgrade = $this->crear_upgrade('shared_hosting');
        $service = new DeploymentService($upgrade);

        $shell = (string) $this->invocar($service, 'build_spa_hosting_deploy_shell');

        $this->assertStringContainsString('find . -mindepth 1 -delete', $shell);

        /*
         * El directorio del SPA NO es el path de la ClientApi: se deriva cambiándole el '/api' por
         * '/spa'. Lo que tiene que aparecer —y es lo que la guarda exige— es el segmento que
         * identifica al cliente, que es justo lo que faltaba en el caso del incidente.
         */
        $identificador = explode('/', $upgrade->target_client_api->path)[0];
        $this->assertStringContainsString($identificador . '/spa', $shell);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Andamiaje
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El constructor de DeploymentService resuelve la credencial SSH con firstOrFail(), así que
     * tiene que existir antes de instanciarlo.
     *
     * @param  string  $type
     * @return void
     */
    private function sembrar_credencial(string $type)
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
    private function crear_version(string $codigo)
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
    private function crear_upgrade(string $hosting_type)
    {
        $this->contador += 2;

        $this->sembrar_credencial($hosting_type === 'vps' ? 'vps' : 'shared_hosting');

        $from = $this->crear_version('8.' . $this->contador . '.0');
        $to   = $this->crear_version('8.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente guarda';
        $client->company_name       = 'Empresa guarda';
        $client->slug               = 'cliente-guarda-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->current_version_id = $from->id;
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-guarda.ejemplo.test';
        $api->path         = 'guarda' . $this->contador . '/api';
        $api->spa_url      = 'https://guarda.ejemplo.test';
        $api->hosting_type = $hosting_type;
        $api->vps_path     = $hosting_type === 'vps' ? 'guarda-vps' : null;
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
     * Invoca un método privado del servicio.
     *
     * Es la única forma de fijar el comando sin un servidor del otro lado, y es el mismo criterio
     * con el que ya se prueban los otros armadores de comando de estos dos servicios.
     *
     * @param  object  $objeto
     * @param  string  $metodo
     * @return mixed
     */
    private function invocar($objeto, string $metodo)
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invoke($objeto);
    }

    /**
     * Corre un closure y devuelve el mensaje de la excepción, o '' si no lanzó ninguna.
     *
     * @param  \Closure  $accion
     * @return string
     */
    private function mensaje_de_error(\Closure $accion)
    {
        try {
            $accion();
        } catch (\Throwable $excepcion) {
            return $excepcion->getMessage();
        }

        return '';
    }
}

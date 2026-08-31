<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\Version;
use App\Services\ClientApiPathResolver;
use App\Services\HostingProvisioningService;
use App\Services\HostingerApiClient;
use App\Services\InstallationService;
use App\Services\RemoteCommandRunner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Fakes\HostingerApiClientFake;
use Tests\Fakes\RemoteCommandRunnerFake;
use Tests\TestCase;

/**
 * U9 — la instalación sobre VPS.
 *
 * Hasta el 31/8/2026 InstallationService asumía hosting compartido de punta a punta: la credencial
 * SSH era la fija 'shared_hosting', get_api_path() y get_spa_path() concatenaban el prefijo de la
 * cuenta de Hostinger, el SFTP abría siempre contra el compartido y el flag de composer venía
 * hardcodeado. Esta unidad lo hizo hosting-aware, para los dos pipelines (el real y el esqueleto).
 *
 * 🔴 Este archivo prueba las DOS mitades, y la segunda importa tanto como la primera:
 *
 *   1. Que en VPS resuelva el VPS (paths absolutos, credencial 'vps', chown, composer envuelto).
 *   2. Que en hosting compartido salga EXACTAMENTE lo de siempre, hasta el último carácter del
 *      shell de despliegue del SPA. Es el único camino que hoy instala clientes y no hay un solo
 *      test que cubra sus etapas SSH de punta a punta.
 *
 * Y la guarda del borrado, que es la que justifica la unidad entera: con la ClientApi mal cargada,
 * el despliegue del SPA no llega a armar el comando.
 *
 * 🔴 Está aparte de AprovisionamientoDeHostingDelClienteTest —que ya iba en 2181 líneas— porque es
 * otro tema: aquel prueba que el hosting se APROVISIONE, este que la instalación sepa INSTALAR
 * sobre lo aprovisionado. R2 (450 líneas) es sobre archivos de app/Services y no aplica a los tests,
 * así que la partición es por tema y no por freno.
 *
 * Ningún test de acá abre una sesión SSH ni sale a la red.
 */
class InstalacionSobreVpsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Reemplazo en memoria del cliente HTTP de Hostinger.
     *
     * @var HostingerApiClientFake
     */
    private $hostinger;

    /**
     * Reemplazo en memoria del runner de comandos remotos.
     *
     * @var RemoteCommandRunnerFake|null
     */
    private $runner = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hostinger = new HostingerApiClientFake();
        $this->app->instance(HostingerApiClient::class, $this->hostinger);

        /* bind() con closure y no instance(): el proveedor lo resuelve con makeWith(['credential']),
         * y el container ignora un instance() cuando la resolución lleva parámetros. */
        $this->app->bind(RemoteCommandRunner::class, function ($app, $parametros) {
            if ($this->runner === null) {
                $this->runner = new RemoteCommandRunnerFake($parametros['credential']);
            }

            return $this->runner;
        });

        config([
            'services.hostinger.api_token'         => 'token-de-prueba',
            'services.hostinger.account_username'  => 'u767360347',
            'services.hostinger.domain'            => 'comerciocity.com',
            'services.hostinger.database_prefix'   => 'u767360347_',
            'services.hostinger.vps_ip'            => '76.13.171.147',
            'services.hostinger.dns_write_enabled' => true,
            'services.hostinger.dns_wait_seconds'  => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LAS RUTAS Y LA CREDENCIAL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * En VPS las tres rutas son ABSOLUTAS y salen de vps_path / spa_url, no del path del compartido.
     *
     * La disposición es la que midió por SSH el informe de migración del 26/8/2026 (§1): la API en
     * /home/api-<slug>/empresa-api y el SPA en el docroot del sitio de CloudPanel.
     */
    public function test_en_vps_el_pipeline_resuelve_los_paths_absolutos_del_vps(): void
    {
        $datos   = $this->cliente_en_vps('lacava');
        $slug    = $datos['slug'];
        $service = new InstallationService($datos['installation']);

        $this->assertSame(
            '/home/api-' . $slug . '/empresa-api',
            $this->invocar($service, 'get_api_path')
        );
        $this->assertSame(
            '/home/' . $slug . '/htdocs/' . $slug . '.comerciocity.com',
            $this->invocar($service, 'get_spa_path')
        );

        /* En VPS el directorio del SPA es el path absoluto tal cual: sin prefijo de cuenta. */
        $this->assertSame(
            '/home/' . $slug . '/htdocs/' . $slug . '.comerciocity.com',
            $this->invocar($service, 'get_spa_hosting_dir')
        );
    }

    /**
     * 🔴 En hosting compartido no se movió NI UN CARÁCTER. Es el único camino que hoy instala
     * clientes y sus etapas SSH no están cubiertas de punta a punta por ningún test: lo que fija
     * este es que el refactor de U9 dejó los tres strings idénticos a los de antes.
     */
    public function test_en_hosting_compartido_los_paths_son_exactamente_los_de_siempre(): void
    {
        $datos   = $this->cliente_en_shared('colman');
        $slug    = $datos['slug'];
        $service = new InstallationService($datos['installation']);

        $this->assertSame(
            'domains/comerciocity.com/public_html/' . $slug . '/api',
            $this->invocar($service, 'get_api_path')
        );
        $this->assertSame($slug . '/spa', $this->invocar($service, 'get_spa_path'));
        $this->assertSame(
            'domains/comerciocity.com/public_html/' . $slug . '/spa',
            $this->invocar($service, 'get_spa_hosting_dir')
        );
    }

    /**
     * 🔴 El shell de despliegue del SPA en compartido, byte por byte.
     *
     * Es el comando que borra y repone el SPA de un cliente en producción. Si alguien lo cambia sin
     * querer —un espacio, un flag, el orden del mv— este test lo detiene. La única diferencia con el
     * de antes de U9 es de dónde sale $spa_dir, y justamente por eso se compara el string entero.
     */
    public function test_el_shell_del_spa_en_compartido_queda_identico_al_de_antes(): void
    {
        $datos   = $this->cliente_en_shared('colman');
        $service = new InstallationService($datos['installation']);
        $uuid    = $datos['installation']->uuid;

        $esperado = 'set -e; '
            . 'SPA_DIR=' . escapeshellarg(
                'domains/comerciocity.com/public_html/' . $datos['slug'] . '/spa'
            ) . '; '
            . 'TEMP_ZIP=' . escapeshellarg('../dist_deploy_' . $uuid . '.zip') . '; '
            . 'cd "$SPA_DIR" || exit 1; '
            . 'if [ -f ' . escapeshellarg('dist.zip') . ' ]; then mv '
            . escapeshellarg('dist.zip') . ' "$TEMP_ZIP"; fi; '
            . 'find . -mindepth 1 -delete 2>/dev/null || true; '
            . 'if [ -f "$TEMP_ZIP" ]; then unzip -o "$TEMP_ZIP" -d .; rm -f "$TEMP_ZIP"; fi; '
            . 'echo SPA_DEPLOY_OK 2>&1';

        $this->assertSame($esperado, $this->invocar($service, 'build_spa_hosting_deploy_shell'));
    }

    /**
     * El mismo shell en VPS apunta al docroot del sitio de CloudPanel, no a la cuenta compartida.
     */
    public function test_el_shell_del_spa_en_vps_apunta_al_docroot_del_sitio(): void
    {
        $datos   = $this->cliente_en_vps('lacava');
        $slug    = $datos['slug'];
        $service = new InstallationService($datos['installation']);

        $shell = $this->invocar($service, 'build_spa_hosting_deploy_shell');

        $this->assertStringContainsString(
            '/home/' . $slug . '/htdocs/' . $slug . '.comerciocity.com',
            $shell
        );
        $this->assertStringNotContainsString('domains/comerciocity.com/public_html', $shell);
    }

    /**
     * La credencial SSH/SFTP sale del hosting_type del destino, igual que en DeploymentService.
     *
     * Se mira la propiedad $credential que resolvió el constructor y no solo el helper: la línea
     * fija `where('type', 'shared_hosting')` del constructor era la mitad del bug, y un test que
     * mirara únicamente el helper la dejaría pasar.
     */
    public function test_la_credencial_sale_del_hosting_del_destino(): void
    {
        $vps = new InstallationService($this->cliente_en_vps('lacava')['installation']);
        $this->assertSame('vps', $this->invocar($vps, 'get_hosting_credential_type'));
        $this->assertSame('vps', $this->propiedad($vps, 'credential')->type);

        $shared = new InstallationService($this->cliente_en_shared('colman')['installation']);
        $this->assertSame('shared_hosting', $this->invocar($shared, 'get_hosting_credential_type'));
        $this->assertSame('shared_hosting', $this->propiedad($shared, 'credential')->type);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 🔴 LA GUARDA DEL BORRADO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 EL TEST QUE JUSTIFICA LA UNIDAD ENTERA.
     *
     * El despliegue del SPA hace `cd "$SPA_DIR"` y después `find . -mindepth 1 -delete`. Con una
     * ClientApi de hosting compartido y `path` vacío —que es exactamente cómo quedaron los clientes
     * 43 y 13 en la migración (§2.5 del informe del 26/8/2026)— la cuenta vieja daba
     * 'domains/comerciocity.com/public_html/' y ese find vaciaba el public_html entero de la cuenta:
     * las carpetas de los ~40 clientes activos, de una.
     *
     * Ahora el comando ni se arma: la guarda tira antes, y como el string es lo único que después se
     * ejecuta, no hay forma de que un comando salga por SSH.
     */
    public function test_con_un_path_vacio_el_deploy_del_spa_no_llega_a_borrar_nada(): void
    {
        $datos = $this->cliente_en_shared('colman');
        $datos['api1']->path = '';
        $datos['api1']->save();

        $service = new InstallationService($datos['installation']->fresh());

        $mensaje = $this->mensaje_de_error(function () use ($service) {
            $this->invocar($service, 'build_spa_hosting_deploy_shell');
        });

        $this->assertStringContainsString('FRENADO ANTES DE BORRAR', $mensaje);
        $this->assertStringContainsString('find . -mindepth 1 -delete', $mensaje);

        /* Y no quedó ni una línea de comando en el panel: la guarda corre antes de armar el string. */
        $this->assertSame(0, $datos['installation']->deployment_logs()->count());
    }

    /**
     * La otra mitad del mismo agujero: hosting_type='vps' con vps_path vacío, que es el estado real
     * de los clientes 43 y 13 en producción. Frena en la resolución de la ruta, con su mensaje.
     */
    public function test_con_vps_path_vacio_el_deploy_del_spa_tampoco_arma_el_comando(): void
    {
        $datos = $this->cliente_en_vps('lacava');
        $datos['api1']->vps_path = '';
        $datos['api1']->save();

        $service = new InstallationService($datos['installation']->fresh());

        $mensaje = $this->mensaje_de_error(function () use ($service) {
            $this->invocar($service, 'build_spa_hosting_deploy_shell');
        });

        $this->assertStringContainsString('vps_path', $mensaje);
        $this->assertSame(0, $datos['installation']->deployment_logs()->count());
    }

    /**
     * La guarda no confía en que el resolver haya calculado bien: se le pasa la raíz de la cuenta
     * compartida a mano, con una ClientApi perfectamente cargada, y frena igual.
     *
     * Es el caso del día en que alguien vuelva a concatenar el prefijo por su cuenta en otro lugar.
     */
    public function test_la_guarda_rechaza_una_raiz_aunque_la_api_este_bien_cargada(): void
    {
        $datos    = $this->cliente_en_shared('colman');
        $api      = $datos['api1'];
        $resolver = new ClientApiPathResolver();

        $raices = [
            '',
            '/',
            'domains/comerciocity.com/public_html',
            'domains/comerciocity.com/public_html/',
            '/home',
        ];

        foreach ($raices as $raiz) {
            $mensaje = $this->mensaje_de_error(function () use ($resolver, $api, $raiz) {
                $resolver->assert_directorio_de_spa_borrable($api, $raiz);
            });

            $this->assertStringContainsString(
                'FRENADO ANTES DE BORRAR',
                $mensaje,
                'La guarda dejó pasar el directorio "' . $raiz . '".'
            );
        }

        /* Y el directorio bueno del cliente pasa sin ruido: la guarda no es un freno de mano. */
        $resolver->assert_directorio_de_spa_borrable(
            $api,
            'domains/comerciocity.com/public_html/' . $datos['slug'] . '/spa'
        );
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EL ESQUELETO EN VPS Y EL ORDEN DE §0.2
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 El escenario de §0.2 del plan, simulado en el orden real.
     *
     * En un grupo [real, esqueleto] la fila esqueleto se construye DESPUÉS de que la real corrió, y
     * provision_check ya pasó las DOS ClientApi a hosting_type='vps'. Con la vieja aserción del
     * constructor, esa segunda fila moría al construirse con un mensaje que no tenía nada que ver
     * con lo que había pasado.
     *
     * Acá el flip lo hacen los pasos de verdad —el mismo VpsSiteProvisioner que corre en
     * producción, con el runner y la API de Hostinger falseados— y recién después se construye el
     * esqueleto.
     *
     * 🔴 El test corre provision_check Y provision_sites, y eso cambió el 31/8/2026 junto con el
     * arreglo del hallazgo D: el flip se mudó del preflight al final de provision_sites, para que
     * las ClientApi no queden diciendo 'vps' cuando no existe un solo sitio del otro lado. Lo que
     * este test fija no cambió —que el esqueleto se construye sin morir cuando las APIs ya están en
     * VPS— pero el momento en que eso pasa sí, y la simulación tiene que seguir al pipeline real.
     */
    public function test_el_esqueleto_se_construye_despues_de_que_el_aprovisionamiento_paso_las_apis_a_vps(): void
    {
        $datos = $this->cliente_en_shared('lacava');

        /* La fila real, con aprovisionamiento en VPS, corre su preflight y crea los sitios. */
        $datos['installation']->provision_hosting_type = ClientInstallation::PROVISION_VPS;
        $datos['installation']->save();
        $this->crear_credencial_vps();
        $this->responder_como_un_vps_sano($datos['slug']);

        $proveedor = HostingProvisioningService::para(
            $datos['installation']->fresh(),
            $datos['api1'],
            function ($step, $linea, $level) {
            }
        );
        $proveedor->provision_check();

        /* El preflight NO escribe: las APIs siguen en el compartido hasta que los sitios existan. */
        $this->assertSame('shared_hosting', $datos['api2']->fresh()->hosting_type);

        $proveedor->provision_sites();

        $this->assertSame('vps', $datos['api2']->fresh()->hosting_type);

        /* Y recién ahora se construye el esqueleto, como hace RunClientInstallationGroupJob. */
        $esqueleto = ClientInstallation::create([
            'client_id'              => $datos['client']->id,
            'client_api_id'          => $datos['api2']->id,
            'kind'                   => ClientInstallation::KIND_ESQUELETO,
            'status'                 => 'pendiente',
            'provision_hosting_type' => ClientInstallation::PROVISION_VPS,
        ]);

        $service = new InstallationService($esqueleto);

        $this->assertSame(
            ['provision_check', 'provision_sites', 'provision_dns', 'provision_db',
             'prepare_dirs', 'upload_public', 'write_env', 'finalize_skeleton'],
            $this->propiedad($service, 'steps')
        );

        /* Y sus rutas son las de la instancia 2 en el VPS, no las de la cuenta compartida. */
        $slug2 = $datos['slug'] . '2';
        $this->assertSame(
            '/home/api-' . $slug2 . '/empresa-api',
            $this->invocar($service, 'get_api_path')
        );
        $this->assertSame(
            '/home/' . $slug2 . '/htdocs/' . $slug2 . '.comerciocity.com',
            $this->invocar($service, 'get_spa_hosting_dir')
        );
    }

    /**
     * 🔴 HALLAZGO I — sin la credencial del hosting, el mensaje dice QUÉ falta y DÓNDE cargarlo.
     *
     * El constructor de InstallationService resuelve la credencial SSH según el hosting_type de la
     * API destino, y desde U9 ese tipo puede ser 'vps'. Con un `firstOrFail()` pelado, una
     * instalación sobre una ClientApi ya migrada sin esa fila cargada moría con "No query results
     * for model [App\Models\ClientSshCredential]" escrito en failure_reason: el operador lee eso en
     * el panel y no tiene con qué saber qué le falta. El preflight del VPS tiene el mensaje bueno,
     * pero corre después —este constructor es lo primero que se ejecuta—.
     *
     * @return void
     */
    public function test_sin_credencial_del_hosting_el_error_dice_que_falta_y_donde_cargarlo(): void
    {
        $datos = $this->cliente_en_vps('lacava');

        /* Se saca la credencial del VPS: es el estado de un cliente recién migrado a mano. */
        ClientSshCredential::where('type', 'vps')->delete();

        $mensaje = '';

        try {
            new InstallationService($datos['installation']->fresh());
        } catch (\Throwable $excepcion) {
            $mensaje = $excepcion->getMessage();
        }

        $this->assertStringNotContainsString('No query results for model', $mensaje);
        $this->assertStringContainsString('credencial SSH de tipo "vps"', $mensaje);
        $this->assertStringContainsString('Credenciales SSH', $mensaje);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHOWN Y COMPOSER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * El chown a los usuarios de CloudPanel (§F6 del informe de migración): se emite en VPS y NO en
     * compartido, donde el usuario SSH ya es el dueño de todo.
     *
     * Sin él, php-fpm corre como el usuario del sitio contra archivos de root y el cliente no puede
     * escribir en storage/. No lo denuncia ninguna verificación: los archivos están, no son suyos.
     */
    public function test_el_chown_se_emite_en_vps_y_no_en_shared(): void
    {
        $datos    = $this->cliente_en_vps('lacava');
        $usuario  = 'api-' . $datos['slug'];
        $api_path = '/home/' . $usuario . '/empresa-api';
        $vps      = new InstallationService($datos['installation']);

        /*
         * 🔴 El esperado se escribe con comillas SIMPLES literales, no con escapeshellarg().
         *
         * Hasta el 31/8/2026 este test usaba escapeshellarg() para armar el esperado, así que
         * comparaba el código contra sí mismo y pasaba en cualquier sistema — tapando justamente el
         * bug: escapeshellarg() escapa según el sistema donde corre PHP, y en el WAMP de esta
         * máquina emite comillas DOBLES, adentro de las cuales el `sh` del VPS expande `$` y
         * backticks. Con vps_path cargado a mano desde el CRUD, eso era ejecución de comandos como
         * root en el VPS. Lo encontró el chequeo de restricciones duras.
         *
         * Ahora el comando se arma con escape_remote_arg(), que emite POSIX siempre, y el esperado
         * es un literal: si alguien vuelve a escapeshellarg(), este test se pone rojo en Windows.
         */
        $this->assertSame(
            "chown -R '" . $usuario . ':' . $usuario . "' '" . $api_path . "' 2>&1",
            $this->invocar($vps, 'build_vps_chown_command', [$api_path])
        );

        /* Y ninguna comilla doble, que es la forma que toma el bug. */
        $this->assertStringNotContainsString(
            '"',
            (string) $this->invocar($vps, 'build_vps_chown_command', [$api_path])
        );

        $shared = new InstallationService($this->cliente_en_shared('colman')['installation']);
        $this->assertSame(
            '',
            $this->invocar(
                $shared,
                'build_vps_chown_command',
                ['domains/comerciocity.com/public_html/colman/api']
            )
        );
    }

    /**
     * El composer install del servidor del cliente sale con el envoltorio que le toca a cada hosting.
     *
     * En compartido, el comando pelado de siempre (`cd ... && ... 2>&1`). En VPS, envuelto en el
     * bash de login que carga el PATH — el mismo tratamiento que el VPS de builds tiene desde
     * siempre y que antes de U9 no recibía ningún servidor de cliente, porque el flag estaba en
     * `false` fijo.
     */
    public function test_composer_install_sale_con_el_flag_correcto_en_cada_hosting(): void
    {
        $datos_shared = $this->cliente_en_shared('colman');
        $shared       = new InstallationService($datos_shared['installation']);
        $api_path     = 'domains/comerciocity.com/public_html/' . $datos_shared['slug'] . '/api';
        $en_shared = $this->invocar($shared, 'build_hosting_composer_install_command', [$api_path]);

        $this->assertStringStartsWith('cd ' . escapeshellarg($api_path) . ' &&', $en_shared);
        $this->assertStringContainsString('--no-scripts', $en_shared);
        $this->assertStringNotContainsString('bash -', $en_shared);

        $datos_vps  = $this->cliente_en_vps('lacava');
        $vps        = new InstallationService($datos_vps['installation']);
        $api_en_vps = '/home/api-' . $datos_vps['slug'] . '/empresa-api';
        $en_vps     = $this->invocar($vps, 'build_hosting_composer_install_command', [$api_en_vps]);

        $this->assertStringStartsWith('bash -', $en_vps);
        $this->assertStringContainsString($api_en_vps, $en_vps);
        $this->assertStringContainsString('--no-scripts', $en_vps);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Cliente con sus dos ClientApi en hosting compartido y una instalación real sobre la primera.
     *
     * @param  string  $slug
     * @return array<string, mixed>
     */
    private function cliente_en_shared(string $slug): array
    {
        /* Sufijo para no chocar con lo que ya viva en admin_testing_s6. */
        $slug = $slug . strtolower(Str::random(6));

        $this->crear_credencial_shared();

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
            'client_id'     => $client->id,
            'client_api_id' => $api1->id,
            'version_id'    => $this->crear_version_publicada()->id,
            'kind'          => ClientInstallation::KIND_COMPLETA,
            'status'        => 'pendiente',
        ]);

        return [
            'slug'         => $slug,
            'client'       => $client,
            'api1'         => $api1,
            'api2'         => $api2,
            'installation' => $installation,
        ];
    }

    /**
     * El mismo cliente, con las dos ClientApi ya en VPS: el estado en el que las deja provision_check.
     *
     * @param  string  $slug
     * @return array<string, mixed>
     */
    private function cliente_en_vps(string $slug): array
    {
        $datos = $this->cliente_en_shared($slug);
        $this->crear_credencial_vps();

        /* Los mismos dos valores que escribe VpsSiteProvisioner::marcar_apis_como_vps(): la
         * instancia 1 lleva el slug pelado y la 2, el slug con el '2'. El campo path NO se toca,
         * igual que en producción. */
        $datos['api1']->hosting_type = 'vps';
        $datos['api1']->vps_path     = $datos['slug'];
        $datos['api1']->save();

        $datos['api2']->hosting_type = 'vps';
        $datos['api2']->vps_path     = $datos['slug'] . '2';
        $datos['api2']->save();

        $datos['installation'] = $datos['installation']->fresh();

        return $datos;
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
     * Salidas del VPS en el camino feliz del preflight y de la creación de los sitios.
     *
     * @param  string  $slug  Slug del cliente; con él se preparan los readlink de los dos docroots.
     * @return void
     */
    private function responder_como_un_vps_sano(string $slug = ''): void
    {
        if ($this->runner === null) {
            $this->runner = new RemoteCommandRunnerFake($this->crear_credencial_vps());
        }

        $this->runner->responder('command -v clpctl', '/usr/bin/clpctl');
        $this->runner->responder('command -v supervisorctl', '/usr/bin/supervisorctl');
        $this->runner->responder('command -v dig', '/usr/bin/dig');

        if ($slug === '') {
            return;
        }

        /* Los dos docroots de API quedan siendo el symlink que enlazar_docroot_de_api() verifica.
         *
         * 🔴 La aguja se arma con escapar_argumento() y NO con escapeshellarg(): la regla del fake
         * tiene que matchear el comando REAL, y el comando real sale con comillas simples corra
         * donde corra. Con escapeshellarg() acá, en Windows esta regla no matcheaba nada y el
         * readlink devolvía vacío. */
        foreach (['api-' . $slug, 'api-' . $slug . '2'] as $label) {
            $docroot = '/home/' . $label . '/htdocs/' . $label . '.comerciocity.com';
            $this->runner->responder(
                'readlink ' . RemoteCommandRunner::escapar_argumento($docroot),
                '/home/' . $label . '/empresa-api/public'
            );
        }
    }

    /**
     * @return void
     */
    private function crear_credencial_shared(): void
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
     * Invoca un método privado del servicio por reflexión.
     *
     * Es la única forma de fijar estas rutas y estos comandos sin un servidor del otro lado, que es
     * justamente lo que U9 no puede tener: es el mismo criterio con el que ya se prueban
     * build_skeleton_verify_command() y step_write_env().
     *
     * @param  object            $objeto
     * @param  string            $metodo
     * @param  array<int, mixed> $argumentos
     * @return mixed
     */
    private function invocar($objeto, string $metodo, array $argumentos = [])
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objeto, $argumentos);
    }

    /**
     * Lee una propiedad privada del servicio.
     *
     * @param  object  $objeto
     * @param  string  $nombre
     * @return mixed
     */
    private function propiedad($objeto, string $nombre)
    {
        $reflexion = new \ReflectionProperty($objeto, $nombre);
        $reflexion->setAccessible(true);

        return $reflexion->getValue($objeto);
    }

    /**
     * Corre un closure y devuelve el mensaje de la excepción, o '' si no lanzó.
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
}

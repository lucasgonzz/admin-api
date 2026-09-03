<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Models\VersionSeeder;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los tres defectos que voltearon la actualización de masquito (cliente 19) a 4.0.11 el 3/9/2026.
 *
 * Cada test de acá es el candado de uno de ellos. Los tres eran del MOTOR, no del dato: los datos
 * que los dispararon ya se corrigieron en la base del admin ese mismo día, pero nada impedía que
 * volvieran a entrar.
 *
 *  1. `db:seed --class=Database\Seeders\Xxx` viajaba crudo por SSH. El shell del hosting se come la
 *     barra invertida y del otro lado llega `--class=DatabaseSeedersXxx`: "Target class does not
 *     exist". Mató al upgrade 75 en el tercero de trece seeders, con las migraciones ya aplicadas.
 *
 *  2. `php artisan migrate` sin `--force`, con `APP_ENV=production`, esperó un `yes` por stdin que
 *     en una sesión SSH no llega nunca. El upgrade 76 se colgó 31 minutos hasta el timeout del job.
 *
 *  3. `step_update_default_version()` abortaba cuando faltaba `clients.api_key`, aunque el
 *     empresa-api del cliente no exige esa clave. El deployment terminaba en `completed` con los
 *     usuarios apuntando a la carpeta VIEJA. Silencioso, y con 20 de 44 clientes en esa condición.
 */
class PipelineDeDeployNoSeCuelgaTest extends TestCase
{
    use DatabaseTransactions;

    /** @var int */
    private $contador = 0;

    /**
     * 🔴 DEFECTO 1 — el `--class` sale escapado, así la barra invertida sobrevive al shell.
     *
     * @return void
     */
    public function test_el_seeder_con_namespace_viaja_escapado()
    {
        $upgrade = $this->crear_upgrade();
        $service = new DeploymentService($upgrade);

        $seeder               = new VersionSeeder();
        $seeder->seeder_class = 'Database\\Seeders\\ExtencionTrackingBuyersSeeder';

        $comando = (string) $this->invocar($service, 'get_seeder_command', [$seeder]);

        /*
         * Lo que importa no es la forma exacta del escapado sino que la barra invertida siga
         * estando: es lo único que el shell del hosting necesita para resolver la clase.
         */
        $this->assertStringContainsString(
            'Database\\Seeders\\ExtencionTrackingBuyersSeeder',
            $comando,
            'El namespace se perdió al armar el comando del seeder.'
        );
        $this->assertStringContainsString("'", $comando, 'El --class no quedó entrecomillado.');
        $this->assertStringContainsString('--force', $comando);
    }

    /**
     * El caso sano no se movió: un seeder sin namespace sigue saliendo igual de simple.
     *
     * @return void
     */
    public function test_el_seeder_sin_namespace_sigue_saliendo_igual()
    {
        $upgrade = $this->crear_upgrade();
        $service = new DeploymentService($upgrade);

        $seeder               = new VersionSeeder();
        $seeder->seeder_class = 'ExtencionAsistenteIaSeeder';

        $comando = (string) $this->invocar($service, 'get_seeder_command', [$seeder]);

        $this->assertStringContainsString('ExtencionAsistenteIaSeeder', $comando);
        $this->assertStringContainsString('--force', $comando);
    }

    /**
     * El `command` propio del seeder se respeta tal cual: es un comando escrito a mano, no un
     * argumento que estemos componiendo nosotros.
     *
     * @return void
     */
    public function test_el_command_propio_del_seeder_no_se_toca()
    {
        $upgrade = $this->crear_upgrade();
        $service = new DeploymentService($upgrade);

        $seeder               = new VersionSeeder();
        $seeder->seeder_class = 'NoSeUsa';
        $seeder->command      = 'php artisan cosa:propia --force';

        $this->assertSame(
            'php artisan cosa:propia --force',
            (string) $this->invocar($service, 'get_seeder_command', [$seeder])
        );
    }

    /**
     * 🔴 DEFECTO 2 — todo comando remoto se ejecuta con stdin cerrado.
     *
     * El envoltorio tiene que cubrir la CADENA entera. Un `cd X && artisan` con la redirección
     * pegada al final solo se la aplica al último eslabón, que es donde estaba el agujero.
     *
     * @return void
     */
    public function test_el_comando_remoto_se_ejecuta_con_stdin_cerrado()
    {
        $upgrade = $this->crear_upgrade();
        $service = new DeploymentService($upgrade);

        $envuelto = (string) $this->invocar(
            $service,
            'con_stdin_cerrado',
            ['cd /home/cliente/api && php artisan migrate']
        );

        $this->assertStringContainsString('< /dev/null', $envuelto);
        $this->assertStringStartsWith('{', $envuelto, 'La redirección no cubre la cadena entera.');
        $this->assertStringEndsWith('< /dev/null', $envuelto);
        $this->assertStringContainsString('cd /home/cliente/api && php artisan migrate', $envuelto);
    }

    /**
     * Un comando vacío no se envuelve: envolverlo produciría `{ ; } < /dev/null`, que es un error
     * de sintaxis del shell y convertiría un no-op en un fallo.
     *
     * @return void
     */
    public function test_un_comando_vacio_no_se_envuelve()
    {
        $upgrade = $this->crear_upgrade();
        $service = new DeploymentService($upgrade);

        $this->assertSame('', (string) $this->invocar($service, 'con_stdin_cerrado', ['']));
    }

    /**
     * Cuando un comando muere por pedir confirmación, el error lo dice.
     *
     * Sin esto el fallo es un "exit 1" mudo que manda a buscar el problema en la base del cliente,
     * cuando lo que falta es un `--force` en el `version_command`.
     *
     * @return void
     */
    public function test_el_error_nombra_la_confirmacion_cuando_fue_esa_la_causa()
    {
        $upgrade = $this->crear_upgrade();
        $service = new DeploymentService($upgrade);

        $salida_real = "**************************************\n"
            . "*     Application In Production!     *\n"
            . "**************************************\n\n"
            . " Do you really wish to run this command? (yes/no) [no]:\n"
            . "Command Cancelled!\n";

        $diagnostico = (string) $this->invocar($service, 'diagnostico_de_confirmacion', [$salida_real]);

        $this->assertStringContainsString('--force', $diagnostico);
        $this->assertNotSame('', $diagnostico);
    }

    /**
     * Y no ensucia los errores que no son de confirmación.
     *
     * @return void
     */
    public function test_el_diagnostico_no_aparece_en_otros_errores()
    {
        $upgrade = $this->crear_upgrade();
        $service = new DeploymentService($upgrade);

        $this->assertSame(
            '',
            (string) $this->invocar($service, 'diagnostico_de_confirmacion', ['SQLSTATE[HY000]: algo'])
        );
    }

    /**
     * 🔴 DEFECTO 3, y el más caro de los tres — sin `api_key` el PUT SE HACE IGUAL.
     *
     * El empresa-api solo exige el header si tiene `ADMIN_SYNC_REQUIRE_API_KEY=true`, y ninguno de
     * los 97 .env del shared hosting la define. Abortar antes de intentar dejaba a los usuarios del
     * cliente apuntando a la carpeta vieja con el deployment diciendo `completed`.
     *
     * @return void
     */
    public function test_sin_api_key_el_put_se_intenta_igual_y_no_degrada_a_manual()
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'users_updated' => 5], 200),
        ]);

        $upgrade = $this->crear_upgrade();

        $client          = $upgrade->client;
        $client->api_key = null;
        $client->save();

        $service = new DeploymentService($upgrade->fresh());
        $this->invocar($service, 'step_update_default_version');

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('X-Admin-Api-Key');
        });

        $upgrade->refresh();
        $this->assertSame(
            'success',
            $upgrade->default_version_sync_status,
            'Sin api_key el paso degradó a manual en vez de intentar el PUT.'
        );
        $this->assertNull($upgrade->default_version_sync_message);
    }

    /**
     * Con `api_key` cargada el comportamiento es el de siempre: el header viaja.
     *
     * @return void
     */
    public function test_con_api_key_el_header_viaja_como_siempre()
    {
        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        $upgrade = $this->crear_upgrade();

        $client          = $upgrade->client;
        $client->api_key = 'clave-del-cliente';
        $client->save();

        $service = new DeploymentService($upgrade->fresh());
        $this->invocar($service, 'step_update_default_version');

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Admin-Api-Key', 'clave-del-cliente');
        });

        $upgrade->refresh();
        $this->assertSame('success', $upgrade->default_version_sync_status);
    }

    /**
     * Un 401 SÍ degrada a manual, y el mensaje distingue las dos causas.
     *
     * Sin api_key cargada, un 401 significa que esa instancia sí exige la clave — que es
     * información accionable, distinta de "la clave no coincide".
     *
     * @return void
     */
    public function test_un_401_sin_api_key_degrada_a_manual_diciendo_la_causa_real()
    {
        Http::fake([
            '*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $upgrade = $this->crear_upgrade();

        $client          = $upgrade->client;
        $client->api_key = null;
        $client->save();

        $service = new DeploymentService($upgrade->fresh());
        $this->invocar($service, 'step_update_default_version');

        $upgrade->refresh();
        $this->assertSame('manual_required', $upgrade->default_version_sync_status);
        $this->assertStringContainsString(
            'ADMIN_SYNC_REQUIRE_API_KEY',
            (string) $upgrade->default_version_sync_message,
            'El mensaje del 401 no nombra la causa real para un cliente sin api_key.'
        );
    }

    /**
     * Y con api_key cargada, el mismo 401 dice la otra causa: la clave no coincide.
     *
     * @return void
     */
    public function test_un_401_con_api_key_dice_que_la_clave_no_coincide()
    {
        Http::fake([
            '*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $upgrade = $this->crear_upgrade();

        $client          = $upgrade->client;
        $client->api_key = 'clave-que-no-coincide';
        $client->save();

        $service = new DeploymentService($upgrade->fresh());
        $this->invocar($service, 'step_update_default_version');

        $upgrade->refresh();
        $this->assertSame('manual_required', $upgrade->default_version_sync_status);
        $this->assertStringContainsString(
            'no coincide',
            (string) $upgrade->default_version_sync_message
        );
    }

    /**
     * 🔴 Un 200 que NO viene del endpoint no se puede marcar como éxito.
     *
     * Lo levantó la verificación independiente. Si la `ClientApi.url` apunta a la carpeta del SPA
     * en vez de a la del API, el `.htaccess` de fallback del SPA contesta 200 con el HTML del
     * index para cualquier path. Sellar eso como `success` deja `users.default_version` intacto y
     * el panel diciendo que está todo bien: el mismo bug silencioso que este paso vino a arreglar,
     * pero disfrazado de éxito.
     *
     * @return void
     */
    public function test_un_200_que_no_es_del_endpoint_no_cuenta_como_exito()
    {
        Http::fake([
            '*' => Http::response('<!DOCTYPE html><html><head><title>App</title></head></html>', 200),
        ]);

        $upgrade = $this->crear_upgrade();
        $service = new DeploymentService($upgrade->fresh());
        $this->invocar($service, 'step_update_default_version');

        $upgrade->refresh();
        $this->assertSame(
            'manual_required',
            $upgrade->default_version_sync_status,
            'Un 200 con HTML del SPA se marcó como éxito: el cambio nunca se aplicó.'
        );
        $this->assertStringContainsString(
            '/public',
            (string) $upgrade->default_version_sync_message,
            'El mensaje no orienta sobre la causa más probable (la URL sin /public).'
        );
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * @param  object  $objeto
     * @param  string  $metodo
     * @param  array   $argumentos
     * @return mixed
     */
    private function invocar($objeto, string $metodo, array $argumentos = [])
    {
        $reflexion = new \ReflectionMethod($objeto, $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objeto, $argumentos);
    }

    /**
     * @return ClientVersionUpgrade
     */
    private function crear_upgrade()
    {
        $this->contador += 2;

        $credencial = ClientSshCredential::where('type', 'shared_hosting')->first();
        if ($credencial === null) {
            $credencial       = new ClientSshCredential();
            $credencial->type = 'shared_hosting';
        }
        $credencial->host     = '198.51.100.10';
        $credencial->port     = 22;
        $credencial->username = 'usuario-de-prueba';
        $credencial->password = 'secreto';
        $credencial->save();

        $from = $this->crear_version('9.' . $this->contador . '.0');
        $to   = $this->crear_version('9.' . $this->contador . '.1');

        $client                     = new Client();
        $client->name               = 'Cliente pipeline';
        $client->company_name       = 'Empresa pipeline';
        $client->slug               = 'cliente-pipeline-' . Str::random(8);
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api';
        $client->inbound_api_key    = 'clave-inbound';
        $client->is_active          = true;
        $client->user_id            = 2700;
        $client->current_version_id = $from->id;
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-pipeline.ejemplo.test';
        $api->path         = 'pipeline' . $this->contador . '/api';
        $api->spa_url      = 'https://pipeline.ejemplo.test';
        $api->hosting_type = 'shared_hosting';
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
}

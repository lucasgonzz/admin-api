<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarInstalacionDeImplementacionJob;
use App\Models\Client;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\EnvTemplate;
use App\Models\Implementation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Que ni la instalación ni el alta arranquen sobre un cliente que YA tiene un sistema andando.
 *
 * `install` crea los subdominios, la base y el `.env` de un cliente, y reinstalar sobre un negocio que opera
 * le pisa el `.env` y la base: el chequeo `instalaciones_previas` solo mira las instalaciones que hizo ESTE
 * camino, y un cliente instalado a mano, por `/instalar-cliente` o por el panel no tiene ninguna. Lo que sí
 * deja un sistema vivo es su historial de actualizaciones (`client_version_upgrades`): un sistema al que se
 * le actualizó la versión es un sistema que ya estuvo en producción.
 *
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 `install`: el chequeo `sin_sistema_vivo` falla si el cliente tiene CUALQUIER fila en
 *     `client_version_upgrades` (en el dry-run sale en false y el real es 422 sin crear ni encolar nada).
 *  2. 🔴 El alta sobre un cliente que ya existe (`client_id`, o un lead ya promovido) con un sistema vivo es
 *     422: no se arranca una implementación sobre un negocio que opera.
 *  3. Como señal ADICIONAL e informativa, el dry-run de `install` consulta `<api activa>/api/version-activa`
 *     (5 s de techo) y dice si el sistema responde con una `default_version`. Informa, no bloquea, y no corre
 *     en el real: una API que no responde es lo normal en un cliente sin instalar.
 */
class SistemaVivoEnLaInstalacionYEnElAltaPorClaudeTest extends BaseDeImplementaciones
{
    /** Cómo se llama el endpoint que dice qué versión tiene activa un sistema. */
    const RUTA_DE_LA_VERSION_ACTIVA = '/api/version-activa';

    /**
     * Sin credenciales ni plantillas heredadas, y sin red: el dry-run de `install` consulta la API del
     * cliente, y un test no sale a internet.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hostinger.api_token' => 'token-de-prueba']);

        EnvTemplate::query()->delete();
        foreach (['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => null, 'DB_USERNAME' => null, 'DB_PASSWORD' => null] as $clave => $valor) {
            EnvTemplate::create(['key' => $clave, 'value' => $valor, 'group' => 'db', 'scope' => 'empresa', 'is_manual_on_create' => true]);
        }

        ClientSshCredential::query()->delete();
        $credencial           = new ClientSshCredential();
        $credencial->type     = 'shared_hosting';
        $credencial->host     = 'compartido.ejemplo.test';
        $credencial->port     = 65002;
        $credencial->username = 'deploy';
        $credencial->password = 'secreta';
        $credencial->save();

        Http::fake(['*' => Http::response([], 404)]);
    }

    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Una implementación LISTA PARA INSTALAR: etapa 2, formulario enviado, las dos APIs y una versión
     * publicada.
     *
     * @param string $slug El subdominio del cliente (distinto en cada vuelta de un test que arma varios).
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(string $slug = 'panchito'): array
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), $slug);

        $implementacion = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);
        $implementacion->form_submitted_at = now();
        $implementacion->save();

        $this->crear_version();

        return ['cliente' => $cliente, 'implementacion' => $implementacion->refresh()];
    }

    /**
     * Una actualización registrada del cliente (lo que deja un sistema que ya anduvo).
     *
     * @param Client $cliente El cliente.
     * @param string $estado  Estado de la actualización.
     *
     * @return ClientVersionUpgrade
     */
    private function crear_una_actualizacion(Client $cliente, string $estado = 'terminada'): ClientVersionUpgrade
    {
        return ClientVersionUpgrade::create([
            'client_id'     => $cliente->id,
            'to_version_id' => $this->crear_version()->id,
            'status'        => $estado,
        ]);
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
     * El chequeo con ese nombre de la respuesta.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta La respuesta.
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
     | install
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. Sin actualizaciones registradas el chequeo pasa, y con todo lo demás en orden el dry-run está listo.
     *
     * @return void
     */
    public function test_sin_actualizaciones_el_chequeo_pasa(): void
    {
        $e = $this->escenario();

        $respuesta = $this->instalar($e['implementacion'], []);

        $respuesta->assertStatus(200);
        $this->assertTrue($this->chequeo($respuesta, 'sin_sistema_vivo')['ok']);
        $respuesta->assertJsonPath('listo', true);
        $this->assertCount(9, $respuesta->json('chequeos'));
    }

    /**
     * 1. 🔴 Con una actualización registrada —en el estado que sea— el cliente tiene un sistema vivo: el
     * chequeo sale en false con el motivo, el dry-run no está listo y el real es 422 sin crear ni encolar nada.
     *
     * @return void
     */
    public function test_con_una_actualizacion_registrada_el_cliente_tiene_un_sistema_vivo(): void
    {
        foreach (['terminada', 'pendiente', 'fallida'] as $vuelta => $estado) {
            Queue::fake();
            $e = $this->escenario('panchito' . $vuelta);
            $this->crear_una_actualizacion($e['cliente'], $estado);

            $simulacion = $this->instalar($e['implementacion'], []);
            $simulacion->assertStatus(200);
            $simulacion->assertJsonPath('listo', false);
            $chequeo = $this->chequeo($simulacion, 'sin_sistema_vivo');
            $this->assertFalse($chequeo['ok'], 'Una actualización en estado ' . $estado . ' tendría que contar como sistema vivo.');
            $this->assertStringContainsString('client_version_upgrades', $chequeo['detalle']);
            $this->assertStringContainsString('1 actualización', $chequeo['detalle']);

            $real = $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);
            $real->assertStatus(422);
            $this->assertStringContainsString('sin_sistema_vivo', $this->cuerpo($real));
            $this->assertSame(0, ClientInstallation::where('client_id', $e['cliente']->id)->count(), 'Creó instalaciones sobre un sistema vivo.');

            /* Se mira el job de la instalación y no "nada": crear una actualización `terminada` ya encola el aviso
               al dueño por su cuenta (hook `saved` de ClientVersionUpgrade). */
            Queue::assertNotPushed(EjecutarInstalacionDeImplementacionJob::class);
        }
    }

    /**
     * 1. Las actualizaciones de OTRO cliente no cuentan.
     *
     * @return void
     */
    public function test_las_actualizaciones_de_otro_cliente_no_cuentan(): void
    {
        $e    = $this->escenario();
        $otro = $this->crear_cliente('Otro Negocio');
        $this->crear_una_actualizacion($otro);

        $this->assertTrue($this->chequeo($this->instalar($e['implementacion'], []), 'sin_sistema_vivo')['ok']);
    }

    /**
     * 3. Como señal informativa, el dry-run consulta `<api activa>/api/version-activa` con 5 s de techo y
     * cuenta qué contestó: si responde 200 con una `default_version` hay un sistema andando ahí y se avisa,
     * pero NO bloquea (el chequeo de la base sigue en true).
     *
     * @return void
     */
    public function test_el_dry_run_informa_si_el_sistema_responde_con_una_version(): void
    {
        $e        = $this->escenario();
        $llamadas = new \stdClass();
        $llamadas->pedidos = [];

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
        Http::fake(function ($request, $opciones) use ($llamadas) {
            $llamadas->pedidos[] = ['metodo' => $request->method(), 'url' => $request->url(), 'techo' => isset($opciones['timeout']) ? $opciones['timeout'] : null];

            return Http::response(['default_version' => '4.3.6'], 200);
        });

        $respuesta = $this->instalar($e['implementacion'], []);

        $respuesta->assertStatus(200);
        $this->assertCount(1, $llamadas->pedidos);
        $this->assertSame('GET', $llamadas->pedidos[0]['metodo']);
        $this->assertStringEndsWith(self::RUTA_DE_LA_VERSION_ACTIVA, $llamadas->pedidos[0]['url']);
        $this->assertStringContainsString('api-panchito.ejemplo.test', $llamadas->pedidos[0]['url']);
        $this->assertSame(5, $llamadas->pedidos[0]['techo']);

        $respuesta->assertJsonPath('senales.version_activa.consultado', true);
        $respuesta->assertJsonPath('senales.version_activa.status', 200);
        $respuesta->assertJsonPath('senales.version_activa.default_version', '4.3.6');
        $respuesta->assertJsonPath('senales.version_activa.responde_con_version', true);
        $this->assertStringContainsString('ya hay un sistema andando', implode(' ', $respuesta->json('avisos')));

        /* Informa, no bloquea: el chequeo de la base sigue en true y el dry-run sigue listo. */
        $this->assertTrue($this->chequeo($respuesta, 'sin_sistema_vivo')['ok']);
        $respuesta->assertJsonPath('listo', true);
    }

    /**
     * 3. Una API que no responde (404, o una excepción de conexión) es lo normal en un cliente sin instalar:
     * se cuenta en la señal, sin aviso, y no rompe el dry-run.
     *
     * @return void
     */
    public function test_una_api_que_no_responde_no_rompe_el_dry_run(): void
    {
        $e = $this->escenario();

        $con_404 = $this->instalar($e['implementacion'], []);
        $con_404->assertStatus(200);
        $con_404->assertJsonPath('senales.version_activa.status', 404);
        $con_404->assertJsonPath('senales.version_activa.responde_con_version', false);
        $this->assertSame([], $con_404->json('avisos'));

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
        Http::fake(function () {
            throw new ConnectionException('cURL error 6: Could not resolve host');
        });

        $sin_dns = $this->instalar($e['implementacion'], []);
        $sin_dns->assertStatus(200);
        $sin_dns->assertJsonPath('senales.version_activa.consultado', true);
        $sin_dns->assertJsonPath('senales.version_activa.status', null);
        $sin_dns->assertJsonPath('senales.version_activa.responde_con_version', false);
        $this->assertStringContainsString('Could not resolve host', $sin_dns->json('senales.version_activa.error'));
        $sin_dns->assertJsonPath('listo', true);
    }

    /**
     * 3. Un 200 sin `default_version` (cualquier cosa que no sea el endpoint de la versión) no cuenta como
     * un sistema andando.
     *
     * @return void
     */
    public function test_un_200_sin_default_version_no_cuenta(): void
    {
        $e = $this->escenario();

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $respuesta = $this->instalar($e['implementacion'], []);

        $respuesta->assertJsonPath('senales.version_activa.status', 200);
        $respuesta->assertJsonPath('senales.version_activa.responde_con_version', false);
        $this->assertSame([], $respuesta->json('avisos'));
    }

    /**
     * 3. El real NO consulta la API: la señal es del dry-run, y una consulta de 5 s adentro de un camino que
     * escribe no aporta nada que los chequeos de la base no digan.
     *
     * @return void
     */
    public function test_el_real_no_consulta_la_api(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $this->instalar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);

        Http::assertNothingSent();
        Queue::assertPushed(EjecutarInstalacionDeImplementacionJob::class, 1);
    }

    /* ------------------------------------------------------------------------------------------
     | El alta
     |----------------------------------------------------------------------------------------- */

    /**
     * El POST del alta.
     *
     * @param array<string, mixed> $cuerpo Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function alta(array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations', $cuerpo, $this->headers());
    }

    /**
     * 2. 🔴 El alta con `client_id` de un cliente con un sistema vivo es 422 en el real y `listo:false` con el
     * bloqueo en el dry-run: no se arranca una implementación sobre un negocio que opera. No crea nada.
     *
     * @return void
     */
    public function test_el_alta_sobre_un_cliente_con_sistema_vivo_es_422(): void
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), 'panchito');
        $this->crear_una_actualizacion($cliente);

        $simulacion = $this->alta(['client_id' => $cliente->id]);
        $simulacion->assertStatus(200);
        $simulacion->assertJsonPath('listo', false);
        $this->assertStringContainsString('sistema en producción', implode(' ', $simulacion->json('bloqueos')));

        $real = $this->alta(['client_id' => $cliente->id, 'dry_run' => false, 'confirm_nombre' => 'Negocio de Panchito Gómez']);
        $real->assertStatus(422);
        $this->assertStringContainsString('sistema en producción', $real->json('error'));
        $this->assertStringContainsString('No se creó nada', $real->json('error'));
        $this->assertSame(0, Implementation::where('client_id', $cliente->id)->count());
    }

    /**
     * 2. Lo mismo con un LEAD ya promovido a ese cliente: el cliente es el mismo.
     *
     * @return void
     */
    public function test_el_alta_de_un_lead_ya_promovido_a_un_cliente_con_sistema_vivo_es_422(): void
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), 'panchito');
        $this->crear_una_actualizacion($cliente);
        $lead = $this->crear_lead(['company_name' => 'Almacén Rosa']);
        $lead->promoted_client_id = $cliente->id;
        $lead->save();

        /* Con el lead ya promovido el alta es sobre SU cliente: el nombre que se confirma es el del cliente. */
        $real = $this->alta(['lead_id' => $lead->id, 'dry_run' => false, 'confirm_nombre' => 'Negocio de Panchito Gómez']);

        $real->assertStatus(422);
        $this->assertStringContainsString('sistema en producción', $real->json('error'));
        $this->assertSame(0, Implementation::where('client_id', $cliente->id)->count());
    }

    /**
     * 2. Un cliente existente SIN sistema vivo sigue pudiendo arrancar su implementación.
     *
     * @return void
     */
    public function test_un_cliente_sin_sistema_vivo_arranca_su_implementacion(): void
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), 'panchito');

        $this->alta(['client_id' => $cliente->id, 'dry_run' => false, 'confirm_nombre' => 'Negocio de Panchito Gómez'])->assertStatus(201);
    }
}

<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarInstalacionDeImplementacionJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\EnvTemplate;
use App\Models\Implementation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Una instalación que quedó en `instalando` para siempre: cómo se ve y cómo se destraba.
 *
 * El pipeline marca la fila `instalando` al arrancar y `completada` o `fallida` al terminar. Si el worker
 * muere sin pasar por ninguna de las dos (un `kill -9`, un reinicio del servidor, un `$timeout` sin `pcntl`)
 * la fila se queda en `instalando` sin que nada la termine, y como `install` frena con 409 mientras haya una
 * instalando, ese cliente no podría volver a instalar nunca: la única salida era tocar la base a mano.
 *
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 Que `install` NUNCA pise un pipeline vivo: una instalando con actividad reciente es 409 como siempre,
 *     y `marcar_colgadas` solo actúa si TODAS las instalando del cliente llevan más de 60 minutos sin
 *     actividad. Con UNA viva no se marca ninguna.
 *  2. Que el estado (GET) diga cuáles están colgadas (`colgada: true`), para que la skill no tenga que
 *     adivinar: "sin actividad" es no tener logs nuevos ni haber tocado la fila en más de 60 minutos.
 *  3. Que lo marcado quede como `fallida` con el motivo "colgada: sin actividad desde <fecha>" (queda de
 *     historial, igual que una fallida de verdad) y que se siga con un par nuevo.
 *  4. Que el parámetro sea de la lista blanca y del catálogo, y que no abra una puerta: no salta ningún otro
 *     chequeo ni instala sobre un sistema que ya está instalado.
 */
class InstalacionColgadaPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * Sin credenciales ni plantillas heredadas, sin red (el dry-run de `install` consulta la API del cliente) y
     * con el reloj congelado: "hace 59 minutos" y "hace 61" tienen que caer del lado que corresponde sin que
     * los segundos que tarda el test los muevan, y la fecha que dice el motivo no puede cambiar de minuto.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00'));

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

    /**
     * Suelta el reloj congelado.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Una implementación LISTA PARA INSTALAR (etapa 2, formulario enviado, las dos APIs, una versión publicada).
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(): array
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), 'panchito');

        $implementacion = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);
        $implementacion->form_submitted_at = now();
        $implementacion->save();

        $this->crear_version();

        return ['cliente' => $cliente, 'implementacion' => $implementacion->refresh()];
    }

    /**
     * Una instalación `instalando` cuya última actividad fue hace tantos minutos.
     *
     * Escribe `started_at` y `updated_at` por query para que Eloquent no los pise con la hora de ahora.
     *
     * @param Client      $cliente  El cliente.
     * @param string      $kind     completa | esqueleto.
     * @param int         $minutos  Hace cuántos minutos fue su última actividad (arranque y última escritura).
     * @param string      $estado   El estado de la fila.
     *
     * @return ClientInstallation
     */
    private function instalacion_hace(Client $cliente, string $kind, int $minutos, string $estado = 'instalando'): ClientInstallation
    {
        $api = $kind === 'completa'
            ? (int) $cliente->active_client_api_id
            : (int) ClientApi::where('client_id', $cliente->id)->where('id', '!=', $cliente->active_client_api_id)->value('id');

        $fila = $this->crear_instalacion($cliente, ['kind' => $kind, 'client_api_id' => $api, 'status' => $estado]);

        $cuando = now()->subMinutes($minutos)->toDateTimeString();
        DB::table('client_installations')->where('id', $fila->id)->update(['started_at' => $cuando, 'updated_at' => $cuando]);

        return $fila->refresh();
    }

    /**
     * Un renglón de log de una instalación, de hace tantos minutos.
     *
     * @param ClientInstallation $fila    La instalación.
     * @param int                $minutos Hace cuántos minutos se escribió.
     *
     * @return void
     */
    private function log_hace(ClientInstallation $fila, int $minutos): void
    {
        DB::table('deployment_logs')->insert([
            'client_installation_id' => $fila->id,
            'step'                   => 'upload_spa',
            'line'                   => 'subiendo el SPA',
            'level'                  => 'info',
            'created_at'             => now()->subMinutes($minutos)->toDateTimeString(),
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
     * El cuerpo de un real con la confirmación del nombre.
     *
     * @param array<string, mixed> $extra Lo que se suma.
     *
     * @return array<string, mixed>
     */
    private function real(array $extra = []): array
    {
        return array_merge(['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'], $extra);
    }

    /**
     * Las instalaciones del estado (GET), por id.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<int, array<string, mixed>>
     */
    private function instalaciones_del_estado(Implementation $implementation): array
    {
        $por_id = [];

        foreach ($this->getJson('/api/claude/implementations/' . $implementation->id, $this->headers())->json('instalaciones') as $fila) {
            $por_id[$fila['id']] = $fila;
        }

        return $por_id;
    }

    /* ------------------------------------------------------------------------------------------
     | El estado dice cuáles están colgadas
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. Una `instalando` sin actividad hace MÁS de 60 minutos sale `colgada: true` (con su última actividad y
     * una nota que dice qué hacer); con actividad reciente, o con 59 minutos, no.
     *
     * @return void
     */
    public function test_el_estado_marca_colgada_la_que_lleva_mas_de_60_minutos_sin_actividad(): void
    {
        $e = $this->escenario();

        $vieja   = $this->instalacion_hace($e['cliente'], 'completa', 61);
        $reciente = $this->instalacion_hace($e['cliente'], 'esqueleto', 59);

        $filas = $this->instalaciones_del_estado($e['implementacion']);

        $this->assertTrue($filas[$vieja->id]['colgada']);
        $this->assertNotNull($filas[$vieja->id]['ultima_actividad_at']);
        $this->assertStringContainsString('marcar_colgadas', $filas[$vieja->id]['nota']);
        $this->assertFalse($filas[$reciente->id]['colgada']);
        $this->assertArrayNotHasKey('nota', $filas[$reciente->id]);
    }

    /**
     * 2. 🔴 Un log reciente cuenta como actividad: una instalando arrancada hace 3 horas pero con un renglón de
     * log de hace 5 minutos está viva, no colgada.
     *
     * @return void
     */
    public function test_un_log_reciente_cuenta_como_actividad(): void
    {
        $e = $this->escenario();

        $con_log = $this->instalacion_hace($e['cliente'], 'completa', 180);
        $this->log_hace($con_log, 5);

        $sin_log = $this->instalacion_hace($e['cliente'], 'esqueleto', 180);
        $this->log_hace($sin_log, 120);

        $filas = $this->instalaciones_del_estado($e['implementacion']);

        $this->assertFalse($filas[$con_log->id]['colgada'], 'Una instalación con un log de hace 5 minutos está viva.');
        $this->assertTrue($filas[$sin_log->id]['colgada']);
    }

    /**
     * 2. Solo las `instalando` pueden estar colgadas: una completada o fallida vieja no.
     *
     * @return void
     */
    public function test_solo_las_instalando_pueden_estar_colgadas(): void
    {
        $e = $this->escenario();

        $completada = $this->instalacion_hace($e['cliente'], 'completa', 500, 'completada');
        $fallida    = $this->instalacion_hace($e['cliente'], 'esqueleto', 500, 'fallida');

        $filas = $this->instalaciones_del_estado($e['implementacion']);

        $this->assertFalse($filas[$completada->id]['colgada']);
        $this->assertFalse($filas[$fallida->id]['colgada']);
        $this->assertNull($filas[$completada->id]['ultima_actividad_at']);
    }

    /* ------------------------------------------------------------------------------------------
     | install: 409 como siempre, y marcar_colgadas
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. Sin `marcar_colgadas`, una instalando es 409 aunque esté colgada: lo que cambia es que el 409 lo dice
     * (`colgadas`, `todas_colgadas`) y nombra el parámetro. No toca nada.
     *
     * @return void
     */
    public function test_sin_el_parametro_una_colgada_sigue_siendo_409(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $a = $this->instalacion_hace($e['cliente'], 'completa', 90);
        $b = $this->instalacion_hace($e['cliente'], 'esqueleto', 90);

        $respuesta = $this->instalar($e['implementacion'], $this->real());

        $respuesta->assertStatus(409);
        $respuesta->assertJsonPath('todas_colgadas', true);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $respuesta->json('colgadas'));
        $this->assertStringContainsString('marcar_colgadas', $respuesta->json('ayuda'));
        $this->assertSame(['instalando', 'instalando'], ClientInstallation::whereIn('id', [$a->id, $b->id])->orderBy('id')->pluck('status')->all());
        Queue::assertNotPushed(EjecutarInstalacionDeImplementacionJob::class);
    }

    /**
     * 3. 🔴 Con `marcar_colgadas=true` y TODAS las instalando colgadas: se pasan a `fallida` con "colgada: sin
     * actividad desde <fecha>" y `finished_at`, se crea el par nuevo y se encola el job, todo en un solo
     * pedido (202).
     *
     * @return void
     */
    public function test_marcar_colgadas_las_pasa_a_fallida_y_sigue_con_un_par_nuevo(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $a = $this->instalacion_hace($e['cliente'], 'completa', 90);
        $b = $this->instalacion_hace($e['cliente'], 'esqueleto', 75);

        $respuesta = $this->instalar($e['implementacion'], $this->real(['marcar_colgadas' => true]));

        $respuesta->assertStatus(202);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $respuesta->json('colgadas_marcadas_como_fallidas'));

        foreach ([$a, $b] as $vieja) {
            $fila = $vieja->refresh();
            $this->assertSame('fallida', $fila->status);
            $this->assertStringStartsWith('colgada: sin actividad desde ', $fila->failure_reason);
            $this->assertNotNull($fila->finished_at);
        }

        /* La fecha del motivo es la de la última actividad de ESA fila (90 y 75 minutos atrás). */
        $this->assertStringContainsString(now()->subMinutes(90)->format('d/m/Y H:i'), $a->failure_reason);
        $this->assertStringContainsString(now()->subMinutes(75)->format('d/m/Y H:i'), $b->failure_reason);

        $nuevas = ClientInstallation::where('client_id', $e['cliente']->id)->whereNotIn('id', [$a->id, $b->id])->orderBy('id')->get();
        $this->assertSame(['completa', 'esqueleto'], $nuevas->pluck('kind')->all());
        $this->assertSame(['instalando', 'instalando'], $nuevas->pluck('status')->all());
        Queue::assertPushed(EjecutarInstalacionDeImplementacionJob::class, 1);
    }

    /**
     * 1. 🔴 Con UNA instalando viva (actividad reciente) y otra colgada NO se marca ninguna: es 409 como
     * siempre. Pisar un pipeline vivo es justo lo que el 409 existe para impedir.
     *
     * @return void
     */
    public function test_con_una_viva_no_se_marca_ninguna(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $colgada = $this->instalacion_hace($e['cliente'], 'completa', 90);
        $viva    = $this->instalacion_hace($e['cliente'], 'esqueleto', 10);

        $respuesta = $this->instalar($e['implementacion'], $this->real(['marcar_colgadas' => true]));

        $respuesta->assertStatus(409);
        $respuesta->assertJsonPath('todas_colgadas', false);
        $this->assertSame([$colgada->id], $respuesta->json('colgadas'));
        $this->assertSame('instalando', $colgada->refresh()->status, 'Marcó una colgada aunque había otra viva.');
        $this->assertSame('instalando', $viva->refresh()->status);
        $this->assertNull($colgada->failure_reason);
        Queue::assertNotPushed(EjecutarInstalacionDeImplementacionJob::class);
    }

    /**
     * 1. Una con un log de hace 2 minutos está viva aunque su fila sea de hace 3 horas: tampoco se marca.
     *
     * @return void
     */
    public function test_una_con_log_reciente_no_se_marca(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $fila = $this->instalacion_hace($e['cliente'], 'completa', 180);
        $this->log_hace($fila, 2);

        $this->instalar($e['implementacion'], $this->real(['marcar_colgadas' => true]))->assertStatus(409);

        $this->assertSame('instalando', $fila->refresh()->status);
    }

    /**
     * 4. 🔴 `marcar_colgadas` no salta ningún otro chequeo: si falta lo demás (acá, el token de Hostinger) el
     * real es 422 y las colgadas quedan EXACTAMENTE como estaban —no se marca nada de una instalación que
     * igual no va a arrancar—.
     *
     * @return void
     */
    public function test_si_falla_otro_chequeo_no_se_marca_nada(): void
    {
        Queue::fake();
        config(['services.hostinger.api_token' => '']);
        $e = $this->escenario();
        $colgada = $this->instalacion_hace($e['cliente'], 'completa', 90);

        $respuesta = $this->instalar($e['implementacion'], $this->real(['marcar_colgadas' => true]));

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('token_de_hostinger', $this->cuerpo($respuesta));
        $this->assertSame('instalando', $colgada->refresh()->status);
        $this->assertNull($colgada->failure_reason);
        Queue::assertNotPushed(EjecutarInstalacionDeImplementacionJob::class);
    }

    /**
     * 4. Con la instalación real YA completada y un esqueleto colgado, `marcar_colgadas` no abre la puerta a
     * reinstalar: sigue siendo 409 (una instalando manda sobre una completada, como siempre), el esqueleto no se
     * toca, no se encola nada y el motivo dice que el sistema ya está instalado.
     *
     * @return void
     */
    public function test_no_abre_la_puerta_a_reinstalar_un_sistema_ya_instalado(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->instalacion_hace($e['cliente'], 'completa', 500, 'completada');
        $esqueleto = $this->instalacion_hace($e['cliente'], 'esqueleto', 90);

        $respuesta = $this->instalar($e['implementacion'], $this->real(['marcar_colgadas' => true]));

        $respuesta->assertStatus(409);
        $this->assertStringContainsString('ya está instalado', $this->cuerpo($respuesta));
        $this->assertSame('instalando', $esqueleto->refresh()->status);
        $this->assertNull($esqueleto->failure_reason);
        Queue::assertNotPushed(EjecutarInstalacionDeImplementacionJob::class);
    }

    /**
     * Sin ninguna instalando el parámetro no hace nada: el pedido sigue su camino normal.
     *
     * @return void
     */
    public function test_sin_instalando_el_parametro_no_hace_nada(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $fallida = $this->instalacion_hace($e['cliente'], 'completa', 500, 'fallida');

        $respuesta = $this->instalar($e['implementacion'], $this->real(['marcar_colgadas' => true]));

        $respuesta->assertStatus(202);
        $this->assertSame([], $respuesta->json('colgadas_marcadas_como_fallidas'));
        $this->assertSame('fallida', $fallida->refresh()->status);
        $this->assertNull($fallida->failure_reason);
    }

    /* ------------------------------------------------------------------------------------------
     | El dry-run
     |----------------------------------------------------------------------------------------- */

    /**
     * El dry-run lo dice: sin el parámetro, las colgadas dejan el chequeo `instalaciones_previas` en false
     * con una explicación que nombra `marcar_colgadas`; con el parámetro, el chequeo pasa y `se_crearia` dice
     * que las marcaría como fallidas. No escribe nada en ningún caso.
     *
     * @return void
     */
    public function test_el_dry_run_dice_que_haria(): void
    {
        $e = $this->escenario();
        $a = $this->instalacion_hace($e['cliente'], 'completa', 90);
        $b = $this->instalacion_hace($e['cliente'], 'esqueleto', 90);

        $sin = $this->instalar($e['implementacion'], []);
        $sin->assertStatus(200);
        $sin->assertJsonPath('listo', false);
        $previas = collect($sin->json('chequeos'))->firstWhere('chequeo', 'instalaciones_previas');
        $this->assertFalse($previas['ok']);
        $this->assertStringContainsString('marcar_colgadas=true', $previas['detalle']);
        $sin->assertJsonPath('todas_colgadas', true);

        $con = $this->instalar($e['implementacion'], ['marcar_colgadas' => true]);
        $con->assertStatus(200);
        $con->assertJsonPath('listo', true);
        $con->assertJsonPath('se_crearia.modo', 'marca_las_colgadas_como_fallidas_y_crea_el_par');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $con->json('se_crearia.colgadas_que_marcaria_como_fallidas'));

        $this->assertSame(['instalando', 'instalando'], ClientInstallation::whereIn('id', [$a->id, $b->id])->orderBy('id')->pluck('status')->all());
        $this->assertSame(2, ClientInstallation::where('client_id', $e['cliente']->id)->count(), 'El dry-run creó instalaciones.');
    }

    /* ------------------------------------------------------------------------------------------
     | La lista blanca
     |----------------------------------------------------------------------------------------- */

    /**
     * 4. `marcar_colgadas` es de la lista blanca (no es un parámetro "de más"), tiene que ser booleano y no
     * se acepta ninguna variante de "forzar".
     *
     * @return void
     */
    public function test_la_lista_blanca_y_el_tipo(): void
    {
        $e = $this->escenario();

        $de_mas = $this->instalar($e['implementacion'], ['forzar' => true]);
        $de_mas->assertStatus(422);
        $this->assertSame(['dry_run', 'confirm_client_name', 'marcar_colgadas'], $de_mas->json('parametros_aceptados'));

        $this->instalar($e['implementacion'], ['marcar_colgadas' => 'quizás'])->assertStatus(422);
        $this->instalar($e['implementacion'], ['marcar_colgadas' => true])->assertStatus(200);
    }
}

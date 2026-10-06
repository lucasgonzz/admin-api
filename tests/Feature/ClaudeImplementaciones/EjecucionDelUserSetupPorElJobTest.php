<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarUserSetupDeImplementacionJob;
use App\Models\Client;
use App\Models\Implementation;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * El job del user setup (`EjecutarUserSetupDeImplementacionJob`): su techo, su idempotencia y lo que dice
 * cuando algo sale mal. Es la parte de `POST claude/implementations/{id}/user-setup` que corre en la cola.
 *
 * 🔴🔴 Del otro lado el setup arranca con `migrate:fresh --force`: LE VACÍA LA BASE AL CLIENTE. Un job que
 * corre dos veces, o que corre cuando ya no le tocaba, es exactamente eso hecho de más. Lo que se protege,
 * en orden de importancia:
 *
 *  1. 🔴 Que el job NO sea re-ejecutable: lleva como token el `iniciado_at` del registro que lo despachó, y
 *     solo llama al cliente si el registro sigue diciendo `en_curso` con ESE token y el candado
 *     (`user_setup_executed_at`) está vacío. Un job viejo que arranca tarde (el worker lo recupera después
 *     del `retry_after`, o se despachó otro intento en el medio) se descarta solo, y lo dice en el log.
 *  2. 🔴 Que al terminar tampoco pise a un intento más nuevo ni a un candado que se llenó mientras tanto.
 *  3. Los techos: la llamada HTTP con 1200 s y el job con 1500, por debajo del `retry_after` (2400).
 *  4. Que cada error diga la verdad sobre si el setup PUDO HABER CORRIDO del otro lado: un 502/503/504/524
 *     (el proxy cortó pero el origen sigue), un timeout, o que el worker haya matado el job, no prueban que
 *     no corrió, y el motivo manda a mirar si el dueño existe en el sistema del cliente antes de reintentar.
 */
class EjecucionDelUserSetupPorElJobTest extends BaseDeImplementaciones
{
    /** El token (`iniciado_at`) del intento que se está corriendo. */
    const TOKEN = '2026-10-05T10:00:00.000000Z';

    /** URL de la empresa-api del cliente de prueba (la API activa). */
    const URL_API = 'https://api-panchito.ejemplo.test';

    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Una implementación con el user setup EN CURSO, como la deja el endpoint antes de despachar: etapa 2,
     * formulario enviado, las dos APIs y el registro con el token.
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario_en_curso(): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente->setup_data = ['company_name' => 'Panchito S.A.', 'email' => 'panchito@ejemplo.test', 'doc_number' => '20304050607'];
        $cliente->save();
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $implementacion = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);
        $implementacion->form_submitted_at = now();
        $implementacion->save();

        $this->escribir_data_de_la_etapa($implementacion, 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => self::TOKEN, 'terminado_at' => null, 'error' => null]]);

        return ['cliente' => $cliente, 'implementacion' => $implementacion->refresh()];
    }

    /**
     * El job de ese escenario, con el token de su registro (o con otro).
     *
     * @param array<string, mixed> $e     El escenario.
     * @param string               $token El token con el que se despachó.
     *
     * @return EjecutarUserSetupDeImplementacionJob
     */
    private function job(array $e, string $token = self::TOKEN): EjecutarUserSetupDeImplementacionJob
    {
        return new EjecutarUserSetupDeImplementacionJob($e['implementacion']->id, $token);
    }

    /**
     * El registro del user setup de la etapa 2.
     *
     * @param array<string, mixed> $e El escenario.
     *
     * @return array<string, mixed>
     */
    private function registro(array $e): array
    {
        $data = $this->data_de_la_etapa($e['implementacion']->refresh(), 2);

        return isset($data['user_setup']) ? $data['user_setup'] : [];
    }

    /**
     * Las llamadas HTTP que salieron mientras corre el job, con el techo de cada una.
     *
     * @param int         $status  Status con el que contesta el cliente.
     * @param callable|null $en_el_medio Algo que pasa "mientras" la llamada está en vuelo.
     *
     * @return \stdClass Con `urls` y `techos`.
     */
    private function falsear_al_cliente(int $status = 200, $en_el_medio = null): \stdClass
    {
        $llamadas         = new \stdClass();
        $llamadas->urls   = [];
        $llamadas->techos = [];

        /* Una fábrica nueva en cada llamada: con `Http::fake()` los stubs se ACUMULAN y gana el primero que
           matchea, así que un test que falsea varias veces (un status distinto por vuelta) se quedaría con
           la primera respuesta. */
        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());

        Http::fake(function ($request, $opciones) use ($llamadas, $status, $en_el_medio) {
            $llamadas->urls[]   = $request->url();
            $llamadas->techos[] = isset($opciones['timeout']) ? $opciones['timeout'] : null;

            if ($en_el_medio !== null) {
                $en_el_medio();
            }

            return Http::response(['ok' => $status < 400, 'detalle' => 'respuesta de prueba'], $status);
        });

        return $llamadas;
    }

    /* ------------------------------------------------------------------------------------------
     | Los techos
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. 🔴 La llamada HTTP va con 1200 s y el job con 1500: el job tiene que sobrevivir a su propia llamada
     * con margen para escribir el resultado, y los dos quedan por debajo del `retry_after` de la conexión
     * `database` (si lo superaran, el worker le entregaría el mismo job a otro mientras el primero sigue
     * corriendo).
     *
     * @return void
     */
    public function test_los_techos_son_1200_de_la_llamada_y_1500_del_job_por_debajo_del_retry_after(): void
    {
        $defaults    = (new \ReflectionClass(EjecutarUserSetupDeImplementacionJob::class))->getDefaultProperties();
        $retry_after = (int) config('queue.connections.database.retry_after');

        $this->assertSame(1, $defaults['tries'], 'Un segundo intento sería otro migrate:fresh.');
        $this->assertSame(1200, EjecutarUserSetupDeImplementacionJob::TIMEOUT_DE_LA_LLAMADA);
        $this->assertSame(1500, $defaults['timeout']);
        $this->assertLessThan($defaults['timeout'], EjecutarUserSetupDeImplementacionJob::TIMEOUT_DE_LA_LLAMADA);
        $this->assertLessThan($retry_after, $defaults['timeout']);
    }

    /**
     * 3. El POST a empresa-api sale con ese techo de verdad (no solo la constante).
     *
     * @return void
     */
    public function test_la_llamada_sale_con_1200_segundos_de_techo(): void
    {
        $e        = $this->escenario_en_curso();
        $llamadas = $this->falsear_al_cliente();

        $this->job($e)->handle();

        $this->assertSame([self::URL_API . '/public/api/admin-sync/user-setup'], $llamadas->urls, 'En hosting compartido el endpoint lleva /public.');
        $this->assertSame([1200], $llamadas->techos);
        $this->assertSame('ok', $this->registro($e)['estado']);
    }

    /* ------------------------------------------------------------------------------------------
     | Qué dice cada error sobre si el setup pudo haber corrido
     |----------------------------------------------------------------------------------------- */

    /**
     * 4. 🔴 Un 502, 503, 504 o 524 (el proxy o el balanceador cortaron la espera pero el origen puede seguir
     * corriendo el setup) NO prueban que no corrió: el registro lo marca (`puede_haber_corrido`) y el motivo
     * manda a mirar si el dueño existe en el sistema del cliente y sugiere conciliar.
     *
     * @return void
     */
    public function test_un_error_de_pasarela_pudo_haber_corrido(): void
    {
        foreach ([502, 503, 504, 524] as $status) {
            $e = $this->escenario_en_curso();
            $this->falsear_al_cliente($status);

            $this->job($e)->handle();

            $registro = $this->registro($e);
            $this->assertSame('error', $registro['estado'], 'status ' . $status);
            $this->assertStringContainsString('status ' . $status, $registro['error']);
            $this->assertTrue($registro['puede_haber_corrido'], 'El ' . $status . ' tiene que marcar que pudo haber corrido.');
            $this->assertStringContainsString('pudo haber seguido corriendo', $registro['error']);
            $this->assertStringContainsString('mirá si el dueño existe en el sistema del cliente', $registro['error']);
            $this->assertStringContainsString('conciliar', $registro['error']);
            $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at, 'Un error no llena el candado.');
        }
    }

    /**
     * 4. Un timeout de la llamada (error de conexión) es lo mismo: pudo haber corrido y se dice qué mirar.
     *
     * @return void
     */
    public function test_un_timeout_pudo_haber_corrido(): void
    {
        $e = $this->escenario_en_curso();
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 1200001 milliseconds');
        });

        $this->job($e)->handle();

        $registro = $this->registro($e);
        $this->assertSame('error', $registro['estado']);
        $this->assertTrue($registro['puede_haber_corrido']);
        $this->assertStringContainsString('Operation timed out', $registro['error']);
        $this->assertStringContainsString('ANTES de reintentar', $registro['error']);
        $this->assertStringContainsString('vaciar la base', $registro['error']);
        $this->assertStringContainsString('mirá si el dueño existe en el sistema del cliente', $registro['error']);
        $this->assertStringContainsString('conciliar', $registro['error']);
    }

    /**
     * 4. 🔴 Que el worker haya matado el job (`failed()`: el `$timeout`, un `SIGALRM`, un reintento que
     * `MaxAttemptsExceededException` descarta) tampoco prueba que no corrió: la llamada pudo estar en vuelo.
     *
     * @return void
     */
    public function test_failed_pudo_haber_corrido(): void
    {
        $e = $this->escenario_en_curso();

        $this->job($e)->failed(new \RuntimeException('timeout de 1500 s'));

        $registro = $this->registro($e);
        $this->assertSame('error', $registro['estado']);
        $this->assertTrue($registro['puede_haber_corrido']);
        $this->assertStringContainsString('El job se cortó antes de terminar: timeout de 1500 s', $registro['error']);
        $this->assertStringContainsString('mirá si el dueño existe en el sistema del cliente', $registro['error']);
        $this->assertStringContainsString('conciliar', $registro['error']);
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * 4. Un 4xx de empresa-api (no autorizado, no existe la ruta, validación) significa que el pedido se
     * rechazó antes de correr nada: `puede_haber_corrido` es false y el motivo lo dice. Es la única clase de
     * error de la que se sabe que NO corrió.
     *
     * @return void
     */
    public function test_un_rechazo_4xx_no_corrio(): void
    {
        foreach ([401, 404, 422] as $status) {
            $e = $this->escenario_en_curso();
            $this->falsear_al_cliente($status);

            $this->job($e)->handle();

            $registro = $this->registro($e);
            $this->assertSame('error', $registro['estado'], 'status ' . $status);
            $this->assertFalse($registro['puede_haber_corrido'], 'El ' . $status . ' se rechazó antes de correr.');
            $this->assertStringContainsString('no corrió', $registro['error']);
        }
    }

    /**
     * 4. Un 500 del otro lado es el caso difícil: el `migrate:fresh` ya pudo haber vaciado la base y el
     * sembrado haberse cortado a medias. Se marca como "pudo haber corrido" y el motivo dice que puede
     * haber quedado a medias.
     *
     * @return void
     */
    public function test_un_500_pudo_haber_corrido_a_medias(): void
    {
        $e = $this->escenario_en_curso();
        $this->falsear_al_cliente(500);

        $this->job($e)->handle();

        $registro = $this->registro($e);
        $this->assertTrue($registro['puede_haber_corrido']);
        $this->assertStringContainsString('a medias', $registro['error']);
    }

    /**
     * 4. Un 409 ("ya hay un setup corriendo del otro lado") no se reintenta, y tampoco prueba que no corrió.
     *
     * @return void
     */
    public function test_un_409_pudo_haber_corrido_y_no_se_reintenta(): void
    {
        $e = $this->escenario_en_curso();
        $this->falsear_al_cliente(409);

        $this->job($e)->handle();

        $registro = $this->registro($e);
        $this->assertTrue($registro['puede_haber_corrido']);
        $this->assertStringContainsString('NO se reintenta', $registro['error']);
    }

    /**
     * 4. Sin client_api activa el servicio ni siquiera llega a llamar: es un error de los que se sabe que
     * NO corrieron.
     *
     * @return void
     */
    public function test_sin_api_activa_no_corrio(): void
    {
        $e = $this->escenario_en_curso();
        $e['cliente']->active_client_api_id = null;
        $e['cliente']->save();
        Http::fake();

        $this->job($e)->handle();

        $registro = $this->registro($e);
        $this->assertSame('error', $registro['estado']);
        $this->assertFalse($registro['puede_haber_corrido']);
        Http::assertNothingSent();
    }

    /**
     * Un éxito no deja marca de "pudo haber corrido": corrió.
     *
     * @return void
     */
    public function test_un_exito_no_deja_la_marca_de_pudo_haber_corrido(): void
    {
        $e = $this->escenario_en_curso();
        $this->falsear_al_cliente();

        $this->job($e)->handle();

        $registro = $this->registro($e);
        $this->assertSame('ok', $registro['estado']);
        $this->assertNull($registro['error']);
        $this->assertArrayNotHasKey('puede_haber_corrido', $registro);
    }

    /* ------------------------------------------------------------------------------------------
     | Idempotencia: el job solo corre si le toca
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. 🔴 Un job con OTRO token (uno viejo que arranca tarde, o el de un intento que ya se reemplazó) se
     * descarta: NO llama al cliente, no toca el registro ni el candado, y lo deja dicho en el log.
     *
     * @return void
     */
    public function test_un_job_con_otro_token_no_llama_al_cliente_ni_toca_el_estado(): void
    {
        $e = $this->escenario_en_curso();
        Http::fake();
        $antes = $this->registro($e);

        $this->job($e, '2026-10-05T09:00:00.000000Z')->handle();

        Http::assertNothingSent();
        $this->assertSame($antes, $this->registro($e), 'El job viejo escribió el registro del intento vigente.');
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * 1. 🔴 Con el candado ya lleno (el setup se aplicó, por este camino o conciliado) el job NO llama al
     * cliente aunque el token coincida.
     *
     * @return void
     */
    public function test_un_job_con_el_candado_lleno_no_llama_al_cliente(): void
    {
        $e = $this->escenario_en_curso();
        $e['implementacion']->user_setup_executed_at = Carbon::parse('2026-10-05 09:30:00');
        $e['implementacion']->save();
        Http::fake();
        $antes = $this->registro($e);

        $this->job($e)->handle();

        Http::assertNothingSent();
        $this->assertSame($antes, $this->registro($e));
    }

    /**
     * 1. Un registro que ya no dice `en_curso` (terminó, o quedó en error) no le da turno a nadie, ni
     * siquiera con el token coincidente: el job solo corre sobre un intento que sigue en curso.
     *
     * @return void
     */
    public function test_un_job_sobre_un_registro_que_ya_no_esta_en_curso_no_llama_al_cliente(): void
    {
        $e = $this->escenario_en_curso();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'error', 'iniciado_at' => self::TOKEN, 'terminado_at' => '2026-10-05T10:05:00.000000Z', 'error' => 'No se pudo encolar el job: x']]);
        Http::fake();

        $this->job($e)->handle();

        Http::assertNothingSent();
        $this->assertSame('error', $this->registro($e)['estado']);
    }

    /**
     * 1. Sin registro (nadie lo escribió, o se borró) tampoco corre.
     *
     * @return void
     */
    public function test_un_job_sin_registro_no_llama_al_cliente(): void
    {
        $e = $this->escenario_en_curso();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, []);
        Http::fake();

        $this->job($e)->handle();

        Http::assertNothingSent();
    }

    /**
     * 1. Descartar un job se deja dicho en el log (con la implementación y el motivo): sin eso, un setup que
     * "no corrió" no tendría de dónde enterarse por qué.
     *
     * @return void
     */
    public function test_descartar_un_job_queda_dicho_en_el_log(): void
    {
        $e     = $this->escenario_en_curso();
        $canal = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::partialMock()->shouldReceive('channel')->with('daily')->andReturn($canal);
        Http::fake();

        $this->job($e, 'otro-token')->handle();

        $canal->shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) use ($e) {
            return strpos($mensaje, 'se descarta') !== false
                && isset($contexto['implementation_id'])
                && (int) $contexto['implementation_id'] === (int) $e['implementacion']->id;
        })->once();
    }

    /**
     * 2. 🔴 Si mientras la llamada está en vuelo se despacha un intento MÁS NUEVO (otro token en el registro),
     * el job viejo, al terminar, NO pisa el estado del nuevo: ni el registro ni el candado.
     *
     * @return void
     */
    public function test_si_cambia_el_token_mientras_corre_no_pisa_el_estado(): void
    {
        $e = $this->escenario_en_curso();

        $this->falsear_al_cliente(200, function () use ($e) {
            $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => '2026-10-05T10:20:00.000000Z', 'terminado_at' => null, 'error' => null]]);
        });

        $this->job($e)->handle();

        $registro = $this->registro($e);
        $this->assertSame('en_curso', $registro['estado'], 'El job viejo pisó el intento nuevo.');
        $this->assertSame('2026-10-05T10:20:00.000000Z', $registro['iniciado_at']);
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at, 'El job viejo llenó el candado del intento nuevo.');
        $this->assertArrayNotHasKey('actions', $this->data_de_la_etapa($e['implementacion'], 2));
    }

    /**
     * 2. 🔴 Si mientras la llamada está en vuelo el candado se llena (alguien conciliado, por ejemplo), el job
     * no pisa el estado: ni lo pasa a error ni registra una segunda acción.
     *
     * @return void
     */
    public function test_si_el_candado_se_llena_mientras_corre_no_pisa_el_estado(): void
    {
        $e = $this->escenario_en_curso();

        $this->falsear_al_cliente(500, function () use ($e) {
            Implementation::where('id', $e['implementacion']->id)->update(['user_setup_executed_at' => '2026-10-05 10:10:00']);
            $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'ok', 'iniciado_at' => self::TOKEN, 'terminado_at' => '2026-10-05T10:10:00.000000Z', 'error' => null, 'nota' => 'conciliado']]);
        });

        $this->job($e)->handle();

        $registro = $this->registro($e);
        $this->assertSame('ok', $registro['estado'], 'El job pisó el estado de un candado que se llenó mientras corría.');
        $this->assertSame('conciliado', $registro['nota']);
        $this->assertNotNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * 2. `failed()` con otro token (lo dispara el worker para un job viejo) no escribe nada.
     *
     * @return void
     */
    public function test_failed_con_otro_token_no_escribe(): void
    {
        $e     = $this->escenario_en_curso();
        $antes = $this->registro($e);

        $this->job($e, '2026-10-05T09:00:00.000000Z')->failed(new \RuntimeException('el worker lo mató'));

        $this->assertSame($antes, $this->registro($e));
    }

    /**
     * 2. `failed()` con el candado lleno no escribe nada.
     *
     * @return void
     */
    public function test_failed_con_el_candado_lleno_no_escribe(): void
    {
        $e = $this->escenario_en_curso();
        $e['implementacion']->user_setup_executed_at = now();
        $e['implementacion']->save();
        $antes = $this->registro($e);

        $this->job($e)->failed(new \RuntimeException('el worker lo mató'));

        $this->assertSame($antes, $this->registro($e));
    }

    /**
     * 2. El camino feliz con el token vigente: llama una sola vez al cliente, llena el candado y registra
     * la acción una sola vez.
     *
     * @return void
     */
    public function test_con_el_token_vigente_llama_una_vez_y_deja_el_resultado(): void
    {
        $e        = $this->escenario_en_curso();
        $llamadas = $this->falsear_al_cliente();

        $this->job($e)->handle();

        $this->assertCount(1, $llamadas->urls);
        $this->assertNotNull($e['implementacion']->refresh()->user_setup_executed_at);

        $acciones = $this->data_de_la_etapa($e['implementacion'], 2)['actions'];
        $this->assertCount(1, $acciones);
        $this->assertSame('user_setup', $acciones[0]['action']);
        $this->assertSame('claude', $acciones[0]['canal']);

        /* Y correrlo otra vez (el worker se lo entregó de nuevo) no vuelve a llamar ni duplica la acción. */
        $this->job($e)->handle();

        $this->assertCount(1, $llamadas->urls, 'El segundo job volvió a llamar al cliente: otro migrate:fresh.');
        $this->assertCount(1, $this->data_de_la_etapa($e['implementacion']->refresh(), 2)['actions']);
    }
}

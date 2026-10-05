<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarUserSetupDeImplementacionJob;
use App\Models\Client;
use App\Models\Implementation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Qué se puede hacer con un user setup DESPUÉS de un error, y en qué etapa se puede hacer cualquier cosa:
 * `POST claude/implementations/{id}/user-setup` con `reintentar` o `conciliar`.
 *
 * 🔴🔴 Del otro lado el setup arranca con `migrate:fresh --force`: LE VACÍA LA BASE AL CLIENTE. Un error del
 * job casi nunca prueba que el setup no corrió (un 502, un timeout, un worker muerto: el origen sigue), y
 * antes bastaba repetir la misma llamada para despacharlo otra vez. Lo que se protege, en orden de
 * importancia:
 *
 *  1. 🔴 Que tras un `error` el real NO se despache solo: responde 422 explicando que el intento anterior pudo
 *     haber corrido del otro lado, y sigue únicamente con UNO de dos parámetros, que son excluyentes y exigen
 *     `confirm_client_name`:
 *       - `reintentar: true` vuelve a despachar el job (con un token nuevo);
 *       - `conciliar: true` NO llama al cliente: marca el candado, deja el estado en `ok` con la nota
 *         "conciliado: el dueño ya existía en el sistema del cliente" y registra la acción `user_setup`.
 *  2. 🔴 Que solo se aplique en la etapa 2: antes no hay sistema instalado, y después el negocio puede estar
 *     operando —`migrate:fresh` le borraría lo que cargó—.
 *  3. Que el dry-run diga cuál de los dos corresponde (`corresponde`, `opciones`) sin escribir nada.
 *  4. Que los dos parámetros no abran una puerta de atrás: sin error previo son 422, con el candado lleno
 *     sigue el 422 sin vuelta, y con otro en curso sigue el 409.
 */
class ReintentarOConciliarElUserSetupPorClaudeTest extends BaseDeImplementaciones
{
    /** El token (`iniciado_at`) del intento que terminó en error. */
    const TOKEN_VIEJO = '2026-10-05T10:00:00.000000Z';

    /** La nota que deja una conciliación. */
    const NOTA = 'conciliado: el dueño ya existía en el sistema del cliente';

    /**
     * Suelta el reloj congelado que algún test haya puesto.
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
     * Una implementación LISTA PARA CONFIGURAR: etapa 2 (o la que se pida), formulario enviado, las dos APIs y
     * la instalación real completada en la API activa.
     *
     * @param array<string, mixed> $opciones `etapa`: la etapa en la que está.
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(array $opciones = []): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente->setup_data = ['company_name' => 'Panchito S.A.', 'email' => 'panchito@ejemplo.test', 'doc_number' => '20304050607'];
        $cliente->save();
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $implementacion = $this->crear_implementacion($cliente);
        $implementacion = $this->llevar_a_la_etapa($implementacion, isset($opciones['etapa']) ? $opciones['etapa'] : 2);
        $implementacion->form_submitted_at = now();
        $implementacion->save();

        $this->crear_instalacion($cliente, ['status' => 'completada', 'kind' => 'completa']);

        return ['cliente' => $cliente, 'implementacion' => $implementacion->refresh()];
    }

    /**
     * Deja el registro del user setup en `error`, como lo deja el job.
     *
     * @param array<string, mixed> $e                   El escenario.
     * @param bool|null            $puede_haber_corrido Lo que dice el registro (null = no lo dice).
     *
     * @return void
     */
    private function dejar_en_error(array $e, $puede_haber_corrido = true): void
    {
        $registro = [
            'estado'       => 'error',
            'iniciado_at'  => self::TOKEN_VIEJO,
            'terminado_at' => '2026-10-05T10:20:00.000000Z',
            'error'        => 'La client_api respondió con error (status 504): timeout del proxy',
        ];

        if ($puede_haber_corrido !== null) {
            $registro['puede_haber_corrido'] = $puede_haber_corrido;
        }

        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => $registro]);
    }

    /**
     * El POST del user setup.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $cuerpo         Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function configurar(Implementation $implementation, array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/user-setup', $cuerpo, $this->headers());
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

    /**
     * El registro del user setup de la etapa 2.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<string, mixed>
     */
    private function registro(Implementation $implementation): array
    {
        $data = $this->data_de_la_etapa($implementation->refresh(), 2);

        return isset($data['user_setup']) ? $data['user_setup'] : [];
    }

    /* ------------------------------------------------------------------------------------------
     | La etapa: solo en la 2
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. 🔴 Fuera de la etapa 2 no se aplica: el chequeo `etapa_2` sale en false en el dry-run y el real es
     * 422 sin encolar ni escribir nada. En la 3 o después el negocio puede estar operando.
     *
     * @return void
     */
    public function test_solo_se_aplica_en_la_etapa_2(): void
    {
        foreach ([1, 3, 4, 8] as $etapa) {
            Queue::fake();
            $e = $this->escenario(['etapa' => $etapa]);

            $simulacion = $this->configurar($e['implementacion'], []);
            $simulacion->assertStatus(200);
            $simulacion->assertJsonPath('listo', false);
            $chequeo = $this->chequeo($simulacion, 'etapa_2');
            $this->assertFalse($chequeo['ok'], 'En la etapa ' . $etapa . ' el chequeo etapa_2 tendría que fallar.');
            $this->assertStringContainsString('está en la etapa ' . $etapa, $chequeo['detalle']);
            $this->assertStringContainsString('solo se aplica en la etapa 2', $chequeo['detalle']);

            $real = $this->configurar($e['implementacion'], $this->real());
            $real->assertStatus(422);
            $this->assertStringContainsString('etapa_2', $this->cuerpo($real));
            $this->assertSame([], $this->registro($e['implementacion']), 'Escribió el registro en la etapa ' . $etapa . '.');
            Queue::assertNothingPushed();
        }

        Queue::fake();
        $en_la_2 = $this->escenario();
        $this->assertTrue($this->chequeo($this->configurar($en_la_2['implementacion'], []), 'etapa_2')['ok']);
        $this->configurar($en_la_2['implementacion'], $this->real())->assertStatus(202);
    }

    /* ------------------------------------------------------------------------------------------
     | Tras un error: ni solo ni sin decir qué
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. 🔴 Tras un error, el real SIN `reintentar` ni `conciliar` es 422: explica que el intento anterior
     * pudo haber corrido, lista las dos opciones y no despacha ni escribe nada.
     *
     * @return void
     */
    public function test_tras_un_error_el_real_sin_parametros_es_422_y_no_despacha(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->dejar_en_error($e);
        $antes = $this->registro($e['implementacion']);

        $respuesta = $this->configurar($e['implementacion'], $this->real());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('PUDO HABER CORRIDO', $respuesta->json('error'));
        $this->assertStringContainsString('conciliar', $respuesta->json('error'));
        $this->assertStringContainsString('reintentar', $respuesta->json('error'));
        $respuesta->assertJsonPath('puede_haber_corrido', true);
        $respuesta->assertJsonPath('sugerida', 'conciliar');
        $this->assertSame(['conciliar', 'reintentar'], array_column($respuesta->json('opciones'), 'parametro'));

        $this->assertSame($antes, $this->registro($e['implementacion']), 'Tocó el registro.');
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        Queue::assertNothingPushed();
    }

    /**
     * 1. Si el error es de los que se sabe que NO corrieron (`puede_haber_corrido` false), el 422 lo dice y
     * sugiere reintentar; si el registro no lo dice (uno viejo), se asume lo peor y se sugiere conciliar.
     *
     * @return void
     */
    public function test_el_422_sugiere_segun_lo_que_dice_el_registro(): void
    {
        Queue::fake();

        $no_corrio = $this->escenario();
        $this->dejar_en_error($no_corrio, false);
        $respuesta = $this->configurar($no_corrio['implementacion'], $this->real());
        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('puede_haber_corrido', false);
        $respuesta->assertJsonPath('sugerida', 'reintentar');
        $this->assertStringNotContainsString('PUDO HABER CORRIDO', $respuesta->json('error'));

        $no_dice = $this->escenario();
        $this->dejar_en_error($no_dice, null);
        $respuesta = $this->configurar($no_dice['implementacion'], $this->real());
        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('sugerida', 'conciliar');
        $this->assertStringContainsString('PUDO HABER CORRIDO', $respuesta->json('error'));

        Queue::assertNothingPushed();
    }

    /**
     * 1. Los dos juntos son 422 aunque no haya ningún error previo: son excluyentes.
     *
     * @return void
     */
    public function test_reintentar_y_conciliar_juntos_son_422(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->dejar_en_error($e);

        $respuesta = $this->configurar($e['implementacion'], $this->real(['reintentar' => true, 'conciliar' => true]));

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('excluyentes', $respuesta->json('error'));
        $this->assertSame('error', $this->registro($e['implementacion'])['estado']);
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        Queue::assertNothingPushed();

        /* También en el dry-run: no se simula una combinación que el real rechaza. */
        $this->configurar($e['implementacion'], ['reintentar' => true, 'conciliar' => true])->assertStatus(422);
    }

    /**
     * 1. `reintentar: true` tras un error: 202, un registro `en_curso` con un token NUEVO y el job despachado
     * con ese token en la conexión `database`. Es lo que hacía antes la llamada pelada.
     *
     * @return void
     */
    public function test_reintentar_tras_un_error_despacha_un_job_nuevo_con_otro_token(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->dejar_en_error($e, false);

        $respuesta = $this->configurar($e['implementacion'], $this->real(['reintentar' => true]));

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('user_setup.estado', 'en_curso');
        $respuesta->assertJsonPath('conexion_de_cola', 'database');

        $registro = $this->registro($e['implementacion']);
        $this->assertSame('en_curso', $registro['estado']);
        $this->assertNotSame(self::TOKEN_VIEJO, $registro['iniciado_at'], 'El intento nuevo reusó el token del viejo.');
        $this->assertSame($respuesta->json('user_setup.iniciado_at'), $registro['iniciado_at']);
        $this->assertNull($registro['error']);
        $this->assertArrayNotHasKey('puede_haber_corrido', $registro, 'El registro nuevo arrastró la marca del error anterior.');

        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, function ($job) use ($registro) {
            $token = new \ReflectionProperty($job, 'token');
            $token->setAccessible(true);

            return $job->connection === 'database' && $token->getValue($job) === $registro['iniciado_at'];
        });
    }

    /**
     * 1. 🔴 El token del intento nuevo NUNCA es el del anterior, ni con el reloj congelado en el mismo instante:
     * dos intentos con el mismo `iniciado_at` no se podrían distinguir y el job viejo pisaría al nuevo.
     *
     * @return void
     */
    public function test_el_token_del_reintento_nunca_es_el_del_intento_anterior(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00'));

        $e     = $this->escenario();
        $viejo = now()->toISOString();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => [
            'estado'              => 'error',
            'iniciado_at'         => $viejo,
            'terminado_at'        => $viejo,
            'error'               => 'x',
            'puede_haber_corrido' => false,
        ]]);

        $this->configurar($e['implementacion'], $this->real(['reintentar' => true]))->assertStatus(202);

        $nuevo = $this->registro($e['implementacion'])['iniciado_at'];
        $this->assertNotSame($viejo, $nuevo, 'El intento nuevo reusó el token del viejo: no se pueden distinguir.');
        $this->assertGreaterThan($viejo, $nuevo);
    }

    /**
     * 1. 🔴 `conciliar: true` tras un error NO llama al cliente ni despacha nada: llena el candado, deja el
     * estado en `ok` con la nota, registra la acción `user_setup` (canal `claude`) y responde 200. Y después
     * ya no se vuelve a aplicar por acá.
     *
     * @return void
     */
    public function test_conciliar_tras_un_error_no_llama_al_cliente_y_deja_el_estado_ok(): void
    {
        Queue::fake();
        Http::fake();
        $e = $this->escenario();
        $this->dejar_en_error($e);

        $respuesta = $this->configurar($e['implementacion'], $this->real(['conciliar' => true]));

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', false);
        $respuesta->assertJsonPath('conciliado', true);
        $respuesta->assertJsonPath('user_setup.estado', 'ok');
        $respuesta->assertJsonPath('user_setup.nota', self::NOTA);
        $this->assertNotNull($respuesta->json('user_setup.ejecutado_at'));

        Http::assertNothingSent();
        Queue::assertNothingPushed();

        $implementacion = $e['implementacion']->refresh();
        $this->assertNotNull($implementacion->user_setup_executed_at, 'No llenó el candado.');

        $registro = $this->registro($implementacion);
        $this->assertSame('ok', $registro['estado']);
        $this->assertSame(self::NOTA, $registro['nota']);
        $this->assertNull($registro['error']);
        $this->assertNotEmpty($registro['terminado_at']);
        $this->assertArrayNotHasKey('puede_haber_corrido', $registro);

        $acciones = $this->data_de_la_etapa($implementacion, 2)['actions'];
        $this->assertCount(1, $acciones);
        $this->assertSame('user_setup', $acciones[0]['action']);
        $this->assertSame(2, $acciones[0]['stage']);
        $this->assertSame('claude', $acciones[0]['canal']);
        $this->assertSame('claude', $acciones[0]['origen']);

        /* El estado lo lee ok con la nota, y el panel ve el user setup aplicado. */
        $this->getJson('/api/claude/implementations/' . $implementacion->id, $this->headers())
            ->assertJsonPath('user_setup.estado', 'ok')
            ->assertJsonPath('user_setup.nota', self::NOTA);

        $panel = $this->actingAs($this->crear_admin(), 'sanctum')->getJson('/api/admin/implementation/' . $implementacion->id . '/actions');
        $user_setup = null;
        foreach ($panel->json('actions') as $accion) {
            if ($accion['key'] === 'user_setup') {
                $user_setup = $accion;
            }
        }
        $this->assertNotNull($user_setup['last_executed_at'], 'El panel no ve la acción user_setup registrada.');
        $this->assertTrue($user_setup['blocked']);

        /* Y por acá no se vuelve a aplicar: ni con reintentar ni con conciliar ni a secas. */
        foreach ([[], ['reintentar' => true], ['conciliar' => true]] as $extra) {
            $otra = $this->configurar($implementacion, $this->real($extra));
            $otra->assertStatus(422);
            $this->assertStringStartsWith('El user setup ya se aplicó', (string) $otra->json('error'));
        }
        Queue::assertNothingPushed();
    }

    /**
     * 1. Los dos parámetros exigen `confirm_client_name` (obligatorio con dry_run=false) y no revelan el
     * nombre correcto cuando falla: no son una forma de saltearse el freno.
     *
     * @return void
     */
    public function test_reintentar_y_conciliar_exigen_el_nombre(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->dejar_en_error($e);

        foreach (['reintentar', 'conciliar'] as $parametro) {
            $sin = $this->configurar($e['implementacion'], ['dry_run' => false, $parametro => true]);
            $sin->assertStatus(422);
            $this->assertStringContainsString('es obligatorio cuando', $this->cuerpo($sin));

            $mal = $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Otro Negocio', $parametro => true]);
            $mal->assertStatus(422);
            $this->assertStringNotContainsString('Panchito Gómez', $this->cuerpo($mal));
        }

        $this->assertSame('error', $this->registro($e['implementacion'])['estado']);
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        Queue::assertNothingPushed();
    }

    /**
     * 4. Sin un error previo los dos parámetros son 422: `conciliar` marcaría como aplicado algo que nunca se
     * intentó, y `reintentar` no tiene nada que reintentar.
     *
     * @return void
     */
    public function test_sin_error_previo_los_dos_son_422(): void
    {
        Queue::fake();
        $e = $this->escenario();

        foreach (['reintentar', 'conciliar'] as $parametro) {
            $respuesta = $this->configurar($e['implementacion'], $this->real([$parametro => true]));

            $respuesta->assertStatus(422);
            $this->assertStringContainsString('error', $respuesta->json('error'));
            $this->assertSame([], $this->registro($e['implementacion']));
            $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        }

        Queue::assertNothingPushed();
    }

    /**
     * 4. Con otro intento EN CURSO (que no está colgado) sigue el 409 aunque se pida `conciliar` o
     * `reintentar`: no se concilia ni se reintenta algo que está corriendo.
     *
     * @return void
     */
    public function test_con_otro_en_curso_sigue_el_409(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(5)->toISOString()]]);

        foreach (['reintentar', 'conciliar'] as $parametro) {
            $this->configurar($e['implementacion'], $this->real([$parametro => true]))->assertStatus(409);
        }

        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        Queue::assertNothingPushed();
    }

    /**
     * 2 y 4. Fuera de la etapa 2 tampoco se concilia ni se reintenta: el chequeo de la etapa vale para los
     * dos caminos.
     *
     * @return void
     */
    public function test_fuera_de_la_etapa_2_tampoco_se_concilia_ni_se_reintenta(): void
    {
        Queue::fake();
        $e = $this->escenario(['etapa' => 3]);
        $this->dejar_en_error($e);

        foreach (['reintentar', 'conciliar'] as $parametro) {
            $respuesta = $this->configurar($e['implementacion'], $this->real([$parametro => true]));

            $respuesta->assertStatus(422);
            $this->assertStringContainsString('etapa_2', $this->cuerpo($respuesta));
        }

        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------------------------------
     | El dry-run dice cuál corresponde
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. El dry-run dice qué corresponde según el último intento: `aplicar` (nada antes), `conciliar` o
     * `reintentar` (tras un error, según si pudo haber corrido), `esperar` (hay uno en curso) y `ninguna`
     * (ya aplicado). Y con error dice qué parámetro mandar y qué hace cada uno.
     *
     * @return void
     */
    public function test_el_dry_run_dice_cual_corresponde(): void
    {
        /* Nada antes. */
        $limpio = $this->escenario();
        $simulacion = $this->configurar($limpio['implementacion'], []);
        $simulacion->assertJsonPath('corresponde', 'aplicar');
        $simulacion->assertJsonPath('ultimo_intento', null);
        $simulacion->assertJsonPath('parametros_para_aplicar', []);
        $this->assertArrayNotHasKey('opciones', $simulacion->json());

        /* Error que pudo haber corrido: conciliar, y se explican las dos opciones. */
        $pudo = $this->escenario();
        $this->dejar_en_error($pudo, true);
        $simulacion = $this->configurar($pudo['implementacion'], []);
        $simulacion->assertStatus(200);
        $simulacion->assertJsonPath('listo', true);
        $simulacion->assertJsonPath('corresponde', 'conciliar');
        $simulacion->assertJsonPath('parametros_para_aplicar', ['conciliar' => true]);
        $simulacion->assertJsonPath('ultimo_intento.estado', 'error');
        $simulacion->assertJsonPath('ultimo_intento.puede_haber_corrido', true);
        $this->assertStringContainsString('status 504', $simulacion->json('ultimo_intento.error'));
        $this->assertSame(['conciliar', 'reintentar'], array_column($simulacion->json('opciones'), 'parametro'));
        $this->assertStringContainsString('NO llama al sistema del cliente', $simulacion->json('opciones.0.que_hace'));
        $this->assertStringContainsString('migrate:fresh', $simulacion->json('opciones.1.que_hace'));

        /* Error de los que no corrieron: reintentar. */
        $no_corrio = $this->escenario();
        $this->dejar_en_error($no_corrio, false);
        $simulacion = $this->configurar($no_corrio['implementacion'], []);
        $simulacion->assertJsonPath('corresponde', 'reintentar');
        $simulacion->assertJsonPath('parametros_para_aplicar', ['reintentar' => true]);

        /* Error de un registro viejo que no dice nada: se asume lo peor, conciliar. */
        $viejo = $this->escenario();
        $this->dejar_en_error($viejo, null);
        $this->configurar($viejo['implementacion'], [])->assertJsonPath('corresponde', 'conciliar');

        /* Uno en curso: esperar. */
        $en_curso = $this->escenario();
        $this->escribir_data_de_la_etapa($en_curso['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(5)->toISOString()]]);
        $this->configurar($en_curso['implementacion'], [])->assertJsonPath('corresponde', 'esperar');

        /* Uno colgado: se vuelve a aplicar normalmente (es seguro, el job viejo se descarta solo). */
        $colgado = $this->escenario();
        $this->escribir_data_de_la_etapa($colgado['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(50)->toISOString()]]);
        $this->configurar($colgado['implementacion'], [])->assertJsonPath('corresponde', 'aplicar');

        /* Ya aplicado: ninguna. */
        $aplicado = $this->escenario();
        $aplicado['implementacion']->user_setup_executed_at = Carbon::parse('2026-10-05 12:34:00');
        $aplicado['implementacion']->save();
        $this->configurar($aplicado['implementacion'], [])->assertJsonPath('corresponde', 'ninguna');
    }

    /**
     * 3. El dry-run no escribe nada ni con `conciliar: true` o `reintentar: true`: sin `dry_run=false`
     * explícito sigue siendo una simulación (el default es true).
     *
     * @return void
     */
    public function test_el_dry_run_con_los_parametros_no_escribe_nada(): void
    {
        Queue::fake();
        Http::fake();
        $e = $this->escenario();
        $this->dejar_en_error($e);
        $antes = $this->registro($e['implementacion']);

        foreach ([['conciliar' => true], ['reintentar' => true]] as $extra) {
            $respuesta = $this->configurar($e['implementacion'], $extra);

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('dry_run', true);
        }

        $this->assertSame($antes, $this->registro($e['implementacion']));
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /**
     * El estado (GET) expone `puede_haber_corrido` cuando el último intento terminó en error, para que la
     * skill decida entre conciliar y reintentar sin tener que leer el texto.
     *
     * @return void
     */
    public function test_el_estado_expone_puede_haber_corrido(): void
    {
        $e = $this->escenario();
        $this->dejar_en_error($e, true);

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertJsonPath('user_setup.estado', 'error')
            ->assertJsonPath('user_setup.puede_haber_corrido', true);

        $this->dejar_en_error($e, false);

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())
            ->assertJsonPath('user_setup.puede_haber_corrido', false);
    }
}

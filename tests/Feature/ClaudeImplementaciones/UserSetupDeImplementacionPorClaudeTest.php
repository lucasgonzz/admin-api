<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarUserSetupDeImplementacionJob;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\Implementation;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * El user setup por `claude/*`: `POST claude/implementations/{id}/user-setup`.
 *
 * 🔴🔴 Del otro lado el setup arranca con `migrate:fresh --force`: LE VACÍA LA BASE AL CLIENTE. Lo que
 * se protege, en orden de importancia:
 *
 *  1. 🔴 Que este camino NO tenga "forzar": ni un parámetro `force`/`forzar` (422), ni forma de volver
 *     a aplicarlo cuando `user_setup_executed_at` ya está lleno (422 sin vuelta).
 *  2. 🔴 `dry_run` por defecto: no encola nada y muestra el payload REAL con las claves tapadas.
 *  3. Que se encole en la conexión `database` (202), con el registro `en_curso` escrito ANTES de
 *     despachar, y que otro en curso sea 409.
 *  4. Que el job deje el resultado correcto: éxito (candado + acción `user_setup` con `canal: claude`),
 *     error (motivo, sin candado), 409 de empresa-api (sin reintento) y error de conexión (con la
 *     advertencia de que pudo haber corrido).
 *  5. Que el chequeo de la instalación mire la REAL de la API activa y no "la última por id" (que con el
 *     par real + esqueleto es el esqueleto).
 *  6. Que la llamada HTTP salga con 1200 s de techo.
 */
class UserSetupDeImplementacionPorClaudeTest extends BaseDeImplementaciones
{
    /** URL de la empresa-api del cliente de prueba (la API activa). */
    const URL_API = 'https://api-panchito.comerciocity.com';

    /**
     * Sin clave de Serper cargada para que cada test decida.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        AdminSetting::whereIn('key', ['implementation_serper_api_key_default', 'implementation_serper_api_key_demo'])->delete();
        AdminSetting::flush_memo();
    }

    /* ------------------------------------------------------------------------------------------
     | Escenario
     |----------------------------------------------------------------------------------------- */

    /**
     * Una implementación LISTA PARA CONFIGURAR: etapa 2, formulario enviado, las dos APIs, el
     * `setup_data` que deja el formulario y la instalación real completada en la API activa.
     *
     * @param array<string, mixed> $opciones `etapa`, `formulario` (false = sin enviar), `instalacion`
     *                                       (estado de la real; null = ninguna).
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario(array $opciones = []): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente->setup_data = [
            'company_name'    => 'Panchito S.A.',
            'email'           => 'panchito@ejemplo.test',
            'doc_number'      => '20304050607',
            'use_price_lists' => true,
            'price_lists'     => "Minorista\nMayorista",
            'use_deposits'    => false,
            'iva_included'    => true,
        ];
        $cliente->save();
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $implementacion = $this->crear_implementacion($cliente);
        $implementacion = $this->llevar_a_la_etapa($implementacion, isset($opciones['etapa']) ? $opciones['etapa'] : 2);

        if (! isset($opciones['formulario']) || $opciones['formulario'] !== false) {
            $implementacion->form_submitted_at = now();
            $implementacion->save();
        } else {
            /* Sin formulario: ni `form_submitted_at` ni datos mapeados (con la etapa 1 completada por
               `llevar_a_la_etapa`, el `setup_data` lleno contaría como formulario cargado a mano). */
            $cliente->setup_data = null;
            $cliente->save();
        }

        $estado_de_la_real = array_key_exists('instalacion', $opciones) ? $opciones['instalacion'] : 'completada';
        if ($estado_de_la_real !== null) {
            $this->crear_instalacion($cliente, ['status' => $estado_de_la_real, 'kind' => 'completa']);
        }

        return ['cliente' => $cliente, 'implementacion' => $implementacion->refresh()];
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
     * El registro del user setup guardado en la etapa 2.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<string, mixed>
     */
    private function registro(Implementation $implementation): array
    {
        $data = $this->data_de_la_etapa($implementation, 2);

        return isset($data['user_setup']) ? $data['user_setup'] : [];
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
        $this->postJson('/api/claude/implementations/1/user-setup', [])->assertStatus(401);
    }

    /**
     * 1. 🔴 NO existe "forzar": un `force` o un `forzar` es 422 (parámetro desconocido) y no se encola
     * nada, ni siquiera con todo lo demás en orden.
     *
     * @return void
     */
    public function test_no_existe_forzar(): void
    {
        Queue::fake();
        $e = $this->escenario();

        foreach (['force' => true, 'forzar' => true, 'reaplicar' => true] as $campo => $valor) {
            $respuesta = $this->configurar($e['implementacion'], [$campo => $valor, 'dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

            $respuesta->assertStatus(422);
            $this->assertStringContainsString($campo, $this->cuerpo($respuesta));
            $respuesta->assertJsonPath('parametros_aceptados', ['dry_run', 'confirm_client_name']);
        }

        $this->assertSame([], $this->registro($e['implementacion']));
        Queue::assertNothingPushed();
    }

    /**
     * Una implementación inexistente es 404; los tipos se validan en español.
     *
     * @return void
     */
    public function test_404_y_tipos(): void
    {
        $this->configurar(new Implementation(['id' => 99999999]), [])->assertStatus(404);

        $e = $this->escenario();
        $this->configurar($e['implementacion'], ['dry_run' => 'quizás'])->assertStatus(422);

        $sin = $this->configurar($e['implementacion'], ['dry_run' => false]);
        $sin->assertStatus(422);
        $this->assertStringContainsString('es obligatorio cuando', $this->cuerpo($sin));
    }

    /* ------------------------------------------------------------------------------------------
     | Dry-run
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. 🔴 Sin `dry_run` explícito no se encola ni se escribe nada: los seis chequeos en true, el
     * destino, el techo de 1200 segundos, el payload y el aviso de que vacía la base.
     *
     * @return void
     */
    public function test_por_defecto_es_dry_run_y_no_escribe_ni_encola_nada(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $respuesta = $this->configurar($e['implementacion'], []);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('listo', true);
        $this->assertCount(6, $respuesta->json('chequeos'));

        foreach ($respuesta->json('chequeos') as $chequeo) {
            $this->assertTrue($chequeo['ok'], 'El chequeo ' . $chequeo['chequeo'] . ' salió en false: ' . $chequeo['detalle']);
        }

        $respuesta->assertJsonPath('destino.endpoint', self::URL_API . '/api/admin-sync/user-setup');
        $respuesta->assertJsonPath('destino.timeout_segundos', 1200);
        $this->assertStringContainsString('migrate:fresh', $respuesta->json('aviso_destructivo'));
        $this->assertStringContainsString('no tiene forzar', $respuesta->json('aviso_destructivo'));

        $this->assertSame([], $this->registro($e['implementacion']), 'El dry-run escribió el registro en_curso.');
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
        Queue::assertNothingPushed();
    }

    /**
     * 2. El payload del dry-run es el REAL (el mismo que arma `trigger_user_setup`), con las claves de
     * servicios pagos tapadas: la de Serper sale como "cargada, termina en XXXX" y la entera no aparece.
     *
     * @return void
     */
    public function test_el_payload_es_el_real_con_las_claves_tapadas(): void
    {
        AdminSetting::set('implementation_serper_api_key_default', '0123456789abcdef0123456789abcdef01234567');
        $e = $this->escenario();

        $respuesta = $this->configurar($e['implementacion'], []);
        $payload   = $respuesta->json('payload');

        $this->assertSame((int) $e['cliente']->user_id, $payload['user_id']);
        $this->assertSame('Panchito S.A.', $payload['company_name']);
        $this->assertSame('20304050607', $payload['doc_number']);
        $this->assertSame('Minorista', $payload['price_type_1']);
        $this->assertSame('cargada, termina en 4567', $payload['serper_api_key']);
        $this->assertStringNotContainsString('0123456789abcdef0123456789abcdef01234567', $this->cuerpo($respuesta), 'La clave de Serper salió entera.');
    }

    /**
     * Cada chequeo que falla sale en false y no escribe nada.
     *
     * @return void
     */
    public function test_cada_chequeo_que_falla_sale_en_false(): void
    {
        $casos = [
            ['chequeo' => 'formulario_enviado', 'escenario' => ['formulario' => false], 'texto' => 'Todavía no se completó el formulario'],
            ['chequeo' => 'etapa_2_o_posterior', 'escenario' => ['etapa' => 1], 'texto' => 'todavía no avanzó a la etapa 2'],
            ['chequeo' => 'instalacion_completada', 'escenario' => ['instalacion' => null], 'texto' => 'No hay ninguna instalación completa'],
            ['chequeo' => 'instalacion_completada', 'escenario' => ['instalacion' => 'instalando'], 'texto' => 'está en "instalando"'],
            ['chequeo' => 'instalacion_completada', 'escenario' => ['instalacion' => 'fallida'], 'texto' => 'está en "fallida"'],
        ];

        foreach ($casos as $caso) {
            Queue::fake();
            $e = $this->escenario($caso['escenario']);

            $respuesta = $this->configurar($e['implementacion'], []);

            $respuesta->assertStatus(200);
            $respuesta->assertJsonPath('listo', false);
            $chequeo = $this->chequeo($respuesta, $caso['chequeo']);
            $this->assertFalse($chequeo['ok'], 'El chequeo ' . $caso['chequeo'] . ' tendría que haber fallado (' . $caso['texto'] . ').');
            $this->assertStringContainsString($caso['texto'], $chequeo['detalle']);
        }
    }

    /**
     * El formulario cuenta como enviado con `form_submitted_at` lleno, o con la etapa 1 completada Y
     * datos ya mapeados en `setup_data` (Lucas cargando las respuestas desde el panel). La etapa 1
     * completada a secas NO: es lo que deja "Avanzar etapa" sin que el cliente haya cargado nada, y el
     * user setup correría con un `setup_data` vacío (migrate:fresh con todo por defecto).
     *
     * @return void
     */
    public function test_la_etapa_1_completada_a_secas_no_cuenta_como_formulario(): void
    {
        $sin_datos = $this->escenario(['formulario' => false]);
        $this->assertFalse($this->chequeo($this->configurar($sin_datos['implementacion'], []), 'formulario_enviado')['ok']);

        $con_datos = $this->escenario();
        $con_datos['implementacion']->form_submitted_at = null;
        $con_datos['implementacion']->save();
        $this->assertTrue($this->chequeo($this->configurar($con_datos['implementacion'], []), 'formulario_enviado')['ok'], 'Etapa 1 completada + datos mapeados es un formulario cargado desde el panel.');
    }

    /**
     * Sin API activa (o sin URL) el chequeo falla.
     *
     * @return void
     */
    public function test_sin_api_activa_el_chequeo_falla(): void
    {
        $e = $this->escenario();
        $e['cliente']->active_client_api_id = null;
        $e['cliente']->save();

        $respuesta = $this->configurar($e['implementacion'], []);

        $this->assertFalse($this->chequeo($respuesta, 'client_api_activa')['ok']);
        $respuesta->assertJsonPath('destino.endpoint', null);
    }

    /**
     * 5. 🔴 El chequeo de la instalación mira la REAL de la API activa: con el par real + esqueleto la
     * "última por id" es el esqueleto —que termina después y no tiene nada que ver con el sistema al que
     * se le pega—, y que esté pendiente o instalando NO tiene que frenar el user setup.
     *
     * @return void
     */
    public function test_un_esqueleto_pendiente_no_frena_si_la_real_esta_completada(): void
    {
        $e = $this->escenario();
        $this->crear_instalacion($e['cliente'], [
            'kind'          => 'esqueleto',
            'client_api_id' => \App\Models\ClientApi::where('client_id', $e['cliente']->id)->orderByDesc('id')->value('id'),
            'status'        => 'instalando',
        ]);

        $respuesta = $this->configurar($e['implementacion'], []);

        $this->assertTrue($this->chequeo($respuesta, 'instalacion_completada')['ok']);
        $respuesta->assertJsonPath('listo', true);
    }

    /**
     * Una instalación completada pero de la OTRA API (la que no es la activa) no cuenta.
     *
     * @return void
     */
    public function test_una_instalacion_de_la_otra_api_no_cuenta(): void
    {
        $e = $this->escenario(['instalacion' => null]);
        $this->crear_instalacion($e['cliente'], [
            'kind'          => 'completa',
            'client_api_id' => \App\Models\ClientApi::where('client_id', $e['cliente']->id)->orderByDesc('id')->value('id'),
            'status'        => 'completada',
        ]);

        $this->assertFalse($this->chequeo($this->configurar($e['implementacion'], []), 'instalacion_completada')['ok']);
    }

    /* ------------------------------------------------------------------------------------------
     | El real
     |----------------------------------------------------------------------------------------- */

    /**
     * Un `confirm_client_name` equivocado es 422, no encola ni escribe nada y no revela el nombre.
     *
     * @return void
     */
    public function test_el_nombre_equivocado_no_encola_ni_revela_el_nombre(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $respuesta = $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Otro Negocio']);

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Panchito Gómez', $this->cuerpo($respuesta));
        $this->assertSame([], $this->registro($e['implementacion']));
        Queue::assertNothingPushed();
    }

    /**
     * Con un chequeo en false el real es 422 con la lista y no encola ni escribe nada.
     *
     * @return void
     */
    public function test_con_un_chequeo_en_false_el_real_es_422(): void
    {
        Queue::fake();
        $e = $this->escenario(['instalacion' => 'instalando']);

        $respuesta = $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('instalacion_completada', $this->cuerpo($respuesta));
        $this->assertCount(6, $respuesta->json('chequeos'));
        $this->assertSame([], $this->registro($e['implementacion']));
        Queue::assertNothingPushed();
    }

    /**
     * 3. El camino feliz: 202, el registro `en_curso` con su fecha escrito ANTES de despachar, y el job
     * encolado en la conexión `database` con el id de la implementación.
     *
     * @return void
     */
    public function test_el_camino_feliz_deja_en_curso_y_encola_en_database(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $respuesta = $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('dry_run', false);
        $respuesta->assertJsonPath('implementation_id', (int) $e['implementacion']->id);
        $respuesta->assertJsonPath('user_setup.estado', 'en_curso');
        $respuesta->assertJsonPath('conexion_de_cola', 'database');
        $respuesta->assertJsonPath('latencia_maxima_segundos', 60);
        $this->assertNotEmpty($respuesta->json('user_setup.iniciado_at'));

        $registro = $this->registro($e['implementacion']);
        $this->assertSame('en_curso', $registro['estado']);
        $this->assertSame($respuesta->json('user_setup.iniciado_at'), $registro['iniciado_at']);
        $this->assertNull($registro['terminado_at']);
        $this->assertNull($registro['error']);

        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at, 'El candado lo llena el job al terminar bien, no el endpoint.');

        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);
        /* 🔴 El job lleva como token el `iniciado_at` del registro que lo despachó: es lo que lo distingue de
           un intento anterior y lo que le permite descartarse solo si ya no le toca. */
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, function ($job) use ($e, $registro) {
            $id = new \ReflectionProperty($job, 'implementation_id');
            $id->setAccessible(true);
            $token = new \ReflectionProperty($job, 'token');
            $token->setAccessible(true);

            return $job->connection === 'database'
                && $id->getValue($job) === (int) $e['implementacion']->id
                && $token->getValue($job) === $registro['iniciado_at'];
        });

        /* Y el estado lo lee en curso. */
        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())->assertJsonPath('user_setup.estado', 'en_curso');
    }

    /**
     * 3. Otro en curso es 409 y no encola un segundo job: es lo que evita que dos llamadas despachen
     * dos setups sobre la misma base.
     *
     * @return void
     */
    public function test_otro_en_curso_es_409(): void
    {
        Queue::fake();
        $e = $this->escenario();

        $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);

        $segunda = $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $segunda->assertStatus(409);
        $segunda->assertJsonPath('user_setup.estado', 'en_curso');
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);

        /* El dry-run también lo dice. */
        $this->assertFalse($this->chequeo($this->configurar($e['implementacion'], []), 'sin_setup_en_curso')['ok']);
    }

    /**
     * Uno que dice `en_curso` hace más de 45 minutos se da por colgado: deja volver a intentar (y el
     * dry-run lo explica).
     *
     * @return void
     */
    public function test_un_en_curso_colgado_deja_reintentar(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->subMinutes(50)->toISOString()]]);

        $simulacion = $this->configurar($e['implementacion'], []);
        $this->assertTrue($this->chequeo($simulacion, 'sin_setup_en_curso')['ok']);
        $this->assertStringContainsString('se da por colgado', $this->chequeo($simulacion, 'sin_setup_en_curso')['detalle']);

        $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);
    }

    /**
     * 1. 🔴 Ya aplicado (`user_setup_executed_at` lleno) es 422 SIN VUELTA, con la fecha y el motivo:
     * ni en el dry-run ni en el real hay forma de volver a correrlo, aunque todo lo demás esté en orden.
     *
     * @return void
     */
    public function test_ya_aplicado_es_422_sin_vuelta(): void
    {
        Queue::fake();
        $e = $this->escenario();
        $e['implementacion']->user_setup_executed_at = Carbon::parse('2026-10-05 12:34:00');
        $e['implementacion']->save();

        $simulacion = $this->configurar($e['implementacion'], []);
        $this->assertFalse($this->chequeo($simulacion, 'sin_aplicar_antes')['ok']);
        $simulacion->assertJsonPath('listo', false);

        $real = $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $real->assertStatus(422);
        $this->assertStringContainsString('05/10/2026 12:34', $real->json('error'));
        $this->assertStringContainsString('VACÍA la base del cliente', $real->json('error'));
        $this->assertStringContainsString('no tiene "forzar"', $real->json('error'));
        $this->assertStringContainsString('desde el panel', $real->json('error'));
        $this->assertSame([], $this->registro($e['implementacion']));
        Queue::assertNothingPushed();
    }

    /**
     * 🔴 Si encolar el job FALLA, el registro —que ya decía `en_curso`— queda en `error` con el motivo:
     * sin un job que lo termine quedaría `en_curso` hasta darse por colgado, 45 minutos después. No toca el
     * candado (nada se aplicó) y el reintento, con la cola andando, sale.
     *
     * @return void
     */
    public function test_si_no_se_puede_encolar_el_registro_queda_en_error_y_se_puede_reintentar(): void
    {
        $e = $this->escenario();

        /* Se reemplaza el despachador CONCRETO y no el contrato: `instance()` borra el alias del contrato al
           concreto, y después de restaurarlo ya no se podría resolver. */
        $roto = \Mockery::mock(\Illuminate\Bus\Dispatcher::class);
        $roto->shouldReceive('dispatch')->andThrow(new \RuntimeException('la tabla jobs no existe'));
        $this->app->instance(\Illuminate\Bus\Dispatcher::class, $roto);

        $respuesta = $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez']);

        $respuesta->assertStatus(500);
        $respuesta->assertJsonPath('reintentable', true);
        $this->assertStringContainsString('la tabla jobs no existe', $respuesta->json('error'));

        $registro = $this->registro($e['implementacion']);
        $this->assertSame('error', $registro['estado']);
        $this->assertStringContainsString('No se pudo encolar el job', $registro['error']);
        $this->assertNotEmpty($registro['terminado_at']);
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);

        $this->getJson('/api/claude/implementations/' . $e['implementacion']->id, $this->headers())->assertJsonPath('user_setup.estado', 'error');

        /* Con la cola andando otra vez, el reintento sale. */
        $this->app->forgetInstance(\Illuminate\Bus\Dispatcher::class);
        Queue::fake();

        $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);
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

        $this->configurar($e['implementacion'], ['dry_run' => false, 'confirm_client_name' => '  PANCHITO gómez '])->assertStatus(202);
    }

    /* ------------------------------------------------------------------------------------------
     | El job
     |----------------------------------------------------------------------------------------- */

    /**
     * El job de un escenario con el user setup en curso, con el token (`iniciado_at`) de su registro, que es
     * como lo despacha el endpoint.
     *
     * @param array{cliente: Client, implementacion: Implementation} $e El escenario.
     *
     * @return EjecutarUserSetupDeImplementacionJob
     */
    private function job_de(array $e): EjecutarUserSetupDeImplementacionJob
    {
        $registro = $this->registro($e['implementacion']->refresh());

        return new EjecutarUserSetupDeImplementacionJob($e['implementacion']->id, (string) $registro['iniciado_at']);
    }

    /**
     * Deja el escenario con el user setup en curso, como lo deja el endpoint antes de despachar.
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario_en_curso(): array
    {
        $e = $this->escenario();
        $this->escribir_data_de_la_etapa($e['implementacion'], 2, ['user_setup' => ['estado' => 'en_curso', 'iniciado_at' => now()->toISOString(), 'terminado_at' => null, 'error' => null]]);

        return $e;
    }

    /**
     * 4, 6. El job en éxito: el POST sale a la URL de la API activa con el payload y con 1200 segundos de
     * techo, y deja el candado, el registro `ok` y la acción `user_setup` con `canal: claude`.
     *
     * @return void
     */
    public function test_el_job_en_exito_deja_el_candado_el_registro_y_la_accion(): void
    {
        $e        = $this->escenario_en_curso();
        $llamadas = new \stdClass();
        $llamadas->techos = [];
        $llamadas->urls   = [];
        $llamadas->cuerpo = null;

        Http::fake(function ($request, $opciones) use ($llamadas) {
            $llamadas->techos[] = isset($opciones['timeout']) ? $opciones['timeout'] : null;
            $llamadas->urls[]   = $request->url();
            $llamadas->cuerpo   = $request->data();

            return Http::response(['ok' => true], 200);
        });

        $this->job_de($e)->handle();

        $this->assertSame([1200], $llamadas->techos);
        $this->assertSame([self::URL_API . '/api/admin-sync/user-setup'], $llamadas->urls);
        $this->assertSame((int) $e['cliente']->user_id, $llamadas->cuerpo['user_id']);
        $this->assertSame('20304050607', $llamadas->cuerpo['doc_number']);

        $implementacion = $e['implementacion']->refresh();
        $this->assertNotNull($implementacion->user_setup_executed_at);

        $registro = $this->registro($implementacion);
        $this->assertSame('ok', $registro['estado']);
        $this->assertNotEmpty($registro['iniciado_at']);
        $this->assertNotEmpty($registro['terminado_at']);
        $this->assertNull($registro['error']);

        $acciones = $this->data_de_la_etapa($implementacion, 2)['actions'];
        $this->assertCount(1, $acciones);
        $this->assertSame('user_setup', $acciones[0]['action']);
        $this->assertSame(2, $acciones[0]['stage']);
        $this->assertSame('claude', $acciones[0]['canal']);
        $this->assertSame('claude', $acciones[0]['origen']);

        /* El estado lo lee ok, y por acá no se vuelve a correr: 422 sin vuelta. */
        $this->getJson('/api/claude/implementations/' . $implementacion->id, $this->headers())->assertJsonPath('user_setup.estado', 'ok');
        $this->configurar($implementacion, ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(422);
    }

    /**
     * 4. Con la acción registrada, el panel ve el user setup aplicado (su candado y su "hecho el …").
     *
     * @return void
     */
    public function test_el_panel_ve_el_user_setup_aplicado(): void
    {
        $e = $this->escenario_en_curso();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->job_de($e)->handle();

        $estado = $this->actingAs($this->crear_admin(), 'sanctum')->getJson('/api/admin/implementation/' . $e['implementacion']->id . '/actions');
        $estado->assertStatus(200);

        $user_setup = null;
        foreach ($estado->json('actions') as $accion) {
            if ($accion['key'] === 'user_setup') {
                $user_setup = $accion;
            }
        }

        $this->assertNotNull($user_setup['last_executed_at'], 'El panel no ve la acción user_setup registrada por el job.');
        $this->assertNotNull($user_setup['executed_at'], 'El panel no ve el candado.');
        $this->assertTrue($user_setup['blocked']);
    }

    /**
     * 4. El job con un error de empresa-api (500): `estado: error` con el motivo, SIN candado y sin
     * acción registrada. Y un error NO bloquea reintentar (el candado no se llenó).
     *
     * @return void
     */
    public function test_el_job_con_error_deja_el_motivo_sin_candado(): void
    {
        Queue::fake();
        $e = $this->escenario_en_curso();
        Http::fake(['*' => Http::response(['error' => 'internal error: Class not found'], 500)]);

        $this->job_de($e)->handle();

        $implementacion = $e['implementacion']->refresh();
        $this->assertNull($implementacion->user_setup_executed_at, 'Un error no llena el candado.');

        $registro = $this->registro($implementacion);
        $this->assertSame('error', $registro['estado']);
        $this->assertStringContainsString('status 500', $registro['error']);
        $this->assertStringContainsString('internal error', $registro['error']);
        $this->assertNotEmpty($registro['terminado_at']);
        $this->assertArrayNotHasKey('actions', $this->data_de_la_etapa($implementacion, 2));

        $this->getJson('/api/claude/implementations/' . $implementacion->id, $this->headers())
            ->assertJsonPath('user_setup.estado', 'error')
            ->assertJsonPath('user_setup.ejecutado_at', null);

        /* Un error no es "ya aplicado": se puede volver a intentar. */
        $this->configurar($implementacion, ['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'])->assertStatus(202);
    }

    /**
     * 4. Un 409 de empresa-api ("ya hay un setup corriendo") es un error y NO se reintenta: el motivo lo
     * dice.
     *
     * @return void
     */
    public function test_un_409_de_empresa_api_es_error_sin_reintento(): void
    {
        $e = $this->escenario_en_curso();
        Http::fake(['*' => Http::response(['error' => 'otro setup en curso'], 409)]);

        $this->job_de($e)->handle();

        $registro = $this->registro($e['implementacion']->refresh());
        $this->assertSame('error', $registro['estado']);
        $this->assertStringContainsString('status 409', $registro['error']);
        $this->assertStringContainsString('NO se reintenta', $registro['error']);
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * 4. 🔴 Un error de conexión (timeout) NO prueba que el setup no haya corrido: el motivo advierte que
     * hay que mirar el sistema del cliente ANTES de reintentar, porque un reintento le vuelve a vaciar la
     * base.
     *
     * @return void
     */
    public function test_un_error_de_conexion_advierte_que_pudo_haber_corrido(): void
    {
        $e = $this->escenario_en_curso();
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 1200001 milliseconds');
        });

        $this->job_de($e)->handle();

        $registro = $this->registro($e['implementacion']->refresh());
        $this->assertSame('error', $registro['estado']);
        $this->assertStringContainsString('Error de conexión con la client_api', $registro['error']);
        $this->assertStringContainsString('Operation timed out', $registro['error']);
        $this->assertStringContainsString('ANTES de reintentar', $registro['error']);
        $this->assertStringContainsString('vaciar la base', $registro['error']);
    }

    /**
     * El job nunca deja escapar una excepción: si el servicio revienta por algo inesperado, queda como
     * error y el job termina bien.
     *
     * @return void
     */
    public function test_una_excepcion_inesperada_queda_como_error(): void
    {
        $e = $this->escenario_en_curso();
        Http::fake(function () {
            throw new \LogicException('algo que nadie previó');
        });

        $this->job_de($e)->handle();

        $registro = $this->registro($e['implementacion']->refresh());
        $this->assertSame('error', $registro['estado']);
        $this->assertStringContainsString('algo que nadie previó', $registro['error']);
    }

    /**
     * Sin API activa el servicio contesta que no hay client_api y el job lo deja como error.
     *
     * @return void
     */
    public function test_sin_api_activa_el_job_deja_error(): void
    {
        $e = $this->escenario_en_curso();
        $e['cliente']->active_client_api_id = null;
        $e['cliente']->save();
        Http::fake();

        $this->job_de($e)->handle();

        $this->assertSame('error', $this->registro($e['implementacion']->refresh())['estado']);
        Http::assertNothingSent();
    }

    /**
     * Una implementación que ya no existe: el job no hace nada (y no revienta).
     *
     * @return void
     */
    public function test_el_job_con_una_implementacion_inexistente_no_hace_nada(): void
    {
        Http::fake();

        (new EjecutarUserSetupDeImplementacionJob(99999999, '2026-10-05T10:00:00.000000Z'))->handle();

        Http::assertNothingSent();
        $this->assertTrue(true);
    }

    /**
     * 4. `failed()` (el worker lo cortó) deja `error` si SIGUE en curso, y no pisa un resultado que
     * `handle()` ya escribió.
     *
     * @return void
     */
    public function test_failed_deja_error_solo_si_sigue_en_curso(): void
    {
        $en_curso = $this->escenario_en_curso();
        $this->job_de($en_curso)->failed(new \RuntimeException('timeout de 1500 s'));

        $registro = $this->registro($en_curso['implementacion']->refresh());
        $this->assertSame('error', $registro['estado']);
        $this->assertStringContainsString('timeout de 1500 s', $registro['error']);
        $this->assertNull($en_curso['implementacion']->refresh()->user_setup_executed_at);

        $terminado = $this->escenario();
        $this->escribir_data_de_la_etapa($terminado['implementacion'], 2, ['user_setup' => ['estado' => 'ok', 'iniciado_at' => '2026-10-05T14:00:00.000000Z', 'terminado_at' => '2026-10-05T14:09:00.000000Z']]);
        $this->job_de($terminado)->failed(new \RuntimeException('MaxAttemptsExceededException'));

        $this->assertSame('ok', $this->registro($terminado['implementacion']->refresh())['estado'], 'failed() pisó un resultado ya escrito.');
    }

    /**
     * 3. El job tiene `tries = 1` y un `$timeout` por debajo del `retry_after` de la conexión `database`;
     * y el techo de la llamada HTTP queda por debajo del del job, con margen para escribir el resultado.
     *
     * @return void
     */
    public function test_los_techos_del_job_son_coherentes(): void
    {
        $defaults    = (new \ReflectionClass(EjecutarUserSetupDeImplementacionJob::class))->getDefaultProperties();
        $retry_after = (int) config('queue.connections.database.retry_after');

        $this->assertSame(1, $defaults['tries'], 'Un segundo intento sería otro migrate:fresh.');
        $this->assertSame(1500, $defaults['timeout']);
        $this->assertLessThan($retry_after, $defaults['timeout']);
        $this->assertSame(1200, EjecutarUserSetupDeImplementacionJob::TIMEOUT_DE_LA_LLAMADA);
        $this->assertLessThan($defaults['timeout'], EjecutarUserSetupDeImplementacionJob::TIMEOUT_DE_LA_LLAMADA);
    }
}

<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Jobs\EjecutarUserSetupDeImplementacionJob;
use App\Models\Client;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * El job del user setup revalida la etapa al tomar el turno, deja la marca de que la llamada sale, y el endpoint lee
 * esa marca (misión `implementar-cliente`, revisión del 5/10/2026).
 *
 * 🔴 Dos agujeros que encontró el revisor independiente alrededor del `migrate:fresh` del otro lado:
 *
 *  - H-3. Entre el despacho y el arranque del job (una cola atrasada, un worker parado) alguien pudo avanzar la
 *    implementación a la etapa 3: con la etapa 3 o después ya se cargan datos del negocio y `migrate:fresh` se los
 *    borraría. Ahora el job revalida la etapa bajo lock (si no es la 2 en curso, NO llama a nadie y deja el registro en
 *    `error` con `puede_haber_corrido = false`), y `advance` da 409 mientras haya un user setup en curso.
 *  - H-2. Un `en_curso` colgado se podía volver a despachar con la llamada normal aunque el job HUBIERA llamado al
 *    cliente y se hubiera cortado (un deploy que mató al worker): otro `migrate:fresh` sobre algo que pudo haber
 *    corrido. Ahora el job escribe `llamada_iniciada_at` bajo lock ANTES de llamar: un colgado SIN la marca (el job
 *    nunca arrancó) se reintenta con la llamada normal; uno CON la marca se trata como un error que pudo haber corrido
 *    (`conciliar` o `reintentar`).
 */
class JobDelUserSetupRevalidaYMarcaLaLlamadaTest extends BaseDeImplementaciones
{
    /** El token del intento que se está corriendo. */
    const TOKEN = '2026-10-05T10:00:00.000000Z';

    /** URL de la API activa. */
    const URL_API = 'https://api-panchito.ejemplo.test';

    /**
     * Una implementación con el user setup en curso: etapa 2, formulario enviado, las dos APIs, la instalación real
     * completada y el registro `en_curso`.
     *
     * @param array<string, mixed> $registro Lo que se le suma al registro `en_curso` (iniciado_at, llamada_iniciada_at…).
     *
     * @return array{cliente: Client, implementacion: Implementation}
     */
    private function escenario_en_curso(array $registro = []): array
    {
        $cliente = $this->crear_cliente('Panchito Gómez');
        $cliente->setup_data = ['company_name' => 'Panchito S.A.', 'email' => 'panchito@ejemplo.test', 'doc_number' => '20304050607'];
        $cliente->save();
        $cliente = $this->crear_las_dos_apis($cliente->refresh(), 'panchito');

        $implementacion                    = $this->llevar_a_la_etapa($this->crear_implementacion($cliente), 2);
        $implementacion->form_submitted_at = now();
        $implementacion->save();

        $this->crear_instalacion($cliente, ['status' => 'completada', 'kind' => 'completa']);

        $this->escribir_data_de_la_etapa($implementacion, 2, ['user_setup' => array_merge(
            ['estado' => 'en_curso', 'iniciado_at' => self::TOKEN, 'terminado_at' => null, 'error' => null],
            $registro
        )]);

        return ['cliente' => $cliente->refresh(), 'implementacion' => $implementacion->refresh()];
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
     * El pedido real con la confirmación y lo que se le sume.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $extra          Parámetros extra (`reintentar`, `conciliar`).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function aplicar(Implementation $implementation, array $extra = [])
    {
        return $this->configurar($implementation, array_merge(['dry_run' => false, 'confirm_client_name' => 'Panchito Gómez'], $extra));
    }

    /**
     * El POST de `advance`.
     *
     * @param Implementation $implementation La implementación.
     * @param int            $etapa          `etapa_actual`.
     * @param bool           $dry_run        Simulacro o real.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function avanzar(Implementation $implementation, int $etapa, bool $dry_run = false)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/advance', ['etapa_actual' => $etapa, 'dry_run' => $dry_run], $this->headers());
    }

    /* ------------------------------------------------------------------------------------------
     | El job: la marca y la revalidación
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 La marca `llamada_iniciada_at` ya está escrita CUANDO la llamada sale (no después): se lee la base desde adentro
     * del fake, que es el momento en que el pedido está en vuelo.
     *
     * @return void
     */
    public function test_la_marca_se_escribe_antes_de_llamar_al_cliente(): void
    {
        $e = $this->escenario_en_curso();

        $visto = new \stdClass();
        $visto->marca = null;

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida());
        Http::fake(function ($pedido) use ($visto, $e) {
            $visto->marca = $this->registro($e['implementacion'])['llamada_iniciada_at'] ?? null;

            return Http::response(['ok' => true], 200);
        });

        (new EjecutarUserSetupDeImplementacionJob($e['implementacion']->id, self::TOKEN))->handle();

        $this->assertNotNull($visto->marca, 'Cuando el pedido salió, el registro todavía no tenía llamada_iniciada_at.');
        $this->assertSame('ok', $this->registro($e['implementacion'])['estado']);
        $this->assertNotNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * 🔴 Si entre el despacho y el arranque alguien avanzó la implementación a la etapa 3, el job NO llama a nadie y
     * deja el registro en `error` con `puede_haber_corrido = false` (no queda `en_curso` para siempre).
     *
     * @return void
     */
    public function test_si_la_implementacion_ya_avanzo_el_job_no_llama_a_nadie(): void
    {
        $e = $this->escenario_en_curso();
        Http::fake();

        DB::table('implementations')->where('id', $e['implementacion']->id)->update(['current_stage' => 3]);

        (new EjecutarUserSetupDeImplementacionJob($e['implementacion']->id, self::TOKEN))->handle();

        Http::assertNothingSent();

        $registro = $this->registro($e['implementacion']);

        $this->assertSame('error', $registro['estado']);
        $this->assertFalse($registro['puede_haber_corrido']);
        $this->assertStringContainsString('Descartado', $registro['error']);
        $this->assertStringContainsString('etapa 3', $registro['error']);
        $this->assertArrayNotHasKey('llamada_iniciada_at', $registro, 'No se llamó a nadie: no tiene que haber marca de llamada.');
        $this->assertNull($e['implementacion']->refresh()->user_setup_executed_at);
    }

    /**
     * Lo mismo si la implementación se cerró (status completed) mientras el job esperaba en la cola.
     *
     * @return void
     */
    public function test_si_la_implementacion_se_cerro_el_job_no_llama_a_nadie(): void
    {
        $e = $this->escenario_en_curso();
        Http::fake();

        DB::table('implementations')->where('id', $e['implementacion']->id)->update(['status' => 'completed']);

        (new EjecutarUserSetupDeImplementacionJob($e['implementacion']->id, self::TOKEN))->handle();

        Http::assertNothingSent();
        $this->assertSame('error', $this->registro($e['implementacion'])['estado']);
        $this->assertFalse($this->registro($e['implementacion'])['puede_haber_corrido']);
    }

    /* ------------------------------------------------------------------------------------------
     | El endpoint lee la marca
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Un `en_curso` colgado CON la marca (la llamada salió y se cortó sin dejar el resultado): la llamada normal es
     * 422 que pide decidir, y no se encola nada.
     *
     * @return void
     */
    public function test_un_colgado_que_llamo_pide_decidir(): void
    {
        Queue::fake();
        $e = $this->escenario_en_curso(['iniciado_at' => now()->subMinutes(60)->toISOString(), 'llamada_iniciada_at' => now()->subMinutes(59)->toISOString()]);

        $respuesta = $this->aplicar($e['implementacion']);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('puede_haber_corrido', true);
        $respuesta->assertJsonPath('sugerida', 'conciliar');
        $this->assertStringContainsString('COLGADO', $this->cuerpo($respuesta));
        $this->assertStringContainsString('conciliar', $this->cuerpo($respuesta));
        $this->assertStringContainsString('reintentar', $this->cuerpo($respuesta));
        Queue::assertNothingPushed();
    }

    /**
     * `conciliar` sobre un colgado que llamó: no llama al cliente, llena el candado y deja el registro en `ok`.
     *
     * @return void
     */
    public function test_un_colgado_que_llamo_se_puede_conciliar(): void
    {
        Queue::fake();
        $e = $this->escenario_en_curso(['iniciado_at' => now()->subMinutes(60)->toISOString(), 'llamada_iniciada_at' => now()->subMinutes(59)->toISOString()]);

        $respuesta = $this->aplicar($e['implementacion'], ['conciliar' => true]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('conciliado', true);
        $this->assertNotNull($e['implementacion']->refresh()->user_setup_executed_at);
        $this->assertSame('ok', $this->registro($e['implementacion'])['estado']);
        Queue::assertNothingPushed();
    }

    /**
     * `reintentar` sobre un colgado que llamó: despacha un job nuevo con un token nuevo y un registro `en_curso` SIN la
     * marca del intento anterior.
     *
     * @return void
     */
    public function test_un_colgado_que_llamo_se_puede_reintentar_con_un_token_nuevo(): void
    {
        Queue::fake();
        $e = $this->escenario_en_curso(['iniciado_at' => now()->subMinutes(60)->toISOString(), 'llamada_iniciada_at' => now()->subMinutes(59)->toISOString()]);

        $token_viejo = $this->registro($e['implementacion'])['iniciado_at'];

        $respuesta = $this->aplicar($e['implementacion'], ['reintentar' => true]);

        $respuesta->assertStatus(202);
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);

        $registro = $this->registro($e['implementacion']);

        $this->assertSame('en_curso', $registro['estado']);
        $this->assertArrayNotHasKey('llamada_iniciada_at', $registro, 'El intento nuevo no hereda la marca del anterior.');
        $this->assertNotSame($token_viejo, $registro['iniciado_at'], 'El intento nuevo lleva un token distinto: el job viejo, si arranca, se descarta solo.');
    }

    /**
     * Un colgado SIN la marca (el job nunca arrancó: la cola estaba parada): no salió nada, así que se reintenta con la
     * llamada normal; y `conciliar` / `reintentar` no tienen sentido y se rechazan.
     *
     * @return void
     */
    public function test_un_colgado_que_nunca_llamo_se_reintenta_con_la_llamada_normal(): void
    {
        Queue::fake();
        $e = $this->escenario_en_curso(['iniciado_at' => now()->subMinutes(60)->toISOString()]);

        $this->aplicar($e['implementacion'], ['conciliar' => true])->assertStatus(422);
        $this->aplicar($e['implementacion'], ['reintentar' => true])->assertStatus(422);
        Queue::assertNothingPushed();

        $this->aplicar($e['implementacion'])->assertStatus(202);
        Queue::assertPushed(EjecutarUserSetupDeImplementacionJob::class, 1);
    }

    /**
     * El dry-run de un colgado que llamó dice qué corresponde (`conciliar`) y los parámetros para aplicar.
     *
     * @return void
     */
    public function test_el_dry_run_de_un_colgado_que_llamo_dice_conciliar(): void
    {
        $e = $this->escenario_en_curso(['iniciado_at' => now()->subMinutes(60)->toISOString(), 'llamada_iniciada_at' => now()->subMinutes(59)->toISOString()]);

        $dry = $this->configurar($e['implementacion'], []);

        $dry->assertStatus(200);
        $dry->assertJsonPath('corresponde', 'conciliar');
        $dry->assertJsonPath('parametros_para_aplicar', ['conciliar' => true]);
        $dry->assertJsonPath('ultimo_intento.puede_haber_corrido', true);
        $this->assertStringContainsString('colgado después de llamar', (string) $dry->json('nota'));
    }

    /* ------------------------------------------------------------------------------------------
     | advance
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 No se avanza de la etapa 2 con un user setup EN CURSO (ni siquiera en dry-run): 409, y la implementación queda
     * donde estaba.
     *
     * @return void
     */
    public function test_no_se_avanza_de_la_etapa_2_con_un_user_setup_en_curso(): void
    {
        $e = $this->escenario_en_curso(['iniciado_at' => now()->subMinutes(2)->toISOString(), 'llamada_iniciada_at' => now()->subMinute()->toISOString()]);

        foreach ([true, false] as $dry_run) {
            $respuesta = $this->avanzar($e['implementacion'], 2, $dry_run);

            $respuesta->assertStatus(409);
            $this->assertStringContainsString('user setup en curso', $this->cuerpo($respuesta));
            $respuesta->assertJsonPath('user_setup.estado', 'en_curso');
        }

        $this->assertSame(2, (int) $e['implementacion']->refresh()->current_stage);
    }

    /**
     * Lo que NO frena el avance: un user setup colgado (hay que resolverlo, no esperarlo), uno ya aplicado, y cerrar
     * cualquier otra etapa.
     *
     * @return void
     */
    public function test_el_avance_sigue_andando_si_el_user_setup_no_esta_en_curso(): void
    {
        /* Colgado: hace más de 45 minutos sin señal. */
        $colgado = $this->escenario_en_curso(['iniciado_at' => now()->subMinutes(60)->toISOString()]);
        $this->avanzar($colgado['implementacion'], 2)->assertStatus(200)->assertJsonPath('implementation.current_stage', 3);

        /* Aplicado: el candado lleno y el registro en ok. */
        $aplicado = $this->escenario_en_curso();
        $this->escribir_data_de_la_etapa($aplicado['implementacion'], 2, ['user_setup' => ['estado' => 'ok', 'iniciado_at' => self::TOKEN]]);
        $aplicado['implementacion']->user_setup_executed_at = now();
        $aplicado['implementacion']->save();
        $this->avanzar($aplicado['implementacion'], 2)->assertStatus(200)->assertJsonPath('implementation.current_stage', 3);

        /* Un registro en curso y reciente NO frena cerrar otra etapa que no sea la 2. */
        $otra = $this->escenario_en_curso(['iniciado_at' => now()->subMinute()->toISOString()]);
        DB::table('implementations')->where('id', $otra['implementacion']->id)->update(['current_stage' => 3]);
        ImplementationStage::where('implementation_id', $otra['implementacion']->id)->where('stage_number', 3)->update(['status' => 'in_progress']);
        $this->avanzar($otra['implementacion']->refresh(), 3)->assertStatus(200)->assertJsonPath('implementation.current_stage', 4);
    }
}

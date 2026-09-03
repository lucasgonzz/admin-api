<?php

namespace Tests\Feature;

use App\Models\FollowupRule;
use App\Models\Lead;
use App\Models\LeadPipelineStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La CADENCIA de los seguimientos automáticos por estado (`POST claude/followup-rules`).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 QUE UN `estado` QUE NO ES DEL PIPELINE SEA 422, Y CON LA LISTA DE LOS VÁLIDOS EN EL
 *     CUERPO. Es la asimetría deliberada con `POST claude/followup-templates`, donde un estado
 *     inexistente sí es válido a propósito. Una REGLA con un estado que no existe queda cargada
 *     PARECIENDO que anda: `LeadFollowupService` la indexa con `keyBy('estado')` y ningún lead va a
 *     tener nunca ese `status`, así que nadie recibe nada y nada lo denuncia.
 *  2. 🔴 QUE `dry_run` SEA EL DEFAULT Y NO ESCRIBA NADA. Una regla gobierna a TODOS los leads de
 *     ese estado de una: no hay "probar con uno".
 *  3. La idempotencia por `estado`, que además es la única forma de que reenviar el lote no explote
 *     contra el índice único de la tabla.
 *  4. Que sea ADITIVO: un lote parcial no puede llevarse puestas las reglas que ya estaban.
 *  5. 🔴 QUE LOS RANGOS DE `horas_espera` ESTÉN ATADOS AL CRON Y NO A UN GUSTO. El mínimo es 3
 *     porque `leads:check-followups` corre `everyTwoHours()`: con 1 o con 2, cada corrida cumple
 *     la condición de `process_lead()` y la cadencia real pasa a ser un mensaje cada dos horas. El
 *     máximo es 720 horas, y el caso que lo justifica no es el 500 de `4294967296` sino el
 *     `4294967295`, que entra en la columna y deja una regla activa, visible en el panel, esperando
 *     490.000 años.
 *  6. 🔴 QUE APAGAR LA ÚLTIMA REGLA ACTIVA NO SALGA GRATIS. Un lote de baja deja el motor mudo y
 *     nada lo denuncia: la simulación y la respuesta lo dicen, y aplicarlo exige un flag aparte.
 *  7. 🔴 QUE LA SIMULACIÓN DIGA A CUÁNTOS LES DISPARA YA. Prender una regla en un estado con leads
 *     parados no empieza una cadencia: les manda un WhatsApp a todos dentro de las dos horas,
 *     porque `last_message_at()` cae a `created_at` cuando el lead no tiene mensajes.
 *  8. 🔴 QUE EL `confirm_token` CUBRA LA POBLACIÓN, que es justo el número que la nota pide mirar.
 *  9. 🔴 QUE `activa` SEA EXPLÍCITO, igual que en el protocolo. La asimetría estaba al revés del
 *     riesgo: una entrada de protocolo la lee una persona, una regla hace que el cron escriba.
 * 10. ⚠️ Que el reintento que el docblock declara seguro devuelva 200 y no 422.
 */
class CadenciaDeSeguimientosPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del bloque claude/*. */
    const CLAVE = 'clave-de-prueba-followup-rules';

    /**
     * Setea la clave de ingesta: en el `.env.testing` del slot está vacía y el middleware es
     * fail-closed, así que sin esto todo el bloque daría 401 y estaríamos midiendo el middleware.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Pega al endpoint.
     *
     * @param array<string, mixed> $payload Body completo.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function pegar(array $payload)
    {
        return $this->withHeaders($this->headers())->postJson('/api/claude/followup-rules', $payload);
    }

    /**
     * Corre la simulación y después aplica de verdad, con el `confirm_count` y el `confirm_token`
     * que devolvió la simulación. Es el flujo real de dos pasos del endpoint.
     *
     * @param array<int, array<string, mixed>> $reglas Lote.
     *
     * @return \Illuminate\Testing\TestResponse La respuesta de la aplicación real.
     */
    private function simular_y_aplicar(array $reglas)
    {
        $simulacion = $this->pegar(['reglas' => $reglas]);
        $simulacion->assertStatus(200);

        return $this->pegar([
            'reglas'        => $reglas,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
            'confirm_token' => $simulacion->json('confirm_token'),
        ]);
    }

    /**
     * Un lote de dos reglas sobre estados reales del pipeline.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dos_reglas(): array
    {
        return [
            ['estado' => 'solicita_disponibilidad', 'horas_espera' => 6, 'max_followups' => 3, 'activa' => true, 'descripcion' => 'Dijo que sí y no cerró horario.'],
            ['estado' => 'closer_activo', 'horas_espera' => 48, 'max_followups' => 2, 'activa' => true, 'descripcion' => 'El closer lo tiene y no avanza.'],
        ];
    }

    /**
     * Apaga TODAS las reglas activas que haya hoy en la base, con el flag explícito. Sirve para
     * dejar el motor en un estado conocido antes de medir el conteo de reglas activas.
     *
     * @return void
     */
    private function apagar_todo_lo_activo(): void
    {
        $activas = FollowupRule::where('activa', true)->get();
        if ($activas->isEmpty()) {
            return;
        }

        $reglas = [];
        foreach ($activas as $regla) {
            $reglas[] = ['estado' => $regla->estado, 'activa' => false];
        }

        $simulacion = $this->pegar(['reglas' => $reglas]);
        $this->pegar([
            'reglas'                 => $reglas,
            'dry_run'                => false,
            'confirm_count'          => $simulacion->json('cambiarian'),
            'confirm_token'          => $simulacion->json('confirm_token'),
            'confirm_apagar_todas'   => true,
        ])->assertStatus(200);
    }

    /* ------------------------------------------------------------------------------------------
     | La puerta
     |------------------------------------------------------------------------------------------ */

    /**
     * Sin la clave del header no entra nada.
     *
     * @return void
     */
    public function test_sin_la_clave_el_endpoint_rechaza(): void
    {
        $response = $this->postJson('/api/claude/followup-rules', ['reglas' => $this->dos_reglas()]);

        $response->assertStatus(401);
        $this->assertSame(0, FollowupRule::where('estado', 'solicita_disponibilidad')->count());
    }

    /* ------------------------------------------------------------------------------------------
     | Camino feliz e idempotencia
     |------------------------------------------------------------------------------------------ */

    /**
     * El camino feliz completo: simular y aplicar deja las dos reglas cargadas.
     *
     * @return void
     */
    public function test_el_camino_feliz_crea_las_reglas_con_su_cadencia(): void
    {
        $response = $this->simular_y_aplicar($this->dos_reglas());

        $response->assertStatus(200);
        $response->assertJsonPath('dry_run', false);
        $response->assertJsonPath('resultados.creadas', 2);
        $response->assertJsonPath('resultados.actualizadas', 0);

        $regla = FollowupRule::where('estado', 'solicita_disponibilidad')->first();
        $this->assertNotNull($regla, 'No se creó la regla de solicita_disponibilidad.');
        $this->assertSame(6, (int) $regla->horas_espera);
        $this->assertSame(3, (int) $regla->max_followups);
        $this->assertTrue((bool) $regla->activa, 'La regla nueva no quedó activa por default.');
        $this->assertSame('Dijo que sí y no cerró horario.', $regla->descripcion);
    }

    /**
     * 🔴 Reenviar el mismo lote no duplica ni escribe: la segunda corrida es toda `sin_cambio`.
     *
     * Sin idempotencia por `estado` esto ni siquiera duplicaría: le explotaría el índice único de
     * `followup_rules.estado` en la cara.
     *
     * @return void
     */
    public function test_reenviar_el_mismo_lote_no_duplica_ni_escribe(): void
    {
        $this->simular_y_aplicar($this->dos_reglas())->assertStatus(200);

        $segunda = $this->pegar(['reglas' => $this->dos_reglas()]);

        $segunda->assertStatus(200);
        $segunda->assertJsonPath('cambiarian', 0);
        $this->assertCount(2, $segunda->json('sin_cambio'), 'La segunda corrida no reportó las dos reglas como sin_cambio.');

        $this->assertSame(1, FollowupRule::where('estado', 'solicita_disponibilidad')->count(), 'Se duplicó la regla.');
        $this->assertSame(1, FollowupRule::where('estado', 'closer_activo')->count(), 'Se duplicó la regla.');
    }

    /**
     * Reenviar el lote con una cadencia corregida ACTUALIZA la fila, no crea una segunda.
     *
     * @return void
     */
    public function test_reenviar_con_la_cadencia_corregida_actualiza_la_fila(): void
    {
        $this->simular_y_aplicar($this->dos_reglas())->assertStatus(200);

        $corregido = $this->dos_reglas();
        $corregido[0]['horas_espera'] = 12;

        $response = $this->simular_y_aplicar($corregido);

        $response->assertStatus(200);
        $response->assertJsonPath('resultados.creadas', 0);
        $response->assertJsonPath('resultados.actualizadas', 1);

        $this->assertSame(1, FollowupRule::where('estado', 'solicita_disponibilidad')->count());
        $this->assertSame(12, (int) FollowupRule::where('estado', 'solicita_disponibilidad')->first()->horas_espera);
    }

    /**
     * Un lote parcial no borra las reglas que no vinieron en el payload.
     *
     * @return void
     */
    public function test_el_alta_nunca_borra_las_reglas_que_no_vinieron(): void
    {
        $this->simular_y_aplicar($this->dos_reglas())->assertStatus(200);

        $this->simular_y_aplicar([
            ['estado' => 'calificado', 'horas_espera' => 24, 'max_followups' => 3, 'activa' => true],
        ])->assertStatus(200);

        $this->assertSame(1, FollowupRule::where('estado', 'solicita_disponibilidad')->count(), 'Un lote de una regla borró las otras.');
        $this->assertSame(1, FollowupRule::where('estado', 'closer_activo')->count(), 'Un lote de una regla borró las otras.');
        $this->assertSame(1, FollowupRule::where('estado', 'calificado')->count());
    }

    /**
     * Al EDITAR, un campo ausente arrastra lo que la fila ya tenía en vez de borrarlo. Es lo que
     * permite apagar una regla sin conocer su cadencia de memoria.
     *
     * @return void
     */
    public function test_apagar_una_regla_no_le_borra_la_cadencia(): void
    {
        $this->simular_y_aplicar($this->dos_reglas())->assertStatus(200);

        $this->simular_y_aplicar([
            ['estado' => 'solicita_disponibilidad', 'activa' => false],
        ])->assertStatus(200);

        $regla = FollowupRule::where('estado', 'solicita_disponibilidad')->first();
        $this->assertFalse((bool) $regla->activa, 'La regla no quedó apagada.');
        $this->assertSame(6, (int) $regla->horas_espera, 'Apagar la regla le borró las horas de espera.');
        $this->assertSame(3, (int) $regla->max_followups, 'Apagar la regla le borró el máximo de seguimientos.');
    }

    /* ------------------------------------------------------------------------------------------
     | Los frenos, uno por uno
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 `dry_run` es el default y NO escribe absolutamente nada.
     *
     * @return void
     */
    public function test_dry_run_es_el_default_y_no_escribe_nada(): void
    {
        $response = $this->pegar(['reglas' => $this->dos_reglas()]);

        $response->assertStatus(200);
        $response->assertJsonPath('dry_run', true);
        $response->assertJsonPath('cambiarian', 2);

        $this->assertSame(
            0,
            FollowupRule::whereIn('estado', ['solicita_disponibilidad', 'closer_activo'])->count(),
            'La simulación escribió reglas en la base.'
        );
    }

    /**
     * 🔴 La simulación dice CUÁNTOS LEADS hay hoy en ese estado: es el número que convierte
     * "cambio una regla" en "le cambio la cadencia a esta cantidad de personas".
     *
     * @return void
     */
    public function test_la_simulacion_dice_cuantos_leads_hay_en_ese_estado(): void
    {
        $estado = 'solicita_disponibilidad';
        $antes  = Lead::where('status', $estado)->count();

        for ($i = 0; $i < 3; $i++) {
            $lead               = new Lead();
            $lead->uuid         = (string) Str::uuid();
            $lead->contact_name = 'Lead de cadencia ' . $i;
            $lead->status       = $estado;
            $lead->save();
        }

        $response = $this->pegar(['reglas' => [
            ['estado' => $estado, 'horas_espera' => 6, 'max_followups' => 3, 'activa' => true],
        ]]);

        $response->assertStatus(200);
        $response->assertJsonPath('cambios.0.leads_en_ese_estado', $antes + 3);
        $response->assertJsonPath('cambios.0.accion', 'crear');
        $response->assertJsonPath('cambios.0.actual.horas_espera', null);
        $response->assertJsonPath('cambios.0.propuesto.horas_espera', 6);
    }

    /**
     * `confirm_count` que no coincide con la simulación es 422 y no escribe nada.
     *
     * @return void
     */
    public function test_confirm_count_que_no_coincide_es_422(): void
    {
        $simulacion = $this->pegar(['reglas' => $this->dos_reglas()]);

        $response = $this->pegar([
            'reglas'        => $this->dos_reglas(),
            'dry_run'       => false,
            'confirm_count' => 1,
            'confirm_token' => $simulacion->json('confirm_token'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('cambiarian', 2);
        $this->assertSame(0, FollowupRule::whereIn('estado', ['solicita_disponibilidad', 'closer_activo'])->count());
    }

    /**
     * Sin `confirm_count` con `dry_run=false` es 422.
     *
     * @return void
     */
    public function test_sin_confirm_count_con_dry_run_false_es_422(): void
    {
        $response = $this->pegar(['reglas' => $this->dos_reglas(), 'dry_run' => false]);

        $response->assertStatus(422);
        $this->assertSame(0, FollowupRule::whereIn('estado', ['solicita_disponibilidad', 'closer_activo'])->count());
    }

    /**
     * Un `confirm_token` que no corresponde al conjunto simulado es 422.
     *
     * @return void
     */
    public function test_confirm_token_que_no_corresponde_es_422(): void
    {
        $response = $this->pegar([
            'reglas'        => $this->dos_reglas(),
            'dry_run'       => false,
            'confirm_count' => 2,
            'confirm_token' => str_repeat('a', 32),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('confirm_token_esperado', (array) $response->json());
        $this->assertSame(0, FollowupRule::whereIn('estado', ['solicita_disponibilidad', 'closer_activo'])->count());
    }

    /**
     * 🔴 EL FRENO CENTRAL: un `estado` que no es un slug del pipeline es 422 CON LA LISTA DE LOS
     * VÁLIDOS EN EL CUERPO.
     *
     * @return void
     */
    public function test_un_estado_que_no_es_del_pipeline_es_422_con_la_lista_de_los_validos(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'manual_coordinacion', 'horas_espera' => 24, 'max_followups' => 3, 'activa' => true],
        ]]);

        $response->assertStatus(422);

        $validos = (array) $response->json('estados_validos');
        $this->assertNotEmpty($validos, 'El 422 no trajo la lista de estados válidos en el cuerpo.');
        $this->assertContains('solicita_disponibilidad', $validos);
        $this->assertNotContains('manual_coordinacion', $validos);

        $this->assertSame(0, FollowupRule::where('estado', 'manual_coordinacion')->count());
    }

    /**
     * 🔴 La asimetría, medida de las dos puntas en la misma corrida: el MISMO `estado` que las
     * reglas rechazan es válido a propósito en las plantillas.
     *
     * @return void
     */
    public function test_el_mismo_estado_manual_lo_rechazan_las_reglas_y_lo_aceptan_las_plantillas(): void
    {
        $estado = 'manual_coordinacion';

        $this->assertNotContains($estado, LeadPipelineStatus::all_slugs(), 'El estado de prueba dejó de ser inexistente.');

        $this->pegar(['reglas' => [
            ['estado' => $estado, 'horas_espera' => 24, 'max_followups' => 3, 'activa' => true],
        ]])->assertStatus(422);

        $this->withHeaders($this->headers())->postJson('/api/claude/followup-templates', [
            'templates' => [[
                'template_name' => 'cc_asimetria_' . Str::random(6),
                'estado'        => $estado,
                'dia_numero'    => 1,
                'body_template' => 'Hola {{1}}! Plantilla manual de prueba.',
            ]],
        ])->assertStatus(200);
    }

    /**
     * `horas_espera` en 0 es 422: convertiría el cron de cada dos horas en un martilleo.
     *
     * @return void
     */
    public function test_horas_espera_en_cero_es_422(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'calificado', 'horas_espera' => 0, 'max_followups' => 3, 'activa' => true],
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, FollowupRule::where('estado', 'calificado')->count());
    }

    /**
     * `max_followups` arriba de 10 es 422.
     *
     * @return void
     */
    public function test_max_followups_arriba_de_diez_es_422(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'calificado', 'horas_espera' => 24, 'max_followups' => 11, 'activa' => true],
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, FollowupRule::where('estado', 'calificado')->count());
    }

    /**
     * Crear una regla sin la cadencia entera es 422: una cadencia a medias no es una cadencia.
     *
     * @return void
     */
    public function test_crear_una_regla_sin_la_cadencia_entera_es_422(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'calificado', 'activa' => true],
        ]]);

        $response->assertStatus(422);
        $this->assertSame(['horas_espera', 'max_followups'], (array) $response->json('faltantes'));
        $this->assertSame(0, FollowupRule::where('estado', 'calificado')->count());
    }

    /**
     * Un `estado` repetido dentro del mismo lote es 422: las dos filas se pisarían y no se sabría
     * cuál ganó.
     *
     * @return void
     */
    public function test_un_estado_repetido_en_el_lote_es_422(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'calificado', 'horas_espera' => 24, 'max_followups' => 3, 'activa' => true],
            ['estado' => 'calificado', 'horas_espera' => 48, 'max_followups' => 1, 'activa' => true],
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, FollowupRule::where('estado', 'calificado')->count());
    }

    /**
     * La simulación avisa cuando el estado es uno que el cron nunca procesa: la regla sería inerte.
     *
     * @return void
     */
    public function test_la_simulacion_avisa_si_el_estado_es_uno_que_el_cron_nunca_procesa(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'en_pausa', 'horas_espera' => 24, 'max_followups' => 3, 'activa' => true],
        ]]);

        $response->assertStatus(200);
        $this->assertArrayHasKey(
            'advertencia',
            (array) $response->json('cambios.0'),
            'La simulación no avisó que una regla para en_pausa es inerte.'
        );
    }

    /* ------------------------------------------------------------------------------------------
     | Los rangos de `horas_espera`, atados al cron
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 `horas_espera` menor a 3 es 422: no frena el martilleo que el mínimo dice frenar.
     *
     * `app/Console/Kernel.php` corre `leads:check-followups` con `everyTwoHours()`, y
     * `LeadFollowupService::process_lead()` compara `$hours < (int) $rule->horas_espera`. Con 1 o
     * con 2, CADA corrida del cron cumple la condición y la cadencia real pasa a ser "un mensaje
     * cada dos horas": diez mensajes en veinte horas a una persona real. 3 es el primer valor que
     * obliga a saltear una corrida.
     *
     * @return void
     */
    public function test_horas_espera_menor_a_tres_es_422_porque_el_cron_corre_cada_dos_horas(): void
    {
        foreach ([1, 2] as $horas) {
            $response = $this->pegar(['reglas' => [
                ['estado' => 'calificado', 'horas_espera' => $horas, 'max_followups' => 3, 'activa' => true],
            ]]);

            $response->assertStatus(422);
            $this->assertSame(0, FollowupRule::where('estado', 'calificado')->count(), 'Se escribió con horas_espera=' . $horas);
        }
    }

    /**
     * 🔴 Un `horas_espera` que no entra en `int unsigned` es 422 en la simulación, no 500 al
     * escribir. `4294967296` es `UINT_MAX + 1`: la validación no tenía ningún `max`, así que pasaba
     * y le explotaba a MySQL en la cara.
     *
     * @return void
     */
    public function test_horas_espera_que_no_entra_en_la_columna_es_422_y_no_500(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'calificado', 'horas_espera' => 4294967296, 'max_followups' => 3, 'activa' => true],
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, FollowupRule::where('estado', 'calificado')->count());
    }

    /**
     * 🔴 Y `4294967295`, que SÍ entra en la columna, también es 422: la regla quedaría activa, se
     * vería en el panel y esperaría 490.000 años. Es el mismo modo de fallo por el que un `estado`
     * inventado es 422 —"queda cargada pareciendo que anda"—, por el otro campo.
     *
     * @return void
     */
    public function test_horas_espera_absurdo_pero_que_entra_en_la_columna_tambien_es_422(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'calificado', 'horas_espera' => 4294967295, 'max_followups' => 3, 'activa' => true],
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, FollowupRule::where('estado', 'calificado')->count());
    }

    /* ------------------------------------------------------------------------------------------
     | Lo que la simulación tiene que decir antes de que alguien confirme
     |------------------------------------------------------------------------------------------ */

    /**
     * 🔴 `activa` es obligatorio y explícito también acá. La asimetría con `protocol-entries`
     * estaba al revés del riesgo: una entrada de protocolo es texto que lee una persona, una regla
     * hace que el cron le escriba a leads reales.
     *
     * @return void
     */
    public function test_activa_es_obligatorio_al_crear_una_regla(): void
    {
        $response = $this->pegar(['reglas' => [
            ['estado' => 'calificado', 'horas_espera' => 24, 'max_followups' => 3],
        ]]);

        $response->assertStatus(422);
        $this->assertSame(0, FollowupRule::where('estado', 'calificado')->count());
    }

    /**
     * 🔴 Apagar la ÚLTIMA regla activa deja el motor de seguimientos mudo, y eso hay que decirlo y
     * confirmarlo aparte: un lote de baja no puede salir tan barato como uno de alta.
     *
     * @return void
     */
    public function test_apagar_la_ultima_regla_activa_exige_un_flag_explicito(): void
    {
        $this->apagar_todo_lo_activo();
        $this->simular_y_aplicar($this->dos_reglas())->assertStatus(200);
        $this->assertSame(2, FollowupRule::where('activa', true)->count());

        $apagar = [
            ['estado' => 'solicita_disponibilidad', 'activa' => false],
            ['estado' => 'closer_activo', 'activa' => false],
        ];

        $simulacion = $this->pegar(['reglas' => $apagar]);
        $simulacion->assertStatus(200);
        $simulacion->assertJsonPath('motor_de_seguimientos.reglas_activas_hoy', 2);
        $simulacion->assertJsonPath('motor_de_seguimientos.reglas_activas_despues', 0);
        $this->assertArrayHasKey(
            'advertencia',
            (array) $simulacion->json('motor_de_seguimientos'),
            'La simulación no avisó que el cron de seguimientos queda sin ninguna regla.'
        );

        $sin_flag = $this->pegar([
            'reglas'        => $apagar,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
            'confirm_token' => $simulacion->json('confirm_token'),
        ]);

        $sin_flag->assertStatus(422);
        $this->assertSame(2, FollowupRule::where('activa', true)->count(), 'Se apagaron todas las reglas sin el flag.');

        $con_flag = $this->pegar([
            'reglas'               => $apagar,
            'dry_run'              => false,
            'confirm_count'        => $simulacion->json('cambiarian'),
            'confirm_token'        => $simulacion->json('confirm_token'),
            'confirm_apagar_todas' => true,
        ]);

        $con_flag->assertStatus(200);
        $con_flag->assertJsonPath('motor_de_seguimientos.reglas_activas_despues', 0);
        $this->assertSame(0, FollowupRule::where('activa', true)->count());
    }

    /**
     * 🔴 Prender una regla en un estado con leads parados dispara una tanda INMEDIATA:
     * `LeadFollowupService::last_message_at()` cae a `$lead->created_at` cuando el lead no tiene
     * mensajes, así que un lead parado hace meses cumple CUALQUIER `horas_espera`. La simulación
     * tiene que decir a cuántos les sale el mensaje en la próxima corrida del cron, no sólo cuántos
     * hay en ese estado.
     *
     * @return void
     */
    public function test_la_simulacion_dice_a_cuantos_leads_les_dispara_ya_mismo(): void
    {
        $estado = 'solicita_disponibilidad';

        $regla = [['estado' => $estado, 'horas_espera' => 24, 'max_followups' => 3, 'activa' => true]];

        $antes = $this->pegar(['reglas' => $regla]);
        $antes->assertStatus(200);
        $base_inmediato = (int) $antes->json('cambios.0.disparo_inmediato');
        $base_en_estado = (int) $antes->json('cambios.0.leads_en_ese_estado');

        for ($i = 0; $i < 3; $i++) {
            $lead               = new Lead();
            $lead->uuid         = (string) Str::uuid();
            $lead->contact_name = 'Lead parado hace meses ' . $i;
            $lead->status       = $estado;
            $lead->created_at   = now()->subMonths(6);
            $lead->save();
        }

        $despues = $this->pegar(['reglas' => $regla]);
        $despues->assertStatus(200);
        $despues->assertJsonPath('cambios.0.leads_en_ese_estado', $base_en_estado + 3);
        $despues->assertJsonPath('cambios.0.disparo_inmediato', $base_inmediato + 3);
    }

    /**
     * 🔴 El `confirm_token` cubre `leads_en_ese_estado` y el disparo inmediato, que son justo los
     * números que la nota de la simulación pide mirar.
     *
     * Medido contra el código anterior: se simulaba con un lead en el estado, se metían cinco más y
     * se confirmaba con el mismo token y el mismo count — 200, escribía, y la población a la que le
     * cambiaba la cadencia había pasado de 1 a 6 sin que el token se enterara.
     *
     * @return void
     */
    public function test_el_confirm_token_cambia_si_cambia_la_poblacion_de_leads(): void
    {
        $estado = 'closer_activo';
        $regla  = [['estado' => $estado, 'horas_espera' => 24, 'max_followups' => 3, 'activa' => true]];

        $simulacion = $this->pegar(['reglas' => $regla]);
        $simulacion->assertStatus(200);

        for ($i = 0; $i < 5; $i++) {
            $lead               = new Lead();
            $lead->uuid         = (string) Str::uuid();
            $lead->contact_name = 'Lead que entró después de la simulación ' . $i;
            $lead->status       = $estado;
            $lead->created_at   = now()->subMonths(6);
            $lead->save();
        }

        $segunda = $this->pegar(['reglas' => $regla]);
        $segunda->assertStatus(200);

        $this->assertNotSame(
            $simulacion->json('confirm_token'),
            $segunda->json('confirm_token'),
            'Entraron cinco leads al estado y el confirm_token no cambió.'
        );

        $con_token_viejo = $this->pegar([
            'reglas'        => $regla,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
            'confirm_token' => $simulacion->json('confirm_token'),
        ]);

        $con_token_viejo->assertStatus(422);
        $this->assertSame(0, FollowupRule::where('estado', $estado)->count(), 'Escribió con el token de una población vieja.');
    }

    /**
     * ⚠️ Reintentar la misma llamada cuando ya no hay nada que cambiar da 200 `sin_cambio`, que es
     * lo que el docblock del endpoint promete. Ver el test gemelo en
     * {@see ProtocoloDeVentasPorClaudeTest}.
     *
     * @return void
     */
    public function test_reintentar_cuando_no_hay_nada_que_cambiar_es_200(): void
    {
        $reglas     = $this->dos_reglas();
        $simulacion = $this->pegar(['reglas' => $reglas]);
        $simulacion->assertStatus(200);

        $body = [
            'reglas'        => $reglas,
            'dry_run'       => false,
            'confirm_count' => $simulacion->json('cambiarian'),
            'confirm_token' => $simulacion->json('confirm_token'),
        ];

        $this->pegar($body)->assertStatus(200);

        $reintento = $this->pegar($body);

        $reintento->assertStatus(200);
        $reintento->assertJsonPath('resultados.creadas', 0);
        $reintento->assertJsonPath('resultados.actualizadas', 0);
        $this->assertSame(1, FollowupRule::where('estado', 'solicita_disponibilidad')->count());
    }
}

<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\ClientInstallation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use Illuminate\Support\Facades\Queue;

/**
 * Avanzar (o cerrar) la etapa actual por `claude/*`: `POST claude/implementations/{id}/advance`.
 *
 * Lo que se protege, en orden de importancia:
 *
 *  1. 🔴 `dry_run` por defecto: la primera llamada no avanza nada.
 *  2. 🔴 `etapa_actual` tiene que ser la real (409 con la verdadera): es lo que impide avanzar dos veces
 *     por un reintento. Y una implementación completada no avanza.
 *  3. 🔴 NO dispara `handle_stage_advance`: entrar a la etapa 2 no crea una `ClientInstallation` (la crea
 *     `install`) y, aun en modo `auto`, no manda mensajes ni encola jobs. Es la diferencia más
 *     importante con el botón del panel.
 *  4. 🔴 Desde la etapa 7 —la última, ARCA— cierra la implementación y deja `current_stage` en 7: mientras
 *     esté abierta, el WhatsApp del cliente cae al hilo de la implementación. (Hasta el 15/10/2026 el flujo
 *     tenía OCHO etapas y cerraba la 8; ver los tests de compatibilidad más abajo: una implementación que
 *     ya está en la 8 se sigue cerrando, y la fila 8 pendiente de una vieja queda `skipped`.)
 *  5. `saltar` deja la etapa en `skipped`, y la nota queda en `data.notas`.
 */
class AvanceDeEtapaPorClaudeTest extends BaseDeImplementaciones
{
    /**
     * Una implementación en la etapa pedida, con su cliente.
     *
     * @param int $etapa Etapa en la que está.
     *
     * @return \App\Models\Implementation
     */
    private function en_la_etapa(int $etapa)
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), 'panchito');

        return $this->llevar_a_la_etapa($this->crear_implementacion($cliente), $etapa);
    }

    /**
     * Una implementación ANTERIOR al 15/10/2026 (con la fila de la etapa 8) en la etapa pedida.
     *
     * @param int $etapa Etapa en la que está.
     *
     * @return \App\Models\Implementation
     */
    private function anterior_en_la_etapa(int $etapa)
    {
        $cliente = $this->crear_las_dos_apis($this->crear_cliente('Panchito Gómez'), 'panchito');

        return $this->llevar_a_la_etapa($this->crear_implementacion_anterior($cliente), $etapa);
    }

    /**
     * El POST de avanzar.
     *
     * @param \App\Models\Implementation $implementation La implementación.
     * @param array<string, mixed>       $cuerpo         Body.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function avanzar($implementation, array $cuerpo)
    {
        return $this->postJson('/api/claude/implementations/' . $implementation->id . '/advance', $cuerpo, $this->headers());
    }

    /**
     * Estados de las etapas de la implementación (una por fila), en orden.
     *
     * @param \App\Models\Implementation $implementation La implementación.
     *
     * @return array<int, string>
     */
    private function estados($implementation): array
    {
        return ImplementationStage::where('implementation_id', $implementation->id)->orderBy('stage_number')->pluck('status')->all();
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
        $this->postJson('/api/claude/implementations/1/advance', ['etapa_actual' => 1])->assertStatus(401);
    }

    /**
     * Un parámetro desconocido es 422 y no avanza nada.
     *
     * @return void
     */
    public function test_un_parametro_desconocido_es_422_y_no_avanza(): void
    {
        $impl = $this->en_la_etapa(1);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 1, 'dry_run' => false, 'a_etapa' => 5]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('a_etapa', $this->cuerpo($respuesta));
        $this->assertSame(1, (int) $impl->refresh()->current_stage);
    }

    /**
     * `etapa_actual` es obligatoria y va de 1 a 8: 422 en español.
     *
     * 🔴 El tope SIGUE en 8 aunque el flujo ahora tiene siete etapas, a propósito: una implementación
     * anterior al 15/10/2026 que quedó en la etapa 8 tiene que poder cerrarse (ver más abajo), y para eso la
     * validación tiene que aceptar el 8. Este test no cambió.
     *
     * @return void
     */
    public function test_etapa_actual_es_obligatoria_y_va_de_1_a_8(): void
    {
        $impl = $this->en_la_etapa(1);

        $sin = $this->avanzar($impl, ['dry_run' => false]);
        $sin->assertStatus(422);
        $this->assertStringContainsString('es obligatorio', $this->cuerpo($sin));

        foreach ([0, 9, -1, 'tres'] as $malo) {
            $this->avanzar($impl, ['etapa_actual' => $malo])->assertStatus(422);
        }

        $entre = $this->avanzar($impl, ['etapa_actual' => 9]);
        $this->assertStringContainsString('entre 1 y 8', $this->cuerpo($entre));
    }

    /**
     * Una nota de más de 500 caracteres es 422.
     *
     * @return void
     */
    public function test_una_nota_larga_es_422(): void
    {
        $impl = $this->en_la_etapa(1);

        $this->avanzar($impl, ['etapa_actual' => 1, 'nota' => str_repeat('x', 501)])->assertStatus(422);
    }

    /**
     * Una implementación inexistente es 404.
     *
     * @return void
     */
    public function test_una_implementacion_inexistente_es_404(): void
    {
        $this->postJson('/api/claude/implementations/99999999/advance', ['etapa_actual' => 1], $this->headers())->assertStatus(404);
    }

    /* ------------------------------------------------------------------------------------------
     | Dry-run
     |----------------------------------------------------------------------------------------- */

    /**
     * 1. 🔴 Sin `dry_run` explícito no se avanza nada: ni la etapa actual, ni los estados, ni las fechas.
     *
     * @return void
     */
    public function test_por_defecto_es_dry_run_y_no_avanza_nada(): void
    {
        $impl   = $this->en_la_etapa(2);
        $antes  = $this->estados($impl);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 2]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('de.numero', 2);
        $respuesta->assertJsonPath('de.nombre', 'Instalación del sistema');
        $respuesta->assertJsonPath('a.numero', 3);
        $respuesta->assertJsonPath('a.nombre', 'Recolección de archivos');
        $respuesta->assertJsonPath('marca_la_etapa_como', 'completed');
        $respuesta->assertJsonPath('cierra_la_implementacion', false);
        $respuesta->assertJsonPath('guarda_nota', false);

        $this->assertSame(2, (int) $impl->refresh()->current_stage);
        $this->assertSame($antes, $this->estados($impl));
    }

    /**
     * El dry-run de la etapa 7 —la última— dice que cierra la implementación y avisa qué pasa con el WhatsApp.
     * (Hasta el 15/10/2026 este test pedía la etapa 8: la 8 ya no es la última.)
     *
     * @return void
     */
    public function test_el_dry_run_de_la_ultima_etapa_dice_que_cierra(): void
    {
        $impl = $this->en_la_etapa(7);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 7]);

        $respuesta->assertJsonPath('de.numero', 7);
        $respuesta->assertJsonPath('de.nombre', 'Vinculación ARCA/AFIP');
        $respuesta->assertJsonPath('cierra_la_implementacion', true);
        $respuesta->assertJsonPath('a', null);
        $this->assertStringContainsString('CIERRA la implementación', $this->cuerpo($respuesta));
        $this->assertStringContainsString('WhatsApp', $this->cuerpo($respuesta));
        $this->assertSame('in_progress', $impl->refresh()->status);
    }

    /**
     * El dry-run de la etapa 6 NO cierra y dice que la que sigue es la 7: el catálogo ya no apunta a una
     * «Capacitación» ni a una etapa 8.
     *
     * @return void
     */
    public function test_el_dry_run_de_la_etapa_6_apunta_a_la_7_y_no_cierra(): void
    {
        // Hermético: los nombres tienen que salir del respaldo (`ETAPAS`) y no de lo que traiga la base de testing.
        \App\Models\ImplementationStageConfig::query()->delete();

        $impl = $this->en_la_etapa(6);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 6]);

        $respuesta->assertJsonPath('de.numero', 6);
        $respuesta->assertJsonPath('de.nombre', 'Catálogo: categorías, imágenes y descripciones');
        $respuesta->assertJsonPath('a.numero', 7);
        $respuesta->assertJsonPath('a.nombre', 'Vinculación ARCA/AFIP');
        $respuesta->assertJsonPath('cierra_la_implementacion', false);
    }

    /**
     * COMPATIBILIDAD: el dry-run de una implementación vieja que ya está en la etapa 8 también dice que cierra
     * (cerrar es `etapa >= última`, no `=== 7`), y la nombra con el nombre que tenía.
     *
     * @return void
     */
    public function test_el_dry_run_de_una_implementacion_vieja_en_la_etapa_8_dice_que_cierra(): void
    {
        $impl = $this->anterior_en_la_etapa(8);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 8]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('de.numero', 8);
        $respuesta->assertJsonPath('de.nombre', 'Videollamada de capacitación');
        $respuesta->assertJsonPath('cierra_la_implementacion', true);
        $respuesta->assertJsonPath('a', null);
        $this->assertSame('in_progress', $impl->refresh()->status);
    }

    /**
     * COMPATIBILIDAD: el dry-run de la etapa 7 de una implementación vieja (con su fila 8 pendiente) avisa que esa
     * fila queda `skipped`; en una implementación de siete etapas no hay nada que avisar de la 8.
     *
     * @return void
     */
    public function test_el_dry_run_de_la_etapa_7_avisa_de_la_etapa_8_pendiente_solo_en_una_vieja(): void
    {
        $vieja = $this->anterior_en_la_etapa(7);
        $nueva = $this->llevar_a_la_etapa($this->crear_implementacion($this->crear_cliente('Rosa Fernández')), 7);

        $this->assertStringContainsString('etapa 8 pendiente', $this->cuerpo($this->avanzar($vieja, ['etapa_actual' => 7])));
        $this->assertStringNotContainsString('etapa 8 pendiente', $this->cuerpo($this->avanzar($nueva, ['etapa_actual' => 7])));
        $this->assertSame('pending', ImplementationStage::where('implementation_id', $vieja->id)->where('stage_number', 8)->value('status'), 'El dry-run no toca nada.');
    }

    /* ------------------------------------------------------------------------------------------
     | Los 409
     |----------------------------------------------------------------------------------------- */

    /**
     * 2. 🔴 Una `etapa_actual` vieja es 409 con la real, en el dry-run y en el real, y no avanza nada: es
     * lo que impide avanzar dos veces por un reintento.
     *
     * @return void
     */
    public function test_una_etapa_vieja_es_409_con_la_real(): void
    {
        $impl = $this->en_la_etapa(3);

        foreach ([['dry_run' => true], ['dry_run' => false]] as $extra) {
            $respuesta = $this->avanzar($impl, array_merge(['etapa_actual' => 2], $extra));

            $respuesta->assertStatus(409);
            $respuesta->assertJsonPath('current_stage', 3);
            $respuesta->assertJsonPath('etapa_pedida', 2);
        }

        $this->assertSame(3, (int) $impl->refresh()->current_stage);
    }

    /**
     * 2. Un reintento después de avanzar es 409: la implementación ya no está en esa etapa. Es el caso
     * real que el parámetro previene.
     *
     * @return void
     */
    public function test_repetir_la_misma_llamada_no_avanza_dos_etapas(): void
    {
        $impl = $this->en_la_etapa(1);

        $this->avanzar($impl, ['etapa_actual' => 1, 'dry_run' => false])->assertStatus(200);
        $this->avanzar($impl, ['etapa_actual' => 1, 'dry_run' => false])->assertStatus(409);

        $this->assertSame(2, (int) $impl->refresh()->current_stage);
    }

    /**
     * 2. Una implementación completada no avanza: 409. (Antes el test la ponía en la etapa 8, que era la última;
     * ahora la última es la 7.)
     *
     * @return void
     */
    public function test_una_implementacion_completada_no_avanza(): void
    {
        $impl         = $this->en_la_etapa(7);
        $impl->status = 'completed';
        $impl->save();

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 7, 'dry_run' => false]);

        $respuesta->assertStatus(409);
        $respuesta->assertJsonPath('status', 'completed');
    }

    /* ------------------------------------------------------------------------------------------
     | El avance real
     |----------------------------------------------------------------------------------------- */

    /**
     * Avanzar de la 1 a la 2: la 1 queda completada con su fecha, la 2 en curso con su cronómetro, y la
     * implementación en la etapa 2.
     *
     * @return void
     */
    public function test_el_avance_deja_el_estado_esperado(): void
    {
        $impl = $this->en_la_etapa(1);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 1, 'dry_run' => false]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', false);
        $respuesta->assertJsonPath('implementation.current_stage', 2);
        $respuesta->assertJsonPath('implementation.status', 'in_progress');
        $respuesta->assertJsonPath('etapa_cerrada.numero', 1);
        $respuesta->assertJsonPath('etapa_cerrada.estado', 'completed');
        $respuesta->assertJsonPath('etapa_actual.numero', 2);
        $respuesta->assertJsonPath('etapa_actual.estado', 'in_progress');
        $respuesta->assertJsonPath('cierra_la_implementacion', false);

        $etapas = ImplementationStage::where('implementation_id', $impl->id)->orderBy('stage_number')->get();
        $this->assertSame('completed', $etapas[0]->status);
        $this->assertNotNull($etapas[0]->completed_at);
        $this->assertSame('in_progress', $etapas[1]->status);
        $this->assertNotNull($etapas[1]->started_at);
        $this->assertSame('pending', $etapas[2]->status);
        $this->assertSame(2, (int) $impl->refresh()->current_stage);
    }

    /**
     * Se puede recorrer de la 1 a la 7 de a una, y cada paso lee la real: de la 1 a la 6 avanza (la que sigue queda
     * en curso) y la 7 cierra. (Hasta el 15/10/2026 el recorrido llegaba a la 8; ahora la 7 es la última.)
     *
     * @return void
     */
    public function test_se_puede_avanzar_etapa_por_etapa(): void
    {
        $impl = $this->en_la_etapa(1);

        for ($etapa = 1; $etapa <= 6; $etapa++) {
            $this->avanzar($impl, ['etapa_actual' => $etapa, 'dry_run' => false])
                ->assertStatus(200)
                ->assertJsonPath('implementation.current_stage', $etapa + 1)
                ->assertJsonPath('cierra_la_implementacion', false);
        }

        $this->assertSame(['completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'in_progress'], $this->estados($impl));

        $this->avanzar($impl, ['etapa_actual' => 7, 'dry_run' => false])
            ->assertStatus(200)
            ->assertJsonPath('implementation.current_stage', 7)
            ->assertJsonPath('implementation.status', 'completed')
            ->assertJsonPath('cierra_la_implementacion', true);

        $this->assertSame(['completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'completed'], $this->estados($impl));
    }

    /**
     * 5. `saltar` deja la etapa en `skipped` (no aplica) y avanza igual. Antes el test saltaba la 7 (ARCA) y
     * comprobaba que abría la 8; la 8 ya no existe, así que saltar la 7 CIERRA (test siguiente) y el caso de
     * "salta y avanza" se prueba con la 4.
     *
     * @return void
     */
    public function test_saltar_deja_la_etapa_en_skipped(): void
    {
        $impl = $this->en_la_etapa(4);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 4, 'saltar' => true, 'dry_run' => false]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('etapa_cerrada.estado', 'skipped');
        $respuesta->assertJsonPath('implementation.current_stage', 5);
        $this->assertStringContainsString('skipped', $this->cuerpo($respuesta));

        $this->assertSame('skipped', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 4)->value('status'));
        $this->assertSame('in_progress', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 5)->value('status'));
    }

    /**
     * 5. Saltar la última etapa —ARCA, el caso típico: el cliente factura con otro sistema— deja la 7 `skipped` Y cierra
     * la implementación: `completed`, `current_stage` en 7 y sin etapa siguiente.
     *
     * @return void
     */
    public function test_saltar_la_ultima_etapa_la_deja_skipped_y_cierra(): void
    {
        $impl = $this->en_la_etapa(7);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 7, 'saltar' => true, 'dry_run' => false]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('etapa_cerrada.numero', 7);
        $respuesta->assertJsonPath('etapa_cerrada.estado', 'skipped');
        $respuesta->assertJsonPath('etapa_actual', null);
        $respuesta->assertJsonPath('cierra_la_implementacion', true);
        $respuesta->assertJsonPath('implementation.status', 'completed');
        $respuesta->assertJsonPath('implementation.current_stage', 7);

        $this->assertSame('skipped', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 7)->value('status'));
    }

    /**
     * 5. La nota se guarda en `data.notas` de la etapa que se cierra, sin pisar lo que ya tenía, y se
     * puede agregar otra al avanzar de nuevo desde esa etapa.
     *
     * @return void
     */
    public function test_la_nota_se_guarda_en_la_etapa_que_se_cierra(): void
    {
        $impl = $this->en_la_etapa(3);
        $this->escribir_data_de_la_etapa($impl, 3, ['pending_files' => ['uno.xlsx'], 'notas' => [['texto' => 'vieja', 'at' => 'x', 'origen' => 'claude']]]);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 3, 'nota' => 'Llegaron los tres Excel', 'dry_run' => false]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('nota_guardada', true);

        $data = $this->data_de_la_etapa($impl, 3);
        $this->assertSame(['uno.xlsx'], $data['pending_files'], 'La nota pisó el data que ya tenía la etapa.');
        $this->assertCount(2, $data['notas']);
        $this->assertSame('Llegaron los tres Excel', $data['notas'][1]['texto']);
        $this->assertSame('claude', $data['notas'][1]['origen']);
        $this->assertNotEmpty($data['notas'][1]['at']);
    }

    /* ------------------------------------------------------------------------------------------
     | La etapa 7 —la última— cierra
     |----------------------------------------------------------------------------------------- */

    /**
     * 4. 🔴 Desde la etapa 7 cierra la implementación: `status=completed`, `completed_at`, la etapa 7
     * completada y `current_stage` QUEDA EN 7. Una implementación de siete etapas no tiene fila 8.
     *
     * @return void
     */
    public function test_desde_la_etapa_7_cierra_la_implementacion_y_deja_la_etapa_en_7(): void
    {
        $impl = $this->en_la_etapa(7);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 7, 'dry_run' => false]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cierra_la_implementacion', true);
        $respuesta->assertJsonPath('implementation.status', 'completed');
        $respuesta->assertJsonPath('implementation.current_stage', 7);
        $respuesta->assertJsonPath('etapa_cerrada.numero', 7);
        $respuesta->assertJsonPath('etapa_cerrada.estado', 'completed');
        $respuesta->assertJsonPath('etapa_actual', null);
        $this->assertNotNull($respuesta->json('implementation.completed_at'));

        $impl->refresh();
        $this->assertSame('completed', $impl->status);
        $this->assertSame(7, (int) $impl->current_stage, 'Cerrar no mueve current_stage: queda en la última etapa.');
        $this->assertNotNull($impl->completed_at);
        $this->assertSame('completed', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 7)->value('status'));
        $this->assertSame(0, ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', '>', 7)->count(), 'Cerrar no inventa una etapa 8.');
    }

    /**
     * 4. Cerrada, el estado por `GET` lo dice y otro avance es 409.
     *
     * @return void
     */
    public function test_la_implementacion_cerrada_se_lee_como_completada(): void
    {
        $impl = $this->en_la_etapa(7);
        $this->avanzar($impl, ['etapa_actual' => 7, 'dry_run' => false])->assertStatus(200);

        $this->getJson('/api/claude/implementations/' . $impl->id, $this->headers())
            ->assertJsonPath('implementation.status', 'completed')
            ->assertJsonPath('implementation.current_stage', 7);

        $this->avanzar($impl, ['etapa_actual' => 7, 'dry_run' => false])->assertStatus(409);
    }

    /* ------------------------------------------------------------------------------------------
     | Compatibilidad con las implementaciones anteriores al 15/10/2026 (ocho filas de etapas)
     |----------------------------------------------------------------------------------------- */

    /**
     * COMPATIBILIDAD 1. Una implementación vieja que ya está EN la etapa 8 se sigue cerrando: `status=completed`,
     * la etapa 8 completada y `current_stage` queda en 8 (cerrar es `etapa >= última`, no `=== 7`). Es el test
     * que antes de la misión era el caso normal.
     *
     * @return void
     */
    public function test_una_implementacion_vieja_que_esta_en_la_etapa_8_se_sigue_cerrando(): void
    {
        $impl = $this->anterior_en_la_etapa(8);

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 8, 'dry_run' => false]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cierra_la_implementacion', true);
        $respuesta->assertJsonPath('implementation.status', 'completed');
        $respuesta->assertJsonPath('implementation.current_stage', 8);
        $respuesta->assertJsonPath('etapa_cerrada.numero', 8);
        $respuesta->assertJsonPath('etapa_cerrada.nombre', 'Videollamada de capacitación');
        $respuesta->assertJsonPath('etapa_actual', null);

        $impl->refresh();
        $this->assertSame('completed', $impl->status);
        $this->assertSame(8, (int) $impl->current_stage);
        $this->assertNotNull($impl->completed_at);
        $this->assertSame('completed', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 8)->value('status'));

        $this->avanzar($impl, ['etapa_actual' => 8, 'dry_run' => false])->assertStatus(409);
    }

    /**
     * COMPATIBILIDAD 2. Cerrar desde la 7 una implementación vieja deja la fila 8 que había quedado `pending` en
     * `skipped` (con su `completed_at`): dentro de una implementación completada no queda una etapa «por hacer».
     * La 7 queda `completed` y `current_stage` en 7.
     *
     * @return void
     */
    public function test_cerrar_desde_la_7_salta_la_etapa_8_pendiente_de_una_implementacion_vieja(): void
    {
        $impl = $this->anterior_en_la_etapa(7);
        $this->assertSame('pending', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 8)->value('status'));

        $respuesta = $this->avanzar($impl, ['etapa_actual' => 7, 'dry_run' => false]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('cierra_la_implementacion', true);
        $respuesta->assertJsonPath('implementation.status', 'completed');
        $respuesta->assertJsonPath('implementation.current_stage', 7);
        $respuesta->assertJsonPath('etapa_actual', null);

        $filas = ImplementationStage::where('implementation_id', $impl->id)->orderBy('stage_number')->get();
        $this->assertCount(8, $filas, 'No se borra ninguna fila.');
        $this->assertSame('completed', $filas[6]->status);
        $this->assertSame('skipped', $filas[7]->status);
        $this->assertNotNull($filas[7]->completed_at);
        $this->assertSame(['completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'skipped'], $this->estados($impl));
    }

    /**
     * COMPATIBILIDAD 3. Una implementación vieja que va avanzando de a una (con sus ocho filas) llega a la 7 y cierra
     * ahí, sin pasar nunca a la 8: avanzar de la 6 a la 7 deja la 7 en curso y la 8 pendiente.
     *
     * @return void
     */
    public function test_una_implementacion_vieja_avanza_hasta_la_7_y_cierra_sin_pasar_por_la_8(): void
    {
        $impl = $this->anterior_en_la_etapa(6);

        $this->avanzar($impl, ['etapa_actual' => 6, 'dry_run' => false])
            ->assertStatus(200)
            ->assertJsonPath('implementation.current_stage', 7)
            ->assertJsonPath('etapa_actual.numero', 7);

        $this->assertSame('pending', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 8)->value('status'));

        $this->avanzar($impl, ['etapa_actual' => 7, 'dry_run' => false])
            ->assertStatus(200)
            ->assertJsonPath('cierra_la_implementacion', true);

        $this->assertSame(7, (int) $impl->refresh()->current_stage);
        $this->assertSame('skipped', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 8)->value('status'));
    }

    /**
     * COMPATIBILIDAD 4. El método del modelo que salta las etapas heredadas SOLO toca las `pending`: una fila 8
     * que ya está en curso o completada (una implementación vieja que llegó a la 8) no se pisa, y correrlo dos
     * veces no cambia nada.
     *
     * @return void
     */
    public function test_saltar_las_etapas_heredadas_solo_toca_las_pendientes(): void
    {
        $en_curso = $this->anterior_en_la_etapa(8);
        $this->assertSame(0, $en_curso->saltar_las_etapas_heredadas());
        $this->assertSame('in_progress', ImplementationStage::where('implementation_id', $en_curso->id)->where('stage_number', 8)->value('status'));

        $pendiente = $this->anterior_en_la_etapa(5);
        $this->assertSame(1, $pendiente->saltar_las_etapas_heredadas());
        $this->assertSame(0, $pendiente->saltar_las_etapas_heredadas(), 'Es idempotente.');
        $this->assertSame('skipped', ImplementationStage::where('implementation_id', $pendiente->id)->where('stage_number', 8)->value('status'));
        $this->assertSame('in_progress', ImplementationStage::where('implementation_id', $pendiente->id)->where('stage_number', 5)->value('status'), 'No toca las etapas del flujo.');
    }

    /* ------------------------------------------------------------------------------------------
     | No dispara handle_stage_advance
     |----------------------------------------------------------------------------------------- */

    /**
     * 3. 🔴 Entrar a la etapa 2 por acá NO crea una `ClientInstallation`: la instalación la crea `install`.
     * En el panel, avanzar a la 2 la crea en `pendiente` (con la versión que quedó fijada al promover).
     *
     * @return void
     */
    public function test_entrar_a_la_etapa_2_no_crea_la_instalacion(): void
    {
        $impl = $this->en_la_etapa(1);

        $this->avanzar($impl, ['etapa_actual' => 1, 'dry_run' => false])->assertStatus(200);

        $this->assertSame(0, ClientInstallation::where('client_id', $impl->client_id)->count(), 'Avanzar creó una instalación: no tiene que hacerlo.');
    }

    /**
     * 3. 🔴 Aunque la implementación esté en modo `auto`, avanzar no manda ningún mensaje ni encola
     * ningún job: en `auto` el panel, al avanzar, notifica al admin, dispara el user setup y manda las
     * aperturas de etapa.
     *
     * @return void
     */
    public function test_en_modo_auto_avanzar_no_manda_mensajes_ni_encola_jobs(): void
    {
        Queue::fake();
        $impl                  = $this->en_la_etapa(1);
        $impl->automation_mode = 'auto';
        $impl->save();

        foreach ([1, 2, 3, 4] as $etapa) {
            $this->avanzar($impl, ['etapa_actual' => $etapa, 'dry_run' => false])->assertStatus(200);
        }

        $this->assertSame(0, ImplementationMessage::where('implementation_id', $impl->id)->count());
        $this->assertSame(0, ClientInstallation::where('client_id', $impl->client_id)->count());
        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------------------------------
     | Avisos
     |----------------------------------------------------------------------------------------- */

    /**
     * Cerrar la etapa 1 sin formulario avisa; con formulario enviado, no.
     *
     * @return void
     */
    public function test_cerrar_la_etapa_1_sin_formulario_avisa(): void
    {
        $impl = $this->en_la_etapa(1);

        $this->assertStringContainsString('no envió el formulario', $this->cuerpo($this->avanzar($impl, ['etapa_actual' => 1])));

        $impl->form_submitted_at = now();
        $impl->save();

        $this->assertStringNotContainsString('no envió el formulario', $this->cuerpo($this->avanzar($impl, ['etapa_actual' => 1])));
    }

    /**
     * Cerrar la etapa 2 sin sistema instalado ni user setup avisa las dos cosas; con las dos hechas, no
     * avisa nada de eso. Avisa y NO frena: el avance sale igual.
     *
     * @return void
     */
    public function test_cerrar_la_etapa_2_avisa_lo_que_falta_pero_no_frena(): void
    {
        $impl = $this->en_la_etapa(2);

        $cuerpo = $this->cuerpo($this->avanzar($impl, ['etapa_actual' => 2]));
        $this->assertStringContainsString('todavía no figura instalado', $cuerpo);
        $this->assertStringContainsString('user setup todavía no se aplicó', $cuerpo);

        $this->crear_instalacion($this->crear_cliente_de($impl), ['status' => 'completada']);
        $impl->user_setup_executed_at = now();
        $impl->save();

        $cuerpo = $this->cuerpo($this->avanzar($impl, ['etapa_actual' => 2]));
        $this->assertStringNotContainsString('todavía no figura instalado', $cuerpo);
        $this->assertStringNotContainsString('user setup todavía no se aplicó', $cuerpo);

        $impl->user_setup_executed_at = null;
        $impl->save();
        $this->avanzar($impl, ['etapa_actual' => 2, 'dry_run' => false])->assertStatus(200);
    }

    /**
     * El cliente de una implementación (para sumarle instalaciones).
     *
     * @param \App\Models\Implementation $impl La implementación.
     *
     * @return \App\Models\Client
     */
    private function crear_cliente_de($impl)
    {
        return \App\Models\Client::find($impl->client_id);
    }
}

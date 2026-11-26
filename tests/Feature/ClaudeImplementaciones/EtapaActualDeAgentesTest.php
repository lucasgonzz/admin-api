<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\AgentImplementation;
use App\Models\AgentImplementationStage;
use Illuminate\Support\Facades\Artisan;

/**
 * La etapa actual de una implementación de Agentes de IA mide el AVANCE REAL (misión agenda-openclaw-empuje,
 * 25/11/2026).
 *
 * 🔴 El bug medido en producción: Pablo (etapas 1 a 6 en curso) y Fernando (2 salteada, 3 completa, 4 en curso)
 * figuraban en la etapa 1 porque la regla era «la primera que no está cerrada» y nadie cierra la Preparación
 * (sus hitos v2 quedan abiertos en las altas viejas, `flujo_version = 1`). La regla nueva: la etapa más alta
 * iniciada (en curso o completa), sin contar la 7 (Pago y verificación), que se puede adelantar.
 *
 * Los casos que ya fijaba `ImplementacionDeAgentesPorClaudeTest` siguen valiendo y no se tocan.
 */
class EtapaActualDeAgentesTest extends BaseDeImplementaciones
{
    /** Ruta base. */
    const RUTA = '/api/claude/agent-implementations';

    /**
     * Da de alta una implementación Full de flujo histórico (`flujo_version = 1`, como las de producción
     * que tienen el bug) y devuelve su id.
     *
     * @param string $nombre Nombre del cliente.
     *
     * @return int
     */
    private function alta_historica(string $nombre): int
    {
        $client    = $this->crear_cliente($nombre);
        $respuesta = $this->postJson(self::RUTA, ['client_id' => $client->id, 'camino' => 'nuevo'], $this->headers());
        $respuesta->assertStatus(201);

        $id = (int) $respuesta->json('model.id');
        AgentImplementation::where('id', $id)->update(['flujo_version' => 1]);

        return $id;
    }

    /**
     * Registra una etapa por la ruta real y devuelve la respuesta.
     *
     * @param int    $id     Implementación.
     * @param int    $numero Etapa.
     * @param string $status Estado.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function etapa(int $id, int $numero, string $status)
    {
        $respuesta = $this->postJson(self::RUTA . '/' . $id . '/stages/' . $numero, ['status' => $status], $this->headers());
        $respuesta->assertStatus(200);

        return $respuesta;
    }

    /**
     * Pablo: etapas 1 a 6 en curso, 7 y 8 pendientes. Va por la 6, no por la 1.
     *
     * @return void
     */
    public function test_caso_pablo_va_por_la_seis(): void
    {
        $id = $this->alta_historica('Pablo');

        for ($n = 2; $n <= 5; $n++) {
            $this->etapa($id, $n, 'in_progress');
        }
        $respuesta = $this->etapa($id, 6, 'in_progress');

        $respuesta->assertJsonPath('model.current_stage', 6);
        $this->assertSame(6, (int) AgentImplementation::find($id)->current_stage);

        /* Y la lectura del estado dice lo mismo. */
        $this->getJson(self::RUTA . '/' . $id, $this->headers())->assertJsonPath('model.current_stage', 6);
    }

    /**
     * Fernando: 1 en curso, 2 salteada, 3 completa, 4 en curso. Va por la 4.
     *
     * @return void
     */
    public function test_caso_fernando_va_por_la_cuatro(): void
    {
        $id = $this->alta_historica('Fernando');

        $this->etapa($id, 2, 'skipped');
        $this->etapa($id, 3, 'completed');
        $respuesta = $this->etapa($id, 4, 'in_progress');

        $respuesta->assertJsonPath('model.current_stage', 4);
        $this->assertSame(4, (int) AgentImplementation::find($id)->current_stage);
    }

    /**
     * El pago adelantado (etapa 7) no mueve la actual: con la 3 en curso y la 7 en curso sigue siendo la 3.
     * Control: con la 3 completa y la 7 completa, la actual es la 4 (la primera abierta por encima de la 3).
     *
     * @return void
     */
    public function test_pago_adelantado_no_mueve_la_actual(): void
    {
        $id = $this->alta_historica('Pago adelantado');

        $this->etapa($id, 3, 'in_progress');
        $this->etapa($id, 7, 'in_progress')->assertJsonPath('model.current_stage', 3);
        $this->assertSame(3, (int) AgentImplementation::find($id)->current_stage);

        $this->etapa($id, 3, 'completed');
        $this->etapa($id, 7, 'completed')->assertJsonPath('model.current_stage', 4);
        $this->assertSame(4, (int) AgentImplementation::find($id)->current_stage);
    }

    /**
     * Todas cerradas: la actual es la 8. Control: con la 8 pendiente y todo lo demás cerrado, también la 8
     * (es la primera abierta por encima de la más alta completa, la 6, salteando la 7 completa).
     *
     * @return void
     */
    public function test_todas_cerradas_es_ocho(): void
    {
        $id = $this->alta_historica('Todas cerradas');

        for ($n = 1; $n <= 7; $n++) {
            $this->etapa($id, $n, $n === 2 ? 'skipped' : 'completed');
        }
        $this->assertSame(8, (int) AgentImplementation::find($id)->current_stage, 'Con la 8 abierta es la 8.');

        $this->etapa($id, 8, 'completed')->assertJsonPath('model.current_stage', 8);
        $this->assertSame(8, (int) AgentImplementation::find($id)->current_stage);
    }

    /**
     * Arma una implementación como la de producción, con la actual guardada mal (1), las etapas del caso y
     * el estado pedido.
     *
     * @param string               $nombre  Nombre del cliente.
     * @param array<int, string>   $etapas  Estado de cada etapa por número (las que no estén, pending).
     * @param string               $estado  Estado de la implementación.
     *
     * @return int Id.
     */
    private function sembrar_con_actual_en_uno(string $nombre, array $etapas, string $estado): int
    {
        $id = $this->alta_historica($nombre);

        foreach ($etapas as $numero => $status) {
            AgentImplementationStage::where('agent_implementation_id', $id)
                ->where('stage_number', $numero)
                ->update(['status' => $status]);
        }
        AgentImplementation::where('id', $id)->update(['status' => $estado, 'current_stage' => 1]);

        return $id;
    }

    /**
     * El comando recalcula sólo las abiertas: `--ver` imprime «1 -> 6» y «1 -> 4» sin tocar la base; sin
     * `--ver` guarda 6 y 4; la completada queda en 1.
     *
     * @return void
     */
    public function test_comando_recalcula_solo_las_abiertas_y_ver_no_guarda(): void
    {
        $pablo = $this->sembrar_con_actual_en_uno('Pablo cmd', [
            1 => 'in_progress', 2 => 'in_progress', 3 => 'in_progress', 4 => 'in_progress', 5 => 'in_progress', 6 => 'in_progress',
        ], 'in_progress');
        $fernando = $this->sembrar_con_actual_en_uno('Fernando cmd', [
            1 => 'in_progress', 2 => 'skipped', 3 => 'completed', 4 => 'in_progress',
        ], 'in_progress');
        $cerrada = $this->sembrar_con_actual_en_uno('Cerrada cmd', [
            1 => 'completed', 2 => 'completed', 3 => 'completed', 4 => 'completed',
        ], 'completed');

        /* --ver: imprime el antes y el después y no escribe. */
        $salida = Artisan::call('agentes:recalcular-etapa-actual', ['--ver' => true]);
        $texto  = Artisan::output();

        $this->assertSame(0, $salida);
        $this->assertStringContainsString('id ' . $pablo . ' (', $texto);
        $this->assertStringContainsString('1 -> 6', $texto);
        $this->assertStringContainsString('id ' . $fernando . ' (', $texto);
        $this->assertStringContainsString('1 -> 4', $texto);
        $this->assertStringNotContainsString('id ' . $cerrada . ' (', $texto, 'La completada no se recorre.');
        $this->assertSame(1, (int) AgentImplementation::find($pablo)->current_stage, '--ver no guarda.');
        $this->assertSame(1, (int) AgentImplementation::find($fernando)->current_stage, '--ver no guarda.');

        /* Sin --ver: guarda. */
        $this->assertSame(0, Artisan::call('agentes:recalcular-etapa-actual'));
        $this->assertSame(6, (int) AgentImplementation::find($pablo)->current_stage);
        $this->assertSame(4, (int) AgentImplementation::find($fernando)->current_stage);
        $this->assertSame(1, (int) AgentImplementation::find($cerrada)->current_stage, 'La completada no se toca.');
    }
}

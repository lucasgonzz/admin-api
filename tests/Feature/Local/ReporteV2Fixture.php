<?php

namespace Tests\Feature\Local;

use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\LocalOficinaSlot;
use App\Services\Local\EscenaService;
use App\Services\Local\OficinaIngestService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Herramientas compartidas por los tests del contrato A v2 y de la escena con misiones y OpenClaw
 * (misión el-local-linux, 25/11/2026): armar un reporte v2 como el de `reporter_linux.py`, mandarlo por el
 * endpoint real, pedir la escena y limpiar lo que es de esta misión.
 *
 * Los textos de las constantes de clave se escriben literales (no se leen de `PcSnapshotService`) para que los
 * tests fallen por la clave o la tabla que falta y no por una clase inexistente al cargar el archivo.
 */
trait ReporteV2Fixture
{
    /** Clave de Claude para el endpoint real. */
    private static $clave_v2 = 'clave-de-claude-para-el-contrato-v2';

    /** Claves de `admin_settings` donde queda el snapshot v2. */
    private static $claves_snapshot = ['local_pc_misiones', 'local_pc_workboard', 'local_pc_openclaw', 'local_pc_cola'];

    /**
     * Limpia lo que es de esta misión y configura la clave. Se llama desde `setUp()`.
     *
     * @param bool $con_tabla_de_fases Si también vacía `local_mission_phases` (sólo cuando el test la usa).
     *
     * @return void
     */
    private function preparar_v2($con_tabla_de_fases = false)
    {
        config(['services.claude_task_ingest.key' => self::$clave_v2]);

        LocalOficinaSlot::query()->delete();
        AdminSetting::where('key', OficinaIngestService::PULSO_KEY)->delete();
        AdminSetting::whereIn('key', self::$claves_snapshot)->delete();

        if ($con_tabla_de_fases) {
            DB::table('local_mission_phases')->delete();
        }
    }

    /**
     * Suelta el reloj congelado. Se llama desde `tearDown()`.
     *
     * @return void
     */
    private function soltar_reloj()
    {
        Carbon::setTestNow();
    }

    /**
     * @return array<string, string>
     */
    private function headers_v2()
    {
        return ['X-Claude-Task-Key' => self::$clave_v2, 'Accept' => 'application/json'];
    }

    /**
     * Una misión del reporte v2 con los campos requeridos; `$cambios` pisa lo que haga falta.
     *
     * @param string               $id      Id de la misión (`cc:<slug>`).
     * @param array<string, mixed> $cambios Cambios sobre la misión.
     *
     * @return array<string, mixed>
     */
    private function mision_v2($id, array $cambios = [])
    {
        return array_merge([
            'id'                  => $id,
            'titulo'              => 'Misión ' . $id,
            'origen'              => 'claude-code',
            'estado'              => 'trabajando',
            'etapa'               => 'construccion',
            'fase'                => 'construccion',
            'fase_inferida'       => false,
            'slug'                => substr($id, 3),
            'tarjeta_id'          => null,
            'ticket'              => null,
            'repos'               => ['admin-api'],
            'worktrees'           => [[
                'repo' => 'admin-api', 'carpeta' => 'admin-api-' . substr($id, 3), 'rama' => substr($id, 3),
                'sucios' => 0, 'ultima_actividad_at' => '2026-11-25T20:39:10-03:00',
            ]],
            'agentes'             => [['rol' => 'coordinador', 'runtime' => 'claude', 'estado' => 'activo', 'desde_at' => '2026-11-25T20:00:00-03:00']],
            'desde_at'            => '2026-11-25T19:00:00-03:00',
            'ultima_actividad_at' => '2026-11-25T20:39:10-03:00',
        ], $cambios);
    }

    /**
     * Una tarjeta del Workboard del reporte v2.
     *
     * @param string               $id
     * @param string               $estado
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    private function tarjeta_v2($id, $estado = 'running', array $cambios = [])
    {
        return array_merge([
            'id' => $id, 'titulo' => 'Tarjeta ' . $id, 'estado' => $estado, 'prioridad' => 'normal',
            'etiquetas' => ['actualizacion'], 'agente' => 'operador',
            'actualizada_at' => '2026-11-25T20:30:00-03:00', 'vence_at' => null, 'mision_id' => null,
        ], $cambios);
    }

    /**
     * Una tarea programada de OpenClaw del reporte v2.
     *
     * @param string               $id
     * @param array<string, mixed> $cambios
     *
     * @return array<string, mixed>
     */
    private function tarea_v2($id, array $cambios = [])
    {
        return array_merge([
            'id' => $id, 'nombre' => 'Tarea ' . $id, 'habilitada' => true, 'tipo' => 'modelo', 'agente' => 'main',
            'condicion' => false, 'frecuencia' => 'cada 5 min, de 8 a 21 h', 'expresion' => '*/5 8-21 * * *',
            'ultimo_resultado' => 'ok', 'ultima_corrida_at' => '2026-11-25T20:35:00-03:00',
            'ultima_condicion_at' => null, 'proxima_corrida_at' => '2026-11-25T20:40:00-03:00', 'errores_seguidos' => 0,
        ], $cambios);
    }

    /**
     * Un reporte v2 completo: 2 misiones, 3 tarjetas, 2 tareas, gateway ok, `slots` vacío.
     *
     * @param array<string, mixed> $cambios Se mezclan (nivel superior) sobre el reporte.
     *
     * @return array<string, mixed>
     */
    private function reporte_v2(array $cambios = [])
    {
        return array_merge([
            'contrato'         => 2,
            'reporter'         => ['version' => '2.0.0', 'host' => 'lucas-pc', 'plataforma' => 'linux', 'enviado_at' => '2026-11-25T20:40:00-03:00', 'slots_total' => 0],
            'sesiones'         => ['claude_busy' => 2, 'claude_idle' => 0, 'codex_activos' => 1],
            'slots'            => [],
            'misiones'         => [
                $this->mision_v2('cc:uno'),
                $this->mision_v2('cc:dos', ['origen' => 'openclaw', 'estado' => 'esperando_lucas', 'etapa' => 'plan', 'fase' => 'plan']),
            ],
            'misiones_omitidas' => 0,
            'openclaw'         => [
                'gateway'   => ['estado' => 'ok', 'version' => '2026.11.5', 'verificado_at' => '2026-11-25T20:40:00-03:00'],
                'workboard' => [
                    'ok' => true, 'total' => 3, 'conteo' => ['running' => 1, 'blocked' => 1, 'done' => 1],
                    'tarjetas' => [$this->tarjeta_v2('t-1', 'running'), $this->tarjeta_v2('t-2', 'blocked'), $this->tarjeta_v2('t-3', 'done')],
                ],
                'tareas'    => ['ok' => true, 'items' => [$this->tarea_v2('c-1'), $this->tarea_v2('c-2', ['tipo' => 'comando', 'agente' => null, 'condicion' => true])]],
            ],
        ], $cambios);
    }

    /**
     * Manda un reporte al endpoint real.
     *
     * @param array<string, mixed> $reporte
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function enviar_v2(array $reporte)
    {
        return $this->postJson('/api/claude/local/oficina', $reporte, $this->headers_v2());
    }

    /**
     * Pide la escena (sin cache) como un admin y devuelve el JSON.
     *
     * @return array<string, mixed>
     */
    private function escena_v2()
    {
        Cache::forget(EscenaService::CACHE_KEY);

        $admin = Admin::create([
            'name' => 'Lucas', 'email' => 'el-local-v2-' . uniqid() . '@test.local', 'password' => bcrypt('secret'),
        ]);

        return $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/local/escena')
            ->assertStatus(200)
            ->json();
    }

    /**
     * Valor decodificado de una clave de `admin_settings` (o null).
     *
     * @param string $clave
     *
     * @return array<string, mixed>|null
     */
    private function guardado_v2($clave)
    {
        $crudo = AdminSetting::get($clave);

        return $crudo === null ? null : json_decode($crudo, true);
    }
}

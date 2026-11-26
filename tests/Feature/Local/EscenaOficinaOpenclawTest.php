<?php

namespace Tests\Feature\Local;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `GET api/admin/local/escena` con misiones y OpenClaw (misión el-local-linux, 25/11/2026, contrato B extendido).
 *
 * Después de un reporte con `contrato: 2` la oficina pasa a `modo = misiones`: las mesas son misiones (ordenadas
 * por estado y actividad, con la ruta del ticket), `por_origen` y `slots_en_uso` reemplazan al pool, el
 * planificador es OpenClaw (sigue al gateway y al Workboard) y `oficina.openclaw` trae el Workboard y las tareas
 * programadas. Sin reporte v2 todo queda en el modo de hoy y con la misma forma (CA16, CA17, CA18, CA27).
 *
 * El reloj se congela en una hora fija: el POST estampa `reportado_at` con `AppTime::now()` y la escena decide
 * «sin señal» comparando contra el mismo reloj.
 */
class EscenaOficinaOpenclawTest extends TestCase
{
    use DatabaseTransactions;
    use ReporteV2Fixture;

    /** Mismo orden de claves de `oficina` que fija `EscenaDelLocalTest`. */
    const CLAVES_OFICINA = ['reporter', 'planificador', 'slots', 'modo', 'misiones', 'por_origen', 'slots_en_uso', 'openclaw', 'cola', 'umbrales_demora'];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_v2();
        $this->congelar();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->soltar_reloj();

        parent::tearDown();
    }

    /**
     * Congela el reloj en las 20:41 del 25/11/2026, hora Argentina (un minuto después del reporte de ejemplo).
     *
     * @param int $minutos_despues Minutos que se adelanta el reloj.
     *
     * @return void
     */
    private function congelar($minutos_despues = 0)
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 20:41:00', 'America/Argentina/Buenos_Aires')->addMinutes($minutos_despues));
    }

    /**
     * Misión del reporte con actividad y worktree propios.
     *
     * @param string $slug
     * @param string $origen
     * @param string $estado
     * @param string $actividad ISO de la última actividad.
     * @param string $carpeta   Carpeta del worktree.
     * @param array  $extra     Cambios extra.
     *
     * @return array<string, mixed>
     */
    private function mision($slug, $origen, $estado, $actividad, $carpeta, array $extra = [])
    {
        return $this->mision_v2('cc:' . $slug, array_merge([
            'origen' => $origen, 'estado' => $estado, 'ultima_actividad_at' => $actividad,
            'worktrees' => [['repo' => 'admin-api', 'carpeta' => $carpeta, 'rama' => $slug, 'sucios' => 0, 'ultima_actividad_at' => $actividad]],
        ], $extra));
    }

    /**
     * CA16: las misiones salen ordenadas (trabajando nueva, trabajando vieja, trabada, esperando a Lucas,
     * terminada), la del ticket 177 trae su ruta, y los conteos del resumen y de `por_origen` son los esperados.
     *
     * @return void
     */
    public function test_misiones_ordenadas_con_ruta_y_conteos(): void
    {
        $reporte             = $this->reporte_v2();
        $reporte['misiones'] = [
            $this->mision('terminada', 'dots', 'terminada', '2026-11-25T20:38:00-03:00', 'wt-d'),
            $this->mision('espera', 'codex', 'esperando_lucas', '2026-11-25T20:30:00-03:00', 'wt-a', ['ticket' => 177]),
            $this->mision('vieja', 'openclaw', 'trabajando', '2026-11-25T20:00:00-03:00', 'wt-a'),
            $this->mision('trabada', 'soporte', 'trabada', '2026-11-25T20:20:00-03:00', 'wt-c'),
            $this->mision('nueva', 'claude-code', 'trabajando', '2026-11-25T20:35:00-03:00', 'wt-b'),
        ];

        $this->enviar_v2($reporte)->assertStatus(200);
        $oficina = $this->escena_v2()['oficina'];

        $this->assertSame('misiones', $oficina['modo']);
        $this->assertSame(
            ['cc:nueva', 'cc:vieja', 'cc:trabada', 'cc:espera', 'cc:terminada'],
            array_column($oficina['misiones'], 'id')
        );

        $espera = $oficina['misiones'][3];
        $this->assertSame('/soporte/ticket/177', $espera['ruta']);
        $this->assertNull($oficina['misiones'][0]['ruta']);
        foreach ($oficina['misiones'] as $mision) {
            $this->assertArrayHasKey('sin_senal', $mision);
            $this->assertFalse($mision['sin_senal'], 'El snapshot es de hace segundos.');
        }

        $this->assertSame(
            ['openclaw' => 1, 'claude-code' => 1, 'soporte' => 1, 'codex' => 1, 'dots' => 0, 'desconocido' => 0],
            $oficina['por_origen']
        );
        $this->assertSame(3, $oficina['slots_en_uso'], 'Carpetas distintas de las no terminadas: wt-a, wt-b y wt-c.');
        $this->assertSame([], $oficina['slots']);

        $escena = $this->escena_v2();
        $this->assertSame(4, $escena['resumen']['misiones_en_curso']);
        $this->assertNull($escena['resumen']['slots_libres']);
        $this->assertSame(3, $escena['resumen']['slots_total']);
    }

    /**
     * CA16: con 45 misiones guardadas la escena devuelve 40.
     *
     * @return void
     */
    public function test_tope_de_40(): void
    {
        $misiones = [];
        for ($n = 1; $n <= 45; $n++) {
            $misiones[] = $this->mision('m' . $n, 'claude-code', 'trabajando', Carbon::parse('2026-11-25 20:00:00', 'America/Argentina/Buenos_Aires')->addMinutes($n)->toIso8601String(), 'wt-' . $n, [
                'agentes' => [],
            ]);
        }
        $reporte             = $this->reporte_v2();
        $reporte['misiones'] = $misiones;

        $this->enviar_v2($reporte)->assertStatus(200)->assertJsonPath('misiones', 45);

        $oficina = $this->escena_v2()['oficina'];
        $this->assertCount(40, $oficina['misiones']);
        $this->assertSame('cc:m45', $oficina['misiones'][0]['id'], 'Quedan las de actividad más nueva.');
    }

    /**
     * CA17: el planificador es OpenClaw y sigue al gateway y al Workboard; con un snapshot viejo queda apagado y
     * el gateway pasa a `desconocido`. Además el Workboard y las tareas salen como se guardaron.
     *
     * @return void
     */
    public function test_planificador_sigue_al_gateway(): void
    {
        // 1. Gateway ok + una tarjeta running -> trabajando.
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
        $oficina = $this->escena_v2()['oficina'];
        $this->assertSame('trabajando', $oficina['planificador']['estado']);
        $this->assertSame('ok', $oficina['openclaw']['gateway']['estado']);
        $this->assertSame('2026.11.5', $oficina['openclaw']['gateway']['version']);
        $this->assertSame(['running' => 1, 'blocked' => 1, 'done' => 1], $oficina['openclaw']['workboard']['conteo']);
        $this->assertSame(['t-1', 't-2', 't-3'], array_column($oficina['openclaw']['workboard']['tarjetas'], 'id'));
        $this->assertSame(['c-1', 'c-2'], array_column($oficina['openclaw']['tareas']['items'], 'id'));
        $this->assertSame('comando', $oficina['openclaw']['tareas']['items'][1]['tipo']);

        // 2. Gateway ok, sin tarjetas running ni misión de OpenClaw trabajando -> libre.
        $libre                                              = $this->reporte_v2();
        $libre['misiones']                                  = [$this->mision_v2('cc:solo-claude')];
        $libre['openclaw']['workboard']['tarjetas']         = [$this->tarjeta_v2('t-2', 'blocked'), $this->tarjeta_v2('t-3', 'done')];
        $libre['openclaw']['workboard']['conteo']           = ['blocked' => 1, 'done' => 1];
        $this->enviar_v2($libre)->assertStatus(200);
        $this->assertSame('libre', $this->escena_v2()['oficina']['planificador']['estado']);

        // 2b. Sin tarjeta running pero con una misión de OpenClaw trabajando -> trabajando.
        $con_mision                                         = $libre;
        $con_mision['misiones']                             = [$this->mision_v2('cc:de-openclaw', ['origen' => 'openclaw'])];
        $this->enviar_v2($con_mision)->assertStatus(200);
        $this->assertSame('trabajando', $this->escena_v2()['oficina']['planificador']['estado']);

        // 3. Gateway caído -> apagado.
        $caido                                      = $this->reporte_v2();
        $caido['openclaw']['gateway']['estado']     = 'caido';
        $this->enviar_v2($caido)->assertStatus(200);
        $this->assertSame('apagado', $this->escena_v2()['oficina']['planificador']['estado']);

        // 4. Snapshot de hace 6 minutos -> apagado y gateway desconocido.
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
        $this->congelar(6);
        $oficina = $this->escena_v2()['oficina'];
        $this->assertSame('apagado', $oficina['planificador']['estado']);
        $this->assertSame('desconocido', $oficina['openclaw']['gateway']['estado']);
    }

    /**
     * CA18: sin reportes o con el último v1 la escena queda en modo slots, con los valores vacíos y la forma
     * exacta de `oficina`.
     *
     * @return void
     */
    public function test_sin_v2_modo_slots_y_forma(): void
    {
        $umbrales = ['plan' => 120, 'construccion' => 240, 'revision' => 120, 'tests' => 120, 'merge' => 60, 'cierre' => 120];

        // Sin ningún reporte.
        $oficina = $this->escena_v2()['oficina'];
        $this->assertSame(self::CLAVES_OFICINA, array_keys($oficina));
        $this->assertSame('slots', $oficina['modo']);
        $this->assertSame([], $oficina['misiones']);
        $this->assertSame([], $oficina['por_origen']);
        $this->assertNull($oficina['slots_en_uso']);
        $this->assertNull($oficina['openclaw']);
        $this->assertNull($oficina['cola']);
        $this->assertEquals($umbrales, $oficina['umbrales_demora']);

        // Con el último reporte v1.
        $this->enviar_v2([
            'reporter' => ['version' => '1.0.0', 'host' => 'ASUS', 'enviado_at' => '2026-11-25T20:40:00-03:00', 'slots_total' => 0],
            'slots'    => [],
        ])->assertStatus(200);
        $oficina = $this->escena_v2()['oficina'];
        $this->assertSame(self::CLAVES_OFICINA, array_keys($oficina));
        $this->assertSame('slots', $oficina['modo']);
        $this->assertNull($oficina['openclaw']);
        $this->assertNull($oficina['cola']);
    }

    /**
     * CA27: la cola sale en `oficina.cola` con su forma, no suma en `misiones_en_curso` ni en `por_origen` ni es
     * una mesa; sin `cola` en el reporte es null; con el snapshot de hace 6 minutos trae `sin_senal`.
     *
     * @return void
     */
    public function test_cola_en_la_escena_fuera_de_las_mesas(): void
    {
        $cola = [
            'ok' => true, 'fallidas' => 2,
            'en_espera' => [
                ['slug' => 'espera-uno', 'titulo' => 'Espera uno', 'prioridad' => 'high', 'origen' => 'claude-code', 'encolada_at' => '2026-11-25T20:05:00-03:00', 'espera_min' => 35, 'motivos' => ['memoria'], 'intentos' => 1],
                ['slug' => 'espera-dos', 'titulo' => 'Espera dos', 'prioridad' => 'normal', 'origen' => 'openclaw', 'encolada_at' => '2026-11-25T20:10:00-03:00', 'espera_min' => 30, 'motivos' => ['cupo', 'pesados'], 'intentos' => 3],
            ],
            'ultimo_chequeo' => ['at' => '2026-11-25T20:39:00-03:00', 'ram_disponible_gib' => 12.5, 'load1' => 0.2, 'nproc' => 24, 'largo_cola' => 2, 'lanzadas' => []],
            'chequeos'       => [['at' => '2026-11-25T20:39:00-03:00', 'ram_disponible_gib' => 12.5, 'load1' => 0.2]],
        ];

        $this->enviar_v2($this->reporte_v2(['cola' => $cola]))->assertStatus(200);
        $escena = $this->escena_v2();
        $oficina = $escena['oficina'];

        $this->assertSame(['reportado_at', 'sin_senal', 'ok', 'en_espera', 'fallidas', 'ultimo_chequeo', 'chequeos'], array_keys($oficina['cola']));
        $this->assertFalse($oficina['cola']['sin_senal']);
        $this->assertTrue($oficina['cola']['ok']);
        $this->assertSame(2, $oficina['cola']['fallidas']);
        $this->assertSame(['espera-uno', 'espera-dos'], array_column($oficina['cola']['en_espera'], 'slug'));
        $this->assertSame(['cupo', 'pesados'], $oficina['cola']['en_espera'][1]['motivos']);
        $this->assertEquals(12.5, $oficina['cola']['ultimo_chequeo']['ram_disponible_gib']);
        $this->assertCount(1, $oficina['cola']['chequeos']);

        // Las misiones en espera no son mesas ni cuentan: sólo las 2 del reporte base (1 trabajando, 1 esperando).
        $this->assertCount(2, $oficina['misiones']);
        $this->assertSame(2, $escena['resumen']['misiones_en_curso']);
        $this->assertSame(2, array_sum($oficina['por_origen']));

        // Sin `cola` en el reporte.
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
        $this->assertNull($this->escena_v2()['oficina']['cola']);

        // Con el snapshot de hace 6 minutos.
        $this->enviar_v2($this->reporte_v2(['cola' => $cola]))->assertStatus(200);
        $this->congelar(6);
        $this->assertTrue($this->escena_v2()['oficina']['cola']['sin_senal']);
    }
}

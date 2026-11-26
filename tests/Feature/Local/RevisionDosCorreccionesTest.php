<?php

namespace Tests\Feature\Local;

use App\Models\AdminSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Correcciones de la revisión 1 de la misión el-local-linux (hallazgos R4, R5, R6 y R7 de admin-api).
 *
 * - R4: el historial de fases guardado nunca deja más de una fase abierta por misión, aunque el reporter
 *   renombre una fase inferida o pase de inferido a registro.
 * - R5: `demorada` es sólo para misiones `trabajando`; las terminadas no cuentan como en curso.
 * - R6: el recorte de misiones es visible en la escena y el snapshot no guarda `fase_historial`.
 * - R7: la cola también se recorta a 60 000 bytes.
 */
class RevisionDosCorreccionesTest extends TestCase
{
    use DatabaseTransactions;
    use ReporteV2Fixture;

    /** Zona de las horas guardadas. */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_v2(true);
        Carbon::setTestNow(Carbon::parse('2026-11-25 21:00:00', self::ZONA));
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
     * ISO de «ahora menos N minutos».
     *
     * @param int $minutos
     *
     * @return string
     */
    private function hace($minutos)
    {
        return Carbon::now()->subMinutes($minutos)->toIso8601String();
    }

    /**
     * Una entrada de `fase_historial`.
     *
     * @param string      $fase
     * @param string      $etapa
     * @param string      $desde
     * @param string|null $hasta
     * @param bool        $inferida
     *
     * @return array<string, mixed>
     */
    private function entrada($fase, $etapa, $desde, $hasta = null, $inferida = false)
    {
        return [
            'fase' => $fase, 'etapa' => $etapa, 'desde' => $desde, 'hasta' => $hasta,
            'segundos' => $hasta === null ? null : Carbon::parse($hasta)->timestamp - Carbon::parse($desde)->timestamp,
            'inferida' => $inferida,
        ];
    }

    /**
     * Manda un reporte con una sola misión y ese historial.
     *
     * @param string            $id
     * @param array<int, array> $historial
     *
     * @return void
     */
    private function reportar($id, array $historial)
    {
        $ultima = end($historial);
        $this->enviar_v2($this->reporte_v2(['misiones' => [$this->mision_v2($id, [
            'fase' => $ultima['fase'], 'etapa' => $ultima['etapa'], 'fase_desde' => $ultima['desde'], 'fase_historial' => $historial,
        ])]]))->assertStatus(200);
    }

    /**
     * Filas de la tabla de una misión, de la más vieja a la más nueva.
     *
     * @param string $mission_key
     *
     * @return \Illuminate\Support\Collection
     */
    private function filas($mission_key)
    {
        return DB::table('local_mission_phases')->where('mission_key', $mission_key)->orderBy('desde')->orderBy('id')->get();
    }

    /**
     * @param string $mission_key
     *
     * @return int
     */
    private function abiertas($mission_key)
    {
        return DB::table('local_mission_phases')->where('mission_key', $mission_key)->whereNull('hasta')->count();
    }

    /**
     * R4: el caso del revisor. `[contexto T0]` y después `[plan T0, construccion T1]`, los dos inferidos: la
     * fila `contexto` es el renombre de `plan` (mismo `desde`) y se borra; queda una sola fase abierta.
     *
     * @return void
     */
    public function test_r4_renombre_inferido_no_deja_fase_abierta(): void
    {
        $t0 = $this->hace(120);
        $t1 = $this->hace(60);

        $this->reportar('cc:renombre', [$this->entrada('contexto', 'plan', $t0, null, true)]);
        $this->reportar('cc:renombre', [
            $this->entrada('plan', 'plan', $t0, $t1, true),
            $this->entrada('construccion', 'construccion', $t1, null, true),
        ]);

        $filas = $this->filas('cc:renombre');
        $this->assertSame(['plan', 'construccion'], $filas->pluck('fase')->all(), 'contexto se borra: era el renombre de plan.');
        $this->assertSame(1, $this->abiertas('cc:renombre'), 'Nunca más de una fase abierta por misión.');
        $this->assertSame('construccion', $filas->whereNull('hasta')->first()->fase);
    }

    /**
     * R4: el reporte nuevo ya no es inferido (el reporter pasó a leer el registro) y la fila guardada sí lo
     * era: se borra aunque el `desde` no coincida con ninguna fase reportada.
     *
     * @return void
     */
    public function test_r4_de_inferido_a_registro_borra_la_inferida_abierta(): void
    {
        $this->reportar('cc:registro', [$this->entrada('contexto', 'plan', $this->hace(130), null, true)]);
        $this->reportar('cc:registro', [
            $this->entrada('plan', 'plan', $this->hace(125), $this->hace(60), false),
            $this->entrada('construccion', 'construccion', $this->hace(60), null, false),
        ]);

        $this->assertSame(['plan', 'construccion'], $this->filas('cc:registro')->pluck('fase')->all());
        $this->assertSame(1, $this->abiertas('cc:registro'));
    }

    /**
     * R4: una fila abierta que no vino, no es inferida y no coincide con ninguna fase reportada se cierra con
     * `hasta` = el `desde` de la siguiente fase reportada y `segundos` calculado.
     *
     * @return void
     */
    public function test_r4_fase_abierta_que_no_vino_se_cierra_en_la_siguiente(): void
    {
        $t0 = $this->hace(200);
        $t1 = $this->hace(100);

        $this->reportar('cc:cierre', [$this->entrada('plan', 'plan', $t0, null, false)]);
        $this->reportar('cc:cierre', [$this->entrada('construccion', 'construccion', $t1, null, false)]);

        $filas = $this->filas('cc:cierre');
        $this->assertSame(['plan', 'construccion'], $filas->pluck('fase')->all());
        $this->assertSame(1, $this->abiertas('cc:cierre'));
        $plan = $filas[0];
        $this->assertNotNull($plan->hasta, 'plan se cierra.');
        $this->assertSame(Carbon::parse($t1)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), $plan->hasta);
        $this->assertSame(6000, (int) $plan->segundos);
    }

    /**
     * R4: si no hay una fase reportada posterior, la huérfana se cierra con la hora del reporte.
     *
     * @return void
     */
    public function test_r4_sin_siguiente_se_cierra_con_la_hora_del_reporte(): void
    {
        $this->reportar('cc:hora', [$this->entrada('plan', 'plan', $this->hace(200), null, false)]);
        $this->reportar('cc:hora', [$this->entrada('construccion', 'construccion', $this->hace(300), $this->hace(250), false)]);

        $plan = $this->filas('cc:hora')->where('fase', 'plan')->first();
        $this->assertNotNull($plan->hasta);
        $this->assertSame(Carbon::now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), $plan->hasta);
        $this->assertSame(12000, (int) $plan->segundos);
        $this->assertSame(0, $this->abiertas('cc:hora') - 0, 'Ninguna fase reportada queda abierta, la huérfana se cerró.');
    }

    /**
     * R5: sólo `trabajando` puede estar demorada; `esperando_lucas` y `trabada` se ven con su propio estado.
     * `misiones_en_curso` y `por_origen` no cuentan las terminadas.
     *
     * @return void
     */
    public function test_r5_demorada_es_sólo_de_trabajando(): void
    {
        $viejo = $this->hace(600);
        $armar = function ($id, $estado) use ($viejo) {
            return $this->mision_v2($id, [
                'estado' => $estado, 'etapa' => 'tests', 'fase' => 'suite-final', 'fase_desde' => $viejo,
                'fase_historial' => [$this->entrada('suite-final', 'tests', $viejo, null)],
            ]);
        };
        $this->enviar_v2($this->reporte_v2(['misiones' => [
            $armar('cc:trab', 'trabajando'), $armar('cc:espera', 'esperando_lucas'), $armar('cc:trabada', 'trabada'), $armar('cc:fin', 'terminada'),
        ]]))->assertStatus(200);

        $escena   = $this->escena_v2();
        $por_id   = [];
        foreach ($escena['oficina']['misiones'] as $mision) {
            $por_id[$mision['id']] = $mision;
        }

        $this->assertTrue($por_id['cc:trab']['demorada']);
        $this->assertFalse($por_id['cc:espera']['demorada'], 'Esperando a Lucas no es demorada.');
        $this->assertFalse($por_id['cc:trabada']['demorada'], 'Trabada no es demorada.');
        $this->assertFalse($por_id['cc:fin']['demorada']);
        $this->assertSame(3, $escena['resumen']['misiones_en_curso'], 'La terminada no cuenta.');
        $this->assertSame(3, array_sum($escena['oficina']['por_origen']));
    }

    /**
     * Reporte con 60 misiones pesadas (10 en curso y 50 terminadas) con historial de fases cada una.
     *
     * @return array<string, mixed>
     */
    private function reporte_grande()
    {
        $misiones = [];
        for ($n = 1; $n <= 60; $n++) {
            $worktrees = [];
            for ($w = 1; $w <= 8; $w++) {
                $worktrees[] = [
                    'repo' => 'admin-api', 'carpeta' => 'admin-api-mision-larga-numero-' . $n . '-w' . $w, 'rama' => 'rama-' . $n,
                    'sucios' => $w, 'ultima_actividad_at' => '2026-11-25T20:39:10-03:00',
                ];
            }
            $misiones[] = $this->mision_v2('cc:larga-' . $n, [
                'estado' => $n <= 10 ? 'trabajando' : 'terminada', 'worktrees' => $worktrees,
                'fase_historial' => [
                    $this->entrada('plan', 'plan', $this->hace(300), $this->hace(200)),
                    $this->entrada('construccion', 'construccion', $this->hace(200), null),
                ],
            ]);
        }
        $reporte             = $this->reporte_v2();
        $reporte['misiones'] = $misiones;

        return $reporte;
    }

    /**
     * R6: la escena dice cuántas misiones no entran; el snapshot guardado no lleva `fase_historial` y la tabla
     * sí conserva el historial.
     *
     * @return void
     */
    public function test_r6_recorte_visible_y_snapshot_sin_historial(): void
    {
        $this->enviar_v2($this->reporte_grande())->assertStatus(200);

        $guardado = json_decode(AdminSetting::get('local_pc_misiones'), true);
        $this->assertTrue($guardado['recortado']);
        foreach ($guardado['items'] as $mision) {
            $this->assertArrayNotHasKey('fase_historial', $mision, 'El historial vive en la tabla, no en el snapshot.');
        }
        $this->assertGreaterThan(0, $this->filas('cc:larga-1')->count(), 'La tabla conserva el historial.');

        $escena  = $this->escena_v2();
        $recorte = $escena['oficina']['misiones_recorte'];
        $this->assertSame(['recortado', 'omitidas'], array_keys($recorte));
        $this->assertTrue($recorte['recortado']);
        $this->assertSame(60 - count($escena['oficina']['misiones']), $recorte['omitidas']);

        // La misión de la escena sigue trayendo su `fase_historial` (sale de la tabla).
        $en_curso = $escena['oficina']['misiones'][0];
        $this->assertCount(2, $en_curso['fase_historial']);
    }

    /**
     * R6: sin recorte la escena dice `recortado` false y 0 omitidas.
     *
     * @return void
     */
    public function test_r6_sin_recorte(): void
    {
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);

        $this->assertSame(['recortado' => false, 'omitidas' => 0], $this->escena_v2()['oficina']['misiones_recorte']);
    }

    /**
     * R7: una cola enorme se recorta a 60 000 bytes como los otros bloques y queda marcada.
     *
     * @return void
     */
    public function test_r7_cola_grande_se_recorta_a_60kb(): void
    {
        $en_espera = [];
        for ($n = 1; $n <= 400; $n++) {
            $en_espera[] = [
                'slug' => 'mision-en-cola-' . $n, 'titulo' => str_repeat('Título largo ', 9), 'prioridad' => 'high', 'origen' => 'claude-code',
                'encolada_at' => '2026-11-25T20:05:00-03:00', 'espera_min' => 35, 'motivos' => ['memoria', 'cupo'], 'intentos' => 2,
                'primera_retencion_at' => '2026-11-25T20:06:00-03:00',
            ];
        }
        $chequeos = [];
        for ($n = 0; $n < 60; $n++) {
            $chequeos[] = ['at' => Carbon::now()->subMinutes($n)->toIso8601String(), 'ram_disponible_gib' => 12.1, 'load1' => 0.3, 'activas_total' => 3, 'largo_cola' => 1];
        }

        /* La validación del endpoint topa la cola en 50 esperas y 20 chequeos (unos 30 KB), así que el recorte es
           una defensa: se la ejercita llamando al servicio con el cuerpo ya «validado», igual que haría el endpoint. */
        app(\App\Services\Local\PcSnapshotService::class)->save($this->reporte_v2(['cola' => [
            'ok' => true, 'fallidas' => 0, 'en_espera' => $en_espera, 'ultimo_chequeo' => ['at' => $this->hace(1)], 'chequeos' => $chequeos,
        ]]), Carbon::now());

        $crudo = AdminSetting::get('local_pc_cola');
        $this->assertNotNull($crudo);
        $this->assertLessThanOrEqual(60000, strlen($crudo));
        $this->assertTrue(json_decode($crudo, true)['recortado']);
    }
}

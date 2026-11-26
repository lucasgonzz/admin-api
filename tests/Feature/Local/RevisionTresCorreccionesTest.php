<?php

namespace Tests\Feature\Local;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Correcciones de la revisión 2 de la misión el-local-linux (hallazgos R13, R14 y R15 de admin-api).
 *
 * - R13: una fila del historial se identifica por (misión, fase, desde); los reportes recortados a las últimas
 *   20 fases no pueden pisar filas ni correr las vueltas.
 * - R14: `demorada` nunca es verdadera en la etapa `cierre`.
 * - R15: `hasta` nunca queda antes de `desde`; un renombre inferido borra también la fila cerrada.
 */
class RevisionTresCorreccionesTest extends TestCase
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
     * ISO de «ahora menos N minutos» (negativo = futuro).
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
            'segundos' => $hasta === null ? null : max(0, Carbon::parse($hasta)->timestamp - Carbon::parse($desde)->timestamp),
            'inferida' => $inferida,
        ];
    }

    /**
     * Manda un reporte con una sola misión y ese historial.
     *
     * @param string            $id
     * @param array<int, array> $historial
     * @param array<string, mixed> $cambios
     *
     * @return void
     */
    private function reportar($id, array $historial, array $cambios = [])
    {
        $ultima = end($historial);
        $this->enviar_v2($this->reporte_v2(['misiones' => [$this->mision_v2($id, array_merge([
            'fase' => $ultima['fase'], 'etapa' => $ultima['etapa'], 'fase_desde' => $ultima['desde'], 'fase_historial' => $historial,
        ], $cambios))]]))->assertStatus(200);
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
     * R13: el caso del revisor. Historial real de 26 fases con `revision-merge` x3 y `suite-final` x2 al
     * principio; el reporter manda cada vez las últimas 20 y el admin recibe POSTs acumulativos. Tiene que
     * haber 26 filas, cada fila con su `desde` y sus vueltas sin repetir.
     *
     * @return void
     */
    public function test_r13_reportes_recortados_a_20_no_pisan_filas(): void
    {
        $nombres = ['revision-merge', 'suite-final', 'revision-merge', 'suite-final', 'revision-merge'];
        for ($n = 1; $n <= 21; $n++) {
            $nombres[] = 'fase-' . $n;
        }
        $total = count($nombres);
        $this->assertSame(26, $total);

        // Historial completo: cada fase dura 10 minutos y las anteriores a la última están cerradas.
        $completo = [];
        foreach ($nombres as $i => $fase) {
            $desde = 400 - 10 * $i;
            $hasta = $i === $total - 1 ? null : $this->hace($desde - 10);
            $completo[] = $this->entrada($fase, 'construccion', $this->hace($desde), $hasta);
        }

        // POSTs acumulativos: del 1.º al 26.º, siempre las últimas 20.
        for ($k = 1; $k <= $total; $k++) {
            $this->reportar('cc:recorte', array_slice(array_slice($completo, 0, $k), -20));
        }

        $filas = $this->filas('cc:recorte');
        $this->assertCount(26, $filas, 'La cantidad de filas es la real.');
        foreach ($completo as $i => $entrada) {
            $esperado = Carbon::parse($entrada['desde'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            $this->assertSame($nombres[$i], $filas[$i]->fase, 'Fila ' . $i . ' con su fase.');
            $this->assertSame($esperado, $filas[$i]->desde, 'Fila ' . $i . ' con su desde.');
        }
        $this->assertSame([1, 2, 3], $filas->where('fase', 'revision-merge')->pluck('vuelta')->map(function ($v) {
            return (int) $v;
        })->values()->all());
        $this->assertSame([1, 2], $filas->where('fase', 'suite-final')->pluck('vuelta')->map(function ($v) {
            return (int) $v;
        })->values()->all());
        $this->assertSame(1, $filas->whereNull('hasta')->count(), 'Sólo la última queda abierta.');
    }

    /**
     * R14: la etapa `cierre` (fases informe/entregada) nunca está demorada, aunque lleve más del umbral.
     *
     * @return void
     */
    public function test_r14_cierre_nunca_esta_demorada(): void
    {
        $viejo = $this->hace(900);
        $this->reportar('cc:cierre', [$this->entrada('informe', 'cierre', $viejo, null)], ['estado' => 'trabajando']);

        $escena  = $this->escena_v2();
        $mision  = null;
        foreach ($escena['oficina']['misiones'] as $m) {
            if ($m['id'] === 'cc:cierre') {
                $mision = $m;
            }
        }
        $this->assertNotNull($mision);
        $this->assertGreaterThan(120, $mision['minutos_en_etapa']);
        $this->assertFalse($mision['demorada'], 'En cierre nunca hay demora.');
    }

    /**
     * R15: una fase reportada con `hasta` antes de `desde` se guarda con `hasta = desde` y 0 segundos.
     *
     * @return void
     */
    public function test_r15_hasta_nunca_antes_de_desde(): void
    {
        $this->reportar('cc:invertida', [
            $this->entrada('plan', 'plan', $this->hace(60), $this->hace(90)),
            $this->entrada('construccion', 'construccion', $this->hace(30), null),
        ]);

        $fila = $this->filas('cc:invertida')[0];
        $this->assertSame($fila->desde, $fila->hasta);
        $this->assertSame(0, (int) $fila->segundos);

        // Cierre por fase abierta que dejó de venir, con `desde` posterior a la hora del reporte.
        $this->reportar('cc:futura', [$this->entrada('plan', 'plan', $this->hace(-30), null)]);
        $this->reportar('cc:futura', [$this->entrada('construccion', 'construccion', $this->hace(-20), null)]);
        $plan = $this->filas('cc:futura')->where('fase', 'plan')->first();
        $this->assertNotNull($plan->hasta);
        $this->assertGreaterThanOrEqual($plan->desde, $plan->hasta, 'hasta no queda antes de desde.');
        $this->assertSame(0, (int) $plan->segundos);
    }

    /**
     * R15: si un reporte trae una fase con el mismo `desde` que una fila inferida CERRADA de otro nombre, la
     * guardada se borra (renombre inferido).
     *
     * @return void
     */
    public function test_r15_renombre_inferido_borra_tambien_la_cerrada(): void
    {
        $t0 = $this->hace(100);
        $this->reportar('cc:renombre', [
            $this->entrada('contexto', 'plan', $t0, $this->hace(50), true),
            $this->entrada('construccion', 'construccion', $this->hace(50), null, true),
        ]);
        $this->assertCount(2, $this->filas('cc:renombre'));

        // El reporter ahora la llama `plan`, con el mismo desde.
        $this->reportar('cc:renombre', [
            $this->entrada('plan', 'plan', $t0, $this->hace(50), true),
            $this->entrada('construccion', 'construccion', $this->hace(50), null, true),
        ]);

        $filas = $this->filas('cc:renombre');
        $this->assertSame(['plan', 'construccion'], $filas->pluck('fase')->all(), 'La cerrada `contexto` se borró.');
    }
}

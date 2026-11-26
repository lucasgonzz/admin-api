<?php

namespace Tests\Feature\Local;

use App\Helpers\AppTime;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Historial de fases y demora de las misiones (misión el-local-linux, 25/11/2026, ampliación §2.E).
 *
 * El reporter manda `fase_historial` por misión y el admin lo guarda en la tabla `local_mission_phases` (una fila
 * por misión, fase y vuelta) para poder consultarlo después (CA25). La escena calcula por misión cuánto lleva en
 * su etapa, contra el umbral configurable de `config/el_local.php`, y si está `demorada` (CA26).
 *
 * El reloj se congela: las horas del historial se arman restando horas a «ahora».
 */
class HistorialDeFasesTest extends TestCase
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
     * Reporte con una sola misión y ese historial.
     *
     * @param string               $id
     * @param array<int, array>    $historial
     * @param array<string, mixed> $cambios Cambios sobre la misión.
     *
     * @return array<string, mixed>
     */
    private function reporte_con_historial($id, array $historial, array $cambios = [])
    {
        $ultima = end($historial);

        return $this->reporte_v2(['misiones' => [$this->mision_v2($id, array_merge([
            'fase' => $ultima['fase'], 'etapa' => $ultima['etapa'], 'fase_desde' => $ultima['desde'], 'fase_historial' => $historial,
        ], $cambios))]]);
    }

    /**
     * Filas de la tabla para una misión, de la más vieja a la más nueva.
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
     * Hora como queda guardada en la base (zona de la app).
     *
     * @param string $iso
     *
     * @return string
     */
    private function en_db($iso)
    {
        return Carbon::parse($iso)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /**
     * CA25: 3 fases = 3 filas con misión, fase, vuelta 1, desde, hasta, segundos e inferida. Una fase repetida
     * (construcción, revisión 1, construcción) lleva vuelta 1 y vuelta 2.
     *
     * @return void
     */
    public function test_guarda_filas_por_fase_y_vuelta(): void
    {
        $historial = [
            $this->entrada('plan', 'plan', $this->hace(300), $this->hace(240)),
            $this->entrada('construccion', 'construccion', $this->hace(240), $this->hace(120)),
            $this->entrada('suite-final', 'tests', $this->hace(120), null, true),
        ];

        $this->enviar_v2($this->reporte_con_historial('cc:fases', $historial))->assertStatus(200);

        $filas = $this->filas('cc:fases');
        $this->assertCount(3, $filas);
        $this->assertSame(['plan', 'construccion', 'suite-final'], $filas->pluck('fase')->all());
        $this->assertSame(['plan', 'construccion', 'tests'], $filas->pluck('etapa')->all());
        $this->assertSame([1, 1, 1], $filas->pluck('vuelta')->map(function ($v) {
            return (int) $v;
        })->all());
        $this->assertSame($this->en_db($historial[0]['desde']), $filas[0]->desde);
        $this->assertSame($this->en_db($historial[0]['hasta']), $filas[0]->hasta);
        $this->assertSame(3600, (int) $filas[0]->segundos);
        $this->assertSame(7200, (int) $filas[1]->segundos);
        $this->assertNull($filas[2]->hasta);
        $this->assertNull($filas[2]->segundos);
        $this->assertSame([0, 0, 1], $filas->pluck('inferida')->map(function ($v) {
            return (int) $v;
        })->all());

        // Fase repetida: construccion -> revision-1 -> construccion.
        $repetida = [
            $this->entrada('construccion', 'construccion', $this->hace(300), $this->hace(200)),
            $this->entrada('revision-1', 'revision', $this->hace(200), $this->hace(100)),
            $this->entrada('construccion', 'construccion', $this->hace(100), null),
        ];
        $this->enviar_v2($this->reporte_con_historial('cc:vuelta', $repetida))->assertStatus(200);

        $construccion = $this->filas('cc:vuelta')->where('fase', 'construccion');
        $this->assertSame([1, 2], $construccion->pluck('vuelta')->map(function ($v) {
            return (int) $v;
        })->values()->all());
        $this->assertSame(1, $this->filas('cc:vuelta')->where('fase', 'revision-1')->count());
    }

    /**
     * CA25: una fase `inferida` conserva el primer `desde` que se vio (la mejor estimación); con `inferida`
     * false el `desde` reportado se pisa. Reenviar no duplica filas.
     *
     * @return void
     */
    public function test_inferida_conserva_el_primer_desde(): void
    {
        $primero = $this->hace(120);
        $this->enviar_v2($this->reporte_con_historial('cc:inf', [$this->entrada('revision-1', 'revision', $primero, null, true)]))->assertStatus(200);
        $this->assertSame($this->en_db($primero), $this->filas('cc:inf')[0]->desde);

        // 20 minutos más tarde la misma fase inferida: el desde guardado no cambia.
        $mas_tarde = $this->hace(100);
        $this->enviar_v2($this->reporte_con_historial('cc:inf', [$this->entrada('revision-1', 'revision', $mas_tarde, null, true)]))->assertStatus(200);
        $this->assertCount(1, $this->filas('cc:inf'), 'Reenviar no duplica.');
        $this->assertSame($this->en_db($primero), $this->filas('cc:inf')[0]->desde, 'Una inferida conserva el primer desde.');

        // Si deja de ser inferida, se toma lo reportado.
        $real = $this->hace(95);
        $this->enviar_v2($this->reporte_con_historial('cc:inf', [$this->entrada('revision-1', 'revision', $real, null, false)]))->assertStatus(200);
        $fila = $this->filas('cc:inf')[0];
        $this->assertCount(1, $this->filas('cc:inf'));
        $this->assertSame($this->en_db($real), $fila->desde, 'Una fase no inferida pisa el desde.');
        $this->assertSame(0, (int) $fila->inferida);
    }

    /**
     * CA25: la misión que deja de venir en los reportes conserva sus filas (por eso es «consultable después»).
     *
     * @return void
     */
    public function test_la_mision_que_se_va_conserva_su_historial(): void
    {
        $historial = [
            $this->entrada('plan', 'plan', $this->hace(300), $this->hace(240)),
            $this->entrada('construccion', 'construccion', $this->hace(240), $this->hace(120)),
            $this->entrada('suite-final', 'tests', $this->hace(120), null),
        ];
        $this->enviar_v2($this->reporte_con_historial('cc:se-va', $historial))->assertStatus(200);
        $this->assertCount(3, $this->filas('cc:se-va'));

        // El reporte siguiente ya no trae esa misión.
        $this->enviar_v2($this->reporte_v2(['misiones' => [$this->mision_v2('cc:otra')]]))->assertStatus(200);

        $this->assertCount(3, $this->filas('cc:se-va'), 'Sus filas siguen.');
    }

    /**
     * Los 4 historiales inválidos.
     *
     * @return array<string, array{0: callable}>
     */
    public function historiales_invalidos_provider(): array
    {
        $valida = function ($fase = 'plan', $etapa = 'plan') {
            return ['fase' => $fase, 'etapa' => $etapa, 'desde' => '2026-11-25T10:00:00-03:00', 'hasta' => null, 'segundos' => null, 'inferida' => false];
        };

        return [
            'entrada sin desde' => [function () use ($valida) {
                $entrada = $valida();
                unset($entrada['desde']);

                return [$entrada];
            }],
            'fase Suite Final'  => [function () use ($valida) {
                return [$valida('Suite Final', 'tests')];
            }],
            '21 entradas'       => [function () use ($valida) {
                $entradas = [];
                for ($n = 1; $n <= 21; $n++) {
                    $entradas[] = $valida('fase-' . $n);
                }

                return $entradas;
            }],
            'etapa deploy'      => [function () use ($valida) {
                return [$valida('plan', 'deploy')];
            }],
        ];
    }

    /**
     * CA25: un historial inválido es 422 y no guarda nada: ni filas ni snapshot.
     *
     * @dataProvider historiales_invalidos_provider
     *
     * @param callable $armar Devuelve el `fase_historial` inválido.
     *
     * @return void
     */
    public function test_historial_invalido_es_422_sin_guardar(callable $armar): void
    {
        $reporte = $this->reporte_v2(['misiones' => [$this->mision_v2('cc:malo', ['fase_historial' => $armar()])]]);

        $respuesta = $this->enviar_v2($reporte);

        $respuesta->assertStatus(422);
        $this->assertNotEmpty($respuesta->json('errors'));
        $this->assertSame(0, DB::table('local_mission_phases')->count(), 'Ni una fila.');
        $this->assertNull(\App\Models\AdminSetting::get('local_pc_misiones'), 'Ni el snapshot.');
    }

    /**
     * Las misiones del escenario de demora de CA26, con `now` = 21:00.
     *
     * @return array<string, mixed> Reporte v2.
     */
    private function reporte_de_demoras()
    {
        return $this->reporte_v2(['misiones' => [
            // Tests desde hace 4 h: 240 > 120 -> demorada.
            $this->mision_v2('cc:tests', [
                'etapa' => 'tests', 'fase' => 'suite-final', 'fase_desde' => $this->hace(240),
                'fase_historial' => [
                    $this->entrada('construccion', 'construccion', $this->hace(480), $this->hace(240)),
                    $this->entrada('suite-final', 'tests', $this->hace(240), null),
                ],
            ]),
            // Construcción desde hace 3 h: 180 < 240 -> no demorada.
            $this->mision_v2('cc:cons', [
                'etapa' => 'construccion', 'fase' => 'construccion', 'fase_desde' => $this->hace(180),
                'fase_historial' => [$this->entrada('construccion', 'construccion', $this->hace(180), null)],
            ]),
            // Revisión 1 hace 3 h y revisión 2 hace 1 h: la etapa cuenta desde la primera (180 > 120).
            $this->mision_v2('cc:rev', [
                'etapa' => 'revision', 'fase' => 'revision-2', 'fase_desde' => $this->hace(60),
                'fase_historial' => [
                    $this->entrada('revision-1', 'revision', $this->hace(180), $this->hace(60)),
                    $this->entrada('revision-2', 'revision', $this->hace(60), null),
                ],
            ]),
            // Terminada: nunca demorada aunque lleve 5 h.
            $this->mision_v2('cc:fin', [
                'estado' => 'terminada', 'etapa' => 'tests', 'fase' => 'suite-final', 'fase_desde' => $this->hace(300),
                'fase_historial' => [$this->entrada('suite-final', 'tests', $this->hace(300), null)],
            ]),
        ]]);
    }

    /**
     * Misión de la escena por id.
     *
     * @param array<string, mixed> $escena
     * @param string               $id
     *
     * @return array<string, mixed>
     */
    private function mision_de($escena, $id)
    {
        foreach ($escena['oficina']['misiones'] as $mision) {
            if ($mision['id'] === $id) {
                return $mision;
            }
        }
        $this->fail('La misión ' . $id . ' no está en la escena.');
    }

    /**
     * CA26: minutos en la etapa, umbral por etapa y `demorada` en los cinco escenarios del plan.
     *
     * @return void
     */
    public function test_minutos_en_etapa_y_demorada(): void
    {
        $this->enviar_v2($this->reporte_de_demoras())->assertStatus(200);
        $escena = $this->escena_v2();

        $tests = $this->mision_de($escena, 'cc:tests');
        $this->assertSame('tests', $tests['etapa']);
        $this->assertSame(240, $tests['minutos_en_etapa']);
        $this->assertSame(120, $tests['umbral_min']);
        $this->assertTrue($tests['demorada']);
        $this->assertSame(Carbon::now()->subMinutes(240)->timestamp, Carbon::parse($tests['etapa_desde'])->timestamp);

        $cons = $this->mision_de($escena, 'cc:cons');
        $this->assertSame(180, $cons['minutos_en_etapa']);
        $this->assertSame(240, $cons['umbral_min']);
        $this->assertFalse($cons['demorada']);

        $rev = $this->mision_de($escena, 'cc:rev');
        $this->assertSame(Carbon::now()->subMinutes(180)->timestamp, Carbon::parse($rev['etapa_desde'])->timestamp, 'Revisión 1 y 2 cuentan juntas.');
        $this->assertSame(180, $rev['minutos_en_etapa']);
        $this->assertTrue($rev['demorada']);

        $fin = $this->mision_de($escena, 'cc:fin');
        $this->assertFalse($fin['demorada'], 'Una terminada nunca está demorada.');

        // Lo reportado se devuelve y el mapa de umbrales viaja para la SPA.
        $this->assertArrayHasKey('fase_desde', $tests);
        $this->assertCount(2, $tests['fase_historial']);
        $this->assertEquals(
            ['plan' => 120, 'construccion' => 240, 'revision' => 120, 'tests' => 120, 'merge' => 60, 'cierre' => 120],
            $escena['oficina']['umbrales_demora']
        );
    }

    /**
     * CA26: el umbral sale de `config/el_local.php`: bajarlo a 60 min demora la construcción de 3 h.
     *
     * @return void
     */
    public function test_umbral_configurable(): void
    {
        config(['el_local.umbrales_demora_min.construccion' => 60]);

        $this->enviar_v2($this->reporte_de_demoras())->assertStatus(200);
        $escena = $this->escena_v2();

        $cons = $this->mision_de($escena, 'cc:cons');
        $this->assertSame(60, $cons['umbral_min']);
        $this->assertTrue($cons['demorada']);
        $this->assertSame(60, (int) $escena['oficina']['umbrales_demora']['construccion']);
    }

    /**
     * CA26: `fases` sale de la tabla (no del payload): ordenadas por `desde`, con `segundos`, y tope de 15.
     *
     * @return void
     */
    public function test_fases_salen_de_la_tabla(): void
    {
        $historial = [
            $this->entrada('plan', 'plan', $this->hace(300), $this->hace(240)),
            $this->entrada('construccion', 'construccion', $this->hace(240), $this->hace(120)),
            $this->entrada('suite-final', 'tests', $this->hace(120), null),
        ];
        $this->enviar_v2($this->reporte_con_historial('cc:tabla', $historial))->assertStatus(200);

        $fases = $this->mision_de($this->escena_v2(), 'cc:tabla')['fases'];
        $this->assertSame(['plan', 'construccion', 'suite-final'], array_column($fases, 'fase'));
        $this->assertSame(['fase', 'etapa', 'desde', 'hasta', 'segundos', 'inferida'], array_keys($fases[0]));
        $this->assertSame(3600, $fases[0]['segundos']);
        $this->assertNull($fases[2]['hasta']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $fases[0]['desde']);

        // Una fila que sólo existe en la tabla aparece: la fuente es la tabla.
        DB::table('local_mission_phases')->insert([
            'mission_key' => 'cc:tabla', 'origen' => 'claude-code', 'titulo' => 'x', 'fase' => 'contexto', 'etapa' => 'plan', 'vuelta' => 1,
            'desde' => Carbon::now()->subMinutes(400)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), 'hasta' => null, 'segundos' => null,
            'inferida' => 1, 'reportado_at' => AppTime::now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'created_at' => AppTime::now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), 'updated_at' => AppTime::now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ]);
        $fases = $this->mision_de($this->escena_v2(), 'cc:tabla')['fases'];
        $this->assertSame(['contexto', 'plan', 'construccion', 'suite-final'], array_column($fases, 'fase'));

        // Tope de 15 y orden por desde.
        $ahora_db = AppTime::now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        for ($n = 1; $n <= 20; $n++) {
            DB::table('local_mission_phases')->insert([
                'mission_key' => 'cc:tabla', 'origen' => 'claude-code', 'titulo' => 'x', 'fase' => 'extra-' . $n, 'etapa' => 'plan', 'vuelta' => 1,
                'desde' => Carbon::now()->subMinutes(1000 - $n)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'), 'hasta' => null, 'segundos' => null,
                'inferida' => 1, 'reportado_at' => $ahora_db, 'created_at' => $ahora_db, 'updated_at' => $ahora_db,
            ]);
        }
        $fases  = $this->mision_de($this->escena_v2(), 'cc:tabla')['fases'];
        $desdes = array_map('strtotime', array_column($fases, 'desde'));
        $this->assertCount(15, $fases);
        $ordenadas = $desdes;
        sort($ordenadas);
        $this->assertSame($ordenadas, $desdes, 'Ordenadas por desde.');
    }
}

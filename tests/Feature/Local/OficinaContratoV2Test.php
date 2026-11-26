<?php

namespace Tests\Feature\Local;

use App\Models\AdminSetting;
use App\Models\LocalOficinaSlot;
use App\Services\Local\OficinaIngestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `POST api/claude/local/oficina` contrato A v2 (misión el-local-linux, 25/11/2026): el reporter de Linux manda
 * misiones, el Workboard y las tareas programadas de OpenClaw, y la cola de misiones por recursos, en lugar del
 * pool de 35 slots.
 *
 * Fija: la guarda del snapshot en `admin_settings` y el borrado de las filas viejas de slots (CA11), que un
 * reporte v1 después de uno v2 vuelve al modo slots (CA12), que un valor fuera de enum es 422 sin guardar nada
 * (CA13 y los casos de cola de CA27), que lo desconocido no se guarda (CA14), el techo de 60 KB por clave (CA15)
 * y que la cola se guarda y se valida (CA27).
 */
class OficinaContratoV2Test extends TestCase
{
    use DatabaseTransactions;
    use ReporteV2Fixture;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_v2();
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
     * Un reporte v1 (sin `contrato`) con los 35 slots libres, como el de la ASUS.
     *
     * @return array<string, mixed>
     */
    private function reporte_v1()
    {
        $slots = [];
        for ($n = 1; $n <= 35; $n++) {
            $slots[] = [
                'slot' => $n, 'proyecto' => $n <= 12 ? 'empresa' : 'admin', 'estado' => 'libre',
                'rama' => null, 'mision' => null, 'fase' => null, 'claim_iniciado_at' => null,
                'ultima_actividad_at' => null, 'sucios' => 0, 'agentes' => [],
            ];
        }

        return [
            'reporter' => ['version' => '1.0.0', 'host' => 'ASUS', 'enviado_at' => '2026-11-03T10:40:12-03:00', 'slots_total' => 35],
            'sesiones' => ['claude_busy' => 2, 'claude_idle' => 13, 'codex_activos' => 1],
            'slots'    => $slots,
        ];
    }

    /**
     * Una cola válida: una misión en espera, un chequeo real y su historial.
     *
     * @return array<string, mixed>
     */
    private function cola_valida()
    {
        $chequeo = [
            'at' => '2026-11-25T20:39:00-03:00', 'ram_disponible_gib' => 12.5, 'ram_total_gib' => 31.2, 'swap_libre_gib' => 7.9,
            'swap_total_gib' => 8.0, 'load1' => 0.2, 'load5' => 0.4, 'nproc' => 24, 'activas_total' => 3, 'activas_sin_pantalla' => 1,
            'largo_cola' => 1, 'pesados_ocupados' => 0, 'pesados_cupos' => 2, 'lanzadas' => ['otra-mision'], 'retenidas' => 1,
            'umbral_ram_gib' => 6, 'umbral_carga_por_nucleo' => 0.8,
        ];

        return [
            'ok'            => true,
            'fallidas'      => 1,
            'en_espera'     => [[
                'slug' => 'mision-en-cola', 'titulo' => 'Una misión esperando recursos', 'prioridad' => 'high', 'origen' => 'claude-code',
                'encolada_at' => '2026-11-25T20:05:00-03:00', 'espera_min' => 35, 'motivos' => ['memoria', 'cupo'], 'intentos' => 2,
                'primera_retencion_at' => '2026-11-25T20:06:00-03:00',
            ]],
            'ultimo_chequeo' => $chequeo,
            'chequeos'       => [
                ['at' => '2026-11-25T20:38:00-03:00', 'ram_disponible_gib' => 12.1, 'swap_libre_gib' => 7.9, 'load1' => 0.3, 'activas_total' => 3, 'largo_cola' => 1],
                ['at' => '2026-11-25T20:39:00-03:00', 'ram_disponible_gib' => 12.5, 'swap_libre_gib' => 7.9, 'load1' => 0.2, 'activas_total' => 3, 'largo_cola' => 1],
            ],
        ];
    }

    /**
     * CA11: un POST v2 guarda las tres claves del snapshot, deja el pulso con `contrato` y `plataforma`, borra
     * las 35 filas viejas de slots y responde con la forma v2.
     *
     * @return void
     */
    public function test_v2_guarda_snapshot_y_borra_slots(): void
    {
        $this->enviar_v2($this->reporte_v1())->assertStatus(200);
        $this->assertSame(35, LocalOficinaSlot::query()->count(), 'Precondición: 35 filas v1.');

        $respuesta = $this->enviar_v2($this->reporte_v2());

        $respuesta->assertStatus(200);
        $this->assertSame(['ok', 'recibido_at', 'slots', 'misiones', 'contrato'], array_keys($respuesta->json()));
        $this->assertTrue($respuesta->json('ok'));
        $this->assertSame(0, $respuesta->json('slots'));
        $this->assertSame(2, $respuesta->json('misiones'));
        $this->assertSame(2, $respuesta->json('contrato'));

        $misiones = $this->guardado_v2('local_pc_misiones');
        $this->assertNotNull($misiones, 'Falta la clave local_pc_misiones en admin_settings.');
        $this->assertCount(2, $misiones['items']);
        $this->assertSame(['cc:uno', 'cc:dos'], array_column($misiones['items'], 'id'));

        $workboard = $this->guardado_v2('local_pc_workboard');
        $this->assertNotNull($workboard, 'Falta la clave local_pc_workboard en admin_settings.');
        $this->assertCount(3, $workboard['tarjetas']);
        $this->assertSame(['running' => 1, 'blocked' => 1, 'done' => 1], $workboard['conteo']);

        $openclaw = $this->guardado_v2('local_pc_openclaw');
        $this->assertNotNull($openclaw, 'Falta la clave local_pc_openclaw en admin_settings.');
        $this->assertSame('ok', $openclaw['gateway']['estado']);
        $this->assertCount(2, $openclaw['tareas']['items']);

        $pulso = $this->guardado_v2(OficinaIngestService::PULSO_KEY);
        $this->assertSame(2, $pulso['contrato']);
        $this->assertSame('linux', $pulso['plataforma']);

        $this->assertSame(0, LocalOficinaSlot::query()->count(), 'El pool de slots ya no existe: se borran las filas viejas.');
    }

    /**
     * CA12: un reporte v1 después de uno v2 devuelve el modo a `slots` y las mesas vuelven a salir de los slots.
     *
     * @return void
     */
    public function test_v1_despues_de_v2_vuelve_a_slots(): void
    {
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
        $this->assertSame('misiones', $this->escena_v2()['oficina']['modo']);

        $v1 = $this->enviar_v2($this->reporte_v1());
        $v1->assertStatus(200);
        $this->assertSame(0, $v1->json('misiones'), 'Un v1 responde misiones 0.');
        $this->assertSame(1, $v1->json('contrato'), 'Un v1 responde contrato 1.');

        $escena = $this->escena_v2();
        $this->assertSame('slots', $escena['oficina']['modo']);
        $this->assertSame([], $escena['oficina']['misiones']);
        $this->assertCount(35, $escena['oficina']['slots']);
    }

    /**
     * Los 14 casos inválidos: cada uno muta un reporte v2 válido.
     *
     * @return array<string, array{0: callable}>
     */
    public function invalidos_provider(): array
    {
        $con_mision = function (array $cambios) {
            return function (array $reporte) use ($cambios) {
                $reporte['misiones'][0] = array_merge($reporte['misiones'][0], $cambios);

                return $reporte;
            };
        };
        $con_cola = function (callable $mutar) {
            return function (array $reporte) use ($mutar) {
                $reporte['cola'] = $mutar([
                    'ok' => true, 'fallidas' => 0, 'en_espera' => [], 'chequeos' => [],
                    'ultimo_chequeo' => ['at' => '2026-11-25T20:39:00-03:00', 'load1' => 0.2],
                ]);

                return $reporte;
            };
        };

        return [
            'origen marte'        => [$con_mision(['origen' => 'marte'])],
            'estado dormida'      => [$con_mision(['estado' => 'dormida'])],
            'etapa deploy'        => [$con_mision(['etapa' => 'deploy'])],
            'rol jefe'            => [$con_mision(['agentes' => [['rol' => 'jefe', 'runtime' => 'claude', 'estado' => 'activo']]])],
            'runtime gpt'         => [$con_mision(['agentes' => [['rol' => 'programador', 'runtime' => 'gpt', 'estado' => 'activo']]])],
            'misma id dos veces'  => [function (array $reporte) {
                $reporte['misiones'][1]['id'] = $reporte['misiones'][0]['id'];

                return $reporte;
            }],
            '61 misiones'         => [function (array $reporte) {
                $reporte['misiones'] = [];
                for ($n = 1; $n <= 61; $n++) {
                    $reporte['misiones'][] = ['id' => 'cc:m' . $n, 'origen' => 'claude-code', 'estado' => 'trabajando'];
                }

                return $reporte;
            }],
            'tarjeta archivada'   => [function (array $reporte) {
                $reporte['openclaw']['workboard']['tarjetas'][0]['estado'] = 'archivada';

                return $reporte;
            }],
            'tarea tipo script'   => [function (array $reporte) {
                $reporte['openclaw']['tareas']['items'][0]['tipo'] = 'script';

                return $reporte;
            }],
            'gateway roto'        => [function (array $reporte) {
                $reporte['openclaw']['gateway']['estado'] = 'roto';

                return $reporte;
            }],
            'fase con mayusculas' => [$con_mision(['fase' => 'Plan Final'])],
            'cola prioridad altisima' => [$con_cola(function (array $cola) {
                $cola['en_espera'] = [['slug' => 'x', 'prioridad' => 'altisima']];

                return $cola;
            })],
            'cola motivo disco'   => [$con_cola(function (array $cola) {
                $cola['en_espera'] = [['slug' => 'x', 'motivos' => ['disco']]];

                return $cola;
            })],
            'cola chequeo sin at' => [$con_cola(function (array $cola) {
                $cola['ultimo_chequeo'] = ['load1' => 0.2];

                return $cola;
            })],
        ];
    }

    /**
     * CA13 y CA27 (casos 422): un valor fuera de enum o de tope es 422 con `errors` por campo y no cambia nada:
     * ni `admin_settings` (snapshot y pulso) ni `local_oficina_slots`.
     *
     * @dataProvider invalidos_provider
     *
     * @param callable $mutar Arma el reporte inválido a partir de uno válido.
     *
     * @return void
     */
    public function test_valores_invalidos_son_422_sin_guardar(callable $mutar): void
    {
        // Estado previo: 35 filas v1 y su pulso.
        $this->enviar_v2($this->reporte_v1())->assertStatus(200);
        $pulso_antes = AdminSetting::get(OficinaIngestService::PULSO_KEY);

        $respuesta = $this->enviar_v2($mutar($this->reporte_v2()));

        $respuesta->assertStatus(422);
        $this->assertNotEmpty($respuesta->json('errors'), 'El 422 trae `errors` por campo.');

        foreach (['local_pc_misiones', 'local_pc_workboard', 'local_pc_openclaw', 'local_pc_cola'] as $clave) {
            $this->assertNull(AdminSetting::get($clave), 'Un 422 no guarda ' . $clave . '.');
        }
        $this->assertSame($pulso_antes, AdminSetting::get(OficinaIngestService::PULSO_KEY), 'Un 422 no toca el pulso.');
        $this->assertSame(35, LocalOficinaSlot::query()->count(), 'Un 422 no borra los slots.');
    }

    /**
     * CA14: lo que el contrato no define (`pedido` en una misión, `notes` en una tarjeta, `message` en una tarea)
     * no da error, pero no se guarda: ni la clave ni su valor.
     *
     * @return void
     */
    public function test_claves_desconocidas_no_se_guardan(): void
    {
        $reporte = $this->reporte_v2();
        $reporte['misiones'][0]['pedido']                        = 'SECRETO-PRIV pedido';
        $reporte['openclaw']['workboard']['tarjetas'][0]['notes'] = 'SECRETO-PRIV notas';
        $reporte['openclaw']['tareas']['items'][0]['message']     = 'SECRETO-PRIV mensaje';

        $this->enviar_v2($reporte)->assertStatus(200);

        foreach (['local_pc_misiones', 'local_pc_workboard', 'local_pc_openclaw'] as $clave) {
            $crudo = AdminSetting::get($clave);
            $this->assertNotNull($crudo, 'Falta ' . $clave . '.');
            $this->assertStringNotContainsString('SECRETO-PRIV', $crudo, $clave . ' guardó un valor desconocido.');
        }
        $this->assertStringNotContainsString('"pedido"', AdminSetting::get('local_pc_misiones'));
        $this->assertStringNotContainsString('"notes"', AdminSetting::get('local_pc_workboard'));
        $this->assertStringNotContainsString('"message"', AdminSetting::get('local_pc_openclaw'));
    }

    /**
     * CA15 (Workboard): 200 tarjetas de 160 caracteres (40 `running`, 160 `done`) entran, pero el JSON guardado
     * mide <= 60 000 bytes, queda `recortado`, están las 40 `running` y `conteo` sigue intacto.
     *
     * @return void
     */
    public function test_workboard_grande_se_recorta_a_60kb(): void
    {
        $tarjetas = [];
        for ($n = 1; $n <= 200; $n++) {
            $estado     = $n <= 40 ? 'running' : 'done';
            $tarjetas[] = $this->tarjeta_v2('tarjeta-' . $n, $estado, ['titulo' => str_pad('Tarjeta ' . $n . ' ', 160, 'x')]);
        }
        $reporte = $this->reporte_v2();
        $reporte['openclaw']['workboard'] = ['ok' => true, 'total' => 200, 'conteo' => ['running' => 40, 'done' => 160], 'tarjetas' => $tarjetas];

        $this->enviar_v2($reporte)->assertStatus(200);

        $crudo = AdminSetting::get('local_pc_workboard');
        $this->assertNotNull($crudo);
        $this->assertLessThanOrEqual(60000, strlen($crudo));

        $guardado = json_decode($crudo, true);
        $this->assertTrue($guardado['recortado']);
        $this->assertSame(['running' => 40, 'done' => 160], $guardado['conteo'], 'El conteo real no se recorta.');
        $running = array_filter($guardado['tarjetas'], function ($tarjeta) {
            return $tarjeta['estado'] === 'running';
        });
        $this->assertCount(40, $running, 'Las running se conservan primero.');
    }

    /**
     * CA15 (misiones): 60 misiones con 8 worktrees y 20 agentes cada una (10 en curso y 50 terminadas) entran,
     * el JSON guardado mide <= 60 000 bytes, queda `recortado` y están las 10 no terminadas.
     *
     * @return void
     */
    public function test_misiones_grandes_se_recortan(): void
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
            $agentes = [];
            for ($a = 1; $a <= 20; $a++) {
                $agentes[] = ['rol' => 'programador', 'runtime' => 'claude', 'estado' => 'activo', 'desde_at' => '2026-11-25T20:00:00-03:00'];
            }
            $misiones[] = $this->mision_v2('cc:larga-' . $n, [
                'estado' => $n <= 10 ? 'trabajando' : 'terminada', 'worktrees' => $worktrees, 'agentes' => $agentes,
            ]);
        }
        $reporte             = $this->reporte_v2();
        $reporte['misiones'] = $misiones;

        $this->enviar_v2($reporte)->assertStatus(200);

        $crudo = AdminSetting::get('local_pc_misiones');
        $this->assertNotNull($crudo);
        $this->assertLessThanOrEqual(60000, strlen($crudo));

        $guardado = json_decode($crudo, true);
        $this->assertTrue($guardado['recortado']);
        $en_curso = array_filter($guardado['items'], function ($mision) {
            return $mision['estado'] !== 'terminada';
        });
        $this->assertCount(10, $en_curso, 'Quedan primero las no terminadas.');
    }

    /**
     * CA27: la cola se guarda en `local_pc_cola`, tal cual se mandó.
     *
     * @return void
     */
    public function test_cola_se_guarda_y_valida(): void
    {
        $cola = $this->cola_valida();

        $this->enviar_v2($this->reporte_v2(['cola' => $cola]))->assertStatus(200);

        $guardada = $this->guardado_v2('local_pc_cola');
        $this->assertNotNull($guardada, 'Falta la clave local_pc_cola en admin_settings.');
        $this->assertSame(1, $guardada['fallidas']);
        $this->assertCount(1, $guardada['en_espera']);
        $this->assertSame('mision-en-cola', $guardada['en_espera'][0]['slug']);
        $this->assertSame('high', $guardada['en_espera'][0]['prioridad']);
        $this->assertSame(['memoria', 'cupo'], $guardada['en_espera'][0]['motivos']);
        $this->assertEquals(12.5, $guardada['ultimo_chequeo']['ram_disponible_gib']);
        $this->assertSame(['otra-mision'], $guardada['ultimo_chequeo']['lanzadas']);
        $this->assertCount(2, $guardada['chequeos']);

        // Sin `cola` en el reporte siguiente no queda una cola vieja a la vista (la escena lo muestra como null).
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
        $escena = $this->escena_v2();
        $this->assertNull($escena['oficina']['cola']);
    }
}

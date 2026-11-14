<?php

namespace Tests\Feature\Prueba;

use App\Models\TrialInstance;
use App\Services\Prueba\PruebaPoolService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A5 (14/11/2026): `proximo_nombre_libre()` no reutiliza un nombre que ya gastó el cupo semanal de certificados
 * de Let's Encrypt (4 o más armados en 7 días, contando las borradas renombradas `prueba-NN~xxxxxx`).
 */
class NombresGastadosDelPoolTest extends BaseDePruebaGratuita
{
    /** Una fila de `trial_instances` con su `created_at` fijado (un armado de hace `$dias` días). */
    private function armado(string $nombre, string $estado = TrialInstance::ESTADO_BORRADA, float $dias = 1): int
    {
        $momento = Carbon::now()->subHours((int) round($dias * 24));

        return (int) DB::table('trial_instances')->insertGetId([
            'nombre'             => $nombre,
            'estado'             => $estado,
            'erp_vps_path'       => $nombre,
            'ecommerce_vps_path' => 'tienda-' . $nombre,
            'etapa'              => $estado,
            'progreso'           => 0,
            'created_at'         => $momento,
            'updated_at'         => $momento,
        ]);
    }

    private function nombre_de(int $id): string
    {
        return (string) DB::table('trial_instances')->where('id', $id)->value('nombre');
    }

    public function test_sin_historial_da_prueba_01(): void
    {
        $this->assertSame('prueba-01', app(PruebaPoolService::class)->proximo_nombre_libre());
    }

    public function test_el_mas_bajo_libre_con_tres_armados_sigue_siendo_el_elegido_y_su_borrada_se_renombra(): void
    {
        $this->armado('prueba-01~aaaaa1');
        $this->armado('prueba-01~aaaaa2');
        $borrada = $this->armado('prueba-01');

        $this->assertSame('prueba-01', app(PruebaPoolService::class)->proximo_nombre_libre());
        $this->assertMatchesRegularExpression('/^prueba-01~[0-9a-f]{6}$/', $this->nombre_de($borrada));
    }

    public function test_con_cuatro_armados_recientes_lo_saltea_y_da_el_siguiente_sin_renombrar_la_borrada_salteada(): void
    {
        $this->crear_instancia_libre('prueba-01', 900100);
        /* prueba-02: tres borradas ya recicladas y una borrada con el nombre pelado = 4 armados en la semana. */
        $this->armado('prueba-02~bbbbb1', TrialInstance::ESTADO_BORRADA, 6);
        $this->armado('prueba-02~bbbbb2', TrialInstance::ESTADO_BORRADA, 3);
        $this->armado('prueba-02~bbbbb3', TrialInstance::ESTADO_BORRADA, 2);
        $borrada_02 = $this->armado('prueba-02', TrialInstance::ESTADO_BORRADA, 0.5);
        /* prueba-03 tiene una borrada (un armado): es el elegido y su borrada se renombra. */
        $borrada_03 = $this->armado('prueba-03', TrialInstance::ESTADO_BORRADA, 1);

        $this->assertSame('prueba-03', app(PruebaPoolService::class)->proximo_nombre_libre());

        $this->assertSame('prueba-02', $this->nombre_de($borrada_02), 'La borrada del nombre salteado no se toca.');
        $this->assertMatchesRegularExpression('/^prueba-03~[0-9a-f]{6}$/', $this->nombre_de($borrada_03));
    }

    public function test_una_viva_creada_en_la_semana_cuenta_como_armado_del_nombre(): void
    {
        /* Tres borradas recicladas de prueba-01 y la viva (fallida) que se borra ahora: 4 armados. */
        $this->armado('prueba-01~ccccc1');
        $this->armado('prueba-01~ccccc2');
        $this->armado('prueba-01~ccccc3');
        $viva = $this->armado('prueba-01', TrialInstance::ESTADO_FALLIDA);
        DB::table('trial_instances')->where('id', $viva)->update(['estado' => TrialInstance::ESTADO_BORRADA]);

        $this->assertSame('prueba-02', app(PruebaPoolService::class)->proximo_nombre_libre());
        $this->assertSame('prueba-01', $this->nombre_de($viva));
    }

    public function test_los_armados_de_hace_mas_de_siete_dias_no_cuentan(): void
    {
        $this->armado('prueba-01~ddddd1', TrialInstance::ESTADO_BORRADA, 8);
        $this->armado('prueba-01~ddddd2', TrialInstance::ESTADO_BORRADA, 9);
        $this->armado('prueba-01~ddddd3', TrialInstance::ESTADO_BORRADA, 10);
        $this->armado('prueba-01~ddddd4', TrialInstance::ESTADO_BORRADA, 7.5);
        $this->armado('prueba-01~ddddd5', TrialInstance::ESTADO_BORRADA, 2);

        $this->assertSame('prueba-01', app(PruebaPoolService::class)->proximo_nombre_libre(), 'Solo uno cae en la ventana.');
    }

    public function test_no_confunde_prueba_05_con_prueba_050_ni_con_prueba_5(): void
    {
        foreach (['prueba-01', 'prueba-02', 'prueba-03', 'prueba-04'] as $i => $nombre) {
            $this->crear_instancia_libre($nombre, 900100 + $i * 100);
        }
        foreach (['e1', 'e2', 'e3', 'e4'] as $sufijo) {
            $this->armado('prueba-050~' . $sufijo);
            $this->armado('prueba-5~' . $sufijo);
            $this->armado('prueba-05' . $sufijo);
        }
        $this->armado('prueba-050');
        $this->armado('prueba-5');

        $this->assertSame('prueba-05', app(PruebaPoolService::class)->proximo_nombre_libre());
    }

    public function test_si_todos_los_libres_estan_gastados_la_excepcion_dice_por_que(): void
    {
        $filas   = [];
        $momento = Carbon::now()->subDay();
        for ($n = 1; $n <= PruebaPoolService::MAX_NUMERO; $n++) {
            $nombre = 'prueba-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            for ($k = 1; $k <= PruebaPoolService::ARMADOS_MAXIMOS_POR_NOMBRE; $k++) {
                $filas[] = [
                    'nombre'             => $nombre . '~f' . $k,
                    'estado'             => TrialInstance::ESTADO_BORRADA,
                    'erp_vps_path'       => $nombre,
                    'ecommerce_vps_path' => 'tienda-' . $nombre,
                    'progreso'           => 0,
                    'created_at'         => $momento,
                    'updated_at'         => $momento,
                ];
            }
        }
        DB::table('trial_instances')->insert($filas);

        try {
            app(PruebaPoolService::class)->proximo_nombre_libre();
            $this->fail('Sin nombres elegibles tenía que tirar la excepción.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No quedan nombres libres en el pool (prueba-01 a prueba-99)', $e->getMessage());
            $this->assertStringContainsString("Let's Encrypt", $e->getMessage());
            $this->assertStringContainsString('prueba-01 (4)', $e->getMessage());
        }
    }

    /**
     * El estado real de producción del 14/11/2026: 01–04 asignadas, 06 y 07 libres, la 05 fallida que se borra.
     * Armados en 7 días: 05 = 8, 06 = 6, 01 = 5, 04 = 5, 07 = 2.
     */
    public function test_el_estado_de_produccion_del_14_11_elige_prueba_08(): void
    {
        $historial = ['prueba-01' => 5, 'prueba-04' => 5, 'prueba-05' => 8, 'prueba-06' => 6, 'prueba-07' => 2];
        $vivas     = [
            'prueba-01' => TrialInstance::ESTADO_ASIGNADA,
            'prueba-02' => TrialInstance::ESTADO_ASIGNADA,
            'prueba-03' => TrialInstance::ESTADO_ASIGNADA,
            'prueba-04' => TrialInstance::ESTADO_ASIGNADA,
            'prueba-05' => TrialInstance::ESTADO_BORRADA,
            'prueba-06' => TrialInstance::ESTADO_LIBRE,
            'prueba-07' => TrialInstance::ESTADO_LIBRE,
        ];
        foreach ($vivas as $nombre => $estado) {
            $this->armado($nombre, $estado);
        }
        foreach ($historial as $nombre => $total) {
            for ($k = 1; $k < $total; $k++) {
                $this->armado($nombre . '~p' . $k);
            }
        }

        $this->assertSame('prueba-08', app(PruebaPoolService::class)->proximo_nombre_libre());
        $this->assertSame(1, TrialInstance::where('nombre', 'prueba-05')->count(), 'La borrada de prueba-05 conserva su nombre.');
    }
}

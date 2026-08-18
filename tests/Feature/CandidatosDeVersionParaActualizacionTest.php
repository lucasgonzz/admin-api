<?php

namespace Tests\Feature;

use App\Models\Version;
use App\Services\VersionPathService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `VersionPathService::candidatesBetween()`: el rango (from, to] entre versiones
 * PUBLICADAS, en orden SEMÁNTICO del código de versión, no por `id` de tabla.
 *
 * El caso de prueba central carga las versiones con `id` deliberadamente desordenado
 * respecto al orden semántico esperado — si el service ordenara (aunque sea de
 * casualidad) por `id`, este test lo expone. Es el ejemplo exacto que dio Lucas:
 * 3.3.1, 3.3.1.1, 3.3.1.2, 3.3.2, 3.3.2.1, 3.3.3.
 */
class CandidatosDeVersionParaActualizacionTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Crea una versión con código, status e is_hotfix a mano (no hay factories en el repo).
     *
     * @param string $codigo
     * @param string $status
     * @param bool   $is_hotfix
     *
     * @return Version
     */
    private function crear_version(string $codigo, string $status = 'published', bool $is_hotfix = false): Version
    {
        $version            = new Version();
        $version->uuid      = (string) Str::uuid();
        $version->version   = $codigo;
        $version->title     = 'Versión ' . $codigo;
        $version->status    = $status;
        $version->is_hotfix = $is_hotfix;
        if ($status === 'published') {
            $version->published_at = now();
        }
        $version->save();

        return $version;
    }

    /**
     * El ejemplo exacto del pedido de Lucas: 3.3.1, 3.3.1.1, 3.3.1.2, 3.3.2, 3.3.2.1,
     * 3.3.3, en ese orden semántico — cargadas en la base en un orden de `id` que NO
     * coincide con ese orden, para probar de verdad que `candidatesBetween` no ordena
     * por `id`.
     *
     * @return void
     */
    public function test_orden_semantico_exacto_del_ejemplo_de_lucas_con_ids_desordenados(): void
    {
        // Orden de inserción (y por lo tanto de `id`) deliberadamente distinto del orden
        // semántico esperado.
        $v_3_3_2   = $this->crear_version('3.3.2');
        $v_3_3_1_1 = $this->crear_version('3.3.1.1', 'published', true);
        $v_3_3_3   = $this->crear_version('3.3.3');
        $v_3_3_1   = $this->crear_version('3.3.1');
        $v_3_3_2_1 = $this->crear_version('3.3.2.1', 'published', true);
        $v_3_3_1_2 = $this->crear_version('3.3.1.2', 'published', true);

        // El `id` de inserción confirma que NO coincide con el orden semántico esperado.
        $this->assertLessThan($v_3_3_1_1->id, $v_3_3_2->id, 'La base del test no quedó desordenada como se esperaba.');

        $from = $this->crear_version('3.3.0');

        $candidatas = VersionPathService::candidatesBetween($from, $v_3_3_3);

        $this->assertSame(
            ['3.3.1', '3.3.1.1', '3.3.1.2', '3.3.2', '3.3.2.1', '3.3.3'],
            $candidatas->pluck('version')->all()
        );

        // is_hotfix correcto para 3 y 4+ componentes.
        $por_codigo = $candidatas->keyBy('version');
        $this->assertFalse((bool) $por_codigo['3.3.1']->is_hotfix);
        $this->assertTrue((bool) $por_codigo['3.3.1.1']->is_hotfix);
        $this->assertTrue((bool) $por_codigo['3.3.1.2']->is_hotfix);
        $this->assertFalse((bool) $por_codigo['3.3.2']->is_hotfix);
        $this->assertTrue((bool) $por_codigo['3.3.2.1']->is_hotfix);
        $this->assertFalse((bool) $por_codigo['3.3.3']->is_hotfix);

        // Evitar "unused variable" en análisis estático: $v_3_3_1 se usa solo para dejar
        // constancia de la versión intermedia sembrada.
        $this->assertNotNull($v_3_3_1->id);
    }

    /**
     * Una versión en `draft` y otra en `archived`, ambas dentro del rango numérico, quedan
     * excluidas: `candidatesBetween` sólo trae `published`.
     *
     * @return void
     */
    public function test_excluye_versiones_draft_y_archived_del_rango(): void
    {
        $from = $this->crear_version('3.4.0');
        $this->crear_version('3.4.1', 'draft');
        $this->crear_version('3.4.2', 'archived');
        $publicada = $this->crear_version('3.4.3');
        $to        = $this->crear_version('3.4.4');

        $candidatas = VersionPathService::candidatesBetween($from, $to);

        $this->assertSame(['3.4.3', '3.4.4'], $candidatas->pluck('version')->all());
        $this->assertTrue($candidatas->contains('id', $publicada->id));
    }

    /**
     * `$from === null`: sólo la versión destino, sin importar qué otras versiones
     * publicadas existan por debajo. Preserva la semántica de la vieja `versionsInRange()`
     * para clientes sin `current_version_id`.
     *
     * @return void
     */
    public function test_from_null_devuelve_solo_la_version_destino(): void
    {
        $this->crear_version('3.5.1');
        $this->crear_version('3.5.2');
        $to = $this->crear_version('3.5.3');

        $candidatas = VersionPathService::candidatesBetween(null, $to);

        $this->assertCount(1, $candidatas);
        $this->assertSame('3.5.3', $candidatas->first()->version);
    }
}

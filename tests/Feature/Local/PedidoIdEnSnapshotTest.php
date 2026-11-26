<?php

namespace Tests\Feature\Local;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA7 de la misión dots-openclaw: el reporte v2 trae un `pedido_id` opcional en cada misión y en cada misión en
 * espera de la cola. Se guarda sólo si cumple el formato `^[0-9]{8}-[0-9]{4}-[a-z0-9-]{1,40}$`; un valor raro
 * se guarda como `null` sin tirar el reporte entero (nunca 422) y su texto no aparece en ningún `local_pc_*`.
 */
class PedidoIdEnSnapshotTest extends TestCase
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
     * Una cola mínima válida con una misión en espera.
     *
     * @param string|null $pedido_id `pedido_id` de la misión en espera.
     *
     * @return array<string, mixed>
     */
    private function cola_con_pedido($pedido_id)
    {
        return [
            'ok' => true, 'fallidas' => 0,
            'en_espera' => [[
                'slug' => 'en-cola', 'titulo' => 'Una en espera', 'prioridad' => 'normal', 'origen' => 'dots',
                'encolada_at' => '2026-11-25T20:05:00-03:00', 'espera_min' => 5, 'motivos' => ['memoria'], 'intentos' => 1,
                'pedido_id' => $pedido_id,
            ]],
            'chequeos' => [],
        ];
    }

    /**
     * Un `pedido_id` con el formato válido se guarda tal cual en la misión y en la cola.
     *
     * @return void
     */
    public function test_guarda_pedido_id_valido(): void
    {
        $reporte = $this->reporte_v2(['cola' => $this->cola_con_pedido('20261126-0930-precios')]);
        $reporte['misiones'][0]['pedido_id'] = '20261126-0930-precios';

        $this->enviar_v2($reporte)->assertStatus(200);

        $misiones = $this->guardado_v2('local_pc_misiones');
        $this->assertArrayHasKey('pedido_id', $misiones['items'][0], 'La misión tiene que guardar `pedido_id`.');
        $this->assertSame('20261126-0930-precios', $misiones['items'][0]['pedido_id']);

        $cola = $this->guardado_v2('local_pc_cola');
        $this->assertArrayHasKey('pedido_id', $cola['en_espera'][0], 'La cola tiene que guardar `pedido_id`.');
        $this->assertSame('20261126-0930-precios', $cola['en_espera'][0]['pedido_id']);
    }

    /**
     * Un `pedido_id` con formato raro (texto libre) no da 422: se guarda `null` y su contenido no queda en ninguna
     * clave `local_pc_*`; de control, un reporte sin `pedido_id` sigue entrando.
     *
     * @return void
     */
    public function test_pedido_id_raro_se_descarta_sin_422(): void
    {
        $reporte = $this->reporte_v2(['cola' => $this->cola_con_pedido('padre SECRETO-PRIV')]);
        $reporte['misiones'][0]['pedido_id'] = 'padre SECRETO-PRIV';

        $this->enviar_v2($reporte)->assertStatus(200);

        $misiones = $this->guardado_v2('local_pc_misiones');
        $this->assertArrayHasKey('pedido_id', $misiones['items'][0], 'La misión tiene que guardar la clave, con null.');
        $this->assertNull($misiones['items'][0]['pedido_id']);

        $cola = $this->guardado_v2('local_pc_cola');
        $this->assertArrayHasKey('pedido_id', $cola['en_espera'][0], 'La cola tiene que guardar la clave, con null.');
        $this->assertNull($cola['en_espera'][0]['pedido_id']);

        $crudo = DB::table('admin_settings')->where('key', 'like', 'local_pc_%')->pluck('value')->implode(' ');
        $this->assertStringNotContainsString('SECRETO-PRIV', $crudo, 'El texto libre del pedido no puede guardarse.');

        // Control: un reporte sin pedido_id sigue siendo válido.
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
    }
}

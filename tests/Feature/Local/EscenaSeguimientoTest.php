<?php

namespace Tests\Feature\Local;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Missions\MissionFixture;
use Tests\TestCase;

/**
 * CA12 de la misión dotin-misiones-admin: la escena de El local (`GET api/admin/local/escena`) agrega el bloque
 * `seguimiento` (lo que publicó la Gamer en el Admin) a las misiones que tienen fila, sin tocar las demás claves
 * ni las misiones que no tienen.
 *
 * Con el reloj en el 26/11/2026 12:00 ART: el reporte v2 trae `cc:uno` y `cc:dos`; el seguimiento se publica sólo
 * para `cc:uno`.
 */
class EscenaSeguimientoTest extends TestCase
{
    use DatabaseTransactions;
    use ReporteV2Fixture;
    use MissionFixture;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Primero la clave de las misiones y después la del reporte v2: `misiones_headers()` lee la vigente.
        $this->misiones_preparar();
        $this->preparar_v2();
        $this->misiones_congelar('2026-11-26 12:00:00');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->misiones_soltar_reloj();

        parent::tearDown();
    }

    /**
     * Las misiones de la oficina de la escena, indexadas por id.
     *
     * @param array<string, mixed> $escena Respuesta de la escena.
     *
     * @return array<string, array<string, mixed>>
     */
    private function misiones_de($escena)
    {
        $por_id = [];
        foreach ($escena['oficina']['misiones'] as $mision) {
            $por_id[$mision['id']] = $mision;
        }

        return $por_id;
    }

    /**
     * La misión con fila trae `seguimiento`; la otra queda exactamente como hoy (misma forma, sin la clave).
     *
     * @return void
     */
    public function test_escena_agrega_seguimiento(): void
    {
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
        $this->misiones_put('uno', [
            'estado' => 'activa', 'fase' => 'construccion', 'ultimo_avance' => 'Va por la unidad 2', 'pendiente' => 'Falta la unidad 3',
            'ultima_actividad_real_at' => $this->misiones_iso('2026-11-26 11:58:00'), 'ejecucion_n' => 1,
        ])->assertStatus(200);

        $escena   = $this->escena_v2();
        $misiones = $this->misiones_de($escena);

        $this->assertArrayHasKey('cc:uno', $misiones);
        $this->assertArrayHasKey('cc:dos', $misiones);

        $seguimiento = $misiones['cc:uno']['seguimiento'];
        $this->assertSame('admin', $seguimiento['fuente']);
        $this->assertSame('activa', $seguimiento['estado']);
        $this->assertSame('activa', $seguimiento['estado_visible']);
        $this->assertSame('al_dia', $seguimiento['vigencia']);
        $this->assertSame('construccion', $seguimiento['fase']);
        $this->assertSame('Va por la unidad 2', $seguimiento['ultimo_avance']);
        $this->assertSame('Falta la unidad 3', $seguimiento['pendiente']);
        $this->assertSame(0, $seguimiento['minutos_sin_reporte']);
        $this->assertNull($seguimiento['pregunta']);

        // La que no tiene fila no cambia en nada: sin la clave.
        $this->assertArrayNotHasKey('seguimiento', $misiones['cc:dos']);

        // Las demás claves de la misión con seguimiento son las mismas que las de una sin él.
        $con = $misiones['cc:uno'];
        unset($con['seguimiento']);
        $this->assertSame(array_keys($misiones['cc:dos']), array_keys($con));
    }

    /**
     * Una pregunta abierta sale en la escena con sus opciones, su impacto y si se puede responder.
     *
     * @return void
     */
    public function test_la_pregunta_abierta_sale_en_la_escena(): void
    {
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
        $uid = $this->misiones_con_pregunta('uno');
        $this->misiones_put('uno', ['estado' => 'esperando_decision', 'ejecucion_n' => 1, 'pregunta_abierta' => ['question_uid' => $uid, 'revision' => 1]])->assertStatus(200);

        $pregunta = $this->misiones_de($this->escena_v2())['cc:uno']['seguimiento']['pregunta'];

        $this->assertSame($uid, $pregunta['question_uid']);
        $this->assertSame(1, $pregunta['revision']);
        $this->assertSame('ordinaria', $pregunta['tipo']);
        $this->assertSame('abierta', $pregunta['estado']);
        $this->assertTrue($pregunta['puede_responder']);
        $this->assertSame('A', $pregunta['recomendada']);
        $this->assertCount(2, $pregunta['opciones']);
        $this->assertSame(['clave', 'texto', 'impacto'], array_keys($pregunta['opciones'][0]));
        $this->assertNull($pregunta['respuesta']);
    }

    /**
     * Publicar un estado nuevo borra la caché de la escena: la vista no muestra el estado viejo 20 segundos.
     *
     * @return void
     */
    public function test_el_put_borra_la_cache_de_la_escena(): void
    {
        $this->enviar_v2($this->reporte_v2())->assertStatus(200);
        $this->misiones_put('uno', ['estado' => 'activa'])->assertStatus(200);

        $admin = Admin::create(['name' => 'Lucas', 'email' => 'escena-seg-' . uniqid() . '@test.local', 'password' => bcrypt('secret')]);
        $antes = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/local/escena')->assertStatus(200)->json();
        $this->assertSame('activa', $this->misiones_de($antes)['cc:uno']['seguimiento']['estado']);

        $this->misiones_put('uno', ['estado' => 'pausada_tecnica', 'motivo' => 'senal_9'])->assertStatus(200);

        $despues = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/local/escena')->assertStatus(200)->json();
        $this->assertSame('pausada_tecnica', $this->misiones_de($despues)['cc:uno']['seguimiento']['estado']);
    }
}

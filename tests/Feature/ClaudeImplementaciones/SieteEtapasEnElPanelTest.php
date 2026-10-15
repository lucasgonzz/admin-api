<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\Implementation;
use App\Models\ImplementationStage;
use App\Services\ImplementationConversationService;

/**
 * Lo que el panel (`/api/admin/implementation*`) sabe de que el flujo tiene SIETE etapas (misión `implementacion-7-etapas`,
 * 15/10/2026, decisión de Lucas): el tope del botón «Avanzar etapa» y del contador de implementaciones listas para avanzar es la 7
 * (`Implementation::ULTIMA_ETAPA`), y no la 8 que había hasta ese día.
 *
 * El cierre por `POST claude/implementations/{id}/advance` y el bloque `etapas` de la lectura se prueban en
 * `AvanceDeEtapaPorClaudeTest` y `EstadoDeLaImplementacionPorClaudeTest`; el cierre del propio panel, en
 * `PanelEtapa8SinVideollamada\AperturaDeLaEtapa8DelPanelTest`.
 */
class SieteEtapasEnElPanelTest extends BaseDeImplementaciones
{
    /**
     * Una implementación en la etapa pedida con esa etapa YA completada (lo que el panel llama «lista para avanzar»).
     *
     * @param string $nombre   Nombre del cliente.
     * @param int    $etapa    Etapa en la que está.
     * @param bool   $anterior true = con la fila 8, como las implementaciones anteriores al 15/10/2026.
     *
     * @return Implementation
     */
    private function lista_para_avanzar(string $nombre, int $etapa, bool $anterior = false): Implementation
    {
        $cliente = $this->crear_cliente($nombre);
        $impl    = $anterior ? $this->crear_implementacion_anterior($cliente) : $this->crear_implementacion($cliente);
        $impl    = $this->llevar_a_la_etapa($impl, $etapa);

        ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', $etapa)->update(['status' => 'completed']);

        return $impl->refresh();
    }

    /**
     * El listado del panel, indexado por id de implementación.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listado_del_panel(): array
    {
        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->getJson('/api/admin/implementation');
        $respuesta->assertStatus(200);

        $por_id = [];
        foreach ($respuesta->json('models') as $modelo) {
            $por_id[(int) $modelo['id']] = $modelo;
        }

        return $por_id;
    }

    /**
     * `ready_to_advance` vale mientras haya una etapa por delante: la 6 completada está lista para avanzar a la 7; la 7 completada NO
     * (es la última: cierra por `claude/*`); y una implementación anterior que quedó en la 8 tampoco.
     *
     * @return void
     */
    public function test_ready_to_advance_llega_hasta_la_etapa_6(): void
    {
        $en_la_6  = $this->lista_para_avanzar('Cliente Seis', 6);
        $en_la_7  = $this->lista_para_avanzar('Cliente Siete', 7);
        $en_la_8  = $this->lista_para_avanzar('Cliente Ocho', 8, true);
        $en_la_3  = $this->lista_para_avanzar('Cliente Tres', 3);

        $listado = $this->listado_del_panel();

        $this->assertTrue((bool) $listado[$en_la_6->id]['ready_to_advance'], 'La 6 completada puede avanzar a la 7.');
        $this->assertTrue((bool) $listado[$en_la_3->id]['ready_to_advance']);
        $this->assertFalse((bool) $listado[$en_la_7->id]['ready_to_advance'], 'La 7 es la última: no hay a dónde avanzar.');
        $this->assertFalse((bool) $listado[$en_la_8->id]['ready_to_advance'], 'Una implementación vieja que quedó en la 8 tampoco avanza.');
    }

    /**
     * El contador del badge del menú cuenta las `in_progress` con la etapa actual completada y `current_stage` por debajo de la última:
     * la de la etapa 7 ya no suma.
     *
     * @return void
     */
    public function test_el_contador_de_listas_para_avanzar_no_cuenta_la_etapa_7(): void
    {
        $contar = function () {
            $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')->getJson('/api/admin/implementation/ready-to-advance-count');
            $respuesta->assertStatus(200);

            return (int) $respuesta->json('count');
        };

        $antes = $contar();

        $this->lista_para_avanzar('Cliente Seis', 6);
        $this->assertSame($antes + 1, $contar(), 'Una en la 6 completada suma.');

        $this->lista_para_avanzar('Cliente Siete', 7);
        $this->lista_para_avanzar('Cliente Ocho', 8, true);
        $this->assertSame($antes + 1, $contar(), 'Una en la 7 y una vieja en la 8 no suman: no hay etapa siguiente.');
    }

    /**
     * Avanzar de la 6 a la 7 por el panel sigue funcionando como siempre: la 6 queda completada, la 7 en curso y `current_stage` en 7
     * (la implementación NO se cierra).
     *
     * @return void
     */
    public function test_avanzar_de_la_6_a_la_7_por_el_panel_abre_la_7(): void
    {
        $impl = $this->lista_para_avanzar('Cliente Seis', 6);

        $this->actingAs($this->crear_admin(), 'sanctum')
            ->postJson('/api/admin/implementation/' . $impl->id . '/advance-stage')
            ->assertStatus(200);

        $impl->refresh();
        $this->assertSame('in_progress', $impl->status);
        $this->assertSame(7, (int) $impl->current_stage);
        $this->assertSame('completed', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 6)->value('status'));
        $this->assertSame('in_progress', ImplementationStage::where('implementation_id', $impl->id)->where('stage_number', 7)->value('status'));
    }

    /**
     * La constante única: el flujo tiene siete etapas, y la 8 es solo la heredada de las implementaciones anteriores. Si alguien cambia
     * el número, este test obliga a mirar todo lo que lo lee.
     *
     * @return void
     */
    public function test_la_constante_unica_dice_siete(): void
    {
        $this->assertSame(7, Implementation::ULTIMA_ETAPA);
        $this->assertSame(8, Implementation::ETAPA_HEREDADA);
    }

    /**
     * La lista de progreso que el servicio de conversación le muestra al cliente tiene tantas líneas como etapas tiene el flujo.
     *
     * @return void
     */
    public function test_la_lista_de_progreso_tiene_una_linea_por_etapa_del_flujo(): void
    {
        $impl = $this->lista_para_avanzar('Cliente Tres', 3);

        $cuerpo = (new ImplementationConversationService())->build_progress_body($impl, 3);

        $this->assertSame(Implementation::ULTIMA_ETAPA, preg_match_all('/^(✅|⬜) \d+\. /mu', $cuerpo));
    }
}

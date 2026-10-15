<?php

namespace Tests\Feature\PanelEtapa8SinVideollamada;

/**
 * Los textos que el panel le arma al cliente por WhatsApp: ninguno nombra la videollamada, la lista de progreso llama «Seguimos con soporte»
 * a la etapa 8, y el pedido de archivos dice que ESTAMOS instalando (no que quedó instalado).
 *
 * Camino real del panel: `GET /api/admin/implementation/{id}/actions/{accion}/preview` → `ImplementationActionService::preview()` →
 * `ImplementationConversationService::build_*_body()`. Los botones «Progreso», «Pedido de archivos» y «Entrega del sistema» corren en modo
 * MANUAL, que es el modo de las cinco implementaciones de producción (14/10/2026): este es el único camino por donde la videollamada
 * le llegaba hoy al cliente.
 */
class TextosDelPanelSinVideollamadaTest extends BaseDeLaEtapa8DelPanel
{
    /** Las otras siete etapas, tal cual las ve el cliente (no cambiaron). */
    const LISTA_DE_PROGRESO_SIN_LA_8 = [
        '1. Información de la empresa',
        '2. Instalación del sistema',
        '3. Recolección de archivos',
        '4. Migración de datos',
        '5. Entrega del sistema',
        '6. Capacitación',
        '7. Vinculación con ARCA/AFIP',
    ];

    /**
     * El cuerpo que el panel mostraría para una acción.
     *
     * @param \App\Models\Implementation $implementacion La implementación.
     * @param string                     $accion         `progreso`, `pedir_archivos` o `entrega`.
     * @param string                     $consulta       Query string opcional (`?stage=8`).
     *
     * @return string
     */
    private function cuerpo_del_preview($implementacion, string $accion, string $consulta = ''): string
    {
        $respuesta = $this->actingAs($this->crear_admin(), 'sanctum')
            ->getJson('/api/admin/implementation/' . $implementacion->id . '/actions/' . $accion . '/preview' . $consulta);

        $respuesta->assertStatus(200);

        return (string) $respuesta->json('body');
    }

    /**
     * (a) 🔴 El botón «Progreso» (en la etapa 8 y en cualquier otra): la lista termina en «8. Seguimos con soporte», con las otras siete
     * como siempre, y no nombra la videollamada.
     *
     * @return void
     */
    public function test_el_boton_progreso_llama_seguimos_con_soporte_a_la_etapa_8(): void
    {
        foreach ([3, 8] as $etapa) {
            $implementacion = $this->implementacion_en($etapa, 'manual');
            $cuerpo         = $this->cuerpo_del_preview($implementacion, 'progreso', '?stage=' . $etapa);

            $this->assertStringContainsString('8. Seguimos con soporte', $cuerpo, 'etapa ' . $etapa);
            $this->assertStringNotContainsStringIgnoringCase('videollamada', $cuerpo, 'etapa ' . $etapa);

            foreach (self::LISTA_DE_PROGRESO_SIN_LA_8 as $linea) {
                $this->assertStringContainsString($linea, $cuerpo, 'etapa ' . $etapa);
            }
        }
    }

    /**
     * (b) 🔴 El botón «Pedido de archivos»: «ya estamos instalando», nunca «ya está instalado» (el sistema se le dice instalado recién en la
     * entrega); lleva la lista de progreso nueva, sigue pidiendo los archivos de siempre y no nombra la videollamada.
     *
     * @return void
     */
    public function test_el_pedido_de_archivos_dice_que_estamos_instalando(): void
    {
        $implementacion = $this->implementacion_en(3, 'manual');
        $cuerpo         = $this->cuerpo_del_preview($implementacion, 'pedir_archivos');

        $this->assertStringContainsString('ya estamos instalando el sistema con la configuración de Panchito S.A.', $cuerpo);
        $this->assertStringContainsString('Para ir ganando tiempo, necesito que nos envíes los archivos con la información del negocio:', $cuerpo);
        $this->assertStringNotContainsStringIgnoringCase('ya está instalado', $cuerpo);
        $this->assertStringNotContainsStringIgnoringCase('videollamada', $cuerpo);
        $this->assertStringContainsString('8. Seguimos con soporte', $cuerpo);

        // Lo que se pide no cambió.
        foreach (['Artículos o productos (Excel)', 'Clientes (Excel)', 'Proveedores (Excel)', 'Logo de la empresa (imagen cuadrada)'] as $pedido) {
            $this->assertStringContainsString($pedido, $cuerpo);
        }
    }

    /**
     * (c) El botón «Pedido de archivos» saluda con el responsable de migración cuando el formulario lo trae, y con «Hola» cuando no.
     *
     * @return void
     */
    public function test_el_pedido_de_archivos_saluda_al_responsable_o_dice_hola(): void
    {
        $implementacion = $this->implementacion_en(3, 'manual');
        $this->assertStringContainsString('Hola, ¡ya estamos instalando', $this->cuerpo_del_preview($implementacion, 'pedir_archivos'));

        $this->escribir_data_de_la_etapa($implementacion, 1, ['migration_responsible_name' => 'Marta']);
        $this->assertStringContainsString('Marta, ¡ya estamos instalando', $this->cuerpo_del_preview($implementacion, 'pedir_archivos'));
    }

    /**
     * (d) El botón «Entrega del sistema»: lleva la lista de progreso con la 8 renombrada y no nombra la videollamada.
     *
     * @return void
     */
    public function test_la_entrega_no_nombra_la_videollamada(): void
    {
        $implementacion = $this->implementacion_en(5, 'manual');
        $cuerpo         = $this->cuerpo_del_preview($implementacion, 'entrega');

        $this->assertStringContainsString('8. Seguimos con soporte', $cuerpo);
        $this->assertStringNotContainsStringIgnoringCase('videollamada', $cuerpo);
    }

    /**
     * (e) 🔴 Los textos que arma el servicio por su cuenta en modo auto (la confirmación del formulario con su lista, la apertura de la etapa 5
     * con la lista, el pedido de archivos de la etapa 3) tampoco nombran la videollamada: salen de los mismos builders.
     *
     * @return void
     */
    public function test_los_mensajes_automaticos_con_lista_de_progreso_tampoco_la_nombran(): void
    {
        $servicio = $this->servicio();

        foreach ([1, 3, 5, 8] as $etapa) {
            $implementacion = $this->implementacion_en($etapa, 'auto');

            $this->assertStringNotContainsStringIgnoringCase('videollamada', $servicio->build_progress_body($implementacion, $etapa), 'progreso, etapa ' . $etapa);
        }

        $implementacion = $this->implementacion_en(3, 'auto');
        $this->assertStringNotContainsStringIgnoringCase('videollamada', $servicio->build_files_request_body($implementacion));
        $this->assertStringNotContainsStringIgnoringCase('videollamada', $servicio->build_delivery_body($implementacion));
    }

    /**
     * (f) La lista marca con ✅ las etapas completadas y con ⬜ las demás, y la 8 sigue la misma regla que las otras (el cambio es solo del
     * nombre).
     *
     * @return void
     */
    public function test_la_etapa_8_se_marca_como_las_demas(): void
    {
        $servicio = $this->servicio();

        $en_curso = $this->implementacion_en(8, 'auto');
        $this->assertStringContainsString('⬜ 8. Seguimos con soporte', $servicio->build_progress_body($en_curso, 8));
        $this->assertStringContainsString('✅ 7. Vinculación con ARCA/AFIP', $servicio->build_progress_body($en_curso, 8));

        $terminada = $this->implementacion_en(8, 'auto');
        \App\Models\ImplementationStage::where('implementation_id', $terminada->id)->where('stage_number', 8)->update(['status' => 'completed']);
        $this->assertStringContainsString('✅ 8. Seguimos con soporte', $servicio->build_progress_body($terminada->refresh(), 8));
    }
}

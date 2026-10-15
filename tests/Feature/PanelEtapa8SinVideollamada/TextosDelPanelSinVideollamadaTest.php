<?php

namespace Tests\Feature\PanelEtapa8SinVideollamada;

/**
 * Los textos que el panel le arma al cliente por WhatsApp: ninguno nombra la videollamada, la lista de progreso tiene SIETE etapas y el pedido
 * de archivos dice que ESTAMOS instalando (no que quedó instalado).
 *
 * 🔴 La lista de progreso terminaba en «8. Seguimos con soporte» hasta el 15/10/2026. Ese día el flujo pasó a siete etapas (decisión de Lucas): la 6
 * es «Catálogo: categorías, imágenes y descripciones» (antes «Capacitación») y la 8 salió. Las aserciones de la lista cambiaron por eso: ahora
 * afirman que la lista tiene esas siete líneas y NO tiene ninguna octava. El resto de lo que protege el archivo (la videollamada no se nombra, el
 * pedido de archivos dice «ya estamos instalando») no cambió.
 *
 * Camino real del panel: `GET /api/admin/implementation/{id}/actions/{accion}/preview` → `ImplementationActionService::preview()` →
 * `ImplementationConversationService::build_*_body()`. Los botones «Progreso», «Pedido de archivos» y «Entrega del sistema» corren en modo
 * MANUAL, que es el modo de las cinco implementaciones de producción (14/10/2026): este es el único camino por donde la videollamada
 * le llegaba hoy al cliente.
 */
class TextosDelPanelSinVideollamadaTest extends BaseDeLaEtapa8DelPanel
{
    /** Las siete etapas, tal cual las ve el cliente en la lista de progreso. */
    const LISTA_DE_PROGRESO = [
        '1. Información de la empresa',
        '2. Instalación del sistema',
        '3. Recolección de archivos',
        '4. Migración de datos',
        '5. Entrega del sistema',
        '6. Catálogo: categorías, imágenes y descripciones',
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
     * (a) 🔴 El botón «Progreso» (en la etapa 7 y en cualquier otra, y también en una implementación anterior que quedó en la 8): la lista tiene las
     * siete etapas y TERMINA en la 7: no hay octava línea, ni «Seguimos con soporte» ni «Capacitación», y no nombra la videollamada.
     *
     * @return void
     */
    public function test_el_boton_progreso_muestra_las_siete_etapas_y_ninguna_octava(): void
    {
        foreach ([3, 7, 8] as $etapa) {
            $implementacion = $this->implementacion_en($etapa, 'manual');
            $cuerpo         = $this->cuerpo_del_preview($implementacion, 'progreso', '?stage=' . $etapa);

            foreach (self::LISTA_DE_PROGRESO as $linea) {
                $this->assertStringContainsString($linea, $cuerpo, 'etapa ' . $etapa);
            }

            $this->assertStringNotContainsString('8. ', $cuerpo, 'etapa ' . $etapa . ': la lista no tiene octava línea.');
            $this->assertStringNotContainsString('Seguimos con soporte', $cuerpo, 'etapa ' . $etapa);
            $this->assertStringNotContainsString('6. Capacitación', $cuerpo, 'etapa ' . $etapa);
            $this->assertStringNotContainsStringIgnoringCase('videollamada', $cuerpo, 'etapa ' . $etapa);
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
        $this->assertStringContainsString('7. Vinculación con ARCA/AFIP', $cuerpo);
        $this->assertStringNotContainsString('8. ', $cuerpo, 'La lista de progreso no tiene octava línea.');

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
     * (d) El botón «Entrega del sistema»: lleva la lista de progreso de siete etapas (sin octava) y no nombra la videollamada.
     *
     * @return void
     */
    public function test_la_entrega_no_nombra_la_videollamada(): void
    {
        $implementacion = $this->implementacion_en(5, 'manual');
        $cuerpo         = $this->cuerpo_del_preview($implementacion, 'entrega');

        $this->assertStringContainsString('6. Catálogo: categorías, imágenes y descripciones', $cuerpo);
        $this->assertStringContainsString('7. Vinculación con ARCA/AFIP', $cuerpo);
        $this->assertStringNotContainsString('8. ', $cuerpo, 'La lista de progreso no tiene octava línea.');
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
     * (f) La lista marca con ✅ las etapas completadas y con ⬜ las demás, y la 7 —la última— sigue la misma regla que las otras. Hasta el
     * 15/10/2026 este test miraba la 8 («Seguimos con soporte»).
     *
     * @return void
     */
    public function test_la_etapa_7_se_marca_como_las_demas(): void
    {
        $servicio = $this->servicio();

        $en_curso = $this->implementacion_en(7, 'auto');
        $this->assertStringContainsString('⬜ 7. Vinculación con ARCA/AFIP', $servicio->build_progress_body($en_curso, 7));
        $this->assertStringContainsString('✅ 6. Catálogo: categorías, imágenes y descripciones', $servicio->build_progress_body($en_curso, 7));

        $terminada = $this->implementacion_en(7, 'auto');
        \App\Models\ImplementationStage::where('implementation_id', $terminada->id)->where('stage_number', 7)->update(['status' => 'completed']);
        $this->assertStringContainsString('✅ 7. Vinculación con ARCA/AFIP', $servicio->build_progress_body($terminada->refresh(), 7));
    }

    /**
     * (g) COMPATIBILIDAD. La lista de una implementación ANTERIOR (con su fila 8 completada) sale igual con las siete líneas: la 8 que tiene
     * en la base no se dibuja.
     *
     * @return void
     */
    public function test_la_lista_de_una_implementacion_vieja_con_la_etapa_8_completada_tiene_siete_lineas(): void
    {
        $servicio = $this->servicio();

        $vieja = $this->implementacion_en(8, 'auto');
        \App\Models\ImplementationStage::where('implementation_id', $vieja->id)->where('stage_number', 8)->update(['status' => 'completed']);

        $cuerpo = $servicio->build_progress_body($vieja->refresh(), 8);

        foreach (self::LISTA_DE_PROGRESO as $linea) {
            $this->assertStringContainsString($linea, $cuerpo);
        }

        $this->assertStringNotContainsString('8. ', $cuerpo);
        $this->assertSame(7, substr_count($cuerpo, '✅'), 'Las siete etapas están cerradas y la 8 no se dibuja.');
    }
}

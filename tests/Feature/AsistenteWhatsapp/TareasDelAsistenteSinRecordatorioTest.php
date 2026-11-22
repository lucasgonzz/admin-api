<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Models\AdminSetting;
use App\Models\ClientTemplate;
use App\Services\AsistenteRecordatoriosService;
use Illuminate\Support\Facades\Http;

/**
 * Tareas del asistente cuando la plantilla de recordatorios NO está disponible.
 *
 * Decisión del Coordinador (21/11/2026) para un hueco del plan: ítem de tarea, ventana cerrada, plantilla
 * de tareas configurada y activa, pero sin plantilla de recordatorios: la tarea sale igual por la de
 * tareas (no se corta con `sin_plantilla`). Un ítem viejo en esa misma situación sigue sin salir.
 */
class TareasDelAsistenteSinRecordatorioTest extends BaseDelCanal
{
    /**
     * Fakea la consulta de pendientes y la marca con los ítems dados.
     *
     * @param array<int, array<string, mixed>> $items Ítems de `recordatorios-pendientes`.
     *
     * @return void
     */
    private function fakear_empresa(array $items): void
    {
        $this->fakear_http([
            '*/asistente/recordatorios-pendientes*' => Http::response(['recordatorios' => $items], 200),
            '*/asistente/recordatorios/avisado'     => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * Ítem con la forma del contrato.
     *
     * @param string $id            Id del ítem.
     * @param bool   $con_campos    Si trae `plantilla` y `resumen`.
     *
     * @return array<string, mixed>
     */
    private function item(string $id, bool $con_campos): array
    {
        $item = [
            'id'                 => $id,
            'tarea_id'           => null,
            'fecha'              => now()->toDateString(),
            'hora'               => '17:00',
            'detalle'            => 'que hoy te escriba por «Preguntas de logística»',
            'texto'              => 'Resultado del turno.',
            'ai_conversation_id' => 88,
            'ruta'               => '',
        ];

        if ($con_campos) {
            $item['plantilla'] = 'tarea_del_asistente';
            $item['resumen']   = '«Preguntas de logística»';
        }

        return $item;
    }

    /**
     * Crea la plantilla de tareas activa y su nombre en `AdminSetting`.
     *
     * @return void
     */
    private function cargar_plantilla_de_tareas(): void
    {
        AdminSetting::set('asistente_tarea_template_name', 'cc_tarea_del_asistente');

        $plantilla                = new ClientTemplate();
        $plantilla->template_name = 'cc_tarea_del_asistente';
        $plantilla->language_code = 'es_AR';
        $plantilla->categoria     = 'recordatorios';
        $plantilla->titulo        = 'Plantilla de tareas';
        $plantilla->body_template = 'Te escribo por: {{1}}';
        $plantilla->activa        = true;
        $plantilla->save();
    }

    /**
     * La tarea sale por la plantilla de tareas aunque no haya plantilla de recordatorios.
     *
     * @return void
     */
    public function test_la_tarea_sale_por_su_plantilla_sin_plantilla_de_recordatorio(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_tareas();
        $this->fakear_empresa([$this->item('recordatorio:tarea_asistente:51-1', true)]);

        $resultado = app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertStringContainsString('recordatorio:tarea_asistente:51-1=enviado_plantilla', $resultado['detalle']);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_tarea_del_asistente', $espia->plantillas[0]['template_name']);
        $this->assertSame(['«Preguntas de logística» (hoy 17:00)'], $espia->plantillas[0]['variables']);
    }

    /**
     * Un ítem sin los campos nuevos, con sólo la plantilla de tareas cargada, sigue sin salir.
     *
     * @return void
     */
    public function test_un_item_viejo_sin_plantilla_de_recordatorio_sigue_sin_salir(): void
    {
        $espia  = $this->espiar_sender();
        $this->crear_config_whatsapp();
        $client = $this->crear_cliente();
        $this->cargar_plantilla_de_tareas();
        $this->fakear_empresa([$this->item('recordatorio:57-1:2026-11-21:1700', false)]);

        $resultado = app(AsistenteRecordatoriosService::class)->enviar_a($client);

        $this->assertSame('pendiente', $resultado['estado']);
        $this->assertStringContainsString('=sin_plantilla', $resultado['detalle']);
        $this->assertCount(0, $espia->plantillas);
        $this->assertCount(0, $espia->textos);
    }
}

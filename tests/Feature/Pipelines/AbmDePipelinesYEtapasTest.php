<?php

namespace Tests\Feature\Pipelines;

use App\Models\Pipeline;
use App\Models\PipelineStage;

/**
 * El ABM de pipelines y etapas del CRM (misión pipelines-crm, 27/9/2026): endpoints #1–9.
 *
 * Lo que se protege, en orden de importancia:
 *  1. 🔴 Que el `type` de una etapa no cambie con oportunidades adentro: el estado de cada
 *     oportunidad SALE de su etapa, y cambiarlo cerraría o reabriría gente que nadie movió.
 *  2. 🔴 Que un pipeline no quede nunca sin etapa abierta (ni al crear, ni al editar, ni al borrar).
 *  3. Que la definición de campos se valide en el back (la SPA no decide qué es válido) y que las
 *     claves generadas sean únicas y estables.
 *  4. Que los 422 lleguen con mensaje en español (el toast de la SPA muestra `message` tal cual).
 */
class AbmDePipelinesYEtapasTest extends BaseDePipelines
{
    /**
     * 1. Las enumeraciones las publica el back, con su etiqueta.
     *
     * @return void
     */
    public function test_meta_publica_las_enumeraciones_con_su_etiqueta(): void
    {
        $this->admin_logueado();

        $respuesta = $this->getJson('/api/admin/pipelines/meta');

        $respuesta->assertStatus(200);
        $this->assertSame([
            ['value' => 'open', 'label' => 'Abierta'],
            ['value' => 'won', 'label' => 'Ganada'],
            ['value' => 'lost', 'label' => 'Perdida'],
        ], $respuesta->json('stage_types'));

        $tipos_de_campo = array_column($respuesta->json('field_types'), 'value');
        $this->assertSame(['text', 'textarea', 'date', 'datetime', 'select', 'number', 'boolean'], $tipos_de_campo);

        $this->assertSame(['call', 'whatsapp', 'meeting', 'email', 'in_person', 'other'], array_column($respuesta->json('channels'), 'value'));
        $this->assertSame(['created', 'stage_change', 'note', 'next_action', 'owner'], array_column($respuesta->json('activity_types'), 'value'));
        $this->assertContains(['value' => 'datetime', 'label' => 'Fecha y hora'], $respuesta->json('field_types'));
    }

    /**
     * 2. Sin etapas, un pipeline nace con "Por contactar" / "Ganado" / "Perdido"; los motivos de
     *    pérdida se guardan recortados, sin vacíos ni repetidos.
     *
     * @return void
     */
    public function test_crear_sin_etapas_nace_con_las_tres_por_defecto(): void
    {
        $this->admin_logueado();

        $respuesta = $this->postJson('/api/admin/pipelines', [
            'name'         => 'Campaña de reactivación',
            'description'  => 'Clientes que dejaron de usar el sistema.',
            'lost_reasons' => ['Precio', ' Precio ', '', 'Otro'],
        ]);

        $respuesta->assertStatus(201);

        $pipeline = $respuesta->json('pipeline');
        $this->assertSame('Campaña de reactivación', $pipeline['name']);
        $this->assertSame(['Precio', 'Otro'], $pipeline['lost_reasons']);
        $this->assertNull($pipeline['archived_at']);
        $this->assertSame(['open' => 0, 'won' => 0, 'lost' => 0, 'total' => 0], $pipeline['counts']);

        $this->assertSame(['Por contactar', 'Ganado', 'Perdido'], array_column($pipeline['stages'], 'name'));
        $this->assertSame(['open', 'won', 'lost'], array_column($pipeline['stages'], 'type'));
        $this->assertSame([0, 1, 2], array_column($pipeline['stages'], 'sort_order'));
        $this->assertSame([], $pipeline['stages'][0]['fields']);
        $this->assertSame(0, $pipeline['stages'][0]['opportunities_count']);

        /* Y aparece en el listado. */
        $ids = array_column($this->getJson('/api/admin/pipelines')->assertStatus(200)->json('pipelines'), 'id');
        $this->assertContains($pipeline['id'], $ids);
    }

    /**
     * 3. Con etapas: respeta el orden, pone `open` por defecto, pasa el color a minúsculas y
     *    normaliza la definición de campos (claves generadas, booleanos, opciones).
     *
     * @return void
     */
    public function test_crear_con_etapas_respeta_el_orden_y_normaliza_los_campos(): void
    {
        $this->admin_logueado();

        $respuesta = $this->postJson('/api/admin/pipelines', [
            'name'   => 'Campaña con etapas',
            'stages' => [
                ['name' => 'Nuevo', 'color' => '#FF0000', 'fields' => [
                    ['label' => 'Fecha y hora', 'type' => 'datetime', 'required' => true, 'agenda' => true],
                    ['label' => 'Canal', 'type' => 'select', 'options' => ['A', 'B']],
                ]],
                ['name' => 'Listo', 'type' => 'won'],
            ],
        ]);

        $respuesta->assertStatus(201);

        $etapas = $respuesta->json('pipeline.stages');
        $this->assertSame(['Nuevo', 'Listo'], array_column($etapas, 'name'));
        $this->assertSame(['open', 'won'], array_column($etapas, 'type'));
        $this->assertSame('#ff0000', $etapas[0]['color']);
        $this->assertSame(PipelineStage::DEFAULT_COLOR, $etapas[1]['color']);

        $this->assertSame([
            ['key' => 'fecha_y_hora', 'label' => 'Fecha y hora', 'type' => 'datetime', 'required' => true, 'agenda' => true, 'options' => []],
            ['key' => 'canal', 'label' => 'Canal', 'type' => 'select', 'required' => false, 'agenda' => false, 'options' => ['A', 'B']],
        ], $etapas[0]['fields']);
    }

    /**
     * 4. Con etapas y ninguna abierta: 422 (no habría dónde dar de alta).
     *
     * @return void
     */
    public function test_crear_con_etapas_sin_ninguna_abierta_da_422(): void
    {
        $this->admin_logueado();

        $antes = Pipeline::query()->count();

        $respuesta = $this->postJson('/api/admin/pipelines', [
            'name'   => 'Solo cierres',
            'stages' => [['name' => 'Ganado', 'type' => 'won']],
        ]);

        $respuesta->assertStatus(422);
        $this->assertArrayHasKey('stages', $respuesta->json('errors'));
        $this->assertSame($antes, Pipeline::query()->count(), 'Un 422 no puede dejar un pipeline creado a medias.');
    }

    /**
     * 5. Editar nombre, archivar (sale del listado salvo `include_archived=1`) y desarchivar.
     *
     * @return void
     */
    public function test_editar_archivar_y_desarchivar(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();

        $respuesta = $this->putJson('/api/admin/pipelines/' . $pipeline->id, [
            'name'     => 'Nombre nuevo',
            'archived' => true,
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame('Nombre nuevo', $respuesta->json('pipeline.name'));
        $this->assertSame('2026-09-27 10:00:00', $respuesta->json('pipeline.archived_at'));

        $activos = array_column($this->getJson('/api/admin/pipelines')->json('pipelines'), 'id');
        $this->assertNotContains($pipeline->id, $activos, 'Un pipeline archivado no aparece en el selector.');

        $todos = array_column($this->getJson('/api/admin/pipelines?include_archived=1')->json('pipelines'), 'id');
        $this->assertContains($pipeline->id, $todos);

        $this->putJson('/api/admin/pipelines/' . $pipeline->id, ['archived' => false])
            ->assertStatus(200)
            ->assertJsonPath('pipeline.archived_at', null);
    }

    /**
     * 6. Borrar: un pipeline vacío se borra (con sus etapas); uno con oportunidades da 422 y pide
     *    archivarlo.
     *
     * @return void
     */
    public function test_borrar_vacio_y_422_con_oportunidades(): void
    {
        $this->admin_logueado();

        $vacio = $this->crear_pipeline();
        $this->deleteJson('/api/admin/pipelines/' . $vacio->id)->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertNull(Pipeline::query()->find($vacio->id));
        $this->assertSame(0, PipelineStage::query()->where('pipeline_id', $vacio->id)->count());

        $con_gente = $this->crear_pipeline();
        $this->alta_de_uno($con_gente, $this->crear_cliente());

        $respuesta = $this->deleteJson('/api/admin/pipelines/' . $con_gente->id);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('archivalo', $respuesta->json('message'));
        $this->assertNotNull(Pipeline::query()->find($con_gente->id));
    }

    /**
     * 7. Orden de etapas: exactamente todas, cada una una vez; si no, 422.
     *
     * @return void
     */
    public function test_orden_de_etapas_y_422_con_conjunto_incompleto(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $otro     = $this->crear_pipeline();

        $ids       = $pipeline->stages->pluck('id')->all();
        $invertido = array_reverse($ids);

        $respuesta = $this->putJson('/api/admin/pipelines/' . $pipeline->id . '/stage-order', ['stage_ids' => $invertido]);
        $respuesta->assertStatus(200);
        $this->assertSame($invertido, array_column($respuesta->json('pipeline.stages'), 'id'));
        $this->assertSame(range(0, count($ids) - 1), array_column($respuesta->json('pipeline.stages'), 'sort_order'));

        $incompleto = array_slice($ids, 1);
        $this->putJson('/api/admin/pipelines/' . $pipeline->id . '/stage-order', ['stage_ids' => $incompleto])->assertStatus(422);

        $repetido = $ids;
        $repetido[0] = $ids[1];
        $this->putJson('/api/admin/pipelines/' . $pipeline->id . '/stage-order', ['stage_ids' => $repetido])->assertStatus(422);

        $ajeno = $ids;
        $ajeno[0] = $otro->stages->first()->id;
        $this->putJson('/api/admin/pipelines/' . $pipeline->id . '/stage-order', ['stage_ids' => $ajeno])->assertStatus(422);

        $vacio = $this->putJson('/api/admin/pipelines/' . $pipeline->id . '/stage-order', ['stage_ids' => []]);
        $vacio->assertStatus(422);
        $this->assertArrayHasKey('stage_ids', $vacio->json('errors'));

        /* El orden bueno quedó: los 422 no tocaron nada. */
        $this->assertSame($invertido, PipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('sort_order')->pluck('id')->all());
    }

    /**
     * 8. Una etapa abierta nueva va antes de la primera cerrada; una perdida, al final.
     *
     * @return void
     */
    public function test_etapa_abierta_nueva_va_antes_de_las_cerradas(): void
    {
        $this->admin_logueado();

        $pipeline_id = $this->postJson('/api/admin/pipelines', ['name' => 'Con defaults'])->assertStatus(201)->json('pipeline.id');

        $respuesta = $this->postJson('/api/admin/pipelines/' . $pipeline_id . '/stages', ['name' => 'Interesado', 'color' => '#0d6efd']);
        $respuesta->assertStatus(201);
        $this->assertSame('open', $respuesta->json('stage.type'));
        $this->assertSame(0, $respuesta->json('stage.opportunities_count'));

        $this->postJson('/api/admin/pipelines/' . $pipeline_id . '/stages', ['name' => 'Descartado', 'type' => 'lost', 'color' => '#123456'])
            ->assertStatus(201);

        $nombres = PipelineStage::query()->where('pipeline_id', $pipeline_id)->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Por contactar', 'Interesado', 'Ganado', 'Perdido', 'Descartado'], $nombres);

        $ordenes = PipelineStage::query()->where('pipeline_id', $pipeline_id)->orderBy('sort_order')->pluck('sort_order')->all();
        $this->assertSame([0, 1, 2, 3, 4], $ordenes, 'El orden queda contiguo después de insertar en el medio.');
    }

    /**
     * 9. 🔴 Cambiar el `type` de una etapa con oportunidades adentro: 422 y no cambia nada. Sin
     *    oportunidades, se puede. Renombrar no es cambiar el tipo.
     *
     * @return void
     */
    public function test_cambiar_el_tipo_con_oportunidades_adentro_da_422(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $inicial  = $this->etapa($pipeline, 'Por contactar');

        $this->alta_de_uno($pipeline, $this->crear_cliente());

        $respuesta = $this->putJson('/api/admin/pipeline-stages/' . $inicial->id, ['type' => 'won']);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('oportunidades adentro', $respuesta->json('message'));
        $this->assertSame('open', $inicial->fresh()->type);

        /* Mismo tipo + nombre nuevo: no es un cambio de tipo. */
        $this->putJson('/api/admin/pipeline-stages/' . $inicial->id, ['type' => 'open', 'name' => 'Para llamar'])
            ->assertStatus(200)
            ->assertJsonPath('stage.name', 'Para llamar')
            ->assertJsonPath('stage.opportunities_count', 1);

        /* Una etapa vacía sí puede cambiar de tipo. */
        $vacia = $this->etapa($pipeline, 'Calificado');
        $this->putJson('/api/admin/pipeline-stages/' . $vacia->id, ['type' => 'lost'])
            ->assertStatus(200)
            ->assertJsonPath('stage.type', 'lost');
    }

    /**
     * 10. 🔴 El pipeline no se queda nunca sin etapa abierta: ni cambiando el tipo de la única
     *     abierta, ni borrándola. Una etapa con oportunidades tampoco se borra; una vacía, sí.
     *
     * @return void
     */
    public function test_no_se_puede_dejar_el_pipeline_sin_etapa_abierta_ni_borrar_una_con_gente(): void
    {
        $this->admin_logueado();

        $pipeline_id = $this->postJson('/api/admin/pipelines', ['name' => 'Con defaults'])->assertStatus(201)->json('pipeline.id');
        $unica       = PipelineStage::query()->where('pipeline_id', $pipeline_id)->where('type', 'open')->first();
        $perdido     = PipelineStage::query()->where('pipeline_id', $pipeline_id)->where('type', 'lost')->first();

        $this->putJson('/api/admin/pipeline-stages/' . $unica->id, ['type' => 'won'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Es la única etapa abierta del pipeline: un pipeline necesita al menos una etapa abierta.');

        $this->deleteJson('/api/admin/pipeline-stages/' . $unica->id)->assertStatus(422);
        $this->assertNotNull(PipelineStage::query()->find($unica->id));

        /* Una etapa vacía y cerrada se borra. */
        $this->deleteJson('/api/admin/pipeline-stages/' . $perdido->id)->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertNull(PipelineStage::query()->find($perdido->id));

        /* Una etapa con oportunidades no se borra aunque haya otras abiertas. */
        $pipeline = $this->crear_pipeline();
        $this->alta_de_uno($pipeline, $this->crear_cliente());
        $con_gente = $this->etapa($pipeline, 'Por contactar');

        $respuesta = $this->deleteJson('/api/admin/pipeline-stages/' . $con_gente->id);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('tiene oportunidades', $respuesta->json('message'));
    }

    /**
     * 11. La definición de campos se valida en el back, con el error en la clave del campo y el
     *     mensaje en español.
     *
     * @return void
     */
    public function test_validacion_de_la_definicion_de_campos(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $url      = '/api/admin/pipelines/' . $pipeline->id . '/stages';

        $tipo_invalido = $this->postJson($url, ['name' => 'X', 'fields' => [['label' => 'Color', 'type' => 'color']]]);
        $tipo_invalido->assertStatus(422);
        $this->assertArrayHasKey('fields.0.type', $tipo_invalido->json('errors'));
        $this->assertNotSame('The given data was invalid.', $tipo_invalido->json('message'));
        $this->assertStringContainsString('Color', $tipo_invalido->json('message'));

        $select_sin_opciones = $this->postJson($url, ['name' => 'X', 'fields' => [['label' => 'Canal', 'type' => 'select']]]);
        $select_sin_opciones->assertStatus(422);
        $this->assertArrayHasKey('fields.0.options', $select_sin_opciones->json('errors'));

        $agenda_en_texto = $this->postJson($url, ['name' => 'X', 'fields' => [['label' => 'Nota', 'type' => 'text', 'agenda' => true]]]);
        $agenda_en_texto->assertStatus(422);
        $this->assertArrayHasKey('fields.0.agenda', $agenda_en_texto->json('errors'));

        $dos_agenda = $this->postJson($url, ['name' => 'X', 'fields' => [
            ['label' => 'Desde', 'type' => 'date', 'agenda' => true],
            ['label' => 'Hasta', 'type' => 'datetime', 'agenda' => true],
        ]]);
        $dos_agenda->assertStatus(422);
        $this->assertArrayHasKey('fields.1.agenda', $dos_agenda->json('errors'));
        $this->assertArrayNotHasKey('fields.0.agenda', $dos_agenda->json('errors'));

        $clave_invalida = $this->postJson($url, ['name' => 'X', 'fields' => [['key' => '1mal', 'label' => 'X', 'type' => 'text']]]);
        $clave_invalida->assertStatus(422);
        $this->assertArrayHasKey('fields.0.key', $clave_invalida->json('errors'));

        $clave_repetida = $this->postJson($url, ['name' => 'X', 'fields' => [
            ['key' => 'dato', 'label' => 'Uno', 'type' => 'text'],
            ['key' => 'dato', 'label' => 'Dos', 'type' => 'text'],
        ]]);
        $clave_repetida->assertStatus(422);
        $this->assertArrayHasKey('fields.1.key', $clave_repetida->json('errors'));

        $sin_etiqueta = $this->putJson('/api/admin/pipeline-stages/' . $this->etapa($pipeline, 'Contactado')->id, [
            'fields' => [['label' => '', 'type' => 'text']],
        ]);
        $sin_etiqueta->assertStatus(422);
        $this->assertArrayHasKey('fields.0.label', $sin_etiqueta->json('errors'));

        $this->assertSame(
            count($this->etapas_de_prueba()),
            PipelineStage::query()->where('pipeline_id', $pipeline->id)->count(),
            'Ningún 422 dejó una etapa creada.'
        );
    }

    /**
     * 15. Los topes de la definición, justo en el borde y uno más: 20 campos, 30 opciones por
     *     lista, 80 caracteres por etiqueta y por opción.
     *
     * @return void
     */
    public function test_topes_de_la_definicion_de_campos(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $url      = '/api/admin/pipelines/' . $pipeline->id . '/stages';

        $veinte = [];
        for ($i = 1; $i <= 20; $i++) {
            $veinte[] = ['label' => 'Dato ' . $i, 'type' => 'text'];
        }
        $this->postJson($url, ['name' => 'Veinte campos', 'fields' => $veinte])->assertStatus(201);

        $veintiuno   = $veinte;
        $veintiuno[] = ['label' => 'Dato 21', 'type' => 'text'];
        $de_mas      = $this->postJson($url, ['name' => 'Veintiún campos', 'fields' => $veintiuno]);
        $de_mas->assertStatus(422);
        $this->assertSame(['fields' => ['Una etapa puede pedir hasta 20 campos.']], $de_mas->json('errors'));

        $treinta = [];
        for ($i = 1; $i <= 30; $i++) {
            $treinta[] = 'Opción ' . $i;
        }
        $this->postJson($url, ['name' => 'Treinta opciones', 'fields' => [['label' => 'Rubro', 'type' => 'select', 'options' => $treinta]]])
            ->assertStatus(201);

        $treinta_y_una   = $treinta;
        $treinta_y_una[] = 'Opción 31';
        $opciones_de_mas = $this->postJson($url, ['name' => 'X', 'fields' => [['label' => 'Rubro', 'type' => 'select', 'options' => $treinta_y_una]]]);
        $opciones_de_mas->assertStatus(422);
        $this->assertArrayHasKey('fields.0.options', $opciones_de_mas->json('errors'));

        $this->postJson($url, ['name' => 'Etiqueta de 80', 'fields' => [['label' => str_repeat('a', 80), 'type' => 'text']]])->assertStatus(201);

        $etiqueta_larga = $this->postJson($url, ['name' => 'X', 'fields' => [['label' => str_repeat('a', 81), 'type' => 'text']]]);
        $etiqueta_larga->assertStatus(422);
        $this->assertArrayHasKey('fields.0.label', $etiqueta_larga->json('errors'));

        $this->postJson($url, ['name' => 'Opción de 80', 'fields' => [['label' => 'Rubro', 'type' => 'select', 'options' => [str_repeat('b', 80)]]]])
            ->assertStatus(201);

        $opcion_larga = $this->postJson($url, ['name' => 'X', 'fields' => [['label' => 'Rubro', 'type' => 'select', 'options' => [str_repeat('b', 81)]]]]);
        $opcion_larga->assertStatus(422);
        $this->assertArrayHasKey('fields.0.options', $opcion_larga->json('errors'));

        $this->assertSame(0, PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('name', 'X')->count(), 'Ningún 422 dejó una etapa creada.');
    }

    /**
     * 12. Claves generadas desde la etiqueta: únicas (con `_2`, `_3`), sin chocar con una explícita,
     *     empezando con letra; y al editar, las que vienen se conservan.
     *
     * @return void
     */
    public function test_claves_generadas_unicas_y_estables(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();

        $respuesta = $this->postJson('/api/admin/pipelines/' . $pipeline->id . '/stages', [
            'name'   => 'Relevamiento',
            'fields' => [
                ['label' => 'Fecha de visita', 'type' => 'date'],
                ['label' => 'Fecha de visita', 'type' => 'text'],
                ['key' => 'fecha_de_visita_2', 'label' => 'Otra', 'type' => 'text'],
                ['label' => '¿Cuántos empleados?', 'type' => 'number'],
                ['label' => '1er contacto', 'type' => 'text'],
                ['key' => '', 'label' => 'Observaciones', 'type' => 'textarea'],
            ],
        ]);

        $respuesta->assertStatus(201);
        $claves = array_column($respuesta->json('stage.fields'), 'key');
        $this->assertSame(
            ['fecha_de_visita', 'fecha_de_visita_3', 'fecha_de_visita_2', 'cuantos_empleados', 'campo_1er_contacto', 'observaciones'],
            $claves
        );

        /* Editar mandando los campos con su clave (como los devuelve la API) no las cambia; un campo
           nuevo sin clave recibe la suya. */
        $campos   = $respuesta->json('stage.fields');
        $campos[] = ['label' => 'Observaciones', 'type' => 'text'];

        $editada = $this->putJson('/api/admin/pipeline-stages/' . $respuesta->json('stage.id'), ['fields' => $campos]);
        $editada->assertStatus(200);
        $this->assertSame(array_merge($claves, ['observaciones_2']), array_column($editada->json('stage.fields'), 'key'));
    }

    /**
     * 13. Un id que no existe es 404 con mensaje legible (no el "No query results for model" de
     *     Laravel).
     *
     * @return void
     */
    public function test_un_pipeline_que_no_existe_es_404_legible(): void
    {
        $this->admin_logueado();

        $respuesta = $this->putJson('/api/admin/pipelines/999999999', ['name' => 'X']);

        $respuesta->assertStatus(404);
        $this->assertSame('El pipeline no existe (puede que otro admin lo haya borrado).', $respuesta->json('message'));
    }

    /**
     * 14. Sin sesión, nada: todo el módulo está bajo auth:sanctum.
     *
     * @return void
     */
    public function test_sin_sesion_devuelve_401(): void
    {
        $this->getJson('/api/admin/pipelines')->assertStatus(401);
    }
}

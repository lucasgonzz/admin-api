<?php

namespace Tests\Feature\Pipelines;

/**
 * La lectura del CRM de pipelines por `GET claude/query` (misión pipelines-crm, 27/9/2026).
 *
 * Lo que se protege:
 *  1. Que los cuatro modelos respondan 200 con EXACTAMENTE las columnas declaradas en
 *     `config/claude_query.php` (lista blanca positiva: ni una de más).
 *  2. 🔴 Que el texto libre escrito por personas (`body` y `data` de las actividades,
 *     `next_action_note` y `lost_reason` de las oportunidades) viaje solo con include=contenido.
 *  3. Que el estado se pueda leer (include=etapa) y filtrar (sin_cerrar).
 */
class ClaudeQueryDePipelinesTest extends BaseDePipelines
{
    /** Clave de ingesta de las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-pipelines';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * `GET claude/query` con la clave.
     *
     * @param array<string, mixed> $parametros
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function consultar(array $parametros)
    {
        return $this->getJson('/api/claude/query?' . http_build_query($parametros), [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ]);
    }

    /**
     * Un pipeline con una oportunidad abierta (con próxima acción y una nota) y una perdida.
     *
     * @return array{pipeline: \App\Models\Pipeline, abierta: array<string, mixed>, perdida: array<string, mixed>}
     */
    private function escenario()
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();

        $abierta = $this->alta_de_uno($pipeline, $this->crear_cliente());
        $this->putJson('/api/admin/pipeline-opportunities/' . $abierta['id'], [
            'next_action_at'   => '2026-09-30 15:00',
            'next_action_note' => 'Llamar al dueño',
        ])->assertStatus(200);
        $this->postJson('/api/admin/pipeline-opportunities/' . $abierta['id'] . '/notes', [
            'body'    => 'Dijo que le interesa el asistente de WhatsApp.',
            'channel' => 'call',
        ])->assertStatus(201);

        $perdida = $this->alta_de_uno($pipeline, $this->crear_lead());
        $this->mover($perdida['id'], [
            'stage_id'    => $this->etapa($pipeline, 'Perdido')->id,
            'lost_reason' => 'Precio',
            'note'        => 'Le pareció caro.',
        ])->assertStatus(200);

        return ['pipeline' => $pipeline, 'abierta' => $abierta, 'perdida' => $perdida];
    }

    /**
     * 1. Los cuatro modelos responden 200 con exactamente las columnas declaradas.
     *
     * @return void
     */
    public function test_los_cuatro_modelos_responden_con_las_columnas_declaradas(): void
    {
        $escenario = $this->escenario();
        $pipeline  = $escenario['pipeline'];

        $consultas = [
            'pipeline'             => ['ids' => $pipeline->id],
            'pipeline_stage'       => ['pipeline_id' => $pipeline->id],
            'pipeline_opportunity' => ['pipeline_id' => $pipeline->id],
            'pipeline_activity'    => ['pipeline_id' => $pipeline->id],
        ];

        foreach ($consultas as $modelo => $filtros) {
            $respuesta = $this->consultar(array_merge(['model' => $modelo], $filtros));

            $respuesta->assertStatus(200);

            $declaradas = config('claude_query.modelos.' . $modelo . '.columnas');
            $this->assertSame($declaradas, $respuesta->json('columnas'), 'Columnas de ' . $modelo);
            $this->assertNotEmpty($respuesta->json('data'), 'Sin filas en ' . $modelo);

            foreach ($respuesta->json('data') as $fila) {
                $this->assertSame($declaradas, array_keys($fila), 'Una fila de ' . $modelo . ' trae columnas de más o de menos.');
            }
        }

        $this->assertCount(count($this->etapas_de_prueba()), $this->consultar(['model' => 'pipeline_stage', 'pipeline_id' => $pipeline->id])->json('data'));
    }

    /**
     * 2. 🔴 El texto de las personas viaja solo con include=contenido: sin él, ni `body` ni `data`
     *    en las actividades, ni `next_action_note` / `lost_reason` en las oportunidades.
     *
     * @return void
     */
    public function test_include_contenido_trae_body_y_data(): void
    {
        $escenario = $this->escenario();

        $sin = $this->consultar(['model' => 'pipeline_activity', 'opportunity_id' => $escenario['abierta']['id']]);
        $sin->assertStatus(200);
        $this->assertNotEmpty($sin->json('data'), 'Sin filas, el recorrido de abajo no afirmaría nada.');
        foreach ($sin->json('data') as $fila) {
            $this->assertArrayNotHasKey('body', $fila);
            $this->assertArrayNotHasKey('data', $fila);
        }

        $con = $this->consultar([
            'model'          => 'pipeline_activity',
            'opportunity_id' => $escenario['abierta']['id'],
            'type'           => 'note',
            'include'        => 'contenido',
        ]);
        $con->assertStatus(200);
        $this->assertSame('Dijo que le interesa el asistente de WhatsApp.', $con->json('data.0.body'));
        $this->assertSame('call', $con->json('data.0.channel'));
        $this->assertContains('contenido', $con->json('includes_aplicados'));

        $cambio = $this->consultar([
            'model'          => 'pipeline_activity',
            'opportunity_id' => $escenario['perdida']['id'],
            'type'           => 'stage_change',
            'include'        => 'contenido',
        ])->assertStatus(200);
        $this->assertSame('Le pareció caro.', $cambio->json('data.0.body'));
        $this->assertSame('Perdido', $cambio->json('data.0.to_stage_name'));

        $oportunidad = $this->consultar(['model' => 'pipeline_opportunity', 'ids' => $escenario['abierta']['id']])->assertStatus(200);
        $this->assertCount(1, $oportunidad->json('data'));
        $this->assertArrayNotHasKey('next_action_note', $oportunidad->json('data.0'));
        $this->assertSame('2026-09-30 15:00:00', $oportunidad->json('data.0.next_action_at'));
        $this->assertSame('manual', $oportunidad->json('data.0.next_action_source'));

        $con_contenido = $this->consultar(['model' => 'pipeline_opportunity', 'ids' => $escenario['abierta']['id'], 'include' => 'contenido'])->assertStatus(200);
        $this->assertSame('Llamar al dueño', $con_contenido->json('data.0.next_action_note'));
    }

    /**
     * 3. El estado se lee con include=etapa y se filtra con sin_cerrar.
     *
     * @return void
     */
    public function test_estado_por_include_etapa_y_filtro_sin_cerrar(): void
    {
        $escenario = $this->escenario();
        $pipeline  = $escenario['pipeline'];

        $con_etapa = $this->consultar([
            'model'       => 'pipeline_opportunity',
            'pipeline_id' => $pipeline->id,
            'include'     => 'etapa,lead',
        ])->assertStatus(200);

        $this->assertNotEmpty($con_etapa->json('data'), 'Sin filas, los recorridos de abajo no afirmarían nada.');

        $tipos = [];
        foreach ($con_etapa->json('data') as $fila) {
            $tipos[$fila['id']] = $fila['etapa']['type'];
        }
        $this->assertSame('open', $tipos[$escenario['abierta']['id']]);
        $this->assertSame('lost', $tipos[$escenario['perdida']['id']]);

        $leads_vistos = 0;
        foreach ($con_etapa->json('data') as $fila) {
            if ($fila['lead'] !== null) {
                $leads_vistos++;
                $this->assertArrayNotHasKey('uuid', $fila['lead'], 'El uuid del lead es una credencial: no viaja por la relación.');
                $this->assertArrayNotHasKey('phone', $fila['lead']);
            }
        }
        $this->assertSame(1, $leads_vistos, 'La oportunidad perdida es de un lead: la relación tuvo que venir.');

        $abiertas = $this->consultar(['model' => 'pipeline_opportunity', 'pipeline_id' => $pipeline->id, 'sin_cerrar' => 1])->assertStatus(200);
        $this->assertSame([$escenario['abierta']['id']], array_column($abiertas->json('data'), 'id'));

        $cerradas = $this->consultar(['model' => 'pipeline_opportunity', 'pipeline_id' => $pipeline->id, 'sin_cerrar' => 0])->assertStatus(200);
        $this->assertSame([$escenario['perdida']['id']], array_column($cerradas->json('data'), 'id'));
    }

    /**
     * 4. Sigue siendo solo lectura: el CRM no agregó ninguna escritura por la API de Claude.
     *
     * @return void
     */
    public function test_no_hay_escritura_por_query(): void
    {
        $this->postJson('/api/claude/query', ['model' => 'pipeline_opportunity'], ['X-Claude-Task-Key' => self::CLAVE])->assertStatus(405);
    }
}

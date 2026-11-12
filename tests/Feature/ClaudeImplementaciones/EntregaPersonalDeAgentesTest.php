<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\AgentImplementation;
use App\Models\AgentImplementationStage;

class EntregaPersonalDeAgentesTest extends BaseDeImplementaciones
{
    const RUTA = '/api/claude/agent-implementations';

    private function entrega(): array
    {
        return [
            'version_ok' => true,
            'extension_ok' => true,
            'numero_personal_solicitado' => true,
            'numero_personal_cargado' => true,
            'asistente_whatsapp_activo' => true,
            'agente_personal_probado' => true,
            'app_link_enviado' => true,
            'acceso_sistema_verificado' => true,
            'onboarding_completado' => true,
            'pdf_agentes_enviado' => true,
            'pdf_agentes_url' => 'https://admin.ejemplo.test/api/guias/agentes-ia-primeros-pasos.pdf',
        ];
    }

    private function alta(array $extra = []): int
    {
        $client = $this->crear_cliente('Agentes nuevos');
        $response = $this->postJson(self::RUTA, array_merge(['client_id' => $client->id, 'camino' => 'propio'], $extra), $this->headers());
        $response->assertStatus(201);
        return (int) $response->json('model.id');
    }

    public function test_full_por_defecto_tiene_tres_instancias_y_no_avanza_sin_entrega(): void
    {
        $id = $this->alta();
        $this->getJson(self::RUTA . '/' . $id, $this->headers())
            ->assertJsonPath('model.paquete', 'full')
            ->assertJsonPath('model.flujo_version', 2)
            ->assertJsonPath('model.entrega_personal.requerida', true)
            ->assertJsonPath('model.entrega_personal.completa', false)
            ->assertJsonCount(3, 'model.entrega_personal.instancias');

        foreach ([2, 3, 4, 5, 6, 7, 8] as $etapa) {
            $this->postJson(self::RUTA . '/' . $id . '/stages/' . $etapa, ['status' => 'in_progress'], $this->headers())
                ->assertStatus(422)->assertJsonPath('motivo', 'avance_no_permitido');
        }
        $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'skipped'], $this->headers())->assertStatus(422);
        $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'completed'], $this->headers())->assertStatus(422);
        $this->patchJson(self::RUTA . '/' . $id, ['status' => 'completed'], $this->headers())->assertStatus(422);
        $this->assertSame('pending', AgentImplementationStage::where('agent_implementation_id', $id)->where('stage_number', 2)->value('status'));
    }

    public function test_full_avanza_tras_entregar_y_solo_cierra_con_todas_sus_etapas(): void
    {
        $id = $this->alta(['paquete' => 'full']);
        $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'completed', 'data' => $this->entrega()], $this->headers())
            ->assertStatus(200)->assertJsonPath('model.entrega_personal.completa', true)->assertJsonPath('model.current_stage', 2);
        $this->postJson(self::RUTA . '/' . $id . '/stages/2', ['status' => 'in_progress'], $this->headers())->assertStatus(200);
        $this->patchJson(self::RUTA . '/' . $id, ['status' => 'completed'], $this->headers())->assertStatus(422);
        foreach (range(2, 8) as $etapa) {
            $this->postJson(self::RUTA . '/' . $id . '/stages/' . $etapa, ['status' => 'completed'], $this->headers())->assertStatus(200);
        }
        $this->patchJson(self::RUTA . '/' . $id, ['status' => 'completed'], $this->headers())
            ->assertStatus(200)->assertJsonPath('model.status', 'completed');
    }

    public function test_lite_se_abre_sin_camino_y_cierra_sin_kapso_ni_meta(): void
    {
        $client = $this->crear_cliente('Agentes Lite');
        $response = $this->postJson(self::RUTA, ['client_id' => $client->id, 'paquete' => 'lite'], $this->headers());
        $response->assertStatus(201)->assertJsonPath('model.camino', null)->assertJsonPath('model.paquete', 'lite');
        $id = (int) $response->json('model.id');
        foreach (array_slice($response->json('model.etapas'), 1) as $etapa) {
            $this->assertSame('skipped', $etapa['estado']);
            $this->assertSame('paquete_lite', $etapa['data']['motivo']);
        }
        $this->postJson(self::RUTA . '/' . $id . '/stages/3', ['status' => 'in_progress'], $this->headers())->assertStatus(422);
        $this->patchJson(self::RUTA . '/' . $id, ['status' => 'completed'], $this->headers())->assertStatus(422);
        $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'completed', 'data' => $this->entrega()], $this->headers())
            ->assertStatus(200)->assertJsonPath('model.current_stage', 1);
        $this->patchJson(self::RUTA . '/' . $id, ['status' => 'completed'], $this->headers())
            ->assertStatus(200)->assertJsonPath('model.status', 'completed')->assertJsonPath('model.phone_number_id', null);
        $this->assertSame($client->phone, $client->fresh()->phone, 'La implementación no cambia el número del negocio.');
    }

    public function test_full_arranca_sin_elegir_meta_y_pide_camino_solo_despues_de_la_entrega(): void
    {
        $client = $this->crear_cliente('Full sin camino');
        $response = $this->postJson(self::RUTA, ['client_id' => $client->id], $this->headers());
        $response->assertStatus(201)->assertJsonPath('model.paquete', 'full')->assertJsonPath('model.camino', null);
        $id = (int) $response->json('model.id');
        $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'completed', 'data' => $this->entrega()], $this->headers())->assertStatus(200);
        $this->postJson(self::RUTA . '/' . $id . '/stages/2', ['status' => 'in_progress'], $this->headers())->assertStatus(422);
        $this->patchJson(self::RUTA . '/' . $id, ['camino' => 'propio'], $this->headers())->assertStatus(200);
        $this->postJson(self::RUTA . '/' . $id . '/stages/2', ['status' => 'in_progress'], $this->headers())->assertStatus(200);
    }

    public function test_un_link_enviado_o_un_valor_con_truthiness_no_acreditan_entrega(): void
    {
        $id = $this->alta();
        $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'in_progress', 'data' => ['app_link_enviado' => true]], $this->headers())
            ->assertStatus(200)->assertJsonPath('model.entrega_personal.completa', false);
        $data = $this->entrega();
        $data['onboarding_completado'] = 'true';
        $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'completed', 'data' => $data], $this->headers())->assertStatus(422);
        $guardado = AgentImplementationStage::where('agent_implementation_id', $id)->where('stage_number', 1)->first()->data;
        $this->assertTrue($guardado['app_link_enviado']);
        $this->assertArrayNotHasKey('onboarding_completado', $guardado, 'El rechazo no escribe una entrega que no ocurrió.');
    }

    public function test_pdf_no_se_registra_antes_de_la_entrega_ni_sin_url_https(): void
    {
        $id = $this->alta();
        $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'in_progress', 'data' => ['pdf_agentes_enviado' => true]], $this->headers())->assertStatus(422);
        $data = $this->entrega();
        foreach ([null, 'http://admin.ejemplo.test/guia.pdf', 'https://admin.ejemplo.test/guia.html'] as $url) {
            $data['pdf_agentes_url'] = $url;
            $this->postJson(self::RUTA . '/' . $id . '/stages/1', ['status' => 'completed', 'data' => $data], $this->headers())->assertStatus(422);
        }
    }

    public function test_flujo_historico_conserva_avances_sin_fingir_entrega(): void
    {
        $id = $this->alta();
        AgentImplementation::where('id', $id)->update(['flujo_version' => 1]);
        $this->postJson(self::RUTA . '/' . $id . '/stages/3', ['status' => 'in_progress'], $this->headers())
            ->assertStatus(200)->assertJsonPath('model.entrega_personal.requerida', false)->assertJsonPath('model.entrega_personal.completa', false);
        $this->patchJson(self::RUTA . '/' . $id, ['status' => 'completed'], $this->headers())->assertStatus(200);
    }

    public function test_paquete_invalido_o_cambio_por_patch_no_escribe(): void
    {
        $client = $this->crear_cliente('Paquete inválido');
        $this->postJson(self::RUTA, ['client_id' => $client->id, 'camino' => 'propio', 'paquete' => 'otro'], $this->headers())->assertStatus(422);
        $this->assertFalse(AgentImplementation::where('client_id', $client->id)->exists());
        $id = $this->alta();
        $this->patchJson(self::RUTA . '/' . $id, ['paquete' => 'lite'], $this->headers())->assertStatus(422);
        $this->assertSame('full', AgentImplementation::find($id)->paquete);
    }
}

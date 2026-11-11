<?php

namespace Tests\Feature\ImplementationFollowups;

use App\Models\ImplementationFollowup;
use App\Models\ImplementationFollowupEvent;
use App\Services\Prueba\PruebaCicloService;

/**
 * Las rutas de Lucas: `GET/POST claude/implementation-followups` y `PATCH claude/implementation-followups/{id}`, con
 * evento `source: lucas` por cada cambio.
 */
class GestionDeLucasDelSeguimientoTest extends BaseDeSeguimientos
{
    const RUTA = '/api/claude/implementation-followups';

    /**
     * Lista con cliente y próximo, y filtra por estado.
     *
     * @return void
     */
    public function test_lista_y_filtra_por_estado(): void
    {
        [$client, $implementation] = $this->escenario();
        $activo = $this->seguimiento($client, $implementation);
        [$otro, $otra] = $this->escenario();
        $frenado = $this->seguimiento($otro, $otra, ['status' => 'frenado']);

        $todos = $this->getJson(self::RUTA, $this->headers())->assertOk()->json('followups');
        $ids   = array_column($todos, 'id');
        $this->assertContains((int) $activo->id, $ids);
        $this->assertContains((int) $frenado->id, $ids);

        $fila = collect($todos)->firstWhere('id', (int) $activo->id);
        $this->assertSame((int) $client->id, $fila['client_id']);
        $this->assertNotEmpty($fila['client_name']);
        $this->assertNotEmpty($fila['proximo_at']);

        $solo = array_column($this->getJson(self::RUTA . '?status=frenado', $this->headers())->assertOk()->json('followups'), 'id');
        $this->assertSame([(int) $frenado->id], $solo);

        $this->getJson(self::RUTA . '?status=cualquiera', $this->headers())->assertStatus(422);
    }

    /**
     * Alta manual aunque la implementación esté cerrada; 409 si ya tiene; 404 si el cliente no existe; 422 si está en
     * prueba.
     *
     * @return void
     */
    public function test_alta_manual(): void
    {
        $client = $this->crear_cliente('Cerrada hace poco');
        $implementation = $this->crear_implementacion($client, ['status' => 'completed', 'completed_at' => now()->subDays(3)]);

        $respuesta = $this->postJson(self::RUTA, ['client_id' => $client->id], $this->headers())->assertStatus(201);
        $this->assertSame('activo', $respuesta->json('seguimiento.status'));

        $followup = ImplementationFollowup::where('client_id', $client->id)->first();
        $this->assertSame((int) $implementation->id, (int) $followup->implementation_id);
        $this->assertSame('lucas', $this->eventos($followup, ImplementationFollowupEvent::KIND_CREADO)->first()->source);

        $this->postJson(self::RUTA, ['client_id' => $client->id], $this->headers())->assertStatus(409)->assertJson(['error' => 'ya_existe']);
        $this->postJson(self::RUTA, ['client_id' => 999999999], $this->headers())->assertStatus(404)->assertJson(['error' => 'sin_cliente']);

        $en_prueba = $this->crear_cliente('En prueba', ['estado' => PruebaCicloService::CLIENTE_PRUEBA]);
        $this->postJson(self::RUTA, ['client_id' => $en_prueba->id], $this->headers())->assertStatus(422)->assertJson(['error' => 'cliente_en_prueba']);
    }

    /**
     * PATCH: reactivar un frenado pone el contador en 0 con evento `reanudado` de Lucas; cambiar el intervalo y los
     * hitos que no aplican deja un `editado`; pausar exige fecha; parámetros de más, 422.
     *
     * @return void
     */
    public function test_patch_con_eventos_de_lucas(): void
    {
        [$client, $implementation] = $this->escenario();
        $followup = $this->seguimiento($client, $implementation, ['status' => 'frenado', 'unanswered_count' => 2]);
        $ruta = self::RUTA . '/' . $followup->id;

        $this->patchJson($ruta, ['status' => 'activo'], $this->headers())->assertOk()
            ->assertJson(['seguimiento' => ['status' => 'activo', 'sin_respuesta' => 0]]);
        $reanudado = $this->eventos($followup, ImplementationFollowupEvent::KIND_REANUDADO)->first();
        $this->assertSame('lucas', $reanudado->source);

        $this->patchJson($ruta, ['interval_business_days' => 3, 'max_unanswered' => 3, 'hitos_no_aplican' => ['factura_arca']], $this->headers())
            ->assertOk()
            ->assertJson(['seguimiento' => ['intervalo_dias_habiles' => 3, 'max_sin_respuesta' => 3, 'hitos_no_aplican' => ['factura_arca']]]);
        $this->assertSame('lucas', $this->eventos($followup, ImplementationFollowupEvent::KIND_EDITADO)->first()->source);

        $this->patchJson($ruta, ['status' => 'pausado'], $this->headers())->assertStatus(422)->assertJson(['error' => 'sin_fecha']);

        $this->patchJson($ruta, ['status' => 'pausado', 'paused_until' => '2026-11-20', 'pause_reason' => 'Vacaciones'], $this->headers())
            ->assertOk()->assertJson(['seguimiento' => ['status' => 'pausado', 'pausado_hasta' => '2026-11-20', 'motivo_pausa' => 'Vacaciones']]);
        $this->assertSame('2026-11-23 10:00', $followup->refresh()->next_due_at->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d H:i'));

        $this->patchJson($ruta, ['status' => 'terminado'], $this->headers())->assertOk()->assertJson(['seguimiento' => ['status' => 'terminado']]);
        $this->assertSame('lucas', $this->eventos($followup, ImplementationFollowupEvent::KIND_TERMINADO)->first()->source);

        $this->patchJson($ruta, ['forzar' => true], $this->headers())->assertStatus(422);
        $this->patchJson(self::RUTA . '/999999999', ['status' => 'activo'], $this->headers())->assertStatus(404)->assertJson(['error' => 'sin_seguimiento']);
    }

    /**
     * Las rutas exigen la clave de Claude.
     *
     * @return void
     */
    public function test_sin_clave_401(): void
    {
        $this->getJson(self::RUTA, ['Accept' => 'application/json'])->assertStatus(401);
        $this->getJson('/api/claude/support-codex/implementation-followups/pending', ['Accept' => 'application/json'])->assertStatus(401);
    }
}

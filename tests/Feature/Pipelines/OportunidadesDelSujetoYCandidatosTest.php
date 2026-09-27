<?php

namespace Tests\Feature\Pipelines;

use App\Models\Client;
use Illuminate\Support\Str;

/**
 * Las oportunidades de un cliente / lead (endpoint #13, la pestaña "Pipelines" de su ficha) y los
 * candidatos del alta masiva (#11).
 *
 * 🔴 La base de tests del slot puede traer clientes de antes (no se limpia), así que toda prueba de
 * candidatos filtra por una marca única (`q`) y nunca cuenta "todos los clientes".
 */
class OportunidadesDelSujetoYCandidatosTest extends BaseDePipelines
{
    /**
     * 1. Las de un cliente, en todos los pipelines (archivados incluidos): abiertas primero,
     *    después las cerradas de la más reciente a la más vieja, cada una con
     *    `pipeline: {id, name, archived_at}` y sin el contacto (no es la ficha).
     *
     * @return void
     */
    public function test_oportunidades_de_un_cliente(): void
    {
        $this->admin_logueado();
        $cliente = $this->crear_cliente();
        $otro    = $this->crear_cliente();

        $abierto  = $this->crear_pipeline(null, ['sort_order' => 10]);
        $perdido  = $this->crear_pipeline(null, ['sort_order' => 20]);
        $ganado   = $this->crear_pipeline(null, ['sort_order' => 30]);

        $this->clavar_reloj('2026-09-25 10:00:00');
        $op_ganada = $this->alta_de_uno($ganado, $cliente);
        $this->mover($op_ganada['id'], ['stage_id' => $this->etapa($ganado, 'Ganado')->id])->assertStatus(200);

        $this->clavar_reloj('2026-09-26 10:00:00');
        $op_perdida = $this->alta_de_uno($perdido, $cliente);
        $this->mover($op_perdida['id'], ['stage_id' => $this->etapa($perdido, 'Perdido')->id, 'lost_reason' => 'Precio'])->assertStatus(200);

        $this->clavar_reloj(self::HOY);
        $op_abierta = $this->alta_de_uno($abierto, $cliente);
        $this->alta_de_uno($abierto, $otro);

        $this->putJson('/api/admin/pipelines/' . $ganado->id, ['archived' => true])->assertStatus(200);

        $respuesta = $this->getJson('/api/admin/pipeline-opportunities?client_id=' . $cliente->id);
        $respuesta->assertStatus(200);

        $lista = $respuesta->json('opportunities');
        $this->assertSame([$op_abierta['id'], $op_perdida['id'], $op_ganada['id']], array_column($lista, 'id'));

        $this->assertSame(['id' => $abierto->id, 'name' => $abierto->name, 'archived_at' => null], $lista[0]['pipeline']);
        $this->assertSame('2026-09-27 10:00:00', $lista[2]['pipeline']['archived_at']);
        $this->assertSame('2026-09-26 10:00:00', $lista[1]['closed_at']);
        $this->assertSame('Precio', $lista[1]['lost_reason']);
        $this->assertNull($lista[0]['subject']['phone'], 'Fuera de la ficha el contacto no viaja.');
    }

    /**
     * 2. Las de un lead.
     *
     * @return void
     */
    public function test_oportunidades_de_un_lead(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $lead     = $this->crear_lead();

        $op = $this->alta_de_uno($pipeline, $lead);
        $this->alta_de_uno($pipeline, $this->crear_lead());

        $lista = $this->getJson('/api/admin/pipeline-opportunities?lead_id=' . $lead->id)->assertStatus(200)->json('opportunities');

        $this->assertSame([$op['id']], array_column($lista, 'id'));
        $this->assertSame('lead', $lista[0]['subject_type']);
        $this->assertSame($lead->id, $lista[0]['lead_id']);
    }

    /**
     * 3. Sin sujeto, o con los dos a la vez: 422.
     *
     * @return void
     */
    public function test_sin_sujeto_o_con_los_dos_da_422(): void
    {
        $this->admin_logueado();

        $this->getJson('/api/admin/pipeline-opportunities')->assertStatus(422);
        $this->getJson('/api/admin/pipeline-opportunities?client_id=1&lead_id=1')->assertStatus(422);
    }

    /**
     * 4. Candidatos clientes: `solo_activos`, búsqueda, orden por nombre, contacto incluido, y lo
     *    que ya tienen en ESTE pipeline (`open_opportunity_id`, `closed_count`).
     *
     * @return void
     */
    public function test_candidatos_clientes_con_su_situacion_en_el_pipeline(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $marca    = 'Cand' . Str::random(8);

        $con_abierta = $this->crear_cliente(['name' => $marca . ' 1']);
        $con_cerrada = $this->crear_cliente(['name' => $marca . ' 2']);
        $sin_nada    = $this->crear_cliente(['name' => $marca . ' 3']);
        $inactivo    = $this->crear_cliente(['name' => $marca . ' 4', 'is_active' => false]);

        $abierta = $this->alta_de_uno($pipeline, $con_abierta);
        $cerrada = $this->alta_de_uno($pipeline, $con_cerrada);
        $this->mover($cerrada['id'], ['stage_id' => $this->etapa($pipeline, 'Perdido')->id, 'lost_reason' => 'Precio'])->assertStatus(200);

        /* Una abierta en OTRO pipeline no cuenta para este. */
        $this->alta_de_uno($this->crear_pipeline(), $sin_nada);

        $respuesta = $this->getJson('/api/admin/pipelines/' . $pipeline->id . '/candidates?type=client&solo_activos=1&q=' . $marca);
        $respuesta->assertStatus(200);

        $candidatos = $respuesta->json('candidates');
        $this->assertSame([$con_abierta->id, $con_cerrada->id, $sin_nada->id], array_column($candidatos, 'id'));
        $this->assertSame(3, $respuesta->json('total'));
        $this->assertFalse($respuesta->json('has_more'));
        $this->assertArrayNotHasKey('lead_statuses', $respuesta->json(), 'lead_statuses va solo con type=lead.');

        $this->assertSame($abierta['id'], $candidatos[0]['open_opportunity_id']);
        $this->assertSame(0, $candidatos[0]['closed_count']);
        $this->assertNull($candidatos[1]['open_opportunity_id']);
        $this->assertSame(1, $candidatos[1]['closed_count']);
        $this->assertNull($candidatos[2]['open_opportunity_id']);
        $this->assertSame(0, $candidatos[2]['closed_count']);

        $this->assertSame('client', $candidatos[0]['type']);
        $this->assertSame($con_abierta->phone, $candidatos[0]['phone']);
        $this->assertSame($con_abierta->email, $candidatos[0]['email']);
        $this->assertSame($con_abierta->company_name, $candidatos[0]['secondary']);
        $this->assertTrue($candidatos[0]['is_active']);

        /* Sin `solo_activos`, el inactivo aparece. */
        $todos = $this->getJson('/api/admin/pipelines/' . $pipeline->id . '/candidates?type=client&q=' . $marca)->assertStatus(200);
        $this->assertSame(4, $todos->json('total'));
        $this->assertFalse($todos->json('candidates.3.is_active'));
        $this->assertSame($inactivo->id, $todos->json('candidates.3.id'));
    }

    /**
     * 5. Candidatos leads: del más nuevo al más viejo, filtro por estado, el nombre según empresa /
     *    contacto / "Lead #id", y `lead_statuses` del catálogo.
     *
     * @return void
     */
    public function test_candidatos_leads_con_filtro_de_estado_y_lead_statuses(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $marca    = 'Ld' . Str::random(8);

        $con_empresa = $this->crear_lead(['company_name' => 'Negocio ' . $marca, 'contact_name' => 'Ana', 'status' => 'nuevo']);
        $solo_contacto = $this->crear_lead(['company_name' => '', 'contact_name' => 'Beto ' . $marca, 'status' => 'contactado']);
        $sin_nombre  = $this->crear_lead([
            'company_name' => null,
            'contact_name' => null,
            'email'        => 'sin-nombre-' . strtolower($marca) . '@test.local',
            'status'       => 'contactado',
        ]);

        $respuesta = $this->getJson('/api/admin/pipelines/' . $pipeline->id . '/candidates?type=lead&q=' . $marca);
        $respuesta->assertStatus(200);

        $candidatos = $respuesta->json('candidates');
        $this->assertSame([$sin_nombre->id, $solo_contacto->id, $con_empresa->id], array_column($candidatos, 'id'));

        $this->assertSame('Lead #' . $sin_nombre->id, $candidatos[0]['name']);
        $this->assertNull($candidatos[0]['secondary']);
        $this->assertSame('Beto ' . $marca, $candidatos[1]['name']);
        $this->assertNull($candidatos[1]['secondary']);
        $this->assertSame('Negocio ' . $marca, $candidatos[2]['name']);
        $this->assertSame('Ana', $candidatos[2]['secondary']);

        $this->assertSame('contactado', $candidatos[0]['status']);
        $this->assertSame('Contactado', $candidatos[0]['status_label']);
        $this->assertNull($candidatos[0]['is_active'], 'is_active es de clientes: en un lead va null.');
        $this->assertSame($con_empresa->phone, $candidatos[2]['phone']);

        $estados = $respuesta->json('lead_statuses');
        $this->assertContains(['slug' => 'nuevo', 'label' => 'Nuevo'], $estados);
        $this->assertContains(['slug' => 'contactado', 'label' => 'Contactado'], $estados);
        $this->assertNotContains('mail2_enviado', array_column($estados, 'slug'), 'Oculto de filtros en todo el admin.');

        $contactados = $this->getJson('/api/admin/pipelines/' . $pipeline->id . '/candidates?type=lead&lead_status=contactado&q=' . $marca)
            ->assertStatus(200)
            ->json('candidates');
        $this->assertSame([$sin_nombre->id, $solo_contacto->id], array_column($contactados, 'id'));
    }

    /**
     * 6. Paginado: `limit` (default 100, se recorta a 300) y `offset`, con `total` y `has_more`.
     *
     * @return void
     */
    public function test_candidatos_paginado(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $marca    = 'Pag' . Str::random(8);

        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->crear_cliente(['name' => $marca . ' ' . $i])->id;
        }

        $base = '/api/admin/pipelines/' . $pipeline->id . '/candidates?type=client&q=' . $marca;

        $primera = $this->getJson($base . '&limit=2&offset=0')->assertStatus(200);
        $this->assertSame(array_slice($ids, 0, 2), array_column($primera->json('candidates'), 'id'));
        $this->assertSame(5, $primera->json('total'));
        $this->assertTrue($primera->json('has_more'));

        $segunda = $this->getJson($base . '&limit=2&offset=2')->assertStatus(200);
        $this->assertSame(array_slice($ids, 2, 2), array_column($segunda->json('candidates'), 'id'));
        $this->assertTrue($segunda->json('has_more'));

        $ultima = $this->getJson($base . '&limit=2&offset=4')->assertStatus(200);
        $this->assertSame([$ids[4]], array_column($ultima->json('candidates'), 'id'));
        $this->assertFalse($ultima->json('has_more'));

        $de_mas = $this->getJson($base . '&limit=1000')->assertStatus(200);
        $this->assertCount(5, $de_mas->json('candidates'));
        $this->assertFalse($de_mas->json('has_more'));

        $this->getJson($base . '&limit=0')->assertStatus(422);
    }

    /**
     * 7. `type` es obligatorio y solo `client` o `lead`.
     *
     * @return void
     */
    public function test_candidatos_sin_tipo_o_con_tipo_invalido_da_422(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();

        $sin_tipo = $this->getJson('/api/admin/pipelines/' . $pipeline->id . '/candidates');
        $sin_tipo->assertStatus(422);
        $this->assertArrayHasKey('type', $sin_tipo->json('errors'));

        $this->getJson('/api/admin/pipelines/' . $pipeline->id . '/candidates?type=proveedor')->assertStatus(422);
    }

    /**
     * 8. Si el cliente o el lead se borró, la oportunidad no rompe nada: el sujeto viaja como
     *    "(registro eliminado)" con `missing: true`.
     *
     * @return void
     */
    public function test_sujeto_borrado_viaja_como_registro_eliminado(): void
    {
        $this->admin_logueado();
        $pipeline = $this->crear_pipeline();
        $cliente  = $this->crear_cliente();
        $lead     = $this->crear_lead();

        $del_cliente = $this->alta_de_uno($pipeline, $cliente);
        $del_lead    = $this->alta_de_uno($pipeline, $lead);

        Client::query()->whereKey($cliente->id)->delete();
        $lead->delete();

        $por_id = [];
        foreach ($this->getJson('/api/admin/pipelines/' . $pipeline->id . '/opportunities')->assertStatus(200)->json('opportunities') as $op) {
            $por_id[$op['id']] = $op['subject'];
        }

        $this->assertSame('(registro eliminado)', $por_id[$del_cliente['id']]['name']);
        $this->assertTrue($por_id[$del_cliente['id']]['missing']);
        $this->assertSame('client', $por_id[$del_cliente['id']]['type']);
        $this->assertSame($cliente->id, $por_id[$del_cliente['id']]['id']);

        $this->assertTrue($por_id[$del_lead['id']]['missing']);
        $this->assertSame('lead', $por_id[$del_lead['id']]['type']);

        $this->getJson('/api/admin/pipeline-opportunities/' . $del_cliente['id'])
            ->assertStatus(200)
            ->assertJsonPath('opportunity.subject.missing', true);
    }
}

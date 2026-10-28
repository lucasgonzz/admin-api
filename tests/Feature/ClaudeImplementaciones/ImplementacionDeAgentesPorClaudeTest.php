<?php

namespace Tests\Feature\ClaudeImplementaciones;

use App\Models\AgentImplementation;
use App\Models\AgentImplementationEvent;
use App\Models\AgentImplementationStage;

/**
 * `claude/agent-implementations/*`: el registro de la implementación de Agentes de IA (misión
 * `implementacion-agentes-ia`, 28/10/2026). Alta, etapas, bitácora, PATCH de ids y cierre, y lecturas.
 *
 * Lo que se mira es lo que un camino feliz no ve: una sola abierta por cliente (409 con el id), que `data`
 * se MEZCLE en vez de pisarse, que `current_stage` saltee las `skipped`, que la etapa fuera de 1–8 sea 422
 * sin escribir nada, que `whatsapp_ops` no se pueda escribir ni leer desde afuera, y que la bitácora no
 * guarde los ids de Meta.
 *
 * El WhatsApp tiene su propio test (`WhatsappDeImplementacionDeAgentesTest`).
 */
class ImplementacionDeAgentesPorClaudeTest extends BaseDeImplementaciones
{
    /** Ruta base. */
    const RUTA = '/api/claude/agent-implementations';

    /**
     * Da de alta una implementación por la ruta real y devuelve su id.
     *
     * @param int    $client_id El cliente.
     * @param string $camino    nuevo | propio.
     *
     * @return int
     */
    private function alta(int $client_id, string $camino = 'propio'): int
    {
        $respuesta = $this->postJson(self::RUTA, ['client_id' => $client_id, 'camino' => $camino], $this->headers());
        $respuesta->assertStatus(201);

        return (int) $respuesta->json('model.id');
    }

    /**
     * Compara dos objetos de `data` sin importar el orden de las claves: la columna JSON de MySQL las
     * guarda normalizadas (por largo y alfabético), así que el orden en que se mandaron no se conserva y
     * no es parte del contrato.
     *
     * @param array<string, mixed> $esperado Lo esperado.
     * @param mixed                $real     Lo que vino.
     *
     * @return void
     */
    private function assertMismasClaves(array $esperado, $real): void
    {
        $this->assertIsArray($real);
        ksort($esperado);
        ksort($real);
        $this->assertSame($esperado, $real);
    }

    /**
     * Alta: 201 con las ocho etapas (la 1 en curso con el camino en su data), el cliente sin claves, y un
     * evento de avance. La segunda alta del mismo cliente es 409 con el id de la abierta; un cliente que no
     * existe es 404; sin camino es 422. Cancelada la abierta, se puede abrir otra.
     *
     * @return void
     */
    public function test_alta_201_409_con_otra_abierta_404_sin_cliente(): void
    {
        $client = $this->crear_cliente('Mayorista de Pesca');

        $respuesta = $this->postJson(self::RUTA, ['client_id' => $client->id, 'camino' => 'propio'], $this->headers());
        $respuesta->assertStatus(201)
            ->assertJsonPath('model.client_id', $client->id)
            ->assertJsonPath('model.status', 'in_progress')
            ->assertJsonPath('model.camino', 'propio')
            ->assertJsonPath('model.current_stage', 1)
            ->assertJsonPath('model.cliente', ['id' => $client->id, 'name' => 'Mayorista de Pesca'])
            ->assertJsonPath('model.etapas.0.numero', 1)
            ->assertJsonPath('model.etapas.0.nombre', 'Preparación')
            ->assertJsonPath('model.etapas.0.estado', 'in_progress')
            ->assertJsonPath('model.etapas.0.data', ['camino' => 'propio'])
            ->assertJsonPath('model.etapas.7.nombre', 'En marcha')
            ->assertJsonPath('model.etapas.7.estado', 'pending')
            ->assertJsonPath('model.eventos.0.kind', 'avance');

        $this->assertCount(8, $respuesta->json('model.etapas'));
        $this->assertStringNotContainsString($client->api_key, $this->cuerpo($respuesta), 'El estado no saca las claves del cliente.');
        /* Una etapa sin nada registrado sale como objeto JSON ({}), no como lista. */
        $this->assertStringContainsString('"data":{}', (string) $respuesta->getContent());

        $id = (int) $respuesta->json('model.id');

        $this->postJson(self::RUTA, ['client_id' => $client->id, 'camino' => 'nuevo'], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error', 'ya_tiene_una_abierta')
            ->assertJsonPath('id', $id);
        $this->assertSame(1, AgentImplementation::where('client_id', $client->id)->count());

        $this->postJson(self::RUTA, ['client_id' => 999999999, 'camino' => 'nuevo'], $this->headers())->assertStatus(404);
        $this->postJson(self::RUTA, ['client_id' => $client->id], $this->headers())->assertStatus(422);
        $this->postJson(self::RUTA, ['client_id' => $client->id, 'camino' => 'otro'], $this->headers())->assertStatus(422);
        $this->postJson(self::RUTA, ['client_id' => $client->id, 'camino' => 'nuevo', 'forzar' => true], $this->headers())->assertStatus(422);

        /* Cancelada la abierta, se puede abrir otra. */
        $this->patchJson(self::RUTA . '/' . $id, ['status' => 'cancelled'], $this->headers())->assertStatus(200);
        $this->postJson(self::RUTA, ['client_id' => $client->id, 'camino' => 'nuevo'], $this->headers())->assertStatus(201);
    }

    /**
     * Etapas: `data` se MEZCLA con lo que ya tenía (las claves nuevas pisan, el resto queda); `current_stage`
     * es la primera que no está completed ni skipped (saltea la 2 salteada); la nota queda como evento; la
     * etapa fuera de 1–8 es 422 sin escribir nada; `whatsapp_ops` y una lista en `data` son 422.
     *
     * @return void
     */
    public function test_etapas_mezclan_data_y_current_stage_saltea_skipped(): void
    {
        $client = $this->crear_cliente('Ferretería Agentes');
        $id     = $this->alta($client->id, 'nuevo');
        $ruta   = self::RUTA . '/' . $id . '/stages/';

        $primera = $this->postJson($ruta . '1', ['status' => 'in_progress', 'data' => ['web_https' => true, 'plan_kapso_ok' => false]], $this->headers());
        $primera->assertStatus(200);
        $this->assertMismasClaves(['camino' => 'nuevo', 'web_https' => true, 'plan_kapso_ok' => false], $primera->json('model.etapas.0.data'));

        $respuesta = $this->postJson($ruta . '1', [
            'status' => 'completed',
            'data'   => ['plan_kapso_ok' => true, 'guia_enviada' => true],
            'nota'   => 'El dueño ya leyó la guía.',
        ], $this->headers());
        $respuesta->assertStatus(200)
            ->assertJsonPath('model.etapas.0.estado', 'completed')
            ->assertJsonPath('model.current_stage', 2)
            ->assertJsonPath('model.eventos.0.kind', 'nota')
            ->assertJsonPath('model.eventos.0.texto', 'El dueño ya leyó la guía.')
            ->assertJsonPath('model.eventos.0.etapa', 1)
            ->assertJsonPath('model.eventos.1.kind', 'avance');
        $this->assertMismasClaves(
            ['camino' => 'nuevo', 'web_https' => true, 'plan_kapso_ok' => true, 'guia_enviada' => true],
            $respuesta->json('model.etapas.0.data')
        );
        $this->assertNotNull($respuesta->json('model.etapas.0.completed_at'));

        /* Camino nuevo: el Entrenamiento se saltea y la actual pasa a la 3. */
        $this->postJson($ruta . '2', ['status' => 'skipped'], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('model.etapas.1.estado', 'skipped')
            ->assertJsonPath('model.current_stage', 3);

        /* Una etapa posterior completa no mueve la actual: la que falta sigue siendo la 3. */
        $this->postJson($ruta . '7', ['status' => 'completed', 'data' => ['tarjeta_cargada' => true]], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('model.current_stage', 3);

        /* Volver una a pending la reabre y limpia sus fechas. */
        $this->postJson($ruta . '1', ['status' => 'pending'], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('model.current_stage', 1)
            ->assertJsonPath('model.etapas.0.started_at', null)
            ->assertJsonPath('model.etapas.0.completed_at', null);

        $eventos_antes = AgentImplementationEvent::where('agent_implementation_id', $id)->count();

        $this->postJson($ruta . '9', ['status' => 'completed'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'etapa_fuera_de_rango');
        $this->postJson($ruta . '0', ['status' => 'completed'], $this->headers())->assertStatus(422);
        $this->postJson($ruta . '3', ['status' => 'hecha'], $this->headers())->assertStatus(422);
        $this->postJson($ruta . '3', [], $this->headers())->assertStatus(422);
        $this->postJson($ruta . '3', ['status' => 'completed', 'data' => ['whatsapp_ops' => ['x' => []]]], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'clave_reservada');
        $this->postJson($ruta . '3', ['status' => 'completed', 'data' => ['a', 'b']], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('motivo', 'data_no_es_objeto');
        $this->postJson($ruta . '3', ['status' => 'completed', 'nota' => str_repeat('a', 1001)], $this->headers())->assertStatus(422);
        $this->postJson(self::RUTA . '/999999999/stages/3', ['status' => 'completed'], $this->headers())->assertStatus(404);

        $this->assertSame($eventos_antes, AgentImplementationEvent::where('agent_implementation_id', $id)->count(), 'Los rechazos no escriben nada.');
        $this->assertSame('pending', AgentImplementationStage::where('agent_implementation_id', $id)->where('stage_number', 3)->value('status'));

        /* Todas cerradas: la actual queda en la 8. */
        for ($n = 1; $n <= 8; $n++) {
            $this->postJson($ruta . $n, ['status' => $n === 2 ? 'skipped' : 'completed'], $this->headers())->assertStatus(200);
        }
        $this->assertSame(8, (int) AgentImplementation::find($id)->current_stage);
    }

    /**
     * `whatsapp_ops` (la memoria interna de los envíos) no sale en el estado aunque esté en la base.
     *
     * @return void
     */
    public function test_el_estado_no_muestra_whatsapp_ops(): void
    {
        $client = $this->crear_cliente('Kiosco Agentes');
        $id     = $this->alta($client->id);

        $etapa       = AgentImplementationStage::where('agent_implementation_id', $id)->where('stage_number', 1)->first();
        $etapa->data = ['camino' => 'propio', 'whatsapp_ops' => ['op-12345678' => ['huella' => 'abc', 'estado' => 'sent']]];
        $etapa->save();

        $respuesta = $this->getJson(self::RUTA . '/' . $id, $this->headers());
        $respuesta->assertStatus(200)->assertJsonPath('model.etapas.0.data', ['camino' => 'propio']);
        $this->assertStringNotContainsString('whatsapp_ops', (string) $respuesta->getContent());
    }

    /**
     * Bitácora: 201 con el evento, defaults `nota`/`claude`, etapa opcional 1–8, kind y source de la lista,
     * texto obligatorio ≤ 1000. Los eventos salen en el estado, más nuevos primero.
     *
     * @return void
     */
    public function test_eventos(): void
    {
        $client = $this->crear_cliente('Librería Agentes');
        $id     = $this->alta($client->id);
        $ruta   = self::RUTA . '/' . $id . '/events';

        $this->postJson($ruta, ['texto' => 'Quedó en mandar la foto del DNI.'], $this->headers())
            ->assertStatus(201)
            ->assertJsonPath('event.kind', 'nota')
            ->assertJsonPath('event.source', 'claude')
            ->assertJsonPath('event.etapa', null)
            ->assertJsonPath('event.texto', 'Quedó en mandar la foto del DNI.');

        $this->postJson($ruta, ['texto' => 'Tarjeta cargada en Meta', 'etapa' => 7, 'kind' => 'dato', 'source' => 'panel'], $this->headers())
            ->assertStatus(201)
            ->assertJsonPath('event.etapa', 7)
            ->assertJsonPath('event.kind', 'dato')
            ->assertJsonPath('event.source', 'panel');

        $this->postJson($ruta, [], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['texto' => '   '], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['texto' => str_repeat('a', 1001)], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['texto' => 'x', 'etapa' => 9], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['texto' => 'x', 'kind' => 'otro'], $this->headers())->assertStatus(422);
        $this->postJson($ruta, ['texto' => 'x', 'source' => 'lucas'], $this->headers())->assertStatus(422);
        $this->postJson(self::RUTA . '/999999999/events', ['texto' => 'x'], $this->headers())->assertStatus(404);

        $this->getJson(self::RUTA . '/' . $id, $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('model.eventos.0.texto', 'Tarjeta cargada en Meta')
            ->assertJsonPath('model.eventos.1.texto', 'Quedó en mandar la foto del DNI.');
    }

    /**
     * PATCH: carga los ids en sus columnas (la bitácora nombra qué se cargó, sin los valores), un null
     * explícito borra, y `status` cierra con `completed_at`. Un estado que no sea de cierre es 422.
     *
     * @return void
     */
    public function test_patch_de_ids_y_cierre(): void
    {
        $client = $this->crear_cliente('Almacén Agentes');
        $id     = $this->alta($client->id, 'nuevo');
        $ruta   = self::RUTA . '/' . $id;

        $respuesta = $this->patchJson($ruta, [
            'kapso_customer_id'     => 'kapso-cust-123',
            'phone_number_id'       => '109876543210987',
            'waba_id'               => '209876543210987',
            'business_portfolio_id' => '309876543210987',
            'camino'                => 'propio',
        ], $this->headers());
        $respuesta->assertStatus(200)
            ->assertJsonPath('model.kapso_customer_id', 'kapso-cust-123')
            ->assertJsonPath('model.phone_number_id', '109876543210987')
            ->assertJsonPath('model.waba_id', '209876543210987')
            ->assertJsonPath('model.business_portfolio_id', '309876543210987')
            ->assertJsonPath('model.camino', 'propio')
            ->assertJsonPath('model.status', 'in_progress')
            ->assertJsonPath('model.eventos.0.kind', 'dato');

        $textos = AgentImplementationEvent::where('agent_implementation_id', $id)->pluck('texto')->implode(' | ');
        $this->assertStringContainsString('phone_number_id', $textos);
        $this->assertStringNotContainsString('109876543210987', $textos, 'La bitácora no guarda los ids de Meta.');
        $this->assertStringNotContainsString('209876543210987', $textos);

        $this->patchJson($ruta, ['waba_id' => null], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('model.waba_id', null)
            ->assertJsonPath('model.phone_number_id', '109876543210987');

        $this->patchJson($ruta, ['status' => 'in_progress'], $this->headers())->assertStatus(422);
        $this->patchJson($ruta, ['phone_number_id' => str_repeat('1', 61)], $this->headers())->assertStatus(422);
        $this->patchJson($ruta, ['otro' => 'x'], $this->headers())->assertStatus(422);
        $this->patchJson(self::RUTA . '/999999999', ['status' => 'completed'], $this->headers())->assertStatus(404);

        $cierre = $this->patchJson($ruta, ['status' => 'completed'], $this->headers());
        $cierre->assertStatus(200)
            ->assertJsonPath('model.status', 'completed')
            ->assertJsonPath('model.eventos.0.kind', 'avance');
        $this->assertNotNull($cierre->json('model.completed_at'));
    }

    /**
     * Cerrada (completed o cancelled): `stages/{n}` y un cambio de `status` son 409 `implementacion_cerrada` sin
     * escribir nada; el mismo status es un no-op (un reintento del cierre no falla); los eventos y los demás
     * campos se aceptan.
     *
     * @return void
     */
    public function test_cerrada_no_acepta_etapas_ni_cambio_de_estado_pero_si_eventos(): void
    {
        foreach (['completed' => 'cancelled', 'cancelled' => 'completed'] as $cierre => $otro) {
            $client = $this->crear_cliente('Cerrada ' . $cierre);
            $id     = $this->alta($client->id);
            $ruta   = self::RUTA . '/' . $id;

            $this->patchJson($ruta, ['status' => $cierre], $this->headers())->assertStatus(200);
            $eventos = AgentImplementationEvent::where('agent_implementation_id', $id)->count();

            $this->postJson($ruta . '/stages/2', ['status' => 'completed', 'data' => ['chats_leidos' => 10]], $this->headers())
                ->assertStatus(409)
                ->assertJsonPath('error', 'implementacion_cerrada')
                ->assertJsonPath('id', $id)
                ->assertJsonPath('status', $cierre);
            $this->assertSame('pending', AgentImplementationStage::where('agent_implementation_id', $id)->where('stage_number', 2)->value('status'));

            $this->patchJson($ruta, ['status' => $otro], $this->headers())
                ->assertStatus(409)
                ->assertJsonPath('error', 'implementacion_cerrada');
            $this->assertSame($cierre, AgentImplementation::find($id)->status);
            $this->assertSame($eventos, AgentImplementationEvent::where('agent_implementation_id', $id)->count(), 'Los 409 no escriben nada.');

            $this->patchJson($ruta, ['status' => $cierre], $this->headers())->assertStatus(200)->assertJsonPath('model.status', $cierre);

            $this->postJson($ruta . '/events', ['texto' => 'El dueño avisó que el agente anda bien.'], $this->headers())->assertStatus(201);
            $this->patchJson($ruta, ['kapso_customer_id' => 'kapso-corregido'], $this->headers())
                ->assertStatus(200)
                ->assertJsonPath('model.kapso_customer_id', 'kapso-corregido');
        }
    }

    /**
     * `contact_phone`: se carga y se borra por PATCH, sale enmascarado en el estado y la bitácora nombra que se
     * cargó pero nunca el número.
     *
     * @return void
     */
    public function test_contact_phone_por_patch_enmascarado_y_fuera_de_la_bitacora(): void
    {
        $client = $this->crear_cliente('Contacto Agentes');
        $id     = $this->alta($client->id);
        $ruta   = self::RUTA . '/' . $id;

        $respuesta = $this->patchJson($ruta, ['contact_phone' => '+54 9 341 555-8877'], $this->headers());
        $respuesta->assertStatus(200)->assertJsonPath('model.contact_phone', '•••••••••8877');
        $this->assertSame('+54 9 341 555-8877', AgentImplementation::find($id)->contact_phone);
        $this->assertStringNotContainsString('5558877', $this->cuerpo($respuesta));

        $this->patchJson($ruta, ['contact_phone' => str_repeat('1', 31)], $this->headers())->assertStatus(422);

        $this->patchJson($ruta, ['contact_phone' => null], $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('model.contact_phone', null);
        $this->assertNull(AgentImplementation::find($id)->contact_phone);

        $bitacora = AgentImplementationEvent::where('agent_implementation_id', $id)->pluck('texto')->implode(' | ');
        $this->assertStringContainsString('contact_phone', $bitacora);
        $this->assertStringNotContainsString('8877', $bitacora);
    }

    /**
     * Lecturas: GET por id (404 si no existe), listado con `{data: [...]}` filtrado por cliente y por
     * estado, y la lista blanca (un parámetro de más es 422).
     *
     * @return void
     */
    public function test_lecturas(): void
    {
        $uno  = $this->crear_cliente('Cliente Uno Agentes');
        $dos  = $this->crear_cliente('Cliente Dos Agentes');
        $id_1 = $this->alta($uno->id);
        $id_2 = $this->alta($dos->id, 'nuevo');
        $this->patchJson(self::RUTA . '/' . $id_2, ['status' => 'completed'], $this->headers())->assertStatus(200);

        $this->getJson(self::RUTA . '/' . $id_1, $this->headers())->assertStatus(200)->assertJsonPath('model.id', $id_1);
        $this->getJson(self::RUTA . '/999999999', $this->headers())->assertStatus(404);
        $this->getJson(self::RUTA . '/' . $id_1 . '?include=todo', $this->headers())->assertStatus(422);

        $this->getJson(self::RUTA . '?client_id=' . $uno->id, $this->headers())
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id_1);

        $this->getJson(self::RUTA . '?client_id=' . $dos->id . '&status=in_progress', $this->headers())
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson(self::RUTA . '?client_id=' . $dos->id . '&status=completed', $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $id_2);

        $this->getJson(self::RUTA . '?status=rara', $this->headers())->assertStatus(422);
        $this->getJson(self::RUTA . '?lead_id=1', $this->headers())->assertStatus(422);
        $this->getJson(self::RUTA, ['Accept' => 'application/json'])->assertStatus(401);
    }
}

<?php

namespace Tests\Feature\LeadsReferidosCompromisos;

use App\Models\Lead;
use App\Models\LeadMessage;

/**
 * Alta de un lead de otro medio DESDE CLAUDE (`POST claude/leads` y `POST claude/leads/{id}/contexto-previo`):
 * criterio CA7 de la etapa E1 de la misión leads-referidos-compromisos.
 *
 * Es la puerta que usan las skills `whatsapp-personal` y `chequeo-de-leads` cuando Lucas le dice a alguien por su
 * WhatsApp personal «pasame el teléfono y lo sumo» (el caso de Ro). Mismo contrato de seguridad que el resto de
 * `claude/*`:
 *  - sin `X-Claude-Task-Key` válida, 401 y no se escribe nada;
 *  - `dry_run` en true por defecto: devuelve lo que haría y NO crea el lead;
 *  - con `dry_run=false` el efecto es el mismo que el del panel (CA1);
 *  - el contexto previo se AGREGA con encabezado y fuente, nunca reemplaza, y no pasa de 60.000 caracteres.
 *
 * Supuestos del contrato (el PLAN no los fija; si la implementación elige otros nombres hay que decirlo):
 * el cuerpo de la ruta de contexto es `{texto, fuente}` y `POST claude/leads` recibe los mismos campos que el alta
 * del panel, sueltos en el cuerpo.
 *
 * Hoy están EN ROJO: las dos rutas no existen.
 */
class AltaDeLeadExternoPorClaudeTest extends BaseDeReferidosYCompromisos
{
    /**
     * Cabeceras con la clave de ingesta de Claude.
     *
     * @return array<string, string>
     */
    private function cabeceras(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE_DE_CLAUDE, 'Accept' => 'application/json'];
    }

    // ------------------------------------------------------------------------------------------
    // Clave
    // ------------------------------------------------------------------------------------------

    /**
     * CA7. Sin la clave (o con una equivocada) las dos rutas responden 401 y no se crea ni se toca nada.
     *
     * @return void
     */
    public function test_sin_clave_401(): void
    {
        $this->exigir_columnas_de_referido();
        $lead = $this->crear_lead_del_tablero('nuevo', ['contexto_previo' => null]);
        $antes = Lead::query()->count();

        $cuerpo = $this->cuerpo_de_alta_externa(['dry_run' => false]);

        $this->postJson('/api/claude/leads', $cuerpo)->assertStatus(401);
        $this->postJson('/api/claude/leads', $cuerpo, ['X-Claude-Task-Key' => 'clave-equivocada'])->assertStatus(401);
        $this->postJson('/api/claude/leads/' . $lead->id . '/contexto-previo', ['texto' => 'algo', 'dry_run' => false])->assertStatus(401);

        $this->assertSame($antes, Lead::query()->count(), 'Sin clave no se crea ningún lead.');
        $this->assertNull($lead->fresh()->contexto_previo, 'Sin clave no se toca el contexto de nadie.');
    }

    // ------------------------------------------------------------------------------------------
    // Alta
    // ------------------------------------------------------------------------------------------

    /**
     * CA7. Sin `dry_run` (por defecto es true) o con `dry_run=true`: 200 con lo que haría y CERO leads nuevos ni
     * mensajes nuevos.
     *
     * @return void
     */
    public function test_dry_run_no_escribe(): void
    {
        $this->exigir_columnas_de_referido();
        $cliente = $this->crear_cliente_que_refiere();
        $cuerpo  = $this->cuerpo_de_alta_externa(['referido_por_client_id' => (int) $cliente->id]);
        $leads   = Lead::query()->count();
        $mensajes = LeadMessage::query()->count();

        $por_defecto = $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads', $cuerpo);
        $por_defecto->assertStatus(200);
        $por_defecto->assertJsonPath('dry_run', true);

        $explicito = $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads', array_merge($cuerpo, ['dry_run' => true]));
        $explicito->assertStatus(200);
        $explicito->assertJsonPath('dry_run', true);

        $this->assertSame($leads, Lead::query()->count(), 'El dry_run no crea el lead.');
        $this->assertSame($mensajes, LeadMessage::query()->count(), 'El dry_run no deja ningún evento en ningún hilo.');
        $this->assertSame([], $this->envios->plantillas);
        $this->assertSame([], $this->envios->textos);
    }

    /**
     * CA7. Con `dry_run=false`: 201 y los mismos efectos que el alta del panel (CA1): origen, quién lo refirió,
     * contexto, evento `lead_alta_externa` en el hilo y ningún WhatsApp. Un teléfono repetido sigue siendo 409.
     *
     * @return void
     */
    public function test_alta_real(): void
    {
        $this->exigir_columnas_de_referido();
        $cliente = $this->crear_cliente_que_refiere();
        $cuerpo  = $this->cuerpo_de_alta_externa(['referido_por_client_id' => (int) $cliente->id, 'dry_run' => false]);

        $respuesta = $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads', $cuerpo);

        $respuesta->assertStatus(201);
        $lead = $this->lead_por_telefono_tipeado($cuerpo['phone']);
        $this->assertNotNull($lead, 'El lead se creó.');
        $this->assertSame('referido', $lead->origen);
        $this->assertNotNull($lead->origen_calculado_at);
        $this->assertSame((int) $cliente->id, (int) $lead->referido_por_client_id);
        $this->assertSame($cuerpo['contexto_previo'], $lead->contexto_previo);
        $this->assertCount(1, $this->eventos_del_hilo($lead, 'lead_alta_externa'), 'Queda el evento del alta en el hilo.');
        $this->assertSame([], $this->envios->plantillas, 'El alta desde Claude tampoco manda WhatsApp por sí sola.');
        $this->assertSame([], $this->envios->textos);

        $repetido = $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads', $cuerpo);
        $repetido->assertStatus(409);
        $repetido->assertJsonPath('code', 'lead_existente');
        $repetido->assertJsonPath('lead_id', (int) $lead->id);
    }

    // ------------------------------------------------------------------------------------------
    // Contexto previo
    // ------------------------------------------------------------------------------------------

    /**
     * CA7. `POST claude/leads/{id}/contexto-previo` AGREGA el texto con un encabezado que lleva la fecha y la
     * fuente (no reemplaza lo anterior); pasar de 60.000 caracteres en total es 422 y no cambia nada; un lead que
     * no existe es 404.
     *
     * @return void
     */
    public function test_contexto_previo_se_agrega(): void
    {
        $this->exigir_columnas_de_referido();
        $lead = $this->crear_lead_del_tablero('nuevo', ['origen' => 'referido', 'contexto_previo' => 'Lo que se habló en la primera charla.']);
        $ruta = '/api/claude/leads/' . $lead->id . '/contexto-previo';

        $respuesta = $this->withHeaders($this->cabeceras())->postJson($ruta, [
            'texto'   => 'El viernes me dijo que prefiere arrancar la semana que viene.',
            'fuente'  => 'whatsapp personal de Lucas',
            'dry_run' => false,
        ]);

        $respuesta->assertStatus(200);
        $contexto = (string) $lead->fresh()->contexto_previo;
        $this->assertSame(0, strpos($contexto, 'Lo que se habló en la primera charla.'), 'Lo anterior no se reemplaza: queda primero.');
        $this->assertSame(1, preg_match('/— \d{2}\/\d{2}\/\d{4} \d{2}:\d{2} \(.*whatsapp personal de Lucas.*\) —/u', $contexto), 'Lo nuevo lleva su encabezado con fecha y fuente.');
        $this->assertStringContainsString('El viernes me dijo que prefiere arrancar la semana que viene.', $contexto);

        /* Tope: 60.000 caracteres en total. */
        $casi_lleno = $this->crear_lead_del_tablero('nuevo', ['contexto_previo' => str_repeat('x', 59990)]);
        $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads/' . $casi_lleno->id . '/contexto-previo', [
            'texto' => str_repeat('y', 200), 'fuente' => 'prueba', 'dry_run' => false,
        ])->assertStatus(422);
        $this->assertSame(str_repeat('x', 59990), (string) $casi_lleno->fresh()->contexto_previo, 'Un pedido que pasa el tope no cambia nada.');

        $this->withHeaders($this->cabeceras())->postJson('/api/claude/leads/99999999/contexto-previo', [
            'texto' => 'hola', 'fuente' => 'prueba', 'dry_run' => false,
        ])->assertStatus(404);
    }
}

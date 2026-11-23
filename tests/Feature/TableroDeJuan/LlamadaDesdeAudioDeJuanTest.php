<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\Admin;
use App\Models\AdminTask;
use App\Models\PipelineActivity;
use App\Models\SupportTicket;
use App\Services\LeadAiService;
use App\Services\Prueba\PruebaContextoSoporteService;
use App\Services\SupportPruebaContextService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PanelAgent\AgentePanelTestHelpers;

/**
 * CA37 a CA40 de la etapa E1b (misión embudo-juan-pipeline, agregado del 23/11): Juan le manda un audio a Lucas y el
 * agente de OpenClaw (un admin con `es_agente` y token de Sanctum) registra la llamada en el tablero en su nombre.
 *
 * Es un test de camino completo: el pedido sale con un token real en el header `Authorization` (helpers de
 * `PanelAgent\AgentePanelTestHelpers`), pasa por `auth:sanctum`, por el guard del agente (que audita) y llega al
 * controlador de `POST lead/{id}/llamadas`. Qué se protege:
 *  1. CA37 — La llamada queda a nombre de Juan (`admin_id`), con el agente como quien la cargó
 *     (`cargada_por_admin_id`), `origen = audio_juan`, la transcripción, y con los mismos efectos de una llamada manual.
 *  2. CA38 — Idempotencia por `(lead, origen_clave)`: repetir el audio responde 200 `duplicada: true` SIN ningún efecto
 *     (no mueve la tarjeta, no cierra tareas nuevas, no duplica la nota). La misma clave con otro lead es otra llamada.
 *  3. CA39 — Validaciones del origen y del vendedor; un humano no puede cargar la llamada «por otro».
 *  4. CA40 — La transcripción llega a Martín (1.500 caracteres), a Soporte (4.000) y al historial (nota de 5.000).
 *
 * Hoy están EN ROJO: la ruta, la tabla `lead_llamadas` y las columnas del origen no existen.
 */
class LlamadaDesdeAudioDeJuanTest extends BaseDelTableroDeJuan
{
    use AgentePanelTestHelpers;

    /** Clave de ingesta de las requests `claude/*` de este test. */
    const CLAVE_DE_INGESTA = 'clave-de-prueba-audio-de-juan';

    /** @var Admin El agente de OpenClaw (`es_agente`). */
    private $agente;

    /** @var string Token en texto plano del agente. */
    private $token_del_agente;

    /**
     * Siembra el tablero y crea al agente con su token.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sembrar_el_tablero();

        $this->agente           = $this->crear_agente('Agente de WhatsApp');
        $this->token_del_agente = $this->token_de($this->agente);
    }

    // ------------------------------------------------------------------------------------------
    // Ayudantes
    // ------------------------------------------------------------------------------------------

    /**
     * El payload de un audio de Juan.
     *
     * @param array<string, mixed> $cambios Pisan los defaults.
     *
     * @return array<string, mixed>
     */
    private function payload_de_audio(array $cambios = []): array
    {
        return array_merge([
            'atendio'       => true,
            'resultado'     => 'sigue_solo',
            'origen'        => 'audio_juan',
            'origen_clave'  => 'wa:abc',
            'notas'         => 'Resumen',
            'transcripcion' => 'Texto de la transcripción del audio.',
            'cotizado'      => 'Gestión $628.400',
        ], $cambios);
    }

    /**
     * Registra una llamada con el token del agente.
     *
     * @param \App\Models\Lead     $lead
     * @param array<string, mixed> $payload
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function cargar_como_agente($lead, array $payload)
    {
        return $this->pedir_como($this->token_del_agente, 'POST', '/api/admin/lead/' . $lead->id . '/llamadas', $payload);
    }

    /**
     * Cuántas llamadas hay guardadas en total.
     *
     * @return int
     */
    private function total_de_llamadas(): int
    {
        return (int) DB::table('lead_llamadas')->count();
    }

    // ------------------------------------------------------------------------------------------
    // CA37
    // ------------------------------------------------------------------------------------------

    /**
     * CA37. El agente carga la llamada en nombre de Juan: 201, `admin_id` = Juan, `cargada_por_admin_id` = el agente,
     * `origen = audio_juan`, la tarjeta en «En prueba por su cuenta», las tareas del embudo cerradas por Juan y una fila de
     * auditoría del agente con categoría `escritura`.
     *
     * @return void
     */
    public function test_el_agente_carga_en_nombre_de_juan(): void
    {
        $lead    = $this->crear_lead_del_tablero('contactado');
        $tarjeta = $this->dar_de_alta_en($lead, 'interesados');
        $tarea_a = $this->crear_tarea(['lead_id' => $lead->id, 'created_via' => 'embudo_prueba_creada']);
        $tarea_b = $this->crear_tarea(['lead_id' => $lead->id, 'created_via' => 'embudo_impl_pedida']);

        $respuesta = $this->cargar_como_agente($lead, $this->payload_de_audio());

        $respuesta->assertStatus(201);

        $fila = DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->first();
        $this->assertNotNull($fila, 'Queda la llamada.');
        $this->assertSame((int) $this->juan->id, (int) $fila->admin_id, 'La llamada es de Juan.');
        $this->assertSame((int) $this->agente->id, (int) $fila->cargada_por_admin_id, 'La cargó el agente.');
        $this->assertSame('audio_juan', $fila->origen);
        $this->assertSame('wa:abc', $fila->origen_clave);
        $this->assertSame('Texto de la transcripción del audio.', $fila->transcripcion);

        /* La respuesta trae el origen, quién la cargó y la transcripción. */
        $this->assertSame('audio_juan', $respuesta->json('llamada.origen'));
        $this->assertSame((int) $this->agente->id, (int) $respuesta->json('llamada.cargada_por.id'));
        $this->assertSame('Agente de WhatsApp', $respuesta->json('llamada.cargada_por.name'));
        $this->assertSame('Texto de la transcripción del audio.', $respuesta->json('llamada.transcripcion'));

        /* Los mismos efectos que una llamada manual. */
        $this->assertSame('en_prueba_sola', $this->clave_de_la_columna($tarjeta));
        foreach ([$tarea_a, $tarea_b] as $tarea) {
            $this->assertTrue((bool) $tarea->fresh()->is_done, 'La tarea del embudo queda cerrada.');
            $this->assertSame((int) $this->juan->id, (int) $tarea->fresh()->done_by_admin_id, 'La cierra Juan, no el agente.');
        }

        /* La auditoría del agente. */
        $auditoria = $this->auditoria_de($this->agente)->filter(function ($fila_de_auditoria) {
            return $this->contiene($fila_de_auditoria->ruta, 'llamadas');
        });
        $this->assertCount(1, $auditoria, 'El guard del agente audita el pedido.');
        $this->assertSame('escritura', $auditoria->first()->categoria);
    }

    // ------------------------------------------------------------------------------------------
    // CA38
    // ------------------------------------------------------------------------------------------

    /**
     * CA38. El mismo POST (mismo lead, misma clave) responde 200 con `duplicada: true`, la misma llamada y SIN efectos: una
     * fila, una nota, la tarjeta no se mueve y una tarea del embudo nueva no se cierra.
     *
     * @return void
     */
    public function test_misma_clave_no_duplica(): void
    {
        $lead    = $this->crear_lead_del_tablero('contactado');
        $tarjeta = $this->dar_de_alta_en($lead, 'interesados');

        $primera = $this->cargar_como_agente($lead, $this->payload_de_audio());
        $primera->assertStatus(201);
        $id_de_la_llamada  = (int) $primera->json('llamada.id');
        $entro_a_la_etapa  = (string) $tarjeta->fresh()->stage_entered_at;

        /* Pasa una hora, aparece una tarea nueva del embudo y el audio se vuelve a procesar. */
        $this->clavar_el_reloj('2026-11-23 13:00:00');
        $tarea_nueva = $this->crear_tarea(['lead_id' => $lead->id, 'created_via' => 'embudo_impl_pedida']);

        $segunda = $this->cargar_como_agente($lead, $this->payload_de_audio());

        $segunda->assertStatus(200);
        $this->assertTrue((bool) $segunda->json('duplicada'), 'Marca la respuesta como duplicada.');
        $this->assertSame($id_de_la_llamada, (int) $segunda->json('llamada.id'), 'Devuelve la llamada existente.');
        $this->assertNotNull($segunda->json('oportunidad'), 'Devuelve también la tarjeta.');

        $this->assertSame(1, (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->count(), 'Sigue habiendo una sola fila.');
        $this->assertSame(
            1,
            PipelineActivity::query()->where('opportunity_id', $tarjeta->id)->where('type', 'note')->where('channel', 'call')->count(),
            'Sigue habiendo una sola nota.'
        );
        $this->assertSame($entro_a_la_etapa, (string) $tarjeta->fresh()->stage_entered_at, 'La tarjeta no cambió de etapa.');
        $this->assertFalse((bool) $tarea_nueva->fresh()->is_done, 'Un duplicado no cierra tareas.');
    }

    /**
     * CA38. La misma clave con OTRO lead crea otra llamada (un audio puede hablar de dos leads): 201 y dos filas en total.
     *
     * @return void
     */
    public function test_misma_clave_otro_lead(): void
    {
        $uno  = $this->crear_lead_del_tablero('contactado');
        $otro = $this->crear_lead_del_tablero('contactado');

        $this->cargar_como_agente($uno, $this->payload_de_audio())->assertStatus(201);
        $this->cargar_como_agente($otro, $this->payload_de_audio())->assertStatus(201);

        $this->assertSame(2, $this->total_de_llamadas(), 'Dos llamadas: una por lead.');
        $this->assertSame(1, (int) DB::table('lead_llamadas')->where('lead_id', (int) $otro->id)->count());
    }

    // ------------------------------------------------------------------------------------------
    // CA39
    // ------------------------------------------------------------------------------------------

    /**
     * CA39. Validaciones del agente: `audio_juan` sin clave, un origen que no existe, y un `hecha_por_admin_id` que no es
     * vendedor dan 422 y no dejan rastro; con un vendedor `es_vendedor` la llamada queda a su nombre; sin ningún vendedor, 422
     * «No hay un vendedor…».
     *
     * @return void
     */
    public function test_validaciones_de_origen_y_vendedor(): void
    {
        $lead = $this->crear_lead_del_tablero('contactado');
        $comun = $this->crear_admin_del_tablero('Admin común');

        $this->cargar_como_agente($lead, $this->payload_de_audio(['origen_clave' => null]))->assertStatus(422);
        $this->cargar_como_agente($lead, $this->payload_de_audio(['origen' => 'otro']))->assertStatus(422);
        $this->cargar_como_agente($lead, $this->payload_de_audio(['hecha_por_admin_id' => $comun->id]))->assertStatus(422);
        $this->assertSame(0, $this->total_de_llamadas(), 'Ninguna de las tres deja rastro.');

        /* Un vendedor (`es_vendedor`) sirve igual que el perfil Sólo Leads. */
        $vendedor = $this->crear_admin_del_tablero('Vendedor');
        $vendedor->es_vendedor = true;
        $vendedor->save();

        $this->cargar_como_agente($lead, $this->payload_de_audio(['hecha_por_admin_id' => $vendedor->id]))->assertStatus(201);
        $this->assertSame((int) $vendedor->id, (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->value('admin_id'));

        /* Sin ningún vendedor en el equipo y sin `hecha_por_admin_id`: 422. */
        Admin::query()->update(['solo_leads' => false, 'es_vendedor' => false]);
        $otro_lead = $this->crear_lead_del_tablero('contactado');

        $respuesta = $this->cargar_como_agente($otro_lead, $this->payload_de_audio(['origen_clave' => 'wa:sin-vendedor']));

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('No hay un vendedor', (string) $respuesta->getContent());
        $this->assertSame(0, (int) DB::table('lead_llamadas')->where('lead_id', (int) $otro_lead->id)->count());
    }

    /**
     * CA39. Un humano (Lucas) que manda `hecha_por_admin_id` NO puede cargar la llamada «por otro»: el campo se ignora, es 201
     * y `admin_id` es el propio Lucas.
     *
     * @return void
     */
    public function test_humano_no_puede_cargar_por_otro(): void
    {
        $lucas = $this->crear_humano('Lucas');
        $token = $this->token_de($lucas);

        foreach ([$this->juan, $this->crear_admin_del_tablero('Otro admin')] as $indice => $por_quien) {
            $lead = $this->crear_lead_del_tablero('contactado');

            $respuesta = $this->pedir_como($token, 'POST', '/api/admin/lead/' . $lead->id . '/llamadas', [
                'atendio'            => true,
                'resultado'          => 'sigue_solo',
                'hecha_por_admin_id' => $por_quien->id,
            ]);

            $respuesta->assertStatus(201);
            $this->assertSame(
                (int) $lucas->id,
                (int) DB::table('lead_llamadas')->where('lead_id', (int) $lead->id)->value('admin_id'),
                'Caso ' . $indice . ': la llamada es de quien la registra.'
            );
        }
    }

    // ------------------------------------------------------------------------------------------
    // CA40
    // ------------------------------------------------------------------------------------------

    /**
     * Una transcripción de 6.000 caracteres en la que cada tramo de 5 caracteres es único (`0000|`, `0001|`…), así se puede
     * comprobar exactamente cuántos caracteres llegaron.
     *
     * @return string
     */
    private function transcripcion_larga(): string
    {
        $texto = '';
        for ($numero = 0; $numero < 1200; $numero++) {
            $texto .= sprintf('%04d|', $numero);
        }

        return $texto;
    }

    /**
     * CA40. Con una transcripción de 6.000 caracteres: Martín ve «Transcripción:» con los primeros 1.500; Soporte la recibe
     * con 4.000 o menos y `origen = audio_juan`; y la nota del historial tiene 5.000 o menos y dice «por audio de Juan».
     *
     * @return void
     */
    public function test_transcripcion_en_martin_soporte_e_historial(): void
    {
        $larga   = $this->transcripcion_larga();
        $cliente = $this->crear_cliente_del_tablero(['api_url' => 'https://api-audio.test', 'api_key' => 'clave-del-cliente-audio']);
        $lead    = $this->crear_lead_del_tablero('contactado', ['promoted_client_id' => $cliente->id]);
        $tarjeta = $this->dar_de_alta_en($lead, 'interesados');

        $this->cargar_como_agente($lead, $this->payload_de_audio(['transcripcion' => $larga]))->assertStatus(201);

        /* Martín. */
        $servicio = new class extends LeadAiService {
            public function contexto(\App\Models\Lead $lead): string
            {
                return $this->build_user_content($lead, false, '', null, false);
            }
        };
        $contexto = $servicio->contexto($lead->fresh());
        $this->assertStringContainsString('Transcripción:', $contexto);
        $this->assertStringContainsString(substr($larga, 0, 1500), $contexto, 'Martín ve los primeros 1.500 caracteres.');
        $this->assertStringNotContainsString(substr($larga, 0, 1510), $contexto, 'Y no más de 1.500.');

        /* El historial de la tarjeta. */
        $nota = PipelineActivity::query()->where('opportunity_id', $tarjeta->id)->where('type', 'note')->where('channel', 'call')->first();
        $this->assertNotNull($nota, 'Hay una nota de la llamada.');
        $this->assertLessThanOrEqual(5000, mb_strlen((string) $nota->body), 'La nota se recorta a 5.000 caracteres.');
        $this->assertStringContainsString('por audio de Juan', (string) $nota->body);

        /* Soporte. */
        config(['services.claude_task_ingest.key' => self::CLAVE_DE_INGESTA]);
        PruebaContextoSoporteService::sin_extensiones();
        SupportPruebaContextService::registrar(true);
        $ticket = SupportTicket::create([
            'client_id'          => $cliente->id,
            'client_employee_id' => null,
            'client_user_id'     => 0,
            'client_user_name'   => 'Marcela',
            'assigned_admin_id'  => null,
            'name'               => null,
            'status'             => 'open',
            'source'             => 'whatsapp',
            'whatsapp_phone'     => (string) $cliente->phone,
            'opened_at'          => now(),
        ]);

        $respuesta = $this->getJson(
            '/api/claude/support-codex/tickets/' . $ticket->id,
            ['X-Claude-Task-Key' => self::CLAVE_DE_INGESTA, 'Accept' => 'application/json']
        );
        $respuesta->assertStatus(200);

        $llamada = $respuesta->json('ticket.lead_del_cliente.llamadas.0');
        $this->assertNotNull($llamada, 'Soporte recibe la llamada.');
        $this->assertSame('audio_juan', $llamada['origen']);
        $this->assertGreaterThan(0, mb_strlen((string) $llamada['transcripcion']));
        $this->assertLessThanOrEqual(4000, mb_strlen((string) $llamada['transcripcion']), 'Soporte recibe 4.000 caracteres o menos.');
        $this->assertStringStartsWith('0000|0001|', (string) $llamada['transcripcion']);
    }
}

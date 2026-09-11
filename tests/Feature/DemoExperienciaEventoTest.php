<?php

namespace Tests\Feature;

use App\Http\Controllers\DemoExperienciaController;
use App\Models\Admin;
use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /api/demo-experiencia/{clave}/evento` (misión experiencia-landing, 11/9/2026): lo que la
 * página reporta cuando un lead SIN turno la recorre como landing —la abrió, llegó al final, tocó el
 * CTA—, y cómo eso se ve después en el recorrido del panel (`pagina` en `demo-roadmap`).
 */
class DemoExperienciaEventoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * (1) Un evento válido se persiste en `demo_eventos_recibidos` (sin clip) y la PRIMERA apertura
     *     deja constancia en el hilo como evento de sistema; la segunda apertura se guarda pero no
     *     repite el mensaje.
     *
     * @return void
     */
    public function test_la_apertura_se_persiste_y_la_primera_deja_constancia_en_el_hilo(): void
    {
        $lead = $this->crear_lead_sin_turno();

        $this->postear($lead, 'pagina_abierta_sin_turno', 'uuid-apertura-1', '2026-09-11 10:15:00')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonMissing(['duplicado' => true])
            ->assertJsonMissing(['ignorado' => true]);

        $this->assertDatabaseHas('demo_eventos_recibidos', [
            'lead_id' => $lead->id,
            'uuid'    => 'uuid-apertura-1',
            'nombre'  => 'pagina_abierta_sin_turno',
            'clip_id' => null,
        ]);
        $this->assertSame(1, $this->mensajes_de_sistema($lead, 'El lead abrió su página de experiencia'));

        $this->postear($lead, 'pagina_abierta_sin_turno', 'uuid-apertura-2', '2026-09-11 11:40:00')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertSame(2, DemoEventoRecibido::where('lead_id', $lead->id)->where('nombre', 'pagina_abierta_sin_turno')->count());
        $this->assertSame(1, $this->mensajes_de_sistema($lead, 'El lead abrió su página de experiencia'), 'La segunda apertura no repite el mensaje del hilo.');
    }

    /**
     * (2) Idempotencia por `lead_id + uuid`: el mismo POST dos veces responde 200 las dos, no crea
     *     una segunda fila ni un segundo mensaje, y la segunda respuesta lo dice (`duplicado`).
     *
     * @return void
     */
    public function test_un_uuid_repetido_no_crea_nada_y_responde_duplicado(): void
    {
        $lead = $this->crear_lead_sin_turno();

        $this->postear($lead, 'cta_demo_tocado', 'uuid-cta-1')->assertStatus(200)->assertJsonPath('ok', true);
        $this->postear($lead, 'cta_demo_tocado', 'uuid-cta-1')->assertStatus(200)->assertJsonPath('duplicado', true);

        $this->assertSame(1, DemoEventoRecibido::where('lead_id', $lead->id)->count());
        $this->assertSame(1, $this->mensajes_de_sistema($lead, 'El lead pidió la demo desde su página (tocó el botón de WhatsApp)'));
    }

    /**
     * (3) El CTA deja constancia en el hilo cada vez que se toca (uuid distinto = toque distinto), y
     *     "llegó al final" se guarda sin escribir nada en el hilo.
     *
     * @return void
     */
    public function test_el_cta_deja_constancia_cada_vez_y_el_final_no_escribe_en_el_hilo(): void
    {
        $lead = $this->crear_lead_sin_turno();

        $this->postear($lead, 'pagina_final_sin_turno', 'uuid-final-1')->assertStatus(200);
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count(), '"Llegó al final" es dato para el panel, no un mensaje del hilo.');

        $this->postear($lead, 'cta_demo_tocado', 'uuid-cta-1')->assertStatus(200);
        $this->postear($lead, 'cta_demo_tocado', 'uuid-cta-2')->assertStatus(200);

        $this->assertSame(2, $this->mensajes_de_sistema($lead, 'El lead pidió la demo desde su página (tocó el botón de WhatsApp)'));
        $this->assertDatabaseHas('lead_messages', [
            'lead_id'         => $lead->id,
            'sender'          => 'sistema',
            'status'          => 'enviado',
            'is_followup'     => 0,
            'is_status_event' => 1,
            'content'         => 'El lead pidió la demo desde su página (tocó el botón de WhatsApp)',
        ]);
    }

    /**
     * (4) Un nombre fuera de la lista es 422 y no se guarda; `datos` por encima del tope, también.
     *
     * @return void
     */
    public function test_nombre_invalido_o_datos_demasiado_grandes_devuelven_422(): void
    {
        $lead = $this->crear_lead_sin_turno();

        $this->postear($lead, 'demo.ingreso', 'uuid-otro')->assertStatus(422);
        $this->postear($lead, 'cualquier_cosa', 'uuid-otro-2')->assertStatus(422);

        $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/evento', [
            'uuid'   => 'uuid-gordo',
            'nombre' => 'pagina_final_sin_turno',
            'datos'  => ['relleno' => str_repeat('x', 5000)],
        ])->assertStatus(422);

        $this->assertSame(0, DemoEventoRecibido::where('lead_id', $lead->id)->count());
    }

    /**
     * (5) Con turno asignado el evento se ignora (200, `ignorado`) sin guardar ni escribir en el
     *     hilo: una pestaña vieja no ensucia el recorrido de un lead que ya tiene demo.
     *
     * @return void
     */
    public function test_con_turno_el_evento_se_ignora_sin_guardar(): void
    {
        $lead                  = $this->crear_lead_sin_turno();
        $lead->demo_id         = 1;
        $lead->demo_date       = '2026-09-11';
        $lead->demo_start_time = '10:00';
        $lead->demo_end_time   = '11:00';
        $lead->save();

        $this->postear($lead, 'pagina_abierta_sin_turno', 'uuid-con-turno')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ignorado', true);

        $this->assertSame(0, DemoEventoRecibido::where('lead_id', $lead->id)->count());
        $this->assertSame(0, LeadMessage::where('lead_id', $lead->id)->count());
    }

    /**
     * (6) La clave resuelve igual que el resto del controller (teléfono o uuid) y una desconocida es 404.
     *
     * @return void
     */
    public function test_resuelve_por_telefono_y_una_clave_desconocida_es_404(): void
    {
        $lead = $this->crear_lead_sin_turno();

        $this->postJson('/api/demo-experiencia/5493519999999/evento', ['uuid' => 'uuid-por-tel', 'nombre' => 'pagina_abierta_sin_turno'])
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
        $this->assertDatabaseHas('demo_eventos_recibidos', ['lead_id' => $lead->id, 'uuid' => 'uuid-por-tel']);

        $this->postJson('/api/demo-experiencia/5493510000000/evento', ['uuid' => 'uuid-nadie', 'nombre' => 'pagina_abierta_sin_turno'])
            ->assertStatus(404);
    }

    /**
     * (7) `pagina` en `demo-roadmap`: null sin eventos; con eventos, el PRIMER `ocurrido_at` de cada
     *     nombre (en hora de Argentina) y la cantidad de aperturas. Campo agregado: el resto del
     *     contrato del endpoint sigue igual.
     *
     * @return void
     */
    public function test_el_recorrido_del_panel_trae_la_fila_de_la_pagina(): void
    {
        $this->autenticar();
        $lead = $this->crear_lead_sin_turno();

        $sin_eventos = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);
        $this->assertNull($sin_eventos->json('pagina'), 'Sin eventos de página, `pagina` es null.');
        $this->assertFalse((bool) $sin_eventos->json('tiene_plan'));

        $this->postear($lead, 'pagina_abierta_sin_turno', 'uuid-a-2', '2026-09-11 11:40:00')->assertStatus(200);
        $this->postear($lead, 'pagina_abierta_sin_turno', 'uuid-a-1', '2026-09-11 10:15:00')->assertStatus(200);
        $this->postear($lead, 'pagina_final_sin_turno', 'uuid-f-1', '2026-09-11 10:21:30')->assertStatus(200);

        $con_apertura = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);
        $this->assertSame([
            'abierta_at' => '2026-09-11 10:15:00',
            'final_at'   => '2026-09-11 10:21:30',
            'cta_at'     => null,
            'aperturas'  => 2,
        ], $con_apertura->json('pagina'));

        $this->postear($lead, 'cta_demo_tocado', 'uuid-c-1', '2026-09-11 10:22:00')->assertStatus(200);

        $con_cta = $this->getJson('/api/admin/lead/' . $lead->id . '/demo-roadmap')->assertStatus(200);
        $this->assertSame('2026-09-11 10:22:00', $con_cta->json('pagina.cta_at'));
        $this->assertSame(2, $con_cta->json('pagina.aperturas'));
        $this->assertSame(['completos' => 0, 'parciales' => 0, 'total' => 0], $con_cta->json('progreso'));
    }

    /**
     * (8) Sin `ocurrido_at` vale el reloj del servidor (la fila nunca queda sin fecha).
     *
     * @return void
     */
    public function test_sin_ocurrido_at_se_usa_el_reloj_del_servidor(): void
    {
        $lead = $this->crear_lead_sin_turno();

        $this->postear($lead, 'pagina_abierta_sin_turno', 'uuid-sin-fecha')->assertStatus(200);

        $evento = DemoEventoRecibido::where('lead_id', $lead->id)->where('uuid', 'uuid-sin-fecha')->first();
        $this->assertNotNull($evento->ocurrido_at);
        $this->assertSame(DemoExperienciaController::EVENTO_PAGINA_ABIERTA, $evento->nombre);
    }

    /**
     * @param Lead        $lead
     * @param string      $nombre
     * @param string      $uuid
     * @param string|null $ocurrido_at
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function postear(Lead $lead, string $nombre, string $uuid, ?string $ocurrido_at = null)
    {
        $body = ['uuid' => $uuid, 'nombre' => $nombre];
        if ($ocurrido_at !== null) {
            $body['ocurrido_at'] = $ocurrido_at;
        }

        return $this->postJson('/api/demo-experiencia/' . $lead->uuid . '/evento', $body);
    }

    /**
     * @param Lead   $lead
     * @param string $content
     *
     * @return int
     */
    private function mensajes_de_sistema(Lead $lead, string $content): int
    {
        return LeadMessage::where('lead_id', $lead->id)
            ->where('sender', 'sistema')
            ->where('is_status_event', true)
            ->where('content', $content)
            ->count();
    }

    /**
     * Lead de la dinámica nueva sin demo asignada: el que ve la página como landing.
     *
     * @return Lead
     */
    private function crear_lead_sin_turno(): Lead
    {
        $lead                   = new Lead();
        $lead->uuid             = (string) Str::uuid();
        $lead->contact_name     = 'Guillermo González';
        $lead->company_name     = 'Ferretería de prueba';
        $lead->phone            = '5493519999999';
        $lead->status           = 'contactado';
        $lead->demo_experiencia = Lead::EXPERIENCIA_NUEVA;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * @return Admin
     */
    private function autenticar(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de prueba';
        $admin->email    = 'evento-pagina-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }
}

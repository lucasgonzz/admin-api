<?php

namespace Tests\Feature\LandingAgenda;

use App\Models\Lead;
use App\Models\LeadMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Los 409 (D2, D5), los 422 (D13) y el 503 del lock de `POST /api/landing/agenda/reservas`. En todos,
 * nada se escribe: ni lead, ni evento, ni carta.
 */
class RechazosDeLaReservaTest extends BaseDeLaAgendaDeLaLanding
{
    /* ------------------------------------------------------------------ 409 */

    public function test_turno_tomado_con_los_turnos_frescos(): void
    {
        $this->ocupar($this->demo_1, '2026-10-23', '14:30', '16:30');
        $this->ocupar($this->demo_2, '2026-10-23', '15:00', '17:00');
        $leads_antes = Lead::query()->count();

        $respuesta = $this->reservar($this->cuerpo())->assertStatus(409);

        $respuesta->assertJsonPath('codigo', 'turno_tomado');
        $this->assertNotEmpty($respuesta->json('mensaje'));
        $turnos = $respuesta->json('turnos');
        $this->assertIsArray($turnos);
        $this->assertSame('2026-10-22', $turnos[0]['fecha'], 'Los mismos días del GET.');
        $manana = collect($turnos)->firstWhere('fecha', '2026-10-23');
        $this->assertNotContains('15:00', array_column($manana['horarios'], 'hora'));
        $this->assertSame($leads_antes, Lead::query()->count(), 'Un 409 no crea el lead.');
        Mail::assertNothingSent();
    }

    public function test_un_horario_que_no_esta_en_la_grilla_es_turno_tomado(): void
    {
        $this->reservar($this->cuerpo(['hora' => '15:07']))->assertStatus(409)->assertJsonPath('codigo', 'turno_tomado');
        $this->reservar($this->cuerpo(['fecha' => '2026-10-21']))->assertStatus(409)->assertJsonPath('codigo', 'turno_tomado');
        $this->reservar($this->cuerpo(['hora' => '25:00']))->assertStatus(409)->assertJsonPath('codigo', 'turno_tomado');
    }

    public function test_un_slot_sin_ventana_de_dos_horas_es_turno_tomado(): void
    {
        $this->ocupar($this->demo_1, '2026-10-23', '13:00', '15:00');
        $this->ocupar($this->demo_2, '2026-10-23', '13:00', '15:00');

        $this->reservar($this->cuerpo(['hora' => '11:00']))->assertStatus(409)->assertJsonPath('codigo', 'turno_tomado');
    }

    public function test_ya_tiene_demo_sin_revelar_el_turno(): void
    {
        Lead::create([
            'phone' => '+5493416665544', 'contact_name' => 'Marina', 'status' => 'demo_agendada',
            'demo_id' => $this->demo_1->id, 'demo_date' => '2026-10-24', 'demo_start_time' => '11:00', 'demo_end_time' => '13:00',
        ]);

        $respuesta = $this->reservar($this->cuerpo())->assertStatus(409);

        $respuesta->assertJsonPath('codigo', 'ya_tiene_demo');
        $this->assertArrayNotHasKey('turnos', $respuesta->json());
        $crudo = $respuesta->getContent();
        foreach (['24/10', '2026-10-24', '11:00', '13:00', 'sábado'] as $dato) {
            $this->assertStringNotContainsString($dato, $crudo, 'No revela el turno: ' . $dato);
        }
    }

    public function test_ya_tiene_demo_si_la_de_hoy_todavia_no_vencio(): void
    {
        /* Hoy de 08:30 a 10:30: con la gracia (10) vence a las 10:40; son las 10:00. */
        Lead::create([
            'phone' => '+5493416665544', 'status' => 'demo_agendada',
            'demo_id' => $this->demo_1->id, 'demo_date' => '2026-10-22', 'demo_start_time' => '08:30', 'demo_end_time' => '10:30',
        ]);

        $this->reservar($this->cuerpo())->assertStatus(409)->assertJsonPath('codigo', 'ya_tiene_demo');
    }

    public function test_con_la_demo_vencida_se_reserva_sobre_el_mismo_lead(): void
    {
        /* No-show del lunes: sigue en demo_pendiente_de_ingreso (el turno lo suelta solo una persona), pero
         * para el agente esa demo está vencida y se le ofrece otra. La web hace lo mismo. */
        $lead = Lead::create([
            'phone' => '+5493416665544', 'contact_name' => 'Marina', 'status' => 'demo_pendiente_de_ingreso',
            'demo_id' => $this->demo_1->id, 'demo_date' => '2026-10-19', 'demo_start_time' => '15:00', 'demo_end_time' => '17:00',
            'recordatorio_demo_enviado' => true,
        ]);
        $leads_antes = Lead::query()->count();

        $this->reservar($this->cuerpo())->assertStatus(201);

        $this->assertSame($leads_antes, Lead::query()->count());
        $lead = $lead->fresh();
        $this->assertSame('demo_agendada', $lead->status);
        $this->assertSame('2026-10-23', $lead->demo_date->format('Y-m-d'));
        $this->assertSame('15:00', substr((string) $lead->demo_start_time, 0, 5));
        $this->assertFalse((bool) $lead->recordatorio_demo_enviado, 'Como un reagendado: los relojes vuelven a cero.');
        $this->assertNotNull($lead->demo_reservada_desde_landing_at);
    }

    public function test_una_demo_de_hoy_ya_terminada_tambien_esta_vencida(): void
    {
        /* Hoy de 07:00 a 09:00: vence a las 09:10 (gracia) y son las 10:00. */
        Lead::create([
            'phone' => '+5493416665544', 'status' => 'demo_agendada',
            'demo_id' => $this->demo_1->id, 'demo_date' => '2026-10-22', 'demo_start_time' => '07:00', 'demo_end_time' => '09:00',
        ]);

        $this->reservar($this->cuerpo())->assertStatus(201);
    }

    public function test_ya_es_cliente(): void
    {
        Lead::create(['phone' => '+5493416665544', 'status' => 'cerrado_ganado']);
        $this->reservar($this->cuerpo())->assertStatus(409)->assertJsonPath('codigo', 'ya_es_cliente');
    }

    public function test_ya_es_cliente_por_estar_promovido(): void
    {
        $cliente                  = new \App\Models\Client();
        $cliente->name            = 'Cliente de prueba';
        $cliente->company_name    = 'Negocio de prueba';
        $cliente->slug            = 'agenda-landing-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10));
        $cliente->api_url         = 'https://ejemplo.test';
        $cliente->api_key         = 'clave-sembrada-' . \Illuminate\Support\Str::random(10);
        $cliente->inbound_api_key = 'clave-sembrada-inbound-' . \Illuminate\Support\Str::random(10);
        $cliente->is_active       = true;
        $cliente->save();
        Lead::create(['phone' => '+5493416665544', 'status' => 'contactado', 'promoted_client_id' => $cliente->id]);
        $this->reservar($this->cuerpo())->assertStatus(409)->assertJsonPath('codigo', 'ya_es_cliente');
    }

    public function test_ya_hizo_la_demo(): void
    {
        $lead = Lead::create(['phone' => '+5493416665544', 'status' => 'demo_realizada']);
        $this->reservar($this->cuerpo())->assertStatus(409)->assertJsonPath('codigo', 'ya_hizo_la_demo');

        $lead->update(['status' => 'closer_activo']);
        $this->reservar($this->cuerpo())->assertStatus(409)->assertJsonPath('codigo', 'ya_hizo_la_demo');
        $this->assertSame(0, LeadMessage::query()->where('lead_id', $lead->id)->count());
    }

    /* ------------------------------------------------------------------ 422 */

    public function test_una_clave_extra_es_422(): void
    {
        $this->reservar($this->cuerpo(['status' => 'cerrado_ganado']))
            ->assertStatus(422)
            ->assertJsonPath('mensaje', 'Hay campos no admitidos.');
        $this->reservar($this->cuerpo(['atribucion' => ['utm_source' => 'fb', 'lead_id' => '1']]))
            ->assertStatus(422)
            ->assertJsonPath('mensaje', 'Hay campos no admitidos.');
        $this->assertSame(0, Lead::query()->where('phone', '+5493416665544')->count());
    }

    public function test_el_honeypot_lleno_es_422_generico_y_vacio_pasa(): void
    {
        $lleno = $this->reservar($this->cuerpo(['sitio_web' => 'https://spam.example']))->assertStatus(422);
        $this->assertSame([], (array) $lleno->json('errores'), 'Genérico: no dice qué filtro saltó.');
        $this->assertSame(0, Lead::query()->where('phone', '+5493416665544')->count());

        /* La landing manda siempre el campo, vacío. */
        $this->reservar($this->cuerpo(['sitio_web' => '']))->assertStatus(201);
    }

    public function test_abierto_hace_ms_corto_o_ausente_es_422_generico(): void
    {
        $this->reservar($this->cuerpo(['abierto_hace_ms' => 1200]))->assertStatus(422);
        $cuerpo = $this->cuerpo();
        unset($cuerpo['abierto_hace_ms']);
        $this->reservar($cuerpo)->assertStatus(422);
        $this->reservar($this->cuerpo(['abierto_hace_ms' => 'mucho']))->assertStatus(422);
        $this->assertSame(0, Lead::query()->where('phone', '+5493416665544')->count());
    }

    public function test_telefono_invalido_es_422_con_el_campo(): void
    {
        foreach (['1234', '+1 555 123 4567', '0341 4123'] as $telefono) {
            $respuesta = $this->reservar($this->cuerpo(['whatsapp' => $telefono]))->assertStatus(422);
            $this->assertArrayHasKey('whatsapp', $respuesta->json('errores'), 'Teléfono ' . $telefono);
        }
    }

    public function test_nombre_con_caracteres_de_control_es_422_y_los_espacios_se_colapsan(): void
    {
        $respuesta = $this->reservar($this->cuerpo(['nombre' => "Marina" . chr(10) . "López"]))->assertStatus(422);
        $this->assertArrayHasKey('nombre', $respuesta->json('errores'));
        $respuesta = $this->reservar($this->cuerpo(['nombre' => "Marina" . chr(9) . "López"]))->assertStatus(422);
        $this->assertArrayHasKey('nombre', $respuesta->json('errores'));

        $this->reservar($this->cuerpo(['nombre' => '  Marina      López  ']))->assertStatus(201);
        $this->assertSame('Marina López', Lead::query()->where('phone', '+5493416665544')->value('contact_name'));
    }

    public function test_rubro_invalido_es_422_con_el_campo(): void
    {
        $respuesta = $this->reservar($this->cuerpo(['rubro' => 'panaderia']))->assertStatus(422);
        $this->assertArrayHasKey('rubro', $respuesta->json('errores'));
        $this->assertNotEmpty($respuesta->json('mensaje'));
    }

    public function test_otros_campos_invalidos(): void
    {
        $respuesta = $this->reservar($this->cuerpo([
            'nombre'           => 'M',
            'email'            => 'no-es-un-correo',
            'fecha'            => '23/10/2026',
            'hora'             => '3pm',
            'fbp'              => 'cualquier-cosa',
            'event_source_url' => 'https://otro-sitio.example/landing',
        ]))->assertStatus(422);

        foreach (['nombre', 'email', 'fecha', 'hora'] as $campo) {
            $this->assertArrayHasKey($campo, $respuesta->json('errores'), 'Campo ' . $campo);
        }
        /* Segunda vuelta (🟡16): los de medición no son errores del visitante; se descartan en silencio. */
        $this->assertArrayNotHasKey('fbp', $respuesta->json('errores'));
        $this->assertArrayNotHasKey('event_source_url', $respuesta->json('errores'));
    }

    public function test_fbp_fbc_y_url_invalidos_se_descartan_sin_tumbar_la_reserva(): void
    {
        $this->reservar($this->cuerpo([
            'fbp'              => 'cualquier-cosa',
            'fbc'              => ['no', 'es', 'texto'],
            'event_source_url' => 'https://otro-sitio.example/landing',
        ]))->assertStatus(201);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $dato = $request->data()['data'][0] ?? [];

            return ($dato['event_name'] ?? '') === 'Lead'
                && ($dato['action_source'] ?? '') === 'chat'
                && ! isset($dato['event_source_url'])
                && ! isset($dato['user_data']['fbp'])
                && strpos((string) ($dato['user_data']['fbc'] ?? ''), 'IwAR123abcXYZ') !== false;
        });
    }

    /* ------------------------------------------------------------------ 503 */

    public function test_sin_el_lock_de_asignacion_es_503_reintentar(): void
    {
        /* `block()` mide la espera con el reloj de Carbon: congelado, esperaría para siempre. El lock se
         * toma con el reloj real (la reserva no llega a mirar la fecha: corta antes). */
        \Carbon\Carbon::setTestNow();
        $lock = Cache::lock('demo_asignacion_directa', 30);
        $this->assertTrue((bool) $lock->get());

        try {
            $this->reservar($this->cuerpo())->assertStatus(503)->assertJsonPath('codigo', 'reintentar');
        } finally {
            $lock->release();
        }
        $this->assertSame(0, Lead::query()->where('phone', '+5493416665544')->count());
    }
}

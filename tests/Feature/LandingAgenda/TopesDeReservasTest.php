<?php

namespace Tests\Feature\LandingAgenda;

use App\Models\Lead;

/**
 * Segunda vuelta, 🟠3: el tope GLOBAL de reservas efectivas (todas las IP juntas) y el throttle por IP.
 */
class TopesDeReservasTest extends BaseDeLaAgendaDeLaLanding
{
    public function test_al_pasar_el_tope_global_por_hora_es_429_con_el_mensaje_de_whatsapp(): void
    {
        config(['landing_agenda.reservas_globales_por_hora' => 1]);

        $this->reservar($this->cuerpo())->assertStatus(201);
        $leads_antes = Lead::query()->count();

        $this->reservar($this->cuerpo(['whatsapp' => '+5493517770000', 'hora' => '16:00']))
            ->assertStatus(429)
            ->assertExactJson(['mensaje' => 'Ahora no podemos tomar más reservas desde la web. Escribinos por WhatsApp y te la agendamos.']);
        $this->assertSame($leads_antes, Lead::query()->count(), 'Un 429 no crea nada.');
    }

    public function test_el_tope_por_dia_tambien_corta(): void
    {
        config(['landing_agenda.reservas_globales_por_dia' => 1]);

        $this->reservar($this->cuerpo())->assertStatus(201);
        $this->reservar($this->cuerpo(['whatsapp' => '+5493517770000', 'hora' => '16:00']))->assertStatus(429);
    }

    public function test_los_intentos_que_no_reservan_no_gastan_el_cupo_global(): void
    {
        config(['landing_agenda.reservas_globales_por_hora' => 1]);
        Lead::create(['phone' => '+5493517770000', 'status' => 'demo_realizada']);

        $this->reservar($this->cuerpo(['rubro' => 'panaderia']))->assertStatus(422);
        $this->reservar($this->cuerpo(['whatsapp' => '+5493517770000']))->assertStatus(409);
        $this->reservar($this->cuerpo(['hora' => '15:07']))->assertStatus(409);

        $this->reservar($this->cuerpo())->assertStatus(201);
    }

    public function test_el_throttle_por_ip(): void
    {
        $this->assertSame(10, config('landing_agenda.reservas_por_hora'));
        config(['landing_agenda.reservas_por_hora' => 1]);

        $this->reservar($this->cuerpo(['rubro' => 'panaderia']))->assertStatus(422);
        $this->reservar($this->cuerpo())->assertStatus(429);
    }
}

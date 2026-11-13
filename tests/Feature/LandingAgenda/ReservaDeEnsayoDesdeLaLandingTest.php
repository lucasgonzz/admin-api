<?php

namespace Tests\Feature\LandingAgenda;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Services\Prueba\PruebaSettings;
use Illuminate\Support\Facades\Http;

/**
 * La reserva de demo de un ENSAYO desde la landing (misión ensayos-sin-eventos-meta, 13/11/2026): con el correo en
 * `prueba_ensayo_correos`, sus `Lead` y `Schedule` quedan `omitido`, no salen por la API de Conversiones y la respuesta
 * no le devuelve ids a la landing (sin id, el píxel no los dispara). La reserva real sigue igual (ver
 * `ReservaDesdeLaLandingTest`).
 */
class ReservaDeEnsayoDesdeLaLandingTest extends BaseDeLaAgendaDeLaLanding
{
    public function test_la_reserva_de_un_ensayo_no_devuelve_ids_ni_manda_nada_a_meta(): void
    {
        AdminSetting::set(PruebaSettings::KEY_ENSAYO_CORREOS, 'marina@example.com');

        $respuesta = $this->reservar($this->cuerpo())->assertStatus(201);

        $this->assertSame([], $respuesta->json('meta_eventos'));
        $lead  = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        $filas = LeadEventoMeta::query()->where('lead_id', $lead->id)->orderBy('id')->get();
        $this->assertSame(['Lead', 'Schedule'], $filas->pluck('evento')->all());
        foreach ($filas as $fila) {
            $this->assertSame(LeadEventoMeta::ESTADO_OMITIDO, $fila->estado);
            $this->assertSame(LeadEventoMeta::MOTIVO_ENSAYO, $fila->ultimo_error);
            $this->assertNull($fila->contexto_web);
        }
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'graph.facebook.com') !== false;
        });
    }

    public function test_con_otro_correo_en_la_lista_la_reserva_devuelve_sus_ids_como_siempre(): void
    {
        AdminSetting::set(PruebaSettings::KEY_ENSAYO_CORREOS, 'otro@example.com');

        $respuesta = $this->reservar($this->cuerpo())->assertStatus(201);

        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        $this->assertSame([
            ['nombre' => 'Lead', 'event_id' => 'cc-lead-' . $lead->id . '-web-lead'],
            ['nombre' => 'Schedule', 'event_id' => 'cc-lead-' . $lead->id . '-web-schedule'],
        ], $respuesta->json('meta_eventos'));
        Http::assertSent(function ($request) {
            return strpos($request->url(), 'graph.facebook.com') !== false;
        });
    }
}

<?php

namespace Tests\Feature\LandingAgenda;

use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Services\MetaConversionesService;
use Illuminate\Support\Facades\Http;

/**
 * Segunda vuelta, 🟡12: el contexto del navegador (IP, user agent, cookies, URL) solo se guarda en las
 * filas del canal web y solo mientras hace falta para mandarlas: enviado o descartado, se borra.
 */
class ContextoWebDeMetaTest extends BaseDeLaAgendaDeLaLanding
{
    public function test_enviado_el_evento_el_contexto_se_borra(): void
    {
        $this->reservar($this->cuerpo())->assertStatus(201);
        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();

        Http::assertSent(function ($request) {
            return (($request->data()['data'][0]['action_source'] ?? '') === 'website');
        });
        $filas = LeadEventoMeta::query()->where('lead_id', $lead->id)->get();
        $this->assertCount(2, $filas);
        foreach ($filas as $fila) {
            $this->assertSame(LeadEventoMeta::ESTADO_ENVIADO, $fila->estado);
            $this->assertNull($fila->contexto_web, 'Enviado: sin IP ni user agent guardados.');
        }
    }

    public function test_un_lead_de_anuncio_no_guarda_contexto_en_sus_filas_de_whatsapp(): void
    {
        Lead::create(['phone' => '+5493416665544', 'status' => 'contactado', 'ctwa_clid' => 'ARAkLkA-clid-de-prueba']);

        $this->reservar($this->cuerpo())->assertStatus(201)->assertJsonPath('meta_eventos', []);

        $filas = LeadEventoMeta::query()->where('lead_id', Lead::query()->where('phone', '+5493416665544')->value('id'))->get();
        $this->assertNotEmpty($filas);
        foreach ($filas as $fila) {
            $this->assertSame(LeadEventoMeta::CANAL_WHATSAPP, $fila->canal);
            $this->assertNull($fila->contexto_web);
        }
    }

    public function test_con_intentos_por_delante_el_error_conserva_el_contexto_y_agotados_lo_borra(): void
    {
        $lead     = Lead::create(['phone' => '+5493416665544', 'status' => 'nuevo']);
        $contexto = ['client_user_agent' => self::USER_AGENT, 'event_source_url' => self::URL_PAGINA];
        $crear    = function (string $evento, int $intentos) use ($lead, $contexto) {
            return LeadEventoMeta::create([
                'lead_id' => $lead->id, 'canal' => 'web', 'evento' => $evento, 'estado' => LeadEventoMeta::ESTADO_ENVIANDO,
                'event_id' => 'cc-lead-' . $lead->id . '-web-' . strtolower($evento), 'event_time' => now(),
                'intentos' => $intentos, 'contexto_web' => $contexto,
            ]);
        };
        $con_intentos = $crear('Lead', 1);
        $agotada      = $crear('Schedule', LeadEventoMeta::MAX_INTENTOS);

        $marcar_error = new \ReflectionMethod(MetaConversionesService::class, 'marcar_error');
        $marcar_error->setAccessible(true);
        $marcar_error->invoke(app(MetaConversionesService::class), $con_intentos, 'Meta caído', null);
        $marcar_error->invoke(app(MetaConversionesService::class), $agotada, 'Meta caído', null);

        $this->assertEquals($contexto, $con_intentos->fresh()->contexto_web, 'El reintento lo necesita para salir como website.');
        $this->assertNull($agotada->fresh()->contexto_web);
    }
}

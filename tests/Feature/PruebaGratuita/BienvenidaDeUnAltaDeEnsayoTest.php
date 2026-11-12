<?php

namespace Tests\Feature\PruebaGratuita;

use App\Models\PruebaWhatsappEnvio;
use App\Models\TrialInstance;
use App\Services\Prueba\PruebaAsistenteService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * La bienvenida de un alta que nace marcada como ensayo (misión cruzada prueba-e2e-campana-imperio, 12/11/2026): los
 * barridos reales saltean a la cuenta de ensayo, salvo el que retoma la bienvenida, porque completarla (el WhatsApp
 * del asistente y el correo salen cuando su sistema lo habilita) depende de ese barrido.
 */
class BienvenidaDeUnAltaDeEnsayoTest extends BaseDeLaPrueba
{
    /** @test */
    public function el_barrido_de_bienvenidas_completa_la_de_una_cuenta_de_ensayo_y_el_empujon_diario_la_saltea(): void
    {
        $espia = $this->espiar_sender();
        Mail::fake();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente(['display_phone' => '+1 201-586-6682']);
        $this->admin();
        $this->plantillas_de_la_prueba();

        $client = $this->cliente_en_prueba();
        $client->email     = 'ensayo@test.local';
        $client->es_ensayo = true;
        $client->save();
        $lead = $this->lead_en_prueba($client);
        $lead->demo_setup_status = 'exitoso';
        $lead->save();
        TrialInstance::create([
            'nombre' => 'prueba-' . Str::random(8), 'estado' => TrialInstance::ESTADO_ASIGNADA,
            'erp_vps_path' => '/home/prueba/erp', 'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id, 'lead_id' => (int) $lead->id, 'user_id' => (int) $client->user_id,
            'alias_slug' => 'ferreteria-ensayo',
        ]);

        /* Al quedar lista la instancia su sistema todavía no habilita al asistente: sale la bienvenida sin él. */
        $this->fakear_http(['*/' . PruebaAsistenteService::RUTA_DISPONIBILIDAD => Http::response([], 404)]);
        /* Desde el 12/11/2026 un ensayo no recibe WhatsApp: la bienvenida queda en la conversación del sistema. */
        $this->assertSame('enviado', app(PruebaWhatsappService::class)->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame([], $espia->plantillas);
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_SISTEMA)
            ->whereIn('plantilla', PruebaPlantillasService::BIENVENIDAS)->count());
        Mail::assertNothingSent();

        /* El barrido de cada cinco minutos la completa aunque sea de ensayo: el asistente y el correo. */
        $this->fakear_http(['*/' . PruebaAsistenteService::RUTA_DISPONIBILIDAD => Http::response(['disponible' => true, 'user_id' => (int) $client->user_id], 200)]);
        $this->assertSame(0, Artisan::call('prueba:empujar', ['--solo-bienvenidas' => true]));
        $this->assertSame([], $espia->plantillas);
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_SISTEMA)
            ->where('plantilla', PruebaPlantillasService::ASISTENTE_LISTO)->count());
        Mail::assertSent(\App\Mail\ComercioCityMail::class, 1);
        $this->assertSame(0, \App\Models\SupportTicket::where('client_id', $client->id)->where('source', 'whatsapp')->count(), 'No se abre ticket de WhatsApp.');

        /* El empujón diario, en cambio, no la recorre. */
        $this->assertSame([], app(PruebaWhatsappService::class)->empujar_a_todos());
        $this->assertSame([], $espia->plantillas);
    }
}

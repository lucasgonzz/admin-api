<?php

namespace Tests\Feature\PruebaGratuita;

use App\Mail\ComercioCityMail;
use App\Models\Client;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\PruebaWhatsappEnvio;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * El aviso AL NEGOCIO de que la prueba está por terminar (misión cruzada prueba-e2e-campana-imperio,
 * 11/11/2026): desde el día 5, un WhatsApp (`cc_prueba_dia_5`) y un correo, cada uno una sola vez. El WhatsApp
 * ocupa el lugar del empujón de ese día; el correo no depende de él.
 */
class AvisoDeQueLaPruebaTerminaTest extends BaseDeLaPrueba
{
    protected function setUp(): void
    {
        parent::setUp();

        /* Estas pruebas miden las novedades prendidas para todos los negocios; el alcance `ensayo` (el default)
           tiene su prueba aparte en AlcanceDeLasNovedadesTest. */
        \App\Models\AdminSetting::set(\App\Services\Prueba\PruebaSettings::KEY_NOVEDADES_ALCANCE, 'todos');
    }

    /**
     * Un negocio con la bienvenida de ayer, correo cargado y todas las plantillas aprobadas.
     *
     * @param int $dias_restantes
     *
     * @return array{0: Client, 1: Lead}
     */
    private function negocio(int $dias_restantes): array
    {
        Mail::fake();
        $this->crear_config_whatsapp();
        $this->admin();
        $this->plantillas_de_la_prueba(true);

        $client = $this->cliente_en_prueba($dias_restantes);
        $client->email = 'duena@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);

        PruebaWhatsappEnvio::create([
            'client_id'  => $client->id,
            'lead_id'    => $lead->id,
            'plantilla'  => PruebaPlantillasService::BIENVENIDA,
            'estado'     => PruebaWhatsappEnvio::ESTADO_ENVIADO,
            'dia'        => now(PruebaWhatsappService::ZONA)->subDay()->toDateString(),
            'enviado_at' => now()->subDay(),
        ]);

        $this->fakear_http(['*/admin-sync/prueba/estado' => Http::response($this->estado_de_la_instancia(['ingreso']), 200)]);

        return [$client, $lead];
    }

    /** @test */
    public function al_dia_5_le_avisa_al_negocio_por_whatsapp_y_por_correo_una_sola_vez(): void
    {
        $espia = $this->espiar_sender();
        /* Vence en 2 días y media hora con una prueba de 7: va por el día 5. */
        [$client, $lead] = $this->negocio(2);

        $servicio  = app(PruebaWhatsappService::class);
        $resultado = $servicio->empujar($client, $lead);

        $this->assertSame('enviado', $resultado['estado'], (string) $resultado['detalle']);
        $this->assertCount(1, $espia->plantillas, 'El aviso es el único WhatsApp del día.');
        $this->assertSame(PruebaPlantillasService::DIA_5, $espia->plantillas[0]['template_name']);
        $this->assertSame(['Marcela', '3 días'], $espia->plantillas[0]['variables'], 'Los días que faltan de verdad: el mismo número que se le informa al closer.');

        $mensaje = \App\Models\SupportMessage::where('whatsapp_message_id', 'wamid.plantilla.1')->firstOrFail();
        $this->assertStringContainsString('termina en 3 días', $mensaje->body, 'En el hilo queda lo mismo que recibió.');

        Mail::assertSent(ComercioCityMail::class, 1);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) use ($client) {
            return $mail->hasTo($client->email) && $mail->payload->subject === 'Tu prueba de ComercioCity termina en 3 días';
        });
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('canal', PruebaWhatsappEnvio::CANAL_CORREO)
            ->where('plantilla', 'correo_dia_5')->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)->count());

        /* El mismo día no sale nada más. */
        $this->assertSame('ya_hoy', $servicio->empujar($client, $lead)['estado']);
        $this->assertCount(1, $espia->plantillas);

        /* Al día siguiente vuelve el empujón por hito, y el aviso no se repite por ningún canal. */
        PruebaWhatsappEnvio::where('client_id', $client->id)->update(['dia' => now(PruebaWhatsappService::ZONA)->subDay()->toDateString()]);
        $otro = $servicio->empujar($client, $lead);
        $this->assertSame('enviado', $otro['estado']);
        $this->assertCount(2, $espia->plantillas);
        $this->assertSame('cc_prueba_paso_importo', $espia->plantillas[1]['template_name']);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            return strpos($mail->payload->subject, 'termina en') !== false;
        });
        $this->assertSame(1, PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'correo_dia_5')->count());
    }

    /** @test */
    public function antes_del_dia_5_no_avisa_y_sigue_el_empujon_por_hito(): void
    {
        $espia = $this->espiar_sender();
        /* Vence en 5 días: va por el día 2. */
        [$client, $lead] = $this->negocio(5);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertSame('cc_prueba_paso_importo', $espia->plantillas[0]['template_name']);
        $this->assertSame(0, PruebaWhatsappEnvio::where('client_id', $client->id)->where('plantilla', 'correo_dia_5')->count());
    }

    /** @test */
    public function sin_la_plantilla_aprobada_sale_el_correo_y_el_whatsapp_del_dia_es_el_empujon(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio(1);
        FollowupTemplate::where('template_name', PruebaPlantillasService::DIA_5)->update(['activa' => false, 'meta_status' => 'PENDING']);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);

        $this->assertSame('enviado', $resultado['estado']);
        $this->assertCount(1, $espia->plantillas);
        $this->assertSame('cc_prueba_paso_importo', $espia->plantillas[0]['template_name']);
        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) {
            return $mail->payload->subject === 'Tu prueba de ComercioCity termina en 2 días';
        });
    }

    /** @test */
    public function sale_aunque_el_negocio_haya_escrito_hace_poco(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio(2);
        $this->ticket_del_cliente($client, true);

        $resultado = app(PruebaWhatsappService::class)->empujar($client, $lead);
        $this->assertSame('enviado', $resultado['estado'], 'No es un empujón: es un aviso de fecha.');
        $this->assertSame(PruebaPlantillasService::DIA_5, $espia->plantillas[0]['template_name']);
    }

    /** @test */
    public function despues_de_vencida_no_avisa_por_ningun_canal(): void
    {
        $espia = $this->espiar_sender();
        [$client, $lead] = $this->negocio(-1);

        $this->assertSame('vencida', app(PruebaWhatsappService::class)->empujar($client, $lead)['estado']);
        $this->assertCount(0, $espia->plantillas);
        Mail::assertNothingSent();
    }
}

<?php

namespace Tests\Feature\PruebaGratuita;

use App\Jobs\EnviarMensajeAlAsistenteJob;
use App\Mail\ComercioCityMail;
use App\Models\ClientAssistantMessage;
use App\Models\FollowupTemplate;
use App\Models\SupportMessage;
use App\Models\TrialInstance;
use App\Services\AsistenteWhatsappService;
use App\Services\Prueba\PruebaAsistenteService;
use App\Services\PruebaPlantillasService;
use App\Services\PruebaWhatsappService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AsistentePersonalEnBienvenidaTest extends BaseDeLaPrueba
{
    private const NUMERO = '+1 201-586-6682';

    public function test_anuncia_el_mismo_numero_por_el_ticket_y_por_correo_una_sola_vez(): void
    {
        $espia = $this->espiar_sender();
        $this->plantillas_de_la_prueba();
        [$client, $lead, $instancia] = $this->alta_lista();
        $this->disponibilidad(['disponible' => true, 'user_id' => (int) $client->user_id]);

        $servicio = app(PruebaWhatsappService::class);
        $this->assertSame('enviado', $servicio->enviar_bienvenida($lead, $client)['estado']);
        $this->assertSame('ya_enviada', $servicio->enviar_bienvenida($lead, $client)['estado']);

        $this->assertCount(1, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA_CON_ASISTENTE, $espia->plantillas[0]['template_name']);
        $this->assertSame(['Marcela', self::NUMERO], $espia->plantillas[0]['variables']);
        $mensaje = SupportMessage::where('whatsapp_message_id', 'wamid.plantilla.1')->firstOrFail();
        $this->assertStringContainsString(self::NUMERO, $mensaje->body);
        $this->assertStringContainsString('foto de una factura de proveedor', $mensaje->body);

        Mail::assertSent(ComercioCityMail::class, function (ComercioCityMail $mail) use ($client) {
            $html = view($mail->build()->view, ['payload' => $mail->payload])->render();

            return $mail->hasTo($client->email)
                && strpos($html, self::NUMERO) !== false
                && strpos($html, 'https://wa.me/12015866682') !== false
                && strpos($html, 'foto de una factura de proveedor') !== false;
        });
        Mail::assertSent(ComercioCityMail::class, 1);
        $this->assertNotNull($instancia->fresh()->correo_bienvenida_at);
        $this->assertTrue(PruebaAsistenteService::puede_atender($client));
        $this->assertFalse($client->fresh()->asistente_whatsapp_activo);

        Http::assertSent(function ($request) use ($client) {
            return $request->method() === 'GET' && $request->hasHeader('X-Admin-Api-Key', $client->api_key)
                && strpos($request->url(), PruebaAsistenteService::RUTA_DISPONIBILIDAD) !== false;
        });
    }

    public function test_un_erp_anterior_o_un_dueno_distinto_posponen_el_numero_y_meta_no_frena_el_correo(): void
    {
        $espia = $this->espiar_sender();
        $this->plantillas_de_la_prueba(true, [PruebaPlantillasService::BIENVENIDA]);
        $this->plantillas_de_la_prueba(false, [PruebaPlantillasService::ASISTENTE_LISTO]);
        [$client, $lead, $instancia] = $this->alta_lista();
        $servicio = app(PruebaWhatsappService::class);

        $this->disponibilidad([], 404);
        $servicio->enviar_bienvenida($lead, $client);
        $this->assertSame(PruebaPlantillasService::BIENVENIDA, $espia->plantillas[0]['template_name']);
        Mail::assertNothingSent();

        $this->disponibilidad(['disponible' => true, 'user_id' => (int) $client->user_id + 1]);
        $servicio->retomar_bienvenidas((int) $client->id);
        Mail::assertNothingSent();
        $this->assertNull($instancia->fresh()->asistente_listo_at);

        $this->disponibilidad(['disponible' => true, 'user_id' => (int) $client->user_id]);
        $servicio->retomar_bienvenidas((int) $client->id);
        $this->assertCount(1, $espia->plantillas);
        Mail::assertSent(ComercioCityMail::class, 1);

        FollowupTemplate::where('template_name', PruebaPlantillasService::ASISTENTE_LISTO)
            ->update(['activa' => true, 'meta_status' => 'APPROVED']);
        $servicio->retomar_bienvenidas((int) $client->id);
        $servicio->retomar_bienvenidas((int) $client->id);
        $this->assertCount(2, $espia->plantillas);
        $this->assertSame(PruebaPlantillasService::ASISTENTE_LISTO, $espia->plantillas[1]['template_name']);
        Mail::assertSent(ComercioCityMail::class, 1);
    }

    public function test_el_numero_dedicado_encamina_al_dueno_y_deja_de_hacerlo_al_vencer(): void
    {
        $espia = $this->espiar_sender();
        [$client, , $instancia] = $this->alta_lista();
        $instancia->update(['asistente_listo_at' => now()]);
        Bus::fake([EnviarMensajeAlAsistenteJob::class]);

        $this->postear_webhook($this->payload_de_texto(
            $client->phone, '¿Cuánto vendí hoy?', 'wamid.prueba.asistente', null, self::PHONE_NUMBER_ID_ASISTENTE
        ), self::SECRETO_DEL_ASISTENTE)->assertOk();
        $fila = ClientAssistantMessage::where('whatsapp_message_id', 'wamid.prueba.asistente')->firstOrFail();
        $this->assertSame((int) $client->id, (int) $fila->client_id);

        $client->trial_ends_at = now()->subSecond();
        $client->save();
        $this->assertFalse(PruebaAsistenteService::puede_atender($client->fresh()));
        $this->correr_job(new EnviarMensajeAlAsistenteJob((int) $fila->id), $espia);
        $this->assertSame(ClientAssistantMessage::ESTADO_ERROR, $fila->fresh()->estado);
        Http::assertNothingSent();

        $this->postear_webhook($this->payload_de_texto(
            $client->phone, '¿Cuánto vendí hoy?', 'wamid.prueba.vencida', null, self::PHONE_NUMBER_ID_ASISTENTE
        ), self::SECRETO_DEL_ASISTENTE)->assertOk();
        $this->assertFalse(ClientAssistantMessage::where('whatsapp_message_id', 'wamid.prueba.vencida')->exists());
    }

    public function test_un_telefono_ambiguo_no_habilita_la_prueba_ni_desplaza_al_cliente_existente(): void
    {
        [$client, $lead, $instancia] = $this->alta_lista();
        $cliente_activo = $this->crear_cliente('+5493415557711', true, 'clave-cliente-activo');
        $cliente_activo->asistente_phone = $client->phone;
        $cliente_activo->save();

        $this->assertNull(app(PruebaAsistenteService::class)->habilitar($client, $lead, $instancia));
        $this->assertNull($instancia->fresh()->asistente_listo_at);
        $dueno = app(AsistenteWhatsappService::class)->dueno_por_telefono_exacto($client->phone);
        $this->assertSame((int) $cliente_activo->id, (int) $dueno->id);
        Http::assertNothingSent();
    }

    private function alta_lista(): array
    {
        Mail::fake();
        $this->crear_config_whatsapp();
        $this->crear_config_asistente(['display_phone' => self::NUMERO]);
        $this->admin();

        $client = $this->cliente_en_prueba();
        $client->email = 'alta@test.local';
        $client->save();
        $lead = $this->lead_en_prueba($client);
        $lead->demo_setup_status = 'exitoso';
        $lead->email = 'ficha-anterior@test.local';
        $lead->save();

        $instancia = TrialInstance::create([
            'nombre' => 'prueba-' . Str::random(8),
            'estado' => TrialInstance::ESTADO_ASIGNADA,
            'erp_vps_path' => '/home/prueba/erp',
            'ecommerce_vps_path' => '/home/prueba/tienda',
            'client_id' => (int) $client->id,
            'lead_id' => (int) $lead->id,
            'user_id' => (int) $client->user_id,
            'alias_slug' => 'ferreteria-test',
        ]);

        return [$client, $lead, $instancia];
    }

    private function disponibilidad(array $respuesta, int $status = 200): void
    {
        $this->fakear_http(['*/' . PruebaAsistenteService::RUTA_DISPONIBILIDAD => Http::response($respuesta, $status)]);
    }
}

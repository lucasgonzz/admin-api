<?php

namespace Tests\Feature\LandingAgenda;

use App\Mail\LeadDemoAccesoMail;
use App\Models\FollowupTemplate;
use App\Models\Lead;
use App\Models\LeadEventoMeta;
use App\Models\LeadMessage;
use App\Services\DemoDirectaService;
use App\Services\DemoScheduledWhatsappService;
use App\Services\LeadOrigenService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * `POST /api/landing/agenda/reservas`: la reserva feliz de un lead nuevo y de uno existente, y los
 * pasos de después de responder (carta, WhatsApp, aviso a los admins, Meta). Los 409 y los 422 están
 * en `RechazosDeLaReservaTest`.
 */
class ReservaDesdeLaLandingTest extends BaseDeLaAgendaDeLaLanding
{
    public function test_reserva_feliz_de_un_lead_nuevo(): void
    {
        $leads_antes = Lead::query()->count();

        $respuesta = $this->reservar($this->cuerpo())->assertStatus(201);

        $this->assertSame($leads_antes + 1, Lead::query()->count());
        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();

        /* El contrato. 23/10/2026 es viernes. */
        $respuesta->assertExactJson([
            'experiencia_url'       => 'https://admin.test/experiencia/5493416665544',
            'turno'                 => ['fecha' => '2026-10-23', 'desde' => '15:00', 'hasta' => '17:00', 'texto' => 'viernes 23/10 de 15:00 a 17:00'],
            'correo'                => 'ma***@example.com',
            'meta_eventos'          => [
                ['nombre' => 'Lead', 'event_id' => 'cc-lead-' . $lead->id . '-web-lead'],
                ['nombre' => 'Schedule', 'event_id' => 'cc-lead-' . $lead->id . '-web-schedule'],
            ],
            'whatsapp_confirmacion' => false,
        ]);

        /* El estado y los campos: los MISMOS que escribe el agente (campos_de_reserva) para esa ventana. */
        $esperados = app(DemoDirectaService::class)->campos_de_reserva(
            $this->demo_1,
            Carbon::parse('2026-10-23 15:00', DemoDirectaService::TZ),
            Carbon::parse('2026-10-23 17:00', DemoDirectaService::TZ)
        );
        foreach ($esperados as $campo => $valor) {
            $actual = $lead->{$campo};
            if ($actual instanceof Carbon) {
                $actual = $actual->format('Y-m-d');
            }
            $this->assertEquals($valor, $actual, 'Campo ' . $campo);
        }
        $this->assertSame('15:00', substr((string) $lead->demo_start_time, 0, 5));
        /* Y lo que dispara el save() del agente: el latch de verificación al entrar al tramo. */
        $this->assertTrue((bool) $lead->requiere_verificacion_mensajes);
        $this->assertSame('nueva', $lead->demo_experiencia);
        $this->assertNotEmpty($lead->doc_number, 'Mismo alta que el webhook: con documento de la demo.');

        $this->assertSame('Marina López', $lead->contact_name);
        $this->assertSame('marina@example.com', $lead->email);
        $this->assertSame('ferreteria', $lead->demo_industria);
        $this->assertSame('Ferretería y herrajes', $lead->business_type);
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->origen);
        $this->assertNotNull($lead->demo_reservada_desde_landing_at);

        /* El evento del hilo y la atribución. */
        $evento = LeadMessage::query()->where('lead_id', $lead->id)->where('content', 'like', 'Reservó su demo desde la web%')->firstOrFail();
        $this->assertSame('sistema', $evento->sender);
        $this->assertTrue((bool) $evento->is_status_event);
        $this->assertStringContainsString('viernes 23/10 de 15:00 a 17:00', $evento->content);
        $this->assertStringContainsString('Ferretería y herrajes', $evento->content);

        $contacto = DB::table('lead_atribucion_contactos')->where('lead_id', $lead->id)->first();
        $this->assertSame((int) $evento->id, (int) $contacto->message_id);
        $this->assertSame('reserva_landing', $contacto->evidencia);
        $this->assertSame('adquisicion', $contacto->tipo);
        /* assertEquals: la columna json de MySQL reordena las claves. */
        $this->assertEquals(['utm_source' => 'facebook', 'ad_id' => '120200000001', 'fbclid' => 'IwAR123abcXYZ'], json_decode($contacto->datos, true));

        $reserva = DB::table('lead_atribucion_reservas')->where('lead_id', $lead->id)->first();
        $this->assertNotNull($reserva);
        $this->assertSame((int) $contacto->id, (int) $reserva->contacto_id);
        $this->assertSame('reserva_landing', $reserva->evidencia);
        $this->assertSame('2026-10-23', $reserva->demo_date);

        /* Después de responder: la carta. */
        Mail::assertSent(LeadDemoAccesoMail::class, function ($mail) {
            return $mail->hasTo('marina@example.com');
        });
        $this->assertNotNull($lead->fresh()->demo_mail_sent_at);
        $this->assertNull($lead->fresh()->demo_mail_last_error);

        /* El aviso a los admins: el mismo del agente. */
        $avisos = $this->enviados_con(DemoScheduledWhatsappService::TEMPLATE_NAME);
        $this->assertCount(1, $avisos);
        $this->assertSame('+5493410000001', $avisos[0]['to']);
        $this->assertSame(['Marina López', 'Viernes 23/10/2026', '15:00'], array_slice($avisos[0]['variables'], 0, 3));

        /* Sin plantilla aprobada, el WhatsApp al lead no sale y queda dicho en el hilo. */
        $this->assertSame([], $this->enviados_con('cc_demo_reservada_landing_v2'));
        $this->assertTrue(LeadMessage::query()->where('lead_id', $lead->id)->where('content', 'like', 'No salió el WhatsApp de confirmación%')->exists());

        /* Meta: dos eventos, enviados, con el contexto del navegador. */
        $filas = LeadEventoMeta::query()->where('lead_id', $lead->id)->orderBy('id')->get();
        $this->assertSame(['Lead', 'Schedule'], $filas->pluck('evento')->all(), 'Ni uno de más por el observer del save().');
        $this->assertSame([LeadEventoMeta::ESTADO_ENVIADO, LeadEventoMeta::ESTADO_ENVIADO], $filas->pluck('estado')->all());

        $fbc_esperado = 'fb.1.' . (Carbon::parse(self::AHORA, DemoDirectaService::TZ)->getTimestamp() * 1000) . '.IwAR123abcXYZ';
        Http::assertSent(function ($request) use ($lead, $fbc_esperado) {
            $dato = $request->data()['data'][0] ?? [];

            return strpos($request->url(), self::WEB_DATASET) !== false
                && ($dato['event_id'] ?? '') === 'cc-lead-' . $lead->id . '-web-schedule'
                && ($dato['action_source'] ?? '') === 'website'
                && ($dato['event_source_url'] ?? '') === self::URL_PAGINA
                && ($dato['user_data']['client_user_agent'] ?? '') === self::USER_AGENT
                && ($dato['user_data']['client_ip_address'] ?? '') === '127.0.0.1'
                && ($dato['user_data']['fbp'] ?? '') === self::FBP
                && ($dato['user_data']['fbc'] ?? '') === $fbc_esperado
                && array_key_last($dato['user_data']) === 'fbc';
        });
    }

    public function test_con_la_plantilla_aprobada_sale_el_whatsapp_al_lead(): void
    {
        $plantilla = FollowupTemplate::create([
            'template_name' => 'cc_demo_reservada_landing_v2',
            'estado'        => 'demo_agendada',
            'dia_numero'    => 0,
            'body_template' => 'Hola {{1}}! Tu demo quedó reservada para el {{2}}. Tu página: {{3}}',
            'language_code' => 'es_AR',
            'activa'        => true,
            'meta_status'   => 'APPROVED',
        ]);

        $this->reservar($this->cuerpo())->assertStatus(201)->assertJsonPath('whatsapp_confirmacion', true);
        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();

        $envios = $this->enviados_con('cc_demo_reservada_landing_v2');
        $this->assertCount(1, $envios);
        $this->assertSame('+5493416665544', $envios[0]['to']);
        $this->assertSame(['Marina', 'viernes 23/10 de 15:00 a 17:00', 'https://admin.test/experiencia/5493416665544'], $envios[0]['variables']);

        $mensaje = LeadMessage::query()->where('lead_id', $lead->id)->where('followup_template_id', $plantilla->id)->firstOrFail();
        $this->assertSame('sistema', $mensaje->sender);
        $this->assertSame('wamid.prueba.1', $mensaje->whatsapp_message_id);
        $this->assertSame('Hola Marina! Tu demo quedó reservada para el viernes 23/10 de 15:00 a 17:00. Tu página: https://admin.test/experiencia/5493416665544', $mensaje->content);
        $this->assertFalse(LeadMessage::query()->where('lead_id', $lead->id)->where('content', 'like', 'No salió el WhatsApp%')->exists());
    }

    public function test_si_el_paso_del_whatsapp_tira_queda_un_error_en_el_hilo(): void
    {
        FollowupTemplate::create([
            'template_name' => 'cc_demo_reservada_landing_v2', 'estado' => 'demo_agendada', 'dia_numero' => 0,
            'body_template' => 'Hola {{1}}', 'language_code' => 'es_AR', 'activa' => true, 'meta_status' => 'APPROVED',
        ]);
        $this->app->instance(\App\Services\WhatsappSendService::class, new class extends \App\Services\WhatsappSendService {
            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                throw new \RuntimeException('Kapso explotó');
            }
        });

        $this->reservar($this->cuerpo())->assertStatus(201);

        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();
        $this->assertTrue(LeadMessage::query()->where('lead_id', $lead->id)->where('is_error', true)
            ->where('content', 'like', 'No salió el WhatsApp de confirmación%Kapso explotó%')->exists());
        Mail::assertSent(LeadDemoAccesoMail::class, function ($mail) {
            return $mail->hasTo('marina@example.com');
        });
    }

    public function test_la_cookie_fbc_gana_sobre_el_fbclid_y_sin_url_sale_como_chat(): void
    {
        $this->reservar($this->cuerpo(['fbc' => 'fb.1.1761130000000.CookieDelPixel']))->assertStatus(201);
        Http::assertSent(function ($request) {
            $dato = $request->data()['data'][0] ?? [];

            return ($dato['event_name'] ?? '') === 'Lead' && ($dato['user_data']['fbc'] ?? '') === 'fb.1.1761130000000.CookieDelPixel';
        });
    }

    public function test_sin_la_url_de_la_pagina_el_evento_sale_como_chat_igual_que_siempre(): void
    {
        $cuerpo = $this->cuerpo();
        unset($cuerpo['event_source_url']);
        $this->reservar($cuerpo)->assertStatus(201);

        Http::assertSent(function ($request) {
            $dato = $request->data()['data'][0] ?? [];

            return ($dato['event_name'] ?? '') === 'Lead'
                && ($dato['action_source'] ?? '') === 'chat'
                && ! isset($dato['event_source_url'])
                && ! isset($dato['user_data']['client_user_agent']);
        });
    }

    public function test_la_primera_instancia_libre_a_esa_hora(): void
    {
        $this->ocupar($this->demo_1, '2026-10-23', '14:30', '16:30');

        $this->reservar($this->cuerpo())->assertStatus(201);

        $this->assertSame((int) $this->demo_2->id, (int) Lead::query()->where('phone', '+5493416665544')->value('demo_id'));
    }

    public function test_lead_existente_sin_demo_no_se_duplica_ni_se_pisan_sus_datos(): void
    {
        $existente = Lead::create([
            'phone'         => '+5493416665544',
            'contact_name'  => 'Marina',
            'email'         => 'viejo@example.com',
            'business_type' => 'Kiosco',
            'status'        => 'contactado',
        ]);
        $leads_antes = Lead::query()->count();

        $respuesta = $this->reservar($this->cuerpo(['email' => 'nuevo@example.com', 'whatsapp' => '+5493416665544']))->assertStatus(201);

        $this->assertSame($leads_antes, Lead::query()->count(), 'No duplica el lead.');
        $respuesta->assertJsonPath('correo', 'vi***@example.com');

        $lead = $existente->fresh();
        $this->assertSame('demo_agendada', $lead->status);
        $this->assertSame('Marina', $lead->contact_name, 'No pisa el nombre.');
        $this->assertSame('viejo@example.com', $lead->email, 'No pisa el correo.');
        $this->assertSame('Kiosco', $lead->business_type, 'No pisa el rubro libre.');
        $this->assertSame('ferreteria', $lead->demo_industria, 'El rubro de la demo sí se escribe.');
        $this->assertSame(LeadOrigenService::ORIGEN_LANDING, $lead->origen, 'Sin origen, queda landing.');

        $this->assertTrue(LeadMessage::query()->where('lead_id', $lead->id)->where('content', 'like', '%nuevo@example.com%')->exists(), 'El correo tipeado queda en el evento.');
        Mail::assertSent(LeadDemoAccesoMail::class, function ($mail) {
            return $mail->hasTo('viejo@example.com');
        });
        Mail::assertNotSent(LeadDemoAccesoMail::class, function ($mail) {
            return $mail->hasTo('nuevo@example.com');
        });
    }

    public function test_el_webhook_encuentra_por_telefono_al_lead_que_reservo(): void
    {
        $this->reservar($this->cuerpo(['whatsapp' => '3416665544']))->assertStatus(201);
        $lead = Lead::query()->where('phone', '+5493416665544')->firstOrFail();

        /* Mismo buscador: un formato distinto del mismo número cae en el mismo lead. */
        $this->assertSame((int) $lead->id, (int) \App\Services\BuscadorDeLeadPorTelefono::buscar('5493416665544')->id);
        $this->assertSame((int) $lead->id, (int) \App\Services\BuscadorDeLeadPorTelefono::buscar('+54 9 341 666-5544')->id);
    }
}

<?php

namespace Tests\Feature;

use App\Events\SupportMessageReceived;
use App\Events\SupportTicketUpdated;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientInstallation;
use App\Models\EcommerceImplementation;
use App\Models\EcommerceImplementationMessage;
use App\Models\EcommerceImplementationStage;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportCodexSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Martín (el soporte de Codex) atiende al cliente en implementación con la implementación en su contexto
 * (misión soporte-con-contexto-de-implementacion, 26/10/2026).
 *
 * Dos mitades:
 *
 *  1. **El ruteo del webhook**, con el webhook firmado real (`BaseDelCanal`). Las seis combinaciones del
 *     plan: con el circuito de Codex prendido, el sistema en modo manual y la tienda en etapa ≥ 2 van a
 *     soporte; el sistema en modo auto y la tienda en etapa 1 siguen en su hilo; con el circuito apagado,
 *     las dos implementaciones se quedan con el mensaje como siempre.
 *  2. **El bloque `implementation`** que devuelve `GET support-codex/tickets/{id}`: completo para una
 *     abierta, resumido para una cerrada hace ≤ 30 días, ausente para una vieja; `form_link` solo para el
 *     dueño; el canal `whatsapp_web`; y que no salga ningún teléfono ni mail.
 *
 * Nada sale a la red: `BaseDelCanal` fakea HTTP y el envío por WhatsApp se espía.
 */
class SoporteConContextoDeImplementacionTest extends BaseDelCanal
{
    /**
     * Teléfono del dueño de prueba.
     */
    const TELEFONO = '+5493415550101';

    /**
     * Headers del puente de Codex.
     *
     * @var array<string, string>
     */
    private $headers = ['X-Claude-Task-Key' => 'soporte-implementacion-test', 'Accept' => 'application/json'];

    /**
     * Reloj fijo, número principal configurado, envíos espiados y eventos/colas falsos.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-26 15:00:00'));
        config(['services.claude_task_ingest.key' => 'soporte-implementacion-test']);
        AdminSetting::flush_memo();

        $this->crear_config_whatsapp();
        $this->espiar_sender();
        Queue::fake();
        Event::fake([SupportMessageReceived::class, SupportTicketUpdated::class]);
    }

    /**
     * Devuelve el reloj y el memo de settings a su estado.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        AdminSetting::flush_memo();

        parent::tearDown();
    }

    /**
     * Prende el circuito de Codex con el corte un minuto antes del reloj.
     *
     * @return void
     */
    private function prender_codex(): void
    {
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subMinute()->format('Y-m-d H:i:s'));
        AdminSetting::flush_memo();
    }

    /**
     * Cliente de prueba (sin asistente) con slug propio.
     *
     * @return Client
     */
    private function cliente(): Client
    {
        $client       = $this->crear_cliente(self::TELEFONO, false);
        $client->slug = 'impl-soporte-' . $client->id;
        $client->contract_sistemas = ['sistema', 'ecommerce'];
        $client->save();

        return $client;
    }

    /**
     * Implementación del sistema con sus siete etapas, en la etapa pedida.
     *
     * @param Client      $client          Cliente.
     * @param string      $status          in_progress | completed.
     * @param string      $automation_mode manual | auto.
     * @param int         $etapa           Etapa actual.
     * @param Carbon|null $completed_at    Cierre, si está cerrada.
     *
     * @return Implementation
     */
    private function implementacion(Client $client, string $status = 'in_progress', string $automation_mode = 'manual', int $etapa = 3, ?Carbon $completed_at = null): Implementation
    {
        $implementation = Implementation::create([
            'client_id'               => $client->id,
            'status'                  => $status,
            'automation_mode'         => $automation_mode,
            'current_stage'           => $etapa,
            'started_at'              => now()->subDays(10),
            'completed_at'            => $completed_at,
            'form_token'              => 'token-del-formulario-' . $client->id,
            'form_submitted_at'       => now()->subDays(9),
            'migration_contact_phone' => '+5493419998877',
        ]);

        for ($numero = 1; $numero <= Implementation::ULTIMA_ETAPA; $numero++) {
            ImplementationStage::create([
                'implementation_id' => $implementation->id,
                'stage_number'      => $numero,
                'status'            => $numero < $etapa ? 'completed' : ($numero === $etapa ? 'in_progress' : 'pending'),
                'started_at'        => $numero <= $etapa ? now()->subDays(10 - $numero) : null,
                'completed_at'      => $numero < $etapa ? now()->subDays(9 - $numero) : null,
                'data'              => $numero === 1 ? ['actions' => [[
                    'action' => 'form_link', 'at' => '2026-10-17T12:00:00+00:00', 'canal' => 'whatsapp_web',
                    'texto' => 'Te paso el link', 'telefono' => '+5493419998877', 'origen' => 'claude',
                ]]] : null,
            ]);
        }

        return $implementation;
    }

    /**
     * Implementación de la tienda en la etapa pedida, con su tienda online.
     *
     * @param Client $client Cliente.
     * @param int    $etapa  Etapa actual (1 a 5).
     *
     * @return EcommerceImplementation
     */
    private function implementacion_de_tienda(Client $client, int $etapa): EcommerceImplementation
    {
        $tienda = ClientEcommerce::create(['client_id' => $client->id, 'status' => 'pending', 'domain' => 'ferreteriadeprueba.com.ar']);

        $implementation = EcommerceImplementation::create([
            'client_id'           => $client->id,
            'client_ecommerce_id' => $tienda->id,
            'status'              => 'in_progress',
            'current_stage'       => $etapa,
            'started_at'          => now()->subDays(5),
        ]);

        for ($numero = 1; $numero <= 5; $numero++) {
            EcommerceImplementationStage::create([
                'ecommerce_implementation_id' => $implementation->id,
                'stage_number'                => $numero,
                'status'                      => $numero < $etapa ? 'completed' : ($numero === $etapa ? 'in_progress' : 'pending'),
            ]);
        }

        return $implementation;
    }

    /**
     * Postea un mensaje de texto del dueño al número principal.
     *
     * @param string $wamid Id del mensaje.
     *
     * @return void
     */
    private function escribe(string $wamid): void
    {
        $this->postear_webhook($this->payload_de_texto(self::TELEFONO, 'Hola, ¿cómo vamos con la implementación?', $wamid))
            ->assertStatus(200);
    }

    /**
     * Ticket abierto por un contacto del cliente, con un mensaje entrante.
     *
     * @param Client   $client             Cliente.
     * @param int|null $client_employee_id null = el dueño.
     *
     * @return SupportTicket
     */
    private function ticket(Client $client, ?int $client_employee_id = null): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id' => $client->id, 'client_user_id' => 0, 'client_employee_id' => $client_employee_id,
            'status' => 'open', 'source' => 'whatsapp', 'whatsapp_phone' => self::TELEFONO,
            'opened_at' => now(), 'last_client_message_at' => now(),
        ]);
        SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'body' => '¿Cómo vamos?',
            'kind' => 'text', 'delivered_at' => now(),
        ]);

        return $ticket;
    }

    /**
     * El bloque `implementation` de un ticket, por el endpoint real.
     *
     * @param SupportTicket $ticket Ticket.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function contexto(SupportTicket $ticket)
    {
        return $this->getJson('/api/claude/support-codex/tickets/' . $ticket->id, $this->headers)->assertOk();
    }

    // ── Ruteo ────────────────────────────────────────────────────────────────────────────────────

    /**
     * Codex prendido + sistema en modo manual → soporte: nace el ticket y el hilo de la implementación
     * no recibe el mensaje. Y el ticket que nace ya trae la implementación en el contexto.
     *
     * @return void
     */
    public function test_codex_prendido_y_sistema_manual_va_a_soporte_con_la_implementacion_en_el_contexto(): void
    {
        $this->prender_codex();
        $client         = $this->cliente();
        $implementation = $this->implementacion($client);

        $this->escribe('wamid.IMPL-MANUAL');

        $ticket = SupportTicket::where('client_id', $client->id)->first();
        $this->assertNotNull($ticket, 'Con Codex prendido, el cliente en implementación manual tiene que abrir ticket.');
        $this->assertSame(1, SupportMessage::where('whatsapp_message_id', 'wamid.IMPL-MANUAL')->count());
        $this->assertSame(0, ImplementationMessage::where('implementation_id', $implementation->id)->count());

        $this->contexto($ticket)
            ->assertJsonPath('ticket.implementation.sistema.id', $implementation->id)
            ->assertJsonPath('ticket.implementation.sistema.abierta', true)
            ->assertJsonPath('ticket.client.slug', $client->slug);
    }

    /**
     * Codex prendido + sistema en modo auto → sigue en el hilo de la implementación (flujo guionado).
     *
     * @return void
     */
    public function test_codex_prendido_y_sistema_auto_sigue_en_la_implementacion(): void
    {
        $this->prender_codex();
        $client = $this->cliente();
        /* Etapa 2: en modo auto, `handle_stage_2` ignora el mensaje; no dispara nada hacia afuera. */
        $implementation = $this->implementacion($client, 'in_progress', 'auto', 2);

        $this->escribe('wamid.IMPL-AUTO');

        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        $this->assertSame(1, ImplementationMessage::where('implementation_id', $implementation->id)
            ->where('whatsapp_message_id', 'wamid.IMPL-AUTO')->count());
    }

    /**
     * Codex prendido + tienda en etapa ≥ 2 → soporte.
     *
     * @return void
     */
    public function test_codex_prendido_y_tienda_en_etapa_dos_o_mas_va_a_soporte(): void
    {
        $this->prender_codex();
        $client         = $this->cliente();
        $implementation = $this->implementacion_de_tienda($client, 3);

        $this->escribe('wamid.TIENDA-3');

        $this->assertNotNull(SupportTicket::where('client_id', $client->id)->first());
        $this->assertSame(0, EcommerceImplementationMessage::where('ecommerce_implementation_id', $implementation->id)->count());
    }

    /**
     * Codex prendido + tienda en etapa 1 → sigue en el hilo de la tienda (preguntas de configuración).
     *
     * @return void
     */
    public function test_codex_prendido_y_tienda_en_etapa_uno_sigue_en_la_tienda(): void
    {
        $this->prender_codex();
        $client         = $this->cliente();
        $implementation = $this->implementacion_de_tienda($client, 1);

        $this->escribe('wamid.TIENDA-1');

        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        $this->assertSame(1, EcommerceImplementationMessage::where('ecommerce_implementation_id', $implementation->id)
            ->where('whatsapp_message_id', 'wamid.TIENDA-1')->count());
    }

    /**
     * Codex apagado + sistema en modo manual → el hilo de la implementación, exactamente como antes.
     *
     * @return void
     */
    public function test_codex_apagado_y_sistema_manual_sigue_en_la_implementacion(): void
    {
        $client         = $this->cliente();
        $implementation = $this->implementacion($client);

        $this->escribe('wamid.APAGADO-SISTEMA');

        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        $this->assertSame(1, ImplementationMessage::where('implementation_id', $implementation->id)
            ->where('whatsapp_message_id', 'wamid.APAGADO-SISTEMA')->count());
    }

    /**
     * Codex apagado + tienda en etapa ≥ 2 → el hilo de la tienda, exactamente como antes.
     *
     * @return void
     */
    public function test_codex_apagado_y_tienda_en_etapa_dos_o_mas_sigue_en_la_tienda(): void
    {
        $client         = $this->cliente();
        $implementation = $this->implementacion_de_tienda($client, 3);

        $this->escribe('wamid.APAGADO-TIENDA');

        $this->assertSame(0, SupportTicket::where('client_id', $client->id)->count());
        $this->assertSame(1, EcommerceImplementationMessage::where('ecommerce_implementation_id', $implementation->id)
            ->where('whatsapp_message_id', 'wamid.APAGADO-TIENDA')->count());
    }

    /**
     * Con las dos en curso decide la del sistema, como siempre: sistema manual + tienda en etapa 1 va a
     * soporte (la tienda no se mira mientras la del sistema esté en curso).
     *
     * @return void
     */
    public function test_con_las_dos_en_curso_decide_la_del_sistema(): void
    {
        $this->prender_codex();
        $client = $this->cliente();
        $this->implementacion($client);
        $tienda = $this->implementacion_de_tienda($client, 1);

        $this->escribe('wamid.LAS-DOS');

        $this->assertNotNull(SupportTicket::where('client_id', $client->id)->first());
        $this->assertSame(0, EcommerceImplementationMessage::where('ecommerce_implementation_id', $tienda->id)->count());
    }

    // ── Contexto ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Una abierta viaja completa: etapas con acciones, mails, conversación con canal, form_link para el
     * dueño. Y no sale ningún teléfono, ni la casilla del mail, ni el texto guardado en la acción.
     *
     * @return void
     */
    public function test_abierta_viaja_completa_y_sin_datos_personales(): void
    {
        $this->prender_codex();
        AdminSetting::set('implementation_form_url', 'https://admin.example/configuracion');
        AdminSetting::flush_memo();

        $client         = $this->cliente();
        $implementation = $this->implementacion($client);

        ImplementationMail::create([
            'implementation_id' => $implementation->id, 'hito' => 'bienvenida', 'email' => 'dueno.secreto@example.com',
            'asunto' => 'Bienvenida', 'estado' => 'enviado', 'enviado_at' => now()->subDays(9), 'reenvios' => 0,
        ]);
        ImplementationMessage::create([
            'implementation_id' => $implementation->id, 'stage_number' => 1, 'direction' => 'outbound',
            'phone' => self::TELEFONO, 'body' => 'Hola, soy Lucas. Te paso el formulario.',
            'whatsapp_message_id' => 'waweb-abc123', 'sent_at' => now()->subDays(9),
        ]);
        ImplementationMessage::create([
            'implementation_id' => $implementation->id, 'stage_number' => 3, 'direction' => 'inbound',
            'phone' => self::TELEFONO, 'body' => str_repeat('a', 2500),
            'whatsapp_message_id' => 'wamid.ENTRANTE-VIEJO', 'sent_at' => now()->subDay(),
        ]);

        $respuesta = $this->contexto($this->ticket($client));
        $sistema   = $respuesta->json('ticket.implementation.sistema');

        $this->assertNull($respuesta->json('ticket.implementation.tienda'));
        $this->assertTrue($sistema['abierta']);
        $this->assertSame('manual', $sistema['automation_mode']);
        $this->assertSame(['numero' => 3, 'nombre' => 'Recolección de archivos'], $sistema['etapa_actual']);
        $this->assertCount(Implementation::ULTIMA_ETAPA, $sistema['etapas']);
        $this->assertSame('completed', $sistema['etapas'][0]['estado']);
        $this->assertSame([['accion' => 'form_link', 'at' => '2026-10-17T12:00:00+00:00', 'canal' => 'whatsapp_web']], $sistema['etapas'][0]['acciones']);
        $this->assertSame('https://admin.example/configuracion/token-del-formulario-' . $client->id, $sistema['form_link']);
        $this->assertTrue($sistema['tienda_contratada']);
        $this->assertNotNull($sistema['form_submitted_at']);
        $this->assertSame([['hito' => 'bienvenida', 'estado' => 'enviado', 'enviado_at' => now()->subDays(9)->toIso8601String()]], $sistema['mails']);

        $this->assertCount(2, $sistema['conversacion']);
        $this->assertSame('whatsapp_web', $sistema['conversacion'][0]['canal']);
        $this->assertSame('outbound', $sistema['conversacion'][0]['direction']);
        $this->assertSame(1, $sistema['conversacion'][0]['etapa']);
        $this->assertSame('whatsapp', $sistema['conversacion'][1]['canal']);
        $this->assertSame(2001, mb_strlen($sistema['conversacion'][1]['body']), 'El cuerpo se recorta a 2000 más la marca.');

        $crudo = json_encode($respuesta->json('ticket.implementation'));
        $this->assertStringNotContainsString('5493415550101', $crudo);
        $this->assertStringNotContainsString('5493419998877', $crudo);
        $this->assertStringNotContainsString('dueno.secreto', $crudo);
        $this->assertStringNotContainsString('Te paso el link', $crudo);
    }

    /**
     * `form_link` y `conversacion` solo para el dueño: a un empleado le llegan null (en el sistema y en
     * la tienda), aunque las implementaciones estén abiertas y tengan mensajes.
     *
     * @return void
     */
    public function test_form_link_y_conversacion_solo_para_el_dueno(): void
    {
        $this->prender_codex();
        AdminSetting::set('implementation_form_url', 'https://admin.example/configuracion');
        AdminSetting::flush_memo();

        $client         = $this->cliente();
        $implementation = $this->implementacion($client);
        $tienda         = $this->implementacion_de_tienda($client, 3);
        ImplementationMessage::create([
            'implementation_id' => $implementation->id, 'stage_number' => 3, 'direction' => 'outbound',
            'phone' => self::TELEFONO, 'body' => 'Mandame los Excel.', 'whatsapp_message_id' => 'waweb-x', 'sent_at' => now()->subDay(),
        ]);
        EcommerceImplementationMessage::create([
            'ecommerce_implementation_id' => $tienda->id, 'stage_number' => 3, 'direction' => 'outbound',
            'body' => 'Estamos instalando la tienda.', 'whatsapp_message_id' => 'waweb-y', 'sent_at' => now()->subDay(),
        ]);
        $empleado = $this->crear_empleado($client, '+5493416660000');

        $this->contexto($this->ticket($client, $empleado->id))
            ->assertJsonPath('ticket.implementation.sistema.abierta', true)
            ->assertJsonPath('ticket.implementation.sistema.form_link', null)
            ->assertJsonPath('ticket.implementation.sistema.conversacion', null)
            ->assertJsonPath('ticket.implementation.tienda.abierta', true)
            ->assertJsonPath('ticket.implementation.tienda.conversacion', null);
    }

    /**
     * Al dueño la conversación le llega enmascarada: el link del formulario (por token y por la URL
     * base), los números de 8 o más dígitos aun con separadores, y lo que sigue a «contraseña»/«clave».
     * Lo que no es dato personal (un precio corto, el resto del texto) queda igual.
     *
     * @return void
     */
    public function test_la_conversacion_del_dueno_viaja_enmascarada(): void
    {
        $this->prender_codex();
        AdminSetting::set('implementation_form_url', 'https://admin.example/configuracion');
        AdminSetting::flush_memo();

        $client         = $this->cliente();
        $implementation = $this->implementacion($client);
        $token          = $implementation->form_token;
        $cuerpos        = [
            'Completá esto: https://admin.example/configuracion/' . $token . ' y avisame.',
            'Otro link: https://otro.example/form?t=' . $token,
            'El viejo: https://admin.example/configuracion/otro-token-cualquiera',
            'Mi cel es +54 9 341 555-0101 y el fijo 4567-8901.',
            'CUIT 20-12345678-9, DNI 12.345.678, sale $12.500.',
            'La contraseña es Perro123 y la clave: abc-99 listo.',
            'Contraseña Gato2026 nueva.',
        ];

        foreach ($cuerpos as $indice => $cuerpo) {
            ImplementationMessage::create([
                'implementation_id' => $implementation->id, 'stage_number' => 3, 'direction' => 'inbound',
                'phone' => self::TELEFONO, 'body' => $cuerpo, 'whatsapp_message_id' => 'wamid.PII-' . $indice,
                'sent_at' => now()->subMinutes(60 - $indice),
            ]);
        }

        $tienda = $this->implementacion_de_tienda($client, 2);
        EcommerceImplementationMessage::create([
            'ecommerce_implementation_id' => $tienda->id, 'stage_number' => 2, 'direction' => 'inbound',
            'body' => 'Llamame al 341 5550101 o entrá a https://admin.example/configuracion/' . $token,
            'whatsapp_message_id' => 'wamid.PII-TIENDA', 'sent_at' => now()->subMinute(),
        ]);

        $respuesta = $this->contexto($this->ticket($client));
        $cuerpos   = array_column($respuesta->json('ticket.implementation.sistema.conversacion'), 'body');

        $this->assertSame([
            'Completá esto: [link del formulario] y avisame.',
            'Otro link: [link del formulario]',
            'El viejo: [link del formulario]',
            'Mi cel es [número] y el fijo [número].',
            'CUIT [número], DNI [número], sale $12.500.',
            'La contraseña es [oculta] y la clave: [oculta] listo.',
            'Contraseña [oculta] nueva.',
        ], $cuerpos);

        $this->assertSame(
            'Llamame al [número] o entrá a [link del formulario]',
            $respuesta->json('ticket.implementation.tienda.conversacion.0.body')
        );
    }

    /**
     * `entrega_hecha` y `sistema_url`: antes de la entrega la URL no viaja; una acción `acceso`
     * registrada en cualquier etapa (con la 5 todavía en curso) alcanza para darla por hecha.
     *
     * @return void
     */
    public function test_sistema_url_solo_despues_de_la_entrega(): void
    {
        $this->prender_codex();
        $client = $this->cliente();
        $api    = $this->crear_client_api($client);
        $api->spa_url = 'https://ferreteria.comerciocity.test';
        $api->save();
        $implementation = $this->implementacion($client, 'in_progress', 'manual', 5);
        $ticket         = $this->ticket($client);

        $this->contexto($ticket)
            ->assertJsonPath('ticket.implementation.sistema.entrega_hecha', false)
            ->assertJsonPath('ticket.implementation.sistema.sistema_url', null);

        $etapa_3 = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 3)->first();
        $etapa_3->data = ['actions' => [['action' => 'acceso', 'at' => '2026-10-25T12:00:00+00:00', 'canal' => 'whatsapp_web']]];
        $etapa_3->save();

        $this->contexto($ticket)
            ->assertJsonPath('ticket.implementation.sistema.entrega_hecha', true)
            ->assertJsonPath('ticket.implementation.sistema.sistema_url', 'https://ferreteria.comerciocity.test');
    }

    /**
     * La etapa 5 cerrada también da la entrega por hecha.
     *
     * @return void
     */
    public function test_la_etapa_cinco_cerrada_da_la_entrega_por_hecha(): void
    {
        $this->prender_codex();
        $client = $this->cliente();
        $this->implementacion($client, 'in_progress', 'manual', 6);

        $this->contexto($this->ticket($client))->assertJsonPath('ticket.implementation.sistema.entrega_hecha', true);
    }

    /**
     * `instalacion`: la última instalación `completa` de la API activa, con el valor real del estado.
     * No cuentan la `esqueleto` (aunque sea más nueva) ni la `completa` de otra API.
     *
     * @return void
     */
    public function test_instalacion_es_la_ultima_completa_de_la_api_activa(): void
    {
        $this->prender_codex();
        $client = $this->cliente();
        $this->implementacion($client);
        $ticket = $this->ticket($client);

        $this->contexto($ticket)->assertJsonPath('ticket.implementation.sistema.instalacion', null);

        $activa = $this->crear_client_api($client);
        $this->crear_instalacion($client, $activa->id, ClientInstallation::KIND_COMPLETA, 'fallida', now()->subDays(3));
        $this->crear_instalacion($client, $activa->id, ClientInstallation::KIND_COMPLETA, 'completada', now()->subDays(2));
        $this->crear_instalacion($client, $activa->id, ClientInstallation::KIND_ESQUELETO, 'instalando', null);
        $this->crear_instalacion($client, $activa->id + 1000, ClientInstallation::KIND_COMPLETA, 'pendiente', null);

        $this->contexto($ticket)->assertJsonPath('ticket.implementation.sistema.instalacion', [
            'estado' => 'completada',
            'at'     => now()->subDays(2)->toIso8601String(),
        ]);
    }

    /**
     * `tienda.activa`: false mientras no esté activa; true con la tienda online `active` o con la etapa
     * 5 de la tienda cerrada.
     *
     * @return void
     */
    public function test_tienda_activa(): void
    {
        $this->prender_codex();
        $client = $this->cliente();
        $tienda = $this->implementacion_de_tienda($client, 5);
        $ticket = $this->ticket($client);

        $this->contexto($ticket)->assertJsonPath('ticket.implementation.tienda.activa', false);

        EcommerceImplementationStage::where('ecommerce_implementation_id', $tienda->id)->where('stage_number', 5)
            ->update(['status' => 'completed']);
        $this->contexto($ticket)->assertJsonPath('ticket.implementation.tienda.activa', true);

        EcommerceImplementationStage::where('ecommerce_implementation_id', $tienda->id)->where('stage_number', 5)
            ->update(['status' => 'in_progress']);
        ClientEcommerce::where('id', $tienda->client_ecommerce_id)->update(['status' => 'active']);
        $this->contexto($ticket)->assertJsonPath('ticket.implementation.tienda.activa', true);
    }

    /**
     * Abierta es solo `in_progress`: una `paused` no viaja (ni como abierta ni como cerrada).
     *
     * @return void
     */
    public function test_una_pausada_no_viaja(): void
    {
        $this->prender_codex();
        $client = $this->cliente();
        $this->implementacion($client, 'paused');

        $this->contexto($this->ticket($client))->assertJsonPath('ticket.implementation', null);
    }

    /**
     * Instalación del cliente.
     *
     * @param Client      $client        Cliente.
     * @param int         $client_api_id API destino.
     * @param string      $kind          completa | esqueleto.
     * @param string      $status        pendiente | instalando | completada | fallida.
     * @param Carbon|null $finished_at   Fin, si terminó.
     *
     * @return ClientInstallation
     */
    private function crear_instalacion(Client $client, int $client_api_id, string $kind, string $status, ?Carbon $finished_at): ClientInstallation
    {
        return ClientInstallation::create([
            'uuid' => (string) Str::uuid(), 'client_id' => $client->id, 'client_api_id' => $client_api_id,
            'kind' => $kind, 'status' => $status, 'started_at' => now()->subDays(4), 'finished_at' => $finished_at,
        ]);
    }

    /**
     * Una cerrada hace ≤ 30 días viaja resumida: `abierta: false`, sin conversación ni form_link.
     *
     * @return void
     */
    public function test_cerrada_reciente_viaja_resumida(): void
    {
        $this->prender_codex();
        AdminSetting::set('implementation_form_url', 'https://admin.example/configuracion');
        AdminSetting::flush_memo();

        $client         = $this->cliente();
        $implementation = $this->implementacion($client, 'completed', 'manual', 7, now()->subDays(10));
        ImplementationMessage::create([
            'implementation_id' => $implementation->id, 'stage_number' => 7, 'direction' => 'outbound',
            'phone' => self::TELEFONO, 'body' => 'Listo, quedó todo.', 'whatsapp_message_id' => 'waweb-fin', 'sent_at' => now()->subDays(10),
        ]);

        $sistema = $this->contexto($this->ticket($client))->json('ticket.implementation.sistema');

        $this->assertFalse($sistema['abierta']);
        $this->assertSame('completed', $sistema['status']);
        $this->assertNull($sistema['conversacion']);
        $this->assertNull($sistema['form_link']);
        $this->assertCount(Implementation::ULTIMA_ETAPA, $sistema['etapas']);
    }

    /**
     * Una cerrada hace más de 30 días no viaja: `implementation` es null.
     *
     * @return void
     */
    public function test_cerrada_vieja_no_viaja(): void
    {
        $this->prender_codex();
        $client = $this->cliente();
        $this->implementacion($client, 'completed', 'manual', 7, now()->subDays(40));

        $this->contexto($this->ticket($client))->assertJsonPath('ticket.implementation', null);
    }

    /**
     * Un cliente sin implementación: `implementation` es null.
     *
     * @return void
     */
    public function test_sin_implementacion_es_null(): void
    {
        $this->prender_codex();

        $this->contexto($this->ticket($this->cliente()))->assertJsonPath('ticket.implementation', null);
    }

    /**
     * La tienda abierta viaja con su etapa, el dominio y la conversación.
     *
     * @return void
     */
    public function test_tienda_abierta_viaja_con_dominio_y_conversacion(): void
    {
        $this->prender_codex();
        $client         = $this->cliente();
        $implementation = $this->implementacion_de_tienda($client, 3);
        EcommerceImplementationMessage::create([
            'ecommerce_implementation_id' => $implementation->id, 'stage_number' => 2, 'direction' => 'outbound',
            'body' => 'Ya compramos el dominio.', 'whatsapp_message_id' => 'waweb-tienda-1', 'sent_at' => now()->subDays(2),
        ]);

        $respuesta = $this->contexto($this->ticket($client));
        $tienda    = $respuesta->json('ticket.implementation.tienda');

        $this->assertNull($respuesta->json('ticket.implementation.sistema'));
        $this->assertTrue($tienda['abierta']);
        $this->assertSame(['numero' => 3, 'nombre' => 'Instalación del API'], $tienda['etapa_actual']);
        $this->assertCount(5, $tienda['etapas']);
        $this->assertSame('ferreteriadeprueba.com.ar', $tienda['dominio']);
        $this->assertCount(1, $tienda['conversacion']);
        $this->assertSame('whatsapp_web', $tienda['conversacion'][0]['canal']);
        $this->assertSame('Ya compramos el dominio.', $tienda['conversacion'][0]['body']);
    }
}

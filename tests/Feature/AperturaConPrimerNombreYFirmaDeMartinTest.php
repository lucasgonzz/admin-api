<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ClientPhoneDirectory;
use App\Services\ImplementationSupportWhatsappService;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión whatsapp-apertura-primer-nombre-martin (16/11/2026).
 *
 * Regla de Lucas: la plantilla de apertura de soporte (`cc_soporte_apertura`) saluda por el PRIMER NOMBRE de la
 * persona (nunca por el nombre del negocio) y firma SIEMPRE «Martín», sin importar qué admin la mande. En el
 * dry-run de Plazoleta (cliente 74) había salido «Hola Ferretería plazoleta, te escribe Lucas».
 *
 * Se prueba por los tres caminos que arman ese texto: la apertura (`open()`), la respuesta a un hilo con la
 * ventana cerrada (`deliver_follow_up()`) y el simulacro de los hitos de implementación (`plan()`).
 */
class AperturaConPrimerNombreYFirmaDeMartinTest extends TestCase
{
    use DatabaseTransactions;

    /** @var string Teléfono del dueño de prueba. */
    const TELEFONO = '+5493415559911';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    private function crear_admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = uniqid('apertura-martin') . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente como Plazoleta: `name` es la persona y `company_name` el negocio.
     */
    private function crear_cliente(string $persona = 'Sebastián Referido', string $negocio = 'Ferretería plazoleta'): Client
    {
        $client               = new Client();
        $client->name         = $persona;
        $client->company_name = $negocio;
        $client->phone        = self::TELEFONO;
        $client->is_active    = true;
        $client->api_url      = 'https://api-cliente-de-prueba.test';
        $client->api_key      = 'clave-de-prueba';
        $client->save();

        return $client;
    }

    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            public $textos = [];
            public $plantillas = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = ['to' => $to, 'body' => $body];

                return 'wamid.texto.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = ['to' => $to, 'template_name' => $template_name, 'variables' => $variables];

                return 'wamid.plantilla.' . count($this->plantillas);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    public function test_primer_nombre_de_la_persona_y_casos_borde()
    {
        $this->assertSame('Sebastián', SupportWhatsappOpenerService::primer_nombre('Sebastián Referido'));
        $this->assertSame('Sebastián', SupportWhatsappOpenerService::primer_nombre('  sebastián   referido '));
        $this->assertSame('Sebastián', SupportWhatsappOpenerService::primer_nombre('Sebastián'), 'No es idempotente.');
        $this->assertSame('Sebastián', SupportWhatsappOpenerService::primer_nombre('SEBASTIÁN REFERIDO'));
        $this->assertSame('Ana', SupportWhatsappOpenerService::primer_nombre('Ana (del lead)'));
        $this->assertSame('Ana', SupportWhatsappOpenerService::primer_nombre('Ana López (personal)'));
        $this->assertSame('equipo', SupportWhatsappOpenerService::primer_nombre(''));
        $this->assertSame('equipo', SupportWhatsappOpenerService::primer_nombre('Empleado'));
        $this->assertSame('equipo', SupportWhatsappOpenerService::primer_nombre('Contacto del lead'), 'La etiqueta genérica del lead no es un nombre.');
    }

    public function test_apertura_con_ventana_cerrada_saluda_a_la_persona_y_firma_martin()
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente();
        $espia  = $this->espiar_sender();

        $resultado = app(SupportWhatsappOpenerService::class)
            ->open($client, self::TELEFONO, 'Te escribo por la implementación.', $admin);

        $this->assertSame('sent', $resultado['whatsapp']['delivery']);
        $this->assertCount(1, $espia->plantillas);

        $variables = $espia->plantillas[0]['variables'];
        $this->assertSame('Sebastián', $variables[0], 'El saludo no es el primer nombre de la persona.');
        $this->assertSame('Martín', $variables[1], 'El remitente no es Martín.');

        $mensaje = SupportMessage::find($resultado['message']->id);
        $this->assertStringContainsString('Hola Sebastián, te escribe Martín del equipo de soporte', (string) $mensaje->body);
        $this->assertStringNotContainsString('Ferretería', (string) $mensaje->body);
        $this->assertStringNotContainsString('Lucas', (string) $mensaje->body);
        $this->assertSame((int) $admin->id, (int) $mensaje->sender_admin_id, 'Se perdió quién lo mandó de verdad.');
    }

    public function test_un_empleado_se_saluda_por_su_primer_nombre()
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente();
        $espia  = $this->espiar_sender();

        $empleado            = new ClientEmployee();
        $empleado->client_id = $client->id;
        $empleado->name      = 'Brisa Gómez';
        $empleado->phone     = '+5493415558822';
        $empleado->save();

        app(SupportWhatsappOpenerService::class)->open($client, '+5493415558822', 'Hola, una consulta.', $admin);

        $this->assertSame('Brisa', $espia->plantillas[0]['variables'][0]);
        $this->assertSame('Martín', $espia->plantillas[0]['variables'][1]);
    }

    public function test_sin_nombre_de_persona_nunca_se_cae_al_negocio()
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente('', 'Ferretería plazoleta');
        $espia  = $this->espiar_sender();

        app(SupportWhatsappOpenerService::class)->open($client, self::TELEFONO, 'Una consulta.', $admin);

        $this->assertSame('equipo', $espia->plantillas[0]['variables'][0]);
        $this->assertStringNotContainsString('Ferretería', json_encode($espia->plantillas[0]['variables']));
    }

    public function test_la_respuesta_a_un_hilo_con_ventana_cerrada_tambien_sale_de_martin()
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente();
        $espia  = $this->espiar_sender();
        $opener = app(SupportWhatsappOpenerService::class);

        $primera = $opener->open($client, self::TELEFONO, 'Primer mensaje.', $admin);
        $ticket  = SupportTicket::findOrFail($primera['ticket']->id);

        $segundo = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'admin',
            'sender_admin_id'   => $admin->id,
            'kind'              => 'text',
            'body'              => 'Segundo mensaje del operador.',
            'delivered_at'      => now(),
        ]);

        // Se le pasa «Lucas» como antes lo hacía SupportMessageController: tiene que ignorarse.
        $resultado = $opener->deliver_follow_up($ticket, $segundo, 'Lucas');

        $this->assertSame('sent', $resultado['delivery']);
        $this->assertCount(2, $espia->plantillas);
        $this->assertSame('Sebastián', $espia->plantillas[1]['variables'][0]);
        $this->assertSame('Martín', $espia->plantillas[1]['variables'][1]);
        $this->assertStringContainsString('Hola Sebastián, te escribe Martín', (string) SupportMessage::find($segundo->id)->body);
    }

    public function test_el_simulacro_de_los_hitos_muestra_el_mismo_saludo_y_remitente()
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente();
        $this->espiar_sender();

        $contacto = app(ClientPhoneDirectory::class)->resolve_for_client($client, self::TELEFONO);
        $this->assertNotNull($contacto);
        $this->assertSame('Ferretería plazoleta', $contacto['label'], 'Precondición: la libreta etiqueta al dueño con el negocio.');

        $plan = app(ImplementationSupportWhatsappService::class)->plan($client, $contacto, 'Ya tenemos tu sistema listo.', $admin);

        $this->assertSame('Sebastián', $plan['contacto_nombre']);
        $this->assertSame('Martín', $plan['remitente']);
        $this->assertTrue($plan['uso_plantilla']);
        $this->assertStringContainsString('Hola Sebastián, te escribe Martín', $plan['vista_previa']);
        $this->assertStringNotContainsString('Ferretería', $plan['vista_previa']);
    }
}

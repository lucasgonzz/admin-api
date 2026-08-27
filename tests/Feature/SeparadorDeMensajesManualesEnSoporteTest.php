<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\ClientPhoneDirectory;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSendService;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El operador de soporte puede partir su propio mensaje con el separador de tres guiones.
 *
 * Lo que estos tests protegen NO es el camino feliz: partir un texto que trae el separador
 * completo es una línea de código. Lo que importa son los NO, que es donde se rompe barato y
 * en silencio: que un "---" suelto, un subrayado de markdown o unos guiones en el medio de un
 * párrafo no le partan el mensaje a nadie sin haberlo pedido. Antes de esta misión el mensaje
 * de una persona no se partía nunca, y ese piso -no cambiarle a alguien lo que escribió- es
 * justamente el que no se puede perder al agregar la forma explícita de pedirlo.
 *
 * Se le pega al endpoint real con base de por medio: el envío se sustituye a nivel
 * WhatsappSendService, así que no se toca la red ni Meta, pero la ruta, el controller, la
 * ventana de 24hs y la persistencia son los de verdad.
 */
class SeparadorDeMensajesManualesEnSoporteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Corta cualquier salida HTTP real y usa un disco de mentira para los adjuntos.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('public');
    }

    /**
     * Admin operador de soporte.
     *
     * @param string $email Email único del admin.
     *
     * @return Admin
     */
    private function crear_admin(string $email): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = $email;
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Cliente activo con teléfono.
     *
     * @param string $phone Teléfono de la ficha.
     *
     * @return Client
     */
    private function crear_cliente(string $phone): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = $phone;
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * Ticket de WhatsApp abierto con la ventana de 24hs abierta.
     *
     * El entrante reciente es lo que la abre: sin él el envío saldría por plantilla y no habría
     * nada que partir.
     *
     * @param Client $client Cliente dueño.
     * @param string $phone  Teléfono del hilo.
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client, string $phone): SupportTicket
    {
        $ticket = $this->crear_ticket_sin_ventana($client, $phone);

        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Me tira un error',
            'delivered_at'      => now()->subMinutes(5),
        ]);

        return $ticket;
    }

    /**
     * Ticket de WhatsApp abierto pero sin entrantes recientes: la ventana está cerrada.
     *
     * @param Client $client Cliente dueño.
     * @param string $phone  Teléfono del hilo.
     *
     * @return SupportTicket
     */
    private function crear_ticket_sin_ventana(Client $client, string $phone): SupportTicket
    {
        return SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => $phone,
            'opened_at'        => now()->subHours(2),
        ]);
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra cada envío.
     *
     * @return WhatsappSendService
     */
    private function espiar_sender(): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, string> Textos enviados, en orden. */
            public $textos = [];

            /** @var array<int, array<string, mixed>> Envíos de plantilla. */
            public $plantillas = [];

            /** @var array<int, string> Mensajes con adjunto, que no pasan por el envío de texto. */
            public $adjuntos = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = $body;

                return 'wamid.texto.' . count($this->textos);
            }

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->plantillas[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                ];

                return 'wamid.plantilla.' . count($this->plantillas);
            }

            public function send_support_message(string $to, SupportMessage $message): ?string
            {
                $message->loadMissing('attachments');

                // Un mensaje con adjunto se cuenta aparte: el envío real leería el archivo del
                // disco y armaría el subido a Meta, y acá lo que se mira es que no haya salido
                // partido en varios textos.
                if ($message->attachments !== null && count($message->attachments) > 0) {
                    $this->adjuntos[] = (string) ($message->body ?? '');

                    return 'wamid.adjunto.' . count($this->adjuntos);
                }

                return parent::send_support_message($to, $message);
            }
        };

        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * El opener con las pausas anuladas.
     *
     * Las esperas son reales y necesarias en producción -1200ms entre parte y parte-, pero acá
     * sumarían segundos a cada corrida sin probar nada. Se registra en el contenedor porque el
     * controller lo resuelve por ahí.
     *
     * @return SupportWhatsappOpenerService
     */
    private function opener_sin_pausas(): SupportWhatsappOpenerService
    {
        $opener = new class(
            app(WhatsappSendService::class),
            app(WhatsappSessionWindowService::class),
            app(ClientPhoneDirectory::class)
        ) extends SupportWhatsappOpenerService {
            protected function pausar(int $microsegundos): void
            {
                // A propósito: en el test no se espera.
            }
        };

        $this->app->instance(SupportWhatsappOpenerService::class, $opener);

        return $opener;
    }

    /**
     * Mensajes del operador que ya salieron, en el orden en que quedaron en el hilo.
     *
     * @param SupportTicket $ticket Ticket del hilo.
     *
     * @return \Illuminate\Support\Collection
     */
    private function mensajes_del_operador(SupportTicket $ticket)
    {
        return SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('sender_type', 'admin')
            ->orderBy('id')
            ->get();
    }

    /**
     * Con el separador completo, el mensaje del operador sale partido en varios.
     *
     * @return void
     */
    public function test_el_operador_parte_su_mensaje_con_el_separador_completo()
    {
        $admin  = $this->crear_admin('separador-completo@test.local');
        $client = $this->crear_cliente('+5493417780001');
        $ticket = $this->crear_ticket($client, '+5493417780001');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind' => 'text',
            'body' => "Ya lo vi.\n\n---\n\nEs el aviso de stock negativo.\n\n---\n\n¿Te lo desactivo?",
        ])->assertStatus(201);

        $this->assertCount(3, $espia->textos, 'El mensaje del operador no salió partido en tres.');
        $this->assertSame('Ya lo vi.', $espia->textos[0]);
        $this->assertSame('¿Te lo desactivo?', $espia->textos[2]);

        // Cada parte queda como mensaje propio del hilo, con su id de Meta: el operador tiene
        // que ver en la bandeja exactamente lo que recibió el cliente.
        $enviados = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('sender_type', 'admin')
            ->whereNotNull('whatsapp_message_id')
            ->get();

        $this->assertCount(3, $enviados, 'El hilo no muestra lo mismo que recibió el cliente.');

        $original = $this->mensajes_del_operador($ticket)->first();
        $this->assertSame('Ya lo vi.', (string) $original->body, 'El mensaje original no quedó con la primera parte.');
    }

    /**
     * Tres guiones sueltos entre dos renglones no parten nada.
     *
     * Es el caso que más se escribe sin querer, y el que el comportamiento viejo protegía.
     *
     * @return void
     */
    public function test_un_guion_suelto_no_parte_el_mensaje_del_operador()
    {
        $admin  = $this->crear_admin('guion-suelto@test.local');
        $client = $this->crear_cliente('+5493417780002');
        $ticket = $this->crear_ticket($client, '+5493417780002');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind' => 'text',
            'body' => "Primero esto\n---\ny después esto",
        ])->assertStatus(201);

        $this->assertCount(1, $espia->textos, 'Se partió un mensaje que no traía el separador completo.');
        $this->assertSame("Primero esto\n---\ny después esto", $espia->textos[0]);
        $this->assertCount(1, $this->mensajes_del_operador($ticket), 'Se creó más de un mensaje en el hilo.');
    }

    /**
     * Unos guiones en el medio de un párrafo no parten nada.
     *
     * @return void
     */
    public function test_tres_guiones_dentro_de_un_parrafo_no_parten_nada()
    {
        $admin  = $this->crear_admin('guiones-en-parrafo@test.local');
        $client = $this->crear_cliente('+5493417780003');
        $ticket = $this->crear_ticket($client, '+5493417780003');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind' => 'text',
            'body' => 'El precio va de 100 --- 200 según el plan',
        ])->assertStatus(201);

        $this->assertCount(1, $espia->textos, 'Unos guiones adentro de un párrafo partieron el mensaje.');
    }

    /**
     * Un subrayado de markdown no parte el mensaje.
     *
     * Es el caso que hace inviable unificar el criterio con el del agente: para el agente
     * "titulo\n---\nsubtitulo" son dos mensajes, y para una persona es un título subrayado.
     *
     * @return void
     */
    public function test_un_subrayado_de_markdown_no_parte_el_mensaje()
    {
        $admin  = $this->crear_admin('subrayado-markdown@test.local');
        $client = $this->crear_cliente('+5493417780004');
        $ticket = $this->crear_ticket($client, '+5493417780004');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind' => 'text',
            'body' => "Pasos a seguir\n---\nEntrá a Configuración",
        ])->assertStatus(201);

        $this->assertCount(1, $espia->textos, 'Un subrayado de markdown partió el mensaje.');
    }

    /**
     * Con la ventana cerrada no se parte, aunque el separador esté completo.
     *
     * Una plantilla no puede llevar tres mensajes: el texto entero viaja adentro de una sola.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_el_mensaje_del_operador_no_se_parte()
    {
        $admin  = $this->crear_admin('ventana-cerrada-operador@test.local');
        $client = $this->crear_cliente('+5493417780005');
        $ticket = $this->crear_ticket_sin_ventana($client, '+5493417780005');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind' => 'text',
            'body' => "Una parte.\n\n---\n\nY otra parte.",
        ])->assertStatus(201);

        $this->assertCount(0, $espia->textos, 'Mandó texto libre con la ventana cerrada.');
        $this->assertCount(1, $espia->plantillas, 'No salió por plantilla con la ventana cerrada.');
    }

    /**
     * Un mensaje con adjunto no se parte: el adjunto viaja en un mensaje solo.
     *
     * @return void
     */
    public function test_un_mensaje_con_adjunto_no_se_parte()
    {
        $admin  = $this->crear_admin('adjunto-sin-partir@test.local');
        $client = $this->crear_cliente('+5493417780006');
        $ticket = $this->crear_ticket($client, '+5493417780006');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $this->actingAs($admin, 'sanctum')->post('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind'       => 'image',
            'body'       => "Mirá esto.\n\n---\n\nY esto también.",
            'attachment' => UploadedFile::fake()->image('captura.png'),
        ])->assertStatus(201);

        $this->assertCount(0, $espia->textos, 'Un mensaje con adjunto salió partido en varios textos.');
        $this->assertCount(1, $espia->adjuntos, 'El adjunto no salió por el camino de siempre.');
        $this->assertCount(1, $this->mensajes_del_operador($ticket), 'Se creó más de un mensaje para un adjunto.');
    }

    /**
     * Más partes que el tope: las que sobran se pegan a la última, no se pierden.
     *
     * Cada parte cuesta una pausa de 1200ms y un POST adentro del request del operador, así que
     * el tope existe; lo que no puede pasar es que el texto de más desaparezca.
     *
     * @return void
     */
    public function test_mas_de_cinco_partes_se_pegan_a_la_ultima()
    {
        $admin  = $this->crear_admin('tope-de-partes@test.local');
        $client = $this->crear_cliente('+5493417780007');
        $ticket = $this->crear_ticket($client, '+5493417780007');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $bloques = ['Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis', 'Siete'];

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket/' . $ticket->id . '/message', [
            'kind' => 'text',
            'body' => implode("\n\n---\n\n", $bloques),
        ])->assertStatus(201);

        $this->assertCount(5, $espia->textos, 'No se respetó el tope de cinco mensajes.');
        $this->assertSame('Uno', $espia->textos[0]);
        $this->assertSame("Cinco\n\nSeis\n\nSiete", $espia->textos[4], 'Los bloques que sobraban no se pegaron a la última parte.');
    }

    /**
     * Deja la ventana de 24hs abierta para un teléfono sin dejar un ticket reusable.
     *
     * El ticket va CERRADO a propósito: la ventana se resuelve por teléfono y un entrante
     * reciente alcanza para abrirla, pero find_reusable_ticket() solo reusa tickets abiertos.
     * Así el alta crea un hilo nuevo, que es lo que estos tests quieren ejercitar.
     *
     * @param Client $client Cliente dueño.
     * @param string $phone  Teléfono del contacto.
     *
     * @return void
     */
    private function abrir_ventana_sin_dejar_ticket_reusable(Client $client, string $phone): void
    {
        $anterior = SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'status'         => 'closed',
            'source'         => 'whatsapp',
            'whatsapp_phone' => $phone,
            'opened_at'      => now()->subDay(),
            'closed_at'      => now()->subHours(2),
        ]);

        SupportMessage::create([
            'support_ticket_id' => $anterior->id,
            'sender_type'       => 'user',
            'kind'              => 'text',
            'body'              => 'Hola, tengo una duda',
            'delivered_at'      => now()->subHour(),
        ]);
    }

    /**
     * El PRIMER mensaje, el que abre el ticket, también se parte con el separador.
     *
     * Lucas lo pidió expresamente el 27/8/2026, después de ver que la regla valía de la segunda
     * respuesta en adelante pero no en la apertura. Para quien escribe es el mismo cuadro de
     * texto y la misma conversación: que el separador funcione en uno y en el otro no sería una
     * distinción que se entiende sin mirar el código.
     *
     * @return void
     */
    public function test_el_primer_mensaje_del_ticket_nuevo_se_parte_con_la_ventana_abierta()
    {
        $admin  = $this->crear_admin('apertura-partida@test.local');
        $client = $this->crear_cliente('+5493417780010');
        $this->abrir_ventana_sin_dejar_ticket_reusable($client, '+5493417780010');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'source'         => 'whatsapp',
            'client_id'      => $client->id,
            'whatsapp_phone' => '+5493417780010',
            'body'           => "Te escribo por el error que reportaste.\n\n---\n\nYa lo estamos mirando.",
        ]);

        $response->assertStatus(201);

        $this->assertCount(2, $espia->textos, 'El mensaje de apertura no salió partido.');
        $this->assertSame('Te escribo por el error que reportaste.', $espia->textos[0]);
        $this->assertSame('Ya lo estamos mirando.', $espia->textos[1]);
        $this->assertCount(0, $espia->plantillas, 'Con la ventana abierta no tenía que usar plantilla.');

        $ticket = SupportTicket::where('whatsapp_phone', '+5493417780010')
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($ticket, 'No se creó el ticket nuevo.');

        $mensajes = $this->mensajes_del_operador($ticket);

        $this->assertCount(2, $mensajes, 'Cada parte tiene que quedar como un mensaje propio del hilo.');
        $this->assertSame('Te escribo por el error que reportaste.', $mensajes[0]->body);
        $this->assertSame('Ya lo estamos mirando.', $mensajes[1]->body);
        $this->assertNotNull($mensajes[1]->whatsapp_message_id, 'La segunda parte quedó sin el id de Meta.');
    }

    /**
     * Un guión suelto en el mensaje de apertura NO lo parte.
     *
     * Mismo criterio que en el resto de la conversación: solo parte el separador completo.
     *
     * @return void
     */
    public function test_un_guion_suelto_en_el_primer_mensaje_no_lo_parte()
    {
        $admin  = $this->crear_admin('apertura-guion-suelto@test.local');
        $client = $this->crear_cliente('+5493417780011');
        $this->abrir_ventana_sin_dejar_ticket_reusable($client, '+5493417780011');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'source'         => 'whatsapp',
            'client_id'      => $client->id,
            'whatsapp_phone' => '+5493417780011',
            'body'           => "Presupuesto\n---\nte lo mando mañana",
        ])->assertStatus(201);

        $this->assertCount(1, $espia->textos, 'Un guión suelto no tenía que partir la apertura.');
        $this->assertSame("Presupuesto\n---\nte lo mando mañana", $espia->textos[0]);
    }

    /**
     * Con la ventana cerrada, la apertura va por plantilla y sin los guiones del separador.
     *
     * Una plantilla es un solo mensaje: no hay forma de partirla. Y los tres guiones no pueden
     * viajar literales adentro de la variable, porque sanitize_template_variable() aplana los
     * saltos de línea y al cliente le llegarían sueltos en medio de la frase, separando nada.
     *
     * @return void
     */
    public function test_el_primer_mensaje_con_la_ventana_cerrada_va_por_plantilla_sin_los_guiones()
    {
        $admin  = $this->crear_admin('apertura-sin-ventana@test.local');
        $client = $this->crear_cliente('+5493417780012');

        $espia = $this->espiar_sender();
        $this->opener_sin_pausas();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'source'         => 'whatsapp',
            'client_id'      => $client->id,
            'whatsapp_phone' => '+5493417780012',
            'body'           => "Te escribo por el error.\n\n---\n\nYa lo estamos mirando.",
        ])->assertStatus(201);

        $this->assertCount(0, $espia->textos, 'Con la ventana cerrada no puede salir texto libre.');
        $this->assertCount(1, $espia->plantillas, 'Tenía que salir una sola plantilla.');

        $variables = $espia->plantillas[0]['variables'];
        $texto_del_operador = (string) $variables[2];

        $this->assertStringNotContainsString('---', $texto_del_operador, 'Los guiones del separador llegaron al cliente.');
        $this->assertStringContainsString('Te escribo por el error.', $texto_del_operador);
        $this->assertStringContainsString('Ya lo estamos mirando.', $texto_del_operador);
    }
}

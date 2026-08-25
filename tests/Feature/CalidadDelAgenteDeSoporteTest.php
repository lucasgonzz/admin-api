<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AgentIdentity;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\SupportAiImageCollector;
use App\Services\SupportAiSuggestionService;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Las tres mejoras de calidad del agente: ver imágenes, contestar en varios mensajes y hablar
 * con la personalidad compartida con el agente de leads.
 *
 * Lo que más importa acá son los topes y las ausencias. Mandarle al agente todas las imágenes
 * de un ticket largo cuesta plata de verdad —el agentic loop reenvía el primer mensaje hasta
 * cinco veces— y partir un mensaje con la ventana de 24hs cerrada sería mandar tres plantillas
 * en vez de una. Ninguna de las dos cosas la mira un test de camino feliz.
 */
class CalidadDelAgenteDeSoporteTest extends TestCase
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
     * Cliente activo con teléfono.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client            = new Client();
        $client->name      = 'Distribuidora de prueba';
        $client->phone     = '+5493417770001';
        $client->is_active = true;
        $client->save();

        return $client;
    }

    /**
     * Ticket de WhatsApp abierto con la ventana de 24hs abierta.
     *
     * @param Client $client Cliente dueño.
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client): SupportTicket
    {
        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => '+5493417770001',
            'opened_at'        => now()->subHours(2),
        ]);

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
     * Agrega un mensaje del cliente con una imagen adjunta.
     *
     * @param SupportTicket $ticket    Ticket.
     * @param string        $nombre    Nombre del archivo en el disco falso.
     * @param string        $mime      Mime del adjunto.
     * @param int           $bytes     Tamaño del contenido a escribir.
     * @param bool          $en_disco  Si false, se registra el adjunto pero no se escribe el archivo.
     *
     * @return SupportMessage
     */
    private function agregar_imagen(SupportTicket $ticket, string $nombre, string $mime = 'image/png', int $bytes = 64, bool $en_disco = true): SupportMessage
    {
        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'image',
            'body'              => '',
            'delivered_at'      => now(),
        ]);

        $path = 'support_messages/' . $ticket->id . '/' . $nombre;
        $contenido = str_repeat('x', $bytes);

        if ($en_disco) {
            Storage::disk('public')->put($path, $contenido);
        }

        SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => 'public',
            'path'               => $path,
            'mime'               => $mime,
            'size'               => $bytes,
        ]);

        return $mensaje;
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra cada envío.
     *
     * @param int $falla_en_la_parte Número de parte (1-based) que debe fallar; 0 = ninguna.
     *
     * @return WhatsappSendService
     */
    private function espiar_sender(int $falla_en_la_parte = 0): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, string> Textos enviados, en orden. */
            public $textos = [];

            /** @var int Parte que debe fallar, 1-based. */
            public $falla_en = 0;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = $body;

                if ($this->falla_en > 0 && count($this->textos) === $this->falla_en) {
                    $this->last_send_error = 'Kapso rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.' . count($this->textos);
            }

            public function last_send_was_transient(): bool
            {
                // Sin esto, el reintento espera 1500ms y 3500ms de verdad y el test se arrastra.
                return false;
            }
        };

        $espia->falla_en = $falla_en_la_parte;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Mensaje del agente listo para entregar.
     *
     * @param SupportTicket $ticket Ticket.
     * @param string        $body   Texto, con o sin separadores.
     *
     * @return SupportMessage
     */
    private function crear_mensaje_del_agente(SupportTicket $ticket, string $body): SupportMessage
    {
        return SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'admin',
            'kind'              => 'text',
            'body'              => $body,
            'delivered_at'      => now(),
            'ai_generated_at'   => now(),
        ]);
    }

    /**
     * El agente recibe la imagen que mandó el cliente, con el bloque que espera la API.
     *
     * @return void
     */
    public function test_la_imagen_del_cliente_le_llega_al_agente()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'error.png');

        $imagenes = app(SupportAiImageCollector::class)->collect((int) $ticket->id);

        $this->assertCount(1, $imagenes, 'No se juntó la imagen que mandó el cliente.');
        $this->assertSame('image/png', $imagenes[0]['media_type']);
        $this->assertSame(base64_encode(str_repeat('x', 64)), $imagenes[0]['data']);
    }

    /**
     * Nunca se le mandan más de tres imágenes, aunque el ticket tenga diez.
     *
     * El agentic loop reenvía el primer mensaje hasta cinco veces, así que cada imagen se paga
     * hasta cinco veces por consulta. Y arriba de veinte imágenes Meta le aplica un límite de
     * dimensión más estricto a todas las del request, no solo a las que sobran.
     *
     * @return void
     */
    public function test_no_se_le_mandan_mas_de_tres_imagenes()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        for ($i = 1; $i <= 10; $i++) {
            $this->agregar_imagen($ticket, 'captura_' . $i . '.png');
        }

        $imagenes = app(SupportAiImageCollector::class)->collect((int) $ticket->id);

        $this->assertCount(3, $imagenes, 'Se le mandaron más imágenes de las que el tope permite.');
    }

    /**
     * Solo se mandan las imágenes posteriores a la última respuesta del operador.
     *
     * Las fotos viejas de un ticket largo casi nunca son de lo que el cliente pregunta ahora.
     *
     * @return void
     */
    public function test_no_se_mandan_las_imagenes_viejas_del_ticket()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        $this->agregar_imagen($ticket, 'vieja.png');

        // El operador ya contestó sobre esa: la conversación siguió.
        SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'admin',
            'kind'              => 'text',
            'body'              => 'Ya lo miro.',
            'delivered_at'      => now(),
        ]);

        $this->agregar_imagen($ticket, 'nueva.png', 'image/png', 128);

        $imagenes = app(SupportAiImageCollector::class)->collect((int) $ticket->id);

        $this->assertCount(1, $imagenes, 'Arrastró imágenes de antes de la última respuesta del operador.');
        $this->assertSame(base64_encode(str_repeat('x', 128)), $imagenes[0]['data'], 'Mandó la imagen vieja en vez de la nueva.');
    }

    /**
     * Un borrador del agente no cuenta como respuesta del operador.
     *
     * Si contara, el agente se quedaría sin ver la imagen que motivó su propio borrador.
     *
     * @return void
     */
    public function test_un_borrador_no_corta_la_ventana_de_imagenes()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'la-que-importa.png');

        SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'admin',
            'kind'                   => 'text',
            'body'                   => 'Un borrador esperando aprobación',
            'is_ai_suggestion_draft' => true,
            'ai_generated_at'        => now(),
        ]);

        $imagenes = app(SupportAiImageCollector::class)->collect((int) $ticket->id);

        $this->assertCount(1, $imagenes, 'El borrador tapó la imagen que lo originó.');
    }

    /**
     * Un formato que la API no acepta se descarta en vez de romper el envío.
     *
     * @return void
     */
    public function test_se_descarta_un_formato_no_soportado()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'foto.heic', 'image/heic');

        $this->assertCount(0, app(SupportAiImageCollector::class)->collect((int) $ticket->id));
    }

    /**
     * Un adjunto cuyo archivo ya no está en disco se saltea sin romper nada.
     *
     * @return void
     */
    public function test_un_adjunto_sin_archivo_no_rompe()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'perdida.png', 'image/png', 64, false);

        $this->assertCount(0, app(SupportAiImageCollector::class)->collect((int) $ticket->id));
    }

    /**
     * Una imagen más grande que el tope se descarta.
     *
     * @return void
     */
    public function test_se_descarta_una_imagen_demasiado_grande()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'enorme.png', 'image/png', SupportAiImageCollector::MAX_BYTES_PER_IMAGE + 1);

        $this->assertCount(0, app(SupportAiImageCollector::class)->collect((int) $ticket->id));
    }

    /**
     * Con la ventana abierta, el mensaje del agente sale partido en varios.
     *
     * @return void
     */
    public function test_el_mensaje_del_agente_sale_partido()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Hola, ya lo vi.\n---\nEs el aviso de stock negativo.\n---\n¿Querés que te lo desactive?");

        $resultado = app(SupportWhatsappOpenerService::class)->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $this->assertSame('sent', $resultado['delivery']);
        $this->assertCount(3, $espia->textos, 'No salió partido en tres mensajes.');
        $this->assertSame('Hola, ya lo vi.', $espia->textos[0]);
        $this->assertSame('¿Querés que te lo desactive?', $espia->textos[2]);

        // Cada parte queda como mensaje propio del hilo, con su id de Meta.
        $enviados = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('sender_type', 'admin')
            ->whereNotNull('whatsapp_message_id')
            ->get();

        $this->assertCount(3, $enviados, 'El hilo no muestra lo mismo que recibió el cliente.');
        $this->assertSame('Hola, ya lo vi.', (string) SupportMessage::find($mensaje->id)->body);
    }

    /**
     * Un mensaje del operador NO se parte, aunque tenga tres guiones.
     *
     * Partir lo que escribió una persona sería cambiarle el mensaje sin que lo haya pedido.
     *
     * @return void
     */
    public function test_el_mensaje_de_una_persona_no_se_parte()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();

        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'admin',
            'kind'              => 'text',
            'body'              => "Primero esto\n---\ny después esto",
            'delivered_at'      => now(),
        ]);

        app(SupportWhatsappOpenerService::class)->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $this->assertCount(1, $espia->textos, 'Se partió el mensaje de una persona.');
    }

    /**
     * Con la ventana cerrada no se parte: una plantilla no puede llevar tres mensajes.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_no_se_parte()
    {
        $client = $this->crear_cliente();

        // Ticket sin entrantes recientes: la ventana está cerrada.
        $ticket = SupportTicket::create([
            'client_id'        => $client->id,
            'client_user_id'   => 0,
            'client_user_name' => 'Contacto',
            'status'           => 'open',
            'source'           => 'whatsapp',
            'whatsapp_phone'   => '+5493417770001',
            'opened_at'        => now()->subDays(5),
        ]);

        $espia = $this->espiar_sender();
        $mensaje = $this->crear_mensaje_del_agente($ticket, "Una parte\n---\ny otra parte");

        $resultado = app(SupportWhatsappOpenerService::class)->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $this->assertCount(0, $espia->textos, 'Mandó texto libre con la ventana cerrada.');
        $this->assertTrue((bool) $resultado['used_template'], 'No salió por plantilla.');
    }

    /**
     * Si una parte falla, se corta el envío y se registra como parcial.
     *
     * Es la lección del incidente del agente de leads: antes un fallo en la última parte se
     * registraba como "no se envió nada" mientras el cliente ya había recibido y contestado.
     *
     * @return void
     */
    public function test_un_envio_parcial_se_registra_como_parcial()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender(2);

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Uno\n---\nDos\n---\nTres");

        $resultado = app(SupportWhatsappOpenerService::class)->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $this->assertSame('partial', $resultado['delivery'], 'Un envío a medias se registró como si fuera entero, o como si no hubiera salido nada.');
        $this->assertSame(1, $resultado['sent_parts']);
        $this->assertSame(3, $resultado['total_parts']);

        // No se manda la tercera si la segunda nunca llegó.
        $this->assertCount(2, $espia->textos, 'Siguió mandando partes después de que una falló.');
    }

    /**
     * El bloque de imagen llega armado en el request que sale a Anthropic.
     *
     * Los otros tests miran el juntador; este mira el cable: que el primer mensaje pase de ser
     * un string a ser una lista de bloques, que la imagen vaya ANTES del texto —que es lo que
     * recomienda la doc de la API— y que el bloque tenga la forma exacta que la API espera.
     *
     * @return void
     */
    public function test_el_request_a_anthropic_lleva_el_bloque_de_imagen()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => '{"suggested_message":"Ya lo veo","reasoning":"Se ve el error","should_close":false,"should_escalate":false,"escalation_reason":null}',
                ]],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'pantalla.png');

        app(SupportAiSuggestionService::class)->generate($ticket);

        Http::assertSent(function ($request) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }

            $body = $request->data();
            $content = $body['messages'][0]['content'];

            if (! is_array($content)) {
                return false;
            }

            // Rótulo, imagen y recién después el texto: ese orden es el que rinde mejor.
            $tipos = array_map(function ($bloque) {
                return $bloque['type'];
            }, $content);

            if ($tipos !== ['text', 'image', 'text']) {
                return false;
            }

            $imagen = $content[1];

            return $imagen['source']['type'] === 'base64'
                && $imagen['source']['media_type'] === 'image/png'
                && $imagen['source']['data'] === base64_encode(str_repeat('x', 64));
        });
    }

    /**
     * Sin imágenes, el primer mensaje sigue siendo un string pelado, como antes.
     *
     * @return void
     */
    public function test_sin_imagenes_el_request_no_cambia()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content'     => [[
                    'type' => 'text',
                    'text' => '{"suggested_message":"Hola","reasoning":"-","should_close":false,"should_escalate":false,"escalation_reason":null}',
                ]],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        app(SupportAiSuggestionService::class)->generate($ticket);

        Http::assertSent(function ($request) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }

            return is_string($request->data()['messages'][0]['content']);
        });
    }

    /**
     * El agente habla con la identidad compartida con el de leads.
     *
     * @return void
     */
    public function test_el_prompt_usa_la_identidad_compartida()
    {
        AgentIdentity::query()->update(['activa' => false]);
        AgentIdentity::create([
            'name'        => 'Martín',
            'description' => 'Sos Martín, de ComercioCity. Hablás en criollo y vas al grano.',
            'activa'      => true,
        ]);

        $servicio = new class extends SupportAiSuggestionService {
            public function prompt_publico(): string
            {
                return $this->build_identity_block();
            }
        };

        $bloque = $servicio->prompt_publico();

        $this->assertStringContainsString('Sos Martín, de ComercioCity.', $bloque, 'El prompt de soporte no usa la identidad compartida.');
        $this->assertStringContainsString('clientes que YA compraron', $bloque, 'No aclara que en soporte no se vende.');
    }

    /**
     * Sin identidad activa, el agente sigue funcionando con el encabezado de siempre.
     *
     * Quedarse sin agente porque nadie sincronizó un archivo sería peor que quedarse sin
     * personalidad.
     *
     * @return void
     */
    public function test_sin_identidad_activa_el_agente_sigue_andando()
    {
        AgentIdentity::query()->update(['activa' => false]);

        $servicio = new class extends SupportAiSuggestionService {
            public function prompt_publico(): string
            {
                return $this->build_identity_block();
            }
        };

        $this->assertStringContainsString(
            'asistente de soporte técnico de ComercioCity',
            $servicio->prompt_publico(),
            'Sin identidad cargada se quedó sin encabezado.'
        );
    }
}

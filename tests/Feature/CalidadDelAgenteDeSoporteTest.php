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
use App\Services\ClientPhoneDirectory;
use App\Services\SupportWhatsappOpenerService;
use App\Services\WhatsappSessionWindowService;
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
        $contenido = $this->png_de_verdad($bytes);

        if ($en_disco) {
            Storage::disk('public')->put($path, $contenido);
        }

        SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => 'public',
            'path'               => $path,
            'mime'               => $mime,
            'size'               => strlen($contenido),
        ]);

        return $mensaje;
    }

    /**
     * Un PNG valido de 1x1, opcionalmente inflado hasta el tamaño pedido.
     *
     * Tiene que ser una imagen DE VERDAD: el collector resuelve el media_type leyendo los bytes
     * y no la columna, justamente para que una columna mal cargada no le mande a la API un tipo
     * que no corresponde. Con bytes de mentira no se probaria nada de eso.
     *
     * @param int $bytes_minimos Tamaño al que inflar el archivo, si hace falta.
     *
     * @return string
     */
    private function png_de_verdad(int $bytes_minimos = 0): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC');

        // Se rellena DESPUES del IEND: getimagesizefromstring lo sigue leyendo como PNG valido
        // y el archivo pesa lo que el test necesite.
        if ($bytes_minimos > strlen($png)) {
            $png .= str_repeat('x', $bytes_minimos - strlen($png));
        }

        return $png;
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra cada envío.
     *
     * @param int $falla_en_la_parte Número de parte (1-based) que debe fallar; 0 = ninguna.
     *
     * @return WhatsappSendService
     */
    private function espiar_sender(int $falla_en_la_parte = 0, bool $transitorio = false): WhatsappSendService
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

            /** @var bool Si el fallo simulado es transitorio (409/429/5xx). */
            public $transitorio = false;

            public function last_send_was_transient(): bool
            {
                return $this->transitorio;
            }
        };

        $espia->falla_en = $falla_en_la_parte;
        $espia->transitorio = $transitorio;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * El opener con las pausas anuladas.
     *
     * Las esperas son reales y necesarias en producción —1200ms entre partes, 1500ms y 3500ms
     * de backoff—, pero acá sumarían segundos a cada corrida sin probar nada.
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
        $this->assertSame(base64_encode($this->png_de_verdad(64)), $imagenes[0]['data']);
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
        $this->assertSame(base64_encode($this->png_de_verdad(128)), $imagenes[0]['data'], 'Mando la imagen vieja en vez de la nueva.');
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

        $resultado = $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

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
     * Tres guiones sueltos en el mensaje de una persona NO lo parten.
     *
     * Desde la misión del separador estandarizado una persona SÍ puede partir su mensaje, pero
     * solo con el separador completo: renglón en blanco, línea con tres guiones, renglón en
     * blanco. Este caso -tres guiones entre dos renglones normales- es justamente el que
     * escribe alguien que no quiso partir nada, y partírselo sería cambiarle el mensaje.
     *
     * @return void
     */
    public function test_un_guion_suelto_en_el_mensaje_de_una_persona_no_lo_parte()
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

        $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

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

        $resultado = $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

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

        $resultado = $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $this->assertSame('partial', $resultado['delivery'], 'Un envío a medias se registró como si fuera entero, o como si no hubiera salido nada.');
        $this->assertSame(1, $resultado['sent_parts']);
        $this->assertSame(3, $resultado['total_parts']);

        // No se manda la tercera si la segunda nunca llegó.
        $this->assertCount(2, $espia->textos, 'Siguió mandando partes después de que una falló.');
    }

    /**
     * En un envio parcial NO se pierde el texto que no salio.
     *
     * Es el defecto central que tuvo la primera version de esto: el body se pisaba con la parte
     * que habia salido, y el resto no quedaba en ningun lado -ni en la base, ni en el log, ni en
     * la pantalla-. El cliente recibia media respuesta y la otra mitad desaparecia del sistema.
     *
     * @return void
     */
    public function test_el_texto_que_no_salio_queda_en_el_hilo()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender(2);

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Ya vi la captura.\n---\nEs el aviso de stock negativo, se saca en Config.\n---\nTe lo dejo desactivado?");

        $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $filas = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('sender_type', 'admin')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $filas, 'Las partes que no salieron no quedaron en el hilo.');

        $textos = $filas->pluck('body')->all();
        $this->assertSame('Es el aviso de stock negativo, se saca en Config.', $textos[1], 'Se perdio el texto de la parte que no salio.');
        $this->assertSame('Te lo dejo desactivado?', $textos[2], 'Se perdio el texto de la ultima parte.');
    }

    /**
     * La parte que SI salio no queda marcada como no entregada.
     *
     * Marcarla seria el mismo error del incidente de leads al reves: decirle al operador que no
     * llego algo que el cliente ya recibio. Y encima el boton de reintentar no la tocaria,
     * porque ya tiene id de Meta, asi que el cartel rojo no se iria nunca.
     *
     * @return void
     */
    public function test_la_parte_que_salio_no_queda_marcada_como_fallida()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender(2);

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Uno\n---\nDos\n---\nTres");

        $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $primera = SupportMessage::find($mensaje->id);
        $this->assertNotNull($primera->whatsapp_message_id, 'La parte que salio quedo sin id de Meta.');
        $this->assertNull($primera->remote_delivery_status, 'Se marco como no entregada una parte que el cliente si recibio.');

        // Y las que faltan quedan justo al reves: sin id y marcadas, que es lo que las hace
        // reintentables desde la conversacion.
        $pendientes = SupportMessage::where('support_ticket_id', $ticket->id)
            ->where('sender_type', 'admin')
            ->whereNull('whatsapp_message_id')
            ->get();

        $this->assertCount(2, $pendientes);
        foreach ($pendientes as $pendiente) {
            $this->assertSame('not_received', $pendiente->remote_delivery_status);
        }
    }

    /**
     * Un 409 de Kapso se reintenta y la parte termina saliendo.
     *
     * Es el camino que motivo todo el mecanismo -Kapso rechaza con 409 cuando hay otro mensaje
     * en vuelo para la misma conversacion- y el que la primera version del test no ejercitaba,
     * porque el espia anulaba last_send_was_transient() y el reintento nunca corria.
     *
     * @return void
     */
    public function test_un_fallo_transitorio_se_reintenta_y_la_parte_sale()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender(2, true);

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Uno\n---\nDos");

        $resultado = $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

        // Tres llamadas: la parte 1, la parte 2 que falla, y la parte 2 reintentada.
        $this->assertCount(3, $espia->textos, 'No reintento la parte que fallo por un motivo transitorio.');
        $this->assertSame('Dos', $espia->textos[2]);
        $this->assertSame('sent', $resultado['delivery'], 'El reintento salio bien pero se registro como parcial.');
    }

    /**
     * Mas de tres partes se recortan a tres.
     *
     * El prompt le pide dos o tres, pero es un modelo: ocho separadores serian ocho mensajes
     * seguidos al cliente y ocho pausas adentro del request del operador.
     *
     * @return void
     */
    public function test_mas_de_tres_partes_se_recortan()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Uno\n---\nDos\n---\nTres\n---\nCuatro\n---\nCinco");

        $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $this->assertCount(3, $espia->textos, 'Mando mas mensajes de los que el tope permite.');
        $this->assertStringContainsString('Tres', $espia->textos[2]);
        $this->assertStringContainsString('Cinco', $espia->textos[2], 'Se perdio texto al recortar las partes.');
    }

    /**
     * Un mensaje del agente con adjunto no se parte.
     *
     * @return void
     */
    public function test_un_mensaje_con_adjunto_no_se_parte()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $espia  = $this->espiar_sender();

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Mira esto\n---\ny esto");
        SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => 'public',
            'path'               => 'support_messages/' . $ticket->id . '/adjunto.png',
            'mime'               => 'image/png',
            'size'               => 10,
        ]);

        $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $this->assertCount(0, $espia->textos, 'Un mensaje con adjunto tiene que ir por el camino de siempre, no partido.');
    }

    /**
     * Agente con el repositorio de conocimiento respondiendo.
     *
     * Desde el 27/8/2026 `generate()` escala sin consultar a Claude cuando no puede leer el
     * índice del manual ni el protocolo de escalado: un agente sin manual no puede afirmar nada
     * del sistema, y hasta ahora eso pasaba en silencio. Estos dos tests miran el cable que sale
     * a Anthropic, así que necesitan esa precondición cumplida.
     *
     * Se simulan los dos métodos del repositorio y no la GitHub API, porque el `Http::fake()`
     * sin argumentos del setUp() ya dejó un comodín registrado y los stubs que se agreguen
     * después no le ganan: el índice llegaría vacío igual.
     *
     * @return SupportAiSuggestionService
     */
    private function agente_con_repositorio(): SupportAiSuggestionService
    {
        return new class extends SupportAiSuggestionService {
            protected function fetch_manual_file_list(): string
            {
                return "- manual_sistema/README.md\n- manual_sistema/listado/precios.md";
            }

            protected function fetch_escalation_rules(): string
            {
                return "PROTOCOLO DE ESCALADO Y CIERRE:\nProtocolo de prueba.";
            }
        };
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

        $this->agente_con_repositorio()->generate($ticket);

        $esperado = base64_encode($this->png_de_verdad(64));

        Http::assertSent(function ($request) use ($esperado) {
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
                && $imagen['source']['data'] === $esperado;
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

        $this->agente_con_repositorio()->generate($ticket);

        Http::assertSent(function ($request) {
            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return false;
            }

            return is_string($request->data()['messages'][0]['content']);
        });
    }

    /**
     * El tipo que viaja a la API sale de los BYTES, no de la columna.
     *
     * Si la columna dice PNG y los bytes son otra cosa, la API devuelve 400 y `generate()` sale
     * sin ninguna sugerencia: una columna mal cargada apagaria al agente para toda esa
     * conversacion. Aca la columna miente diciendo jpeg y el archivo es un PNG de verdad.
     *
     * @return void
     */
    public function test_el_tipo_real_le_gana_a_la_columna()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'mentirosa.png', 'image/jpeg');

        $imagenes = app(SupportAiImageCollector::class)->collect((int) $ticket->id);

        $this->assertCount(1, $imagenes);
        $this->assertSame('image/png', $imagenes[0]['media_type'], 'Le mando a la API el tipo de la columna en vez del real.');
    }

    /**
     * `image/jpg` no se descarta: es como lo reporta Meta y este repo ya lo sabe.
     *
     * @return void
     */
    public function test_image_jpg_no_se_descarta()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'de-meta.jpg', 'image/jpg');

        $this->assertCount(
            1,
            app(SupportAiImageCollector::class)->collect((int) $ticket->id),
            'Se descarto una imagen que Meta manda como image/jpg y el cliente si mando.'
        );
    }

    /**
     * Un mime con parametros tampoco se descarta.
     *
     * @return void
     */
    public function test_un_mime_con_parametros_no_se_descarta()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->agregar_imagen($ticket, 'con-charset.png', 'image/png; charset=binary');

        $this->assertCount(1, app(SupportAiImageCollector::class)->collect((int) $ticket->id));
    }

    /**
     * Un archivo que no es una imagen se descarta aunque la columna diga que si.
     *
     * @return void
     */
    public function test_un_archivo_que_no_es_imagen_se_descarta()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'kind'              => 'image',
            'body'              => '',
            'delivered_at'      => now(),
        ]);

        $path = 'support_messages/' . $ticket->id . '/no-es-imagen.png';
        Storage::disk('public')->put($path, 'esto es texto plano, no una imagen');

        SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => 'public',
            'path'               => $path,
            'mime'               => 'image/png',
            'size'               => 34,
        ]);

        $this->assertCount(0, app(SupportAiImageCollector::class)->collect((int) $ticket->id));
    }

    /**
     * Al aprobar un borrador que sale a medias, la API NO dice que se entrego.
     *
     * Es el pecado del incidente del lead #440 una capa mas arriba: el opener devolvia un
     * 'partial' honesto y el endpoint lo tiraba, porque miraba el estado de la primera parte
     * -que si salio- en vez del resultado del envio. El operador apretaba Enviar, veia el tilde,
     * y las otras dos partes nunca habian llegado.
     *
     * @return void
     */
    public function test_aprobar_un_borrador_que_sale_a_medias_no_dice_que_se_entrego()
    {
        $admin = new Admin();
        $admin->name     = 'Lucas';
        $admin->email    = 'aprueba-parcial@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender(2);
        $this->opener_sin_pausas();

        $borrador = SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'admin',
            'kind'                   => 'text',
            'body'                   => "Uno\n---\nDos\n---\nTres",
            'is_ai_suggestion_draft' => true,
            'ai_generated_at'        => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/support-message/' . $borrador->id . '/approve-ai-draft');

        $response->assertStatus(200);
        $response->assertJsonPath('delivered', false);
        $response->assertJsonPath('partial', true);
        $response->assertJsonPath('sent_parts', 1);
        $response->assertJsonPath('total_parts', 3);
    }

    /**
     * Las partes que no salieron tambien se avisan a la pantalla.
     *
     * Persistirlas sin emitir el evento dejaba el estado a medias en la base y no en los ojos
     * del operador, que era justo la mitad que faltaba. En el autoenvio, ademas, es la unica
     * forma de enterarse: ahi no hay ningun request de por medio.
     *
     * @return void
     */
    public function test_las_partes_que_no_salieron_se_avisan_a_la_pantalla()
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\SupportMessageReceived::class]);

        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);
        $this->espiar_sender(2);

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Uno\n---\nDos\n---\nTres");

        $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

        // Una por la que salio, y una por cada una de las dos que quedaron pendientes.
        \Illuminate\Support\Facades\Event::assertDispatchedTimes(\App\Events\SupportMessageReceived::class, 3);
    }

    /**
     * El aviso de fallo que reciben los admins dice de que ticket y de que parte se trata.
     *
     * Sin contexto decia solamente "Envio de texto a +549...", indistinguible de cualquier otro
     * fallo de envio del sistema.
     *
     * @return void
     */
    public function test_el_aviso_de_fallo_dice_ticket_y_parte()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        $espia = new class extends WhatsappSendService {
            /** @var array<int, string|null> Contextos con los que se llamo. */
            public $contextos = [];

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->contextos[] = $context;

                return 'wamid.' . count($this->contextos);
            }

            public function last_send_was_transient(): bool
            {
                return false;
            }
        };
        $this->app->instance(WhatsappSendService::class, $espia);

        $mensaje = $this->crear_mensaje_del_agente($ticket, "Uno\n---\nDos");
        $this->opener_sin_pausas()->deliver_follow_up($ticket, $mensaje, 'Lucas');

        $this->assertStringContainsString('ticket #' . $ticket->id, (string) $espia->contextos[0]);
        $this->assertStringContainsString('mensaje 1 de 2', (string) $espia->contextos[0]);
        $this->assertStringContainsString('mensaje 2 de 2', (string) $espia->contextos[1]);
    }

    /**
     * Las imagenes que se caen por el tope tambien se le avisan al agente.
     *
     * Si no, el historial le muestra cinco [IMAGE], recibe tres, y nadie le dice que faltan dos:
     * es la receta para que invente que decian.
     *
     * @return void
     */
    public function test_las_imagenes_que_no_entran_se_cuentan_como_descartadas()
    {
        $client = $this->crear_cliente();
        $ticket = $this->crear_ticket($client);

        for ($i = 1; $i <= 5; $i++) {
            $this->agregar_imagen($ticket, 'captura_' . $i . '.png');
        }

        $collector = app(SupportAiImageCollector::class);
        $collector->collect((int) $ticket->id);

        $this->assertSame(2, $collector->descartadas(), 'Las imagenes que no entraron por el tope no se contaron.');
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

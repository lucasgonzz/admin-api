<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El mensaje que un operador le escribe a mano a un lead también se puede partir en varios.
 *
 * Es la mitad de leads de la misma regla que ya rige en soporte: una persona parte su mensaje
 * solo si escribió el separador completo -renglón en blanco, línea con tres guiones, renglón en
 * blanco-. Lo que estos tests protegen no es el camino feliz, que es una llamada; son los NO y la
 * forma en que queda guardado:
 *
 * 1. Que un "---" suelto no le parta el mensaje a nadie que no lo pidió.
 * 2. Que quede UNA SOLA fila en el hilo, con el texto completo y los contadores de partes. El
 *    hilo de leads ya sabe leer sent_parts_count / total_parts_count / partial_send_pending y
 *    mostrar el envío parcial; una fila por parte -como hace soporte- obligaría a cambiar el SPA.
 * 3. Que un envío que se corta a mitad de camino deje asentado qué llegó y qué falta, en vez de
 *    mentir con "salió todo" o con "no salió nada". Es el mismo problema del lead #440.
 *
 * Se le pega al endpoint real con base de por medio: el envío se sustituye a nivel
 * WhatsappSendService, así que no se toca la red ni Meta, pero la ruta, el controller, el partido
 * y la persistencia son los de verdad.
 */
class SeparadorEnMensajesDirectosALeadsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Corta cualquier salida HTTP real y usa un disco de mentira para el audio.
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
     * Admin que escribe desde el panel.
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
     * Lead con teléfono cargado: sin teléfono no se intenta ningún envío.
     *
     * @param string $nombre Nombre de contacto.
     *
     * @return Lead
     */
    private function crear_lead(string $nombre): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = $nombre;
        $lead->company_name = 'Empresa de ' . $nombre;
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = 'contactado';
        $lead->save();

        return $lead;
    }

    /**
     * Sustituye WhatsappSendService por un espía que registra cada envío.
     *
     * @param int $falla_en_la_parte Número de parte (contando desde 1) que Meta rechaza. 0 = ninguna.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(int $falla_en_la_parte = 0): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, string> Textos enviados, en orden. */
            public $textos = [];

            /** @var array<int, string> Adjuntos de audio que se intentaron mandar. */
            public $audios = [];

            /** @var int Parte que falla, contando desde 1. 0 significa que no falla ninguna. */
            public $falla_en_la_parte = 0;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->textos[] = $body;

                if ($this->falla_en_la_parte > 0 && count($this->textos) === $this->falla_en_la_parte) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.texto.' . count($this->textos);
            }

            /**
             * Ningún fallo del test es transitorio, y eso es a propósito.
             *
             * Si devolviera true, cada parte que falla dispararía los tres intentos con sus
             * usleep de verdad (1500ms + 3500ms) y el caso tardaría cinco segundos midiendo el
             * reloj en vez de la lógica. El camino de los reintentos es el del arreglo del lead
             * #440 y no se toca acá: lo que estos tests miran es el partido y lo que queda
             * guardado.
             *
             * @return bool
             */
            public function last_send_was_transient(): bool
            {
                return false;
            }

            public function send_audio_attachment(string $to, $attachment): ?string
            {
                $this->audios[] = (string) ($attachment->path ?? '');

                return 'wamid.audio.' . count($this->audios);
            }
        };

        $espia->falla_en_la_parte = $falla_en_la_parte;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Mensajes del operador que quedaron en el hilo, en orden.
     *
     * @param Lead $lead Lead dueño del hilo.
     *
     * @return \Illuminate\Support\Collection
     */
    private function mensajes_del_operador(Lead $lead)
    {
        return LeadMessage::where('lead_id', $lead->id)
            ->where('sender', 'setter')
            ->orderBy('id')
            ->get();
    }

    /**
     * Con el separador completo, el mensaje directo sale en varios WhatsApp y queda en una fila.
     *
     * @return void
     */
    public function test_el_mensaje_directo_sale_partido_con_el_separador_completo()
    {
        $admin = $this->crear_admin('directo-separador@test.local');
        $lead  = $this->crear_lead('Brisa');
        $espia = $this->espiar_sender();

        $contenido = "Te mando el link.\n\n---\n\nCualquier cosa avisame.";

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/send-direct-message', ['content' => $contenido])
            ->assertStatus(200);

        $this->assertCount(2, $espia->textos, 'El mensaje directo no salió partido en dos.');
        $this->assertSame('Te mando el link.', $espia->textos[0]);
        $this->assertSame('Cualquier cosa avisame.', $espia->textos[1]);

        // Una sola fila, no una por parte: el hilo de leads muestra el partido con los contadores.
        $mensajes = $this->mensajes_del_operador($lead);
        $this->assertCount(1, $mensajes, 'Se guardó una fila por parte en vez de un solo mensaje.');

        $mensaje = $mensajes->first();
        $this->assertSame($contenido, (string) $mensaje->content, 'El hilo no guardó el texto completo con sus separadores.');
        $this->assertSame(2, (int) $mensaje->total_parts_count);
        $this->assertSame(2, (int) $mensaje->sent_parts_count);
        $this->assertNull($mensaje->partial_send_pending, 'Salió todo y quedó texto pendiente igual.');
    }

    /**
     * Tres guiones sueltos entre dos renglones no parten nada.
     *
     * Es el caso que más se escribe sin querer partir nada, y el piso que no se puede perder al
     * agregar la forma explícita de pedirlo.
     *
     * @return void
     */
    public function test_un_guion_suelto_no_parte_el_mensaje_directo()
    {
        $admin = $this->crear_admin('directo-guion-suelto@test.local');
        $lead  = $this->crear_lead('Marcelo');
        $espia = $this->espiar_sender();

        $contenido = "Primero esto\n---\ny después esto";

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/send-direct-message', ['content' => $contenido])
            ->assertStatus(200);

        $this->assertCount(1, $espia->textos, 'Se partió un mensaje que no traía el separador completo.');
        $this->assertSame($contenido, $espia->textos[0]);

        $mensaje = $this->mensajes_del_operador($lead)->first();
        $this->assertNull($mensaje->total_parts_count, 'Un mensaje que no se partió quedó con contadores de partes.');
        $this->assertNull($mensaje->sent_parts_count);
    }

    /**
     * Si el envío se corta a mitad de camino, queda asentado qué llegó y qué falta mandar.
     *
     * Es el caso del lead #440: no alcanza con "salió" o "no salió", porque el lead ya recibió
     * parte del mensaje y el operador tiene que poder mandar el resto sin escribirlo de nuevo.
     *
     * @return void
     */
    public function test_un_envio_parcial_deja_lo_que_falta_para_copiar()
    {
        $admin = $this->crear_admin('directo-parcial@test.local');
        $lead  = $this->crear_lead('Camila');
        // Meta rechaza la segunda parte: la tercera ni se intenta.
        $espia = $this->espiar_sender(2);

        $contenido = "Primera parte.\n\n---\n\nSegunda parte.\n\n---\n\nTercera parte.";

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/send-direct-message', ['content' => $contenido])
            ->assertStatus(200);

        $this->assertCount(2, $espia->textos, 'No cortó el envío en la parte que falló.');

        $mensaje = $this->mensajes_del_operador($lead)->first();
        $this->assertSame(1, (int) $mensaje->sent_parts_count);
        $this->assertSame(3, (int) $mensaje->total_parts_count);
        $this->assertSame(
            "Segunda parte.\n\n---\n\nTercera parte.",
            (string) $mensaje->partial_send_pending,
            'Lo que quedó sin enviar no se puede copiar y remandar tal cual.'
        );

        // Algo llegó al lead: registrar un error en el hilo diría lo contrario.
        $errores = LeadMessage::where('lead_id', $lead->id)->where('is_error', true)->count();
        $this->assertSame(0, $errores, 'Un envío parcial se registró como fallo total.');
    }

    /**
     * Si no sale ninguna parte, el mensaje igual queda en el hilo y el fallo se asienta aparte.
     *
     * El mensaje se guarda igual con `status = 'enviado'` y sin id de Meta: es exactamente lo que
     * ya pasaba con un envío de una sola parte que WhatsApp rechaza. Es raro, pero cambiarlo es
     * otra misión; acá se agrega el partido, no se rediseña el manejo de fallos.
     *
     * @return void
     */
    public function test_si_no_sale_ninguna_parte_el_mensaje_igual_queda_en_el_hilo()
    {
        $admin = $this->crear_admin('directo-sin-salir@test.local');
        $lead  = $this->crear_lead('Nahuel');
        // Falla la primera: no llega nada.
        $espia = $this->espiar_sender(1);

        $contenido = "Una parte.\n\n---\n\nY otra parte.";

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/send-direct-message', ['content' => $contenido])
            ->assertStatus(200);

        $this->assertCount(1, $espia->textos, 'Siguió mandando partes después de que la primera no saliera.');

        $mensaje = $this->mensajes_del_operador($lead)->first();
        $this->assertNotNull($mensaje, 'El mensaje desapareció del hilo porque no salió.');
        $this->assertSame(0, (int) $mensaje->sent_parts_count);
        $this->assertSame(2, (int) $mensaje->total_parts_count);
        $this->assertNull($mensaje->whatsapp_message_id);
        // No hace falta duplicar lo pendiente: el texto entero sigue en `content`.
        $this->assertNull($mensaje->partial_send_pending);

        $errores = LeadMessage::where('lead_id', $lead->id)->where('is_error', true)->count();
        $this->assertSame(1, $errores, 'No quedó constancia en el hilo de que no salió nada.');
    }

    /**
     * Un mensaje sin separador sale como salió siempre.
     *
     * @return void
     */
    public function test_el_mensaje_de_una_sola_parte_sigue_saliendo_como_siempre()
    {
        $admin = $this->crear_admin('directo-una-parte@test.local');
        $lead  = $this->crear_lead('Sofía');
        $espia = $this->espiar_sender();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/lead/' . $lead->id . '/send-direct-message', ['content' => 'Hola, ¿cómo va?'])
            ->assertStatus(200);

        $this->assertCount(1, $espia->textos);
        $this->assertSame('Hola, ¿cómo va?', $espia->textos[0]);

        $mensaje = $this->mensajes_del_operador($lead)->first();
        $this->assertSame('enviado', (string) $mensaje->status);
        $this->assertSame((int) $admin->id, (int) $mensaje->sent_by_admin_id);
        $this->assertNotNull($mensaje->whatsapp_message_id);
        $this->assertNull($mensaje->sent_parts_count);
        $this->assertNull($mensaje->total_parts_count);
        $this->assertNull($mensaje->partial_send_pending);
    }

    /**
     * Un audio no se parte: el adjunto viaja en un mensaje solo.
     *
     * @return void
     */
    public function test_un_audio_directo_no_se_parte()
    {
        $admin = $this->crear_admin('directo-audio@test.local');
        $lead  = $this->crear_lead('Iván');
        $espia = $this->espiar_sender();

        $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/lead/' . $lead->id . '/send-direct-audio', [
                'audio' => UploadedFile::fake()->create('nota.ogg', 10, 'audio/ogg'),
            ])
            ->assertStatus(200);

        $this->assertCount(0, $espia->textos, 'Un audio salió por el camino del texto partido.');
        $this->assertCount(1, $espia->audios, 'El audio no salió por el camino de siempre.');

        $mensajes = $this->mensajes_del_operador($lead);
        $this->assertCount(1, $mensajes, 'Se creó más de un mensaje para un solo audio.');
        $this->assertNull($mensajes->first()->total_parts_count, 'Un audio quedó con contadores de partes.');
    }
}

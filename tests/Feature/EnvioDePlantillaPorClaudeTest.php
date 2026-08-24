<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Los frenos del envío de plantillas por Claude (endpoints `claude/leads/{id}/send-template` y
 * `claude/send-template-batch`).
 *
 * Este test existe por un motivo puntual y no por completitud: estos dos endpoints son los únicos
 * de todo `claude/*` que le mandan un WhatsApp a un lead REAL de producción, y un mensaje enviado
 * no se deshace. Los frenos —simulación por defecto, confirmación del conteo exacto, tope de lote
 * y cooldown— son la única cosa entre un filtro mal escrito y 50 personas recibiendo un mensaje
 * que no correspondía. Todo lo que se verifica acá es "NO se envió nada", que es exactamente lo
 * que un test de camino feliz nunca mira.
 *
 * El envío se sustituye a nivel WhatsappSendService, así que no se toca la red ni Meta, pero el
 * resto del camino (validación, resolución de leads, persistencia del LeadMessage) es el real.
 */
class EnvioDePlantillaPorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude';

    /**
     * Setea la clave de ingesta en config para que el middleware fail-closed deje pasar.
     *
     * En el .env del slot CLAUDE_TASK_INGEST_KEY está vacía, así que sin esto TODO `claude/*`
     * devuelve 401 y los tests medirían el middleware en vez del controlador.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Crea un lead con teléfono, listo para recibir un envío.
     *
     * @param string $nombre
     * @param string $status
     *
     * @return Lead
     */
    private function crear_lead(string $nombre, string $status = 'contactado'): Lead
    {
        $lead               = new Lead();
        $lead->contact_name = $nombre;
        $lead->company_name = 'Empresa de ' . $nombre;
        $lead->phone        = '549341' . random_int(1000000, 9999999);
        $lead->status       = $status;
        $lead->save();

        return $lead;
    }

    /**
     * Sustituye WhatsappSendService por un espía que cuenta los envíos en vez de llamar a Meta.
     *
     * @param bool $confirma True: devuelve un whatsapp_message_id. False: simula envío no confirmado.
     *
     * @return WhatsappSendService El espía, ya registrado en el contenedor.
     */
    private function espiar_sender(bool $confirma = true): WhatsappSendService
    {
        $espia = new class extends WhatsappSendService {
            /** @var array<int, array<string, mixed>> Envíos que se intentaron. */
            public $envios = [];

            /** @var bool Si el envío se confirma o no. */
            public $confirma = true;

            public function send_template(string $to, string $template_name, array $variables = [], string $language_code = 'es_AR', ?string $context = null): ?string
            {
                $this->envios[] = [
                    'to'            => $to,
                    'template_name' => $template_name,
                    'variables'     => $variables,
                    'language_code' => $language_code,
                ];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.test.' . count($this->envios);
            }
        };

        $espia->confirma = $confirma;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Sin el header de la clave, el endpoint de envío no atiende.
     *
     * @return void
     */
    public function test_sin_clave_el_envio_devuelve_401()
    {
        $lead  = $this->crear_lead('Sin clave');
        $espia = $this->espiar_sender();

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
            'template_name' => 'cc_recuperacion_demora_propia',
            'content'       => 'Hola!',
        ]);

        $respuesta->assertStatus(401);
        $this->assertCount(0, $espia->envios, 'No se pudo haber intentado ningún envío sin clave.');
    }

    /**
     * El envío individual deja el mensaje marcado como enviado por Claude, sin admin y sin
     * consumir el cupo de seguimientos del lead.
     *
     * @return void
     */
    public function test_el_envio_individual_marca_el_mensaje_como_enviado_por_claude()
    {
        $lead  = $this->crear_lead('Marcado');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => 'cc_recuperacion_demora_propia',
                'variables'     => ['Marcado'],
                'content'       => 'Hola Marcado! Perdoná la demora.',
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['enviado' => true]);

        $this->assertCount(1, $espia->envios);
        $this->assertSame('cc_recuperacion_demora_propia', $espia->envios[0]['template_name']);
        $this->assertSame(['Marcado'], $espia->envios[0]['variables']);

        $mensaje = LeadMessage::query()->where('lead_id', $lead->id)->first();
        $this->assertNotNull($mensaje, 'Tiene que quedar registrado el mensaje en la conversación.');
        $this->assertSame('claude', $mensaje->sent_via, 'El origen tiene que quedar marcado como claude.');
        $this->assertNull($mensaje->sent_by_admin_id, 'No hay admin detrás de un envío de Claude.');
        $this->assertSame('setter', $mensaje->sender, 'Con sender=sistema se pintaría como sugerencia de IA.');
        $this->assertFalse((bool) $mensaje->is_followup, 'is_followup=false para no consumir el cupo del lead.');
        $this->assertNotNull($mensaje->whatsapp_message_id);
    }

    /**
     * Un envío que no se confirma igual queda registrado en el hilo, con el motivo, para que se
     * pueda encontrar después con `claude/messages?has_send_error=1`.
     *
     * @return void
     */
    public function test_un_envio_que_falla_igual_queda_registrado_con_el_motivo()
    {
        $lead  = $this->crear_lead('Fallado');
        $espia = $this->espiar_sender(false);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/leads/' . $lead->id . '/send-template', [
                'template_name' => 'cc_recuperacion_demora_propia',
                'content'       => 'Hola Fallado!',
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['enviado' => false]);

        $mensaje = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->where('sent_via', 'claude')
            ->first();

        $this->assertNotNull($mensaje, 'Un envío fallido igual tiene que dejar rastro en el hilo.');
        $this->assertNull($mensaje->whatsapp_message_id);
        $this->assertNotEmpty($mensaje->whatsapp_send_error, 'Sin motivo, el mensaje sería invisible para la consulta de fallidos.');
    }

    /**
     * 🔴 El freno principal: por defecto el lote SIMULA. Sin dry_run explícito no sale ni un mensaje.
     *
     * @return void
     */
    public function test_el_lote_simula_por_defecto_y_no_envia_nada()
    {
        $uno  = $this->crear_lead('Uno');
        $dos  = $this->crear_lead('Dos');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'         => [$uno->id, $dos->id],
                'template_name'    => 'cc_recuperacion_demora_propia',
                'content_template' => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => true, 'enviarian' => 2]);

        $this->assertCount(0, $espia->envios, 'La simulación no puede llamar al sender ni una vez.');
        $this->assertSame(
            0,
            LeadMessage::query()->whereIn('lead_id', [$uno->id, $dos->id])->count(),
            'La simulación no puede crear ningún LeadMessage.'
        );
    }

    /**
     * 🔴 Un confirm_count que no coincide corta el lote entero, sin enviar nada.
     *
     * Es el caso que protege del error más probable: yo pido la simulación, alguien agrega o saca
     * un lead de la lista, y el envío real sale a un conjunto distinto del que se revisó.
     *
     * @return void
     */
    public function test_un_confirm_count_equivocado_no_envia_nada()
    {
        $uno   = $this->crear_lead('Uno');
        $dos   = $this->crear_lead('Dos');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$uno->id, $dos->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
                'dry_run'              => false,
                'confirm_count'        => 3,
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios, 'Con el conteo desfasado no puede salir ni un mensaje.');
        $this->assertSame(0, LeadMessage::query()->whereIn('lead_id', [$uno->id, $dos->id])->count());
    }

    /**
     * 🔴 Sin confirm_count, dry_run=false tampoco alcanza para enviar.
     *
     * @return void
     */
    public function test_sin_confirm_count_no_se_envia_aunque_dry_run_sea_false()
    {
        $lead  = $this->crear_lead('Solo');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$lead->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
                'dry_run'              => false,
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios);
    }

    /**
     * 🔴 Un lote por encima del tope se rechaza entero, sin enviar nada.
     *
     * El tope existe por el timeout: 15 s de timeout por envío con dos reintentos son ~45 s por
     * fallo, y con Meta caído —el escenario mismo para el que existe este endpoint— un lote grande
     * se comería el request entero sin devolver respuesta.
     *
     * @return void
     */
    public function test_un_lote_por_encima_del_tope_se_rechaza_entero()
    {
        $espia = $this->espiar_sender();

        /* No hace falta que los leads existan: el tope se chequea antes de resolverlos. */
        $ids = range(1, 51);

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'         => $ids,
                'template_name'    => 'cc_recuperacion_demora_propia',
                'content_template' => 'Hola!',
                'dry_run'          => false,
                'confirm_count'    => 51,
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios, 'Por encima del tope no puede salir ni un mensaje.');
    }

    /**
     * Con el conteo correcto, el lote sí envía, y marca cada mensaje como enviado por Claude.
     *
     * @return void
     */
    public function test_con_el_conteo_correcto_el_lote_envia()
    {
        $uno   = $this->crear_lead('Uno');
        $dos   = $this->crear_lead('Dos');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$uno->id, $dos->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
                'dry_run'              => false,
                'confirm_count'        => 2,
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => false, 'enviados' => 2]);

        $this->assertCount(2, $espia->envios);

        $mensajes = LeadMessage::query()
            ->whereIn('lead_id', [$uno->id, $dos->id])
            ->where('sent_via', 'claude')
            ->get();

        $this->assertCount(2, $mensajes);
        foreach ($mensajes as $mensaje) {
            $this->assertFalse((bool) $mensaje->is_followup);
            $this->assertNull($mensaje->sent_by_admin_id);
        }

        /* El contenido se renderiza por lead, no se manda el template crudo. */
        $contenidos = $mensajes->pluck('content')->all();
        sort($contenidos);
        $this->assertSame(['Hola Dos!', 'Hola Uno!'], $contenidos);
    }

    /**
     * 🔴 El cooldown: un lead que ya recibió un mensaje de Claude en las últimas 24 hs se omite.
     *
     * Es lo que hace seguro reintentar un lote que se cortó por red a mitad de camino: sin esto,
     * los leads que ya habían recibido el mensaje lo reciben dos veces.
     *
     * @return void
     */
    public function test_un_lead_con_envio_reciente_de_claude_queda_omitido()
    {
        $reciente = $this->crear_lead('Reciente');
        $limpio   = $this->crear_lead('Limpio');

        /* Envío previo de Claude, dentro de la ventana de cooldown. */
        LeadMessage::create([
            'lead_id'  => $reciente->id,
            'sender'   => 'setter',
            'content'  => 'Mensaje anterior de Claude',
            'status'   => 'enviado',
            'sent_via' => 'claude',
        ]);

        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$reciente->id, $limpio->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => true, 'enviarian' => 1]);

        $cuerpo = $respuesta->json();
        $this->assertCount(1, $cuerpo['omitidos'], 'El lead con envío reciente tiene que salir omitido.');
        $this->assertSame($reciente->id, $cuerpo['omitidos'][0]['lead_id']);
    }

    /**
     * Un lead sin teléfono se omite del lote en vez de romperlo.
     *
     * @return void
     */
    public function test_un_lead_sin_telefono_se_omite_sin_romper_el_lote()
    {
        $con_telefono = $this->crear_lead('Con telefono');

        $sin_telefono               = new Lead();
        $sin_telefono->contact_name = 'Sin telefono';
        $sin_telefono->status       = 'contactado';
        $sin_telefono->save();

        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$con_telefono->id, $sin_telefono->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['contact_name'],
            ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['dry_run' => true, 'enviarian' => 1]);
        $this->assertCount(1, $respuesta->json()['omitidos']);
    }

    /**
     * El teléfono no puede usarse como variable de plantilla: sería la puerta de atrás del
     * enmascarado que hace la simulación.
     *
     * @return void
     */
    public function test_el_telefono_no_se_puede_usar_como_variable()
    {
        $lead  = $this->crear_lead('Curioso');
        $espia = $this->espiar_sender();

        $respuesta = $this->withHeaders($this->headers())
            ->postJson('/api/claude/send-template-batch', [
                'lead_ids'             => [$lead->id],
                'template_name'        => 'cc_recuperacion_demora_propia',
                'content_template'     => 'Hola {{1}}!',
                'variables_desde_lead' => ['phone'],
            ]);

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios);
    }
}

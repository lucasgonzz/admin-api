<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\WhatsappSendService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los frenos del envío de TEXTO LIBRE a un lead (`POST claude/leads/{id}/message`).
 *
 * Este endpoint es el único de `claude/*` que le manda a un lead real un texto que no pasó por
 * ninguna plantilla aprobada, así que lo que se verifica acá es sobre todo cuándo NO manda:
 *
 *   1. Con la ventana de 24 hs de Meta cerrada, el mensaje NO SALDRÍA —Meta lo rechaza— y el
 *      endpoint tiene que frenarlo antes de intentarlo, sin dejar ninguna fila.
 *   2. Un solo mensaje por turno de conversación: respondido el lead una vez, el turno es de él.
 *      Es además lo que hace que un reintento tras un corte de red no le mande el mensaje dos veces.
 *
 * 🔴 La ventana se prueba con el WhatsappSessionWindowService REAL, abriéndola como se abre en
 * producción: con un mensaje entrante del lead dentro de las últimas 24 hs. Sustituirlo por un
 * doble haría pasar el test aunque el criterio de la ventana estuviera mal leído desde el
 * controlador, que es justo lo que hay que verificar.
 *
 * Lo único sustituido es WhatsappSendService, para no tocar la red ni Meta.
 */
class MensajeDeTextoLibreAUnLeadTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude';

    /**
     * Setea la clave de ingesta en config para que el middleware fail-closed deje pasar.
     *
     * En el .env del slot la clave está vacía, así que sin esto TODO `claude/*` devuelve 401 y los
     * tests medirían el middleware en vez del controlador.
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
    private function crear_lead(string $nombre, string $status = 'solicita_disponibilidad'): Lead
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
     * Abre la ventana de 24 hs del lead como se abre en producción: con un entrante suyo reciente.
     *
     * @param Lead $lead
     * @param int  $hace_horas Antigüedad del entrante. Más de 24 deja la ventana CERRADA.
     *
     * @return LeadMessage
     */
    private function entrante_del_lead(Lead $lead, int $hace_horas = 1): LeadMessage
    {
        $mensaje          = new LeadMessage();
        $mensaje->lead_id = $lead->id;
        $mensaje->sender  = 'lead';
        $mensaje->content = 'Dale, mañana nos podemos ver.';
        $mensaje->status  = 'enviado';
        $mensaje->save();

        /* El created_at se pisa con update() y no con el modelo: LeadMessage tiene timestamps
           automáticos y booted() que reacciona al guardado. Acá sólo interesa la antigüedad. */
        $momento = now()->subHours($hace_horas);
        DB::table('lead_messages')->where('id', $mensaje->id)->update([
            'created_at' => $momento,
            'updated_at' => $momento,
        ]);

        return $mensaje->refresh();
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
            /** @var array<int, array<string, mixed>> Textos que se intentaron enviar. */
            public $envios = [];

            /** @var bool Si el envío se confirma o no. */
            public $confirma = true;

            public function send_text(string $to, string $body, ?string $context = null, bool $skip_failure_notification = false): ?string
            {
                $this->envios[] = [
                    'to'      => $to,
                    'body'    => $body,
                    'context' => $context,
                ];

                if (! $this->confirma) {
                    $this->last_send_error = 'Meta rechazó el envío (simulado en el test).';

                    return null;
                }

                return 'wamid.libre.' . count($this->envios);
            }
        };

        $espia->confirma = $confirma;
        $this->app->instance(WhatsappSendService::class, $espia);

        return $espia;
    }

    /**
     * Cantidad de mensajes salientes del lead, que es lo que no tiene que crecer cuando el
     * endpoint frena.
     *
     * @param Lead $lead
     *
     * @return int
     */
    private function salientes_de(Lead $lead): int
    {
        return LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->whereIn('sender', ['setter', 'sistema'])
            ->count();
    }

    /**
     * 🔴 El test central: con la ventana cerrada no se intenta el envío ni se crea ninguna fila.
     *
     * Fuera de la ventana Meta rechaza el texto libre, así que "intentarlo igual" no sería un
     * mensaje que se pierde: sería una fila en la conversación diciendo que se le escribió a
     * alguien que nunca recibió nada.
     *
     * @return void
     */
    public function test_con_la_ventana_cerrada_no_se_manda_nada_ni_queda_fila()
    {
        $lead  = $this->crear_lead('Ventana cerrada');
        $espia = $this->espiar_sender();

        /* Entrante de hace 30 hs: la ventana de 24 ya venció. */
        $this->entrante_del_lead($lead, 30);

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Che, ¿coordinamos la demo?',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['ventana_abierta' => false]);
        $this->assertCount(0, $espia->envios, 'Con la ventana cerrada no se puede haber intentado ningún envío.');
        $this->assertSame(0, $this->salientes_de($lead), 'Con la ventana cerrada no se puede haber creado ninguna fila.');
    }

    /**
     * Un lead sin ningún entrante tiene la ventana cerrada: ante la duda, cerrada.
     *
     * @return void
     */
    public function test_sin_ningun_entrante_la_ventana_esta_cerrada()
    {
        $lead  = $this->crear_lead('Sin entrantes');
        $espia = $this->espiar_sender();

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Hola!',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['ventana_abierta' => false]);
        $this->assertCount(0, $espia->envios);
        $this->assertSame(0, $this->salientes_de($lead));
    }

    /**
     * Con la ventana abierta el mensaje sale y queda registrado como enviado por Claude.
     *
     * @return void
     */
    public function test_con_la_ventana_abierta_el_mensaje_sale_y_queda_registrado()
    {
        $lead  = $this->crear_lead('Ventana abierta');
        $espia = $this->espiar_sender();

        $this->entrante_del_lead($lead, 2);

        $texto     = 'Dale Carim, ¿a qué hora te queda cómodo hoy?';
        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => $texto,
        ], $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['enviado' => true, 'ventana_abierta' => true]);

        $this->assertCount(1, $espia->envios, 'Tendría que haberse intentado exactamente un envío.');
        $this->assertSame($texto, $espia->envios[0]['body'], 'El texto que viaja a Meta es el que se pidió.');
        $this->assertSame($lead->phone, $espia->envios[0]['to']);

        $mensaje = LeadMessage::query()
            ->where('lead_id', $lead->id)
            ->whereIn('sender', ['setter', 'sistema'])
            ->first();

        $this->assertNotNull($mensaje, 'El mensaje enviado tiene que quedar en la conversación.');
        $this->assertSame($texto, $mensaje->content);
        $this->assertSame('enviado', $mensaje->status);
        $this->assertSame(LeadMessage::SENT_VIA_CLAUDE, $mensaje->sent_via);
        $this->assertNotNull($mensaje->whatsapp_message_id, 'Un envío confirmado deja el id de Meta.');
        $this->assertNotNull($mensaje->sent_at, 'Un envío confirmado deja sent_at.');
        $this->assertNull($mensaje->sent_by_admin_id, 'Acá no hay admin: la request entra por la clave de ingesta.');
        $this->assertFalse((bool) $mensaje->is_followup, 'El mensaje de Claude no consume el cupo de seguimientos.');
    }

    /**
     * Sin teléfono no hay a dónde mandar: 422 y no se crea nada.
     *
     * @return void
     */
    public function test_un_lead_sin_telefono_da_422_sin_crear_nada()
    {
        $lead = $this->crear_lead('Sin teléfono');
        $this->entrante_del_lead($lead, 1);

        /* El teléfono se saca DESPUÉS del entrante: la ventana se abre por el número, y lo que se
           prueba acá es el freno del teléfono, no el de la ventana. */
        $lead->phone = null;
        $lead->save();

        $espia = $this->espiar_sender();

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Hola!',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios);
        $this->assertSame(0, $this->salientes_de($lead));
    }

    /**
     * 🔴 Un solo mensaje por turno: respondido el lead, el segundo mensaje se frena.
     *
     * Es también la prueba de que un reintento tras un corte de red no duplica el envío.
     *
     * @return void
     */
    public function test_el_segundo_mensaje_del_mismo_turno_se_frena()
    {
        $lead  = $this->crear_lead('Turno ocupado');
        $espia = $this->espiar_sender();

        $this->entrante_del_lead($lead, 2);

        $primera = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Primera respuesta.',
        ], $this->headers());
        $primera->assertStatus(200);

        $segunda = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Segunda, sin que haya contestado.',
        ], $this->headers());

        $segunda->assertStatus(422);
        $segunda->assertJson(['turno_del_lead' => true]);
        $this->assertCount(1, $espia->envios, 'El segundo mensaje no se puede haber intentado.');
        $this->assertSame(1, $this->salientes_de($lead), 'Tiene que haber quedado una sola fila.');
    }

    /**
     * Cuando el lead vuelve a escribir, el turno se libera y el mensaje siguiente pasa.
     *
     * @return void
     */
    public function test_cuando_el_lead_contesta_el_turno_se_libera()
    {
        $lead  = $this->crear_lead('Turno liberado');
        $espia = $this->espiar_sender();

        $this->entrante_del_lead($lead, 3);

        $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Primera respuesta.',
        ], $this->headers())->assertStatus(200);

        /* El lead contesta: su mensaje queda después del saliente y devuelve el turno. */
        $this->entrante_del_lead($lead, 0);

        $segunda = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Segunda, ya con el turno devuelto.',
        ], $this->headers());

        $segunda->assertStatus(200);
        $this->assertCount(2, $espia->envios, 'Con el turno devuelto, el segundo mensaje sale.');
    }

    /**
     * El freno de turno se puede saltear, pero solo pidiéndolo explícitamente.
     *
     * @return void
     */
    public function test_el_freno_de_turno_se_saltea_con_el_parametro_explicito()
    {
        $lead  = $this->crear_lead('Turno salteado');
        $espia = $this->espiar_sender();

        $this->entrante_del_lead($lead, 2);

        $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Primera.',
        ], $this->headers())->assertStatus(200);

        $segunda = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content'                   => 'Segunda, pedida a propósito.',
            'permitir_varios_por_turno' => true,
        ], $this->headers());

        $segunda->assertStatus(200);
        $this->assertCount(2, $espia->envios);
    }

    /**
     * Una sugerencia sin enviar NO ocupa el turno.
     *
     * Es la razón por la que el freno usa `apply_reply_to_lead_conditions()` y no un conteo de
     * filas: un mensaje en estado `sugerido` nunca salió, así que el lead sigue esperando respuesta.
     *
     * @return void
     */
    public function test_una_sugerencia_sin_enviar_no_ocupa_el_turno()
    {
        $lead  = $this->crear_lead('Con sugerencia');
        $espia = $this->espiar_sender();

        $this->entrante_del_lead($lead, 2);

        $sugerencia          = new LeadMessage();
        $sugerencia->lead_id = $lead->id;
        $sugerencia->sender  = 'sistema';
        $sugerencia->content = 'Sugerencia que nunca se mandó.';
        $sugerencia->status  = 'sugerido';
        $sugerencia->save();

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'La respuesta de verdad.',
        ], $this->headers());

        $respuesta->assertStatus(200);
        $this->assertCount(1, $espia->envios, 'Una sugerencia sin enviar no puede bloquear la respuesta.');
    }

    /**
     * A un lead ya promovido a cliente no se le manda por acá.
     *
     * @return void
     */
    public function test_a_un_lead_ya_promovido_a_cliente_no_se_le_manda()
    {
        $lead = $this->crear_lead('Ya es cliente');
        $this->entrante_del_lead($lead, 1);

        $lead->status = 'cerrado_ganado';
        $lead->save();

        $espia = $this->espiar_sender();

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Hola!',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios);
        $this->assertSame(0, $this->salientes_de($lead));
    }

    /**
     * Sin la clave de ingesta el endpoint no atiende.
     *
     * @return void
     */
    public function test_sin_clave_no_atiende()
    {
        $lead  = $this->crear_lead('Sin clave');
        $espia = $this->espiar_sender();

        $this->entrante_del_lead($lead, 1);

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => 'Hola!',
        ]);

        $respuesta->assertStatus(401);
        $this->assertCount(0, $espia->envios, 'No se pudo haber intentado ningún envío sin clave.');
    }

    /**
     * Un texto vacío no llega a intentarse.
     *
     * @return void
     */
    public function test_el_texto_vacio_no_se_manda()
    {
        $lead  = $this->crear_lead('Texto vacío');
        $espia = $this->espiar_sender();

        $this->entrante_del_lead($lead, 1);

        $respuesta = $this->postJson('/api/claude/leads/' . $lead->id . '/message', [
            'content' => '   ',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertCount(0, $espia->envios);
        $this->assertSame(0, $this->salientes_de($lead));
    }

    /**
     * Un lead que no existe da 404, no una página de error.
     *
     * @return void
     */
    public function test_un_lead_inexistente_da_404()
    {
        $espia = $this->espiar_sender();

        $respuesta = $this->postJson('/api/claude/leads/99999999/message', [
            'content' => 'Hola!',
        ], $this->headers());

        $respuesta->assertStatus(404);
        $this->assertCount(0, $espia->envios);
    }
}

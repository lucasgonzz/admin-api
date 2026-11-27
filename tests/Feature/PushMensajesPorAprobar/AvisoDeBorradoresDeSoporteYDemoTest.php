<?php

namespace Tests\Feature\PushMensajesPorAprobar;

use App\Models\SupportCodexOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Criterios CA4 y CA5 de la misión push-mensajes-por-aprobar: los borradores del agente de soporte (Claude) y las
 * respuestas del chat de la demo que esperan el ok también avisan por push, uno por mensaje.
 *
 * Soporte: un borrador `is_ai_suggestion_draft = true` con `ai_auto_send_at` vacío espera a una persona. Avisa al dueño por
 * defecto de soporte y al admin asignado al ticket (los mismos destinatarios que el aviso de atención de Martín), NO a los
 * setters de leads. No avisa si el borrador tiene cuenta regresiva (sale solo), si lo maneja Codex/Martín (ya avisa con su
 * propio circuito: «espera tu aprobación») ni si el ticket es de un cliente de ensayo.
 *
 * Chat de la demo: la respuesta del asistente en estado `borrador` (el chat tiene la respuesta automática apagada) avisa al
 * setter del lead y abre la ficha del lead; la ya `publicado` no.
 *
 * Hoy están EN ROJO: no existen el servicio, la tabla ni el comando `pending-approvals:notify`.
 */
class AvisoDeBorradoresDeSoporteYDemoTest extends BaseDePushPorAprobar
{
    /**
     * Cola falsa: crear un borrador de soporte puede encolar trabajos y emite un evento de tiempo real; ninguno es lo que se
     * mide acá.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * CA4. Un borrador de Claude esperando aprobación (creado por `SupportAiSuggestionDraftService::create_draft`) avisa al
     * dueño por defecto de soporte y al admin asignado si es otro: un push a cada uno, con título «Para aprobar · Soporte ·
     * {contacto}», el link al ticket, el tag por ticket y los ids. Un setter que no es de soporte no recibe nada.
     *
     * @return void
     */
    public function test_el_borrador_de_soporte_por_aprobar_avisa_a_soporte(): void
    {
        $dueno    = $this->crear_admin('Dueño de soporte', ['is_default_support_owner' => true]);
        self::registrar_device($dueno);
        $asignado = $this->crear_admin('Asignado');
        self::registrar_device($asignado);
        $this->crear_setter_con_device('Setter de leads');

        $ticket   = $this->crear_ticket_de_soporte([], ['assigned_admin_id' => $asignado->id]);
        $borrador = $this->crear_borrador_de_soporte($ticket, "Hola Inés,\n\nya revisamos lo que nos contás y lo dejamos andando.");

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $esperados = [(int) $dueno->id, (int) $asignado->id];
        sort($esperados);
        $this->assertSame($esperados, $this->admins_pusheados(), 'Dueño por defecto y asignado; el setter de leads no.');

        foreach ($this->espia->enviados as $push) {
            $this->assertSame('Para aprobar · Soporte · Inés', $push['title']);
            $this->assertSame('Hola Inés, ya revisamos lo que nos contás y lo dejamos andando.', $push['body']);
            $this->assertSame('/soporte/ticket/' . $ticket->id, $push['data']['url']);
            $this->assertSame('aprobacion-ticket-' . $ticket->id, $push['data']['tag']);
            $this->assertSame('aprobacion_pendiente', $push['data']['tipo']);
            $this->assertSame('support_message', $push['data']['origen']);
            $this->assertSame((int) $ticket->id, $push['data']['ticket_id']);
            $this->assertSame((int) $borrador->id, $push['data']['message_id']);
        }

        $fila = $this->fila_de('support_message', (int) $borrador->id);
        $this->assertNotNull($fila);
        $this->assertSame('sent', (string) $fila->status);
        $this->assertSame('poller', (string) $fila->channel);
    }

    /**
     * CA4. Los tres borradores de soporte que NO esperan a una persona del conciliador no avisan ni dejan fila: el que tiene
     * cuenta regresiva (`ai_auto_send_at` cargado: sale solo), el que tiene una operación de Codex/Martín ligada (lo avisa el
     * circuito de atención de soporte) y el de un ticket de un cliente de ensayo.
     *
     * @return void
     */
    public function test_cuenta_regresiva_codex_y_ensayo_no_avisan(): void
    {
        $dueno = $this->crear_admin('Dueño de soporte', ['is_default_support_owner' => true]);
        self::registrar_device($dueno);

        /* Con cuenta regresiva: sale solo. */
        $con_cuenta  = $this->crear_borrador_de_soporte($this->crear_ticket_de_soporte(), 'Respuesta que sale sola.', 120);

        /* De Codex/Martín: tiene una operación `awaiting_approval` con este mensaje. */
        $ticket_codex = $this->crear_ticket_de_soporte();
        $de_codex     = $this->crear_borrador_de_soporte($ticket_codex, 'Borrador de Martín.');
        SupportCodexOperation::create([
            'support_ticket_id'   => $ticket_codex->id,
            'expected_message_id' => $de_codex->id,
            'message_id'          => $de_codex->id,
            'operation_id'        => (string) Str::uuid(),
            'action'              => 'responder',
            'status'              => 'awaiting_approval',
            'request_hash'        => str_repeat('a', 64),
        ]);

        /* De un cliente de ensayo. */
        $de_ensayo = $this->crear_borrador_de_soporte($this->crear_ticket_de_soporte(['es_ensayo' => 1]), 'Borrador de un ensayo.');

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertCount(0, $this->espia->llamadas, 'Ninguno de los tres avisa.');
        foreach ([$con_cuenta, $de_codex, $de_ensayo] as $borrador) {
            $this->assertNull($this->fila_de('support_message', (int) $borrador->id), 'Borrador #' . $borrador->id . ' no debe dejar fila.');
        }
    }

    /**
     * CA5. La respuesta del asistente del chat de la demo en borrador (conversación con `auto_responder = false`) avisa al
     * setter del lead: título «Para aprobar · Chat de la demo · {nombre}», link a la ficha del lead, tag por lead y origen
     * `demo_chat_message`.
     *
     * @return void
     */
    public function test_el_borrador_del_chat_de_la_demo_avisa(): void
    {
        $setter  = $this->crear_setter_con_device();
        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_del_chat_de_la_demo($lead, 'borrador', ['contenido' => 'Te muestro cómo se carga una venta en el sistema.']);

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertSame([(int) $setter->id], $this->admins_pusheados());
        $push = $this->espia->enviados[0];
        $this->assertSame('Para aprobar · Chat de la demo · Juana Pérez', $push['title']);
        $this->assertSame('Te muestro cómo se carga una venta en el sistema.', $push['body']);
        $this->assertSame('/leads?lead_id=' . $lead->id, $push['data']['url']);
        $this->assertSame('aprobacion-demo-chat-' . $lead->id, $push['data']['tag']);
        $this->assertSame('aprobacion_pendiente', $push['data']['tipo']);
        $this->assertSame('demo_chat_message', $push['data']['origen']);
        $this->assertSame((int) $mensaje->id, $push['data']['message_id']);
        $this->assertSame((int) $lead->id, $push['data']['lead_id']);
        $this->assertSame('sent', $this->estado_de('demo_chat_message', (int) $mensaje->id));
    }

    /**
     * CA5. Lo que ya se publicó no espera a nadie (0 push y sin fila), ni lo que escribió el propio visitante (`rol = lead`
     * aunque estuviera en borrador): sólo la respuesta del asistente se aprueba.
     *
     * @return void
     */
    public function test_lo_publicado_no_avisa(): void
    {
        $this->crear_setter_con_device();
        $lead      = $this->crear_lead();
        $publicado = $this->crear_mensaje_del_chat_de_la_demo($lead, 'publicado');
        $del_lead  = $this->crear_mensaje_del_chat_de_la_demo($lead, 'borrador', ['rol' => 'lead', 'origen' => 'lead']);

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertCount(0, $this->espia->llamadas, 'Lo publicado y lo del visitante no avisan.');
        $this->assertNull($this->fila_de('demo_chat_message', (int) $publicado->id));
        $this->assertNull($this->fila_de('demo_chat_message', (int) $del_lead->id));
        $this->assertSame(0, DB::table(self::TABLA)->where('source_type', 'demo_chat_message')->count());
    }
}

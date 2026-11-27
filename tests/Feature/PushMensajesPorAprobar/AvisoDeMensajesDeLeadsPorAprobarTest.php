<?php

namespace Tests\Feature\PushMensajesPorAprobar;

use App\Models\LeadMessage;
use App\Services\LeadAiSuggestionAutoSendScheduler;
use App\Services\LeadSuggestionEnvioEnCurso;
use Illuminate\Support\Facades\DB;

/**
 * Criterios CA2, CA3, CA6, CA11 y CA12 de la misión push-mensajes-por-aprobar: los mensajes de LEADS que esperan el ok
 * de una persona avisan por push (uno por mensaje), y lo que sale solo o ya no está pendiente no avisa.
 *
 * El criterio único de «esperando verificación» (PLAN §2.2) para un LeadMessage es: `sender = sistema`, `status =
 * sugerido`, sin ser evento de estado ni error, y `requiere_verificacion = true` o `system_message_kind` igual a
 * `closer_followup_suggestion`. El comando `pending-approvals:notify` lo concilia contra el ESTADO (no contra un
 * observer: la retención posterior se hace por query builder y ningún evento de Eloquent la ve).
 *
 * Todos corren el comando con el reloj 30 segundos después del alta del mensaje (pasó el asentamiento de 20 s), con un
 * setter con device y el espía de `PendingApprovalPushService` ligado en el contenedor (nada sale a la red).
 *
 * Hoy están EN ROJO: no existen el servicio, la tabla ni el comando.
 */
class AvisoDeMensajesDeLeadsPorAprobarTest extends BaseDePushPorAprobar
{
    /**
     * CA2. La retención posterior: una sugerencia que iba a salir sola (sin verificación, con auto-envío programado) la
     * retiene `LeadAiSuggestionAutoSendScheduler::retener_para_aprobacion_humana()` con un UPDATE por query builder, que no
     * dispara eventos de Eloquent. Hasta entonces el comando no avisa (sale sola); apenas queda retenida, avisa UNA vez al
     * setter, con el contenido y el link a la conversación con `message_id`. Antes de la misión nadie avisaba esto.
     *
     * @return void
     */
    public function test_la_retencion_posterior_avisa(): void
    {
        $setter  = $this->crear_setter_con_device();
        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_por_aprobar($lead, [
            'requiere_verificacion' => false,
            'ai_auto_send_at'       => now()->addMinutes(5),
        ]);

        /* Mientras puede salir sola, no hay nada que aprobar. */
        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();
        $this->assertCount(0, $this->espia->enviados, 'Una sugerencia que sale sola no avisa.');
        $this->assertNull($this->fila_de('lead_message', (int) $mensaje->id), 'Y no deja fila.');

        /* La retienen para una persona (por query builder). */
        $this->pasar_a('+60 seconds');
        app(LeadAiSuggestionAutoSendScheduler::class)->retener_para_aprobacion_humana($mensaje);

        $this->pasar_a('+90 seconds');
        $this->correr_el_comando();

        $this->assertCount(1, $this->espia->enviados, 'Retenida, avisa una vez.');
        $push = $this->espia->enviados[0];
        $this->assertSame((int) $setter->id, $push['admin_id']);
        $this->assertSame('Para aprobar · Juana Pérez', $push['title']);
        $this->assertSame($this->cuerpo_esperado(self::TEXTO_POR_APROBAR), $push['body']);
        $this->assertSame('/leads/' . $lead->id . '/conversacion?message_id=' . $mensaje->id, $push['data']['url']);
        $this->assertSame('aprobacion-lead-' . $lead->id, $push['data']['tag']);
        $this->assertSame('aprobacion_pendiente', $push['data']['tipo']);
        $this->assertSame('lead_message', $push['data']['origen']);
        $this->assertSame((int) $mensaje->id, $push['data']['message_id']);
        $this->assertSame((int) $lead->id, $push['data']['lead_id']);
        $this->assertSame('sent', $this->estado_de('lead_message', (int) $mensaje->id));
    }

    /**
     * El contenido del cuerpo según PLAN §2.7: los saltos de línea y los espacios repetidos se colapsan a uno y el texto se
     * recorta a 140 caracteres con «…». Un lead sin nombre de contacto se llama por la empresa y, sin empresa, «Lead #id».
     *
     * @return void
     */
    public function test_el_contenido_del_aviso_colapsa_espacios_y_recorta(): void
    {
        $this->crear_setter_con_device();
        $lead_empresa = $this->crear_lead(['contact_name' => null, 'company_name' => 'Ferretería Rossi']);
        $largo        = "Hola,\n\n   te paso   el detalle de la propuesta: " . str_repeat('palabra ', 40);
        $mensaje      = $this->crear_mensaje_por_aprobar($lead_empresa, ['content' => $largo]);
        $lead_sin_nombre = $this->crear_lead(['contact_name' => null, 'company_name' => null]);
        $mensaje_b       = $this->crear_mensaje_por_aprobar($lead_sin_nombre);

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertCount(2, $this->espia->enviados);
        $por_mensaje = [];
        foreach ($this->espia->enviados as $push) {
            $por_mensaje[(int) $push['data']['message_id']] = $push;
        }

        $this->assertSame('Para aprobar · Ferretería Rossi', $por_mensaje[(int) $mensaje->id]['title']);
        $cuerpo = $por_mensaje[(int) $mensaje->id]['body'];
        $this->assertSame($this->cuerpo_esperado($largo), $cuerpo);
        $this->assertStringStartsWith('Hola, te paso el detalle de la propuesta: palabra', $cuerpo);
        $this->assertSame(140, mb_strlen($cuerpo), 'Recortado a 140 con el punto suspensivo incluido.');
        $this->assertSame('…', mb_substr($cuerpo, -1));

        $this->assertSame('Para aprobar · Lead #' . $lead_sin_nombre->id, $por_mensaje[(int) $mensaje_b->id]['title']);
    }

    /**
     * CA3. El seguimiento post-llamada del closer nace `sugerido` con `system_message_kind = closer_followup_suggestion` y SIN
     * `requiere_verificacion`, pero espera a una persona igual: avisa al setter (regla del 2/9: lo que espera verificación va
     * al setter aunque el lead sea del closer) y NO al closer, que no está en la campanita del lead.
     *
     * @return void
     */
    public function test_el_seguimiento_del_closer_avisa_al_setter_y_no_al_closer(): void
    {
        $setter = $this->crear_setter_con_device('Setter');
        $closer = $this->crear_admin('Closer', ['is_closer' => true]);
        self::registrar_device($closer);

        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_por_aprobar($lead, [
            'requiere_verificacion' => false,
            'system_message_kind'   => 'closer_followup_suggestion',
            'content'               => 'Juana, te dejo por acá el resumen de lo que hablamos en la llamada.',
        ]);

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertSame([(int) $setter->id], $this->admins_pusheados(), 'Sólo el setter; el closer no.');
        $this->assertSame('/leads/' . $lead->id . '/conversacion?message_id=' . $mensaje->id, $this->espia->enviados[0]['data']['url']);
        $this->assertSame('sent', $this->estado_de('lead_message', (int) $mensaje->id));
    }

    /**
     * CA6. Lo que sale solo o no es una tarea de una persona NO avisa y ni siquiera deja fila: una respuesta sin verificación
     * ni kind manual, un evento de estado, un error, un mensaje que no es del sistema y uno que ya está enviado.
     *
     * @return void
     */
    public function test_lo_que_sale_solo_no_avisa(): void
    {
        $this->crear_setter_con_device();
        $lead = $this->crear_lead();

        $mensajes = [
            $this->crear_mensaje_por_aprobar($lead, ['requiere_verificacion' => false]),
            $this->crear_mensaje_por_aprobar($lead, ['is_status_event' => true]),
            $this->crear_mensaje_por_aprobar($lead, ['is_error' => true]),
            $this->crear_mensaje_por_aprobar($lead, ['sender' => 'admin']),
            $this->crear_mensaje_por_aprobar($lead, ['status' => 'enviado']),
            $this->crear_mensaje_por_aprobar($lead, ['status' => 'rechazado']),
        ];

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertCount(0, $this->espia->enviados, 'Nada de lo que sale solo o ya no espera avisa.');
        foreach ($mensajes as $mensaje) {
            $this->assertNull($this->fila_de('lead_message', (int) $mensaje->id), 'Mensaje #' . $mensaje->id . ' no debe dejar fila.');
        }
    }

    /**
     * CA6. Un pendiente que ya lo resolvieron antes de que el aviso saliera no avisa: queda `resolved`. Para que haya fila
     * que resolver el mensaje se retiene (se está enviando) en la primera vuelta; después se aprueba (`enviado`) y en la
     * vuelta siguiente la fila se cierra sin push. Lo mismo si lo borraron (el sistema reemplaza sugerencias viejas).
     *
     * @return void
     */
    public function test_lo_resuelto_o_borrado_antes_no_avisa(): void
    {
        $this->crear_setter_con_device();
        $lead_a      = $this->crear_lead();
        $lead_b      = $this->crear_lead();
        $aprobado    = $this->crear_mensaje_por_aprobar($lead_a);
        $borrado     = $this->crear_mensaje_por_aprobar($lead_b);
        $en_curso    = app(LeadSuggestionEnvioEnCurso::class);
        $token_a     = $en_curso->marcar((int) $aprobado->id);
        $token_b     = $en_curso->marcar((int) $borrado->id);

        try {
            $this->pasar_a('+30 seconds');
            $this->correr_el_comando();
            $this->assertCount(0, $this->espia->enviados, 'Se están enviando: esperan.');
            $this->assertSame('pending', $this->estado_de('lead_message', (int) $aprobado->id));
            $this->assertSame('pending', $this->estado_de('lead_message', (int) $borrado->id));

            /* Uno se aprueba y sale; el otro lo borran. */
            DB::table('lead_messages')->where('id', (int) $aprobado->id)->update(['status' => 'enviado']);
            LeadMessage::query()->where('id', (int) $borrado->id)->delete();
        } finally {
            $en_curso->liberar((int) $aprobado->id, $token_a);
            $en_curso->liberar((int) $borrado->id, $token_b);
        }

        $this->pasar_a('+60 seconds');
        $this->correr_el_comando();

        $this->assertCount(0, $this->espia->enviados, 'Ni el aprobado ni el borrado avisan.');
        $this->assertSame('resolved', $this->estado_de('lead_message', (int) $aprobado->id));
        $this->assertSame('resolved', $this->estado_de('lead_message', (int) $borrado->id));
    }

    /**
     * CA6. Un mensaje con el envío en curso (`LeadSuggestionEnvioEnCurso::marcar()` vigente: se está mandando) NO avisa en esa
     * vuelta y su fila sigue `pending`; cuando el lease se libera y sigue pendiente, la vuelta siguiente avisa.
     *
     * @return void
     */
    public function test_lo_que_se_esta_enviando_espera(): void
    {
        $setter  = $this->crear_setter_con_device();
        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_por_aprobar($lead);
        $en_curso = app(LeadSuggestionEnvioEnCurso::class);
        $token    = $en_curso->marcar((int) $mensaje->id);

        try {
            $this->pasar_a('+30 seconds');
            $this->correr_el_comando();

            $this->assertCount(0, $this->espia->enviados, 'Mientras se está mandando no avisa.');
            $this->assertSame('pending', $this->estado_de('lead_message', (int) $mensaje->id), 'La fila sigue pendiente.');
        } finally {
            $en_curso->liberar((int) $mensaje->id, $token);
        }

        $this->pasar_a('+60 seconds');
        $this->correr_el_comando();

        $this->assertSame([(int) $setter->id], $this->admins_pusheados(), 'Liberado y todavía pendiente: avisa.');
        $this->assertSame('sent', $this->estado_de('lead_message', (int) $mensaje->id));
    }

    /**
     * CA11. Las dos ventanas. Un pendiente recién nacido (10 s) todavía no se toma (deja terminar el request que lo creó); a
     * los 30 s sí. Uno nacido hace 31 minutos y sin fila NO se registra ni avisa (al desplegar no se avisa el histórico); uno
     * de hace 29 minutos sí. Cada mensaje es de un lead distinto: la agrupación por conversación no interviene.
     *
     * @return void
     */
    public function test_ventana_de_asentamiento_y_de_descubrimiento(): void
    {
        $this->crear_setter_con_device();

        $fresco  = $this->crear_mensaje_por_aprobar($this->crear_lead());
        $viejo   = $this->crear_mensaje_por_aprobar($this->crear_lead());
        $cercano = $this->crear_mensaje_por_aprobar($this->crear_lead());
        $this->fechar_mensaje_de_lead($viejo, now()->subMinutes(31));
        $this->fechar_mensaje_de_lead($cercano, now()->subMinutes(29));

        /* A los 10 s el fresco no se toma. El cercano (29 min) ya pasó el asentamiento y sí. */
        $this->pasar_a('+10 seconds');
        $this->correr_el_comando();

        $avisados = array_map(function ($push) {
            return (int) $push['data']['message_id'];
        }, $this->espia->enviados);
        $this->assertSame([(int) $cercano->id], $avisados, 'A los 10 s sólo el de hace 29 minutos.');
        $this->assertNotSame('sent', $this->estado_de('lead_message', (int) $fresco->id), 'El fresco todavía no se avisó.');

        /* A los 30 s se toma el fresco; el de 31 minutos nunca. */
        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $avisados = array_map(function ($push) {
            return (int) $push['data']['message_id'];
        }, $this->espia->enviados);
        sort($avisados);
        $esperados = [(int) $fresco->id, (int) $cercano->id];
        sort($esperados);
        $this->assertSame($esperados, $avisados, 'A los 30 s avisa el fresco; el de hace 31 minutos no.');
        $this->assertNull($this->fila_de('lead_message', (int) $viejo->id), 'El histórico no se registra.');
    }

    /**
     * CA12. Sin ningún destinatario con device (el setter no tiene dispositivo registrado y no hay otro admin): 0 push y la
     * fila queda `no_recipients` para que se vea que se intentó.
     *
     * @return void
     */
    public function test_sin_device_queda_sin_destinatarios(): void
    {
        $this->crear_admin('Setter sin device', ['es_setter' => true]);
        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_por_aprobar($lead);

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertCount(0, $this->espia->llamadas, 'Sin device no hay a quién mandar.');
        $this->assertSame('no_recipients', $this->estado_de('lead_message', (int) $mensaje->id));
    }
}

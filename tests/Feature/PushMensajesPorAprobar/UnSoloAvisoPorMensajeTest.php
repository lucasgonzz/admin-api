<?php

namespace Tests\Feature\PushMensajesPorAprobar;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Criterios CA7, CA8 y CA9 de la misión push-mensajes-por-aprobar: sin spam.
 *
 *  - CA7. UN aviso por mensaje, venga de donde venga: tres vueltas del comando, el servicio de verificación que ya avisaba
 *    en el momento seguido del comando, y el Caso A (el respaldo automático frenado) que hoy mandaba un segundo push del
 *    mismo mensaje.
 *  - CA8. Los dos servicios de verificación existentes (`LeadVerificacionWhatsappService`,
 *    `LeadVerificacionAgendamientoNotificationService`) siguen avisando al instante, ahora con el contenido y el link únicos
 *    de PLAN §2.7/§13, y su WhatsApp de respaldo no cambia.
 *  - CA9. Agrupación por conversación en 10 minutos: si una conversación ya tiene un aviso reciente cuyo mensaje sigue
 *    esperando (o ya no existe), el aviso nuevo se agrupa y no suena otra vez.
 *
 * Hoy están EN ROJO: no existen el servicio, la tabla ni el comando, y los servicios de verificación mandan el contenido viejo.
 */
class UnSoloAvisoPorMensajeTest extends BaseDePushPorAprobar
{
    /**
     * CA7. Correr el comando tres veces sobre el mismo mensaje pendiente manda UN solo push (la fila `source_key` es única y la
     * reserva se toma con `lockForUpdate`).
     *
     * @return void
     */
    public function test_tres_vueltas_un_push(): void
    {
        $this->crear_setter_con_device();
        $mensaje = $this->crear_mensaje_por_aprobar($this->crear_lead());

        foreach (['+30 seconds', '+90 seconds', '+150 seconds'] as $desplazamiento) {
            $this->pasar_a($desplazamiento);
            $this->correr_el_comando();
        }

        $this->assertCount(1, $this->espia->enviados, 'Tres vueltas, un push.');
        $this->assertSame(1, DB::table(self::TABLA)->where('source_key', 'lead_message:' . $mensaje->id)->count(), 'Una sola fila por mensaje.');
    }

    /**
     * CA7. El servicio de verificación que ya avisaba en el momento (fuera del tramo de agenda) reclama el aviso del mensaje
     * ANTES de mandar su push; el comando que viene después no lo repite. En total, un push: el del servicio, con la fila en
     * canal `direct` y estado `sent`.
     *
     * @return void
     */
    public function test_servicio_directo_y_conciliador_un_push(): void
    {
        $setter  = $this->crear_setter_con_device();
        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_por_aprobar($lead);

        $servicio = $this->servicio_fuera_del_tramo($this->sender_espia());
        $servicio->notify($lead, $mensaje);

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertCount(1, $servicio->pushes, 'El servicio mandó su push en el momento.');
        $this->assertSame((int) $setter->id, $servicio->pushes[0]['admin_id']);
        $this->assertCount(0, $this->espia->llamadas, 'El comando no repite lo que el servicio ya avisó.');

        $fila = $this->fila_de('lead_message', (int) $mensaje->id);
        $this->assertNotNull($fila);
        $this->assertSame('direct', (string) $fila->channel);
        $this->assertSame('sent', (string) $fila->status);
        $this->assertSame([(int) $setter->id], $this->admin_ids_de($fila));
    }

    /**
     * CA7. El Caso A (el respaldo automático frenado por agendar o cancelar) vuelve a llamar al servicio de verificación del
     * tramo de agenda sobre el MISMO mensaje: antes salía un segundo push, ahora queda uno solo. Tampoco lo repite el comando.
     *
     * @return void
     */
    public function test_caso_a_no_repite(): void
    {
        $setter  = $this->crear_setter_con_device();
        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_por_aprobar($lead);

        $servicio = $this->servicio_de_agenda($this->sender_espia());
        $servicio->notify($lead, $mensaje);
        $servicio->notify($lead, $mensaje);

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertCount(1, $servicio->pushes, 'Dos llamadas sobre el mismo mensaje, un push.');
        $this->assertSame((int) $setter->id, $servicio->pushes[0]['admin_id']);
        $this->assertCount(0, $this->espia->llamadas);
        $this->assertSame(1, DB::table(self::TABLA)->where('source_key', 'lead_message:' . $mensaje->id)->count());
    }

    /**
     * CA7. Una fila `pending` con la reserva vigente (`processing_until` en el futuro: otro proceso la está atendiendo) no la
     * toca el comando: 0 push y la fila sigue igual.
     *
     * @return void
     */
    public function test_reserva_vigente_no_se_toca(): void
    {
        $this->crear_setter_con_device();
        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_por_aprobar($lead);

        DB::table(self::TABLA)->insert([
            'source_key'       => 'lead_message:' . $mensaje->id,
            'source_type'      => 'lead_message',
            'source_id'        => $mensaje->id,
            'group_key'        => 'lead:' . $lead->id,
            'status'           => 'pending',
            'channel'          => 'poller',
            'attempts'         => 0,
            'processing_until' => now()->addMinutes(5)->format('Y-m-d H:i:s'),
            'created_at'       => now()->format('Y-m-d H:i:s'),
            'updated_at'       => now()->format('Y-m-d H:i:s'),
        ]);

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();

        $this->assertCount(0, $this->espia->llamadas, 'Con la reserva vigente no se toca.');
        $fila = $this->fila_de('lead_message', (int) $mensaje->id);
        $this->assertSame('pending', (string) $fila->status);
        $this->assertSame(0, (int) $fila->attempts);
    }

    /**
     * CA8. Los dos servicios de verificación mandan el push con el contenido ÚNICO (título «Para aprobar · {nombre}», el texto
     * del mensaje, link a la conversación con `message_id`, tag, tipo, origen y ids) en vez de los textos viejos, a los
     * mismos destinatarios; y el WhatsApp de respaldo sigue llegándole al admin que no tiene device y tiene el flag.
     *
     * @return void
     */
    public function test_el_servicio_directo_usa_el_contenido_nuevo_y_conserva_el_respaldo(): void
    {
        $setter = $this->crear_setter_con_device('Setter con device');
        $sin_device_fuera  = $this->crear_admin('Sin device (fuera del tramo)', ['es_setter' => true, 'notify_verificacion_whatsapp' => true]);
        $sin_device_agenda = $this->crear_admin('Sin device (agenda)', ['es_setter' => true, 'notify_verificacion_agendamiento_whatsapp' => true]);

        /* Fuera del tramo de agenda. */
        $lead_a    = $this->crear_lead();
        $mensaje_a = $this->crear_mensaje_por_aprobar($lead_a);
        $sender_a  = $this->sender_espia();
        $fuera     = $this->servicio_fuera_del_tramo($sender_a);
        $fuera->notify($lead_a, $mensaje_a);

        $this->assertCount(1, $fuera->pushes, 'Un push, al setter con device.');
        $push = $fuera->pushes[0];
        $this->assertSame((int) $setter->id, $push['admin_id']);
        $this->assertSame('Para aprobar · Juana Pérez', $push['title']);
        $this->assertSame($this->cuerpo_esperado(self::TEXTO_POR_APROBAR), $push['body']);
        $this->assertSame('/leads/' . $lead_a->id . '/conversacion?message_id=' . $mensaje_a->id, $push['data']['url']);
        $this->assertSame('aprobacion-lead-' . $lead_a->id, $push['data']['tag']);
        $this->assertSame('aprobacion_pendiente', $push['data']['tipo']);
        $this->assertSame('lead_message', $push['data']['origen']);
        $this->assertSame((int) $mensaje_a->id, $push['data']['message_id']);
        $this->assertSame((int) $lead_a->id, $push['data']['lead_id']);
        $this->assertSame([$sin_device_fuera->phone_number], $sender_a->destinatarios, 'El WhatsApp de respaldo no cambia.');

        /* Tramo de agenda. */
        $lead_b    = $this->crear_lead(['contact_name' => 'Marcos Díaz']);
        $mensaje_b = $this->crear_mensaje_por_aprobar($lead_b, ['content' => 'Marcos, te confirmo el horario de la demo para el jueves.']);
        $sender_b  = $this->sender_espia();
        $agenda    = $this->servicio_de_agenda($sender_b);
        $agenda->notify($lead_b, $mensaje_b);

        $this->assertCount(1, $agenda->pushes);
        $push = $agenda->pushes[0];
        $this->assertSame((int) $setter->id, $push['admin_id']);
        $this->assertSame('Para aprobar · Marcos Díaz', $push['title']);
        $this->assertSame('Marcos, te confirmo el horario de la demo para el jueves.', $push['body']);
        $this->assertSame('/leads/' . $lead_b->id . '/conversacion?message_id=' . $mensaje_b->id, $push['data']['url']);
        $this->assertSame('aprobacion-lead-' . $lead_b->id, $push['data']['tag']);
        $this->assertSame('lead_message', $push['data']['origen']);
        $this->assertSame([$sin_device_agenda->phone_number], $sender_b->destinatarios, 'El WhatsApp de respaldo de agenda no cambia.');
    }

    /**
     * CA9. La agrupación de 10 minutos por conversación, en cinco escenarios (cada uno con leads propios; el reloj sigue
     * corriendo hacia adelante entre escenarios). `T` es el momento en que sale el aviso de la sugerencia A.
     *
     *  1. B nace a T+3 min con A todavía pendiente: B queda `grouped` (`grouped_into_id` = fila de A) y no suena.
     *  2. Igual, pero el sistema borró A (reemplazó la sugerencia): B se agrupa.
     *  3. A fue aprobada (`enviado`) antes de que naciera B: hay algo nuevo que Lucas no vio, B avisa.
     *  4. B nace a T+11 min con A todavía pendiente: pasó la ventana, B avisa.
     *  5. Dos leads distintos a la vez: un push cada uno.
     *
     * @return void
     */
    public function test_agrupacion_de_diez_minutos(): void
    {
        $setter = $this->crear_setter_con_device();

        /* ---- 1. B se agrupa en A (A sigue pendiente) ---- */
        $inicio = Carbon::now();
        $lead   = $this->crear_lead();
        $a      = $this->crear_mensaje_por_aprobar($lead, ['content' => 'Primera propuesta.']);
        $this->reloj_desde($inicio, '+30 seconds');
        $this->correr_el_comando();
        $this->assertCount(1, $this->espia->enviados, 'Escenario 1: A avisa.');

        $this->reloj_desde($inicio, '+3 minutes');
        $b = $this->crear_mensaje_por_aprobar($lead, ['content' => 'Segunda propuesta.']);
        $this->reloj_desde($inicio, '+3 minutes +30 seconds');
        $this->correr_el_comando();

        $this->assertCount(1, $this->espia->enviados, 'Escenario 1: B no suena otra vez.');
        $fila_b = $this->fila_de('lead_message', (int) $b->id);
        $this->assertSame('grouped', (string) $fila_b->status);
        $this->assertSame((int) $this->fila_de('lead_message', (int) $a->id)->id, (int) $fila_b->grouped_into_id);

        /* ---- 2. B se agrupa aunque A ya no exista ---- */
        $inicio = Carbon::now()->addMinutes(1);
        $this->reloj_desde($inicio, '+0 seconds');
        $cantidad_antes = count($this->espia->enviados);
        $lead  = $this->crear_lead();
        $a     = $this->crear_mensaje_por_aprobar($lead, ['content' => 'Propuesta que se va a reemplazar.']);
        $this->reloj_desde($inicio, '+30 seconds');
        $this->correr_el_comando();
        $this->assertCount($cantidad_antes + 1, $this->espia->enviados, 'Escenario 2: A avisa.');

        DB::table('lead_messages')->where('id', (int) $a->id)->delete();
        $this->reloj_desde($inicio, '+3 minutes');
        $b = $this->crear_mensaje_por_aprobar($lead, ['content' => 'Propuesta nueva.']);
        $this->reloj_desde($inicio, '+3 minutes +30 seconds');
        $this->correr_el_comando();

        $this->assertCount($cantidad_antes + 1, $this->espia->enviados, 'Escenario 2: B no suena.');
        $this->assertSame('grouped', $this->estado_de('lead_message', (int) $b->id));

        /* ---- 3. A ya fue aprobada: B avisa ---- */
        $inicio = Carbon::now()->addMinutes(1);
        $this->reloj_desde($inicio, '+0 seconds');
        $cantidad_antes = count($this->espia->enviados);
        $lead  = $this->crear_lead();
        $a     = $this->crear_mensaje_por_aprobar($lead, ['content' => 'Propuesta que se aprueba.']);
        $this->reloj_desde($inicio, '+30 seconds');
        $this->correr_el_comando();
        $this->assertCount($cantidad_antes + 1, $this->espia->enviados, 'Escenario 3: A avisa.');

        $this->reloj_desde($inicio, '+60 seconds');
        DB::table('lead_messages')->where('id', (int) $a->id)->update(['status' => 'enviado']);
        $this->reloj_desde($inicio, '+3 minutes');
        $b = $this->crear_mensaje_por_aprobar($lead, ['content' => 'Otra propuesta distinta.']);
        $this->reloj_desde($inicio, '+3 minutes +30 seconds');
        $this->correr_el_comando();

        $this->assertCount($cantidad_antes + 2, $this->espia->enviados, 'Escenario 3: B avisa, A ya estaba resuelta.');
        $this->assertSame('sent', $this->estado_de('lead_message', (int) $b->id));

        /* ---- 4. B nace pasados 10 minutos: avisa aunque A siga pendiente ---- */
        $inicio = Carbon::now()->addMinutes(1);
        $this->reloj_desde($inicio, '+0 seconds');
        $cantidad_antes = count($this->espia->enviados);
        $lead  = $this->crear_lead();
        $a     = $this->crear_mensaje_por_aprobar($lead, ['content' => 'Propuesta A sin tocar.']);
        $this->reloj_desde($inicio, '+30 seconds');
        $this->correr_el_comando();
        $this->assertCount($cantidad_antes + 1, $this->espia->enviados, 'Escenario 4: A avisa.');

        $this->reloj_desde($inicio, '+11 minutes');
        $b = $this->crear_mensaje_por_aprobar($lead, ['content' => 'Propuesta B tardía.']);
        $this->reloj_desde($inicio, '+11 minutes +30 seconds');
        $this->correr_el_comando();

        $this->assertCount($cantidad_antes + 2, $this->espia->enviados, 'Escenario 4: pasada la ventana, B avisa.');
        $this->assertSame('sent', $this->estado_de('lead_message', (int) $b->id));

        /* ---- 5. Dos leads distintos a la vez: un push cada uno ---- */
        $inicio = Carbon::now()->addMinutes(1);
        $this->reloj_desde($inicio, '+0 seconds');
        $cantidad_antes = count($this->espia->enviados);
        $lead_uno = $this->crear_lead();
        $lead_dos = $this->crear_lead();
        $uno      = $this->crear_mensaje_por_aprobar($lead_uno);
        $dos      = $this->crear_mensaje_por_aprobar($lead_dos);
        $this->reloj_desde($inicio, '+30 seconds');
        $this->correr_el_comando();

        $this->assertCount($cantidad_antes + 2, $this->espia->enviados, 'Escenario 5: un push por lead.');
        $this->assertSame('sent', $this->estado_de('lead_message', (int) $uno->id));
        $this->assertSame('sent', $this->estado_de('lead_message', (int) $dos->id));
        $this->assertSame((int) $setter->id, $this->espia->enviados[count($this->espia->enviados) - 1]['admin_id']);
    }
}

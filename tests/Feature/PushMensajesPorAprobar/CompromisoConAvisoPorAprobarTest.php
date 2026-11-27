<?php

namespace Tests\Feature\PushMensajesPorAprobar;

use App\Models\AdminPushSubscription;
use App\Models\Lead;
use App\Services\PendingApprovalPushService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\LeadsReferidosCompromisos\BaseDeReferidosYCompromisos;

/**
 * CA1 de la misión push-mensajes-por-aprobar: el motor de compromisos (el caso que disparó el pedido de Lucas).
 *
 * Cuando llega la hora de un compromiso `escribir` y el envío automático del motor está APAGADO (el default), el mensaje de
 * Martín queda como sugerencia (`sugerido`, con verificación, `system_message_kind = lead_compromiso`) esperando a una
 * persona. Hasta esta misión ese caso no avisaba por push (sólo creaba una tarea si la ventana estaba cerrada): ahora el
 * comando `pending-approvals:notify` manda UN push al setter con el link a la conversación con el mensaje a la vista. Con el
 * envío automático PRENDIDO el mensaje sale en el mismo request y no hay nada que aprobar: 0 push.
 *
 * Extiende la base de la misión leads-referidos-compromisos (reloj fijado, tablero de Juan —el setter—, IA simulada, HTTP
 * cerrado y espía de WhatsApp) y le suma el espía de `PendingApprovalPushService` ligado en el contenedor: nada sale a la red.
 *
 * Hoy están EN ROJO: no existen el servicio, la tabla ni el comando `pending-approvals:notify`.
 */
class CompromisoConAvisoPorAprobarTest extends BaseDeReferidosYCompromisos
{
    /** El viernes a las 10:00, la hora del compromiso. */
    private const VIERNES_YA = '2026-11-27 10:00:30';

    /** Treinta segundos después de que el motor dejó el mensaje: pasó el asentamiento de 20 s. */
    private const VIERNES_UN_MINUTO_DESPUES = '2026-11-27 10:01:00';

    /** @var object Espía de `PendingApprovalPushService` (nunca sale a la red). */
    private $espia_de_avisos;

    /**
     * Martes 24/11 a las 12:00 con el motor prendido (como `EjecucionDeCompromisosTest`), a Juan —el único setter— con un
     * device registrado y el espía ligado en el contenedor.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ir_a('2026-11-24 12:00:00');
        $this->prender_el_motor();

        AdminPushSubscription::query()->delete();
        BaseDePushPorAprobar::registrar_device($this->juan);

        $this->espia_de_avisos = BaseDePushPorAprobar::nuevo_espia();
        $this->app->instance(PendingApprovalPushService::class, $this->espia_de_avisos);
    }

    /**
     * Deja el escenario de un compromiso `escribir` con la ventana de 24 h abierta: el jueves 26/11 a las 12:00 el lead (Ro)
     * habló a las 11:30 y se crea un compromiso `escribir` para el viernes 27/11 a las 10:00. La IA devuelve un texto válido.
     * El reloj queda en el jueves.
     *
     * @return Lead
     */
    private function armar_un_compromiso_de_escribir(): Lead
    {
        $this->ir_a('2026-11-26 12:00:00');
        $lead = $this->lead_de_ro(['phone' => '+54935111' . random_int(10000, 99999)]);
        $this->entrante_a_las($lead, 'Mirá, lo hablo con mi socio y mañana te escribo yo.', '2026-11-26 11:30:00');

        $this->crear_compromiso_por_el_panel($lead, [
            'vence_at' => '2026-11-27 10:00',
            'accion'   => 'escribir',
            'resumen'  => 'Retomar lo del local y la carga doble.',
            'cita_del_lead' => 'mañana te escribo yo',
        ]);
        $this->fakear_la_ia_de_compromisos([$this->texto_valido_de_martin()]);

        return $lead->fresh();
    }

    /**
     * Corre el comando del motor a la hora del compromiso y después el de avisos, 30 s más tarde.
     *
     * @return void
     */
    private function llegar_la_hora_y_avisar(): void
    {
        $this->ir_a(self::VIERNES_YA);
        $this->assertSame(0, $this->correr_el_comando_de_compromisos());

        $this->ir_a(self::VIERNES_UN_MINUTO_DESPUES);
        $this->assertArrayHasKey('pending-approvals:notify', Artisan::all(), 'Falta el comando pending-approvals:notify.');
        $this->assertSame(0, Artisan::call('pending-approvals:notify'));
    }

    /**
     * CA1. Envío automático apagado: el compromiso deja el mensaje `sugerido` (kind `lead_compromiso`) y el comando manda UN
     * push a Juan con título «Para aprobar · Ro», el cuerpo del mensaje según PLAN §2.7, el link a la conversación con el id
     * del mensaje y el tag por lead. La fila queda `sent`, canal `poller`, con Juan como destinatario.
     *
     * @return void
     */
    public function test_el_compromiso_que_deja_un_mensaje_por_aprobar_avisa_por_push(): void
    {
        $lead = $this->armar_un_compromiso_de_escribir();

        $this->llegar_la_hora_y_avisar();

        $mensajes = $this->mensajes_del_motor($lead);
        $this->assertCount(1, $mensajes, 'El motor dejó una sugerencia en el hilo.');
        $mensaje = $mensajes->first();
        $this->assertSame('sugerido', (string) $mensaje->status);
        $this->assertSame([], $this->envios->textos, 'Nada salió a WhatsApp.');

        $this->assertCount(1, $this->espia_de_avisos->enviados, 'Un solo push.');
        $push = $this->espia_de_avisos->enviados[0];
        $this->assertSame((int) $this->juan->id, $push['admin_id']);
        $this->assertSame('Para aprobar · Ro', $push['title']);

        $esperado = mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $this->texto_valido_de_martin())), 0, 140, '…');
        $this->assertSame($esperado, $push['body']);
        $this->assertSame('/leads/' . $lead->id . '/conversacion?message_id=' . $mensaje->id, $push['data']['url']);
        $this->assertSame('aprobacion-lead-' . $lead->id, $push['data']['tag']);
        $this->assertSame('aprobacion_pendiente', $push['data']['tipo']);
        $this->assertSame('lead_message', $push['data']['origen']);
        $this->assertSame((int) $mensaje->id, $push['data']['message_id']);
        $this->assertSame((int) $lead->id, $push['data']['lead_id']);

        $fila = DB::table('pending_approval_notifications')->where('source_key', 'lead_message:' . $mensaje->id)->first();
        $this->assertNotNull($fila, 'Tiene que haber una fila del aviso.');
        $this->assertSame('sent', (string) $fila->status);
        $this->assertSame('poller', (string) $fila->channel);
        $this->assertSame([(int) $this->juan->id], array_map('intval', json_decode((string) $fila->admin_ids, true)));
    }

    /**
     * CA1. Con el envío automático PRENDIDO el mensaje sale en el mismo request (queda `enviado`): no espera a nadie, así que
     * el comando no manda ningún push y la fila no existe o queda `resolved`.
     *
     * @return void
     */
    public function test_con_envio_automatico_no_hay_aviso(): void
    {
        $this->prender_el_motor(['envio_automatico' => true]);
        $lead = $this->armar_un_compromiso_de_escribir();

        $this->llegar_la_hora_y_avisar();

        $mensajes = $this->mensajes_del_motor($lead);
        $this->assertCount(1, $mensajes);
        $mensaje = $mensajes->first();
        $this->assertSame('enviado', (string) $mensaje->status, 'Con el envío automático sale solo.');
        $this->assertCount(1, $this->envios->textos, 'Salió por WhatsApp una vez.');

        $this->assertCount(0, $this->espia_de_avisos->llamadas, 'Lo que sale solo no avisa.');
        $fila = DB::table('pending_approval_notifications')->where('source_key', 'lead_message:' . $mensaje->id)->first();
        $this->assertTrue($fila === null || (string) $fila->status === 'resolved', 'Sin fila o resuelta.');
    }
}

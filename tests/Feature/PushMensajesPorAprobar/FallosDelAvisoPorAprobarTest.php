<?php

namespace Tests\Feature\PushMensajesPorAprobar;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Criterios CA10 y CA13 de la misión push-mensajes-por-aprobar: un fallo del aviso NUNCA rompe nada, y el conciliador está
 * programado.
 *
 *  - CA10. Un `send_push` que tira no corta la vuelta ni el comando (sale con 0): la fila queda `pending` con `attempts`
 *    contado, se reintenta en las vueltas siguientes y al tercer intento fallido pasa a `failed`. En el reintento no se
 *    repite el device que ya aceptó (se le pasan al seam los `accepted_endpoint_hashes`). Sin la tabla (código desplegado
 *    antes de la migración, simulado con el seam `table_ready()`, NUNCA con `Schema::drop`) el comando sale con 0 sin tocar
 *    nada y el servicio de verificación que ya avisaba sigue mandando su push como hoy.
 *  - CA13. El schedule tiene un evento de `pending-approvals:notify` cada minuto, en segundo plano y sin superponerse.
 *
 * Hoy están EN ROJO: no existen el servicio, la tabla ni el comando.
 */
class FallosDelAvisoPorAprobarTest extends BaseDePushPorAprobar
{
    /**
     * CA10. El transporte falla siempre: tres vueltas, tres intentos (`attempts` 1, 2 y 3), la fila queda `pending` hasta el
     * tercero y después `failed`; una cuarta vuelta no vuelve a intentar. El comando sale con 0 todas las veces.
     *
     * @return void
     */
    public function test_un_push_que_tira_no_rompe_y_reintenta_hasta_tres(): void
    {
        $this->crear_setter_con_device();
        $mensaje = $this->crear_mensaje_por_aprobar($this->crear_lead());
        $this->espia->siempre = 'tirar';

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();
        $fila = $this->fila_de('lead_message', (int) $mensaje->id);
        $this->assertSame('pending', (string) $fila->status);
        $this->assertSame(1, (int) $fila->attempts);

        $this->pasar_a('+60 seconds');
        $this->correr_el_comando();
        $fila = $this->fila_de('lead_message', (int) $mensaje->id);
        $this->assertSame('pending', (string) $fila->status);
        $this->assertSame(2, (int) $fila->attempts);

        $this->pasar_a('+90 seconds');
        $this->correr_el_comando();
        $fila = $this->fila_de('lead_message', (int) $mensaje->id);
        $this->assertSame('failed', (string) $fila->status, 'Al tercer intento fallido se rinde.');
        $this->assertSame(3, (int) $fila->attempts);

        $this->pasar_a('+120 seconds');
        $this->correr_el_comando();
        $this->assertCount(3, $this->espia->llamadas, 'Tres intentos y no más.');
        $this->assertSame('failed', $this->estado_de('lead_message', (int) $mensaje->id));
    }

    /**
     * CA10. Un admin con dos devices: en el primer intento uno acepta y el otro falla (resultado incompleto). La fila queda
     * `pending` con `attempts = 1`; en el reintento se le pasa al seam el hash del device que ya aceptó, para que no reciba
     * el push dos veces, y al completarse la fila queda `sent`.
     *
     * @return void
     */
    public function test_el_reintento_no_repite_el_device_aceptado(): void
    {
        $setter    = $this->crear_admin('Setter con dos devices', ['es_setter' => true]);
        $hash_uno  = self::registrar_device($setter, 'uno');
        $hash_dos  = self::registrar_device($setter, 'dos');
        $mensaje   = $this->crear_mensaje_por_aprobar($this->crear_lead());

        $this->espia->respuestas = [
            ['accepted_endpoint_hashes' => [$hash_uno], 'failed_endpoint_hashes' => [$hash_dos], 'complete' => false],
            ['accepted_endpoint_hashes' => [$hash_dos], 'failed_endpoint_hashes' => [], 'complete' => true],
        ];

        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();
        $fila = $this->fila_de('lead_message', (int) $mensaje->id);
        $this->assertSame('pending', (string) $fila->status, 'Quedó un device sin entregar.');
        $this->assertSame(1, (int) $fila->attempts);

        $this->pasar_a('+60 seconds');
        $this->correr_el_comando();

        $this->assertCount(2, $this->espia->llamadas, 'Un intento y un reintento.');
        $this->assertSame([], $this->espia->llamadas[0]['accepted_hashes'], 'El primer intento no trae aceptados.');
        $this->assertContains($hash_uno, $this->espia->llamadas[1]['accepted_hashes'], 'El reintento no repite el device que ya aceptó.');
        $this->assertSame('sent', $this->estado_de('lead_message', (int) $mensaje->id));
    }

    /**
     * CA10. Sin la tabla (simulado con `table_ready() = false`, nunca con un DDL): el comando sale con 0 sin mandar ni
     * escribir nada, y el servicio de verificación que ya avisaba sigue mandando su push como hoy —al mismo destinatario y
     * con el contenido de PLAN §2.7, porque `claim_direct` devuelve el contenido con `notification_id = null`— y sin dejar fila.
     *
     * @return void
     */
    public function test_sin_tabla_el_comando_sale_y_el_servicio_directo_avisa_como_hoy(): void
    {
        $setter  = $this->crear_setter_con_device();
        $lead    = $this->crear_lead();
        $mensaje = $this->crear_mensaje_por_aprobar($lead);
        $this->espia->tabla_lista = false;

        /* El servicio directo avisa igual. */
        $servicio = $this->servicio_fuera_del_tramo($this->sender_espia());
        $servicio->notify($lead, $mensaje);

        $this->assertCount(1, $servicio->pushes, 'El servicio manda su push aunque falte la tabla.');
        $this->assertSame((int) $setter->id, $servicio->pushes[0]['admin_id']);
        $this->assertSame('Para aprobar · Juana Pérez', $servicio->pushes[0]['title']);
        $this->assertSame('/leads/' . $lead->id . '/conversacion?message_id=' . $mensaje->id, $servicio->pushes[0]['data']['url']);

        /* El comando sale con 0 y no toca nada. */
        $this->pasar_a('+30 seconds');
        $this->correr_el_comando();
        $this->assertCount(0, $this->espia->llamadas, 'Sin tabla el comando no manda nada.');
        $this->assertSame(0, DB::table(self::TABLA)->count(), 'Y no escribe nada.');
    }

    /**
     * CA13. El schedule tiene exactamente un evento de `pending-approvals:notify`: cada minuto, en segundo plano (no clava el
     * scheduler) y sin superponerse consigo mismo.
     *
     * @return void
     */
    public function test_el_comando_esta_programado_cada_minuto(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $eventos = array_values(array_filter($schedule->events(), function ($evento) {
            return strpos((string) $evento->command, 'pending-approvals:notify') !== false;
        }));

        $this->assertCount(1, $eventos, 'Tiene que haber exactamente un evento de pending-approvals:notify.');
        $this->assertSame('* * * * *', $eventos[0]->expression, 'Cada minuto.');
        $this->assertTrue($eventos[0]->runInBackground, 'En segundo plano.');
        $this->assertTrue($eventos[0]->withoutOverlapping, 'Sin superponerse.');
    }
}

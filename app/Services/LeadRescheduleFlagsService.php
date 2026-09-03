<?php

namespace App\Services;

use App\Models\Lead;

/**
 * El reset de los flags de automatización que corresponde cuando se REAGENDA una demo.
 *
 * 🔴 POR QUÉ ESTO ES UN SERVICIO Y NO UN BLOQUE ADENTRO DEL CONTROLADOR
 * ----------------------------------------------------------------------
 * Este cuerpo estaba escrito DOS veces adentro de `LeadController` —en `update()` (el camino
 * Blade) y en `update_json()` (el camino que usa el panel)— y esta misión venía a agregar un
 * TERCER camino que reagenda: `PATCH claude/leads/{id}`
 * ({@see \App\Http\Controllers\Api\ClaudeLeadsFieldsController}). Tres copias del mismo reset
 * garantizan que se separen: el día que aparezca un cuarto flag de recordatorio, alguien lo agrega
 * en la copia que estaba mirando y los otros dos caminos reagendan una demo que sigue con el
 * recordatorio del horario VIEJO marcado como enviado — o sea, una demo nueva a la que nunca le
 * llega el recordatorio, sin que nada lo denuncie.
 *
 * 🔴 LOS TRES FLAGS, Y POR QUÉ CADA UNO
 * --------------------------------------
 *  - `recordatorio_demo_enviado` y `recordatorio_manana_enviado`: son latches por demo agendada
 *    (evitan mandar el mismo recordatorio dos veces). Al mover la fecha, el horario nuevo tiene
 *    que poder recibir los suyos, así que vuelven a false.
 *  - `demo_fin_check_reprogramado_para`: reprogramación del check de fin (grupo 307, prompt 01).
 *    Si quedó seteada de una conversación viva en el horario viejo, es un timestamp del pasado que
 *    nunca más cae dentro de la ventana de ±2 minutos -- el check de fin quedaría trabado para
 *    siempre. Al reagendar, vuelve a null para que el nuevo horario calcule su propio objetivo
 *    (demo_datetime + duración) como cualquier demo sin reprogramar.
 *
 * ⚠️ LO QUE ESTE SERVICIO NO HACE, Y ES DELIBERADO. Reagendar por el panel NO mueve el evento de
 * Google Calendar del closer: eso lo hace `CloserGoogleCalendarEventService` y desde el panel se
 * dispara con el botón aparte (`POST admin/lead/{id}/force-calendar-event`), o solo desde el flujo
 * del agente (`LeadAiService`). Verificado el 3/9/2026 recorriendo los consumidores de `demo_date`:
 * `LeadController::update_json()` no llama al calendario ni a `DemoScheduledWhatsappService`. Este
 * servicio replica EXACTAMENTE lo que el panel ya hacía, ni más ni menos: agregarle acá el
 * calendario le cambiaría el comportamiento al camino del panel, que es lo contrario de lo que una
 * extracción tiene que hacer.
 */
class LeadRescheduleFlagsService
{
    /**
     * Resetea los flags SOLO si la fecha de la demo efectivamente cambió.
     *
     * La comparación es contra `getRawOriginal('demo_date')` —el string crudo de la base— y no
     * contra el accessor: `demo_date` está casteada a `date`, así que comparar objetos Carbon
     * compararía instancias distintas y daría "cambió" siempre.
     *
     * ⚠️ El lead tiene que venir YA PERSISTIDO y refrescado cuando se llama a este método: el
     * valor nuevo se lee de `getRawOriginal()`, que es lo que hay en la base, no lo que hay en
     * memoria sin guardar.
     *
     * @param Lead        $lead              Lead ya guardado con la fecha nueva.
     * @param string|null $demo_date_original Valor crudo de `demo_date` ANTES de guardar.
     *
     * @return bool Si hubo reset (o sea, si la fecha cambió).
     */
    public function resetear_si_cambio_la_fecha(Lead $lead, $demo_date_original): bool
    {
        if ($demo_date_original === $lead->getRawOriginal('demo_date')) {
            return false;
        }

        $this->resetear($lead);

        return true;
    }

    /**
     * Escribe el reset, sin preguntar nada.
     *
     * @param Lead $lead Lead a resetear.
     *
     * @return void
     */
    public function resetear(Lead $lead): void
    {
        $lead->update($this->flags_reseteados());
    }

    /**
     * Los flags tal cual quedan después de reagendar.
     *
     * Es público y devuelve el array en vez de escribirlo para que un simulacro (el `dry_run` de
     * `PATCH claude/leads/{id}`) pueda MOSTRAR qué se va a resetear sin escribir nada, leyendo la
     * misma definición que después escribe. Si el dry_run armara su propia lista, volveríamos a
     * tener dos definiciones del reset: exactamente el problema que este servicio cierra.
     *
     * @return array<string, mixed>
     */
    public function flags_reseteados(): array
    {
        return [
            'recordatorio_demo_enviado'   => false,
            'recordatorio_manana_enviado' => false,
            /* Ver el porqué completo en el docblock de la clase: un timestamp del horario viejo
               deja el check de fin trabado para siempre. */
            'demo_fin_check_reprogramado_para' => null,
        ];
    }
}

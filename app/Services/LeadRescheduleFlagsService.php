<?php

namespace App\Services;

use App\Models\Lead;

/**
 * El reset de los flags de automatización que corresponde cuando se REAGENDA una demo.
 *
 * 🔴 POR QUÉ ESTO ES UN SERVICIO Y NO UN BLOQUE ADENTRO DEL CONTROLADOR
 * ----------------------------------------------------------------------
 * Este cuerpo estaba escrito DOS veces adentro de `LeadController` —en `update()` (el camino
 * Blade) y en `update_json()` (el camino que usa el panel)— y después apareció un TERCER camino
 * que reagenda: `PATCH claude/leads/{id}`
 * ({@see \App\Http\Controllers\Api\ClaudeLeadsFieldsController}). Tres copias del mismo reset
 * garantizan que se separen: el día que aparezca un cuarto flag de recordatorio, alguien lo agrega
 * en la copia que estaba mirando y los otros dos caminos reagendan una demo que sigue con el
 * recordatorio del horario VIEJO marcado como enviado — o sea, una demo nueva a la que nunca le
 * llega el recordatorio, sin que nada lo denuncie.
 *
 * 🔴 QUÉ CAMBIÓ EL 3/9/2026, Y POR QUÉ LE CAMBIA EL COMPORTAMIENTO AL PANEL
 * -------------------------------------------------------------------------
 * Hasta esta fecha el método se llamaba `resetear_si_cambio_la_fecha()` y miraba SOLO `demo_date`.
 * Medido por los DOS caminos —panel y Claude—: mover una demo de las 18:00 a las 20:00 del MISMO
 * día no reseteaba nada. O sea que quedaba
 *   - `recordatorio_demo_enviado = true`, y el recordatorio del horario NUEVO nunca salía, y
 *   - `demo_fin_check_reprogramado_para` apuntando a las 19:15 de un horario que ya no existe,
 *     que es exactamente el "timestamp del pasado que traba el check de fin para siempre" que este
 *     mismo docblock viene describiendo desde que se escribió.
 *
 * Reagendar NO es "cambiar el día": es cambiar CUÁNDO empieza y termina la demo. Por eso ahora se
 * mira la AGENDA entera ({@see self::CAMPOS_DE_AGENDA}) y por eso el método pasó a llamarse
 * `resetear_si_cambio_la_agenda()`: el nombre viejo describía la mitad de la regla, y por esa
 * mitad que faltaba se colaba el bug.
 *
 * ⚠️ ESTO LE CAMBIA EL COMPORTAMIENTO AL PANEL, y está bien que se lo cambie. Guardar el modal del
 * lead moviendo sólo la hora ahora apaga los dos latches de recordatorio y limpia la reprogramación
 * del check de fin, cosa que antes no hacía. Es el arreglo, no un efecto colateral: que los tres
 * caminos compartan UNA definición de "se reagendó" es para lo que se extrajo este servicio, y una
 * definición que le miente a dos de los tres no sirve de nada.
 *
 * ⚠️ La comparación es de STRINGS CRUDOS, así que reescribir `18:00` como `18:00:00` cuenta como
 * reagenda aunque sea el mismo instante. Es deliberado y va para el lado seguro: el costo de un
 * reset de más son dos latches apagados (los recordatorios vuelven a poder salir); el de un reset
 * de menos es una demo sin recordatorio y un check de fin trabado. Normalizar la hora acá sería
 * además una segunda definición de "misma hora", que es justo lo que este archivo evita.
 *
 * 🔴 LOS TRES FLAGS, Y POR QUÉ CADA UNO
 * --------------------------------------
 *  - `recordatorio_demo_enviado` y `recordatorio_manana_enviado`: son latches por demo agendada
 *    (evitan mandar el mismo recordatorio dos veces). Al mover el turno, el horario nuevo tiene
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
 * servicio replica EXACTAMENTE lo que el panel ya hacía en ESE punto, ni más ni menos: agregarle
 * acá el calendario le cambiaría al panel algo que nadie pidió. Desde afuera del panel, el evento
 * del closer se mueve con `POST claude/leads/{id}/calendar-event`.
 */
class LeadRescheduleFlagsService
{
    /**
     * Las columnas que, juntas, SON el turno de la demo. Que cambie cualquiera de las tres es
     * reagendar.
     *
     * ⚠️ `demo_end_time` está adentro a propósito aunque no mueva el inicio: la reprogramación del
     * check de fin (`demo_fin_check_reprogramado_para`) se calcula contra el FIN de la demo, así
     * que estirar o recortar la demo deja ese timestamp apuntando a un final que ya no existe —
     * el mismo síntoma, por la otra punta.
     *
     * @var array<int, string>
     */
    const CAMPOS_DE_AGENDA = ['demo_date', 'demo_start_time', 'demo_end_time'];

    /**
     * Foto de la agenda ANTES de guardar, para poder comparar después.
     *
     * 🔴 LA FOTO LA ARMA EL SERVICIO Y NO EL LLAMADOR, y eso es medio punto de la extracción: si
     * cada camino armara su propio array, agregar una cuarta columna de agenda obligaría a tocar
     * los tres, y el que se olvide reagenda sin resetear igual que antes. Acá se agrega a
     * {@see self::CAMPOS_DE_AGENDA} y los tres caminos lo toman solos.
     *
     * Se lee con `getRawOriginal()` —el string crudo de la base— y no con el accessor: `demo_date`
     * está casteada a `date`, así que comparar objetos Carbon compararía instancias distintas y
     * daría "cambió" siempre.
     *
     * @param Lead $lead Lead TAL CUAL está en la base, antes de aplicarle el cambio.
     *
     * @return array<string, mixed>
     */
    public function fotografiar_agenda(Lead $lead): array
    {
        $foto = [];

        foreach (self::CAMPOS_DE_AGENDA as $campo) {
            $foto[$campo] = $lead->getRawOriginal($campo);
        }

        return $foto;
    }

    /**
     * Resetea los flags SOLO si el turno de la demo efectivamente cambió.
     *
     * ⚠️ El lead tiene que venir YA PERSISTIDO y refrescado cuando se llama a este método: el
     * valor nuevo se lee de `getRawOriginal()`, que es lo que hay en la base, no lo que hay en
     * memoria sin guardar.
     *
     * @param Lead                 $lead          Lead ya guardado con el turno nuevo.
     * @param array<string, mixed> $agenda_previa La foto de {@see self::fotografiar_agenda()},
     *                                            tomada ANTES de guardar.
     *
     * @return bool Si hubo reset (o sea, si el turno cambió).
     */
    public function resetear_si_cambio_la_agenda(Lead $lead, array $agenda_previa): bool
    {
        if ($this->fotografiar_agenda($lead) === $agenda_previa) {
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

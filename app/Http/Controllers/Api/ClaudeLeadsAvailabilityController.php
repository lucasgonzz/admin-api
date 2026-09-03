<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Http\Controllers\LeadController;
use App\Models\Lead;
use App\Services\CloserGoogleCalendarEventService;
use App\Services\LeadAiService;
use Illuminate\Http\Request;

/**
 * Las dos cosas que faltaban para poder AGENDAR una demo desde afuera del panel: LEER la
 * disponibilidad y MOVER el evento de calendario del closer.
 *
 * 🔴 POR QUÉ EXISTE
 * ------------------
 * `PATCH claude/leads/{id}` ya escribía el turno, pero de las dos puntas que hacen falta para
 * agendar de verdad no tenía ninguna:
 *
 *   1. NO SE PODÍA LEER LA GRILLA. Una corrida de `/leads` que quiere agendar tiene que ELEGIR un
 *      horario antes de escribirlo, y la única ruta que publicaba los slots libres era
 *      `GET admin/lead/{id}/panel-availability`, que vive detrás de `auth:sanctum`. Sin esto, la
 *      única forma de agendar era mandar un horario a ojo y esperar que el 422 de la grilla dijera
 *      cuáles estaban libres — o sea, usar el error como si fuera la consulta.
 *   2. NO SE PODÍA MOVER EL EVENTO DEL CLOSER. Reagendar no toca el Google Calendar del closer (ni
 *      por el panel ni por Claude: es deliberado, ver `LeadRescheduleFlagsService`), y el docblock
 *      del endpoint de campos mandaba a usar `POST admin/lead/{id}/force-calendar-event`… que
 *      también está adentro del grupo `auth:sanctum` (`routes/api.php`, línea ~519). O sea que el
 *      consejo era INAPLICABLE para una sesión con `X-Claude-Task-Key`: el evento del closer
 *      quedaba en el horario viejo y no había manera de moverlo sin entrar al panel.
 *
 * 🔴 LAS DOS RUTAS DELEGAN, NO REIMPLEMENTAN. El mismo patrón con el que
 * {@see ClaudeDemoMediaController} delega en `Api\DemoMediaController`: no puede haber dos
 * definiciones de "slot válido" ni dos formas de recrear el evento del closer. Si mañana el panel
 * cambia cómo calcula la grilla, estos endpoints cambian con él sin que nadie toque este archivo.
 * Lo único propio de acá es el freno del punto 2, que está explicado abajo.
 *
 * ⚠️ QUÉ NO HAY ACÁ: no hay forma de BORRAR el evento del closer ni de escribir `google_event_id` /
 * `meet_url` a mano. El turno se escribe con `PATCH claude/leads/{id}` y el evento se recrea con
 * esta ruta; cualquier otra combinación deja al lead con un link de Meet que no apunta a ningún
 * evento.
 */
class ClaudeLeadsAvailabilityController extends Controller
{
    use RespuestasParaClaude;

    /**
     * GET /api/claude/leads/{id}/availability
     *
     * Catálogo de instancias de demo del pool y horarios libres por instancia y por fecha, con el
     * turno del propio lead excluido del bloqueo contra sí mismo.
     *
     * 🔴 ES LO PRIMERO QUE HAY QUE PEDIR ANTES DE AGENDAR: `PATCH claude/leads/{id}` rechaza con 422
     * cualquier horario que no figure acá, y rechaza una `demo_date` sin `demo_id`. Los `demo_id`
     * válidos salen de la clave `demos` de esta misma respuesta.
     *
     * Forma de la respuesta (la define `LeadController::panel_availability_json()`, que es la que
     * consume el panel; no se toca acá):
     *   `demos`: [{demo_id, label}]
     *   `slots`: {demo_id: {"Y-m-d": ["HH:MM", ...]}}
     *
     * ⚠️ La ventana es la fija de {@see LeadAiService::DIAS_DISPONIBILIDAD} días corridos, igual que
     * la que ve el panel. Una fecha más lejana que eso no aparece acá aunque esté libre; el PATCH sí
     * la valida (le pasa la fecha pedida al cálculo, que extiende la ventana hasta ella).
     *
     * @param int|string    $id         Lead sobre el que se calcula la disponibilidad.
     * @param LeadAiService $ai_service Servicio con el cálculo de slots (inyectado).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function availability_json($id, LeadAiService $ai_service)
    {
        return (new LeadController())->panel_availability_json($id, $ai_service);
    }

    /**
     * POST /api/claude/leads/{id}/calendar-event
     *
     * (Re)crea el evento de Google Calendar del closer para este lead y, con eso, su link de Meet —
     * son la misma operación en Google Calendar.
     *
     * 🔴 EL FRENO ES DE ACÁ Y NO DE `LeadController::force_calendar_event_json()`, A PROPÓSITO.
     * Leído ese método antes de exponerlo: llama a `recreate_event_for_lead()`, que BORRA el evento
     * anterior (si había) y crea uno limpio. O sea que es idempotente en el único sentido que le
     * importa al calendario —nunca deja dos eventos para el mismo lead— pero NO es inocuo repetirlo:
     *
     *   - cada corrida genera un `google_event_id` y un `meet_url` NUEVOS, así que el link de Meet
     *     que el lead ya recibió deja de servir (`CloserAlertService` se lo manda por WhatsApp
     *     cuando el closer entra a la llamada), y
     *   - `create_event_for_lead()` invita con `sendUpdates=all` cuando el lead tiene email, así que
     *     cada corrida le manda otra invitación de Google.
     *
     * Por eso, cuando el lead YA tiene evento, hace falta `confirmar_reemplazo_del_meet=true`. El
     * freno no va en `LeadController` porque ahí lo aprieta una persona que está mirando el panel y
     * ya sabe que va a reemplazar el evento: el botón dice "forzar". Acá lo aprieta un proceso, y un
     * proceso que reintenta una llamada cortada por red no tiene por qué quemarle el link de Meet al
     * lead sin haberlo pedido.
     *
     * ⚠️ Sin `demo_date` y `demo_start_time` cargados no hay con qué calcular el horario del evento:
     * eso lo rechaza el método delegado con su propio 422, y no se replica el chequeo acá.
     *
     * @param Request                          $request          Body: confirmar_reemplazo_del_meet.
     * @param int|string                       $id               Lead objetivo.
     * @param CloserGoogleCalendarEventService  $calendar_service Servicio del calendario (inyectado).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function calendar_event_json(Request $request, $id, CloserGoogleCalendarEventService $calendar_service)
    {
        $invalido = $this->validar_o_422($request, [
            'confirmar_reemplazo_del_meet' => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $lead = Lead::find((int) $id);
        if ($lead === null) {
            return $this->error_404('No existe el lead ' . (int) $id . '.');
        }

        $ya_tiene_evento = trim((string) $lead->google_event_id) !== '';

        if ($ya_tiene_evento && ! $request->boolean('confirmar_reemplazo_del_meet')) {
            return $this->error_422(
                'El lead ya tiene un evento de calendario del closer. Recrearlo BORRA el que hay y genera un '
                    . 'google_event_id y un meet_url nuevos: el link de Meet que el lead ya tenga deja de servir y, si '
                    . 'el lead tiene email cargado, Google le manda otra invitación. No se tocó nada.',
                [
                    'lead_id'         => (int) $lead->id,
                    'google_event_id' => (string) $lead->google_event_id,
                    'ayuda'           => 'Si el evento quedó en el horario viejo después de reagendar, eso es '
                        . 'exactamente para lo que existe esta ruta: repetí la llamada con '
                        . 'confirmar_reemplazo_del_meet=true. Si sólo estás reintentando una llamada que se cortó, '
                        . 'fijate antes si el evento ya quedó bien.',
                ]
            );
        }

        return (new LeadController())->force_calendar_event_json($id, $calendar_service);
    }
}

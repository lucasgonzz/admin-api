<?php

namespace App\Services\Pipelines;

use App\Models\AdminCalendarConnection;
use App\Models\PipelineOpportunity;
use App\Services\CloserGoogleCalendarBusyService;
use App\Services\GoogleCalendarOAuthService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza la PRÓXIMA ACCIÓN de una oportunidad del CRM con el Google Calendar del admin que la
 * fijó (misión pipelines-calendario-proxima-accion, 5/10/2026).
 *
 * Qué hace, en una línea: cuando una oportunidad tiene `next_action_at` y el admin que ejecuta la
 * acción tiene su calendario vinculado (`AdminCalendarConnection` activa con `google_calendar_id`),
 * hay un evento en ese calendario que sigue a la próxima acción: se crea al fijarla, se actualiza
 * al cambiarla y se borra al borrarla, al cerrar la oportunidad o al borrar la oportunidad.
 *
 * Decisiones que NO hay que "simplificar" (cada una tiene su porqué):
 *
 * 1. 🔴 LA FUNCIÓN COMPARA ESTADO DESEADO CON ESTADO ACTUAL, no "reacciona" a un tipo de cambio.
 *    El estado deseado sale de `next_action_at` (¿hay próxima acción?); el actual, de las dos
 *    columnas `next_action_calendar_*` (¿hay un evento y de quién?). Así los cuatro puntos de
 *    enganche del servicio (alta masiva, mover, actualizar, borrar) llaman a lo mismo y no hay un
 *    camino de "reprogramar" distinto del de "fijar" que algún día diga otra cosa.
 *
 * 2. 🔴 DÍA COMPLETO ≠ BLOQUEAR EL DÍA. Una próxima acción sin hora se guarda como `00:00:00` y se
 *    sincroniza como evento de día completo con `transparency: transparent` (libre). Si fuera
 *    "opaque", `CloserGoogleCalendarBusyService` (freeBusy sobre ESTE mismo calendario) vería el día
 *    entero ocupado y sacaría TODOS los slots de demo de ese día. Solo las próximas acciones con
 *    hora bloquean el horario (`opaque`), que es lo que pidió Lucas.
 *
 * 3. 🔴 LA HORA SE MANDA LOCAL, SIN CONVERTIR. `next_action_at` se guarda como hora local de
 *    Argentina (`Y-m-d H:i:s`, sin offset) y a Google se le manda esa misma hora con
 *    `timeZone: America/Argentina/Buenos_Aires`. Pasarla por `toIso8601String()` o por UTC
 *    es la trampa de las tres horas (ver `Pipeline::serializeDate()`).
 *
 * 4. 🔴 NUNCA PROPAGA UNA EXCEPCIÓN. El calendario es un efecto secundario cómodo, no parte de la
 *    operación: si Google devuelve 403 (token viejo sin el scope `calendar.events`), 5xx, el token
 *    está revocado o no hay red, se loguea en el canal `disponibilidad` y el guardado del admin
 *    queda como estaba. Todo público va adentro de un `try/catch (\Throwable)`.
 *
 * 5. 🔴 SE LLAMA DESPUÉS DEL COMMIT, nunca adentro de la transacción de `PipelineOpportunityService`:
 *    esas transacciones tienen tomado el lock de la fila del pipeline, y una llamada a Google
 *    (hasta `TIMEOUT_SEGUNDOS` por llamada) con el lock tomado dejaría a todos los demás admins
 *    esperando. Es responsabilidad del servicio respetarlo.
 *
 * Sin invitados, sin Meet y sin `sendUpdates`: es un recordatorio del propio admin, no una reunión.
 *
 * No toca `CloserGoogleCalendarEventService`: ese servicio es específico de las llamadas del closer
 * con leads (busca "el primer closer", arma Meet e invitados) y esto es otra cosa.
 */
class PipelineCalendarSync
{
    /**
     * Duración del evento de una próxima acción CON hora, en minutos. La próxima acción no tiene
     * duración (es un "a esta hora hay que hacer esto"): 30 minutos es lo que ocupa un bloque corto
     * en el calendario sin comerse una franja de demos entera.
     */
    const DURACION_MINUTOS = 30;

    /** Zona horaria con la que se interpreta la hora local guardada (Argentina, sin horario de verano). */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /** Base de la API de eventos de Google Calendar. */
    const URL_BASE = 'https://www.googleapis.com/calendar/v3/calendars/';

    /**
     * Tiempo máximo de espera por cada llamada a Google, en segundos. Sin esto, un Google lento deja
     * colgado el request del admin (la cola es `sync` en producción, así que no hay otro proceso que
     * absorba la espera).
     */
    const TIMEOUT_SEGUNDOS = 10;

    /** Prefijo de todos los logs de este servicio (canal `disponibilidad`). */
    const LOG = '[PIPELINE_CALENDAR]';

    /**
     * @var GoogleCalendarOAuthService
     */
    protected $oauth;

    /**
     * @var CloserGoogleCalendarBusyService
     */
    protected $busy;

    /**
     * Access tokens ya pedidos en este request, por id de conexión. Un alta masiva de 25
     * oportunidades haría 25 refrescos de token idénticos (un POST a Google cada uno) si no se
     * reutilizaran; el token dura ~1 hora y esta instancia vive lo que dura el request.
     *
     * @var array<int, string>
     */
    private $tokens = [];

    /**
     * Conexiones cuyo refresco de token falló en este request, con el mensaje del error. Se
     * consulta antes de pedir un token para no reintentar un refresco que ya falló (ver `llamar()`).
     *
     * @var array<int, string>
     */
    private $tokens_fallidos = [];

    /**
     * @param GoogleCalendarOAuthService      $oauth Refresco del access token de la conexión.
     * @param CloserGoogleCalendarBusyService $busy  Para invalidar la caché de disponibilidad.
     */
    public function __construct(GoogleCalendarOAuthService $oauth, CloserGoogleCalendarBusyService $busy)
    {
        $this->oauth = $oauth;
        $this->busy  = $busy;
    }

    /* ------------------------------------------------------------------------------------------
     | API pública
     |----------------------------------------------------------------------------------------- */

    /**
     * Deja el calendario como dice la oportunidad (ver el docblock de la clase, punto 1).
     *
     *  - Sin próxima acción: si hay un evento guardado, se borra del calendario de SU admin y se
     *    limpian las dos columnas.
     *  - Con próxima acción y el admin con calendario vinculado:
     *      · evento guardado del MISMO admin → PATCH (si Google dice 404/410, el evento ya no
     *        existe: se recrea con POST);
     *      · evento guardado de OTRO admin → se borra allá y se crea acá;
     *      · sin evento → POST.
     *    Se guardan el id del evento y el admin en la oportunidad.
     *  - Con próxima acción pero el admin SIN calendario vinculado: si había un evento de otro
     *    admin se borra (con la fecha nueva sería mentira) y se limpian las columnas; si no, no se
     *    hace ninguna llamada a Google.
     *
     * Best-effort: nunca lanza.
     *
     * @param PipelineOpportunity            $op             Recargada de la base, ya con la próxima acción final.
     * @param int|null                       $admin_id       El admin que ejecuta la acción (no el responsable de la oportunidad).
     * @param string|\DateTimeInterface|null $fecha_anterior La próxima acción que había ANTES del cambio (`Y-m-d H:i:s` o fecha), solo para invalidar la caché de la fecha vieja.
     *
     * @return void
     */
    public function sincronizar(PipelineOpportunity $op, $admin_id, $fecha_anterior = null)
    {
        try {
            $fresca = $this->sincronizar_interno($op, $admin_id === null ? null : (int) $admin_id, $this->fecha_a_texto($fecha_anterior));

            /* El trabajo se hizo sobre una copia fresca de la base: la instancia que recibió el
               llamador se pone al día con lo que quedó anotado del evento. */
            if ($fresca !== null) {
                $op->next_action_calendar_admin_id = $fresca->next_action_calendar_admin_id;
                $op->next_action_calendar_event_id = $fresca->next_action_calendar_event_id;
                $op->syncOriginalAttribute('next_action_calendar_admin_id');
                $op->syncOriginalAttribute('next_action_calendar_event_id');
            }
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->error(
                self::LOG . ' Excepción al sincronizar la próxima acción con Google Calendar.'
                . ' opportunity_id=' . $op->id
                . ' admin_id=' . ($admin_id === null ? 'null' : $admin_id)
                . ' error=' . $e->getMessage()
            );
        }
    }

    /**
     * Borra el evento de una oportunidad que YA no existe (la usa `borrar()` del servicio: la fila
     * se borró, así que no hay columnas que limpiar).
     *
     * Best-effort: nunca lanza.
     *
     * @param int|null                       $admin_id       Dueño del calendario donde está el evento.
     * @param string|null                    $event_id       Id del evento en Google.
     * @param string|\DateTimeInterface|null $fecha_anterior La próxima acción que tenía (para invalidar la caché si bloqueaba horario).
     *
     * @return void
     */
    public function borrar_evento($admin_id, $event_id, $fecha_anterior = null)
    {
        try {
            if ($admin_id === null || $this->texto($event_id) === null) {
                return;
            }

            $resuelto = $this->borrar_en_google((int) $admin_id, (string) $event_id, null);

            if ($resuelto) {
                $this->invalidar_cache([$this->fecha_que_bloquea($this->fecha_a_texto($fecha_anterior))]);
            }
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->error(
                self::LOG . ' Excepción al borrar el evento de una oportunidad borrada.'
                . ' admin_id=' . ($admin_id === null ? 'null' : $admin_id)
                . ' google_event_id=' . $event_id
                . ' error=' . $e->getMessage()
            );
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Lógica
     |----------------------------------------------------------------------------------------- */

    /**
     * El cuerpo de `sincronizar()`, sin el try/catch. Relee la oportunidad de la base y trabaja con
     * ese estado (ver el comentario adentro).
     *
     * @param PipelineOpportunity $op
     * @param int|null            $admin_id
     * @param string|null         $fecha_anterior `Y-m-d H:i:s` o null.
     *
     * @return PipelineOpportunity|null La copia fresca con la que se trabajó (con el evento ya anotado), o null si la oportunidad ya no existe.
     */
    private function sincronizar_interno(PipelineOpportunity $op, $admin_id, $fecha_anterior)
    {
        /* 🔴 Se trabaja con el estado FRESCO de la base, no con la instancia en memoria. Dos requests
           casi simultáneos sobre la misma oportunidad (doble click en Guardar) cargaron la fila
           antes de que el otro terminara: el segundo creía "sin evento" cuando el primero ya había
           creado uno, y hacía un segundo POST (evento duplicado). Releyendo acá, justo antes de
           llamar a Google, el segundo ve el evento del primero y hace un PATCH. Si la fecha de la
           base ya no es la que venía a sincronizar, manda la de la base: es la que quedó guardada.
           Honestamente: esto REDUCE la ventana de la carrera, no la cierra del todo; entre esta
           lectura y el guardado del id del evento siguen pasando unas decenas de milisegundos (la
           llamada a Google) en los que otro request podría leer "sin evento". Cerrarla de verdad
           exigiría un lock de la fila durante la llamada a Google, que es justo lo que decisión 5
           de la clase prohíbe. Un evento duplicado en un calendario es molesto, no destructivo. */
        $fresca = PipelineOpportunity::query()->whereKey($op->id)->first();
        if ($fresca === null) {
            /* La oportunidad se borró entre el commit y acá: no hay nada que sincronizar. */
            return null;
        }
        $op = $fresca;

        $evento_id    = $this->texto($op->next_action_calendar_event_id);
        $evento_admin = $op->next_action_calendar_admin_id === null ? null : (int) $op->next_action_calendar_admin_id;
        $tiene_evento = $evento_id !== null && $evento_admin !== null;

        /* Estado deseado: NO hay próxima acción. Lo único que puede haber que hacer es borrar.
           Nota: si esto es el reintento de un borrado que había fallado, `$fecha_anterior` es null
           (la próxima acción ya estaba vacía) y la caché de disponibilidad de la fecha que tenía el
           evento no se invalida: esa fecha no se guarda en ningún lado. Vence sola a los 5 minutos. */
        if ($op->next_action_at === null) {
            if ($tiene_evento && $this->borrar_en_google($evento_admin, $evento_id, $op->id)) {
                $this->guardar_evento($op, null, null);
                $this->invalidar_cache([$this->fecha_que_bloquea($fecha_anterior)]);
            }

            return $op;
        }

        $fecha_nueva = $op->next_action_at->format('Y-m-d H:i:s');
        $conexion    = $admin_id === null ? null : $this->conexion_activa($admin_id);

        /* Hay próxima acción pero el admin no tiene calendario vinculado: no hay nada que crear. */
        if ($conexion === null) {
            if ($tiene_evento && $evento_admin !== $admin_id) {
                /* Un evento de otro admin con la fecha vieja sería mentira: se borra y la
                   oportunidad queda sin evento. */
                if ($this->borrar_en_google($evento_admin, $evento_id, $op->id)) {
                    $this->guardar_evento($op, null, null);
                    $this->invalidar_cache([$this->fecha_que_bloquea($fecha_anterior)]);
                }
            }

            return $op;
        }

        $url_eventos = $this->url_eventos($conexion);

        /* Mismo admin y ya hay evento: se actualiza (un PATCH, no borrar y crear: recrearlo le
           cambiaría el id y le volvería a sonar la notificación al admin sin motivo). */
        if ($tiene_evento && $evento_admin === $admin_id) {
            $respuesta = $this->llamar($conexion, 'PATCH', $url_eventos . '/' . rawurlencode($evento_id), $this->cuerpo_del_evento($op, true), $op->id);

            if ($respuesta !== null && $respuesta->successful()) {
                $this->invalidar_cache([$this->fecha_que_bloquea($fecha_nueva), $this->fecha_que_bloquea($fecha_anterior)]);

                Log::channel('disponibilidad')->info(
                    self::LOG . ' Evento actualizado.'
                    . ' opportunity_id=' . $op->id . ' admin_id=' . $admin_id . ' google_event_id=' . $evento_id
                );

                return $op;
            }

            if ($respuesta === null || ! in_array($respuesta->status(), [404, 410], true)) {
                /* Falla de Google (o del token): ya se logueó. El evento queda como estaba. */
                if ($respuesta !== null) {
                    $this->loguear_falla('actualizar', $respuesta, $admin_id, $op->id);
                }

                return $op;
            }

            /* 404 / 410: el admin borró el evento a mano en su calendario. Se recrea más abajo. */
            Log::channel('disponibilidad')->info(
                self::LOG . ' El evento ya no existe en Google (HTTP ' . $respuesta->status() . '): se recrea.'
                . ' opportunity_id=' . $op->id . ' admin_id=' . $admin_id . ' google_event_id=' . $evento_id
            );
        } elseif ($tiene_evento) {
            /* Otro admin reprograma: el evento viejo se borra del calendario donde está (con SU
               token). Si falla igual se crea el nuevo: queda un evento huérfano, logueado. */
            if (! $this->borrar_en_google($evento_admin, $evento_id, $op->id)) {
                Log::channel('disponibilidad')->warning(
                    self::LOG . ' No se pudo borrar el evento del admin anterior; queda huérfano en su calendario.'
                    . ' opportunity_id=' . $op->id . ' admin_anterior=' . $evento_admin . ' google_event_id=' . $evento_id
                );
            }
        }

        /* Crear. */
        $respuesta = $this->llamar($conexion, 'POST', $url_eventos, $this->cuerpo_del_evento($op, false), $op->id);

        if ($respuesta === null) {
            return $op;
        }

        $nuevo_id = $respuesta->successful() ? $this->texto($respuesta->json('id')) : null;

        if ($nuevo_id === null) {
            $this->loguear_falla('crear', $respuesta, $admin_id, $op->id);

            return $op;
        }

        $this->guardar_evento($op, $nuevo_id, $admin_id);
        $this->invalidar_cache([$this->fecha_que_bloquea($fecha_nueva), $this->fecha_que_bloquea($fecha_anterior)]);

        Log::channel('disponibilidad')->info(
            self::LOG . ' Evento creado.'
            . ' opportunity_id=' . $op->id . ' admin_id=' . $admin_id . ' google_event_id=' . $nuevo_id
            . ' inicio=' . $fecha_nueva
        );

        return $op;
    }

    /**
     * El cuerpo del evento para la API de Google Calendar.
     *
     * Con hora: `start`/`end` con `dateTime` local + `timeZone`, `opaque` (bloquea el horario).
     * Sin hora (00:00:00): día completo (`date`, y el `end` es el día SIGUIENTE porque la fecha de
     * fin de un evento de día completo es exclusiva), `transparent` (no bloquea nada).
     *
     * @param PipelineOpportunity $op
     * @param bool                $para_parche true si es un PATCH: ahí hay que anular explícitamente
     *                            los campos del otro formato (`dateTime` / `date`), porque Google
     *                            mezcla los objetos del PATCH con los del evento existente y un
     *                            evento que pasa de "con hora" a "día completo" quedaría con los dos.
     *
     * @return array<string, mixed>
     */
    private function cuerpo_del_evento(PipelineOpportunity $op, $para_parche)
    {
        $op->loadMissing(['pipeline', 'stage', 'client', 'lead']);

        $inicio       = $op->next_action_at;
        $dia_completo = $inicio->format('H:i:s') === '00:00:00';

        $nota   = $this->texto($op->next_action_note);
        $etapa  = $op->stage ? (string) $op->stage->name : null;
        $sujeto = $this->describir_sujeto($op);

        $titulo = $nota !== null ? $nota : ($etapa !== null ? $etapa : 'Próxima acción');
        if ($sujeto['name'] !== null) {
            $titulo .= ' — ' . $sujeto['name'];
        }

        /* La descripción lleva el contexto, nunca teléfono ni mail del sujeto. */
        $lineas = [];
        if ($op->pipeline) {
            $lineas[] = 'Pipeline: ' . $op->pipeline->name;
        }
        if ($etapa !== null) {
            $lineas[] = 'Etapa: ' . $etapa;
        }
        if ($sujeto['name'] !== null) {
            $lineas[] = $sujeto['label'] . ': ' . $sujeto['name'];
        }
        if ($nota !== null) {
            $lineas[] = 'Próxima acción: ' . $nota;
        }
        $lineas[] = 'Fijada desde el CRM de ComercioCity (oportunidad #' . $op->id . ').';

        $cuerpo = [
            'summary'     => $titulo,
            'description' => implode("\n", $lineas),
        ];

        if ($dia_completo) {
            $cuerpo['start'] = ['date' => $inicio->format('Y-m-d')];
            $cuerpo['end']   = ['date' => $inicio->copy()->addDay()->format('Y-m-d')];
            if ($para_parche) {
                $cuerpo['start']['dateTime'] = null;
                $cuerpo['start']['timeZone'] = null;
                $cuerpo['end']['dateTime']   = null;
                $cuerpo['end']['timeZone']   = null;
            }
            /* 🔴 Libre a propósito (decisión 2 del docblock de la clase). */
            $cuerpo['transparency'] = 'transparent';
        } else {
            /* 🔴 La hora local TAL CUAL está guardada, con la zona en un campo aparte (decisión 3). */
            $cuerpo['start'] = ['dateTime' => $inicio->format('Y-m-d\TH:i:s'), 'timeZone' => self::ZONA];
            $cuerpo['end']   = [
                'dateTime' => $inicio->copy()->addMinutes(self::DURACION_MINUTOS)->format('Y-m-d\TH:i:s'),
                'timeZone' => self::ZONA,
            ];
            if ($para_parche) {
                $cuerpo['start']['date'] = null;
                $cuerpo['end']['date']   = null;
            }
            /* Bloquea el horario: `CloserGoogleCalendarBusyService` lo lee por freeBusy. */
            $cuerpo['transparency'] = 'opaque';
        }

        return $cuerpo;
    }

    /**
     * Nombre y rótulo ("Cliente" / "Lead") del sujeto de la oportunidad, con el mismo criterio que
     * el tablero (`PipelinePresenter`): así el evento dice lo mismo que la tarjeta. Un nombre vacío
     * (o de puros espacios) cuenta como sin nombre: el título no queda con un guion colgando ni la
     * descripción con un "Cliente: " vacío.
     *
     * @param PipelineOpportunity $op
     *
     * @return array{name: string|null, label: string}
     */
    private function describir_sujeto(PipelineOpportunity $op)
    {
        $presenter = new PipelinePresenter();

        if ($op->client_id !== null) {
            $nombre = $op->client ? $this->texto($presenter->subject_de_cliente($op->client, false)['name']) : null;

            return ['name' => $nombre, 'label' => 'Cliente'];
        }

        if ($op->lead_id !== null) {
            $nombre = $op->lead ? $this->texto($presenter->subject_de_lead($op->lead, false)['name']) : null;

            return ['name' => $nombre, 'label' => 'Lead'];
        }

        return ['name' => null, 'label' => 'Sujeto'];
    }

    /* ------------------------------------------------------------------------------------------
     | Google
     |----------------------------------------------------------------------------------------- */

    /**
     * Conexión de Google Calendar utilizable de un admin: activa y con calendario elegido. Es el
     * mismo criterio que `CloserGoogleCalendarEventService::get_closer_connection()`; la diferencia
     * es que acá el admin lo da quien ejecuta la acción y no "el primer closer".
     *
     * @param int $admin_id
     *
     * @return AdminCalendarConnection|null
     */
    private function conexion_activa($admin_id)
    {
        return AdminCalendarConnection::where('admin_id', $admin_id)
            ->where('is_active', true)
            ->whereNotNull('google_calendar_id')
            ->where('google_calendar_id', '!=', '')
            ->first();
    }

    /**
     * URL de la colección de eventos del calendario de una conexión.
     *
     * @param AdminCalendarConnection $conexion
     *
     * @return string
     */
    private function url_eventos(AdminCalendarConnection $conexion)
    {
        return self::URL_BASE . rawurlencode((string) $conexion->google_calendar_id) . '/events';
    }

    /**
     * Una llamada a la API de eventos con el token de la conexión. No lanza: si no se puede
     * obtener el token (revocado, red) o la llamada revienta, loguea y devuelve null.
     *
     * @param AdminCalendarConnection $conexion
     * @param string                  $metodo POST | PATCH | DELETE
     * @param string                  $url
     * @param array<string, mixed>    $cuerpo
     * @param int|null                $oportunidad_id Solo para el log.
     *
     * @return Response|null
     */
    private function llamar(AdminCalendarConnection $conexion, $metodo, $url, array $cuerpo, $oportunidad_id)
    {
        try {
            /* Si el refresco de ESTA conexión ya falló en este request, no se reintenta: un alta
               masiva con el endpoint de tokens caído haría 25 intentos (cada uno hasta su timeout)
               con el request del admin esperando. La falla se recuerda por conexión. */
            if (isset($this->tokens_fallidos[$conexion->id])) {
                return null;
            }

            if (! isset($this->tokens[$conexion->id])) {
                try {
                    $this->tokens[$conexion->id] = $this->oauth->get_fresh_access_token($conexion);
                } catch (\Throwable $e) {
                    $this->tokens_fallidos[$conexion->id] = $e->getMessage();

                    throw $e;
                }
            }

            $cliente = Http::withToken($this->tokens[$conexion->id])->timeout(self::TIMEOUT_SEGUNDOS);

            if ($metodo === 'POST') {
                return $cliente->post($url, $cuerpo);
            }

            if ($metodo === 'PATCH') {
                return $cliente->patch($url, $cuerpo);
            }

            return $cliente->delete($url);
        } catch (\Throwable $e) {
            Log::channel('disponibilidad')->warning(
                self::LOG . ' No se pudo llamar a Google Calendar (' . $metodo . ').'
                . ' opportunity_id=' . ($oportunidad_id === null ? 'null' : $oportunidad_id)
                . ' admin_id=' . $conexion->admin_id
                . ' error=' . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Borra un evento del calendario del admin dueño. Devuelve si el tema quedó RESUELTO, o sea si
     * se pueden limpiar las columnas: el evento se borró, ya no existía (404 / 410 = éxito), o el
     * admin ya no tiene calendario vinculado (no hay forma de borrarlo, y guardar el id para siempre
     * no ayuda). Una falla transitoria (5xx, red, 403) NO es resuelto: las columnas quedan para que
     * el próximo cambio de la oportunidad lo vuelva a intentar.
     *
     * @param int         $admin_id       Dueño del calendario donde está el evento.
     * @param string      $event_id
     * @param int|null    $oportunidad_id Solo para el log.
     *
     * @return bool
     */
    private function borrar_en_google($admin_id, $event_id, $oportunidad_id)
    {
        $conexion = $this->conexion_activa($admin_id);

        if ($conexion === null) {
            Log::channel('disponibilidad')->info(
                self::LOG . ' El admin ya no tiene calendario vinculado: el evento no se puede borrar de Google.'
                . ' admin_id=' . $admin_id . ' google_event_id=' . $event_id
            );

            return true;
        }

        $respuesta = $this->llamar($conexion, 'DELETE', $this->url_eventos($conexion) . '/' . rawurlencode($event_id), [], $oportunidad_id);

        if ($respuesta === null) {
            return false;
        }

        if ($respuesta->successful() || in_array($respuesta->status(), [404, 410], true)) {
            Log::channel('disponibilidad')->info(
                self::LOG . ' Evento borrado.'
                . ' opportunity_id=' . ($oportunidad_id === null ? 'null' : $oportunidad_id)
                . ' admin_id=' . $admin_id . ' google_event_id=' . $event_id
            );

            return true;
        }

        $this->loguear_falla('borrar', $respuesta, $admin_id, $oportunidad_id);

        return false;
    }

    /**
     * Loguea una respuesta de Google que no fue un éxito. El 403 se distingue porque tiene una
     * causa conocida y accionable (token conectado antes de que existiera el scope de escritura).
     *
     * @param string      $accion         crear | actualizar | borrar
     * @param Response    $respuesta
     * @param int|null    $admin_id
     * @param int|null    $oportunidad_id
     *
     * @return void
     */
    private function loguear_falla($accion, Response $respuesta, $admin_id, $oportunidad_id)
    {
        $detalle = $respuesta->status() === 403
            ? ' El token del admin no tiene el scope calendar.events: tiene que desconectarse y reconectarse el calendario desde Usuarios admin.'
            : '';

        Log::channel('disponibilidad')->warning(
            self::LOG . ' Google Calendar rechazó la acción de ' . $accion . '.'
            . ' opportunity_id=' . ($oportunidad_id === null ? 'null' : $oportunidad_id)
            . ' admin_id=' . ($admin_id === null ? 'null' : $admin_id)
            . ' HTTP=' . $respuesta->status()
            . ' body=' . substr($respuesta->body(), 0, 300)
            . $detalle
        );
    }

    /* ------------------------------------------------------------------------------------------
     | Estado local y caché
     |----------------------------------------------------------------------------------------- */

    /**
     * Guarda (o limpia, con nulls) el evento de la oportunidad. Va por `query()->update()` y no por
     * `save()`: no toca `updated_at` (esto no es un cambio de la oportunidad que el admin tenga que
     * ver como "se movió") y no pisa nada más que las dos columnas aunque otro request haya tocado
     * la fila. También se setean en la instancia, para que quien la sigue usando vea lo mismo que
     * la base.
     *
     * @param PipelineOpportunity $op
     * @param string|null         $event_id
     * @param int|null            $admin_id
     *
     * @return void
     */
    private function guardar_evento(PipelineOpportunity $op, $event_id, $admin_id)
    {
        PipelineOpportunity::query()->whereKey($op->id)->update([
            'next_action_calendar_admin_id' => $admin_id,
            'next_action_calendar_event_id' => $event_id,
        ]);

        $op->next_action_calendar_admin_id = $admin_id;
        $op->next_action_calendar_event_id = $event_id;
        $op->syncOriginalAttribute('next_action_calendar_admin_id');
        $op->syncOriginalAttribute('next_action_calendar_event_id');
    }

    /**
     * Invalida la caché de disponibilidad (la que lee el agente de demos) de cada fecha dada. Solo
     * hace falta cuando el evento BLOQUEA horario: uno de día completo es transparente y no cambia
     * la disponibilidad, así que no se invalida nada.
     *
     * @param array<int, string|null> $fechas `Y-m-d` o null (se ignoran los null y los repetidos).
     *
     * @return void
     */
    private function invalidar_cache(array $fechas)
    {
        foreach (array_unique(array_filter($fechas)) as $fecha) {
            $this->busy->flush_cache_for_date($fecha);
        }
    }

    /**
     * La fecha `Y-m-d` en la que un evento de esta próxima acción BLOQUEA horario, o null si no
     * bloquea (no hay fecha, o es de día completo y por lo tanto transparente).
     *
     * @param string|null $fecha_hora `Y-m-d H:i:s` o null.
     *
     * @return string|null
     */
    private function fecha_que_bloquea($fecha_hora)
    {
        if ($fecha_hora === null || ! preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/', $fecha_hora, $partes)) {
            return null;
        }

        return $partes[2] === '00:00:00' ? null : $partes[1];
    }

    /**
     * Una fecha como `Y-m-d H:i:s`, o null. Acepta el texto del presenter o un objeto de fecha.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function fecha_a_texto($valor)
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /**
     * Texto recortado, o null si está vacío o no es texto.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function texto($valor)
    {
        if ($valor === null || is_array($valor) || is_bool($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}

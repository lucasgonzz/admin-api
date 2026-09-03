<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Demo;
use App\Models\Lead;
use App\Services\ClaudeQueryService;
use App\Services\LeadAiService;
use App\Services\LeadRescheduleFlagsService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Edición de los campos descriptivos y de agenda de UN lead, desde los endpoints `claude/*`.
 *
 * Por qué existe: el bloque `claude/*` podía leer todo el pipeline, mandar mensajes y mover el
 * estado de un lead, pero no podía corregir un rubro mal cargado, guardar el dolor que el lead
 * contó en la conversación, cargarle el mail ni —sobre todo— AGENDAR LA DEMO, que es el objetivo
 * entero del chequeo diario de leads. Todo eso solo se podía hacer entrando al panel a mano.
 *
 * 🔴 LISTA BLANCA CERRADA, Y UN CAMPO NO DECLARADO ES 422 CON LA LISTA DE LOS VÁLIDOS
 * -----------------------------------------------------------------------------------
 * Nunca se ignora un campo en silencio. Mismo criterio que los filtros de `GET claude/query`: si
 * lo que mandaste no existe, tenés que enterarte en la respuesta y no descubrirlo tres días
 * después mirando por qué el dato no está. Los válidos son {@see self::CAMPOS} y los prohibidos
 * más probables llevan su motivo escrito en el cuerpo del 422 ({@see self::MOTIVOS_PROHIBIDOS}):
 * no alcanza con decir "ese no", hay que decir por dónde va.
 *
 * 🔴 LOS CAMPOS VAN ADENTRO DE `campos`, NO SUELTOS EN EL BODY. Si fueran sueltos, `dry_run`,
 * `confirm_count` y los dos permisos explícitos viajarían al lado de los datos y habría que
 * excluirlos a mano de la lista blanca — una lista blanca con excepciones deja de ser una lista
 * blanca, y el día que se agregue un freno nuevo alguien se olvida de excluirlo y el freno se
 * vuelve un campo del lead.
 *
 * 🔴 LOS FRENOS, en el orden en que corren:
 *   1. Todo campo del payload tiene que estar en la lista blanca.
 *   2. Un lead ya promovido a cliente (`promoted_client_id` no nulo) no se toca. Es el MISMO
 *      criterio que `ClaudeLeadsPipelineController::motivo_de_bloqueo()`. De ese criterio se copia
 *      solo esa mitad y no la del `cerrado_ganado`, a propósito: allá se bloquea el estado porque
 *      ese tramo cuelga de la promoción a Client, y acá el estado no se toca — corregirle el
 *      nombre de contacto a un lead que se cerró ganado hace un mes no rompe nada.
 *   3. Cada valor se valida contra su columna REAL: los formatos de fecha son una lista cerrada, el
 *      `meeting_scheduled_at` tiene que entrar en el rango de un `timestamp` y `notes` se mide en
 *      BYTES contra el largo de un `TEXT`. Los tres nacieron de tres 500 medidos: ver
 *      {@see self::normalizar()}.
 *   4. AGENDAR DE VERDAD: una `demo_date` sin instancia es 422, el turno resultante no puede caer
 *      en el pasado y el horario tiene que estar LIBRE en la grilla real, validado adentro del
 *      lock `demo_slot_hold_{demo_id}`. Ver el bloque de abajo.
 *   5. `dry_run` en `true` por defecto, devolviendo el DIFF campo por campo (actual → propuesto).
 *      Sin `dry_run=false` explícito no se escribe absolutamente nada. 🔴 La simulación corre los
 *      frenos 1 a 4 IGUAL que la escritura: un dry_run que devuelve 200 y después revienta al
 *      aplicar no sirve para nada, que es exactamente lo que pasaba antes del 3/9/2026.
 *   6. Con `dry_run=false`, `confirm_count` tiene que ser exactamente la cantidad de campos que
 *      CAMBIAN (la que devolvió la simulación).
 *   7. Un campo que llega con el valor que ya tiene se reporta `sin_cambio` y no cuenta como
 *      escritura: reintentar la misma llamada es seguro.
 *
 * 🔴 AGENDAR NO ES ESCRIBIR UNA FECHA (arreglado el 3/9/2026)
 * -----------------------------------------------------------
 * Hasta esa fecha este endpoint escribía `demo_date` / `demo_start_time` / `demo_end_time` y nada
 * más. Dos cosas se rompían con eso, las dos medidas:
 *
 *   1. NO ESCRIBÍA `demo_id`, y sin instancia asignada la demo no existe:
 *      `LeadController::send_demo_mail_json()` la exige y devuelve 422 ("falta demo asignada"), o
 *      sea que el lead agendado por acá NUNCA recibía el mail de demo, y `DemoIngresoTokenService`
 *      no tenía instancia sobre la cual emitir el token de ingreso. Ahora `demo_id` está en la
 *      lista blanca y escribir `demo_date` sin instancia (ni en el lead ni en el payload) es 422.
 *   2. NO PASABA POR LA GRILLA, así que podía pisarle el horario a otro lead sobre la MISMA
 *      instancia sin que nada lo denunciara. Ahora el horario se valida contra la grilla real, que
 *      es la que ya filtra por instancia, por rangos ocupados de otros leads y por el calendario
 *      del closer.
 *
 * 🔴 EL CÁLCULO DE LA GRILLA NO SE REIMPLEMENTA. Lo hace `LeadAiService::build_availability_json()`
 * con `exclude_lead_id = $lead->id` —para que el turno que el propio lead ya tiene no se bloquee
 * contra sí mismo—, que es EXACTAMENTE la forma en que lo reutiliza
 * `LeadController::panel_availability_json()`. Una segunda definición de "slot válido" se separa de
 * la primera en cuanto alguien toque una sola, y la que queda vieja es siempre la que nadie mira.
 *
 * 🔴 Y CORRE ADENTRO DEL LOCK `demo_slot_hold_{demo_id}`, el mismo nombre que toma `LeadAiService`
 * al asignar un turno. Sin el lock, leer la grilla y escribir el turno no serían atómicos y dos
 * requests concurrentes sobre la misma demo física volverían a poder pisarse — que es el bug de
 * colisión de horarios ya documentado en `LeadAiService` (leads 65, 70, 93, 192, 197 y 234 en
 * producción, 1/7/2026). Acá se toma por el mismo motivo y con el mismo nombre: dos locks distintos
 * sobre la misma demo no serializan nada.
 *
 * ⚠️ `permitir_horario_fuera_de_grilla` (default `false`) saltea SÓLO la validación de "figura en
 * la lista de libres", nunca el lock. Existe porque el panel puede forzar un horario que la grilla
 * no ofrece (`forzar_slot` en `LeadAiService`), y un endpoint que no puede hacer lo que el panel
 * hace obliga a volver al panel. Es explícito y nunca por default.
 *
 * 🔴 `notes` REEMPLAZA, NO AGREGA. Ver {@see self::ADVERTENCIA_DE_NOTES}: esa columna la escribe
 * una persona desde el panel y este endpoint hace `update()`, así que escribirla sin leerla antes
 * le borra la nota a Lucas. La advertencia viaja en la simulación, en la respuesta de la escritura
 * y en el 422 de `notes`.
 *
 * ⚠️ Lo que este endpoint NO hace, igual que el panel: no le avisa al lead por WhatsApp que se
 * cambió el horario (`DemoScheduledWhatsappService`, que solo dispara desde el flujo del agente en
 * `LeadAiService`) y no mueve por su cuenta el evento de Google Calendar del closer. Para eso está
 * `POST claude/leads/{id}/calendar-event` ({@see ClaudeLeadsAvailabilityController}), que es la
 * ruta que una sesión con `X-Claude-Task-Key` SÍ puede llamar — el `POST admin/lead/{id}/…` que
 * este docblock recomendaba hasta el 3/9/2026 vive detrás de `auth:sanctum` y era un consejo
 * inaplicable.
 */
class ClaudeLeadsFieldsController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Lo que aguanta una columna `TEXT` de MySQL: 65.535 BYTES (no caracteres).
     *
     * @var int
     */
    const BYTES_DE_UN_TEXT = 65535;

    /**
     * Los bordes de una columna `timestamp` de MySQL, en UTC.
     *
     * Fuera de este rango el INSERT explota en el driver y sale un 500 con stack trace, que es lo
     * contrario del 422 legible que todo `claude/*` promete. Medidos: `9999-12-31 23:59` y
     * `1000-01-01 00:00` daban 500 antes del 3/9/2026.
     *
     * @var string
     */
    const TIMESTAMP_MINIMO_UTC = '1970-01-01 00:00:01';

    /** @var string Ver {@see self::TIMESTAMP_MINIMO_UTC}. */
    const TIMESTAMP_MAXIMO_UTC = '2038-01-19 03:14:07';

    /** @var int Segundos que vive el lock de la demo, igual que en LeadAiService. */
    const LOCK_TTL_SEGUNDOS = 8;

    /** @var int Segundos que se espera el lock antes de rendirse, igual que en LeadAiService. */
    const LOCK_ESPERA_SEGUNDOS = 5;

    /**
     * Cuántos caracteres del valor recibido se devuelven en el cuerpo de un 422.
     *
     * Existe porque el 422 de `notes` con 20.000 emoji devolvía los 20.000 emoji: un error de
     * 80 KB no lo lee nadie y encima duplica el problema que está denunciando.
     *
     * @var int
     */
    const RECORTE_DEL_VALOR_EN_EL_ERROR = 200;

    /**
     * 🔴 Lo que hay que saber antes de escribir `notes`, y que hasta el 3/9/2026 no estaba escrito
     * en ninguna parte: el catálogo la presentaba como "el lugar del dolor detectado" sin decir que
     * PISA.
     *
     * @var string
     */
    const ADVERTENCIA_DE_NOTES = '🔴 `notes` REEMPLAZA lo que haya escrito: no agrega ni concatena. '
        . 'Esa columna la llena Lucas a mano desde el panel del lead, así que escribirla sin leerla '
        . 'antes le borra la nota a una persona y no queda registro de lo que había. El camino '
        . 'correcto son dos pasos: leer el valor de hoy (GET claude/query?model=lead, o el propio '
        . 'dry_run de este endpoint, que lo devuelve en diff.notes.actual) y recién después mandar '
        . 'el texto COMPLETO ya integrado, lo viejo más lo nuevo.';

    /**
     * La lista blanca, con el tipo de cada campo. Nada que no esté acá se puede escribir.
     *
     * Tipos: `texto`, `email`, `fecha` (Y-m-d), `hora` (HH:MM o HH:MM:SS), `booleano`,
     * `fecha_hora`, `demo` (id de una instancia del pool).
     *
     * ⚠️ `max` cuenta CARACTERES y `max_bytes` cuenta BYTES, y no son lo mismo ni intercambiables:
     * un `varchar(150)` de MySQL son 150 caracteres, pero un `TEXT` son 65.535 bytes. Por eso
     * `notes` lleva los dos: el tope de caracteres es una decisión de producto (una nota más larga
     * que eso no la lee nadie) y el de bytes es el borde real de la columna. Medido el 3/9/2026:
     * 20.000 emoji pasaban el `max` de 20.000 caracteres, ocupaban 80.000 bytes y daban 500.
     */
    const CAMPOS = [
        'contact_name'         => ['tipo' => 'texto', 'max' => 150],
        'company_name'         => ['tipo' => 'texto', 'max' => 150],
        'business_type'        => ['tipo' => 'texto', 'max' => 80],
        /* 🔴 `notes` es el lugar del proyecto para el "dolor detectado": NO existe columna `dolor`
           y no se crea una (esta misión no lleva migraciones). 🔴 Y REEMPLAZA: ver
           self::ADVERTENCIA_DE_NOTES antes de escribirla. */
        'notes'                => ['tipo' => 'texto', 'max' => 20000, 'max_bytes' => self::BYTES_DE_UN_TEXT],
        'email'                => ['tipo' => 'email', 'max' => 150],
        /* 🔴 La INSTANCIA de demo. Sin esto, agendar no agenda: el mail de demo no sale y no hay
           sobre qué emitir el token de ingreso. Ver el docblock de la clase. */
        'demo_id'              => ['tipo' => 'demo'],
        'demo_date'            => ['tipo' => 'fecha'],
        'demo_start_time'      => ['tipo' => 'hora', 'max' => 32],
        'demo_end_time'        => ['tipo' => 'hora', 'max' => 32],
        'demo_flexible'        => ['tipo' => 'booleano'],
        'meeting_scheduled_at' => ['tipo' => 'fecha_hora'],
    ];

    /**
     * Campos que NO se pueden escribir desde acá, con el motivo. Van en el cuerpo del 422 porque un
     * "ese campo no existe" a secas manda al que llamó a buscar en el código; el motivo lo manda al
     * lugar correcto.
     */
    const MOTIVOS_PROHIBIDOS = [
        'status'               => 'El estado del pipeline se mueve con POST claude/leads/{id}/status (o el lote status-batch), que tiene sus propios frenos: no asigna cerrado_ganado y deja el evento en la conversación.',
        'phone'                => '🔴 Cambiar el teléfono redirige TODOS los envíos futuros de ese lead a otro número. Se toca desde el panel, mirando la conversación.',
        'doc_number'           => '🔴 Es la CONTRASEÑA con la que el lead entra a la demo (LeadDemoMailHelper arma el mail con doc_number como usuario y contraseña). Cambiarlo deja muerto el acceso del mail que ya se envió, sin que nada lo avise. Lo asigna el sistema (LeadDocNumberGenerator) y se corrige desde el panel.',
        'target_client_id'     => 'Es el empresa-api del cliente contra el que se dispara la demo remota, no la instancia del pool. La instancia SÍ se escribe desde acá y se llama demo_id.',
        'google_event_id / meet_url' => 'Los escribe CloserGoogleCalendarEventService al crear el evento del closer. Para (re)crear ese evento y su link de Meet está POST claude/leads/{id}/calendar-event; escribirlos a mano dejaría al lead con un link que no apunta a ningún evento.',
        'contract_*'           => 'Los campos de contrato los arma la generación de contrato, no una edición suelta.',
        'promoted_client_id'   => 'Cuelga de la promoción a Client (contrato y alta). Escribirlo a mano dejaría un lead apuntando a un cliente que nadie dio de alta.',
        'user_id'              => 'Se asigna recién en la promoción a Client. El propio panel lo saca del request de edición a propósito.',
        'demo_ingreso_token_*' => 'Los tokens de ingreso a la demo los emite y revoca el sistema, con vencimiento propio.',
        'flags de automatización (claude_auto_reply, requiere_*, notificar_mensajes, automatizaciones_demo_activas, auto_*)' => 'Los pone una persona mirando la conversación. Un agente no decide dejar de verificar lo que le sale al lead.',
    ];

    /**
     * Actualiza campos de UN lead por lista blanca.
     *
     * @param Request    $request Body: campos{} (req), dry_run, confirm_count,
     *                            permitir_horario_fuera_de_grilla, permitir_fecha_pasada.
     * @param int|string $id      Lead objetivo.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'campos'                           => 'required|array|min:1',
            'dry_run'                          => 'nullable|boolean',
            'confirm_count'                    => 'nullable|integer|min:0',
            'permitir_horario_fuera_de_grilla' => 'nullable|boolean',
            'permitir_fecha_pasada'            => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $campos = (array) $request->input('campos', []);

        /* --- Freno 1: la lista blanca, ANTES de resolver el lead. Un campo inventado es un error
               de armado y aborta todo: nada se escribe y nada se ignora en silencio. --- */
        foreach (array_keys($campos) as $campo) {
            if (! array_key_exists($campo, self::CAMPOS)) {
                return $this->error_422(
                    'El campo "' . $campo . '" no se puede escribir desde acá. No se escribió nada.',
                    [
                        'campos_validos'             => array_keys(self::CAMPOS),
                        'motivos_de_los_prohibidos'  => self::MOTIVOS_PROHIBIDOS,
                    ]
                );
            }
        }

        $lead = Lead::find((int) $id);
        if ($lead === null) {
            return $this->error_404('No existe el lead ' . (int) $id . '.');
        }

        /* --- Freno 2: lead ya promovido a cliente. Mismo criterio que
               ClaudeLeadsPipelineController::motivo_de_bloqueo(); ver el docblock de la clase para
               por qué se copia sólo esa mitad. --- */
        if ($lead->promoted_client_id !== null) {
            return $this->error_422(
                'El lead ya está promovido a cliente (promoted_client_id=' . (int) $lead->promoted_client_id
                    . '); sus campos no se tocan desde acá. No se escribió nada.',
                ['lead_id' => (int) $lead->id]
            );
        }

        /* Normalización y validación de VALOR, campo por campo y en español. No se hace con reglas
           de Laravel porque `email`, la hora HH:MM y el booleano estricto no están en la lista de
           mensajes traducidos del trait, y una regla que no está ahí no falla: contesta en inglés
           (ver el docblock de RespuestasParaClaude::mensajes_de_validacion()). */
        $normalizados = [];
        foreach ($campos as $campo => $valor_crudo) {
            $normalizado = $this->normalizar($campo, $valor_crudo);
            if (! $normalizado['ok']) {
                $extra = [
                    'campo'          => $campo,
                    'valor_recibido' => $this->recortar_para_el_error($valor_crudo),
                ];

                /* El 422 de `notes` es el único momento garantizado en que alguien que la está
                   escribiendo mal lee una respuesta: la advertencia de que PISA va acá también. */
                if ($campo === 'notes') {
                    $extra['advertencia'] = self::ADVERTENCIA_DE_NOTES;
                }

                return $this->error_422(
                    'El campo "' . $campo . '" ' . $normalizado['error'] . ' No se escribió nada.',
                    $extra
                );
            }

            $normalizados[$campo] = $normalizado['valor'];
        }

        /* --- Freno 7: el diff. Un campo que ya tiene el valor propuesto no cuenta como escritura. --- */
        $diff       = [];
        $sin_cambio = [];
        foreach ($normalizados as $campo => $valor_nuevo) {
            $valor_actual = $this->actual_de($lead, $campo);

            if ($this->son_iguales($valor_actual, $valor_nuevo)) {
                $sin_cambio[$campo] = $valor_actual;
                continue;
            }

            $diff[$campo] = ['actual' => $valor_actual, 'propuesto' => $valor_nuevo];
        }

        /* --- Freno 4: la agenda. Lo que va a quedar en las cuatro columnas del turno DESPUÉS de
               escribir, que es lo único contra lo que tiene sentido validar. --- */
        $agenda        = $this->agenda_resultante($lead, $normalizados);
        $cambia_turno  = $this->cambia_el_turno($diff);

        /* Una demo sin instancia no manda mail ni emite token: ver el docblock de la clase. */
        if (array_key_exists('demo_date', $normalizados) && $normalizados['demo_date'] !== null
            && $agenda['demo_id'] === null) {
            return $this->error_422(
                'Se está escribiendo demo_date pero el lead no tiene demo_id ni viene uno en el payload. '
                    . 'No se escribió nada: una demo sin INSTANCIA asignada no manda el mail de demo '
                    . '(LeadController::send_demo_mail_json lo rechaza con 422) ni tiene sobre qué emitir el token '
                    . 'de ingreso, así que quedaría una fecha escrita y ninguna demo agendada.',
                [
                    'lead_id' => (int) $lead->id,
                    'ayuda'   => 'Pedí GET claude/leads/' . (int) $lead->id . '/availability: devuelve las instancias '
                        . 'del pool (clave `demos`) y los horarios libres de cada una. Mandá demo_id junto con '
                        . 'demo_date en el mismo `campos`.',
                ]
            );
        }

        /* El turno en el PASADO desarma la guarda `if ($demo_start->isFuture()) return null;` de
           LeadFollowupService::process_lead(): el lead pasa a contar como "no se presentó" y el cron
           le manda la tanda de seguimiento sobre una demo que nunca ocurrió. Medido el 3/9/2026:
           demo_date=2020-01-01 sobre un lead en demo_agendada devolvía 200. */
        $turno_en_el_pasado = $cambia_turno && $this->turno_en_el_pasado($agenda);
        if ($turno_en_el_pasado && ! $request->boolean('permitir_fecha_pasada')) {
            return $this->error_422(
                'El turno que quedaría (' . $this->turno_legible($agenda) . ') está en el PASADO. No se escribió nada: '
                    . 'sobre un lead en demo_agendada eso lo hace contar como "no se presentó" y el cron de seguimiento '
                    . 'le manda la tanda al lead por una demo que nunca ocurrió.',
                [
                    'lead_id' => (int) $lead->id,
                    'turno'   => $this->turno_legible($agenda),
                    'ayuda'   => 'Si estás corrigiendo un registro viejo a propósito, repetí la llamada con '
                        . 'permitir_fecha_pasada=true. Para agendar de verdad, elegí un horario de '
                        . 'GET claude/leads/' . (int) $lead->id . '/availability.',
                ]
            );
        }

        /*
         * La grilla se valida cuando el turno cambia y hay contra qué validar. Se saltea:
         *   - con `permitir_horario_fuera_de_grilla=true` (el permiso explícito de forzar), y
         *   - cuando el turno es pasado Y ya se permitió explícitamente, porque la grilla sólo
         *     describe el futuro: exigir ahí el segundo permiso sería exigir un flag para un
         *     chequeo que no puede pasar nunca.
         * Sin `demo_start_time` no hay slot que ocupar, así que tampoco hay nada que validar.
         */
        $valida_la_grilla = $cambia_turno
            && $agenda['demo_id'] !== null
            && $agenda['demo_date'] !== null
            && $agenda['demo_start_time'] !== null
            && ! $turno_en_el_pasado
            && ! $request->boolean('permitir_horario_fuera_de_grilla');

        $contexto = [
            'diff'             => $diff,
            'sin_cambio'       => $sin_cambio,
            'agenda'           => $agenda,
            'valida_la_grilla' => $valida_la_grilla,
        ];

        $aplicar = function () use ($request, $lead, $contexto) {
            return $this->validar_la_grilla_y_aplicar($request, $lead, $contexto);
        };

        /* 🔴 Leer la grilla y escribir el turno tienen que ser atómicos sobre la misma demo física:
           ver el docblock de la clase. El lock se toma aunque se haya permitido forzar el horario,
           igual que hace LeadAiService con `forzar_slot`. */
        if ($cambia_turno && $agenda['demo_id'] !== null) {
            return $this->con_el_lock_de_la_demo((int) $agenda['demo_id'], $aplicar);
        }

        return $aplicar();
    }

    /**
     * La segunda mitad de `update_json()`: valida el horario contra la grilla y después simula o
     * escribe. Está separada porque, cuando el turno cambia, TODO esto corre adentro del lock de la
     * demo — y el `dry_run` sale por el medio, así que un solo bloque no alcanzaba.
     *
     * @param Request              $request  Request entrante.
     * @param Lead                 $lead     Lead objetivo, ya verificado.
     * @param array<string, mixed> $contexto diff, sin_cambio, agenda y valida_la_grilla.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function validar_la_grilla_y_aplicar(Request $request, Lead $lead, array $contexto)
    {
        $diff       = $contexto['diff'];
        $sin_cambio = $contexto['sin_cambio'];
        $agenda     = $contexto['agenda'];
        $cambiarian = count($diff);

        if ($contexto['valida_la_grilla']) {
            $libres = $this->horarios_libres_para($lead, $agenda);
            $pedido = $this->hora_hhmm($agenda['demo_start_time']);

            if (! in_array($pedido, $libres, true)) {
                return $this->error_422(
                    'El horario ' . $pedido . ' del ' . $agenda['demo_date'] . ' NO está libre en la grilla de la '
                        . 'instancia de demo ' . (int) $agenda['demo_id'] . '. No se escribió nada: escribirlo igual '
                        . 'le pisa el turno a otro lead sobre la misma instancia y nada lo denunciaría.',
                    [
                        'lead_id'          => (int) $lead->id,
                        'demo_id'          => (int) $agenda['demo_id'],
                        'fecha'            => $agenda['demo_date'],
                        'horario_pedido'   => $pedido,
                        'horarios_libres'  => $libres,
                        'ayuda'            => 'Elegí uno de horarios_libres. La grilla completa (todas las instancias '
                            . 'y todas las fechas de la ventana) sale de GET claude/leads/' . (int) $lead->id
                            . '/availability. Si el horario tiene que ser ése aunque la grilla no lo ofrezca —lo mismo '
                            . 'que hace el panel al forzar—, repetí la llamada con permitir_horario_fuera_de_grilla=true.',
                    ]
                );
            }
        }

        /* Los tres campos cuyo cambio dispara el reset de reagenda los declara el servicio, no este
           controlador: una segunda lista acá se separaría de la de allá. */
        $reagenda     = $this->cambia_la_agenda($diff);
        $advertencias = $this->advertencias_de($lead, $diff);

        /* --- Freno 5: simulación, que es el default. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            $respuesta = [
                'dry_run'    => true,
                'lead_id'    => (int) $lead->id,
                'cambiarian' => $cambiarian,
                'diff'       => (object) $diff,
                'sin_cambio' => (object) $sin_cambio,
                'turno'      => $this->turno_resultante_publicable($agenda, $contexto['valida_la_grilla']),
                'nota'       => 'Simulación: no se escribió nada en el lead. Para aplicar de verdad, repetí la '
                    . 'misma llamada con dry_run=false y confirm_count=' . $cambiarian . '.',
            ];

            if ($reagenda) {
                /* Se muestran leídos del MISMO servicio que después los escribe: si el dry_run
                   armara su propia lista, volvería a haber dos definiciones del reset. */
                $respuesta['reset_de_reagenda'] = app(LeadRescheduleFlagsService::class)->flags_reseteados();
                $respuesta['nota'] .= ' 🔴 Cambia el turno de la demo: además de los campos, se resetean los flags de '
                    . 'recordatorio que están en reset_de_reagenda, igual que cuando se reagenda desde el panel.';
            }

            if (! empty($advertencias)) {
                $respuesta['advertencias'] = $advertencias;
            }

            return response()->json($respuesta, 200);
        }

        /* --- Freno 6: confirmación explícita del número exacto de campos que cambian. --- */
        if (! $request->filled('confirm_count')) {
            return $this->error_422(
                'confirm_count es obligatorio cuando dry_run es false. No se escribió nada.',
                ['cambiarian' => $cambiarian]
            );
        }

        if ((int) $request->input('confirm_count') !== $cambiarian) {
            return $this->error_422(
                'confirm_count (' . (int) $request->input('confirm_count') . ') no coincide con los ' . $cambiarian
                    . ' campos que realmente cambian. No se escribió nada: volvé a correr la simulación.',
                ['cambiarian' => $cambiarian, 'diff' => (object) $diff]
            );
        }

        if ($cambiarian === 0) {
            /* Todo el payload venía con los valores que el lead ya tenía. No es un error: es la
               segunda corrida de la misma llamada, y tiene que ser barata y silenciosa. */
            return response()->json([
                'dry_run'    => false,
                'lead_id'    => (int) $lead->id,
                'escritos'   => 0,
                'sin_cambio' => (object) $sin_cambio,
                'reagendado' => false,
                'nota'       => 'Todos los campos ya tenían el valor propuesto: no se escribió nada ni se '
                    . 'resetearon flags.',
            ], 200);
        }

        /* Foto de la agenda ANTES de escribir, armada por el servicio que después decide el reset:
           ver el docblock de LeadRescheduleFlagsService. */
        $agenda_previa = app(LeadRescheduleFlagsService::class)->fotografiar_agenda($lead);

        $a_escribir = [];
        foreach ($diff as $campo => $valores) {
            $a_escribir[$campo] = $valores['propuesto'];
        }
        $lead->update($a_escribir);

        /* 🔴 UNA SOLA DEFINICIÓN DEL RESET DE REAGENDA: el mismo servicio que usan los dos caminos
           del panel. Se refresca antes para leer la agenda ya persistida, igual que hace
           LeadController::update_json(). */
        $lead->refresh();
        $reagendado = app(LeadRescheduleFlagsService::class)->resetear_si_cambio_la_agenda($lead, $agenda_previa);

        Log::channel('daily')->info('ClaudeLeadsFieldsController: campos de lead actualizados.', [
            'lead_id'    => (int) $lead->id,
            'campos'     => array_keys($diff),
            'reagendado' => $reagendado,
        ]);

        $respuesta = [
            'dry_run'    => false,
            'lead_id'    => (int) $lead->id,
            'escritos'   => $cambiarian,
            'diff'       => (object) $diff,
            'sin_cambio' => (object) $sin_cambio,
            'reagendado' => $reagendado,
            'turno'      => $this->turno_resultante_publicable($agenda, $contexto['valida_la_grilla']),
        ];

        if (! empty($advertencias)) {
            $respuesta['advertencias'] = $advertencias;
        }

        return response()->json($respuesta, 200);
    }

    /**
     * Corre `$cuerpo` adentro del lock exclusivo de la demo física.
     *
     * 🔴 EL NOMBRE DEL LOCK ES EL MISMO QUE TOMA `LeadAiService` (`demo_slot_hold_{demo_id}`) y eso
     * es todo el punto: dos locks con nombres distintos sobre la misma demo no serializan nada, así
     * que el agente podría estar asignando el mismo horario mientras este endpoint lo valida.
     *
     * 🔴 `block()` NO devuelve false al vencer la espera: tira `LockTimeoutException` (ver
     * `Illuminate\Cache\Lock::block()`, que sólo puede devolver true). Sin este try/catch la rama de
     * "no se pudo tomar el lock" sería inalcanzable y un timeout de contención saldría como 500.
     *
     * @param int      $demo_id Instancia de demo involucrada.
     * @param callable $cuerpo  Lo que hay que correr con el lock tomado.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function con_el_lock_de_la_demo(int $demo_id, callable $cuerpo)
    {
        $lock   = Cache::lock('demo_slot_hold_' . $demo_id, self::LOCK_TTL_SEGUNDOS);
        $tomado = false;

        try {
            $tomado = (bool) $lock->block(self::LOCK_ESPERA_SEGUNDOS);
        } catch (LockTimeoutException $e) {
            $tomado = false;
        }

        if (! $tomado) {
            /* Contención transitoria, no un descarte: otra request está asignando esta misma demo
               física en este instante. Se pide reintentar y no se escribe nada. */
            return $this->error_422(
                'No se pudo tomar el lock de la instancia de demo ' . $demo_id . ' en '
                    . self::LOCK_ESPERA_SEGUNDOS . ' segundos: hay otra asignación en curso sobre la misma demo '
                    . 'física. No se escribió nada. Es transitorio: reintentá la misma llamada.',
                ['demo_id' => $demo_id, 'reintentable' => true]
            );
        }

        try {
            return $cuerpo();
        } finally {
            $lock->release();
        }
    }

    /**
     * Los horarios que la grilla REAL declara libres para esa instancia y esa fecha.
     *
     * 🔴 NO CALCULA NADA: delega en `LeadAiService::build_availability_json()` con
     * `exclude_lead_id = $lead->id`, que es la misma forma en que lo reutiliza
     * `LeadController::panel_availability_json()`. Ese cálculo ya filtra por instancia, por rangos
     * ocupados de otros leads y por el calendario del closer.
     *
     * ⚠️ La fecha pedida viaja como `specific_date`: si cae más allá de la ventana por defecto de
     * {@see LeadAiService::DIAS_DISPONIBILIDAD} días, el servicio EXTIENDE la ventana hasta ella. Sin
     * eso, agendar a tres semanas daría "no está libre" por una demo que en realidad nadie tomó.
     *
     * @param Lead                 $lead   Lead objetivo (se excluye del bloqueo contra sí mismo).
     * @param array<string, mixed> $agenda Turno resultante.
     *
     * @return array<int, string> Horarios 'HH:MM'.
     */
    protected function horarios_libres_para(Lead $lead, array $agenda): array
    {
        /* Los tres son parámetros POR REFERENCIA del servicio: tienen que ser variables. */
        $snapshot = null;
        $config   = null;
        $ventanas = null;

        $disponibilidad = app(LeadAiService::class)->build_availability_json(
            LeadAiService::DIAS_DISPONIBILIDAD,
            $snapshot,
            (string) $agenda['demo_date'],
            (int) $lead->id,
            $lead->usa_experiencia_demo_nueva(),
            null,
            $config,
            $ventanas
        );

        $por_fecha = isset($disponibilidad['demos'][(int) $agenda['demo_id']])
            ? (array) $disponibilidad['demos'][(int) $agenda['demo_id']]
            : [];

        /* El servicio arma las claves como "jueves 2026-09-17" —formato que usan otros consumidores
           y que no se toca allá—, así que se busca la fecha adentro de la clave en vez de asumir una
           posición fija, igual que hace panel_availability_json(). */
        foreach ($por_fecha as $etiqueta => $horarios) {
            if (strpos((string) $etiqueta, (string) $agenda['demo_date']) !== false) {
                return array_values(array_map('strval', (array) $horarios));
            }
        }

        return [];
    }

    /**
     * Lo que va a quedar en las cuatro columnas del turno después de escribir: el valor del payload
     * si vino, y si no el que el lead ya tiene.
     *
     * @param Lead                 $lead         Lead objetivo.
     * @param array<string, mixed> $normalizados Payload ya validado.
     *
     * @return array<string, mixed>
     */
    protected function agenda_resultante(Lead $lead, array $normalizados): array
    {
        $agenda = [];

        foreach ($this->campos_del_turno() as $campo) {
            $agenda[$campo] = array_key_exists($campo, $normalizados)
                ? $normalizados[$campo]
                : $this->actual_de($lead, $campo);
        }

        return $agenda;
    }

    /**
     * Las cuatro columnas que definen el turno de una demo.
     *
     * 🔴 SON LOS TRES DE `LeadRescheduleFlagsService::CAMPOS_DE_AGENDA` MÁS `demo_id`, y las dos
     * listas son distintas a propósito: mover el lead a OTRA instancia con la misma fecha y hora
     * puede colisionar en la instancia nueva (o sea, hay que revalidar la grilla) pero no cambia
     * cuándo es la demo, así que no corresponde resetear ningún recordatorio. Los tres campos que
     * sí disparan el reset los declara el servicio y no este controlador: dos listas del reset se
     * separan, que es justo lo que ese servicio existe para impedir.
     *
     * @return array<int, string>
     */
    protected function campos_del_turno(): array
    {
        return array_merge(LeadRescheduleFlagsService::CAMPOS_DE_AGENDA, ['demo_id']);
    }

    /**
     * Si el cambio toca el turno (fecha, horas o instancia) y hay que revalidar la grilla.
     *
     * @param array<string, mixed> $diff Diff campo por campo.
     *
     * @return bool
     */
    protected function cambia_el_turno(array $diff): bool
    {
        foreach ($this->campos_del_turno() as $campo) {
            if (array_key_exists($campo, $diff)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si el cambio es una REAGENDA, o sea si dispara el reset de flags. La lista la declara el
     * servicio del reset.
     *
     * @param array<string, mixed> $diff Diff campo por campo.
     *
     * @return bool
     */
    protected function cambia_la_agenda(array $diff): bool
    {
        foreach (LeadRescheduleFlagsService::CAMPOS_DE_AGENDA as $campo) {
            if (array_key_exists($campo, $diff)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si el turno resultante ya pasó.
     *
     * ⚠️ El cálculo es el MISMO que hace `LeadFollowupService::process_lead()` —instante de inicio
     * si hay hora, fin del día si no—, y tiene que seguir siéndolo: si acá se calculara distinto, un
     * turno podría pasar este freno y desarmar igual la guarda de allá, que es el bug que este
     * freno tapa.
     *
     * @param array<string, mixed> $agenda Turno resultante.
     *
     * @return bool
     */
    protected function turno_en_el_pasado(array $agenda): bool
    {
        if ($agenda['demo_date'] === null) {
            return false;
        }

        $hora = $agenda['demo_start_time'] !== null && trim((string) $agenda['demo_start_time']) !== ''
            ? (string) $agenda['demo_start_time']
            : '23:59:59';

        $inicio = $this->parsear_o_null((string) $agenda['demo_date'] . ' ' . $hora);

        return $inicio !== null && $inicio->isPast();
    }

    /**
     * El turno resultante en una línea, para los mensajes de error.
     *
     * @param array<string, mixed> $agenda Turno resultante.
     *
     * @return string
     */
    protected function turno_legible(array $agenda): string
    {
        $texto = (string) $agenda['demo_date'];

        if ($agenda['demo_start_time'] !== null && trim((string) $agenda['demo_start_time']) !== '') {
            $texto .= ' ' . $this->hora_hhmm($agenda['demo_start_time']);
        }

        return $texto;
    }

    /**
     * El turno resultante tal cual se publica en la respuesta, más si pasó por la grilla.
     *
     * Se publica siempre —y no sólo cuando cambia— porque quien llama necesita poder afirmar contra
     * QUÉ instancia quedó agendada la demo sin tener que pedir el lead de nuevo.
     *
     * @param array<string, mixed> $agenda           Turno resultante.
     * @param bool                 $valida_la_grilla Si el horario se verificó contra la grilla real.
     *
     * @return array<string, mixed>
     */
    protected function turno_resultante_publicable(array $agenda, bool $valida_la_grilla): array
    {
        return [
            'demo_id'            => $agenda['demo_id'] === null ? null : (int) $agenda['demo_id'],
            'demo_date'          => $agenda['demo_date'],
            'demo_start_time'    => $agenda['demo_start_time'],
            'demo_end_time'      => $agenda['demo_end_time'],
            'validado_en_grilla' => $valida_la_grilla,
        ];
    }

    /**
     * Las advertencias que hay que leer aunque la llamada salga bien.
     *
     * Hoy hay una sola y es la de `notes`, que sólo se emite cuando el lead YA tenía algo escrito:
     * ahí es cuando la escritura destruye trabajo de una persona.
     *
     * @param Lead                 $lead Lead objetivo.
     * @param array<string, mixed> $diff Diff campo por campo.
     *
     * @return array<int, string>
     */
    protected function advertencias_de(Lead $lead, array $diff): array
    {
        $advertencias = [];

        if (array_key_exists('notes', $diff) && trim((string) $lead->notes) !== '') {
            $advertencias[] = self::ADVERTENCIA_DE_NOTES;
        }

        return $advertencias;
    }

    /**
     * Recorta el valor recibido para que quepa en un mensaje de error legible.
     *
     * @param mixed $valor Valor crudo del payload.
     *
     * @return mixed
     */
    protected function recortar_para_el_error($valor)
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = (string) $valor;
        if (mb_strlen($texto) <= self::RECORTE_DEL_VALOR_EN_EL_ERROR) {
            return $valor;
        }

        return mb_substr($texto, 0, self::RECORTE_DEL_VALOR_EN_EL_ERROR) . '… (recortado, llegaron '
            . mb_strlen($texto) . ' caracteres)';
    }

    /**
     * Una hora en 'HH:MM', que es como las publica la grilla.
     *
     * @param mixed $hora Hora 'HH:MM' o 'HH:MM:SS'.
     *
     * @return string
     */
    protected function hora_hhmm($hora): string
    {
        return substr(trim((string) $hora), 0, 5);
    }

    /**
     * Valida y normaliza el valor de UN campo de la lista blanca.
     *
     * Un `null` explícito significa "vaciar el campo" y se acepta en todos los tipos menos en
     * `booleano`, cuya columna es NOT NULL. ⚠️ El middleware global `ConvertEmptyStringsToNull`
     * convierte `""` en `null` antes de llegar acá, así que mandar el string vacío es lo mismo que
     * mandar null: vaciar.
     *
     * @param string $campo Nombre del campo, ya verificado contra la lista blanca.
     * @param mixed  $valor Valor crudo del payload.
     *
     * @return array{ok: bool, valor: mixed, error: string|null}
     */
    protected function normalizar(string $campo, $valor): array
    {
        $spec = self::CAMPOS[$campo];
        $tipo = $spec['tipo'];

        if (is_array($valor)) {
            return ['ok' => false, 'valor' => null, 'error' => 'llegó como lista y tiene que ser un valor simple.'];
        }

        if ($valor === null) {
            if ($tipo === 'booleano') {
                return ['ok' => false, 'valor' => null, 'error' => 'no admite null: es un booleano y la columna es NOT NULL. Mandá true o false.'];
            }

            return ['ok' => true, 'valor' => null, 'error' => null];
        }

        if ($tipo === 'booleano') {
            if (is_bool($valor)) {
                return ['ok' => true, 'valor' => $valor, 'error' => null];
            }

            $texto = strtolower(trim((string) $valor));
            if (! in_array($texto, ['1', '0', 'true', 'false'], true)) {
                return ['ok' => false, 'valor' => null, 'error' => 'tiene que ser booleano (true, false, 1 o 0).'];
            }

            return ['ok' => true, 'valor' => in_array($texto, ['1', 'true'], true), 'error' => null];
        }

        $texto = trim((string) $valor);
        if ($texto === '') {
            return ['ok' => true, 'valor' => null, 'error' => null];
        }

        if (isset($spec['max']) && mb_strlen($texto) > (int) $spec['max']) {
            return ['ok' => false, 'valor' => null, 'error' => 'supera el máximo de ' . (int) $spec['max'] . ' caracteres de la columna.'];
        }

        /* 🔴 EL LARGO DE UN `TEXT` SE MIDE EN BYTES, NO EN CARACTERES, y por eso este chequeo existe
           aparte del de arriba. Medido el 3/9/2026: `notes` con 20.000 emoji pasaba el máximo de
           20.000 CARACTERES, ocupaba 80.000 BYTES contra una columna que aguanta 65.535 y el INSERT
           reventaba con 500 — o sea, el endpoint prometía un 422 legible y devolvía un stack trace. */
        if (isset($spec['max_bytes']) && strlen($texto) > (int) $spec['max_bytes']) {
            return [
                'ok'    => false,
                'valor' => null,
                'error' => 'ocupa ' . strlen($texto) . ' BYTES y la columna aguanta ' . (int) $spec['max_bytes']
                    . '. Ojo: el largo de esa columna se mide en bytes, no en caracteres, y un emoji ocupa cuatro.',
            ];
        }

        if ($tipo === 'email') {
            if (filter_var($texto, FILTER_VALIDATE_EMAIL) === false) {
                return ['ok' => false, 'valor' => null, 'error' => 'no es una dirección de mail válida.'];
            }

            return ['ok' => true, 'valor' => $texto, 'error' => null];
        }

        if ($tipo === 'demo') {
            if (! is_numeric($texto) || (int) $texto <= 0) {
                return ['ok' => false, 'valor' => null, 'error' => 'tiene que ser el id numérico de una instancia de demo del pool.'];
            }

            if (Demo::find((int) $texto) === null) {
                return [
                    'ok'    => false,
                    'valor' => null,
                    'error' => 'no corresponde a ninguna instancia de demo del pool. Las instancias válidas salen de '
                        . 'GET claude/leads/{id}/availability, en la clave `demos`.',
                ];
            }

            return ['ok' => true, 'valor' => (int) $texto, 'error' => null];
        }

        if ($tipo === 'fecha') {
            /* 🔴 LISTA CERRADA DE FORMATOS, y la comparte con `GET claude/query`: ver el docblock de
               ClaudeQueryService::FORMATOS_DE_FECHA. La guarda anterior sólo pedía que el texto
               tuviera un \d{4}-\d{2}-\d{2} en algún lado, y con eso pasaban —medidos el 3/9/2026—
               'tomorrow 2026-09-15' (guardaba el 16), '2026-09-15 +3 months' (guardaba diciembre) y
               'x2026-09-15x' (guardaba el 15). Los tres los acepta Carbon::parse() sin chistar. */
            $fecha = ClaudeQueryService::fecha_estricta($texto);

            /* El ida y vuelta es lo que exige que sea SÓLO fecha: `2026-09-15 16:00` parsea, pero la
               columna es DATE y guardar la hora ahí la tira en silencio. */
            if ($fecha === null || $fecha->format('Y-m-d') !== $texto) {
                return [
                    'ok'    => false,
                    'valor' => null,
                    'error' => 'tiene que ser una fecha absoluta en formato Y-m-d (por ejemplo 2026-09-15), sin hora. '
                        . 'No se aceptan expresiones relativas ("tomorrow 2026-09-15", "2026-09-15 +3 months") ni '
                        . 'texto pegado ("x2026-09-15x"): Carbon::parse() las acepta y guarda una fecha que nadie pidió.',
                ];
            }

            return ['ok' => true, 'valor' => $texto, 'error' => null];
        }

        if ($tipo === 'hora') {
            /* HH:MM o HH:MM:SS. La columna es varchar(32) y el resto del sistema la parsea con
               `Carbon::parse($fecha . ' ' . $hora)`: cualquier otra cosa rompe ahí, lejos de acá. */
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $texto) !== 1) {
                return ['ok' => false, 'valor' => null, 'error' => 'tiene que venir como HH:MM o HH:MM:SS en 24 horas (por ejemplo 18:30).'];
            }

            return ['ok' => true, 'valor' => $texto, 'error' => null];
        }

        if ($tipo === 'fecha_hora') {
            /* 🔴 MISMA LISTA CERRADA QUE `fecha`, y por el mismo motivo. `parsear_o_null()` del trait
               NO valida: `Carbon::parse('x')` devuelve AHORA sin lanzar (está escrito en su propio
               docblock), y la guarda que había —"que el texto contenga un \d{4}-\d{2}-\d{2}"— dejaba
               pasar los tres casos medidos arriba. */
            $fecha = ClaudeQueryService::fecha_estricta($texto);
            if ($fecha === null) {
                return [
                    'ok'    => false,
                    'valor' => null,
                    'error' => 'no es una fecha y hora absoluta válida. Se acepta SÓLO alguno de estos formatos: '
                        . implode(', ', ClaudeQueryService::EJEMPLOS_DE_FECHA) . '. Nada de expresiones relativas '
                        . '("tomorrow 2026-09-15", "2026-09-15 +3 months") ni texto pegado ("x2026-09-15x").',
                ];
            }

            /* 🔴 La columna es `timestamp`, no `datetime`: fuera de 1970-2038 el INSERT explota en el
               driver y sale un 500 con stack trace. Medidos el 3/9/2026: '9999-12-31 23:59' y
               '1000-01-01 00:00'. */
            $instante = $fecha->copy()->setTimezone('UTC');
            if ($instante->lt(Carbon::parse(self::TIMESTAMP_MINIMO_UTC, 'UTC'))
                || $instante->gt(Carbon::parse(self::TIMESTAMP_MAXIMO_UTC, 'UTC'))) {
                return [
                    'ok'    => false,
                    'valor' => null,
                    'error' => 'queda fuera del rango que aguanta la columna, que es un `timestamp` de MySQL: desde '
                        . self::TIMESTAMP_MINIMO_UTC . ' hasta ' . self::TIMESTAMP_MAXIMO_UTC . ' (UTC).',
                ];
            }

            return ['ok' => true, 'valor' => $fecha->format('Y-m-d H:i:s'), 'error' => null];
        }

        return ['ok' => true, 'valor' => $texto, 'error' => null];
    }

    /**
     * El valor que el lead tiene HOY en ese campo, con la MISMA forma que devuelve
     * `normalizar()`, para que el diff se pueda comparar sin adaptadores de por medio.
     *
     * Las dos columnas casteadas (`demo_date` a `date` y `meeting_scheduled_at` a `datetime`) se
     * leen con `getRawOriginal()`: comparar objetos Carbon compararía instancias distintas y daría
     * "cambió" siempre.
     *
     * @param Lead   $lead  Lead objetivo.
     * @param string $campo Campo de la lista blanca.
     *
     * @return mixed
     */
    protected function actual_de(Lead $lead, string $campo)
    {
        $tipo = self::CAMPOS[$campo]['tipo'];

        if ($campo === 'demo_date') {
            $crudo = $lead->getRawOriginal('demo_date');

            return $crudo === null || $crudo === '' ? null : substr((string) $crudo, 0, 10);
        }

        if ($tipo === 'demo') {
            return $lead->demo_id === null || $lead->demo_id === '' ? null : (int) $lead->demo_id;
        }

        if ($tipo === 'fecha_hora') {
            $crudo = $lead->getRawOriginal($campo);

            return $crudo === null || $crudo === '' ? null : (string) $crudo;
        }

        if ($tipo === 'booleano') {
            return (bool) $lead->{$campo};
        }

        $valor = $lead->{$campo};

        return $valor === null || trim((string) $valor) === '' ? null : (string) $valor;
    }

    /**
     * Compara el valor actual con el propuesto sin las sorpresas de `==`.
     *
     * @param mixed $actual    Valor de hoy.
     * @param mixed $propuesto Valor del payload, ya normalizado.
     *
     * @return bool
     */
    protected function son_iguales($actual, $propuesto): bool
    {
        if ($actual === null || $propuesto === null) {
            return $actual === $propuesto;
        }

        if (is_bool($actual) || is_bool($propuesto)) {
            return (bool) $actual === (bool) $propuesto;
        }

        return (string) $actual === (string) $propuesto;
    }
}

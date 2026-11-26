<?php

namespace App\Services\Local;

use App\Models\AdminSetting;
use App\Models\LocalMissionPhase;
use Carbon\Carbon;

/**
 * Snapshot de la PC de Lucas que manda el reporter de Linux (misión el-local-linux, 25/11/2026, contrato A v2):
 * las misiones en curso de todos los orígenes (Claude Code, OpenClaw, Codex, soporte, Dots), el Workboard y las
 * tareas programadas de OpenClaw, y la cola de misiones por recursos.
 *
 * Es el único que sabe cómo queda guardado:
 *   - `local_pc_misiones`  = {reportado_at, items[], omitidas, recortado}
 *   - `local_pc_workboard` = {reportado_at, ok, total, conteo, tarjetas[], recortado}
 *   - `local_pc_openclaw`  = {reportado_at, gateway, tareas{ok, items[]}}
 *   - `local_pc_cola`      = {reportado_at, ok, en_espera[], fallidas, ultimo_chequeo, chequeos[]}
 * en `admin_settings` (un espejo que se reescribe entero en cada reporte, sin migración) y el historial de fases
 * en la tabla `local_mission_phases` (que crece y se consulta por misión y fecha).
 *
 * Lo leen la escena de «El local» ({@see EscenaService}) y, a futuro, un conector de sólo lectura (por eso la
 * lectura vive acá y no adentro de la escena): {@see self::read()} y {@see self::phases_by_mission()}.
 *
 * 🔴 Privacidad: se guarda sólo lo que el contrato define, con una lista blanca de claves por entidad. Un
 * campo desconocido (`pedido`, `notes`, `message`…) no da error pero no se guarda.
 * 🔴 Cada JSON entra en {@see self::MAX_BYTES}: si lo pasa se descartan primero las terminadas / hechas, después
 * las de actividad más vieja, y queda `recortado = true`.
 */
class PcSnapshotService
{
    /** Claves de `admin_settings` donde queda el snapshot. */
    const KEY_MISIONES  = 'local_pc_misiones';
    const KEY_WORKBOARD = 'local_pc_workboard';
    const KEY_OPENCLAW  = 'local_pc_openclaw';
    const KEY_COLA      = 'local_pc_cola';

    /** Techo en bytes de cada JSON guardado (la columna `value` es un TEXT de 64 KB). */
    const MAX_BYTES = 60000;

    /** Orígenes de una misión (contrato A v2). */
    const ORIGENES = ['openclaw', 'claude-code', 'soporte', 'codex', 'dots', 'desconocido'];

    /** Estados de una misión. */
    const ESTADOS_MISION = ['trabajando', 'esperando_lucas', 'trabada', 'terminada'];

    /** Las seis etapas que ve Lucas. */
    const ETAPAS = ['plan', 'construccion', 'revision', 'tests', 'merge', 'cierre'];

    /** Roles de un agente de una misión. */
    const ROLES = ['coordinador', 'arquitecto', 'programador', 'revisor', 'operador', 'explorador', 'otro'];

    /** De qué está hecho el agente. */
    const RUNTIMES = ['claude', 'codex', 'openclaw'];

    /** Estados de un agente. */
    const ESTADOS_AGENTE = ['activo', 'idle'];

    /** Estados del gateway de OpenClaw. */
    const ESTADOS_GATEWAY = ['ok', 'degradado', 'caido', 'desconocido'];

    /** Estados de una tarjeta del Workboard. */
    const ESTADOS_TARJETA = ['triage', 'backlog', 'todo', 'scheduled', 'ready', 'running', 'review', 'blocked', 'done'];

    /** Prioridades de una tarjeta y de una misión en cola. */
    const PRIORIDADES = ['urgent', 'high', 'normal', 'low'];

    /** Tipos de tarea programada. */
    const TIPOS_TAREA = ['modelo', 'comando', 'otro'];

    /** Último resultado de una tarea programada. */
    const RESULTADOS_TAREA = ['ok', 'error', 'omitida', 'otro'];

    /** Motivos por los que una misión espera en la cola. */
    const MOTIVOS_COLA = ['memoria', 'swap', 'cpu', 'cupo', 'pesados'];

    /** Campos numéricos (decimales) de un chequeo de recursos. */
    const CHEQUEO_DECIMALES = [
        'ram_disponible_gib', 'ram_total_gib', 'swap_libre_gib', 'swap_total_gib', 'load1', 'load5',
        'umbral_ram_gib', 'umbral_carga_por_nucleo',
    ];

    /** Campos enteros de un chequeo de recursos. */
    const CHEQUEO_ENTEROS = [
        'nproc', 'activas_total', 'activas_sin_pantalla', 'largo_cola', 'pesados_ocupados', 'pesados_cupos', 'retenidas',
    ];

    /** Zona en que se guardan las fechas del snapshot (la de la oficina). */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /** Regla de una fase fina: minúsculas, números y guiones (libre para que una fase nueva de la skill no rompa). */
    const REGEX_FASE = '/^[a-z0-9-]{1,40}$/';

    /* ------------------------------------------------------------------------------------------
     | Validación
     |----------------------------------------------------------------------------------------- */

    /**
     * Reglas de validación que el contrato A v2 SUMA a las de v1 (todas opcionales).
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        $reglas = [
            'contrato'             => 'nullable|integer|in:1,2',
            'reporter.plataforma'  => 'nullable|string|max:20',

            'misiones'                     => 'nullable|array|max:60',
            'misiones.*'                   => 'array',
            'misiones.*.id'                => 'required|string|max:80|distinct',
            'misiones.*.titulo'            => 'nullable|string|max:160',
            'misiones.*.origen'            => 'required|in:' . implode(',', self::ORIGENES),
            'misiones.*.estado'            => 'required|in:' . implode(',', self::ESTADOS_MISION),
            'misiones.*.etapa'             => 'nullable|in:' . implode(',', self::ETAPAS),
            'misiones.*.fase'              => ['nullable', 'string', 'regex:' . self::REGEX_FASE],
            'misiones.*.fase_inferida'     => 'nullable|boolean',
            'misiones.*.slug'              => 'nullable|string|max:120',
            'misiones.*.tarjeta_id'        => 'nullable|string|max:64',
            'misiones.*.ticket'            => 'nullable|integer|min:1',
            'misiones.*.repos'             => 'nullable|array|max:8',
            'misiones.*.repos.*'           => 'string|max:40',
            'misiones.*.worktrees'         => 'nullable|array|max:8',
            'misiones.*.worktrees.*.repo'  => 'required|string|max:40',
            'misiones.*.worktrees.*.carpeta' => 'required|string|max:120',
            'misiones.*.worktrees.*.rama'  => 'nullable|string|max:120',
            'misiones.*.worktrees.*.sucios' => 'nullable|integer|min:0|max:65535',
            'misiones.*.worktrees.*.ultima_actividad_at' => 'nullable|date',
            'misiones.*.agentes'           => 'nullable|array|max:20',
            'misiones.*.agentes.*.rol'     => 'required|in:' . implode(',', self::ROLES),
            'misiones.*.agentes.*.runtime' => 'required|in:' . implode(',', self::RUNTIMES),
            'misiones.*.agentes.*.estado'  => 'required|in:' . implode(',', self::ESTADOS_AGENTE),
            'misiones.*.agentes.*.desde_at' => 'nullable|date',
            'misiones.*.desde_at'          => 'nullable|date',
            'misiones.*.ultima_actividad_at' => 'nullable|date',
            'misiones.*.fase_desde'        => 'nullable|date',
            'misiones.*.fase_historial'    => 'nullable|array|max:20',
            'misiones.*.fase_historial.*.fase' => ['required', 'string', 'regex:' . self::REGEX_FASE],
            'misiones.*.fase_historial.*.etapa' => 'nullable|in:' . implode(',', self::ETAPAS),
            'misiones.*.fase_historial.*.desde' => 'required|date',
            'misiones.*.fase_historial.*.hasta' => 'nullable|date',
            'misiones.*.fase_historial.*.segundos' => 'nullable|integer|min:0|max:100000000',
            'misiones.*.fase_historial.*.inferida' => 'nullable|boolean',
            'misiones_omitidas'            => 'nullable|integer|min:0|max:100000',

            'openclaw'                     => 'nullable|array',
            'openclaw.gateway'             => 'nullable|array',
            'openclaw.gateway.estado'      => 'required_with:openclaw.gateway|in:' . implode(',', self::ESTADOS_GATEWAY),
            'openclaw.gateway.version'     => 'nullable|string|max:40',
            'openclaw.gateway.verificado_at' => 'nullable|date',
            'openclaw.workboard'           => 'nullable|array',
            'openclaw.workboard.ok'        => 'nullable|boolean',
            'openclaw.workboard.total'     => 'nullable|integer|min:0|max:100000',
            'openclaw.workboard.conteo'    => 'nullable|array',
            'openclaw.workboard.conteo.*'  => 'integer|min:0|max:100000',
            'openclaw.workboard.tarjetas'  => 'nullable|array|max:200',
            'openclaw.workboard.tarjetas.*' => 'array',
            'openclaw.workboard.tarjetas.*.id' => 'required|string|max:64|distinct',
            'openclaw.workboard.tarjetas.*.titulo' => 'required|string|max:160',
            'openclaw.workboard.tarjetas.*.estado' => 'required|in:' . implode(',', self::ESTADOS_TARJETA),
            'openclaw.workboard.tarjetas.*.prioridad' => 'nullable|in:' . implode(',', self::PRIORIDADES),
            'openclaw.workboard.tarjetas.*.etiquetas' => 'nullable|array|max:10',
            'openclaw.workboard.tarjetas.*.etiquetas.*' => 'string|max:40',
            'openclaw.workboard.tarjetas.*.agente' => 'nullable|string|max:40',
            'openclaw.workboard.tarjetas.*.actualizada_at' => 'nullable|date',
            'openclaw.workboard.tarjetas.*.vence_at' => 'nullable|date',
            'openclaw.workboard.tarjetas.*.mision_id' => 'nullable|string|max:80',
            'openclaw.tareas'              => 'nullable|array',
            'openclaw.tareas.ok'           => 'nullable|boolean',
            'openclaw.tareas.items'        => 'nullable|array|max:60',
            'openclaw.tareas.items.*'      => 'array',
            'openclaw.tareas.items.*.id'   => 'required|string|max:64|distinct',
            'openclaw.tareas.items.*.nombre' => 'required|string|max:160',
            'openclaw.tareas.items.*.habilitada' => 'required|boolean',
            'openclaw.tareas.items.*.tipo' => 'required|in:' . implode(',', self::TIPOS_TAREA),
            'openclaw.tareas.items.*.agente' => 'nullable|string|max:40',
            'openclaw.tareas.items.*.condicion' => 'nullable|boolean',
            'openclaw.tareas.items.*.frecuencia' => 'nullable|string|max:80',
            'openclaw.tareas.items.*.expresion' => 'nullable|string|max:80',
            'openclaw.tareas.items.*.ultimo_resultado' => 'nullable|in:' . implode(',', self::RESULTADOS_TAREA),
            'openclaw.tareas.items.*.ultima_corrida_at' => 'nullable|date',
            'openclaw.tareas.items.*.ultima_condicion_at' => 'nullable|date',
            'openclaw.tareas.items.*.proxima_corrida_at' => 'nullable|date',
            'openclaw.tareas.items.*.errores_seguidos' => 'nullable|integer|min:0|max:100000',

            'cola'                         => 'nullable|array',
            'cola.ok'                      => 'nullable|boolean',
            'cola.fallidas'                => 'nullable|integer|min:0|max:100000',
            'cola.en_espera'               => 'nullable|array|max:50',
            'cola.en_espera.*'             => 'array',
            'cola.en_espera.*.slug'        => 'required|string|max:120',
            'cola.en_espera.*.titulo'      => 'nullable|string|max:160',
            'cola.en_espera.*.prioridad'   => 'nullable|in:' . implode(',', self::PRIORIDADES),
            'cola.en_espera.*.origen'      => 'nullable|string|max:20',
            'cola.en_espera.*.encolada_at' => 'nullable|date',
            'cola.en_espera.*.primera_retencion_at' => 'nullable|date',
            'cola.en_espera.*.espera_min'  => 'nullable|numeric|min:0',
            'cola.en_espera.*.motivos'     => 'nullable|array|max:5',
            'cola.en_espera.*.motivos.*'   => 'in:' . implode(',', self::MOTIVOS_COLA),
            'cola.en_espera.*.intentos'    => 'nullable|integer|min:0|max:1000',
            'cola.ultimo_chequeo'          => 'nullable|array',
            'cola.ultimo_chequeo.at'       => 'required_with:cola.ultimo_chequeo|date',
            'cola.ultimo_chequeo.lanzadas' => 'nullable|array|max:20',
            'cola.ultimo_chequeo.lanzadas.*' => 'string|max:120',
            'cola.chequeos'                => 'nullable|array|max:20',
            'cola.chequeos.*'              => 'array',
            'cola.chequeos.*.at'           => 'required|date',
        ];

        /* Los numéricos del chequeo valen igual para el último y para cada uno del historial. */
        foreach (self::CHEQUEO_DECIMALES as $campo) {
            $reglas['cola.ultimo_chequeo.' . $campo] = 'nullable|numeric|min:0|max:100000';
            $reglas['cola.chequeos.*.' . $campo]     = 'nullable|numeric|min:0|max:100000';
        }
        foreach (self::CHEQUEO_ENTEROS as $campo) {
            $reglas['cola.ultimo_chequeo.' . $campo] = 'nullable|integer|min:0|max:100000';
            $reglas['cola.chequeos.*.' . $campo]     = 'nullable|integer|min:0|max:100000';
        }

        return $reglas;
    }

    /* ------------------------------------------------------------------------------------------
     | Guardado
     |----------------------------------------------------------------------------------------- */

    /**
     * Guarda un reporte v2 ya validado: las cuatro claves del snapshot y el historial de fases.
     *
     * Un bloque que el reporte no trae (`openclaw`, `cola`) se BORRA: la escena lo muestra como null en lugar
     * de mostrar el de un reporte anterior. Pensado para correr dentro de la transacción de quien lo llama.
     *
     * @param array<string, mixed> $datos Body validado del reporte.
     * @param Carbon               $ahora Momento de recepción.
     *
     * @return int Cantidad de misiones guardadas (las que quedaron después del recorte).
     */
    public function save(array $datos, Carbon $ahora)
    {
        $reportado_at = $ahora->copy()->setTimezone(self::ZONA)->toIso8601String();

        /* ---- Misiones ---- */
        $misiones = [];
        foreach ($this->lista($datos, 'misiones') as $crudo) {
            if (is_array($crudo)) {
                $misiones[] = $this->normalizar_mision($crudo);
            }
        }

        /* El historial de fases vive en la tabla `local_mission_phases` y la escena lo lee de ahí: el snapshot no
           lo guarda (ahorra espacio y deja entrar más misiones en los 60 KB). Se lo retiene aparte por misión. */
        $historial_por_mision = [];
        foreach ($misiones as $indice => $mision) {
            $historial_por_mision[$mision['id']] = $mision['fase_historial'];
            unset($misiones[$indice]['fase_historial']);
        }

        // Misiones omitidas por el propio reporter (su tope), antes del recorte de acá.
        $omitidas_reporter = $this->entero_o_null(isset($datos['misiones_omitidas']) ? $datos['misiones_omitidas'] : null) ?: 0;

        $doc_misiones = [
            'reportado_at' => $reportado_at,
            'items'        => $misiones,
            'omitidas'     => $omitidas_reporter,
            'recortado'    => false,
        ];
        $doc_misiones = $this->recortar($doc_misiones, 'items', function ($mision) {
            // Se descarta primero la terminada y, entre iguales, la de actividad más vieja.
            return [$mision['estado'] === 'terminada' ? 0 : 1, $this->segundos($mision['ultima_actividad_at'])];
        });
        // Las que se descartaron acá se suman a las omitidas: la escena muestra el total que no entra.
        $doc_misiones['omitidas'] = $omitidas_reporter + (count($misiones) - count($doc_misiones['items']));
        $this->guardar_json(self::KEY_MISIONES, $doc_misiones);

        // Las misiones que quedaron, con su historial, para guardarlo en la tabla.
        $misiones_con_historial = [];
        foreach ($doc_misiones['items'] as $mision) {
            $mision['fase_historial'] = $historial_por_mision[$mision['id']];
            $misiones_con_historial[] = $mision;
        }

        /* ---- OpenClaw: Workboard y tareas programadas ---- */
        $openclaw = isset($datos['openclaw']) && is_array($datos['openclaw']) ? $datos['openclaw'] : null;
        if ($openclaw === null) {
            $this->borrar_clave(self::KEY_OPENCLAW);
            $this->borrar_clave(self::KEY_WORKBOARD);
        } else {
            $this->guardar_json(self::KEY_OPENCLAW, $this->armar_openclaw($openclaw, $reportado_at));

            if (isset($openclaw['workboard']) && is_array($openclaw['workboard'])) {
                $this->guardar_json(self::KEY_WORKBOARD, $this->armar_workboard($openclaw['workboard'], $reportado_at));
            } else {
                $this->borrar_clave(self::KEY_WORKBOARD);
            }
        }

        /* ---- Cola de misiones por recursos ---- */
        if (isset($datos['cola']) && is_array($datos['cola'])) {
            $this->guardar_json(self::KEY_COLA, $this->armar_cola($datos['cola'], $reportado_at));
        } else {
            $this->borrar_clave(self::KEY_COLA);
        }

        $this->save_phase_history($misiones_con_historial, $ahora);

        return count($doc_misiones['items']);
    }

    /**
     * Guarda el historial de fases de las misiones del reporte en `local_mission_phases`.
     *
     * Una sola consulta trae las filas de esas misiones; una fila se identifica por `(mission_key, fase, desde)`
     * (REVISION-2 R13: la vuelta no se deduce de la posición, porque el reporter manda sólo las últimas 20
     * fases): si existe se actualiza ESA fila y si no se inserta con vuelta = máx(vuelta) de la fase + 1. Actualiza sólo si algo cambió. Una fase `inferida` conserva el `desde` más viejo que se vio
     * (la primera vez que se la vio es la mejor estimación, porque el reporter infiere con el `mtime` y eso
     * llega siempre un poco tarde); una no inferida pisa el `desde`. Una misión que deja de venir conserva sus
     * filas: eso es lo «consultable después».
     *
     * @param array<int, array<string, mixed>> $misiones Misiones ya normalizadas.
     * @param Carbon                           $ahora    Momento de recepción.
     *
     * @return void
     */
    private function save_phase_history(array $misiones, Carbon $ahora)
    {
        $claves = [];
        foreach ($misiones as $mision) {
            if (count($mision['fase_historial']) > 0) {
                $claves[] = $mision['id'];
            }
        }
        if (count($claves) === 0) {
            return;
        }

        // Filas ya guardadas, por id; y su índice misión|fase|desde (la identidad de una fila, REVISION-2 R13).
        $guardadas   = [];
        $por_desde   = [];
        $max_vuelta  = [];
        foreach (LocalMissionPhase::query()->whereIn('mission_key', $claves)->get() as $fila) {
            $guardadas[$fila->id] = $fila;
            $por_desde[$fila->mission_key . '|' . $fila->fase . '|' . (string) $fila->getRawOriginal('desde')] = $fila->id;
            $clave_vuelta = $fila->mission_key . '|' . $fila->fase;
            $max_vuelta[$clave_vuelta] = max(isset($max_vuelta[$clave_vuelta]) ? $max_vuelta[$clave_vuelta] : 0, (int) $fila->vuelta);
        }

        $ahora_db  = $this->formato_db($ahora);
        $nuevas    = [];

        // Por misión, lo que trajo este reporte: ids de filas guardadas que corresponden, `desde` de cada fase y
        // si alguna era inferida.
        $reportado = [];

        foreach ($misiones as $mision) {
            $reportado[$mision['id']] = ['ids' => [], 'desdes' => [], 'hay_inferida' => false];

            // Entradas con sus valores ya en formato de base.
            $entradas = [];
            foreach ($mision['fase_historial'] as $entrada) {
                $desde    = $this->formato_db(Carbon::parse($entrada['desde']));
                $hasta    = $entrada['hasta'] === null ? null : $this->formato_db(Carbon::parse($entrada['hasta']));
                $segundos = $entrada['segundos'];
                if ($hasta !== null) {
                    // Una fase cerrada nunca termina antes de empezar (REVISION-2 R15).
                    if ($hasta < $desde) {
                        $hasta    = $desde;
                        $segundos = 0;
                    } elseif ($segundos === null) {
                        $segundos = max(0, Carbon::parse($hasta)->getTimestamp() - Carbon::parse($desde)->getTimestamp());
                    }
                }
                $entradas[] = [
                    'fase' => $entrada['fase'], 'etapa' => $entrada['etapa'], 'desde' => $desde, 'hasta' => $hasta,
                    'segundos' => $segundos, 'inferida' => $entrada['inferida'] ? 1 : 0, 'fila_id' => null,
                ];
                $reportado[$mision['id']]['desdes'][] = $desde;
                if ($entrada['inferida']) {
                    $reportado[$mision['id']]['hay_inferida'] = true;
                }
            }

            // Pasada 1: coincidencia exacta por (misión, fase, desde).
            foreach ($entradas as $i => $e) {
                $clave = $mision['id'] . '|' . $e['fase'] . '|' . $e['desde'];
                if (isset($por_desde[$clave]) && ! isset($reportado[$mision['id']]['ids'][$por_desde[$clave]])) {
                    $entradas[$i]['fila_id'] = $por_desde[$clave];
                    $reportado[$mision['id']]['ids'][$por_desde[$clave]] = true;
                }
            }

            // Pasada 2: sin coincidencia exacta, la entrada retoma la fila abierta e inferida de esa fase (el
            // reporter infiere con el mtime y su `desde` se corre un poco); si no hay, es una aparición nueva.
            foreach ($entradas as $i => $e) {
                if ($e['fila_id'] === null) {
                    foreach ($guardadas as $id => $fila) {
                        if ($fila->mission_key === $mision['id'] && $fila->fase === $e['fase'] && (int) $fila->inferida === 1
                            && $fila->getRawOriginal('hasta') === null && ! isset($reportado[$mision['id']]['ids'][$id])) {
                            $entradas[$i]['fila_id'] = $id;
                            $reportado[$mision['id']]['ids'][$id] = true;
                            break;
                        }
                    }
                }
            }

            foreach ($entradas as $e) {
                if ($e['fila_id'] === null) {
                    // Aparición nueva: vuelta = máximo guardado de esa fase + 1.
                    $clave_vuelta = $mision['id'] . '|' . $e['fase'];
                    $max_vuelta[$clave_vuelta] = (isset($max_vuelta[$clave_vuelta]) ? $max_vuelta[$clave_vuelta] : 0) + 1;
                    $nuevas[] = [
                        'mission_key'  => $mision['id'],
                        'origen'       => $mision['origen'],
                        'titulo'       => $mision['titulo'],
                        'fase'         => $e['fase'],
                        'etapa'        => $e['etapa'],
                        'vuelta'       => min(255, $max_vuelta[$clave_vuelta]),
                        'desde'        => $e['desde'],
                        'hasta'        => $e['hasta'],
                        'segundos'     => $e['segundos'],
                        'inferida'     => $e['inferida'],
                        'reportado_at' => $ahora_db,
                        'created_at'   => $ahora_db,
                        'updated_at'   => $ahora_db,
                    ];
                    continue;
                }

                $fila  = $guardadas[$e['fila_id']];
                $desde = $e['desde'];

                // Inferida: se queda con el desde más viejo entre lo guardado y lo reportado.
                $guardado_desde = $fila->getRawOriginal('desde');
                if ($e['inferida'] === 1 && $guardado_desde !== null && (string) $guardado_desde < $desde) {
                    $desde = (string) $guardado_desde;
                }
                $hasta    = $e['hasta'];
                $segundos = $e['segundos'];
                if ($hasta !== null && $hasta < $desde) {
                    $hasta    = $desde;
                    $segundos = 0;
                }

                $cambios = [
                    'origen'   => $mision['origen'],
                    'titulo'   => $mision['titulo'],
                    'etapa'    => $e['etapa'],
                    'desde'    => $desde,
                    'hasta'    => $hasta,
                    'segundos' => $segundos,
                    'inferida' => $e['inferida'],
                ];

                $distinto = false;
                foreach ($cambios as $campo => $valor) {
                    if ((string) $fila->getRawOriginal($campo) !== (string) $valor) {
                        $distinto = true;
                        break;
                    }
                }

                if ($distinto) {
                    $cambios['reportado_at'] = $ahora_db;
                    $cambios['updated_at']   = $ahora_db;
                    LocalMissionPhase::query()->where('id', $fila->id)->update($cambios);
                }
            }
        }

        $this->resolve_stale_open_phases($guardadas, $reportado, $ahora, $ahora_db);

        if (count($nuevas) > 0) {
            LocalMissionPhase::query()->insert($nuevas);
        }

        $this->ensure_single_open_phase(array_keys($reportado), $ahora_db);
    }

    /**
     * Resuelve las filas abiertas (`hasta` null) de una misión que el reporte actual ya no trae.
     *
     * Sin esto una fase inferida que el reporter renombra (contexto -> plan con el mismo `desde`) o que pasa a
     * venir del registro quedaba abierta para siempre (REVISION-1 R4). Por cada fila guardada abierta que no
     * vino:
     * - si otra fase reportada tiene el mismo `desde` (renombre inferido), se borra;
     * - si el historial reportado ya no es inferido y la fila sí lo era (pasó de inferido a registro), se borra;
     * - en otro caso se cierra con `hasta` = `desde` de la siguiente fase reportada (o la hora del reporte) y los
     *   `segundos` calculados.
     *
     * @param array<int, \App\Models\LocalMissionPhase>    $guardadas Filas guardadas, por id.
     * @param array<string, array<string, mixed>>           $reportado Lo que trajo el reporte, por misión.
     * @param Carbon                                        $ahora     Momento de recepción.
     * @param string                                        $ahora_db  El mismo momento en formato de base.
     *
     * @return void
     */
    private function resolve_stale_open_phases(array $guardadas, array $reportado, Carbon $ahora, $ahora_db)
    {
        // Ids de las filas a borrar.
        $a_borrar = [];

        foreach ($guardadas as $clave => $fila) {
            $mision_key = $fila->mission_key;
            if (! isset($reportado[$mision_key]) || isset($reportado[$mision_key]['ids'][$fila->id])) {
                continue;
            }
            $abierta = $fila->getRawOriginal('hasta') === null;
            // Una cerrada sólo interesa si es inferida (renombre inferido, REVISION-2 R15).
            if (! $abierta && (int) $fila->inferida !== 1) {
                continue;
            }

            $desde_fila = (string) $fila->getRawOriginal('desde');
            $trajo      = $reportado[$mision_key];

            // Renombre inferido: otra fase reportada empieza en el mismo instante.
            if (in_array($desde_fila, $trajo['desdes'], true)) {
                $a_borrar[] = $fila->id;
                continue;
            }
            if (! $abierta) {
                continue;
            }

            // De inferido a registro: la fila era una estimación y el reporte ya no lo es.
            if ((int) $fila->inferida === 1 && ! $trajo['hay_inferida']) {
                $a_borrar[] = $fila->id;
                continue;
            }

            // Se cierra donde empieza la siguiente fase reportada; sin ninguna posterior, con la hora del reporte.
            $hasta = $ahora_db;
            foreach ($trajo['desdes'] as $desde_reportado) {
                if ($desde_reportado > $desde_fila && $desde_reportado < $hasta) {
                    $hasta = $desde_reportado;
                }
            }
            // `hasta` nunca queda antes de `desde` (REVISION-2 R15).
            if ($hasta < $desde_fila) {
                $hasta = $desde_fila;
            }
            $segundos = max(0, Carbon::parse($hasta)->getTimestamp() - Carbon::parse($desde_fila)->getTimestamp());

            LocalMissionPhase::query()->where('id', $fila->id)->update([
                'hasta'        => $hasta,
                'segundos'     => $segundos,
                'reportado_at' => $ahora_db,
                'updated_at'   => $ahora_db,
            ]);
        }

        if (count($a_borrar) > 0) {
            LocalMissionPhase::query()->whereIn('id', $a_borrar)->delete();
        }
    }

    /**
     * Garantiza a lo sumo una fase abierta por misión: si después de guardar quedan varias, todas menos la de
     * `desde` más nuevo se cierran en el `desde` de la siguiente abierta (REVISION-1 R4, red de seguridad).
     *
     * @param array<int, string> $mission_keys Misiones del reporte.
     * @param string             $ahora_db     Momento de recepción en formato de base.
     *
     * @return void
     */
    private function ensure_single_open_phase(array $mission_keys, $ahora_db)
    {
        $abiertas = LocalMissionPhase::query()
            ->whereIn('mission_key', $mission_keys)
            ->whereNull('hasta')
            ->orderBy('mission_key')->orderBy('desde')->orderBy('id')
            ->get();

        // Filas abiertas agrupadas por misión, de la más vieja a la más nueva.
        $por_mision = [];
        foreach ($abiertas as $fila) {
            $por_mision[$fila->mission_key][] = $fila;
        }

        foreach ($por_mision as $filas) {
            for ($i = 0; $i < count($filas) - 1; $i++) {
                $desde_fila = (string) $filas[$i]->getRawOriginal('desde');
                $hasta      = (string) $filas[$i + 1]->getRawOriginal('desde');
                LocalMissionPhase::query()->where('id', $filas[$i]->id)->update([
                    'hasta'        => $hasta,
                    'segundos'     => max(0, Carbon::parse($hasta)->getTimestamp() - Carbon::parse($desde_fila)->getTimestamp()),
                    'reportado_at' => $ahora_db,
                    'updated_at'   => $ahora_db,
                ]);
            }
        }
    }

    /* ------------------------------------------------------------------------------------------
     | Lectura (escena y conector futuro)
     |----------------------------------------------------------------------------------------- */

    /**
     * Lo último que reportó la PC, decodificado. Cada bloque es null si no hay guardado.
     *
     * @return array{misiones: array|null, workboard: array|null, openclaw: array|null, cola: array|null}
     */
    public function read()
    {
        return [
            'misiones'  => $this->leer_json(self::KEY_MISIONES),
            'workboard' => $this->leer_json(self::KEY_WORKBOARD),
            'openclaw'  => $this->leer_json(self::KEY_OPENCLAW),
            'cola'      => $this->leer_json(self::KEY_COLA),
        ];
    }

    /**
     * Historial de fases guardado de varias misiones, de la más vieja a la más nueva.
     *
     * Las fechas salen como vienen de la base ('Y-m-d H:i:s', hora del admin).
     *
     * @param array<int, string> $claves Ids de misión (`cc:…`).
     *
     * @return array<string, array<int, array<string, mixed>>> `mission_key => [{fase, etapa, desde, hasta, segundos, inferida}]`
     */
    public function phases_by_mission(array $claves)
    {
        $resultado = [];
        if (count($claves) === 0) {
            return $resultado;
        }

        $filas = LocalMissionPhase::query()
            ->whereIn('mission_key', $claves)
            ->orderBy('desde')
            ->orderBy('id')
            ->get();

        foreach ($filas as $fila) {
            $resultado[$fila->mission_key][] = [
                'fase'     => (string) $fila->fase,
                'etapa'    => $fila->etapa,
                'desde'    => $fila->getRawOriginal('desde'),
                'hasta'    => $fila->getRawOriginal('hasta'),
                'segundos' => $fila->segundos === null ? null : (int) $fila->segundos,
                'inferida' => (bool) $fila->inferida,
            ];
        }

        return $resultado;
    }

    /* ------------------------------------------------------------------------------------------
     | Normalización (lista blanca de claves por entidad)
     |----------------------------------------------------------------------------------------- */

    /**
     * Una misión con sólo las claves del contrato y todas presentes (null / [] si no vinieron).
     *
     * @param array<string, mixed> $m Misión validada.
     *
     * @return array<string, mixed>
     */
    private function normalizar_mision(array $m)
    {
        $worktrees = [];
        foreach ($this->lista($m, 'worktrees') as $w) {
            if (! is_array($w)) {
                continue;
            }
            $worktrees[] = [
                'repo'                => (string) $w['repo'],
                'carpeta'             => (string) $w['carpeta'],
                'rama'                => $this->texto_o_null(isset($w['rama']) ? $w['rama'] : null),
                'sucios'              => $this->entero_o_null(isset($w['sucios']) ? $w['sucios'] : null) ?: 0,
                'ultima_actividad_at' => $this->iso_o_null(isset($w['ultima_actividad_at']) ? $w['ultima_actividad_at'] : null),
            ];
        }

        $agentes = [];
        foreach ($this->lista($m, 'agentes') as $a) {
            if (! is_array($a)) {
                continue;
            }
            $agentes[] = [
                'rol'      => (string) $a['rol'],
                'runtime'  => (string) $a['runtime'],
                'estado'   => (string) $a['estado'],
                'desde_at' => $this->iso_o_null(isset($a['desde_at']) ? $a['desde_at'] : null),
            ];
        }

        $repos = [];
        foreach ($this->lista($m, 'repos') as $repo) {
            if (is_string($repo) && $repo !== '') {
                $repos[] = $repo;
            }
        }

        $historial = [];
        foreach ($this->lista($m, 'fase_historial') as $h) {
            if (! is_array($h)) {
                continue;
            }
            $historial[] = [
                'fase'     => (string) $h['fase'],
                'etapa'    => $this->texto_o_null(isset($h['etapa']) ? $h['etapa'] : null),
                'desde'    => $this->iso_o_null($h['desde']),
                'hasta'    => $this->iso_o_null(isset($h['hasta']) ? $h['hasta'] : null),
                'segundos' => $this->entero_o_null(isset($h['segundos']) ? $h['segundos'] : null),
                'inferida' => ! empty($h['inferida']),
            ];
        }

        return [
            'id'                  => (string) $m['id'],
            'titulo'              => $this->recortar_texto(isset($m['titulo']) ? $m['titulo'] : null, 120),
            'origen'              => (string) $m['origen'],
            'estado'              => (string) $m['estado'],
            'etapa'               => $this->texto_o_null(isset($m['etapa']) ? $m['etapa'] : null),
            'fase'                => $this->texto_o_null(isset($m['fase']) ? $m['fase'] : null),
            'fase_inferida'       => ! empty($m['fase_inferida']),
            'slug'                => $this->texto_o_null(isset($m['slug']) ? $m['slug'] : null),
            'tarjeta_id'          => $this->texto_o_null(isset($m['tarjeta_id']) ? $m['tarjeta_id'] : null),
            'ticket'              => $this->entero_o_null(isset($m['ticket']) ? $m['ticket'] : null),
            'repos'               => $repos,
            'worktrees'           => $worktrees,
            'agentes'             => $agentes,
            'fase_desde'          => $this->iso_o_null(isset($m['fase_desde']) ? $m['fase_desde'] : null),
            'fase_historial'      => $historial,
            'desde_at'            => $this->iso_o_null(isset($m['desde_at']) ? $m['desde_at'] : null),
            'ultima_actividad_at' => $this->iso_o_null(isset($m['ultima_actividad_at']) ? $m['ultima_actividad_at'] : null),
        ];
    }

    /**
     * Documento de `local_pc_workboard` (con el recorte a 60 KB: primero las hechas, después las más viejas).
     *
     * @param array<string, mixed> $wb           Bloque `openclaw.workboard` validado.
     * @param string               $reportado_at Momento de recepción (ISO).
     *
     * @return array<string, mixed>
     */
    private function armar_workboard(array $wb, $reportado_at)
    {
        $tarjetas = [];
        foreach ($this->lista($wb, 'tarjetas') as $t) {
            if (! is_array($t)) {
                continue;
            }
            $etiquetas = [];
            foreach ($this->lista($t, 'etiquetas') as $etiqueta) {
                if (is_string($etiqueta) && $etiqueta !== '') {
                    $etiquetas[] = $etiqueta;
                }
            }
            $tarjetas[] = [
                'id'             => (string) $t['id'],
                'titulo'         => $this->recortar_texto(isset($t['titulo']) ? $t['titulo'] : null, 160),
                'estado'         => (string) $t['estado'],
                'prioridad'      => $this->texto_o_null(isset($t['prioridad']) ? $t['prioridad'] : null),
                'etiquetas'      => $etiquetas,
                'agente'         => $this->texto_o_null(isset($t['agente']) ? $t['agente'] : null),
                'actualizada_at' => $this->iso_o_null(isset($t['actualizada_at']) ? $t['actualizada_at'] : null),
                'vence_at'       => $this->iso_o_null(isset($t['vence_at']) ? $t['vence_at'] : null),
                'mision_id'      => $this->texto_o_null(isset($t['mision_id']) ? $t['mision_id'] : null),
            ];
        }

        // El conteo real (de TODAS las tarjetas, aunque se recorten) viaja tal cual, sólo con estados válidos.
        $conteo = [];
        foreach ($this->lista($wb, 'conteo') as $estado => $cantidad) {
            if (in_array($estado, self::ESTADOS_TARJETA, true) && is_numeric($cantidad)) {
                $conteo[$estado] = (int) $cantidad;
            }
        }

        $doc = [
            'reportado_at' => $reportado_at,
            'ok'           => ! array_key_exists('ok', $wb) || $wb['ok'] === null ? true : (bool) $wb['ok'],
            'total'        => isset($wb['total']) && $wb['total'] !== null ? (int) $wb['total'] : count($tarjetas),
            'conteo'       => $conteo,
            'tarjetas'     => $tarjetas,
            'recortado'    => false,
        ];

        return $this->recortar($doc, 'tarjetas', function ($tarjeta) {
            return [$tarjeta['estado'] === 'done' ? 0 : 1, $this->segundos($tarjeta['actualizada_at'])];
        });
    }

    /**
     * Documento de `local_pc_openclaw`: gateway y tareas programadas.
     *
     * @param array<string, mixed> $oc           Bloque `openclaw` validado.
     * @param string               $reportado_at Momento de recepción (ISO).
     *
     * @return array<string, mixed>
     */
    private function armar_openclaw(array $oc, $reportado_at)
    {
        $gateway = null;
        if (isset($oc['gateway']) && is_array($oc['gateway']) && isset($oc['gateway']['estado'])) {
            $gateway = [
                'estado'       => (string) $oc['gateway']['estado'],
                'version'      => $this->texto_o_null(isset($oc['gateway']['version']) ? $oc['gateway']['version'] : null),
                'verificado_at' => $this->iso_o_null(isset($oc['gateway']['verificado_at']) ? $oc['gateway']['verificado_at'] : null),
            ];
        }

        $tareas = ['ok' => false, 'items' => []];
        if (isset($oc['tareas']) && is_array($oc['tareas'])) {
            $items = [];
            foreach ($this->lista($oc['tareas'], 'items') as $t) {
                if (! is_array($t)) {
                    continue;
                }
                $items[] = [
                    'id'                  => (string) $t['id'],
                    'nombre'              => $this->recortar_texto(isset($t['nombre']) ? $t['nombre'] : null, 160),
                    'habilitada'          => (bool) $t['habilitada'],
                    'tipo'                => (string) $t['tipo'],
                    'agente'              => $this->texto_o_null(isset($t['agente']) ? $t['agente'] : null),
                    'condicion'           => ! empty($t['condicion']),
                    'frecuencia'          => $this->texto_o_null(isset($t['frecuencia']) ? $t['frecuencia'] : null),
                    'expresion'           => $this->texto_o_null(isset($t['expresion']) ? $t['expresion'] : null),
                    'ultimo_resultado'    => $this->texto_o_null(isset($t['ultimo_resultado']) ? $t['ultimo_resultado'] : null),
                    'ultima_corrida_at'   => $this->iso_o_null(isset($t['ultima_corrida_at']) ? $t['ultima_corrida_at'] : null),
                    'ultima_condicion_at' => $this->iso_o_null(isset($t['ultima_condicion_at']) ? $t['ultima_condicion_at'] : null),
                    'proxima_corrida_at'  => $this->iso_o_null(isset($t['proxima_corrida_at']) ? $t['proxima_corrida_at'] : null),
                    'errores_seguidos'    => $this->entero_o_null(isset($t['errores_seguidos']) ? $t['errores_seguidos'] : null) ?: 0,
                ];
            }
            $tareas = [
                'ok'    => ! array_key_exists('ok', $oc['tareas']) || $oc['tareas']['ok'] === null ? true : (bool) $oc['tareas']['ok'],
                'items' => $items,
            ];
        }

        $doc = ['reportado_at' => $reportado_at, 'gateway' => $gateway, 'tareas' => $tareas, 'recortado' => false];

        // Las tareas son pocas (≤ 60), pero el techo vale igual: se descartan las deshabilitadas primero.
        $doc['tareas'] = $this->recortar_tareas($doc);
        unset($doc['recortado']);

        return $doc;
    }

    /**
     * Recorta las tareas del documento de OpenClaw si el JSON pasa el techo (primero las deshabilitadas).
     *
     * @param array<string, mixed> $doc Documento con `tareas.items`.
     *
     * @return array<string, mixed> El bloque `tareas` ya recortado.
     */
    private function recortar_tareas(array $doc)
    {
        $tareas = $doc['tareas'];
        while (strlen($this->codificar($doc)) > self::MAX_BYTES && count($tareas['items']) > 0) {
            $baja = 0;
            foreach ($tareas['items'] as $i => $item) {
                if (! $item['habilitada']) {
                    $baja = $i;
                    break;
                }
                $baja = count($tareas['items']) - 1;
            }
            array_splice($tareas['items'], $baja, 1);
            $doc['tareas'] = $tareas;
        }

        return $tareas;
    }

    /**
     * Documento de `local_pc_cola`, recortado a {@see self::MAX_BYTES} (`recortado` true si se descartó algo).
     *
     * @param array<string, mixed> $cola         Bloque `cola` validado.
     * @param string               $reportado_at Momento de recepción (ISO).
     *
     * @return array<string, mixed>
     */
    private function armar_cola(array $cola, $reportado_at)
    {
        $en_espera = [];
        foreach ($this->lista($cola, 'en_espera') as $e) {
            if (! is_array($e)) {
                continue;
            }
            $motivos = [];
            foreach ($this->lista($e, 'motivos') as $motivo) {
                if (in_array($motivo, self::MOTIVOS_COLA, true)) {
                    $motivos[] = $motivo;
                }
            }
            $en_espera[] = [
                'slug'                  => (string) $e['slug'],
                'titulo'                => $this->recortar_texto(isset($e['titulo']) ? $e['titulo'] : null, 120),
                'prioridad'             => $this->texto_o_null(isset($e['prioridad']) ? $e['prioridad'] : null),
                'origen'                => $this->texto_o_null(isset($e['origen']) ? $e['origen'] : null),
                'encolada_at'           => $this->iso_o_null(isset($e['encolada_at']) ? $e['encolada_at'] : null),
                'espera_min'            => isset($e['espera_min']) && is_numeric($e['espera_min']) ? $e['espera_min'] + 0 : null,
                'motivos'               => $motivos,
                'intentos'              => $this->entero_o_null(isset($e['intentos']) ? $e['intentos'] : null) ?: 0,
                'primera_retencion_at'  => $this->iso_o_null(isset($e['primera_retencion_at']) ? $e['primera_retencion_at'] : null),
            ];
        }

        $ultimo = isset($cola['ultimo_chequeo']) && is_array($cola['ultimo_chequeo']) && count($cola['ultimo_chequeo']) > 0
            ? $this->normalizar_chequeo($cola['ultimo_chequeo'], true)
            : null;

        $chequeos = [];
        foreach ($this->lista($cola, 'chequeos') as $c) {
            if (is_array($c)) {
                $chequeos[] = $this->normalizar_chequeo($c, false);
            }
        }

        $doc = [
            'reportado_at'   => $reportado_at,
            'ok'             => ! array_key_exists('ok', $cola) || $cola['ok'] === null ? true : (bool) $cola['ok'],
            'en_espera'      => $en_espera,
            'fallidas'       => $this->entero_o_null(isset($cola['fallidas']) ? $cola['fallidas'] : null) ?: 0,
            'ultimo_chequeo' => $ultimo,
            'chequeos'       => $chequeos,
            'recortado'      => false,
        ];

        /* Mismo techo de 60 KB que los demás bloques. Primero se descartan los chequeos más viejos del historial
           y, si aun así no entra, las esperas encoladas más recientemente (las más antiguas son las que más
           importan en una cola). */
        $doc = $this->recortar($doc, 'chequeos', function ($chequeo) {
            return [1, $this->segundos($chequeo['at'])];
        });
        $doc = $this->recortar($doc, 'en_espera', function ($espera) {
            return [1, -$this->segundos($espera['encolada_at'])];
        });

        return $doc;
    }

    /**
     * Un chequeo de recursos de la cola. El último trae todos los campos; los del historial sólo los que vinieron.
     *
     * @param array<string, mixed> $c    Chequeo validado.
     * @param bool                 $todo Si es el último chequeo (con los 16 campos y `lanzadas`).
     *
     * @return array<string, mixed>
     */
    private function normalizar_chequeo(array $c, $todo)
    {
        $salida = ['at' => $this->iso_o_null($c['at'])];

        foreach (self::CHEQUEO_DECIMALES as $campo) {
            if ($todo || (isset($c[$campo]) && $c[$campo] !== null)) {
                $salida[$campo] = isset($c[$campo]) && is_numeric($c[$campo]) ? $c[$campo] + 0 : null;
            }
        }
        foreach (self::CHEQUEO_ENTEROS as $campo) {
            if ($todo || (isset($c[$campo]) && $c[$campo] !== null)) {
                $salida[$campo] = $this->entero_o_null(isset($c[$campo]) ? $c[$campo] : null);
            }
        }
        if ($todo) {
            $lanzadas = [];
            foreach ($this->lista($c, 'lanzadas') as $slug) {
                if (is_string($slug) && $slug !== '') {
                    $lanzadas[] = $slug;
                }
            }
            $salida['lanzadas'] = $lanzadas;
        }

        return $salida;
    }

    /* ------------------------------------------------------------------------------------------
     | Utilidades
     |----------------------------------------------------------------------------------------- */

    /**
     * Recorta la lista `$clave` del documento hasta que el JSON entre en {@see self::MAX_BYTES}.
     *
     * Se descarta primero lo de menor prioridad; lo que queda conserva el orden original. Si se descartó algo,
     * el documento queda con `recortado = true`.
     *
     * @param array<string, mixed> $doc       Documento con una lista en `$clave` y `recortado`.
     * @param string               $clave     Nombre de la lista (`items` o `tarjetas`).
     * @param callable             $prioridad Recibe un elemento y devuelve `[rango, segundos]`: se descarta
     *                                        primero el de rango más bajo y, a igual rango, el más viejo.
     *
     * @return array<string, mixed>
     */
    private function recortar(array $doc, $clave, callable $prioridad)
    {
        if (strlen($this->codificar($doc)) <= self::MAX_BYTES) {
            return $doc;
        }

        // Índices del más descartable al menos descartable (el índice original desempata).
        $orden = array_keys($doc[$clave]);
        usort($orden, function ($a, $b) use ($doc, $clave, $prioridad) {
            $pa = $prioridad($doc[$clave][$a]);
            $pb = $prioridad($doc[$clave][$b]);
            if ($pa[0] !== $pb[0]) {
                return $pa[0] - $pb[0];
            }
            if ($pa[1] !== $pb[1]) {
                return $pa[1] < $pb[1] ? -1 : 1;
            }

            return $b - $a;
        });

        $doc['recortado'] = true;
        $descartados      = [];
        foreach ($orden as $indice) {
            $descartados[$indice] = true;
            $restantes = [];
            foreach ($doc[$clave] as $i => $elemento) {
                if (! isset($descartados[$i])) {
                    $restantes[] = $elemento;
                }
            }
            $candidato         = $doc;
            $candidato[$clave] = $restantes;

            if (strlen($this->codificar($candidato)) <= self::MAX_BYTES) {
                return $candidato;
            }
        }

        $doc[$clave] = [];

        return $doc;
    }

    /**
     * Guarda un documento como JSON en `admin_settings`.
     *
     * @param string               $clave
     * @param array<string, mixed> $doc
     *
     * @return void
     */
    private function guardar_json($clave, array $doc)
    {
        AdminSetting::set($clave, $this->codificar($doc));
    }

    /**
     * Borra una clave de `admin_settings` (por Eloquent para que se invalide el memo).
     *
     * @param string $clave
     *
     * @return void
     */
    private function borrar_clave($clave)
    {
        AdminSetting::query()->where('key', $clave)->get()->each(function ($fila) {
            $fila->delete();
        });
    }

    /**
     * Lee una clave de `admin_settings` y la decodifica. Null si no existe o no es JSON de un objeto.
     *
     * @param string $clave
     *
     * @return array<string, mixed>|null
     */
    private function leer_json($clave)
    {
        $crudo = AdminSetting::get($clave);
        if ($crudo === null || $crudo === '') {
            return null;
        }

        $datos = json_decode((string) $crudo, true);

        return is_array($datos) ? $datos : null;
    }

    /**
     * JSON compacto: sin escapar acentos ni barras (ahorra bytes y se lee en la base).
     *
     * @param array<string, mixed> $doc
     *
     * @return string
     */
    private function codificar(array $doc)
    {
        return (string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * El valor `$clave` del arreglo si es una lista / mapa; si no, [].
     *
     * @param array<string, mixed> $datos
     * @param string               $clave
     *
     * @return array<int|string, mixed>
     */
    private function lista(array $datos, $clave)
    {
        return isset($datos[$clave]) && is_array($datos[$clave]) ? $datos[$clave] : [];
    }

    /**
     * Texto sin espacios sobrantes, o null si quedó vacío.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function texto_o_null($valor)
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Texto recortado a N letras (con «…» si se cortó), o null.
     *
     * @param mixed $valor
     * @param int   $letras
     *
     * @return string|null
     */
    private function recortar_texto($valor, $letras)
    {
        $texto = $this->texto_o_null($valor);
        if ($texto === null || mb_strlen($texto) <= $letras) {
            return $texto;
        }

        return rtrim(mb_substr($texto, 0, $letras - 1)) . '…';
    }

    /**
     * Entero o null.
     *
     * @param mixed $valor
     *
     * @return int|null
     */
    private function entero_o_null($valor)
    {
        if ($valor === null || $valor === '' || is_array($valor) || ! is_numeric($valor)) {
            return null;
        }

        return (int) $valor;
    }

    /**
     * Fecha ISO-8601 en la zona de la oficina, o null si no se puede leer.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function iso_o_null($valor)
    {
        if ($valor === null || $valor === '' || is_array($valor)) {
            return null;
        }

        try {
            return Carbon::parse((string) $valor)->setTimezone(self::ZONA)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Segundos de una fecha ISO (0 si no hay), para ordenar.
     *
     * @param mixed $iso
     *
     * @return int
     */
    private function segundos($iso)
    {
        if ($iso === null || $iso === '') {
            return 0;
        }

        try {
            return Carbon::parse((string) $iso)->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Fecha en el formato y la zona de las columnas datetime de la base.
     *
     * @param Carbon $fecha
     *
     * @return string
     */
    private function formato_db(Carbon $fecha)
    {
        return $fecha->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}

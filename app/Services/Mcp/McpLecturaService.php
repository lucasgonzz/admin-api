<?php

namespace App\Services\Mcp;

use App\Models\Admin;
use App\Services\Agenda\AgendaService;
use App\Services\Local\EscenaService;
use App\Services\Local\PcSnapshotService;
use Illuminate\Support\Facades\Cache;

/**
 * Las ocho herramientas de LECTURA del conector MCP (misión dots-openclaw, 25/11/2026).
 *
 * 🔴 Sólo lee. Ninguna herramienta escribe, crea, cambia ni borra nada: las únicas fuentes son
 *  - la escena de «El local» (`EscenaService::armar()['oficina']`: misiones con sus demoras, cola, Workboard y
 *    tareas de OpenClaw), o sea exactamente lo que ya ve Lucas en el admin, y
 *  - la Agenda del admin dueño del token (`AgendaService::listar()` y `buscar()`, ambas de lectura y siempre con
 *    su `admin_id`: nunca la de otro admin).
 * Nunca sale texto libre que el snapshot no guarda (el texto del pedido no existe del lado del admin).
 *
 * Cada herramienta devuelve un arreglo de datos; {@see self::ejecutar()} lo envuelve en el resultado MCP
 * (`content` con el JSON como texto + `structuredContent`). Un argumento inválido no es una excepción
 * JSON-RPC: es un resultado con `isError = true` y un texto que dice qué rango es válido.
 */
class McpLecturaService
{
    /** Tope de caracteres de las notas de una tarea de Agenda que salen por el conector. */
    const TOPE_NOTAS = 300;

    /** @var EscenaService */
    private $escena;

    /** @var AgendaService */
    private $agenda;

    /** @var array<string, mixed>|null Oficina de la escena ya armada en este pedido (se arma una sola vez). */
    private $oficina_en_memoria = null;

    /**
     * @param EscenaService $escena Escena de El local (fuente única de misiones, cola, Workboard y tareas).
     * @param AgendaService $agenda Agenda del admin.
     */
    public function __construct(EscenaService $escena, AgendaService $agenda)
    {
        $this->escena = $escena;
        $this->agenda = $agenda;
    }

    /**
     * Los nombres de las herramientas que este servicio sabe ejecutar.
     *
     * @return array<int, string>
     */
    public function nombres()
    {
        return [
            'get_overview', 'list_missions', 'get_mission', 'get_request_status',
            'get_workboard', 'get_scheduled_tasks', 'get_agenda', 'get_mission_queue',
        ];
    }

    /**
     * Ejecuta una herramienta y devuelve el resultado MCP listo para el JSON-RPC.
     *
     * @param string               $nombre     Nombre de la herramienta (ya verificado con {@see nombres()}).
     * @param array<string, mixed> $argumentos Argumentos que mandó el cliente.
     * @param Admin                $admin      Admin dueño del token (la Agenda es la suya).
     *
     * @return array<string, mixed> `{content: [...], structuredContent?: {...}, isError: bool}`.
     */
    public function ejecutar($nombre, array $argumentos, Admin $admin)
    {
        try {
            switch ($nombre) {
                case 'get_overview':
                    $datos = $this->get_overview($admin);
                    break;
                case 'list_missions':
                    $datos = $this->list_missions($argumentos);
                    break;
                case 'get_mission':
                    $datos = $this->get_mission($argumentos);
                    break;
                case 'get_request_status':
                    $datos = $this->get_request_status($argumentos, $admin);
                    break;
                case 'get_workboard':
                    $datos = $this->get_workboard($argumentos);
                    break;
                case 'get_scheduled_tasks':
                    $datos = $this->get_scheduled_tasks();
                    break;
                case 'get_agenda':
                    $datos = $this->get_agenda($argumentos, $admin);
                    break;
                case 'get_mission_queue':
                    $datos = $this->get_mission_queue();
                    break;
                default:
                    return $this->resultado_de_error('Herramienta desconocida: ' . $nombre);
            }
        } catch (\InvalidArgumentException $e) {
            return $this->resultado_de_error($e->getMessage());
        }

        return [
            'content'           => [['type' => 'text', 'text' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'structuredContent' => $datos,
            'isError'           => false,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Herramientas
     |----------------------------------------------------------------------------------------- */

    /**
     * `get_overview`: el panorama de una mirada.
     *
     * @param Admin $admin Dueño de la Agenda.
     *
     * @return array<string, mixed>
     */
    private function get_overview(Admin $admin)
    {
        $oficina  = $this->oficina();
        $misiones = $this->misiones($oficina);

        $por_estado = ['trabajando' => 0, 'esperando_lucas' => 0, 'trabada' => 0, 'terminada' => 0];
        $demoradas  = [];
        $esperando  = [];
        foreach ($misiones as $m) {
            if (isset($por_estado[$m['estado']])) {
                $por_estado[$m['estado']]++;
            }
            if (! empty($m['demorada'])) {
                $demoradas[] = $this->mision_resumida($m);
            }
            if ($m['estado'] === 'esperando_lucas') {
                $esperando[] = $this->mision_resumida($m);
            }
        }

        $cola      = isset($oficina['cola']) && is_array($oficina['cola']) ? $oficina['cola'] : null;
        $en_espera = $cola !== null && isset($cola['en_espera']) ? array_values($cola['en_espera']) : [];

        $planificador = isset($oficina['planificador']['estado']) ? $oficina['planificador']['estado'] : 'apagado';
        $gateway      = isset($oficina['openclaw']['gateway']) ? $oficina['openclaw']['gateway'] : null;

        $hoy   = $this->agenda->hoy();
        $agenda = $this->agenda->listar($admin->id, ['desde' => $hoy, 'hasta' => $hoy, 'incluir_completadas' => false, 'vencidas' => true], false);

        return array_merge($this->senal($oficina), [
            'por_estado'      => $por_estado,
            'por_origen'      => isset($oficina['por_origen']) ? $oficina['por_origen'] : new \stdClass(),
            'demoradas'       => $demoradas,
            'esperando_lucas' => $esperando,
            'cola'            => [
                'en_espera' => count($en_espera),
                'primeras'  => array_map([$this, 'entrada_de_cola'], array_slice($en_espera, 0, 5)),
                'fallidas'  => $cola !== null && isset($cola['fallidas']) ? (int) $cola['fallidas'] : 0,
            ],
            'planificador'    => $planificador,
            'gateway'         => $gateway,
            'agenda'          => [
                'hoy_pendientes' => count($agenda['ocurrencias']),
                'vencidas'       => isset($agenda['vencidas']) ? count($agenda['vencidas']) : 0,
            ],
        ]);
    }

    /**
     * `list_missions`: las misiones de la escena con filtros.
     *
     * @param array<string, mixed> $args `estado?`, `origen?`, `pedido_id?`, `solo_demoradas?`, `incluir_terminadas?`, `limite?`.
     *
     * @return array<string, mixed>
     */
    private function list_missions(array $args)
    {
        $estado     = $this->arg_opcion($args, 'estado', ['trabajando', 'esperando_lucas', 'trabada', 'terminada']);
        $origen     = $this->arg_opcion($args, 'origen', PcSnapshotService::ORIGENES);
        $pedido_id  = $this->arg_pedido($args);
        $demoradas  = $this->arg_bool($args, 'solo_demoradas', false);
        $terminadas = $this->arg_bool($args, 'incluir_terminadas', false);
        $limite     = $this->arg_entero($args, 'limite', 1, 60, 20);

        $oficina = $this->oficina();
        $salida  = [];
        foreach ($this->misiones($oficina) as $m) {
            // Sin pedirlas (por estado o con el flag) las terminadas no salen.
            if ($m['estado'] === 'terminada' && ! $terminadas && $estado !== 'terminada') {
                continue;
            }
            if ($estado !== null && $m['estado'] !== $estado) {
                continue;
            }
            if ($origen !== null && $m['origen'] !== $origen) {
                continue;
            }
            if ($pedido_id !== null && (! isset($m['pedido_id']) || $m['pedido_id'] !== $pedido_id)) {
                continue;
            }
            if ($demoradas && empty($m['demorada'])) {
                continue;
            }
            $salida[] = $this->mision_resumida($m);
        }

        return array_merge($this->senal($oficina), [
            'misiones'         => array_slice($salida, 0, $limite),
            'misiones_recorte' => isset($oficina['misiones_recorte']) ? $oficina['misiones_recorte'] : ['recortado' => false, 'omitidas' => 0],
        ]);
    }

    /**
     * `get_mission`: una misión completa de la escena (con el historial de fases y la demora).
     *
     * @param array<string, mixed> $args `id` (`cc:slug` o `slug`).
     *
     * @return array<string, mixed>
     */
    private function get_mission(array $args)
    {
        if (! isset($args['id']) || ! is_string($args['id']) || trim($args['id']) === '' || strlen($args['id']) > 120) {
            throw new \InvalidArgumentException('El argumento "id" es obligatorio: el id de la misión (cc:<slug>) o su slug.');
        }
        $buscada = trim($args['id']);

        $oficina = $this->oficina();
        foreach ($this->misiones($oficina) as $m) {
            $slug = isset($m['slug']) ? $m['slug'] : null;
            if ($m['id'] === $buscada || $m['id'] === 'cc:' . $buscada || ($slug !== null && $slug === $buscada)) {
                unset($m['fases']);

                return array_merge($this->senal($oficina), $m);
            }
        }

        throw new \InvalidArgumentException('No la veo entre las ' . EscenaService::TOPE_MISIONES . ' más recientes de El local: ' . $buscada);
    }

    /**
     * `get_request_status`: el avance de un pedido de Dots (o, sin id, la lista de pedidos vistos).
     *
     * @param array<string, mixed> $args  `pedido_id?`.
     * @param Admin                $admin Dueño de la Agenda.
     *
     * @return array<string, mixed>
     */
    private function get_request_status(array $args, Admin $admin)
    {
        $pedido_id = $this->arg_pedido($args);
        $oficina   = $this->oficina();
        $misiones  = $this->misiones($oficina);
        $en_espera = $this->cola_en_espera($oficina);

        // Sin id: los pedidos vistos en misiones y cola, con sus conteos.
        if ($pedido_id === null) {
            $pedidos = [];
            foreach ($misiones as $m) {
                if (empty($m['pedido_id'])) {
                    continue;
                }
                $p = $m['pedido_id'];
                $pedidos[$p] = isset($pedidos[$p]) ? $pedidos[$p] : ['pedido_id' => $p, 'misiones' => 0, 'terminadas' => 0, 'en_cola' => 0];
                $pedidos[$p]['misiones']++;
                if ($m['estado'] === 'terminada') {
                    $pedidos[$p]['terminadas']++;
                }
            }
            foreach ($en_espera as $e) {
                if (empty($e['pedido_id'])) {
                    continue;
                }
                $p = $e['pedido_id'];
                $pedidos[$p] = isset($pedidos[$p]) ? $pedidos[$p] : ['pedido_id' => $p, 'misiones' => 0, 'terminadas' => 0, 'en_cola' => 0];
                $pedidos[$p]['en_cola']++;
            }

            return array_merge($this->senal($oficina), ['pedidos' => array_values($pedidos)]);
        }

        // Con id: las piezas (misiones, cola y tareas de Agenda con `pedido:<id>` en las notas) y el progreso.
        $de_misiones = [];
        $progreso = [
            'total' => 0, 'terminadas' => 0, 'trabajando' => 0, 'esperando_lucas' => 0, 'trabadas' => 0,
            'en_cola' => 0, 'agenda_pendientes' => 0, 'agenda_hechas' => 0,
        ];
        foreach ($misiones as $m) {
            if (! isset($m['pedido_id']) || $m['pedido_id'] !== $pedido_id) {
                continue;
            }
            $de_misiones[] = $this->mision_resumida($m);
            $progreso['total']++;
            if ($m['estado'] === 'terminada') {
                $progreso['terminadas']++;
            } elseif ($m['estado'] === 'trabajando') {
                $progreso['trabajando']++;
            } elseif ($m['estado'] === 'esperando_lucas') {
                $progreso['esperando_lucas']++;
            } elseif ($m['estado'] === 'trabada') {
                $progreso['trabadas']++;
            }
        }

        $de_cola = [];
        foreach ($en_espera as $e) {
            if (isset($e['pedido_id']) && $e['pedido_id'] === $pedido_id) {
                $de_cola[] = $this->entrada_de_cola($e);
                $progreso['total']++;
                $progreso['en_cola']++;
            }
        }

        $de_agenda = [];
        foreach ($this->agenda->buscar($admin->id, 'pedido:' . $pedido_id) as $tarea) {
            // El LIKE de la búsqueda es por prefijo: se confirma que el id sea exactamente éste.
            if (! $this->notas_nombran_el_pedido((string) $tarea['notes'], $pedido_id)) {
                continue;
            }
            $de_agenda[] = $this->tarea_de_agenda($tarea);
            $progreso['total']++;
            $progreso[$tarea['completed'] ? 'agenda_hechas' : 'agenda_pendientes']++;
        }

        return array_merge($this->senal($oficina), [
            'pedido_id' => $pedido_id,
            'progreso'  => $progreso,
            'misiones'  => $de_misiones,
            'cola'      => $de_cola,
            'agenda'    => $de_agenda,
        ]);
    }

    /**
     * `get_workboard`: conteo y tarjetas del Workboard de OpenClaw.
     *
     * @param array<string, mixed> $args `estado?`, `limite?`.
     *
     * @return array<string, mixed>
     */
    private function get_workboard(array $args)
    {
        $estado = $this->arg_opcion($args, 'estado', PcSnapshotService::ESTADOS_TARJETA);
        $limite = $this->arg_entero($args, 'limite', 1, 100, 30);

        $oficina   = $this->oficina();
        $workboard = isset($oficina['openclaw']['workboard']) && is_array($oficina['openclaw']['workboard']) ? $oficina['openclaw']['workboard'] : null;

        $tarjetas = [];
        foreach ($workboard !== null && isset($workboard['tarjetas']) ? $workboard['tarjetas'] : [] as $t) {
            if ($estado !== null && $t['estado'] !== $estado) {
                continue;
            }
            $tarjetas[] = [
                'id'             => $t['id'],
                'titulo'         => $t['titulo'],
                'estado'         => $t['estado'],
                'prioridad'      => isset($t['prioridad']) ? $t['prioridad'] : null,
                'etiquetas'      => isset($t['etiquetas']) ? array_values($t['etiquetas']) : [],
                'agente'         => isset($t['agente']) ? $t['agente'] : null,
                'actualizada_at' => isset($t['actualizada_at']) ? $t['actualizada_at'] : null,
                'vence_at'       => isset($t['vence_at']) ? $t['vence_at'] : null,
                'mision_id'      => isset($t['mision_id']) ? $t['mision_id'] : null,
            ];
        }

        return array_merge($this->senal($oficina), [
            'ok'        => $workboard !== null && ! empty($workboard['ok']),
            'total'     => $workboard !== null && isset($workboard['total']) ? (int) $workboard['total'] : 0,
            'conteo'    => $workboard !== null && ! empty($workboard['conteo']) ? $workboard['conteo'] : new \stdClass(),
            'recortado' => $workboard !== null && ! empty($workboard['recortado']),
            'tarjetas'  => array_slice($tarjetas, 0, $limite),
        ]);
    }

    /**
     * `get_scheduled_tasks`: el gateway de OpenClaw y sus tareas programadas.
     *
     * @return array<string, mixed>
     */
    private function get_scheduled_tasks()
    {
        $oficina  = $this->oficina();
        $openclaw = isset($oficina['openclaw']) && is_array($oficina['openclaw']) ? $oficina['openclaw'] : null;
        $items    = $openclaw !== null && isset($openclaw['tareas']['items']) ? $openclaw['tareas']['items'] : [];

        $tareas = [];
        foreach ($items as $t) {
            $tareas[] = [
                'id'                 => $t['id'],
                'nombre'             => $t['nombre'],
                'habilitada'         => (bool) $t['habilitada'],
                'tipo'               => isset($t['tipo']) ? $t['tipo'] : null,
                'agente'             => isset($t['agente']) ? $t['agente'] : null,
                'frecuencia'         => isset($t['frecuencia']) ? $t['frecuencia'] : null,
                'ultimo_resultado'   => isset($t['ultimo_resultado']) ? $t['ultimo_resultado'] : null,
                'ultima_corrida_at'  => isset($t['ultima_corrida_at']) ? $t['ultima_corrida_at'] : null,
                'proxima_corrida_at' => isset($t['proxima_corrida_at']) ? $t['proxima_corrida_at'] : null,
                'errores_seguidos'   => isset($t['errores_seguidos']) ? (int) $t['errores_seguidos'] : 0,
            ];
        }

        return array_merge($this->senal($oficina), [
            'gateway' => $openclaw !== null ? $openclaw['gateway'] : ['estado' => 'desconocido', 'version' => null, 'verificado_at' => null],
            'tareas'  => $tareas,
        ]);
    }

    /**
     * `get_agenda`: la Agenda del admin del token (hoy, la semana o las vencidas).
     *
     * @param array<string, mixed> $args  `rango?` (hoy|vencidas|semana), `responsable?` (todos|lucas|openclaw).
     * @param Admin                $admin Dueño de la Agenda: nunca se lee la de otro admin.
     *
     * @return array<string, mixed>
     */
    private function get_agenda(array $args, Admin $admin)
    {
        $rango       = $this->arg_opcion($args, 'rango', ['hoy', 'vencidas', 'semana']);
        $rango       = $rango === null ? 'hoy' : $rango;
        $responsable = $this->arg_opcion($args, 'responsable', ['todos', 'lucas', 'openclaw']);
        $responsable = $responsable === null ? 'todos' : $responsable;

        $hoy   = $this->agenda->hoy();
        $input = ['desde' => $hoy, 'hasta' => $hoy];
        if ($rango === 'semana') {
            $input['hasta'] = date('Y-m-d', strtotime($hoy . ' +6 days'));
        }
        if ($rango === 'vencidas') {
            $input['vencidas'] = true;
        }
        if ($responsable !== 'todos') {
            // En la Agenda «lucas» es el responsable `owner`.
            $input['assignee'] = $responsable === 'lucas' ? 'owner' : 'openclaw';
        }

        $lista = $this->agenda->listar($admin->id, $input, false);
        $tareas = [];
        $origen = $rango === 'vencidas' ? $lista['vencidas'] : $lista['ocurrencias'];
        foreach ($origen as $tarea) {
            $tareas[] = $this->tarea_de_agenda($tarea);
        }

        return [
            'rango'       => $rango,
            'responsable' => $responsable,
            'hoy'         => $hoy,
            'desde'       => $lista['desde'],
            'hasta'       => $lista['hasta'],
            'tareas'      => $tareas,
        ];
    }

    /**
     * `get_mission_queue`: la cola de misiones por recursos.
     *
     * @return array<string, mixed>
     */
    private function get_mission_queue()
    {
        $oficina = $this->oficina();
        $cola    = isset($oficina['cola']) && is_array($oficina['cola']) ? $oficina['cola'] : null;

        return [
            'ok'             => $cola !== null && ! empty($cola['ok']),
            'en_espera'      => array_map([$this, 'entrada_de_cola'], $this->cola_en_espera($oficina)),
            'fallidas'       => $cola !== null && isset($cola['fallidas']) ? (int) $cola['fallidas'] : 0,
            'ultimo_chequeo' => $cola !== null && isset($cola['ultimo_chequeo']) ? $cola['ultimo_chequeo'] : null,
            'snapshot_at'    => $cola !== null && isset($cola['reportado_at']) ? $cola['reportado_at'] : null,
            'sin_senal'      => $cola === null ? true : (bool) $cola['sin_senal'],
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Fuente de datos y formas de salida
     |----------------------------------------------------------------------------------------- */

    /**
     * La `oficina` de la escena de El local (la misma que dibuja el admin). Se pide una sola vez por pedido y
     * comparte el cache de 20 s de la vista, para que el conector no duplique el cálculo.
     *
     * @return array<string, mixed>
     */
    private function oficina()
    {
        if ($this->oficina_en_memoria === null) {
            $escena = $this->escena;
            $armada = Cache::remember(EscenaService::CACHE_KEY, EscenaService::CACHE_SEGUNDOS, function () use ($escena) {
                return $escena->armar();
            });
            $this->oficina_en_memoria = isset($armada['oficina']) && is_array($armada['oficina']) ? $armada['oficina'] : [];
        }

        return $this->oficina_en_memoria;
    }

    /**
     * Las misiones de la oficina (vacío si El local no está en modo misiones).
     *
     * @param array<string, mixed> $oficina Oficina de la escena.
     *
     * @return array<int, array<string, mixed>>
     */
    private function misiones(array $oficina)
    {
        return isset($oficina['misiones']) && is_array($oficina['misiones']) ? array_values($oficina['misiones']) : [];
    }

    /**
     * Las misiones en espera de la cola.
     *
     * @param array<string, mixed> $oficina Oficina de la escena.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cola_en_espera(array $oficina)
    {
        return isset($oficina['cola']['en_espera']) && is_array($oficina['cola']['en_espera']) ? array_values($oficina['cola']['en_espera']) : [];
    }

    /**
     * `snapshot_at` y `sin_senal` que lleva cada respuesta: cuándo vio la PC por última vez el admin y si esa
     * señal ya no es viva (reporter sin pulso reciente o El local fuera del modo misiones).
     *
     * @param array<string, mixed> $oficina Oficina de la escena.
     *
     * @return array{snapshot_at: string|null, sin_senal: bool}
     */
    private function senal(array $oficina)
    {
        $reporter = isset($oficina['reporter']) && is_array($oficina['reporter']) ? $oficina['reporter'] : [];
        $en_modo  = isset($oficina['modo']) && $oficina['modo'] === 'misiones';

        return [
            'snapshot_at' => isset($reporter['last_seen_at']) ? $reporter['last_seen_at'] : null,
            'sin_senal'   => ! $en_modo || empty($reporter['ok']),
        ];
    }

    /**
     * Una misión resumida para listas.
     *
     * @param array<string, mixed> $m Misión de la escena.
     *
     * @return array<string, mixed>
     */
    private function mision_resumida(array $m)
    {
        return [
            'id'                  => $m['id'],
            'titulo'              => isset($m['titulo']) ? $m['titulo'] : null,
            'origen'              => $m['origen'],
            'estado'              => $m['estado'],
            'etapa'               => isset($m['etapa']) ? $m['etapa'] : null,
            'fase'                => isset($m['fase']) ? $m['fase'] : null,
            'minutos_en_etapa'    => isset($m['minutos_en_etapa']) ? $m['minutos_en_etapa'] : null,
            'umbral_min'          => isset($m['umbral_min']) ? $m['umbral_min'] : null,
            'demorada'            => ! empty($m['demorada']),
            'pedido_id'           => isset($m['pedido_id']) ? $m['pedido_id'] : null,
            'slug'                => isset($m['slug']) ? $m['slug'] : null,
            'ticket'              => isset($m['ticket']) ? $m['ticket'] : null,
            'repos'               => isset($m['repos']) ? array_values($m['repos']) : [],
            'ultima_actividad_at' => isset($m['ultima_actividad_at']) ? $m['ultima_actividad_at'] : null,
        ];
    }

    /**
     * Una misión en espera de la cola, con las claves que salen por el conector.
     *
     * @param array<string, mixed> $e Entrada de `cola.en_espera`.
     *
     * @return array<string, mixed>
     */
    private function entrada_de_cola(array $e)
    {
        return [
            'slug'        => $e['slug'],
            'titulo'      => isset($e['titulo']) ? $e['titulo'] : null,
            'prioridad'   => isset($e['prioridad']) ? $e['prioridad'] : null,
            'origen'      => isset($e['origen']) ? $e['origen'] : null,
            'pedido_id'   => isset($e['pedido_id']) ? $e['pedido_id'] : null,
            'motivos'     => isset($e['motivos']) ? array_values($e['motivos']) : [],
            'espera_min'  => isset($e['espera_min']) ? $e['espera_min'] : null,
            'intentos'    => isset($e['intentos']) ? (int) $e['intentos'] : 0,
            'encolada_at' => isset($e['encolada_at']) ? $e['encolada_at'] : null,
        ];
    }

    /**
     * Una tarea de Agenda con las claves que salen por el conector (notas recortadas).
     *
     * @param array<string, mixed> $t Tarea serializada por `AgendaService`.
     *
     * @return array<string, mixed>
     */
    private function tarea_de_agenda(array $t)
    {
        $notas = isset($t['notes']) && $t['notes'] !== null ? mb_substr((string) $t['notes'], 0, self::TOPE_NOTAS) : null;

        return [
            'id'              => $t['id'],
            'title'           => $t['title'],
            'notes'           => $notas,
            'date'            => $t['date'],
            'occurrence_date' => $t['occurrence_date'],
            'start_time'      => $t['start_time'],
            'end_time'        => $t['end_time'],
            'all_day'         => $t['all_day'],
            'completed'       => $t['completed'],
            'assignee'        => isset($t['assignee']) ? $t['assignee'] : 'owner',
            'repeat'          => $t['repeat'],
            'created_via'     => isset($t['created_via']) ? $t['created_via'] : null,
            'subtasks_done'   => $t['subtasks_done'],
            'subtasks_total'  => $t['subtasks_total'],
        ];
    }

    /**
     * Si unas notas de Agenda nombran exactamente a un pedido (`pedido:<id>` sin que el id siga con otro
     * carácter de id).
     *
     * @param string $notas     Notas de la tarea.
     * @param string $pedido_id Pedido buscado.
     *
     * @return bool
     */
    private function notas_nombran_el_pedido($notas, $pedido_id)
    {
        return preg_match('/pedido:' . preg_quote($pedido_id, '/') . '(?![a-z0-9-])/', $notas) === 1;
    }

    /**
     * El resultado MCP de un error de herramienta.
     *
     * @param string $mensaje Texto para quien llamó.
     *
     * @return array<string, mixed>
     */
    private function resultado_de_error($mensaje)
    {
        return ['content' => [['type' => 'text', 'text' => $mensaje]], 'isError' => true];
    }

    /* ------------------------------------------------------------------------------------------
     | Argumentos
     |----------------------------------------------------------------------------------------- */

    /**
     * Un argumento de lista cerrada (opcional).
     *
     * @param array<string, mixed> $args      Argumentos.
     * @param string               $clave     Nombre.
     * @param array<int, string>   $permitidos Valores válidos.
     *
     * @return string|null null si no vino.
     *
     * @throws \InvalidArgumentException Si vino con otro valor.
     */
    private function arg_opcion(array $args, $clave, array $permitidos)
    {
        if (! isset($args[$clave])) {
            return null;
        }
        if (! is_string($args[$clave]) || ! in_array($args[$clave], $permitidos, true)) {
            throw new \InvalidArgumentException('El argumento "' . $clave . '" tiene que ser uno de: ' . implode(', ', $permitidos) . '.');
        }

        return $args[$clave];
    }

    /**
     * Un argumento booleano (opcional); acepta true/false, 1/0 y "true"/"false".
     *
     * @param array<string, mixed> $args    Argumentos.
     * @param string               $clave   Nombre.
     * @param bool                 $defecto Valor si no vino.
     *
     * @return bool
     *
     * @throws \InvalidArgumentException Si no se puede leer como booleano.
     */
    private function arg_bool(array $args, $clave, $defecto)
    {
        if (! isset($args[$clave])) {
            return $defecto;
        }
        $valor = filter_var($args[$clave], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($valor === null) {
            throw new \InvalidArgumentException('El argumento "' . $clave . '" tiene que ser true o false.');
        }

        return $valor;
    }

    /**
     * Un argumento entero con rango (opcional).
     *
     * @param array<string, mixed> $args    Argumentos.
     * @param string               $clave   Nombre.
     * @param int                  $minimo  Mínimo válido.
     * @param int                  $maximo  Máximo válido.
     * @param int                  $defecto Valor si no vino.
     *
     * @return int
     *
     * @throws \InvalidArgumentException Si no es un entero dentro del rango.
     */
    private function arg_entero(array $args, $clave, $minimo, $maximo, $defecto)
    {
        if (! isset($args[$clave])) {
            return $defecto;
        }
        $valor = filter_var($args[$clave], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
        if (is_bool($args[$clave]) || $valor === null || $valor < $minimo || $valor > $maximo) {
            throw new \InvalidArgumentException('El argumento "' . $clave . '" tiene que ser un entero entre ' . $minimo . ' y ' . $maximo . '.');
        }

        return $valor;
    }

    /**
     * El argumento `pedido_id` (opcional): tiene que cumplir el formato de pedido.
     *
     * @param array<string, mixed> $args Argumentos.
     *
     * @return string|null
     *
     * @throws \InvalidArgumentException Si no cumple el formato.
     */
    private function arg_pedido(array $args)
    {
        if (! isset($args['pedido_id'])) {
            return null;
        }
        if (! is_string($args['pedido_id']) || preg_match(PcSnapshotService::REGEX_PEDIDO_ID, $args['pedido_id']) !== 1) {
            throw new \InvalidArgumentException('El argumento "pedido_id" tiene que tener el formato aaaammdd-hhmm-tema (ej. 20261126-0930-precios).');
        }

        return $args['pedido_id'];
    }
}

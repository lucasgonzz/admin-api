<?php

namespace App\Services\Agenda;

use App\Helpers\AppTime;
use App\Models\AgendaSubtask;
use App\Models\AgendaTask;
use App\Models\AgendaTaskCompletion;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * TODA la lógica de la Agenda del admin (misión agenda-admin, 31/10/2026).
 *
 * La usan los dos controladores, que son delgados a propósito: `Api\AgendaController` (el panel,
 * con la agenda del admin logueado) y `Api\ClaudeAgendaController` (Claude, con la agenda del admin
 * que pida). Así hay UNA sola definición de:
 *
 *  - la validación ({@see AgendaValidator});
 *  - la expansión de las repetidas ({@see ocurrencias_en_rango()}), que también decide si una
 *    fecha es una ocurrencia real al marcarla hecha;
 *  - la forma de una tarea en las respuestas ({@see serializar_ocurrencia()}), que es la misma en
 *    el panel y en Claude.
 *
 * Reglas de fondo:
 *  - Una tarea repetida es UNA fila (la serie). Editarla o moverla edita/mueve la serie entera; sus
 *    subpasos son de la serie. Lo único que es de cada ocurrencia es si está hecha, y eso vive en
 *    `agenda_task_completions` (una fila por ocurrencia hecha).
 *  - Una tarea no repetida guarda si está hecha en `agenda_tasks.completed_at`.
 *  - "Ahora" sale de {@see AppTime::now()} (reloj virtual en local), nunca de `now()` a secas.
 *  - Las cuentas de fechas se hacen en UTC sobre días sin hora: un día es un día, sin cambios de
 *    horario de por medio. Las horas de la tarea son de pared, en la zona de Buenos Aires.
 */
class AgendaService
{
    /** Zona horaria de la Agenda (la del sistema). */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /** Tope de coincidencias de una búsqueda de Claude. */
    const TOPE_BUSQUEDA = 50;

    /** Freno de seguridad de la expansión: nunca más ocurrencias que esto por tarea y rango. */
    const TOPE_OCURRENCIAS = 1000;

    /** Valores con los que nace una tarea si el alta no los manda. */
    const POR_DEFECTO = [
        'title'            => null,
        'notes'            => null,
        'date'             => null,
        'start_time'       => null,
        'all_day'          => false,
        'duration_minutes' => 15,
        'color'            => 'salmon',
        'icon'             => 'check2-square',
        'repeat'           => 'none',
        'repeat_until'     => null,
    ];

    /** @var AgendaValidator */
    private $validator;

    /**
     * @param AgendaValidator $validator La única definición de validación de la Agenda.
     */
    public function __construct(AgendaValidator $validator)
    {
        $this->validator = $validator;
    }

    /* ------------------------------------------------------------------------------------------
     | Lectura
     |----------------------------------------------------------------------------------------- */

    /**
     * El día de hoy (Y-m-d) según el reloj del sistema.
     *
     * @return string
     */
    public function hoy(): string
    {
        return AppTime::now(self::ZONA)->toDateString();
    }

    /**
     * La agenda de un admin en un rango: las ocurrencias de cada día y la bandeja.
     *
     * Las ocurrencias salen de las tareas con fecha del rango (no repetidas) y de la expansión de
     * las repetidas que se cruzan con el rango. Si están hechas o no sale de una sola consulta a
     * `agenda_task_completions` para todo el rango, indexada en memoria. Orden: fecha, las de todo
     * el día primero, hora, id. La bandeja va por `sort_order` e id.
     *
     * @param int   $admin_id    Dueño de la agenda.
     * @param array $input       Parámetros del request (`desde`, `hasta`, `incluir_completadas`).
     * @param bool  $obligatorio Si `desde`/`hasta` son obligatorios (panel) u opcionales (Claude).
     *
     * @return array{desde: string, hasta: string, hoy: string, ocurrencias: array, bandeja: array}
     *
     * @throws \Illuminate\Validation\ValidationException 422 si el rango no es válido.
     */
    public function listar(int $admin_id, array $input, bool $obligatorio): array
    {
        $hoy = $this->hoy();

        list($desde, $hasta, $incluir_completadas) = $this->validator->validar_rango($input, $obligatorio, $hoy);

        /* Las candidatas: las no repetidas con fecha adentro del rango, y las repetidas que empiezan
           antes del fin del rango y no terminaron antes de su comienzo. La expansión fina (qué días
           caen adentro) la hace ocurrencias_en_rango(). */
        $tareas = AgendaTask::with('subtasks')
            ->where('admin_id', $admin_id)
            ->whereNotNull('date')
            ->where(function ($q) use ($desde, $hasta) {
                $q->where(function ($q) use ($desde, $hasta) {
                    $q->where('repeat', 'none')->whereBetween('date', [$desde, $hasta]);
                })->orWhere(function ($q) use ($desde, $hasta) {
                    $q->where('repeat', '!=', 'none')
                        ->where('date', '<=', $hasta)
                        ->where(function ($q) use ($desde) {
                            $q->whereNull('repeat_until')->orWhere('repeat_until', '>=', $desde);
                        });
                });
            })
            ->get();

        $hechas = $this->hechas_del_rango($tareas, $desde, $hasta);

        $ocurrencias = [];

        foreach ($tareas as $tarea) {
            if (! $tarea->is_recurring()) {
                $ocurrencias[] = $this->serializar_ocurrencia(
                    $tarea,
                    (string) $tarea->date,
                    $tarea->completed_at !== null,
                    $tarea->completed_at
                );
                continue;
            }

            foreach ($this->ocurrencias_en_rango($tarea, $desde, $hasta) as $fecha) {
                $clave = $tarea->id . '|' . $fecha;
                $hecha = isset($hechas[$clave]) ? $hechas[$clave] : null;

                $ocurrencias[] = $this->serializar_ocurrencia(
                    $tarea,
                    $fecha,
                    $hecha !== null,
                    $hecha !== null ? $hecha->completed_at : null
                );
            }
        }

        if (! $incluir_completadas) {
            $ocurrencias = array_values(array_filter($ocurrencias, function ($o) {
                return ! $o['completed'];
            }));
        }

        usort($ocurrencias, function ($a, $b) {
            return [$a['occurrence_date'], $a['all_day'] ? 0 : 1, (string) $a['start_time'], $a['id']]
                <=> [$b['occurrence_date'], $b['all_day'] ? 0 : 1, (string) $b['start_time'], $b['id']];
        });

        $bandeja = AgendaTask::with('subtasks')
            ->where('admin_id', $admin_id)
            ->whereNull('date')
            ->when(! $incluir_completadas, function ($q) {
                $q->whereNull('completed_at');
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function ($tarea) {
                return $this->serializar($tarea);
            })
            ->values()
            ->all();

        return [
            'desde'       => $desde,
            'hasta'       => $hasta,
            'hoy'         => $hoy,
            'ocurrencias' => $ocurrencias,
            'bandeja'     => $bandeja,
        ];
    }

    /**
     * Busca tareas de un admin por texto en el título o en las notas, sin importar la fecha.
     *
     * Devuelve las tareas SIN expandir (una por serie, como `GET agenda/{id}`), las más nuevas
     * primero y con un tope de 50. Los comodines del LIKE se escapan: «50%» busca «50%».
     *
     * @param int    $admin_id Dueño de la agenda.
     * @param string $texto    Texto a buscar (ya recortado, no vacío).
     *
     * @return array<int, array> Tareas serializadas.
     */
    public function buscar(int $admin_id, string $texto): array
    {
        $patron = '%' . addcslashes($texto, '%_\\') . '%';

        return AgendaTask::with('subtasks')
            ->where('admin_id', $admin_id)
            ->where(function ($q) use ($patron) {
                $q->where('title', 'like', $patron)->orWhere('notes', 'like', $patron);
            })
            ->orderByDesc('id')
            ->limit(self::TOPE_BUSQUEDA)
            ->get()
            ->map(function ($tarea) {
                return $this->serializar($tarea);
            })
            ->values()
            ->all();
    }

    /**
     * Trae una tarea por id, opcionalmente restringida a un dueño; si no está, corta con 404.
     *
     * En el panel `$admin_id` es el admin logueado: una tarea de otro admin es 404 (no 403), para
     * no confirmar siquiera que existe. En Claude se pasa null y vale cualquier tarea.
     *
     * @param int      $id       Id de la tarea.
     * @param int|null $admin_id Dueño exigido, o null para no exigir ninguno.
     *
     * @return AgendaTask Con los subpasos cargados.
     *
     * @throws HttpResponseException 404 si no existe (o no es de ese admin).
     */
    public function encontrar(int $id, ?int $admin_id): AgendaTask
    {
        $tarea = AgendaTask::with('subtasks')
            ->where('id', $id)
            ->when($admin_id !== null, function ($q) use ($admin_id) {
                $q->where('admin_id', $admin_id);
            })
            ->first();

        if ($tarea === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'No existe esa tarea en la agenda.',
            ], 404));
        }

        return $tarea;
    }

    /* ------------------------------------------------------------------------------------------
     | Escritura de tareas
     |----------------------------------------------------------------------------------------- */

    /**
     * Crea una tarea (con sus subpasos) en la agenda de un admin.
     *
     * Una tarea nueva va al final del orden del admin (`sort_order` = el mayor + 1), salvo que el
     * alta mande otro.
     *
     * @param int    $admin_id    Dueño de la tarea.
     * @param array  $input       Body del request.
     * @param string $created_via Origen: `panel` o `claude`.
     *
     * @return AgendaTask La tarea creada, con los subpasos cargados.
     *
     * @throws \Illuminate\Validation\ValidationException 422 si los datos no son válidos.
     */
    public function crear(int $admin_id, array $input, string $created_via): AgendaTask
    {
        $datos  = $this->validator->validar_campos($input, true);
        $estado = $this->validator->resolver_estado(self::POR_DEFECTO, $datos);

        $tarea = DB::transaction(function () use ($admin_id, $datos, $estado, $created_via) {
            $tarea = new AgendaTask();
            $tarea->fill($this->columnas_de_estado($estado));
            $tarea->admin_id     = $admin_id;
            $tarea->created_via  = $created_via;
            $tarea->completed_at = null;
            $tarea->sort_order   = array_key_exists('sort_order', $datos)
                ? (int) $datos['sort_order']
                : $this->siguiente_orden($admin_id);
            $tarea->save();

            if (! empty($datos['subtasks'])) {
                $this->crear_subpasos($tarea, $datos['subtasks']);
            }

            return $tarea;
        });

        return $this->recargar($tarea);
    }

    /**
     * Edita (o mueve) una tarea. La edición es parcial: lo que no vino no se toca.
     *
     * - `subtasks` presente REEMPLAZA el conjunto: con `id` se actualiza ese subpaso, sin `id` se
     *   crea, y los que no vinieron se borran. Un `id` que no es de esta tarea es 422. `subtasks`
     *   en null se toma como "no tocar los subpasos" (y `[]` como "borrarlos todos").
     * - Si la tarea deja de repetirse, lo hecho en la fecha base pasa a `completed_at` y las
     *   ocurrencias hechas se borran; si empieza a repetirse estando hecha, lo hecho pasa a ser la
     *   ocurrencia de la fecha base. Así el estado "hecha" nunca queda en dos lugares a la vez.
     * - Si pasa a la bandeja (desde una fecha) y no vino `sort_order`, va al final de la bandeja.
     *
     * @param AgendaTask $tarea La tarea a editar.
     * @param array      $input Body del request.
     *
     * @return AgendaTask La tarea editada, recargada con sus subpasos.
     *
     * @throws \Illuminate\Validation\ValidationException 422 si los datos no son válidos.
     */
    public function actualizar(AgendaTask $tarea, array $input): AgendaTask
    {
        $datos  = $this->validator->validar_campos($input, false);
        $estado = $this->validator->resolver_estado($this->estado_de($tarea), $datos);

        DB::transaction(function () use ($tarea, $datos, $estado) {
            $era_repetida = $tarea->is_recurring();
            $tenia_fecha  = $tarea->date !== null;

            $tarea->fill($this->columnas_de_estado($estado));

            if (array_key_exists('sort_order', $datos)) {
                $tarea->sort_order = (int) $datos['sort_order'];
            } elseif ($tenia_fecha && $estado['date'] === null) {
                $tarea->sort_order = $this->siguiente_orden((int) $tarea->admin_id);
            }

            $es_repetida = $tarea->is_recurring();

            if ($era_repetida && ! $es_repetida) {
                // La serie pasa a ser una tarea suelta: hereda lo hecho en su (nueva) fecha.
                $hecha = $estado['date'] === null ? null : AgendaTaskCompletion::where('agenda_task_id', $tarea->id)
                    ->where('occurrence_date', $estado['date'])
                    ->orderBy('id')
                    ->first();

                $tarea->completed_at = $hecha !== null ? $hecha->completed_at : null;
                AgendaTaskCompletion::where('agenda_task_id', $tarea->id)->delete();
            } elseif (! $era_repetida && $es_repetida) {
                // La tarea suelta pasa a ser una serie: lo hecho pasa a la ocurrencia de su fecha base.
                if ($tarea->completed_at !== null) {
                    AgendaTaskCompletion::create([
                        'agenda_task_id'  => $tarea->id,
                        'occurrence_date' => $estado['date'],
                        'completed_at'    => $tarea->completed_at,
                    ]);
                }
                $tarea->completed_at = null;
            }

            $tarea->save();

            if (array_key_exists('subtasks', $datos)) {
                $this->reemplazar_subpasos($tarea, $datos['subtasks']);
            }
        });

        return $this->recargar($tarea);
    }

    /**
     * Borra una tarea con sus subpasos y sus ocurrencias hechas.
     *
     * @param AgendaTask $tarea La tarea a borrar.
     *
     * @return int El id de la tarea borrada.
     */
    public function borrar(AgendaTask $tarea): int
    {
        $id = (int) $tarea->id;

        DB::transaction(function () use ($tarea, $id) {
            AgendaSubtask::where('agenda_task_id', $id)->delete();
            AgendaTaskCompletion::where('agenda_task_id', $id)->delete();
            $tarea->delete();
        });

        return $id;
    }

    /**
     * Duplica una tarea: mismos datos y subpasos, pero sin marcar y no hecha, al final del orden.
     *
     * @param AgendaTask $tarea       La tarea a copiar.
     * @param string     $created_via Origen de la copia: `panel` o `claude`.
     *
     * @return AgendaTask La copia, con sus subpasos cargados.
     */
    public function duplicar(AgendaTask $tarea, string $created_via): AgendaTask
    {
        $copia = DB::transaction(function () use ($tarea, $created_via) {
            $copia = new AgendaTask();
            $copia->fill($this->columnas_de_estado($this->estado_de($tarea)));
            $copia->admin_id     = (int) $tarea->admin_id;
            $copia->created_via  = $created_via;
            $copia->completed_at = null;
            $copia->sort_order   = $this->siguiente_orden((int) $tarea->admin_id);
            $copia->save();

            $subpasos = [];
            foreach ($tarea->subtasks as $subpaso) {
                $subpasos[] = ['id' => null, 'title' => (string) $subpaso->title, 'done' => false];
            }
            $this->crear_subpasos($copia, $subpasos);

            return $copia;
        });

        return $this->recargar($copia);
    }

    /* ------------------------------------------------------------------------------------------
     | Hecha / no hecha
     |----------------------------------------------------------------------------------------- */

    /**
     * Marca hecha una tarea (o una ocurrencia de una repetida). Idempotente.
     *
     * @param AgendaTask $tarea La tarea.
     * @param array      $input Body del request (`date`, obligatorio si la tarea se repite).
     *
     * @return array La tarea serializada en forma de ocurrencia (la de esa fecha).
     *
     * @throws \Illuminate\Validation\ValidationException 422 si la fecha falta o no es una ocurrencia.
     */
    public function completar(AgendaTask $tarea, array $input): array
    {
        return $this->marcar($tarea, $input, true);
    }

    /**
     * Desmarca una tarea (o una ocurrencia de una repetida). Idempotente.
     *
     * @param AgendaTask $tarea La tarea.
     * @param array      $input Body del request (`date`, obligatorio si la tarea se repite).
     *
     * @return array La tarea serializada en forma de ocurrencia (la de esa fecha).
     *
     * @throws \Illuminate\Validation\ValidationException 422 si la fecha falta o no es una ocurrencia.
     */
    public function descompletar(AgendaTask $tarea, array $input): array
    {
        return $this->marcar($tarea, $input, false);
    }

    /* ------------------------------------------------------------------------------------------
     | Subpasos sueltos
     |----------------------------------------------------------------------------------------- */

    /**
     * Edita UN subpaso (texto y/o tildado) de una tarea.
     *
     * @param AgendaTask $tarea      La tarea dueña.
     * @param int        $subtask_id Id del subpaso.
     * @param array      $input      Body del request (`title?`, `done?`).
     *
     * @return AgendaTask La tarea recargada.
     *
     * @throws HttpResponseException 404 si el subpaso no es de esta tarea.
     * @throws \Illuminate\Validation\ValidationException 422 si los datos no son válidos.
     */
    public function actualizar_subpaso(AgendaTask $tarea, int $subtask_id, array $input): AgendaTask
    {
        $subpaso = AgendaSubtask::where('agenda_task_id', $tarea->id)->where('id', $subtask_id)->first();

        if ($subpaso === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'No existe ese subpaso en esta tarea.',
            ], 404));
        }

        $datos = $this->validator->validar_subpaso($input, false);

        if (! empty($datos)) {
            $subpaso->fill($datos);
            $subpaso->save();
            // La tarea también "cambió": su updated_at refleja el último toque a sus subpasos.
            $tarea->touch();
        }

        return $this->recargar($tarea);
    }

    /**
     * Agrega UN subpaso al final de una tarea.
     *
     * @param AgendaTask $tarea La tarea dueña.
     * @param array      $input Body del request (`title`, `done?`).
     *
     * @return AgendaTask La tarea recargada.
     *
     * @throws \Illuminate\Validation\ValidationException 422 si los datos no son válidos o la tarea
     *                                                    ya tiene el máximo de subpasos.
     */
    public function agregar_subpaso(AgendaTask $tarea, array $input): AgendaTask
    {
        $datos = $this->validator->validar_subpaso($input, true);

        $cantidad = AgendaSubtask::where('agenda_task_id', $tarea->id)->count();
        if ($cantidad >= AgendaValidator::MAX_SUBPASOS) {
            $this->validator->fallar(['title' => ['La tarea ya tiene ' . AgendaValidator::MAX_SUBPASOS . ' subpasos, que es el máximo.']]);
        }

        $ultimo = AgendaSubtask::where('agenda_task_id', $tarea->id)->max('sort_order');

        AgendaSubtask::create([
            'agenda_task_id' => $tarea->id,
            'title'          => $datos['title'],
            'done'           => isset($datos['done']) ? (bool) $datos['done'] : false,
            'sort_order'     => $ultimo === null ? 0 : ((int) $ultimo + 1),
        ]);

        $tarea->touch();

        return $this->recargar($tarea);
    }

    /* ------------------------------------------------------------------------------------------
     | Repetidas
     |----------------------------------------------------------------------------------------- */

    /**
     * Las fechas (Y-m-d) en que cae una tarea dentro de un rango, ambos extremos incluidos.
     *
     * Es LA definición de la expansión. La ocurrencia k se calcula SIEMPRE desde la fecha base
     * (nunca desde la ocurrencia anterior): diaria +k días, semanal +7k días, mensual
     * `addMonthsNoOverflow(k)`. Así una mensual del 31 cae el 30 en noviembre, el 28 en febrero y
     * vuelve al 31 en marzo, en vez de quedarse pegada en el 28 para siempre. Corta en
     * `repeat_until` inclusive. Una tarea no repetida devuelve su fecha si cae en el rango.
     *
     * @param AgendaTask $tarea La tarea (con `date`).
     * @param string     $desde Primer día del rango (Y-m-d).
     * @param string     $hasta Último día del rango (Y-m-d).
     *
     * @return array<int, string> Fechas en orden cronológico.
     */
    public function ocurrencias_en_rango(AgendaTask $tarea, string $desde, string $hasta): array
    {
        if ($tarea->date === null) {
            return [];
        }

        $base   = $this->dia((string) $tarea->date);
        $inicio = $this->dia($desde);
        $fin    = $this->dia($hasta);

        if ($tarea->repeat_until !== null && $tarea->is_recurring()) {
            $limite = $this->dia((string) $tarea->repeat_until);
            if ($limite->lt($fin)) {
                $fin = $limite;
            }
        }

        if ($fin->lt($base) || $fin->lt($inicio)) {
            return [];
        }

        if (! $tarea->is_recurring()) {
            return $base->gte($inicio) ? [$base->toDateString()] : [];
        }

        $fechas = [];
        $k      = $this->primer_indice((string) $tarea->repeat, $base, $inicio);

        for ($vueltas = 0; $vueltas < self::TOPE_OCURRENCIAS; $vueltas++, $k++) {
            $fecha = $this->fecha_de_ocurrencia((string) $tarea->repeat, $base, $k);

            if ($fecha->gt($fin)) {
                break;
            }

            if ($fecha->gte($inicio)) {
                $fechas[] = $fecha->toDateString();
            }
        }

        return $fechas;
    }

    /**
     * Si una fecha es una ocurrencia real de la tarea. Usa la misma expansión que el listado.
     *
     * @param AgendaTask $tarea La tarea.
     * @param string     $fecha Fecha Y-m-d.
     *
     * @return bool
     */
    public function es_ocurrencia(AgendaTask $tarea, string $fecha): bool
    {
        return in_array($fecha, $this->ocurrencias_en_rango($tarea, $fecha, $fecha), true);
    }

    /* ------------------------------------------------------------------------------------------
     | Serialización
     |----------------------------------------------------------------------------------------- */

    /**
     * Una tarea SIN expandir: `occurrence_date = date` y, si se repite, hecha/no hecha es el de la
     * ocurrencia de su fecha base.
     *
     * @param AgendaTask $tarea La tarea (idealmente con `subtasks` cargados).
     *
     * @return array
     */
    public function serializar(AgendaTask $tarea): array
    {
        if ($tarea->is_recurring() && $tarea->date !== null) {
            $hecha = AgendaTaskCompletion::where('agenda_task_id', $tarea->id)
                ->where('occurrence_date', (string) $tarea->date)
                ->orderBy('id')
                ->first();

            return $this->serializar_ocurrencia($tarea, (string) $tarea->date, $hecha !== null, $hecha !== null ? $hecha->completed_at : null);
        }

        return $this->serializar_ocurrencia(
            $tarea,
            $tarea->date !== null ? (string) $tarea->date : null,
            $tarea->completed_at !== null,
            $tarea->completed_at
        );
    }

    /**
     * LA forma de una tarea en las respuestas, igual en el panel y en Claude.
     *
     * - En la bandeja `date`, `occurrence_date`, `start_time` y `end_time` van en null.
     * - `end_time` = `start_time` + `duration_minutes`; si pasa de medianoche se informa la hora
     *   igual ("00:15"). En una de todo el día, `start_time` y `end_time` van en null.
     * - En una ocurrencia de una repetida, `occurrence_date` es la de ESA ocurrencia, `date` la
     *   base de la serie, y `completed`/`completed_at` los de esa ocurrencia.
     * - Las fechas con hora (`completed_at`, `created_at`, `updated_at`) van en ISO 8601 con la
     *   zona de Buenos Aires.
     *
     * @param AgendaTask         $tarea           La tarea.
     * @param string|null        $occurrence_date Fecha de la ocurrencia (null en la bandeja).
     * @param bool               $completed       Si esa ocurrencia está hecha.
     * @param \DateTimeInterface|null $completed_at Cuándo se marcó hecha.
     *
     * @return array
     */
    public function serializar_ocurrencia(AgendaTask $tarea, ?string $occurrence_date, bool $completed, $completed_at): array
    {
        $en_bandeja = $tarea->date === null;
        $todo_el_dia = (bool) $tarea->all_day;

        $start_time = null;
        $end_time   = null;

        if (! $en_bandeja && ! $todo_el_dia && $tarea->start_time !== null) {
            $start_time = substr((string) $tarea->start_time, 0, 5);
            $end_time   = $this->hora_de_fin($start_time, (int) $tarea->duration_minutes);
        }

        $subtasks = [];
        $hechos   = 0;
        foreach ($tarea->subtasks as $subpaso) {
            $subtasks[] = [
                'id'         => (int) $subpaso->id,
                'title'      => (string) $subpaso->title,
                'done'       => (bool) $subpaso->done,
                'sort_order' => (int) $subpaso->sort_order,
            ];
            if ($subpaso->done) {
                $hechos++;
            }
        }

        return [
            'id'               => (int) $tarea->id,
            'admin_id'         => (int) $tarea->admin_id,
            'title'            => (string) $tarea->title,
            'notes'            => $tarea->notes,
            'date'             => $en_bandeja ? null : (string) $tarea->date,
            'occurrence_date'  => $en_bandeja ? null : $occurrence_date,
            'start_time'       => $start_time,
            'end_time'         => $end_time,
            'all_day'          => $todo_el_dia,
            'duration_minutes' => (int) $tarea->duration_minutes,
            'color'            => (string) $tarea->color,
            'icon'             => (string) $tarea->icon,
            'repeat'           => (string) $tarea->repeat,
            'repeat_until'     => $tarea->repeat_until !== null ? (string) $tarea->repeat_until : null,
            'is_recurring'     => $tarea->is_recurring(),
            'completed'        => $completed,
            'completed_at'     => $completed ? $this->fecha_hora($completed_at) : null,
            'subtasks'         => $subtasks,
            'subtasks_done'    => $hechos,
            'subtasks_total'   => count($subtasks),
            'sort_order'       => (int) $tarea->sort_order,
            'created_via'      => (string) $tarea->created_via,
            'created_at'       => $this->fecha_hora($tarea->created_at),
            'updated_at'       => $this->fecha_hora($tarea->updated_at),
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Internos
     |----------------------------------------------------------------------------------------- */

    /**
     * Marca o desmarca una tarea (o una ocurrencia). Lo comparten completar() y descompletar().
     *
     * - Repetida: `date` es obligatoria y tiene que ser una ocurrencia real (misma expansión que el
     *   listado). Marcar crea la fila de la ocurrencia si no estaba; desmarcar borra la(s) fila(s).
     *   Solo se toca ESA ocurrencia: las demás de la serie quedan como estaban.
     * - No repetida: `date` es opcional; si vino y la tarea tiene fecha, tiene que ser esa (para no
     *   marcar "otra" tarea por error). Marcar pone `completed_at` si estaba vacío; desmarcar lo
     *   limpia.
     *
     * @param AgendaTask $tarea   La tarea.
     * @param array      $input   Body del request.
     * @param bool       $hecha   true para marcar, false para desmarcar.
     *
     * @return array La tarea en forma de ocurrencia.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function marcar(AgendaTask $tarea, array $input, bool $hecha): array
    {
        $fecha = $this->validator->validar_fecha_de_ocurrencia($input, $tarea->is_recurring());

        if ($tarea->is_recurring()) {
            if (! $this->es_ocurrencia($tarea, $fecha)) {
                $this->validator->fallar(['date' => [
                    'La tarea «' . $tarea->title . '» no cae el ' . $fecha . ': esa fecha no es una de sus repeticiones.',
                ]]);
            }

            $existente = AgendaTaskCompletion::where('agenda_task_id', $tarea->id)
                ->where('occurrence_date', $fecha)
                ->orderBy('id')
                ->first();

            if ($hecha && $existente === null) {
                $existente = AgendaTaskCompletion::create([
                    'agenda_task_id'  => $tarea->id,
                    'occurrence_date' => $fecha,
                    'completed_at'    => AppTime::now(self::ZONA),
                ]);
            } elseif (! $hecha && $existente !== null) {
                AgendaTaskCompletion::where('agenda_task_id', $tarea->id)->where('occurrence_date', $fecha)->delete();
                $existente = null;
            }

            $tarea = $this->recargar($tarea);

            return $this->serializar_ocurrencia($tarea, $fecha, $hecha, $existente !== null ? $existente->completed_at : null);
        }

        if ($fecha !== null && $tarea->date !== null && $fecha !== (string) $tarea->date) {
            $this->validator->fallar(['date' => [
                'La tarea «' . $tarea->title . '» es del ' . $tarea->date . ', no del ' . $fecha . '.',
            ]]);
        }

        if ($hecha && $tarea->completed_at === null) {
            $tarea->completed_at = AppTime::now(self::ZONA);
            $tarea->save();
        } elseif (! $hecha && $tarea->completed_at !== null) {
            $tarea->completed_at = null;
            $tarea->save();
        }

        return $this->serializar($this->recargar($tarea));
    }

    /**
     * Las ocurrencias hechas de las repetidas de un rango, en UNA consulta, indexadas por
     * "id|fecha". Si hubiera dos filas para la misma ocurrencia (doble clic), gana la primera.
     *
     * @param \Illuminate\Support\Collection $tareas Tareas candidatas del rango.
     * @param string                         $desde  Primer día (Y-m-d).
     * @param string                         $hasta  Último día (Y-m-d).
     *
     * @return array<string, AgendaTaskCompletion>
     */
    private function hechas_del_rango($tareas, string $desde, string $hasta): array
    {
        $ids = $tareas->filter(function ($tarea) {
            return $tarea->is_recurring();
        })->pluck('id')->all();

        if (empty($ids)) {
            return [];
        }

        $indice = [];

        $filas = AgendaTaskCompletion::whereIn('agenda_task_id', $ids)
            ->whereBetween('occurrence_date', [$desde, $hasta])
            ->orderBy('id')
            ->get();

        foreach ($filas as $fila) {
            $clave = $fila->agenda_task_id . '|' . $fila->occurrence_date;
            if (! isset($indice[$clave])) {
                $indice[$clave] = $fila;
            }
        }

        return $indice;
    }

    /**
     * Primer índice k desde el que conviene empezar a expandir para llegar al rango sin recorrer
     * toda la historia de la serie. Puede quedar un paso antes del rango: el bucle lo saltea.
     *
     * @param string $repeat daily | weekly | monthly.
     * @param Carbon $base   Fecha base de la serie (UTC, inicio del día).
     * @param Carbon $inicio Primer día del rango (UTC, inicio del día).
     *
     * @return int
     */
    private function primer_indice(string $repeat, Carbon $base, Carbon $inicio): int
    {
        if ($inicio->lte($base)) {
            return 0;
        }

        $dias = (int) $base->diffInDays($inicio);

        switch ($repeat) {
            case 'daily':
                return $dias;
            case 'weekly':
                return intdiv($dias, 7);
            case 'monthly':
                // Un mes antes de la cuenta "calendario", para no saltearse el mes del borde.
                return max(0, ($inicio->year - $base->year) * 12 + ($inicio->month - $base->month) - 1);
        }

        return 0;
    }

    /**
     * La fecha de la ocurrencia k de una serie, calculada desde la base.
     *
     * @param string $repeat daily | weekly | monthly.
     * @param Carbon $base   Fecha base (UTC, inicio del día).
     * @param int    $k      Número de ocurrencia (0 = la base).
     *
     * @return Carbon
     */
    private function fecha_de_ocurrencia(string $repeat, Carbon $base, int $k): Carbon
    {
        switch ($repeat) {
            case 'daily':
                return $base->copy()->addDays($k);
            case 'weekly':
                return $base->copy()->addDays(7 * $k);
            case 'monthly':
                return $base->copy()->addMonthsNoOverflow($k);
        }

        return $base->copy();
    }

    /**
     * Un día Y-m-d como Carbon en UTC al inicio del día (para cuentas de días sin horarios).
     *
     * @param string $fecha Fecha Y-m-d.
     *
     * @return Carbon
     */
    private function dia(string $fecha): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', substr($fecha, 0, 10), 'UTC')->startOfDay();
    }

    /**
     * La hora de fin (H:i) a partir de la de inicio y la duración, dando la vuelta a medianoche.
     *
     * @param string $inicio   Hora de inicio H:i.
     * @param int    $duracion Duración en minutos.
     *
     * @return string Hora de fin H:i.
     */
    private function hora_de_fin(string $inicio, int $duracion): string
    {
        list($horas, $minutos) = array_map('intval', explode(':', $inicio));

        $total = ($horas * 60 + $minutos + $duracion) % 1440;

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /**
     * Fecha y hora en ISO 8601 con la zona de Buenos Aires, o null.
     *
     * @param mixed $valor Un DateTimeInterface, un string de MySQL o null.
     *
     * @return string|null
     */
    private function fecha_hora($valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $carbon = $valor instanceof \DateTimeInterface
            ? Carbon::instance($valor)
            : Carbon::parse((string) $valor, self::ZONA);

        return $carbon->setTimezone(self::ZONA)->toIso8601String();
    }

    /**
     * El estado actual de una tarea con las mismas claves que {@see POR_DEFECTO}, para que el
     * validador aplique las reglas entre campos sobre "cómo queda" después del cambio.
     *
     * @param AgendaTask $tarea La tarea.
     *
     * @return array<string, mixed>
     */
    private function estado_de(AgendaTask $tarea): array
    {
        return [
            'title'            => (string) $tarea->title,
            'notes'            => $tarea->notes,
            'date'             => $tarea->date !== null ? (string) $tarea->date : null,
            'start_time'       => $tarea->start_time !== null ? (string) $tarea->start_time : null,
            'all_day'          => (bool) $tarea->all_day,
            'duration_minutes' => (int) $tarea->duration_minutes,
            'color'            => (string) $tarea->color,
            'icon'             => (string) $tarea->icon,
            'repeat'           => (string) $tarea->repeat,
            'repeat_until'     => $tarea->repeat_until !== null ? (string) $tarea->repeat_until : null,
        ];
    }

    /**
     * Las columnas que se guardan de un estado resuelto (deja afuera `sort_order` y lo que no es
     * columna, que se manejan aparte).
     *
     * @param array $estado Estado resuelto por el validador.
     *
     * @return array<string, mixed>
     */
    private function columnas_de_estado(array $estado): array
    {
        return array_intersect_key($estado, self::POR_DEFECTO);
    }

    /**
     * El `sort_order` que le toca a una tarea que va al final del orden del admin.
     *
     * @param int $admin_id Dueño.
     *
     * @return int
     */
    private function siguiente_orden(int $admin_id): int
    {
        $maximo = AgendaTask::where('admin_id', $admin_id)->max('sort_order');

        return $maximo === null ? 0 : ((int) $maximo + 1);
    }

    /**
     * Crea subpasos nuevos al final de una tarea, en el orden en que vinieron.
     *
     * @param AgendaTask $tarea    La tarea dueña.
     * @param array      $subpasos Lista normalizada `{id, title, done}` (el `id` se ignora).
     *
     * @return void
     */
    private function crear_subpasos(AgendaTask $tarea, array $subpasos): void
    {
        foreach (array_values($subpasos) as $orden => $subpaso) {
            AgendaSubtask::create([
                'agenda_task_id' => $tarea->id,
                'title'          => $subpaso['title'],
                'done'           => (bool) $subpaso['done'],
                'sort_order'     => $orden,
            ]);
        }
    }

    /**
     * Reemplaza el conjunto de subpasos de una tarea por la lista que vino.
     *
     * Con `id` se actualiza ese subpaso (tiene que ser de esta tarea, si no 422), sin `id` se crea,
     * y los que no vinieron se borran. El orden pasa a ser el de la lista.
     *
     * @param AgendaTask $tarea    La tarea dueña.
     * @param array      $subpasos Lista normalizada `{id, title, done}`.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException 422 si algún `id` no es de esta tarea.
     */
    private function reemplazar_subpasos(AgendaTask $tarea, array $subpasos): void
    {
        $actuales = AgendaSubtask::where('agenda_task_id', $tarea->id)->get()->keyBy('id');

        $errores = [];
        foreach (array_values($subpasos) as $indice => $subpaso) {
            if ($subpaso['id'] !== null && ! $actuales->has($subpaso['id'])) {
                $errores['subtasks.' . $indice . '.id'][] = 'El subpaso ' . ($indice + 1) . ' (id ' . $subpaso['id'] . ') no es de esta tarea.';
            }
        }
        if (! empty($errores)) {
            $this->validator->fallar($errores);
        }

        $conservados = [];

        foreach (array_values($subpasos) as $orden => $subpaso) {
            if ($subpaso['id'] !== null) {
                $fila = $actuales->get($subpaso['id']);
                $fila->title      = $subpaso['title'];
                $fila->done       = (bool) $subpaso['done'];
                $fila->sort_order = $orden;
                $fila->save();
                $conservados[] = (int) $fila->id;
                continue;
            }

            $nuevo = AgendaSubtask::create([
                'agenda_task_id' => $tarea->id,
                'title'          => $subpaso['title'],
                'done'           => (bool) $subpaso['done'],
                'sort_order'     => $orden,
            ]);
            $conservados[] = (int) $nuevo->id;
        }

        AgendaSubtask::where('agenda_task_id', $tarea->id)
            ->when(! empty($conservados), function ($q) use ($conservados) {
                $q->whereNotIn('id', $conservados);
            })
            ->delete();

        $tarea->touch();
    }

    /**
     * Vuelve a leer una tarea de la base con sus subpasos (para responder con lo que quedó guardado).
     *
     * @param AgendaTask $tarea La tarea.
     *
     * @return AgendaTask
     */
    private function recargar(AgendaTask $tarea): AgendaTask
    {
        return AgendaTask::with('subtasks')->findOrFail($tarea->id);
    }
}

<?php

namespace App\Services\Agenda;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * LA definición de validación de la Agenda (misión agenda-admin, 31/10/2026).
 *
 * La usan el panel (`Api\AgendaController`) y Claude (`Api\ClaudeAgendaController`) a través de
 * {@see AgendaService}: no hay dos juegos de reglas que se puedan desincronizar. Valida en dos
 * pisos:
 *
 *  1. **Campo por campo** ({@see validar_campos()}): formato y rango de cada dato que vino.
 *  2. **Entre campos, sobre el estado final** ({@see resolver_estado()}): lo que depende de cómo
 *     queda la tarea después del cambio (hora sin fecha, repetición sin fecha, repetición que
 *     termina antes de empezar) y las limpiezas que el contrato manda (pasar a la bandeja limpia la
 *     hora y la repetición; "todo el día" limpia la hora; una fecha sin hora arranca a las 09:00).
 *
 * Todo error sale como 422 con la forma de Laravel (`message` + `errors` por campo), pero con los
 * mensajes en criollo y el `message` igual al primer error (Laravel 8 pone "The given data was
 * invalid.", que no le dice nada ni a Lucas ni a Claude).
 */
class AgendaValidator
{
    /**
     * Paleta de colores de la Agenda: clave => hexadecimal. Cualquier otra clave es 422.
     * La misma paleta vive en el SPA (`agenda_utils.js`); acá manda la lista de claves.
     */
    const PALETA = [
        'salmon'  => '#f88e87',
        'orange'  => '#f5a05a',
        'yellow'  => '#f7c948',
        'green'   => '#7cc35c',
        'sky'     => '#7fb2e5',
        'emerald' => '#2e9e6b',
        'red'     => '#e5534b',
        'blue'    => '#2f7fe0',
        'white'   => '#f2f2f2',
    ];

    /** Repeticiones posibles de una tarea. */
    const REPETICIONES = ['none', 'daily', 'weekly', 'monthly'];

    /** Tope del rango de un listado (hasta ≤ desde + 100 días). */
    const MAX_DIAS_RANGO = 100;

    /** Días que abarca el listado de Claude cuando no se manda `hasta` (o `desde`). */
    const DIAS_RANGO_POR_DEFECTO = 7;

    /** Tope de subpasos por tarea: más que esto ya no es un paso a paso, es otra tarea. */
    const MAX_SUBPASOS = 100;

    /**
     * Tope de las notas en BYTES. La columna es TEXT (65.535 bytes, no caracteres): 20.000 emoji
     * pasan un `max:20000` de caracteres y revientan el INSERT con un 500. Ya pasó en
     * `PATCH claude/leads/{id}` (ver AgendaDeDemoPorClaudeTest), por eso se mide en bytes.
     */
    const MAX_BYTES_NOTAS = 65535;

    /** Fechas aceptadas: fuera de este rango es casi seguro un error de tipeo. */
    const FECHA_MINIMA = '2000-01-01';
    const FECHA_MAXIMA = '2100-12-31';

    /** Hora con la que arranca una tarea con fecha que llega sin hora y no es de todo el día. */
    const HORA_POR_DEFECTO = '09:00:00';

    /** Campos de la tarea que se pueden mandar (fuera de `subtasks`). */
    const CAMPOS_DE_TAREA = [
        'title', 'notes', 'date', 'start_time', 'all_day', 'duration_minutes',
        'color', 'icon', 'repeat', 'repeat_until', 'sort_order',
    ];

    /**
     * Valida los campos de una tarea que vinieron en el request, uno por uno.
     *
     * Solo devuelve los campos PRESENTES (la edición es parcial): un campo ausente no se toca, un
     * campo presente en null se limpia. `subtasks` vuelve normalizado como lista de
     * `{id, title, done}` (ver {@see normalizar_subpasos()}).
     *
     * @param array $input   El body del request.
     * @param bool  $es_alta true en el alta (el título es obligatorio y los `id` de subpasos se ignoran).
     *
     * @return array<string, mixed> Los campos presentes, normalizados (hora en H:i:s, textos recortados).
     *
     * @throws ValidationException 422 con los errores por campo.
     */
    public function validar_campos(array $input, bool $es_alta): array
    {
        $reglas = [
            'title'            => ($es_alta ? 'required' : 'sometimes|required') . '|string|max:500',
            'notes'            => [
                'sometimes', 'nullable', 'string', 'max:20000',
                function ($atributo, $valor, $fallar) {
                    if (is_string($valor) && strlen($valor) > self::MAX_BYTES_NOTAS) {
                        $fallar('Las notas son demasiado largas para guardarse (pasan de 64 KB): acortalas.');
                    }
                },
            ],
            'date'             => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:' . self::FECHA_MINIMA . '|before_or_equal:' . self::FECHA_MAXIMA,
            'start_time'       => 'sometimes|nullable|date_format:H:i',
            'all_day'          => 'sometimes|boolean',
            'duration_minutes' => 'sometimes|integer|between:5,1440',
            'color'            => 'sometimes|string|in:' . implode(',', array_keys(self::PALETA)),
            'icon'             => 'sometimes|string|max:60|regex:/^[a-z0-9-]+$/',
            'repeat'           => 'sometimes|nullable|string|in:' . implode(',', self::REPETICIONES),
            'repeat_until'     => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:' . self::FECHA_MINIMA . '|before_or_equal:' . self::FECHA_MAXIMA,
            'sort_order'       => 'sometimes|integer|between:-1000000,1000000',
            'subtasks'         => 'sometimes|nullable|array|max:' . self::MAX_SUBPASOS,
        ];

        $this->correr($input, $reglas);

        $datos = [];

        foreach (self::CAMPOS_DE_TAREA as $campo) {
            if (! array_key_exists($campo, $input)) {
                continue;
            }

            $datos[$campo] = $this->normalizar_campo($campo, $input[$campo]);
        }

        if (array_key_exists('subtasks', $input) && $input['subtasks'] !== null) {
            $datos['subtasks'] = $this->normalizar_subpasos($input['subtasks'], ! $es_alta);
        }

        return $datos;
    }

    /**
     * Aplica las reglas ENTRE campos sobre el estado final de la tarea y devuelve ese estado.
     *
     * Las reglas son las del contrato del plan, en este orden:
     *  - Sin fecha (bandeja): una `start_time` mandada explícitamente es 422, una repetición
     *    distinta de `none` mandada explícitamente es 422; lo demás se limpia solo (hora, todo el
     *    día, repetición y fin de la repetición), que es lo que hace "Mover a la bandeja".
     *  - Con fecha: una `start_time` mandada sin `all_day` saca la tarea de "todo el día"; si es de
     *    todo el día la hora se limpia; si no lo es y no tiene hora, arranca a las 09:00.
     *  - Repetición: `none` limpia el fin; otra repetición con un fin anterior a la fecha es 422.
     *
     * @param array $actual  Estado actual de la tarea (o los valores por defecto, en el alta).
     * @param array $cambios Campos presentes del request, ya validados por {@see validar_campos()}.
     *
     * @return array<string, mixed> Estado final, listo para guardar (sin `subtasks`).
     *
     * @throws ValidationException 422 si el estado final no es coherente.
     */
    public function resolver_estado(array $actual, array $cambios): array
    {
        unset($cambios['subtasks']);

        $final   = array_merge($actual, $cambios);
        $errores = [];

        $hora_explicita = array_key_exists('start_time', $cambios) && $cambios['start_time'] !== null;

        if ($final['date'] === null) {
            /* La bandeja no tiene hora ni repetición. Lo que vino EXPLÍCITO y contradice eso es un
               error de quien llama (no se adivina qué quiso); lo que quedó del estado anterior se
               limpia, porque mandar `date: null` es exactamente "mover a la bandeja". */
            if ($hora_explicita) {
                $errores['start_time'][] = 'No se puede poner una hora sin fecha: mandá también «date» (AAAA-MM-DD).';
            }

            if (array_key_exists('repeat', $cambios) && $cambios['repeat'] !== 'none') {
                $errores['repeat'][] = 'Una tarea que se repite necesita una fecha de inicio: mandá también «date».';
            }

            $final['start_time']   = null;
            $final['all_day']      = false;
            $final['repeat']       = 'none';
            $final['repeat_until'] = null;
        } else {
            /* Mandar una hora sin decir nada de "todo el día" es pedir esa hora: la tarea deja de
               ser de todo el día. Si además vino `all_day: true`, gana `all_day` y la hora se
               limpia, que es lo que dice el contrato. */
            if ($hora_explicita && ! array_key_exists('all_day', $cambios)) {
                $final['all_day'] = false;
            }

            if ($final['all_day']) {
                $final['start_time'] = null;
            } elseif ($final['start_time'] === null) {
                $final['start_time'] = self::HORA_POR_DEFECTO;
            }

            if ($final['repeat'] === 'none') {
                $final['repeat_until'] = null;
            } elseif ($final['repeat_until'] !== null && $final['repeat_until'] < $final['date']) {
                // Fechas Y-m-d: la comparación de strings es la cronológica.
                $errores['repeat_until'][] = 'La repetición no puede terminar («repeat_until») antes de la fecha de la tarea («date»).';
            }
        }

        if (! empty($errores)) {
            $this->fallar($errores);
        }

        return $final;
    }

    /**
     * Valida el rango de un listado y lo completa si es opcional.
     *
     * En el panel (`obligatorio = true`) `desde` y `hasta` son obligatorios. En Claude son
     * opcionales: sin ninguno es hoy..hoy+7; con uno solo, el otro queda a 7 días de distancia.
     * En los dos casos `hasta` no puede ser anterior a `desde` ni estar a más de 100 días.
     *
     * `incluir_completadas` se parsea a mano y no con la regla `boolean` de Laravel porque llega
     * por query string, y ahí `"true"` / `"false"` son texto que esa regla rechaza.
     *
     * @param array  $input       Los parámetros del request.
     * @param bool   $obligatorio Si `desde` y `hasta` son obligatorios.
     * @param string $hoy         El día de hoy (Y-m-d), del reloj del sistema.
     *
     * @return array{0: string, 1: string, 2: bool} [desde, hasta, incluir_completadas].
     *
     * @throws ValidationException 422 con el campo que falla.
     */
    public function validar_rango(array $input, bool $obligatorio, string $hoy): array
    {
        $presencia = $obligatorio ? 'required' : 'sometimes|nullable';
        $formato   = '|date_format:Y-m-d|after_or_equal:' . self::FECHA_MINIMA . '|before_or_equal:' . self::FECHA_MAXIMA;

        $this->correr($input, [
            'desde' => $presencia . $formato,
            'hasta' => $presencia . $formato,
        ]);

        $incluir_completadas = true;
        if (array_key_exists('incluir_completadas', $input) && $input['incluir_completadas'] !== null) {
            $incluir_completadas = $this->a_booleano($input['incluir_completadas']);
            if ($incluir_completadas === null) {
                $this->fallar(['incluir_completadas' => ['«incluir_completadas» tiene que ser verdadero o falso (true/false o 1/0).']]);
            }
        }

        $desde = isset($input['desde']) ? (string) $input['desde'] : null;
        $hasta = isset($input['hasta']) ? (string) $input['hasta'] : null;

        // Solo en el modo opcional (Claude) puede faltar alguno de los dos.
        if ($desde === null && $hasta === null) {
            $desde = $hoy;
        }
        if ($hasta === null) {
            $hasta = $this->sumar_dias($desde, self::DIAS_RANGO_POR_DEFECTO);
        }
        if ($desde === null) {
            $desde = $this->sumar_dias($hasta, -self::DIAS_RANGO_POR_DEFECTO);
        }

        if ($hasta < $desde) {
            $this->fallar(['hasta' => ['La fecha «hasta» no puede ser anterior a «desde».']]);
        }

        if ($hasta > $this->sumar_dias($desde, self::MAX_DIAS_RANGO)) {
            $this->fallar(['hasta' => ['El rango no puede pasar de ' . self::MAX_DIAS_RANGO . ' días: pedilo en tramos.']]);
        }

        return [$desde, $hasta, $incluir_completadas];
    }

    /**
     * Valida la fecha de la ocurrencia que se quiere marcar o desmarcar.
     *
     * Solo valida el formato y, si la tarea se repite, que haya venido. Que la fecha sea una
     * ocurrencia REAL de la serie lo decide el servicio, que es el que sabe expandirla.
     *
     * @param array $input       El body del request.
     * @param bool  $obligatoria true si la tarea se repite.
     *
     * @return string|null La fecha (Y-m-d) o null si no vino.
     *
     * @throws ValidationException 422 si falta (siendo obligatoria) o tiene mal formato.
     */
    public function validar_fecha_de_ocurrencia(array $input, bool $obligatoria): ?string
    {
        $this->correr($input, [
            'date' => ($obligatoria ? 'required' : 'sometimes|nullable') . '|date_format:Y-m-d',
        ], [
            'date.required' => 'Esta tarea se repite: mandá en «date» qué día querés marcar (AAAA-MM-DD).',
        ]);

        return isset($input['date']) ? (string) $input['date'] : null;
    }

    /**
     * Valida los datos de UN subpaso (edición de uno suelto, o alta de uno nuevo desde Claude).
     *
     * @param array $input   El body del request.
     * @param bool  $es_alta true en el alta (el título es obligatorio).
     *
     * @return array<string, mixed> Los campos presentes (`title` recortado, `done` booleano).
     *
     * @throws ValidationException 422 con los errores por campo.
     */
    public function validar_subpaso(array $input, bool $es_alta): array
    {
        $this->correr($input, [
            'title' => ($es_alta ? 'required' : 'sometimes|required') . '|string|max:500',
            'done'  => 'sometimes|boolean',
        ], [
            'title.required' => 'El subpaso necesita un texto.',
            'title.string'   => 'El texto del subpaso tiene que ser texto.',
            'title.max'      => 'El texto del subpaso no puede pasar de 500 caracteres.',
            'done.boolean'   => '«done» tiene que ser verdadero o falso.',
        ]);

        $datos = [];

        if (array_key_exists('title', $input)) {
            $datos['title'] = trim((string) $input['title']);
        }

        if (array_key_exists('done', $input)) {
            $datos['done'] = (bool) $this->a_booleano($input['done']);
        }

        return $datos;
    }

    /**
     * Normaliza la lista de subpasos que vino en el alta o en la edición de una tarea.
     *
     * Acepta cada ítem como string (un subpaso nuevo sin tildar, que es como los manda Claude) o
     * como `{id?, title, done?}` (como los manda el panel). Con `$permitir_ids` (edición) el `id`
     * se conserva para actualizar ese subpaso; en el alta se ignora, porque todo subpaso es nuevo.
     * Que el `id` sea de ESTA tarea lo verifica el servicio, que tiene la tarea a mano.
     *
     * @param mixed $lista        Lo que vino en `subtasks` (ya se sabe que es un array).
     * @param bool  $permitir_ids Si se conservan los `id` (edición) o se ignoran (alta).
     *
     * @return array<int, array{id: int|null, title: string, done: bool}>
     *
     * @throws ValidationException 422 con el índice del subpaso que falla.
     */
    public function normalizar_subpasos($lista, bool $permitir_ids): array
    {
        $errores   = [];
        $subpasos  = [];

        foreach (array_values((array) $lista) as $indice => $item) {
            $id    = null;
            $done  = false;
            $title = null;

            if (is_string($item)) {
                $title = trim($item);
            } elseif (is_array($item)) {
                $title = array_key_exists('title', $item) && is_string($item['title']) ? trim($item['title']) : null;

                if (array_key_exists('done', $item) && $item['done'] !== null) {
                    $done = $this->a_booleano($item['done']);
                    if ($done === null) {
                        $errores['subtasks.' . $indice . '.done'][] = 'El subpaso ' . ($indice + 1) . ': «done» tiene que ser verdadero o falso.';
                        $done = false;
                    }
                }

                if ($permitir_ids && array_key_exists('id', $item) && $item['id'] !== null) {
                    if (! is_numeric($item['id']) || (int) $item['id'] <= 0 || (string) (int) $item['id'] !== (string) $item['id']) {
                        $errores['subtasks.' . $indice . '.id'][] = 'El subpaso ' . ($indice + 1) . ': el «id» tiene que ser un número entero.';
                    } else {
                        $id = (int) $item['id'];
                    }
                }
            } else {
                $errores['subtasks.' . $indice][] = 'El subpaso ' . ($indice + 1) . ' tiene que ser un texto o un objeto {title, done}.';
                continue;
            }

            if ($title === null || $title === '') {
                $errores['subtasks.' . $indice . '.title'][] = 'El subpaso ' . ($indice + 1) . ' necesita un texto.';
            } elseif (mb_strlen($title) > 500) {
                $errores['subtasks.' . $indice . '.title'][] = 'El subpaso ' . ($indice + 1) . ' no puede pasar de 500 caracteres.';
            }

            $subpasos[] = ['id' => $id, 'title' => (string) $title, 'done' => (bool) $done];
        }

        if (! empty($errores)) {
            $this->fallar($errores);
        }

        return $subpasos;
    }

    /**
     * Corta con un 422 en el formato de Laravel (`message` + `errors`) y los textos en criollo.
     *
     * `message` es el primer error, con la cuenta de los que siguen. Se arma la respuesta a mano
     * (y no con `ValidationException::withMessages()`) porque en Laravel 8 esa vía deja el
     * `message` en inglés genérico.
     *
     * @param array<string, string|array<int, string>> $errores Errores por campo.
     * @param array<string, mixed>                     $extra   Claves adicionales para la respuesta
     *                                                          (por ejemplo, la lista de admins).
     *
     * @return void Nunca vuelve: siempre lanza.
     *
     * @throws ValidationException
     */
    public function fallar(array $errores, array $extra = []): void
    {
        $normalizados = [];
        $total        = 0;
        $primero      = null;

        foreach ($errores as $campo => $mensajes) {
            foreach ((array) $mensajes as $mensaje) {
                $normalizados[$campo][] = (string) $mensaje;
                $total++;
                if ($primero === null) {
                    $primero = (string) $mensaje;
                }
            }
        }

        $message = (string) $primero;
        if ($total > 1) {
            $message .= ' (y ' . ($total - 1) . ' error' . ($total - 1 > 1 ? 'es' : '') . ' más)';
        }

        // El validador hace falta solo para construir la excepción; el que manda es el response.
        $validador = Validator::make([], []);
        foreach ($normalizados as $campo => $mensajes) {
            foreach ($mensajes as $mensaje) {
                $validador->errors()->add($campo, $mensaje);
            }
        }

        throw new ValidationException(
            $validador,
            response()->json(array_merge(['message' => $message, 'errors' => $normalizados], $extra), 422)
        );
    }

    /**
     * Interpreta un valor booleano tolerante: true/false, 1/0, "1"/"0", "true"/"false",
     * "on"/"off", "yes"/"no".
     *
     * @param mixed $valor Lo que vino.
     *
     * @return bool|null El booleano, o null si no es interpretable como tal.
     */
    public function a_booleano($valor): ?bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (is_int($valor) && ($valor === 0 || $valor === 1)) {
            return $valor === 1;
        }

        if (is_string($valor)) {
            return filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    /**
     * Corre un validador de Laravel con los mensajes en criollo y corta con {@see fallar()} si falla.
     *
     * @param array $input    Datos a validar.
     * @param array $reglas   Reglas de Laravel.
     * @param array $mensajes Mensajes propios de esta validación (se suman a los comunes).
     *
     * @return void
     *
     * @throws ValidationException
     */
    private function correr(array $input, array $reglas, array $mensajes = []): void
    {
        $validador = Validator::make($input, $reglas, array_merge($this->mensajes_comunes(), $mensajes));

        if ($validador->fails()) {
            $this->fallar($validador->errors()->toArray());
        }
    }

    /**
     * Normaliza el valor de un campo de tarea ya validado al formato en que se guarda.
     *
     * @param string $campo Nombre del campo.
     * @param mixed  $valor Valor validado.
     *
     * @return mixed
     */
    private function normalizar_campo(string $campo, $valor)
    {
        switch ($campo) {
            case 'title':
                return trim((string) $valor);
            case 'notes':
                return $valor === null ? null : (string) $valor;
            case 'date':
            case 'repeat_until':
                return $valor === null ? null : (string) $valor;
            case 'start_time':
                // Llega H:i y se guarda H:i:s, que es lo que devuelve MySQL de una columna TIME.
                return $valor === null ? null : ((string) $valor . ':00');
            case 'all_day':
                return (bool) $this->a_booleano($valor);
            case 'duration_minutes':
            case 'sort_order':
                return (int) $valor;
            case 'repeat':
                // `repeat: null` se lee como "no se repite".
                return $valor === null ? 'none' : (string) $valor;
            default:
                return (string) $valor;
        }
    }

    /**
     * Suma (o resta) días a una fecha Y-m-d, en UTC para no depender de cambios de horario.
     *
     * @param string $fecha Fecha Y-m-d.
     * @param int    $dias  Días a sumar (negativo para restar).
     *
     * @return string Fecha Y-m-d resultante.
     */
    private function sumar_dias(string $fecha, int $dias): string
    {
        return Carbon::createFromFormat('Y-m-d', $fecha, 'UTC')->startOfDay()->addDays($dias)->toDateString();
    }

    /**
     * Mensajes en criollo para las reglas de los campos de una tarea y del rango.
     *
     * @return array<string, string>
     */
    private function mensajes_comunes(): array
    {
        $colores = implode(', ', array_keys(self::PALETA));

        return [
            'title.required'                => 'La tarea necesita un título.',
            'title.string'                  => 'El título tiene que ser texto.',
            'title.max'                     => 'El título no puede pasar de 500 caracteres.',
            'notes.string'                  => 'Las notas tienen que ser texto.',
            'notes.max'                     => 'Las notas no pueden pasar de 20.000 caracteres.',
            'date.date_format'              => 'La fecha tiene que venir como AAAA-MM-DD y ser un día que exista (por ejemplo 2026-10-31).',
            'date.after_or_equal'           => 'La fecha no puede ser anterior al año 2000.',
            'date.before_or_equal'          => 'La fecha no puede ser posterior al año 2100.',
            'start_time.date_format'        => 'La hora tiene que venir como HH:MM en 24 horas (por ejemplo 09:30).',
            'all_day.boolean'               => '«all_day» (todo el día) tiene que ser verdadero o falso.',
            'duration_minutes.integer'      => 'La duración tiene que ser un número entero de minutos.',
            'duration_minutes.between'      => 'La duración tiene que estar entre 5 minutos y 24 horas (1440 minutos).',
            'color.string'                  => 'El color tiene que ser una de estas claves: ' . $colores . '.',
            'color.in'                      => 'Ese color no está en la paleta. Las claves válidas son: ' . $colores . '.',
            'icon.string'                   => 'El ícono tiene que ser el nombre de un Bootstrap Icon (por ejemplo «airplane»).',
            'icon.max'                      => 'El nombre del ícono no puede pasar de 60 caracteres.',
            'icon.regex'                    => 'El ícono tiene que ser el nombre de un Bootstrap Icon sin «bi-»: solo minúsculas, números y guiones (por ejemplo «check2-square»).',
            'repeat.string'                 => 'La repetición tiene que ser una de estas: none, daily, weekly, monthly.',
            'repeat.in'                     => 'La repetición tiene que ser una de estas: none, daily, weekly, monthly.',
            'repeat_until.date_format'      => 'El fin de la repetición tiene que venir como AAAA-MM-DD y ser un día que exista.',
            'repeat_until.after_or_equal'   => 'El fin de la repetición no puede ser anterior al año 2000.',
            'repeat_until.before_or_equal'  => 'El fin de la repetición no puede ser posterior al año 2100.',
            'sort_order.integer'            => 'El orden tiene que ser un número entero.',
            'sort_order.between'            => 'El orden está fuera de rango.',
            'subtasks.array'                => 'Los subpasos tienen que venir como una lista.',
            'subtasks.max'                  => 'Una tarea no puede tener más de ' . self::MAX_SUBPASOS . ' subpasos.',
            'desde.required'                => 'Falta «desde»: el primer día del rango (AAAA-MM-DD).',
            'desde.date_format'             => '«desde» tiene que venir como AAAA-MM-DD y ser un día que exista.',
            'desde.after_or_equal'          => '«desde» no puede ser anterior al año 2000.',
            'desde.before_or_equal'         => '«desde» no puede ser posterior al año 2100.',
            'hasta.required'                => 'Falta «hasta»: el último día del rango (AAAA-MM-DD).',
            'hasta.date_format'             => '«hasta» tiene que venir como AAAA-MM-DD y ser un día que exista.',
            'hasta.after_or_equal'          => '«hasta» no puede ser anterior al año 2000.',
            'hasta.before_or_equal'         => '«hasta» no puede ser posterior al año 2100.',
            'date.required'                 => 'Falta la fecha («date», AAAA-MM-DD).',
        ];
    }
}

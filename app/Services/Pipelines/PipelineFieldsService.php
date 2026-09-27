<?php

namespace App\Services\Pipelines;

use App\Models\PipelineStage;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Los campos que una etapa pide al entrar (misión pipelines-crm, 27/9/2026): la definición
 * (`pipeline_stages.fields`) y los valores que se cargan al mover una oportunidad.
 *
 * 🔴 ES EL ÚNICO QUE DECIDE QUÉ ES VÁLIDO, EN LOS DOS LADOS DEL MÓDULO:
 *  - qué es una definición válida (al crear / editar una etapa, y en el seeder);
 *  - qué es un valor válido y qué es "obligatorio" (al mover).
 * La SPA pinta el formulario desde la definición que le da la API y muestra los 422 al lado de cada
 * campo, pero NO decide qué es obligatorio: si lo decidiera también, algún día decidiría distinto
 * (clase "el mismo invariante decidido con dos criterios distintos en front y back",
 * APRENDER_NO_PARCHEAR.md).
 *
 * Formatos que manda la SPA (los mismos que se validan acá, sin ISO con `Z`):
 *  - date     → "2026-09-30"        (Y-m-d)
 *  - datetime → "2026-09-30 15:00"  (Y-m-d H:i)
 */
class PipelineFieldsService
{
    /**
     * Tipos de campo con su etiqueta. Única definición: la publica `GET pipelines/meta` y la SPA no
     * la escribe de nuevo.
     *
     * @var array<string, string>
     */
    const FIELD_TYPE_LABELS = [
        'text'     => 'Texto',
        'textarea' => 'Texto largo',
        'date'     => 'Fecha',
        'datetime' => 'Fecha y hora',
        'select'   => 'Lista',
        'number'   => 'Número',
        'boolean'  => 'Sí/No',
    ];

    /** Tipos que pueden completar la próxima acción (campo "agenda"). */
    const TIPOS_AGENDA = ['date', 'datetime'];

    /** Máximo de campos por etapa. */
    const MAX_CAMPOS = 20;

    /** Largo máximo de la etiqueta de un campo. */
    const MAX_LABEL = 80;

    /** Largo máximo de la clave de un campo. */
    const MAX_KEY = 40;

    /** Formato de la clave: minúsculas, números y guiones bajos, empezando con letra, hasta 40. */
    const REGEX_KEY = '/^[a-z][a-z0-9_]{0,39}$/';

    /** Opciones de un campo lista: mínimo 1, máximo 30, cada una hasta 80 caracteres. */
    const MAX_OPCIONES = 30;
    const MAX_OPCION   = 80;

    /** Largos máximos de los valores de texto. */
    const MAX_TEXTO       = 255;
    const MAX_TEXTO_LARGO = 5000;

    /** Formato de un valor `date`. */
    const FORMATO_FECHA = 'Y-m-d';

    /** Formato de un valor `datetime`. */
    const FORMATO_FECHA_HORA = 'Y-m-d H:i';

    /* ------------------------------------------------------------------------------------------
     | La definición
     |----------------------------------------------------------------------------------------- */

    /**
     * Valida y normaliza la definición de campos de una etapa.
     *
     * Devuelve cada campo con las seis claves siempre presentes y en este orden:
     * `{key, label, type, required, agenda, options}`. Las claves que no vinieron se generan desde
     * la etiqueta (`Str::slug($label, '_')`, recortada a 40, con `_2`, `_3`... si se repite), así
     * la SPA puede mandar un campo nuevo sin clave y los que ya existían conservan la suya (y con
     * ella, lo que el historial cargó bajo esa clave).
     *
     * @param mixed  $definicion Lo que vino en el payload (lista de campos o null).
     * @param string $prefijo    Prefijo de las claves de error (`fields`, `stages.0.fields`).
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws PipelineRuleException 422 con errores por campo (`fields.0.type`, etc.).
     */
    public function normalizar_definicion($definicion, $prefijo = 'fields')
    {
        if ($definicion === null) {
            return [];
        }

        if (! is_array($definicion) || ! $this->es_lista($definicion)) {
            throw PipelineRuleException::validacion([$prefijo => ['Los campos de la etapa tienen que ser una lista.']]);
        }

        if (count($definicion) > self::MAX_CAMPOS) {
            throw PipelineRuleException::validacion([$prefijo => ['Una etapa puede pedir hasta ' . self::MAX_CAMPOS . ' campos.']]);
        }

        $errores           = [];
        $campos            = [];
        $claves_explicitas = [];
        $hay_agenda        = false;

        foreach ($definicion as $indice => $crudo) {
            $p      = $prefijo . '.' . $indice;
            $numero = $indice + 1;

            if (! is_array($crudo)) {
                $errores[$p][] = 'El campo #' . $numero . ' no tiene un formato válido.';
                continue;
            }

            /* Etiqueta. */
            $label = $this->texto_o_vacio(isset($crudo['label']) ? $crudo['label'] : null);
            if ($label === '') {
                $errores[$p . '.label'][] = 'Falta el nombre del campo #' . $numero . '.';
            } elseif (mb_strlen($label) > self::MAX_LABEL) {
                $errores[$p . '.label'][] = 'El nombre del campo #' . $numero . ' no puede tener más de ' . self::MAX_LABEL . ' caracteres.';
            }
            $nombre = $label !== '' ? '«' . $label . '»' : '#' . $numero;

            /* Tipo. */
            $tipo = isset($crudo['type']) && is_string($crudo['type']) ? $crudo['type'] : null;
            if ($tipo === null || ! array_key_exists($tipo, self::FIELD_TYPE_LABELS)) {
                $errores[$p . '.type'][] = 'El tipo del campo ' . $nombre . ' no es válido.';
                $tipo = null;
            }

            /* Clave: opcional; si viene, con formato y sin repetirse. */
            $key = null;
            if (array_key_exists('key', $crudo) && $crudo['key'] !== null && $crudo['key'] !== '') {
                if (! is_string($crudo['key']) || preg_match(self::REGEX_KEY, trim($crudo['key'])) !== 1) {
                    $errores[$p . '.key'][] = 'La clave del campo ' . $nombre . ' no es válida: minúsculas, números y guiones bajos, empezando con una letra (hasta ' . self::MAX_KEY . ').';
                } else {
                    $key = trim($crudo['key']);
                    if (in_array($key, $claves_explicitas, true)) {
                        $errores[$p . '.key'][] = 'La clave «' . $key . '» está repetida en la etapa.';
                    }
                    $claves_explicitas[] = $key;
                }
            }

            /* Obligatorio y agenda: booleanos, default false. */
            $required = $this->booleano_de_definicion($crudo, 'required', $p, $nombre, $errores);
            $agenda   = $this->booleano_de_definicion($crudo, 'agenda', $p, $nombre, $errores);

            if ($agenda) {
                if ($tipo !== null && ! in_array($tipo, self::TIPOS_AGENDA, true)) {
                    $errores[$p . '.agenda'][] = 'Solo un campo de fecha o de fecha y hora puede completar la agenda (' . $nombre . ' no lo es).';
                } elseif ($hay_agenda) {
                    $errores[$p . '.agenda'][] = 'Solo un campo por etapa puede completar la agenda.';
                } else {
                    $hay_agenda = true;
                }
            }

            /* Opciones: obligatorias en una lista; en los demás tipos se normalizan a []. */
            $opciones = [];
            if ($tipo === 'select') {
                $opciones = $this->opciones_de_definicion($crudo, $p, $nombre, $errores);
            }

            $campos[] = [
                'key'      => $key,
                'label'    => $label,
                'type'     => $tipo,
                'required' => $required,
                'agenda'   => $agenda,
                'options'  => $opciones,
            ];
        }

        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        /* Segunda pasada: claves generadas para los que no traían, sin chocar con ninguna. */
        $usadas = $claves_explicitas;
        foreach ($campos as $indice => $campo) {
            if ($campo['key'] !== null) {
                continue;
            }
            $clave                  = $this->clave_desde_etiqueta($campo['label'], $usadas);
            $campos[$indice]['key'] = $clave;
            $usadas[]               = $clave;
        }

        return $campos;
    }

    /* ------------------------------------------------------------------------------------------
     | Los valores al mover
     |----------------------------------------------------------------------------------------- */

    /**
     * Valida los valores cargados al entrar a `$etapa` contra SU definición (la de la etapa
     * destino) y los devuelve normalizados.
     *
     * - Obligatorio = presente, no null y no string vacío. Un `false` en un sí/no cuenta como
     *   respondido.
     * - Las claves que no están en la definición se ignoran (no se guardan).
     * - Los errores van en `fields.<key>`, que es donde el `MoveModal` los pinta.
     *
     * @param PipelineStage $etapa   Etapa destino.
     * @param mixed         $valores Lo que vino en `fields` (objeto `{key: valor}` o null).
     *
     * @return array<string, mixed> `key => valor normalizado`, solo los que vinieron con valor, en
     *                              el orden de la definición.
     *
     * @throws PipelineRuleException 422 con `errors["fields.<key>"]`.
     */
    public function validar_valores(PipelineStage $etapa, $valores)
    {
        $errores = [];
        $limpios = $this->valores_o_errores($etapa, $valores, $errores);

        if ($errores !== []) {
            throw PipelineRuleException::validacion($errores);
        }

        return $limpios;
    }

    /**
     * Igual que `validar_valores()`, pero acumula los errores en `$errores` en vez de tirar: el
     * mover junta en un solo 422 los errores de los campos y el del motivo de pérdida.
     *
     * @param PipelineStage                       $etapa
     * @param mixed                               $valores
     * @param array<string, array<int, string>>   $errores Se le agregan los errores.
     *
     * @return array<string, mixed>
     */
    public function valores_o_errores(PipelineStage $etapa, $valores, array &$errores)
    {
        if ($valores === null) {
            $valores = [];
        }

        if (! is_array($valores)) {
            $errores['fields'][] = 'Los campos tienen que venir como un objeto {clave: valor}.';

            return [];
        }

        $limpios = [];

        foreach ($etapa->definicion_de_campos() as $campo) {
            $key   = (string) $campo['key'];
            $label = (string) $campo['label'];
            $clave = 'fields.' . $key;

            $valor = array_key_exists($key, $valores) ? $valores[$key] : null;
            if (is_string($valor)) {
                $valor = trim($valor);
            }

            if ($valor === null || $valor === '') {
                if (! empty($campo['required'])) {
                    $errores[$clave][] = 'Completá «' . $label . '».';
                }
                continue;
            }

            $error = null;
            $normalizado = $this->normalizar_valor($campo, $valor, $error);

            if ($error !== null) {
                $errores[$clave][] = $error;
                continue;
            }

            $limpios[$key] = $normalizado;
        }

        return $limpios;
    }

    /**
     * La foto que se guarda en la actividad: `[{key, label, type, value}]` de los campos que
     * vinieron con valor, en el orden de la definición.
     *
     * Es una FOTO a propósito (etiqueta y tipo copiados): si mañana se renombra o se borra el campo,
     * el historial se sigue leyendo igual.
     *
     * @param PipelineStage        $etapa
     * @param array<string, mixed> $limpios Lo que devolvió `validar_valores()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function foto(PipelineStage $etapa, array $limpios)
    {
        $foto = [];

        foreach ($etapa->definicion_de_campos() as $campo) {
            $key = (string) $campo['key'];

            if (! array_key_exists($key, $limpios)) {
                continue;
            }

            $foto[] = [
                'key'   => $key,
                'label' => (string) $campo['label'],
                'type'  => (string) $campo['type'],
                'value' => $limpios[$key],
            ];
        }

        return $foto;
    }

    /**
     * El valor del campo "agenda" de la etapa, si vino cargado, como fecha (un `date` queda a las
     * 00:00:00).
     *
     * @param PipelineStage        $etapa
     * @param array<string, mixed> $limpios
     *
     * @return Carbon|null
     */
    public function valor_de_agenda(PipelineStage $etapa, array $limpios)
    {
        foreach ($etapa->definicion_de_campos() as $campo) {
            if (empty($campo['agenda'])) {
                continue;
            }

            $key = (string) $campo['key'];
            if (! array_key_exists($key, $limpios)) {
                return null;
            }

            $formato = $campo['type'] === 'date' ? self::FORMATO_FECHA : self::FORMATO_FECHA_HORA;

            return self::parsear_fecha($limpios[$key], $formato);
        }

        return null;
    }

    /**
     * Si la etapa tiene un campo "agenda".
     *
     * @param PipelineStage $etapa
     *
     * @return bool
     */
    public function tiene_campo_agenda(PipelineStage $etapa)
    {
        foreach ($etapa->definicion_de_campos() as $campo) {
            if (! empty($campo['agenda'])) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------------------------------
     | Fechas que manda la SPA
     |----------------------------------------------------------------------------------------- */

    /**
     * Parsea una fecha en UN formato exacto, en la zona de la app, o null.
     *
     * Misma semántica que `date_format` de Laravel (el valor tiene que volver a escribirse igual
     * con el formato): "2026-9-3" no pasa por "2026-09-03", y un ISO con `Z` tampoco. El `!` del
     * formato pone en cero lo que el formato no nombra (sin él, "Y-m-d" se quedaría con la hora de
     * la máquina).
     *
     * @param mixed  $texto
     * @param string $formato Formato de `DateTime::createFromFormat()`, sin el `!`.
     *
     * @return Carbon|null
     */
    public static function parsear_fecha($texto, $formato)
    {
        if (! is_string($texto)) {
            return null;
        }

        $zona  = new \DateTimeZone((string) config('app.timezone'));
        $fecha = \DateTime::createFromFormat('!' . $formato, $texto, $zona);

        /* En PHP 7.4 getLastErrors() devuelve siempre un array; desde 8.2, false cuando no hubo nada. */
        $errores = \DateTime::getLastErrors();
        $limpio  = $errores === false
            || (is_array($errores) && (int) $errores['warning_count'] === 0 && (int) $errores['error_count'] === 0);

        if ($fecha === false || ! $limpio || $fecha->format($formato) !== $texto) {
            return null;
        }

        return Carbon::instance($fecha);
    }

    /**
     * Parsea una fecha suelta que manda la SPA (la próxima acción, la fecha de una nota):
     * `Y-m-d H:i` (con hora), `Y-m-d` (sin hora, queda a las 00:00:00) o `Y-m-d H:i:s` (el mismo
     * formato con el que la API la devuelve, por si la SPA reenvía lo que leyó). Nada más: un ISO
     * con `Z` es null, a propósito (es la trampa de las tres horas).
     *
     * @param mixed $texto
     *
     * @return Carbon|null
     */
    public static function parsear_fecha_de_la_spa($texto)
    {
        foreach ([self::FORMATO_FECHA_HORA, self::FORMATO_FECHA, 'Y-m-d H:i:s'] as $formato) {
            $fecha = self::parsear_fecha($texto, $formato);
            if ($fecha !== null) {
                return $fecha;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------------------------------
     | Interno
     |----------------------------------------------------------------------------------------- */

    /**
     * Normaliza un valor no vacío según el tipo del campo. Si no sirve, deja el mensaje en `$error`.
     *
     * @param array<string, mixed> $campo
     * @param mixed                $valor No vacío (ya recortado si es string).
     * @param string|null          $error Salida: el mensaje si el valor no sirve.
     *
     * @return mixed
     */
    private function normalizar_valor(array $campo, $valor, &$error)
    {
        $label = '«' . (string) $campo['label'] . '»';

        switch ($campo['type']) {
            case 'text':
            case 'textarea':
                $maximo = $campo['type'] === 'text' ? self::MAX_TEXTO : self::MAX_TEXTO_LARGO;
                if (! is_string($valor) && ! is_int($valor) && ! is_float($valor)) {
                    $error = $label . ' tiene que ser texto.';

                    return null;
                }
                $texto = (string) $valor;
                if (mb_strlen($texto) > $maximo) {
                    $error = $label . ' no puede tener más de ' . $maximo . ' caracteres.';

                    return null;
                }

                return $texto;

            case 'number':
                if (is_int($valor) || is_float($valor)) {
                    return $valor;
                }
                if (is_string($valor) && is_numeric($valor)) {
                    return $valor + 0;
                }
                $error = $label . ' tiene que ser un número.';

                return null;

            case 'boolean':
                if (is_bool($valor)) {
                    return $valor;
                }
                if ($valor === 1 || $valor === 0) {
                    return $valor === 1;
                }
                if (is_string($valor)) {
                    $minuscula = mb_strtolower($valor);
                    if ($minuscula === '1' || $minuscula === 'true') {
                        return true;
                    }
                    if ($minuscula === '0' || $minuscula === 'false') {
                        return false;
                    }
                }
                $error = $label . ' tiene que ser sí o no.';

                return null;

            case 'date':
                if (self::parsear_fecha($valor, self::FORMATO_FECHA) === null) {
                    $error = $label . ' tiene que ser una fecha con formato AAAA-MM-DD.';

                    return null;
                }

                return $valor;

            case 'datetime':
                if (self::parsear_fecha($valor, self::FORMATO_FECHA_HORA) === null) {
                    $error = $label . ' tiene que ser una fecha y hora con formato AAAA-MM-DD HH:MM.';

                    return null;
                }

                return $valor;

            case 'select':
                $opciones = isset($campo['options']) && is_array($campo['options']) ? $campo['options'] : [];
                $texto    = (is_string($valor) || is_int($valor) || is_float($valor)) ? (string) $valor : null;
                if ($texto === null || ! in_array($texto, $opciones, true)) {
                    $error = $label . ' tiene que ser una de las opciones: ' . implode(', ', $opciones) . '.';

                    return null;
                }

                return $texto;
        }

        /* Un tipo que no está en la lista no pasó por normalizar_definicion(): no se guarda. */
        $error = 'El campo ' . $label . ' tiene un tipo desconocido.';

        return null;
    }

    /**
     * Un booleano de la definición (`required`, `agenda`). Ausente o null = false.
     *
     * @param array<string, mixed>              $crudo
     * @param string                            $propiedad
     * @param string                            $prefijo
     * @param string                            $nombre   Nombre del campo para el mensaje.
     * @param array<string, array<int, string>> $errores
     *
     * @return bool
     */
    private function booleano_de_definicion(array $crudo, $propiedad, $prefijo, $nombre, array &$errores)
    {
        if (! array_key_exists($propiedad, $crudo) || $crudo[$propiedad] === null) {
            return false;
        }

        $valor = $crudo[$propiedad];

        if (is_bool($valor)) {
            return $valor;
        }

        if ($valor === 1 || $valor === 0 || $valor === '1' || $valor === '0') {
            return (string) $valor === '1';
        }

        if ($valor === 'true' || $valor === 'false') {
            return $valor === 'true';
        }

        $errores[$prefijo . '.' . $propiedad][] = '«' . $propiedad . '» del campo ' . $nombre . ' tiene que ser sí o no.';

        return false;
    }

    /**
     * Las opciones de un campo lista: 1 a 30, cada una no vacía y hasta 80 caracteres, recortadas y
     * sin repetidos.
     *
     * @param array<string, mixed>              $crudo
     * @param string                            $prefijo
     * @param string                            $nombre
     * @param array<string, array<int, string>> $errores
     *
     * @return array<int, string>
     */
    private function opciones_de_definicion(array $crudo, $prefijo, $nombre, array &$errores)
    {
        $crudas = isset($crudo['options']) ? $crudo['options'] : null;

        if (! is_array($crudas) || ! $this->es_lista($crudas) || count($crudas) === 0) {
            $errores[$prefijo . '.options'][] = 'El campo ' . $nombre . ' es una lista: cargale al menos una opción.';

            return [];
        }

        if (count($crudas) > self::MAX_OPCIONES) {
            $errores[$prefijo . '.options'][] = 'El campo ' . $nombre . ' puede tener hasta ' . self::MAX_OPCIONES . ' opciones.';

            return [];
        }

        $opciones = [];
        foreach ($crudas as $opcion) {
            $texto = $this->texto_o_vacio($opcion);

            if ($texto === '') {
                $errores[$prefijo . '.options'][] = 'El campo ' . $nombre . ' tiene una opción vacía.';
                continue;
            }

            if (mb_strlen($texto) > self::MAX_OPCION) {
                $errores[$prefijo . '.options'][] = 'Las opciones de ' . $nombre . ' no pueden tener más de ' . self::MAX_OPCION . ' caracteres.';
                continue;
            }

            if (! in_array($texto, $opciones, true)) {
                $opciones[] = $texto;
            }
        }

        return $opciones;
    }

    /**
     * Clave generada desde la etiqueta, única entre `$usadas`.
     *
     * @param string             $label
     * @param array<int, string> $usadas
     *
     * @return string
     */
    private function clave_desde_etiqueta($label, array $usadas)
    {
        $base = preg_replace('/[^a-z0-9_]/', '', Str::slug((string) $label, '_'));
        $base = trim((string) $base, '_');

        if ($base === '') {
            $base = 'campo';
        } elseif (preg_match('/^[a-z]/', $base) !== 1) {
            /* "1er contacto" → "campo_1er_contacto": la clave tiene que empezar con una letra. */
            $base = 'campo_' . $base;
        }

        $base = rtrim(substr($base, 0, self::MAX_KEY), '_');

        if (! in_array($base, $usadas, true)) {
            return $base;
        }

        for ($n = 2; ; $n++) {
            $sufijo    = '_' . $n;
            $candidata = rtrim(substr($base, 0, self::MAX_KEY - strlen($sufijo)), '_') . $sufijo;

            if (! in_array($candidata, $usadas, true)) {
                return $candidata;
            }
        }
    }

    /**
     * Texto recortado de un escalar; '' si no es texto ni número.
     *
     * @param mixed $valor
     *
     * @return string
     */
    private function texto_o_vacio($valor)
    {
        if (is_string($valor)) {
            return trim($valor);
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        return '';
    }

    /**
     * Si un array es una lista (claves 0..n-1 en orden) y no un objeto.
     *
     * @param array<mixed, mixed> $array
     *
     * @return bool
     */
    private function es_lista(array $array)
    {
        return $array === [] || array_keys($array) === range(0, count($array) - 1);
    }
}

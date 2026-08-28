<?php

namespace App\Services;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Arma y corre la consulta genérica de `GET claude/query` a partir de `config/claude_query.php`.
 *
 * 🔴 SÓLO LECTURA, Y NO POR CONVENCIÓN. Acá no hay ningún método que escriba, la ruta se registra
 * únicamente con `Route::get` y un `POST claude/query` devuelve 405 de Laravel. El motivo no es
 * prudencia genérica: `ClaudeUpgradeOpsController` son más de 1300 líneas que casi todas son frenos
 * —confirm_client_name, dry_run por defecto, allow_deploy_to_active_api, el gate de horario, los
 * dos umbrales del vencimiento—, y una escritura genérica "por nombre de modelo" los saltearía a
 * todos y arrancaría deploys SSH sobre negocios reales. Un endpoint que escribe se escribe entero,
 * con sus frenos, o no se escribe.
 *
 * 🔴 LA PROYECCIÓN ES UNA LISTA BLANCA POSITIVA. Todo sale de `DB::table($tabla)->select($columnas)`
 * con `$columnas` leídas del config. No hay `select *`, no hay modelo Eloquent serializado (así que
 * no hay `$appends` ni accessors que agreguen nada), y no hay ningún camino que devuelva una columna
 * que no esté escrita en el config. `clients.api_key` no se filtra porque nadie la escribió ahí, no
 * porque haya una lista de columnas prohibidas que la ataje.
 *
 * 🔴 Y ATRÁS DE ESO HAY UNA SEGUNDA REJA, FAIL-CLOSED. Si el config igual declarara una columna que
 * matchea `claude_query.columnas_prohibidas`, `consultar()` devuelve 422 ANTES de tocar la base y no
 * sirve ni una fila. Se chequea en cada request y no una sola vez al bootear, para que también
 * agarre un config inyectado en runtime.
 *
 * 🔴 UN INCLUDE NUNCA DISPARA UNA CONSULTA POR FILA. Cada include es UNA consulta agregada para toda
 * la página: `belongs_to` es un `whereIn` sobre las claves externas distintas, `has_many` es un
 * `whereIn` sobre los ids de la página y el recorte por `limite` se hace en memoria. Una página de
 * 30 filas cuesta exactamente las mismas consultas que una de 3, y hay un test que lo mide con
 * `DB::getQueryLog()`.
 */
class ClaudeQueryService
{
    use RespuestasParaClaude;

    /**
     * Nombres de parámetro que gobiernan la consulta y por lo tanto NO pueden ser nombre de filtro.
     * Si un modelo declarara un filtro llamado `limit`, el filtro nunca se aplicaría y el que
     * consulta no tendría forma de darse cuenta. Hay un test que lo verifica sobre el config entero.
     *
     * @var array<int, string>
     */
    const PARAMETROS_RESERVADOS = ['model', 'include', 'fields', 'after_id', 'limit', 'order', 'q', 'count_only'];

    /**
     * Claves de modelo disponibles, ordenadas alfabéticamente.
     *
     * @return array<int, string>
     */
    public function modelos_disponibles()
    {
        $modelos = array_keys((array) config('claude_query.modelos', []));
        sort($modelos);

        return $modelos;
    }

    /**
     * Definición de un modelo de la lista blanca.
     *
     * @param string $clave Clave del modelo.
     *
     * @return array<string, mixed>|null
     */
    public function definicion($clave)
    {
        $modelos = (array) config('claude_query.modelos', []);

        return isset($modelos[$clave]) && is_array($modelos[$clave]) ? $modelos[$clave] : null;
    }

    /**
     * Columnas declaradas por un modelo que matchean el patrón de columnas prohibidas.
     *
     * Mira las tres superficies por las que una columna puede llegar a la respuesta: la proyección
     * base, las columnas opt-in y las columnas de cada relación incluible. Una relación es tan
     * capaz de filtrar un secreto como la tabla principal.
     *
     * @param array<string, mixed> $definicion Definición del modelo.
     *
     * @return array<int, string> Vacío si está limpio.
     */
    public function columnas_prohibidas_declaradas(array $definicion)
    {
        $patron = (string) config('claude_query.columnas_prohibidas');

        if ($patron === '') {
            return [];
        }

        $columnas = isset($definicion['columnas']) ? (array) $definicion['columnas'] : [];

        foreach ((array) (isset($definicion['columnas_opt_in']) ? $definicion['columnas_opt_in'] : []) as $opt_in) {
            $columnas = array_merge($columnas, (array) $opt_in);
        }

        foreach ((array) (isset($definicion['relaciones']) ? $definicion['relaciones'] : []) as $relacion) {
            $columnas = array_merge($columnas, isset($relacion['columnas']) ? (array) $relacion['columnas'] : []);
        }

        $ofensoras = [];
        foreach ($columnas as $columna) {
            $columna = (string) $columna;
            if (preg_match($patron, $columna) === 1 && ! in_array($columna, $ofensoras, true)) {
                $ofensoras[] = $columna;
            }
        }

        return $ofensoras;
    }

    /**
     * Corre la consulta y arma el cuerpo de la respuesta.
     *
     * @param Request $request Request entrante (ya validada por el controlador).
     * @param string  $clave   Clave del modelo pedido.
     *
     * @return array<string, mixed>|JsonResponse Array con el cuerpo, o la respuesta de error.
     */
    public function consultar(Request $request, $clave)
    {
        $definicion = $this->definicion($clave);

        if ($definicion === null) {
            return $this->error_422('El modelo "' . $clave . '" no está en la lista blanca de lectura.', [
                'modelos_disponibles' => $this->modelos_disponibles(),
                'ayuda'               => 'GET claude/catalog publica cada modelo con sus columnas, filtros y relaciones, y también los que están excluidos a propósito con el motivo.',
            ]);
        }

        /*
         * 🔴 Fail-closed, y antes de tocar la base: si el config declara una columna que matchea el
         * patrón prohibido, no se sirve NADA. Devolver "todo menos esa" sería peor que devolver un
         * error, porque el que escribió mal el config se quedaría sin enterarse.
         */
        $prohibidas = $this->columnas_prohibidas_declaradas($definicion);
        if ($prohibidas !== []) {
            return $this->error_422(
                'La configuración del modelo "' . $clave . '" declara una columna prohibida ("' . implode('", "', $prohibidas) . '"). No se devolvió nada.',
                [
                    'columnas_prohibidas' => $prohibidas,
                    'patron'              => (string) config('claude_query.columnas_prohibidas'),
                    'ayuda'               => 'Es fail-closed a propósito: se corta la consulta entera en vez de servir el resto sin esa columna. Sacala de config/claude_query.php.',
                ]
            );
        }

        $relaciones = (array) (isset($definicion['relaciones']) ? $definicion['relaciones'] : []);
        $opt_in     = (array) (isset($definicion['columnas_opt_in']) ? $definicion['columnas_opt_in'] : []);

        $includes = $this->resolver_includes($request, array_merge(array_keys($opt_in), array_keys($relaciones)));
        if (! is_array($includes)) {
            return $includes;
        }

        $columnas = $this->resolver_columnas($request, $definicion, $includes, $relaciones, $opt_in);
        if (! is_array($columnas)) {
            return $columnas;
        }

        $desconocido = $this->primer_parametro_desconocido($request, $definicion);
        if ($desconocido !== null) {
            return $this->error_422('El filtro "' . $desconocido . '" no existe para el modelo "' . $clave . '".', [
                'filtros_validos'       => array_keys((array) $definicion['filtros']),
                'parametros_reservados' => self::PARAMETROS_RESERVADOS,
            ]);
        }

        $query = DB::table($definicion['tabla'])->select($columnas);

        $aplicados = [];
        foreach ((array) $definicion['filtros'] as $nombre => $filtro) {
            if (! $request->has($nombre)) {
                continue;
            }

            $resultado = $this->aplicar_filtro($query, $clave, $nombre, (array) $filtro, $request);

            if ($resultado instanceof JsonResponse) {
                return $resultado;
            }

            if ($resultado !== null) {
                $aplicados[$nombre] = $resultado;
            }
        }

        $texto = $this->texto_o_null($request->input('q'));
        if ($texto !== null) {
            $busqueda = (array) (isset($definicion['busqueda']) ? $definicion['busqueda'] : []);

            if ($busqueda === []) {
                return $this->error_422('El modelo "' . $clave . '" no declara búsqueda por texto.', [
                    'ayuda' => 'Filtrá por una columna concreta. GET claude/catalog lista los filtros de cada modelo.',
                ]);
            }

            $query->where(function ($sub) use ($busqueda, $texto) {
                foreach ($busqueda as $columna) {
                    $sub->orWhere($columna, 'like', '%' . $texto . '%');
                }
            });

            $aplicados['q'] = $texto;
        }

        if ($this->booleano_o_null($request, 'count_only') === true) {
            return [
                'model'            => $clave,
                'tabla'            => $definicion['tabla'],
                'count_total'      => (int) $query->count(),
                'filtros_aplicados' => $aplicados,
                'nota'             => 'count_only devuelve el total de la consulta, sin traer ninguna fila. La paginación normal no cuenta el total: usa has_more.',
            ];
        }

        $orden    = $this->direccion($request, $definicion);
        $limite   = $this->resolver_limite($request->input('limit'), (int) $definicion['limite_default'], (int) $definicion['limite_max']);
        $after_id = $this->entero_o_null($request->input('after_id'));
        $cursor   = $definicion['clave_de_cursor'];

        $this->aplicar_cursor($query, $cursor, $after_id, $orden);
        $query->orderBy($cursor, $orden);

        $pagina = $this->traer_pagina($query, $limite);

        $filas = [];
        foreach ($pagina['rows'] as $fila) {
            $filas[] = (array) $fila;
        }

        foreach ($includes as $include) {
            if (isset($relaciones[$include])) {
                $filas = $this->adjuntar_relacion($filas, $include, (array) $relaciones[$include]);
            }
        }

        $ultima = $filas === [] ? null : $filas[count($filas) - 1];

        return [
            'model'              => $clave,
            'tabla'              => $definicion['tabla'],
            'data'               => $filas,
            'count'              => count($filas),
            'has_more'           => $pagina['has_more'],
            'next_after_id'      => ($pagina['has_more'] && $ultima !== null && isset($ultima[$cursor])) ? $ultima[$cursor] : null,
            'columnas'           => array_values($columnas),
            'includes_aplicados' => array_values($includes),
            'filtros_aplicados'  => $aplicados,
            'limite'             => [
                'pedido'   => $this->entero_o_null($request->input('limit')),
                'aplicado' => $limite,
                'max'      => (int) $definicion['limite_max'],
            ],
            'orden'              => ['columna' => $cursor, 'direccion' => $orden],
            'nota'               => isset($definicion['nota']) ? $definicion['nota'] : null,
        ];
    }

    /* ----------------------------------------------------------------------------------------
     | Interno
     |--------------------------------------------------------------------------------------- */

    /**
     * Proyección final: columnas base, más las opt-in que se pidieron, recortadas por `fields`.
     *
     * 🔴 `fields` sólo ACHICA. Un campo que no está en la proyección vigente es 422, y eso cubre los
     * dos casos que importan: `fields=api_key` (no está en el config) y `fields=phone` sin
     * `include=contacto` (está en el config pero no se pidió). Si `fields` pudiera agrandar, el
     * opt-in no sería opt-in.
     *
     * @param Request              $request    Request entrante.
     * @param array<string, mixed> $definicion Definición del modelo.
     * @param array<int, string>   $includes   Includes pedidos.
     * @param array<string, mixed> $relaciones Relaciones declaradas.
     * @param array<string, mixed> $opt_in     Columnas opt-in declaradas.
     *
     * @return array<int, string>|JsonResponse
     */
    private function resolver_columnas(Request $request, array $definicion, array $includes, array $relaciones, array $opt_in)
    {
        $columnas = (array) $definicion['columnas'];

        foreach ($opt_in as $nombre => $extras) {
            if (in_array($nombre, $includes, true)) {
                $columnas = array_merge($columnas, (array) $extras);
            }
        }

        $fields = $this->normalizar_lista($request->input('fields'));

        if ($fields !== []) {
            foreach ($fields as $field) {
                if (! in_array($field, $columnas, true)) {
                    return $this->error_422('El campo "' . $field . '" no está en la proyección de este modelo.', [
                        'campos_validos' => array_values($columnas),
                        'ayuda'          => '`fields` sólo achica la proyección, nunca la agranda. Una columna opt-in necesita su include (por ejemplo include=contacto), y una columna que no está en config/claude_query.php no existe para este endpoint.',
                    ]);
                }
            }

            $columnas = $fields;
        }

        /*
         * La clave de cursor y las claves locales de los includes se agregan sí o sí: sin ellas no
         * hay paginación ni relación posible. Están declaradas en `columnas` del config, así que
         * esto no agranda la lista blanca, sólo evita que `fields` rompa la mecánica.
         */
        $obligatorias = [$definicion['clave_de_cursor']];

        foreach ($relaciones as $nombre => $relacion) {
            if (in_array($nombre, $includes, true)) {
                $obligatorias[] = $relacion['clave_local'];
            }
        }

        foreach ($obligatorias as $obligatoria) {
            if (! in_array($obligatoria, $columnas, true)) {
                $columnas[] = $obligatoria;
            }
        }

        return array_values($columnas);
    }

    /**
     * Primer parámetro de la query string que no es reservado ni un filtro declarado.
     *
     * Un filtro mal escrito que se ignora en silencio devuelve una lista más larga de la esperada y
     * parece un problema de datos. Mejor 422 con la lista de filtros válidos.
     *
     * @param Request              $request    Request entrante.
     * @param array<string, mixed> $definicion Definición del modelo.
     *
     * @return string|null
     */
    private function primer_parametro_desconocido(Request $request, array $definicion)
    {
        $declarados = array_keys((array) $definicion['filtros']);

        foreach (array_keys((array) $request->query()) as $parametro) {
            $parametro = (string) $parametro;

            if (in_array($parametro, self::PARAMETROS_RESERVADOS, true)) {
                continue;
            }

            if (! in_array($parametro, $declarados, true)) {
                return $parametro;
            }
        }

        return null;
    }

    /**
     * Dirección del cursor: la pedida, o la del config.
     *
     * @param Request              $request    Request entrante.
     * @param array<string, mixed> $definicion Definición del modelo.
     *
     * @return string asc | desc
     */
    private function direccion(Request $request, array $definicion)
    {
        $orden = strtolower($this->texto_con_default($request, 'order', (string) $definicion['orden_default']));

        return $orden === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Aplica un filtro declarado sobre la query.
     *
     * @param \Illuminate\Database\Query\Builder $query   Query en construcción.
     * @param string                             $modelo  Clave del modelo (para los mensajes).
     * @param string                             $nombre  Nombre del filtro.
     * @param array<string, mixed>               $filtro  Definición del filtro.
     * @param Request                            $request Request entrante.
     *
     * @return mixed Null si el filtro llegó vacío y se ignora; JsonResponse si es error; el valor
     *               aplicado en cualquier otro caso.
     */
    private function aplicar_filtro($query, $modelo, $nombre, array $filtro, Request $request)
    {
        $columna = $filtro['columna'];
        $tipo    = $filtro['tipo'];
        $crudo   = $request->input($nombre);

        if ($tipo === 'booleano' || $tipo === 'nulo') {
            $valor = $this->booleano_o_null($request, $nombre);

            if ($valor === null) {
                return null;
            }

            if ($tipo === 'nulo') {
                if ($valor) {
                    $query->whereNull($columna);
                } else {
                    $query->whereNotNull($columna);
                }

                return $valor;
            }

            $query->where($columna, '=', $valor ? 1 : 0);

            return $valor;
        }

        if ($tipo === 'entero') {
            if ($crudo === null || $crudo === '') {
                return null;
            }

            $valor = $this->entero_o_null($crudo);
            if ($valor === null) {
                return $this->error_422('El filtro "' . $nombre . '" del modelo "' . $modelo . '" tiene que ser un número entero.');
            }

            $query->where($columna, '=', $valor);

            return $valor;
        }

        if ($tipo === 'lista_de_enteros') {
            if ($crudo === null || $crudo === '') {
                return null;
            }

            $valores = $this->normalizar_lista_enteros($crudo);
            if ($valores === []) {
                return $this->error_422('El filtro "' . $nombre . '" del modelo "' . $modelo . '" tiene que ser una lista de enteros positivos (por ejemplo ids=1,2,3).');
            }

            $query->whereIn($columna, $valores);

            return $valores;
        }

        if ($tipo === 'texto' || $tipo === 'texto_exacto') {
            $valor = $this->texto_o_null($crudo);

            if ($valor === null) {
                return null;
            }

            if ($tipo === 'texto') {
                $query->where($columna, 'like', '%' . $valor . '%');
            } else {
                $query->where($columna, '=', $valor);
            }

            return $valor;
        }

        if ($tipo === 'en') {
            $valor = $this->texto_o_null($crudo);

            if ($valor === null) {
                return null;
            }

            $valores = (array) (isset($filtro['valores']) ? $filtro['valores'] : []);

            if (! in_array($valor, $valores, true)) {
                return $this->error_422('El filtro "' . $nombre . '" del modelo "' . $modelo . '" no acepta el valor "' . $valor . '".', [
                    'valores_validos' => $valores,
                ]);
            }

            $query->where($columna, '=', $valor);

            return $valor;
        }

        if ($tipo === 'fecha_desde' || $tipo === 'fecha_hasta') {
            if ($crudo === null || $crudo === '') {
                return null;
            }

            $fecha = $this->parsear_o_null($crudo);
            if ($fecha === null) {
                return $this->error_422('El filtro "' . $nombre . '" del modelo "' . $modelo . '" no es una fecha válida.');
            }

            $query->where($columna, $tipo === 'fecha_desde' ? '>=' : '<=', $fecha);

            return $fecha->toDateTimeString();
        }

        /* Tipo desconocido en el config: fail-closed, igual que la columna prohibida. */
        return $this->error_422('El filtro "' . $nombre . '" del modelo "' . $modelo . '" declara un tipo desconocido ("' . $tipo . '"). No se devolvió nada.');
    }

    /**
     * Adjunta una relación a TODAS las filas de la página con UNA sola consulta.
     *
     * 🔴 Esto es la regla del bloque `claude/*`: nunca una consulta por fila. `belongs_to` hace un
     * `whereIn` sobre las claves externas distintas de la página; `has_many` hace un `whereIn` sobre
     * los ids de la página, ordena en SQL y recorta por `limite` en memoria. Recortar en SQL
     * requeriría una consulta por fila (o una ventana que Laravel 8 no expone), que es exactamente
     * lo que esto evita.
     *
     * @param array<int, array<string, mixed>> $filas    Filas de la página.
     * @param string                           $nombre   Nombre del include (clave de salida).
     * @param array<string, mixed>             $relacion Definición de la relación.
     *
     * @return array<int, array<string, mixed>>
     */
    private function adjuntar_relacion(array $filas, $nombre, array $relacion)
    {
        $clave_local   = $relacion['clave_local'];
        $clave_externa = $relacion['clave_externa'];
        $es_has_many   = $relacion['tipo'] === 'has_many';

        if ($filas === []) {
            return $filas;
        }

        $claves = [];
        foreach ($filas as $fila) {
            $valor = isset($fila[$clave_local]) ? $fila[$clave_local] : null;
            if ($valor !== null && ! in_array($valor, $claves, true)) {
                $claves[] = $valor;
            }
        }

        if ($claves === []) {
            foreach ($filas as $indice => $fila) {
                $filas[$indice][$nombre] = $es_has_many ? [] : null;
            }

            return $filas;
        }

        $declaradas = (array) $relacion['columnas'];
        $proyeccion = $declaradas;
        if (! in_array($clave_externa, $proyeccion, true)) {
            $proyeccion[] = $clave_externa;
        }

        $consulta = DB::table($relacion['tabla'])->select($proyeccion)->whereIn($clave_externa, $claves);

        if ($es_has_many) {
            $consulta->orderBy($clave_externa);
            if (in_array('id', $proyeccion, true)) {
                $consulta->orderBy('id');
            }
        }

        $agrupadas = [];
        foreach ($consulta->get() as $relacionada) {
            $relacionada = (array) $relacionada;
            $clave       = (string) $relacionada[$clave_externa];

            /* La proyección declarada es la que promete el config: si clave_externa se agregó sólo
               para poder agrupar, se saca antes de devolverla. */
            $salida = [];
            foreach ($declaradas as $columna) {
                $salida[$columna] = isset($relacionada[$columna]) ? $relacionada[$columna] : null;
            }

            $agrupadas[$clave][] = $salida;
        }

        $limite = isset($relacion['limite']) ? (int) $relacion['limite'] : null;

        foreach ($filas as $indice => $fila) {
            $clave       = isset($fila[$clave_local]) ? (string) $fila[$clave_local] : null;
            $encontradas = ($clave !== null && isset($agrupadas[$clave])) ? $agrupadas[$clave] : [];

            if ($es_has_many) {
                $filas[$indice][$nombre] = $limite !== null ? array_slice($encontradas, 0, $limite) : $encontradas;
                continue;
            }

            $filas[$indice][$nombre] = $encontradas === [] ? null : $encontradas[0];
        }

        return $filas;
    }
}

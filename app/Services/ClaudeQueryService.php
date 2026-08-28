<?php

namespace App\Services;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use Carbon\Carbon;
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
     * Lo único que este endpoint acepta como booleano en un filtro declarado.
     *
     * 🔴 ES MÁS ESTRICTO QUE `booleano_o_null()` A PROPÓSITO, Y SÓLO ACÁ. El helper del trait
     * resuelve con `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, que para CUALQUIER cosa que no
     * reconozca devuelve `false` en vez de avisar. El caso que lo originó es
     * `GET claude/query?model=client&is_active[]=1`: `filter_var` de un array devuelve `false`, así
     * que el filtro se INVERTÍA en silencio y la respuesta traía los clientes INACTIVOS con
     * `filtros_aplicados: {"is_active": false}` como si eso fuera lo que se pidió.
     *
     * No se endurece el helper porque `GET claude/clients` (`is_active`, `has_schedule`),
     * `GET claude/versions` (`is_hotfix`) y `GET claude/upgrades` (`activos`) lo vienen usando desde
     * el 24/8/2026 con la semántica tolerante: cambiarla allá sería cambiarle el contrato a
     * endpoints que ya están en uso, para arreglar un agujero que es de acá.
     *
     * @var array<int, string>
     */
    const BOOLEANOS_ACEPTADOS = ['1', '0', 'true', 'false'];

    /**
     * Formatos con los que se lee un filtro `fecha_desde` / `fecha_hasta`, en orden de intento.
     *
     * 🔴 ES UNA LISTA CERRADA PORQUE `Carbon::parse()` NO FALLA CUANDO DEBERÍA. El caso que lo
     * originó es `GET claude/query?model=client&creado_desde=x`: `Carbon::parse('x')` no lanza nada
     * y devuelve la fecha y hora de AHORA, así que la consulta quedaba
     * `where created_at >= <ahora>` —cero filas, siempre— y `filtros_aplicados` publicaba
     * `{"creado_desde": "2026-08-28 13:56:53"}` como si el que consultó hubiera pedido eso.
     * `next monday` y `0000-00-00` son de la misma familia: parsean, y en algo que nadie pidió.
     *
     * El `!` de adelante resetea los campos que el formato no nombra (sin él, `Y-m-d H:i` le
     * completaría los segundos con los del reloj de la máquina). El último no lo lleva porque trae
     * el offset y define el instante entero.
     *
     * @var array<int, string>
     */
    const FORMATOS_DE_FECHA = ['!Y-m-d', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i', '!Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP'];

    /**
     * Los mismos formatos escritos como ejemplo, que es lo que se publica en el 422.
     *
     * Un `!Y-m-d\TH:i:sP` no le sirve a nadie del otro lado del endpoint; una fecha de verdad, sí.
     *
     * @var array<int, string>
     */
    const EJEMPLOS_DE_FECHA = [
        '2026-08-27',
        '2026-08-27 18:02',
        '2026-08-27 18:02:11',
        '2026-08-27T18:02:11',
        '2026-08-27T18:02:11-03:00',
    ];

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

        /*
         * 🔴 UN ARRAY DONDE VA UN ESCALAR ES 422, Y NO PUEDE SER OTRA COSA. `lista_de_enteros` es el
         * único tipo que espera una lista; en todos los demás, un `filtro[]=x` entraba y salía mal
         * por tres caminos distintos, ninguno visible:
         *   - `creado_desde[]=x` llegaba a `Carbon::parse(array)`, que tira TypeError. TypeError es
         *     un `\Error`, NO un `\Exception`, así que el `catch (\Exception)` de `parsear_o_null()`
         *     ni lo veía: 500 con stack trace y rutas del disco, en los 6 modelos con filtro de fecha.
         *   - `is_active[]=1` llegaba a `filter_var(array, FILTER_VALIDATE_BOOLEAN)`, que devuelve
         *     `false`: el filtro se INVERTÍA en silencio y devolvía los inactivos.
         *   - `created_via[]=claude` llegaba a `texto_o_null(array)`, que devuelve `null`: el filtro
         *     se ignoraba y la lista salía más larga que la pedida, que del otro lado parece un
         *     problema de datos y no un parámetro mal armado.
         * El bloque `claude/*` promete 422 legible para un parámetro mal formado. Los tres eran lo
         * contrario: 500, o una respuesta plausible y equivocada.
         */
        if (is_array($crudo) && $tipo !== 'lista_de_enteros') {
            return $this->error_422(
                'El filtro "' . $nombre . '" del modelo "' . $modelo . '" espera un solo valor y llegó una lista.',
                [
                    'tipo_del_filtro' => $tipo,
                    'ayuda'           => 'Mandalo como ' . $nombre . '=valor, sin corchetes. Los corchetes sólo los '
                        . 'acepta un filtro de tipo lista_de_enteros (por ejemplo ids[]=1&ids[]=2, o ids=1,2).',
                ]
            );
        }

        if ($tipo === 'booleano' || $tipo === 'nulo') {
            $valor = $this->booleano_de_filtro($crudo, $modelo, $nombre);

            if ($valor === null) {
                return null;
            }

            if ($valor instanceof JsonResponse) {
                return $valor;
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
            $texto = $this->texto_o_null($crudo);

            if ($texto === null) {
                return null;
            }

            /* 🔴 `fecha_estricta()` y NO `parsear_o_null()`: ver el docblock de FORMATOS_DE_FECHA.
               El helper del trait delega en `Carbon::parse()`, que para "x" devuelve AHORA en vez de
               fallar, y el filtro terminaba siendo `created_at >= <ahora>` sin que nadie se enterara. */
            $fecha = self::fecha_estricta($texto);
            if ($fecha === null) {
                return $this->error_422('El filtro "' . $nombre . '" del modelo "' . $modelo . '" no es una fecha válida.', [
                    'recibido'         => $texto,
                    'formatos_validos' => self::EJEMPLOS_DE_FECHA,
                    'ayuda'            => 'Se acepta sólo una fecha absoluta en alguno de esos formatos. Nada de '
                        . 'expresiones relativas ("ayer", "next monday"): parsean a algo que no es lo que se pidió y el '
                        . 'filtro saldría aplicado y mal.',
                ]);
            }

            $query->where($columna, $tipo === 'fecha_desde' ? '>=' : '<=', $fecha);

            return $fecha->toDateTimeString();
        }

        /* Tipo desconocido en el config: fail-closed, igual que la columna prohibida. */
        return $this->error_422('El filtro "' . $nombre . '" del modelo "' . $modelo . '" declara un tipo desconocido ("' . $tipo . '"). No se devolvió nada.');
    }

    /**
     * Lee un filtro booleano exigiendo que sea booleano de verdad.
     *
     * 🔴 NO USA `booleano_o_null()` DEL TRAIT, y esa es toda la razón por la que existe: el helper
     * resuelve con `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, que ante cualquier cosa que no
     * entiende devuelve `false` en vez de avisar. Con `?is_active[]=1` eso terminaba filtrando por
     * clientes INACTIVOS y publicando `filtros_aplicados: {"is_active": false}`, o sea una respuesta
     * plausible que contesta lo contrario de lo que se preguntó. Un filtro declarado que llega mal
     * es 422, igual que ya hacen los de tipo `en` con su `valores_validos`.
     *
     * El helper del trait NO se endurece porque lo comparten endpoints que ya están en uso desde el
     * 24/8/2026 con la semántica tolerante (ver el docblock de BOOLEANOS_ACEPTADOS).
     *
     * @param mixed  $crudo  Valor crudo del parámetro.
     * @param string $modelo Clave del modelo (para el mensaje).
     * @param string $nombre Nombre del filtro (para el mensaje).
     *
     * @return bool|JsonResponse|null Null si llegó vacío y el filtro no se aplica.
     */
    private function booleano_de_filtro($crudo, $modelo, $nombre)
    {
        if (is_bool($crudo)) {
            return $crudo;
        }

        $texto = $this->texto_o_null($crudo);

        if ($texto === null) {
            return null;
        }

        $normalizado = mb_strtolower($texto);

        if (! in_array($normalizado, self::BOOLEANOS_ACEPTADOS, true)) {
            return $this->error_422(
                'El filtro "' . $nombre . '" del modelo "' . $modelo . '" tiene que ser booleano.',
                [
                    'recibido'        => $texto,
                    'valores_validos' => self::BOOLEANOS_ACEPTADOS,
                    'ayuda'           => 'Un valor que no es booleano no se interpreta como false: se rechaza. Si se '
                        . 'interpretara, el filtro contestaría lo contrario de lo que se preguntó y la respuesta '
                        . 'parecería correcta.',
                ]
            );
        }

        return $normalizado === '1' || $normalizado === 'true';
    }

    /**
     * Parsea una fecha exigiendo uno de los formatos absolutos declarados, o null.
     *
     * 🔴 NO USA `parsear_o_null()` DEL TRAIT por dos motivos, y los dos están medidos:
     *   1. Ese helper delega en `Carbon::parse()`, que para `'x'` NO lanza: devuelve la fecha y hora
     *      de ahora. El filtro salía aplicado, con cero filas siempre, y `filtros_aplicados` publicaba
     *      un instante que nadie pidió.
     *   2. Su `catch` es de `\Exception` y `Carbon::parse(array)` tira `TypeError`, que es `\Error`:
     *      eso era un 500. Acá ni llega, porque el guard de arrays de `aplicar_filtro()` corta antes,
     *      y además `DateTime::createFromFormat()` sobre un string no tiene ese camino.
     *
     * El helper del trait tampoco se endurece por esto: lo usan `salud_del_deployment()` y la `salud`
     * de las corridas de ecommerce sobre timestamps que salen de la base, y rechazar formatos ahí
     * cambiaría cómo se calcula `deployment_stale` en endpoints que ya estaban.
     *
     * 🔴 ES `public static` PORQUE TIENE UN SEGUNDO LLAMADOR, Y ESO ES EL PUNTO:
     * `ClaudeEcommerceOpsController::installations_json()` valida ahí sus `desde` / `hasta` con
     * ESTA función y no con una copia. Los dos endpoints tienen que responder lo mismo a la
     * pregunta "¿qué es una fecha válida?": dos definiciones se desincronizan y la que se queda
     * vieja es siempre la que nadie mira. Es una función pura sobre un string, así que no necesita
     * instancia ni estado.
     *
     * @param string $texto Valor ya recortado.
     *
     * @return Carbon|null
     */
    public static function fecha_estricta($texto)
    {
        $zona = new \DateTimeZone((string) config('app.timezone'));

        foreach (self::FORMATOS_DE_FECHA as $formato) {
            $fecha   = \DateTime::createFromFormat($formato, $texto, $zona);
            $errores = \DateTime::getLastErrors();

            /* Un warning alcanza para rechazar: es lo que denuncia "2026-02-30" (día desbordado) y
               "2026-08-01xxx" (basura pegada al final), que si no entrarían como fechas válidas. */
            $limpio = $errores === false
                || (is_array($errores) && (int) $errores['error_count'] === 0 && (int) $errores['warning_count'] === 0);

            if ($fecha !== false && $limpio) {
                return Carbon::instance($fecha);
            }
        }

        return null;
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

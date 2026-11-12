<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Corre una consulta SQL de SOLO LECTURA sobre la base del admin y devuelve el resultado ya
 * filtrado (`POST claude/support-codex/admin-sql`, misión `acceso-soporte-al-admin-lectura`,
 * 11/11/2026).
 *
 * 🔴 EL MODELO: EL TEXTO SOLO SALE COMO COLUMNA ENTERA DE UNA TABLA; LAS EXPRESIONES SOLO
 * DEVUELVEN NÚMEROS Y FECHAS. Tres rondas de revisión encontraron, cada una, una forma nueva de
 * sacar un secreto validando solo el TEXTO de la consulta (un alias, un `JSON_EXTRACT`, una letra
 * Unicode que MySQL pliega a `k`, un `CONCAT` con bytes inválidos). Por eso la reja principal ya
 * no mira la consulta: mira el RESULTADO, columna por columna, con lo que MySQL informa de cada
 * una (`mysqli_result::fetch_fields()`):
 *
 *  - **columna directa de una tabla** (`orgtable` y `orgname` son una tabla y una columna reales
 *    de esta base): se decide por su nombre REAL, no por el alias. Tabla vedada → se rechaza el
 *    resultado entero; nombre sensible o reservado → máscara; JSON / texto largo / binario → solo
 *    si `tabla.columna` está en `columnas_de_texto_revisadas`, y pasa por el redactor; `varchar` →
 *    pasa por el redactor; número o fecha → tal cual;
 *  - **expresión** (una función, una cuenta, un literal, una subconsulta escalar): solo sale si su
 *    TIPO es numérico, de fecha/hora o NULL. Toda expresión de texto sale con la máscara, sea lo
 *    que sea: `CONCAT`, `UPPER`, `MAX(body)`, `IF(…, 'a', 'b')`.
 *
 * Las rejas, en orden, y ninguna confía en la anterior:
 *
 *  1. `ClaudeAdminSqlValidator` rechaza lo que no sea una lectura admitida, antes de tocar MySQL.
 *  2. La sesión es de solo lectura (`READ ONLY`, error 1792 ante una escritura), con tope de tiempo.
 *  3. `EXPLAIN` con `derived_merge=off`: si el plan tiene una tabla derivada o un `UNION`, se
 *     rechaza. Ver `rechazo_por_la_forma_del_plan()`: es lo que hace valer la reja 4.
 *  4. La reja por origen de cada columna, descripta arriba.
 *  5. El redactor sobre lo que sí sale (claves de JSON, etiquetas de clave en un texto).
 *
 * 🔴 CORRE POR `mysqli`, EN UNA CONEXIÓN APARTE, NO POR LA DE LA APLICACIÓN. Por `mysqli` porque
 * PDO no informa la tabla y la columna de origen de cada campo. Aparte porque `READ ONLY` y
 * `START TRANSACTION` son estado de la SESIÓN: sobre la conexión por defecto dejarían al resto
 * del request en solo lectura y confirmarían de prepo una transacción abierta (la de un test con
 * `DatabaseTransactions`). La conexión se arma con la config de la conexión por defecto, se usa
 * para UNA consulta y se cierra. Si `mysqli` no está, el endpoint contesta 503 `no_disponible`:
 * nunca cae a PDO, que no puede hacer la reja 4.
 */
class ClaudeAdminSqlService
{
    /** Tipos de campo de MySQL que una EXPRESIÓN puede devolver: números. */
    const TIPOS_NUMERICOS = [0, 1, 2, 3, 4, 5, 8, 9, 13, 246];

    /** Tipos de campo de MySQL que una EXPRESIÓN puede devolver: fechas y horas. */
    const TIPOS_DE_FECHA = [7, 10, 11, 12, 14];

    /** Tipo NULL (una expresión que vale NULL en todas las filas). */
    const TIPO_NULL = 6;

    /** Tipos de dato de una columna (information_schema) que se devuelven tal cual. */
    const DATOS_NUMERICOS_O_FECHA = [
        'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real',
        'year', 'date', 'datetime', 'timestamp', 'time',
    ];

    /** Tipos de dato de texto corto: pasan por el redactor de texto, sin lista de revisadas. */
    const DATOS_DE_TEXTO_CORTO = ['varchar', 'char', 'enum', 'set'];

    /** Tipos de dato de texto libre o JSON: solo salen si `tabla.columna` está revisada. */
    const DATOS_DE_TEXTO_LIBRE = ['json', 'text', 'tinytext', 'mediumtext', 'longtext'];

    /**
     * `select_type` de EXPLAIN que se admiten. `SUBQUERY` / `DEPENDENT SUBQUERY` / `MATERIALIZED`
     * son un `IN (SELECT …)` o un `EXISTS (…)`. Lo que NO está —`DERIVED`, `UNION`, `UNION RESULT`,
     * `DEPENDENT UNION`, `DEPENDENT DERIVED`, `UNCACHEABLE …`— hace rechazar la consulta.
     */
    const PLANES_ADMITIDOS = ['SIMPLE', 'PRIMARY', 'SUBQUERY', 'DEPENDENT SUBQUERY', 'MATERIALIZED'];

    /**
     * Subcadenas que delatan una clave de JSON (o un `clave: valor` dentro de un texto) con una
     * credencial. Por subcadena y no por nombre exacto: `user_pin`, `clave_admin`, `pwd`.
     */
    const ETIQUETAS_DE_CLAVE_JSON = 'pass|pwd|pin|clave|contrasen|contraseñ|token|secret|key|credential';

    /** @var ClaudeAdminSqlValidator */
    private $validador;

    /**
     * @param ClaudeAdminSqlValidator $validador Validador de la consulta.
     */
    public function __construct(ClaudeAdminSqlValidator $validador)
    {
        $this->validador = $validador;
    }

    /**
     * Valida, corre y filtra. Es lo que llama el endpoint.
     *
     * @param mixed $sql La consulta tal como llegó.
     *
     * @return array<string, mixed> Con `ok` true: `read_only`, `columns`, `rows`, `row_count`,
     *                              `truncated`. Con `ok` false: `codigo` y `error` (y `http` 503
     *                              si el servicio no está disponible).
     */
    public function consultar($sql)
    {
        $validacion = $this->validador->validar($sql);

        if (! $validacion['ok']) {
            return $validacion;
        }

        return $this->ejecutar_y_filtrar($validacion['sql'], $validacion['clase'] !== 'select');
    }

    /**
     * Corre una sentencia YA VALIDADA en solo lectura y aplica la reja por origen de columna.
     *
     * 🔴 NO VALIDA EL TEXTO. Es pública para que los tests puedan mandarle lo que el validador
     * rechazaría (`SELECT api_key AS x FROM clients`, `SELECT * FROM admin_settings`, un UPDATE) y
     * comprobar que las rejas de este lado existen por sí solas. En la aplicación solo la llama
     * `consultar()`.
     *
     * @param string $sql        Sentencia.
     * @param bool   $de_esquema True para `SHOW TABLES`, `SHOW COLUMNS`, `DESCRIBE` y `EXPLAIN`:
     *                           su resultado es metadata (nombres de tablas, columnas, el plan) y
     *                           sale tal cual, sin reja por origen. NUNCA true para un SELECT.
     *
     * @return array<string, mixed>
     */
    public function ejecutar_y_filtrar($sql, $de_esquema = false)
    {
        if (! extension_loaded('mysqli')) {
            return $this->no_disponible('La extensión mysqli de PHP no está cargada.');
        }

        $config = (array) config('database.connections.' . config('database.default'));

        if (! isset($config['driver']) || $config['driver'] !== 'mysql') {
            return $this->no_disponible('La conexión por defecto no es MySQL.');
        }

        $max_filas = (int) config('claude_admin_sql.max_filas', 200);
        $mysqli    = null;

        try {
            $mysqli = $this->conectar($config);

            if ($mysqli === null) {
                return $this->no_disponible('No se pudo abrir la conexión de solo lectura.');
            }

            $this->preparar_la_sesion($mysqli);

            $esquema = [];

            if (! $de_esquema) {
                $rechazo = $this->rechazo_por_la_forma_del_plan($mysqli, $sql);
                if ($rechazo !== null) {
                    return $rechazo;
                }

                $esquema = $this->leer_el_esquema($mysqli);
            }

            $resultado = $mysqli->query($sql, MYSQLI_USE_RESULT);

            if ($resultado === false) {
                return $this->fallo($mysqli->errno, $mysqli->sqlstate);
            }

            if ($resultado === true) {
                /* Una sentencia que no devuelve filas no es una lectura. En solo lectura no pudo
                   escribir nada, pero tampoco hay nada que devolver. */
                return $this->fallo(0, null);
            }

            $campos = [];
            foreach ($resultado->fetch_fields() as $campo) {
                $campos[] = $campo;
            }

            $politica = [];
            if (! $de_esquema) {
                $base_actual = isset($config['database']) ? (string) $config['database'] : '';

                foreach ($campos as $campo) {
                    $decision = $this->decidir_un_campo($campo, $esquema, $base_actual);

                    if (isset($decision['rechazo'])) {
                        return $decision['rechazo'];
                    }

                    $politica[] = $decision;
                }
            }

            $columnas = [];
            foreach ($campos as $campo) {
                if (! in_array((string) $campo->name, $columnas, true)) {
                    $columnas[] = (string) $campo->name;
                }
            }

            $filas     = [];
            $recortado = false;

            while (($fila = $resultado->fetch_row()) !== null && $fila !== false) {
                if (count($filas) >= $max_filas) {
                    $recortado = true;
                    break;
                }

                $salida = [];
                foreach ($fila as $indice => $valor) {
                    $nombre          = (string) $campos[$indice]->name;
                    $salida[$nombre] = $de_esquema
                        ? $this->recortar($this->texto_de_esquema($valor))
                        : $this->recortar($this->aplicar_la_decision($valor, $politica[$indice]));
                }

                $filas[] = $salida;
            }

            if ($mysqli->errno !== 0) {
                return $this->fallo($mysqli->errno, $mysqli->sqlstate);
            }

            return [
                'ok'        => true,
                'read_only' => true,
                'columns'   => $columnas,
                'rows'      => $filas,
                'row_count' => count($filas),
                'truncated' => $recortado,
            ];
        } catch (\Throwable $e) {
            Log::warning('claude admin-sql: excepción', ['clase' => get_class($e)]);

            return $this->fallo(0, null);
        } finally {
            if ($mysqli !== null) {
                /* Cerrar la conexión deshace la transacción y descarta el resto de un resultado
                   que se cortó en `max_filas`: no hay nada que confirmar en una lectura. */
                try {
                    $mysqli->close();
                } catch (\Throwable $e) {
                    /* Ya estaba cerrada. */
                }
            }
        }
    }

    /**
     * Abre la conexión descartable con la config de la conexión por defecto.
     *
     * @param array<string, mixed> $config Config de la conexión por defecto.
     *
     * @return \mysqli|null Null si no se pudo conectar.
     */
    private function conectar(array $config)
    {
        $mysqli = mysqli_init();

        if (! $mysqli) {
            return null;
        }

        /* Números como números: sin esto todo vuelve como texto. */
        $mysqli->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

        $socket = isset($config['unix_socket']) && $config['unix_socket'] !== '' ? (string) $config['unix_socket'] : null;

        $conecto = @$mysqli->real_connect(
            $socket === null ? (isset($config['host']) ? (string) $config['host'] : '127.0.0.1') : null,
            isset($config['username']) ? (string) $config['username'] : '',
            isset($config['password']) ? (string) $config['password'] : '',
            isset($config['database']) ? (string) $config['database'] : '',
            isset($config['port']) && $config['port'] !== '' ? (int) $config['port'] : 3306,
            $socket
        );

        if (! $conecto) {
            return null;
        }

        return $mysqli;
    }

    /**
     * Deja la sesión en solo lectura, con tope de tiempo y con las reglas de lectura que el
     * validador supone. Si algo de lo que es REJA no se puede setear, se lanza y la consulta no corre.
     *
     * @param \mysqli $mysqli Conexión descartable.
     *
     * @return void
     *
     * @throws \RuntimeException Si no se pudo dejar la sesión en solo lectura.
     */
    private function preparar_la_sesion(\mysqli $mysqli)
    {
        if (! $mysqli->set_charset('utf8mb4')) {
            throw new \RuntimeException('charset');
        }

        $zona = config('database.connections.' . config('database.default') . '.timezone');
        if (is_string($zona) && preg_match('/^[+\-]\d{2}:\d{2}$/', $zona)) {
            $mysqli->query("SET time_zone = '" . $zona . "'");
        }

        /* Tope de tiempo. `max_execution_time` es de MySQL (ms); MariaDB usa `max_statement_time`
           (s). Si ninguna existe, corre sin tope de base: no es una reja de seguridad. */
        $segundos = (int) config('claude_admin_sql.max_segundos', 8);
        if ($segundos > 0 && ! $mysqli->query('SET SESSION max_execution_time = ' . ($segundos * 1000))) {
            $mysqli->query('SET SESSION max_statement_time = ' . $segundos);
        }

        /* El validador lee la consulta con las reglas de MySQL por defecto: `\'` escapa una
           comilla y las comillas dobles no son identificadores. */
        if (! $mysqli->query("SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_BACKSLASH_ESCAPES', ''), 'ANSI_QUOTES', ''), 'ANSI', '')")) {
            throw new \RuntimeException('sql_mode');
        }

        /* Sin fusión de tablas derivadas: así EXPLAIN las muestra siempre como DERIVED. Ver
           rechazo_por_la_forma_del_plan(). */
        if (! $mysqli->query("SET SESSION optimizer_switch = 'derived_merge=off'")) {
            throw new \RuntimeException('optimizer_switch');
        }

        if (! $mysqli->query('SET SESSION TRANSACTION READ ONLY') || ! $mysqli->query('START TRANSACTION READ ONLY')) {
            throw new \RuntimeException('read only');
        }
    }

    /**
     * Rechaza la consulta si su plan tiene una tabla derivada o un UNION.
     *
     * 🔴 POR QUÉ HACE FALTA, MEDIDO EL 11/11/2026 CONTRA MYSQL 8.3. La reja por origen confía en
     * que `orgname` es el nombre real de la columna. Es verdad para un alias común
     * (`SELECT api_key AS x` → `orgname = api_key`), pero NO a través de una tabla derivada:
     * `SELECT name FROM (SELECT api_key AS name FROM clients) clients` informa `orgtable = clients`
     * y `orgname = name`, indistinguible de la columna `clients.name` de verdad. Y un UNION informa
     * el nombre de la primera rama. El validador ya rechaza las dos formas mirando el texto; esto
     * las rechaza mirando el PLAN, que no se puede disfrazar con una letra rara ni con un
     * paréntesis de más. Con `derived_merge=off` toda tabla derivada (también un `WITH` y un
     * `TABLE`) aparece como `DERIVED`.
     *
     * @param \mysqli $mysqli Conexión descartable, ya en solo lectura.
     * @param string  $sql    Sentencia.
     *
     * @return array<string, mixed>|null El rechazo, o null si el plan se admite.
     */
    private function rechazo_por_la_forma_del_plan(\mysqli $mysqli, $sql)
    {
        $plan = $mysqli->query('EXPLAIN ' . $sql);

        if ($plan === false) {
            return $this->fallo($mysqli->errno, $mysqli->sqlstate);
        }

        if ($plan === true) {
            return $this->fallo(0, null);
        }

        $tipos = [];
        while (($fila = $plan->fetch_assoc()) !== null && $fila !== false) {
            $tipos[] = isset($fila['select_type']) ? strtoupper((string) $fila['select_type']) : '';
        }
        $plan->free();

        if ($tipos === []) {
            return $this->fallo(0, null);
        }

        foreach ($tipos as $tipo) {
            if (! in_array($tipo, self::PLANES_ADMITIDOS, true)) {
                return [
                    'ok'     => false,
                    'codigo' => 'subconsulta_no_permitida',
                    'error'  => 'La consulta usa una tabla derivada, un UNION o una subconsulta que no se admite (' . strtolower($tipo) . '). Solo se admiten subconsultas después de IN o de EXISTS.',
                ];
            }
        }

        return null;
    }

    /**
     * Las tablas y columnas REALES de la base, con su tipo de dato: `[tabla][columna] => tipo`,
     * todo en minúscula. Se lee en cada consulta: es lo que impide que un nombre de origen
     * inventado pase por columna de tabla.
     *
     * @param \mysqli $mysqli Conexión descartable.
     *
     * @return array<string, array<string, string>>
     *
     * @throws \RuntimeException Si no se pudo leer.
     */
    private function leer_el_esquema(\mysqli $mysqli)
    {
        $resultado = $mysqli->query(
            'SELECT table_name AS t, column_name AS c, data_type AS d FROM information_schema.columns WHERE table_schema = DATABASE()'
        );

        if (! $resultado instanceof \mysqli_result) {
            throw new \RuntimeException('esquema');
        }

        $esquema = [];
        while (($fila = $resultado->fetch_assoc()) !== null && $fila !== false) {
            $esquema[strtolower((string) $fila['t'])][strtolower((string) $fila['c'])] = strtolower((string) $fila['d']);
        }
        $resultado->free();

        if ($esquema === []) {
            throw new \RuntimeException('esquema vacío');
        }

        return $esquema;
    }

    /**
     * Decide qué se hace con una columna del resultado, por su ORIGEN. Fail-closed: lo que no se
     * reconoce sale con la máscara.
     *
     * @param object                               $campo       Campo de `fetch_fields()`.
     * @param array<string, array<string, string>> $esquema     Tablas y columnas reales.
     * @param string                               $base_actual Nombre de la base del admin.
     *
     * @return array<string, mixed> `['accion' => crudo | texto | json | mascara, 'nombre' => …]`,
     *                              o `['rechazo' => […]]` si el resultado entero no se sirve.
     */
    private function decidir_un_campo($campo, array $esquema, $base_actual)
    {
        $tabla   = strtolower((string) $campo->orgtable);
        $columna = strtolower((string) $campo->orgname);
        $base    = (string) $campo->db;

        $es_de_tabla = $tabla !== '' && $columna !== ''
            && strcasecmp($base, $base_actual) === 0
            && isset($esquema[$tabla][$columna]);

        /* Una tabla vedada rechaza TODO, aunque la columna pedida parezca inocente y aunque el
           nombre de origen no sea una columna real (una tabla derivada sobre una vedada). */
        if ($tabla !== '' && $this->es_una_tabla_vedada($tabla)) {
            return ['rechazo' => [
                'ok'     => false,
                'codigo' => 'tabla_vedada',
                'error'  => 'El resultado trae datos de la tabla `' . $tabla . '`, que no se puede consultar: guarda credenciales o secretos.',
            ]];
        }

        /* Otra base (information_schema, mysql, …): no se sirve nada de ahí. */
        if ($tabla !== '' && $base !== '' && strcasecmp($base, $base_actual) !== 0) {
            return ['rechazo' => [
                'ok'     => false,
                'codigo' => 'otra_base',
                'error'  => 'El resultado trae datos de otra base. La consulta debe usar únicamente tablas de la base del admin.',
            ]];
        }

        if (! $es_de_tabla) {
            /* Expresión (o un origen que no es una columna real): solo números, fechas y NULL. */
            $tipo = (int) $campo->type;

            if (in_array($tipo, self::TIPOS_NUMERICOS, true) || in_array($tipo, self::TIPOS_DE_FECHA, true) || $tipo === self::TIPO_NULL) {
                return ['accion' => 'crudo'];
            }

            return ['accion' => 'mascara'];
        }

        if ($this->es_un_nombre_sensible($columna)) {
            return ['accion' => 'mascara'];
        }

        $dato = $esquema[$tabla][$columna];

        if (in_array($dato, self::DATOS_NUMERICOS_O_FECHA, true)) {
            return ['accion' => 'crudo'];
        }

        if (in_array($dato, self::DATOS_DE_TEXTO_CORTO, true)) {
            return ['accion' => 'texto', 'nombre' => $columna];
        }

        if (in_array($dato, self::DATOS_DE_TEXTO_LIBRE, true)) {
            $revisadas = (array) config('claude_admin_sql.columnas_de_texto_revisadas');

            return isset($revisadas[$tabla . '.' . $columna]) ? ['accion' => 'texto', 'nombre' => $columna] : ['accion' => 'mascara'];
        }

        /* blob, binary, varbinary, bit, geometry y cualquier tipo que no se conozca. */
        return ['accion' => 'mascara'];
    }

    /**
     * Aplica la decisión de `decidir_un_campo()` a un valor.
     *
     * @param mixed                $valor    Valor de la fila.
     * @param array<string, mixed> $decision Decisión del campo.
     *
     * @return mixed
     */
    private function aplicar_la_decision($valor, array $decision)
    {
        /* La máscara va primero: de una columna reservada no sale ni si está vacía. */
        if ($decision['accion'] !== 'crudo' && $decision['accion'] !== 'texto') {
            return $this->mascara();
        }

        if ($valor === null) {
            return null;
        }

        if ($decision['accion'] === 'crudo') {
            /* Un tipo numérico o de fecha: el valor es un número o una fecha escrita por MySQL.
               Si aun así no es UTF-8 limpio, no sale. */
            return is_string($valor) && ! mb_check_encoding($valor, 'UTF-8') ? $this->mascara() : $valor;
        }

        return $this->redactar($valor, isset($decision['nombre']) ? (string) $decision['nombre'] : '');
    }

    /**
     * Un valor de `SHOW` / `DESCRIBE` / `EXPLAIN`: metadata. Sale tal cual si es UTF-8 válido.
     *
     * @param mixed $valor Valor.
     *
     * @return mixed
     */
    private function texto_de_esquema($valor)
    {
        return is_string($valor) && ! mb_check_encoding($valor, 'UTF-8') ? $this->mascara() : $valor;
    }

    /**
     * Arma el fallo SIN el mensaje del servidor, que puede citar valores de la base. Sale el
     * SQLSTATE, el código de MySQL y un resumen fijo por código.
     *
     * @param int         $codigo_mysql Código de error de MySQL (0 si no hay).
     * @param string|null $sqlstate     SQLSTATE.
     *
     * @return array<string, mixed>
     */
    private function fallo($codigo_mysql, $sqlstate)
    {
        $codigo_mysql = (int) $codigo_mysql;

        $resumenes = [
            1054 => 'Una de las columnas que nombra la consulta no existe. Mirá las columnas con SHOW COLUMNS FROM tabla.',
            1064 => 'MySQL no pudo interpretar la consulta: revisá la sintaxis.',
            1146 => 'Una de las tablas que nombra la consulta no existe. Mirá las tablas con SHOW TABLES.',
            1052 => 'Una columna es ambigua: calificala con el alias de su tabla.',
            1055 => 'La consulta agrupa mal: toda columna del SELECT tiene que estar en el GROUP BY o dentro de una función de agregación.',
            1140 => 'La consulta mezcla una función de agregación con columnas sin GROUP BY.',
            1792 => 'La consulta intenta escribir y la transacción es de solo lectura.',
            3024 => 'La consulta superó el tiempo máximo de ejecución. Acotala con WHERE o LIMIT.',
            1969 => 'La consulta superó el tiempo máximo de ejecución. Acotala con WHERE o LIMIT.',
        ];

        Log::warning('claude admin-sql: consulta fallida', ['sqlstate' => $sqlstate, 'codigo_mysql' => $codigo_mysql]);

        return [
            'ok'           => false,
            'codigo'       => 'consulta_fallida',
            'error'        => isset($resumenes[$codigo_mysql]) ? $resumenes[$codigo_mysql] : 'MySQL rechazó la consulta.',
            'sqlstate'     => $sqlstate === null || $sqlstate === '' || $sqlstate === '00000' ? null : (string) $sqlstate,
            'codigo_mysql' => $codigo_mysql === 0 ? null : $codigo_mysql,
        ];
    }

    /**
     * El servicio no puede atender: 503, sin ejecutar nada.
     *
     * @param string $motivo Motivo, para el log.
     *
     * @return array<string, mixed>
     */
    private function no_disponible($motivo)
    {
        Log::error('claude admin-sql: no disponible', ['motivo' => $motivo]);

        return [
            'ok'     => false,
            'codigo' => 'no_disponible',
            'error'  => 'La consulta SQL del admin no está disponible en este servidor.',
            'http'   => 503,
        ];
    }

    /**
     * La máscara de un valor reservado.
     *
     * @return string
     */
    private function mascara()
    {
        return (string) config('claude_admin_sql.mascara', '[dato reservado]');
    }

    /**
     * ¿La tabla está en `claude_admin_sql.tablas_vedadas`?
     *
     * @param string $tabla Nombre real de la tabla.
     *
     * @return bool
     */
    private function es_una_tabla_vedada($tabla)
    {
        $tabla = strtolower((string) $tabla);

        foreach ((array) config('claude_admin_sql.tablas_vedadas') as $vedada => $motivo) {
            if (strtolower((string) $vedada) === $tabla) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿El nombre (de columna, o de clave de JSON en la raíz) matchea el patrón sensible o está en
     * `columnas_reservadas`? Si la expresión regular falla, es sensible.
     *
     * @param string $nombre Nombre a mirar.
     *
     * @return bool
     */
    private function es_un_nombre_sensible($nombre)
    {
        $nombre = (string) $nombre;
        $patron = (string) config('claude_admin_sql.patron_sensible');

        if ($patron !== '' && preg_match('~' . $patron . '~i', $nombre) !== 0) {
            return true;
        }

        $minuscula = mb_strtolower($nombre, 'UTF-8');

        foreach ((array) config('claude_admin_sql.columnas_reservadas') as $reservada => $motivo) {
            if (mb_strtolower((string) $reservada, 'UTF-8') === $minuscula) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿La clave de un JSON delata una credencial? Más ancha que `es_un_nombre_sensible()`: además
     * mira por SUBCADENA las etiquetas de clave (`user_pin`, `clave_admin`, `pwd`, `api-key`).
     * Tapa de más a propósito (`shipping`, `monkey`): adentro de un JSON nadie revisó cada clave.
     *
     * @param string $clave Clave del JSON.
     *
     * @return bool
     */
    private function es_una_clave_json_sensible($clave)
    {
        if ($this->es_un_nombre_sensible($clave)) {
            return true;
        }

        return preg_match('~' . self::ETIQUETAS_DE_CLAVE_JSON . '~iu', (string) $clave) !== 0;
    }

    /**
     * Redacta una fila por NOMBRE de columna, sin origen. Es la capa del redactor sola —lo que
     * queda después de la reja por origen—; la usan los tests para probarla con filas armadas a
     * mano. En la aplicación el camino es `ejecutar_y_filtrar()`.
     *
     * @param array<string, mixed> $fila Fila.
     *
     * @return array<string, mixed>
     */
    public function redactar_fila(array $fila)
    {
        $salida = [];

        foreach ($fila as $columna => $valor) {
            $salida[$columna] = $this->recortar($this->redactar($valor, (string) $columna));
        }

        return $salida;
    }

    /**
     * Redacta un valor según el nombre bajo el que viene.
     *
     *  - Nombre sensible o reservado → la máscara, sin mirar el valor.
     *  - Arreglo → recursivo, por clave (con la regla ancha de claves de JSON).
     *  - Texto que no es UTF-8 válido → la máscara: las reglas de texto no lo pueden leer.
     *  - Texto que es un JSON → se decodifica, se redacta por clave y se vuelve a codificar.
     *  - Cualquier otro texto → `tapar_texto()`.
     *
     * 🔴 FALLA CERRADO: si una expresión regular devuelve null (un límite de PCRE, un byte raro),
     * sale la máscara, nunca el valor.
     *
     * @param mixed  $valor         Valor a redactar.
     * @param string $clave         Nombre de columna o clave del JSON.
     * @param bool   $es_clave_json True adentro de un JSON: aplica la regla ancha de claves.
     *
     * @return mixed
     */
    public function redactar($valor, $clave = '', $es_clave_json = false)
    {
        $clave = (string) $clave;

        if ($clave !== '' && ($es_clave_json ? $this->es_una_clave_json_sensible($clave) : $this->es_un_nombre_sensible($clave))) {
            return $this->mascara();
        }

        if (is_array($valor)) {
            foreach ($valor as $k => $v) {
                $valor[$k] = $this->redactar($v, (string) $k, true);
            }

            return $valor;
        }

        if (! is_string($valor)) {
            return $valor;
        }

        if (! mb_check_encoding($valor, 'UTF-8')) {
            return $this->mascara();
        }

        $primero = substr(ltrim($valor), 0, 1);
        if ($primero === '{' || $primero === '[') {
            $json = json_decode($valor, true);

            if (is_array($json)) {
                $redactado = [];
                foreach ($json as $k => $v) {
                    $redactado[$k] = $this->redactar($v, (string) $k, true);
                }

                $codificado = json_encode($redactado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return $codificado === false ? $this->mascara() : $codificado;
            }
        }

        return $this->tapar_texto($valor);
    }

    /**
     * Las reglas de texto, en orden. Cada una falla cerrado.
     *
     *  1. Etiquetas XML/HTML de credenciales (`<Token>…</Token>`).
     *  2. `Bearer …`.
     *  3. `nombre: valor` / `"nombre": valor` / `NOMBRE=valor` cuando el nombre matchea el patrón
     *     sensible, es una columna reservada o contiene una etiqueta de clave: se va el resto de
     *     la línea (o la línea siguiente, si después del `:` la línea termina). Cubre una línea de
     *     .env, y un JSON que llegó roto o pegado a otro texto.
     *  4. `SupportImplementationContextService::ocultar_claves`, la regla del contexto del ticket.
     *  5. Una etiqueta de clave suelta («contraseña», «password», «pin»…): el resto de la línea.
     *
     * @param string $valor Texto UTF-8 válido.
     *
     * @return string
     */
    private function tapar_texto($valor)
    {
        $mascara = $this->mascara();
        $patron  = (string) config('claude_admin_sql.patron_sensible');

        $valor = $this->tapar_etiquetas_de_credenciales($valor, $patron, $mascara);
        if ($valor === null) {
            return $mascara;
        }

        $valor = preg_replace('~(Bearer\s+)[A-Za-z0-9._\~+/\-]+~iu', '$1' . $mascara, $valor);
        if ($valor === null) {
            return $mascara;
        }

        $nombres = [self::ETIQUETAS_DE_CLAVE_JSON];
        if ($patron !== '') {
            $nombres[] = $patron;
        }
        foreach ((array) config('claude_admin_sql.columnas_reservadas') as $reservada => $motivo) {
            $nombres[] = '(?<![\p{L}\p{N}])' . preg_quote((string) $reservada, '~') . '(?![\p{L}\p{N}])';
        }

        $valor = preg_replace(
            '~((?:[\p{L}\p{N}_.\-]*(?:' . implode('|', $nombres) . ')[\p{L}\p{N}_.\-]*)["\']?[ \t]*[:=][ \t]*(?:\r?\n[ \t]*)?)[^\r\n]*~iu',
            '$1' . $mascara,
            $valor
        );
        if ($valor === null) {
            return $mascara;
        }

        $valor = SupportImplementationContextService::ocultar_claves($valor);
        if ($valor === null) {
            return $mascara;
        }

        $valor = $this->tapar_despues_de_una_etiqueta_de_clave($valor);

        return $valor === null ? $mascara : $valor;
    }

    /**
     * Tapa el contenido de las etiquetas XML/HTML de credenciales: las que se llaman `Token`,
     * `Sign`, `Password` o `Clave`, y toda etiqueta cuyo nombre matchee el patrón sensible. Con o
     * sin prefijo de namespace (`<ar:Token>`), con atributos, sin distinguir mayúsculas, y también
     * cuando el XML viene escapado (`&lt;Token&gt;…&lt;/Token&gt;`).
     *
     * 🔴 POR QUÉ: el ticket de acceso de ARCA viaja como `<Auth><Token>…</Token><Sign>…</Sign>` y
     * ninguna de las otras reglas de texto lo ve. La columna que lo guarda está reservada; esto es
     * la segunda reja, para el próximo XML que aparezca en otra columna.
     *
     * @param string $valor   Texto.
     * @param string $patron  Patrón sensible (sin delimitadores).
     * @param string $mascara Máscara.
     *
     * @return string|null Null si una expresión regular falló (el que llama tapa todo).
     */
    private function tapar_etiquetas_de_credenciales($valor, $patron, $mascara)
    {
        if (strpos($valor, '<') === false && stripos($valor, '&lt;') === false) {
            return $valor;
        }

        $nombre = '(?:[\w.\-]+:)?(?:Token|Sign|Password|Clave'
            . ($patron === '' ? '' : '|[\w.\-]*(?:' . $patron . ')[\w.\-]*')
            . ')';

        $tapado = preg_replace(
            '~<(' . $nombre . ')(\s[^<>]*)?>.*?</\1\s*>~isu',
            '<$1$2>' . $mascara . '</$1>',
            $valor
        );

        if ($tapado === null) {
            return null;
        }

        return preg_replace(
            '~&lt;(' . $nombre . ')((?:\s(?:(?!&gt;).)*)?)&gt;.*?&lt;/\1\s*&gt;~isu',
            '&lt;$1$2&gt;' . $mascara . '&lt;/$1&gt;',
            $tapado
        );
    }

    /**
     * Tapa TODO el resto de la línea después de una palabra que anuncia una clave: «contraseña»,
     * «clave», «password», «passwd», «pass», «pwd», «pin», «token» (y sus plurales), sin distinguir
     * mayúsculas ni la eñe. Si después de la etiqueta la línea termina (con o sin `:`), tapa la
     * línea siguiente.
     *
     * 🔴 TAPA DE MÁS A PROPÓSITO. `ocultar_claves` —la regla del contexto del ticket, que acá se
     * sigue aplicando— tapa solo la palabra que sigue a «contraseña» / «clave», salteando un
     * conector: con «la contraseña del router es 1234» tapa «del» y deja el valor, y «El password
     * del wifi: X» no lo ve. No hay forma de saber cuántas palabras hay entre la etiqueta y el
     * valor, así que se va la línea entera. El costo es que «Clave Fiscal nivel 3» o «tokens
     * consumidos: 500» también pierden el resto de la línea; por SQL eso es aceptable.
     *
     * 🔴 LA ETIQUETA NO NECESITA EMPEZAR UNA PALABRA: con «xContraseña: 1234» también se tapa. Sí
     * tiene que TERMINARLA: «pinturería» o «tokenizar» no disparan nada.
     *
     * No toca un nombre de etiqueta XML (`<Token>`, `</ar:Token>`): de esos se ocupa
     * `tapar_etiquetas_de_credenciales`, que deja el resto del documento legible.
     *
     * @param string $valor Texto UTF-8 válido.
     *
     * @return string|null Null si la expresión regular falló (el que llama tapa todo).
     */
    private function tapar_despues_de_una_etiqueta_de_clave($valor)
    {
        $etiquetas = '(?:contrase(?:ñ|n|\?)as?|claves?|passwords?|passwd|pass|pwd|pins?|tokens?)';
        $resto     = '([ \t]*[:=]?[ \t]*(?:\r?\n[ \t]*)?)[^\r\n]+';

        return preg_replace(
            '~(?<![</])(?<![\p{L}\p{N}_]:)(' . $etiquetas . ')(?![\p{L}\p{N}_])' . $resto . '~iu',
            '$1$2' . SupportImplementationContextService::MASCARA_DE_CLAVE,
            $valor
        );
    }

    /**
     * Corta un texto al largo máximo por valor, y lo dice.
     *
     * @param mixed $valor Valor ya filtrado.
     *
     * @return mixed
     */
    private function recortar($valor)
    {
        if (! is_string($valor)) {
            return $valor;
        }

        $max = (int) config('claude_admin_sql.max_caracteres_valor', 4000);

        return mb_strlen($valor, 'UTF-8') > $max ? mb_substr($valor, 0, $max, 'UTF-8') . ' [recortado]' : $valor;
    }
}

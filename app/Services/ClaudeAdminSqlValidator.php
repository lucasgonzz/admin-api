<?php

namespace App\Services;

/**
 * Decide si una consulta SQL se puede correr, de solo lectura, sobre la base del ADMIN
 * (`POST claude/support-codex/admin-sql`, misión `acceso-soporte-al-admin-lectura`, 11/11/2026).
 *
 * Es un servicio PURO: recibe texto y contesta sí o no, sin tocar la base. Por eso se prueba con
 * tests unitarios, barrera por barrera.
 *
 * 🔴 NACIÓ COMO UN PORTE DE `validar_sql` DE `herramientas/soporte-codex/diagnostico.py` (repo de
 * conocimiento), que es la que valida el SQL contra el sistema de un cliente. La lista de palabras
 * prohibidas es LA MISMA (más cinco) y la de funciones es un subconjunto. Lo que esta ENDURECE,
 * porque la base del admin guarda las claves de todos los clientes:
 *
 *  - sin `UNION`, sin `WITH`, sin `TABLE`/`VALUES` y sin `SHOW CREATE TABLE`. Las cuatro primeras
 *    son formas de RENOMBRAR columnas sin nombrarlas (`SELECT 1,2 UNION SELECT * FROM clients`,
 *    `(TABLE clients) AS t(a, b, …)`), y la redacción del resultado es por nombre de columna;
 *  - `*` comodín adentro de un paréntesis solo como `COUNT(*)`, por el mismo motivo;
 *  - sin comillas dobles: con `ANSI_QUOTES` serían identificadores, y acá se vaciarían como si
 *    fueran un dato literal;
 *  - un identificador entre backticks solo puede ser un nombre simple (`` `clients` ``). Un alias
 *    como `` `a'` `` haría que este validador viera un literal donde MySQL ve un nombre, y entre
 *    dos de esos se esconde `api_key AS k`;
 *  - sin funciones que devuelvan un fragmento de su argumento (`SUBSTRING`, `LEFT`, `RIGHT`,
 *    `TRIM`, `CAST`, `CONVERT`) ni que extraigan una parte de un JSON (`JSON_EXTRACT`,
 *    `JSON_UNQUOTE`, `->`, `->>`): ver `FUNCIONES` y `FUNCIONES_QUITADAS`;
 *  - el patrón de nombres sensibles ampliado, la lista de tablas vedadas y la de columnas
 *    reservadas, las tres en `config/claude_admin_sql.php`.
 *
 * Y lo que AFLOJA respecto del original, sin abrir nada (cada caso tiene su test de ataque):
 *
 *  - `;`, `--`, `/*`, `#` y `@` se buscan con los literales ya vaciados: `email = 'a@b.com'` pasa.
 *    Fuera de un literal siguen prohibidos;
 *  - un paréntesis de agrupación puede seguir a `AND`, `OR`, `NOT`, `WHERE`, `ON`, `HAVING`,
 *    `WHEN`, `THEN` y `ELSE`. `FROM (`, `JOIN (` y `SELECT (` siguen prohibidos;
 *  - `*` dentro de un paréntesis se admite cuando es una multiplicación (`SUM(a * b)`).
 *
 * ⚠️ Lo que NO hace: no garantiza que la consulta sea SQL válido (eso lo dice MySQL) ni es la
 * reja principal. Tres rondas de revisión le encontraron un agujero cada una: validar el TEXTO de
 * una consulta con expresiones regulares no alcanza. La reja que decide qué sale es la de
 * `ClaudeAdminSqlService`, que mira el RESULTADO columna por columna (de qué tabla y columna real
 * viene cada una). Este validador es la primera reja, y lo que le toca cerrar a él solo es lo que
 * aquella no puede ver: las tablas derivadas (ver `tiene_una_subconsulta_fuera_de_in_o_exists()`).
 */
class ClaudeAdminSqlValidator
{
    /**
     * Lista positiva de lo que puede ir pegado a un `(`. Nació igual a `_FUNCIONES` de
     * `diagnostico.py` y hoy es un SUBCONJUNTO: `FUNCIONES_QUITADAS` dice cuáles se sacaron.
     *
     * 🔴 EL CRITERIO PARA QUE UNA FUNCIÓN ESTÉ ACÁ: no puede devolver un FRAGMENTO de su
     * argumento ni una transformación que separe una etiqueta de su valor. La redacción del
     * resultado trabaja sobre el texto entero («Contraseña: X» → se tapa X) y sobre el JSON entero
     * (por clave); una función que corta la cabeza del texto o extrae un escalar de un JSON
     * devuelve el secreto solo, bajo un alias inocente, y ya no hay etiqueta ni clave que lo delate.
     * Lo que queda: agregaciones, condicionales que devuelven un argumento ENTERO, `CONCAT` (que
     * agrega, no corta), mayúsculas/minúsculas (el redactor no las distingue), `LTRIM`/`RTRIM`
     * (solo sacan espacios), largos, números y fechas.
     *
     * 🔴 Y DESDE LA REJA POR ORIGEN, ADEMÁS: UNA FUNCIÓN QUE SOLO DEVUELVE TEXTO NO SIRVE. El
     * servicio tapa toda expresión de tipo texto, así que `CONCAT`, `UPPER` o `DATE_FORMAT`
     * devolverían siempre la máscara. Se sacan para que el rechazo sea claro en vez de una columna
     * entera de `[dato reservado]`. Quedan las que devuelven números o fechas, y las condicionales
     * (`IF`, `COALESCE`…), que sirven cuando sus argumentos son números o fechas.
     *
     * ⚠️ `IN`, `EXISTS`, `NOT`, `OVER` y `AS` no son funciones: están porque la regla mira toda
     * palabra seguida de paréntesis.
     */
    const FUNCIONES = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'COALESCE', 'IFNULL', 'NULLIF', 'IF', 'LENGTH', 'CHAR_LENGTH',
        'ROUND', 'ABS', 'CEIL', 'CEILING', 'FLOOR', 'DATE', 'YEAR', 'MONTH', 'DAY', 'DAYOFMONTH', 'HOUR', 'MINUTE',
        'SECOND', 'NOW', 'CURDATE', 'CURRENT_DATE', 'CONVERT_TZ', 'DATE_ADD', 'DATE_SUB', 'DATEDIFF', 'TIMESTAMPDIFF',
        'JSON_LENGTH', 'JSON_VALID', 'GREATEST', 'LEAST',
        'IN', 'EXISTS', 'NOT', 'OVER', 'AS',
    ];

    /**
     * Funciones de la lista del monitor que acá NO se admiten, con el motivo. Hay un test que
     * exige que `FUNCIONES` + estas sea exactamente la lista del monitor: si allá agregan una,
     * acá hay que decidir en cuál de las dos va.
     */
    const FUNCIONES_QUITADAS = [
        'JSON_EXTRACT' => 'Extrae un escalar de un JSON: `JSON_EXTRACT(data, \'$.admin_password\')` devuelve la contraseña sola. Las columnas JSON se devuelven enteras.',
        'JSON_UNQUOTE' => 'Es la otra mitad de `->>`: solo sirve para sacarle las comillas a un escalar extraído.',
        'SUBSTRING'    => 'Devuelve un fragmento: `SUBSTRING(body, 14)` corta justo después de «Contraseña: » y deja el valor sin etiqueta.',
        'SUBSTR'       => 'Sinónimo de `SUBSTRING`.',
        'LEFT'         => 'Devuelve un fragmento; sobre un JSON lo corta por la mitad y deja de ser un JSON que se pueda redactar por clave.',
        'RIGHT'        => 'Devuelve la cola: `RIGHT(body, 20)` es el valor sin su etiqueta.',
        'TRIM'         => '`TRIM(LEADING \'Contraseña: \' FROM body)` saca la etiqueta. `LTRIM` y `RTRIM` solo sacan espacios y se quedan.',
        'CAST'         => '`CAST(body AS CHAR CHARACTER SET ascii)` convierte «Contraseña» en «Contrase?a» y la etiqueta deja de reconocerse; `CAST(x AS CHAR(n))` corta un JSON por la mitad.',
        'CONVERT'      => '`CONVERT(body USING ascii)`: mismo caso que `CAST`.',
        'CONCAT'       => 'Solo devuelve texto, y una expresión de texto ya no sale (el servicio la tapa por tipo). Además pegaba un prefijo a una etiqueta (`xContraseña: …`) y degradaba un JSON a texto.',
        'CONCAT_WS'    => 'Mismo caso que `CONCAT`.',
        'GROUP_CONCAT' => 'Mismo caso que `CONCAT`; además corta el resultado en `group_concat_max_len`.',
        'LOWER'        => 'Solo devuelve texto: una expresión de texto ya no sale.',
        'UPPER'        => 'Solo devuelve texto; `BINARY UPPER(x)` con un byte inválido hacía fallar al redactor.',
        'LTRIM'        => 'Solo devuelve texto: una expresión de texto ya no sale.',
        'RTRIM'        => 'Solo devuelve texto: una expresión de texto ya no sale.',
        'DATE_FORMAT'  => 'Devuelve texto con un formato que elige el que consulta: `DATE_FORMAT(STR_TO_DATE(x, …), \'%f\')` sacaba dígitos de un texto.',
        'STR_TO_DATE'  => '`STR_TO_DATE(body, \'El PIN es %f\')` convierte en fecha —un tipo que sí sale— hasta seis dígitos que siguen a una etiqueta.',
        'DATABASE'     => 'Solo devuelve texto y no hace falta: la base es la del admin.',
        'VERSION'      => 'Solo devuelve texto y no hace falta.',
        'REPLACE'      => 'Ya estaba prohibida como palabra (es también una sentencia de escritura); como función borraría la etiqueta: `REPLACE(body, \'Contraseña: \', \'\')`.',
    ];

    /**
     * Palabras que pueden ir pegadas a un paréntesis de AGRUPACIÓN (`AND (a OR b)`). No están en
     * la lista del monitor; se agregan acá porque sin ellas no se puede escribir una condición
     * compuesta.
     *
     * 🔴 `FROM`, `JOIN` y `SELECT` NO ESTÁN Y NO TIENEN QUE ESTAR. `FROM (` y `JOIN (` son tablas
     * derivadas, y `SELECT (` una subconsulta escalar: las tres devuelven columnas con un nombre
     * que elige el que consulta.
     */
    const AGRUPADORES = ['AND', 'OR', 'NOT', 'WHERE', 'ON', 'HAVING', 'WHEN', 'THEN', 'ELSE', 'USING'];

    /**
     * Modificadores de SELECT: lo que puede haber entre `SELECT` y un `*` comodín. Un `*` precedido
     * por una de estas palabras nunca es una multiplicación.
     */
    const MODIFICADORES_DE_SELECT = [
        'SELECT', 'ALL', 'DISTINCT', 'DISTINCTROW', 'HIGH_PRIORITY', 'STRAIGHT_JOIN', 'SQL_SMALL_RESULT',
        'SQL_BIG_RESULT', 'SQL_BUFFER_RESULT', 'SQL_NO_CACHE', 'SQL_CALC_FOUND_ROWS',
    ];

    /**
     * Palabras que no pueden aparecer en un SELECT. Las de `_PROHIBIDOS` de `diagnostico.py` más
     * `UNION`, `WITH`, `TABLE`, `VALUES`, `SHARE`, `BINARY` y `COLLATE` (ver el docblock de la clase).
     * `BINARY` y `COLLATE` cambian cómo se comparan o se codifican los textos: con `BINARY` un
     * texto sale como bytes y deja de ser UTF-8 que el redactor pueda leer.
     */
    const PROHIBIDAS = 'INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|REVOKE|CALL|DO|SET|INTO|OUTFILE|DUMPFILE'
        . '|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|LOCK|UNLOCK|PROCEDURE|HANDLER|PREPARE|EXECUTE|DEALLOCATE'
        . '|KILL|SHUTDOWN|INSTALL|UNINSTALL|FLUSH|RESET|PURGE|INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|MYSQL|SYS'
        . '|UNION|WITH|TABLE|VALUES|SHARE|BINARY|COLLATE|STRAIGHT_JOIN';

    /** @var int Largo máximo de la consulta. */
    private $max_caracteres;

    /** @var string Patrón de nombres sensibles (sin delimitadores). */
    private $patron_sensible;

    /** @var array<int, string> Nombres de las tablas vedadas, en minúscula. */
    private $tablas_vedadas;

    /** @var array<int, string> Nombres de columna reservados, en minúscula. */
    private $columnas_reservadas;

    /**
     * @param array<string, mixed>|null $config Config de `claude_admin_sql`; null = la del proyecto.
     */
    public function __construct(array $config = null)
    {
        $config = $config === null ? (array) config('claude_admin_sql') : $config;

        $this->max_caracteres      = isset($config['max_caracteres']) ? (int) $config['max_caracteres'] : 12000;
        $this->patron_sensible     = isset($config['patron_sensible']) ? (string) $config['patron_sensible'] : '';
        $this->tablas_vedadas      = [];
        $this->columnas_reservadas = [];

        $vedadas = isset($config['tablas_vedadas']) ? (array) $config['tablas_vedadas'] : [];
        foreach ($vedadas as $tabla => $motivo) {
            $this->tablas_vedadas[] = strtolower((string) $tabla);
        }

        $reservadas = isset($config['columnas_reservadas']) ? (array) $config['columnas_reservadas'] : [];
        foreach ($reservadas as $columna => $motivo) {
            $this->columnas_reservadas[] = mb_strtolower((string) $columna, 'UTF-8');
        }
    }

    /**
     * Valida una consulta.
     *
     * @param mixed $sql La consulta tal como llegó.
     *
     * @return array<string, mixed> `['ok' => true, 'sql' => string, 'clase' => 'select' | 'esquema']`
     *                              (la consulta tal cual llegó, sin los espacios de los bordes: es
     *                              la que se ejecuta; `esquema` = SHOW / DESCRIBE / EXPLAIN) o
     *                              `['ok' => false, 'codigo' => string, 'error' => string]`.
     */
    public function validar($sql)
    {
        if (! is_string($sql) || trim($sql) === '' || mb_strlen($sql, 'UTF-8') > $this->max_caracteres) {
            return $this->rechazo('consulta_invalida', 'La consulta debe tener entre 1 y ' . $this->max_caracteres . ' caracteres.');
        }

        if (strpos($sql, "\0") !== false) {
            return $this->rechazo('varias_sentencias', 'La consulta tiene un carácter nulo.');
        }

        $sql = trim($sql);

        /* Se recorre la consulta de izquierda a derecha, como la lee MySQL, y se vacía cada
           literal entre comillas simples: un dato literal no es una instrucción. Los
           identificadores entre backticks se resuelven EN LA MISMA PASADA y no en otra: si se
           sacaran después, un alias como `a'` (válido para MySQL) abriría acá un literal que MySQL
           no ve. */
        $backtick_invalido = false;
        $limpio            = preg_replace_callback(
            "/`[^`]*`|'(?:\\\\.|''|[^'\\\\])*'/su",
            function ($coincidencia) use (&$backtick_invalido) {
                $trozo = $coincidencia[0];

                if ($trozo[0] === "'") {
                    return "''";
                }

                if (! preg_match('/^`[A-Za-z_][A-Za-z0-9_]*`$/', $trozo)) {
                    $backtick_invalido = true;
                }

                return ' ' . substr($trozo, 1, -1) . ' ';
            },
            $sql
        );

        if ($limpio === null) {
            return $this->rechazo('consulta_invalida', 'La consulta tiene caracteres que no se pueden analizar (¿no es UTF-8?).');
        }

        if ($backtick_invalido) {
            return $this->rechazo('consulta_invalida', 'Entre backticks solo se admite un nombre simple de tabla o de columna (letras, números y guion bajo).');
        }

        /* Si después de vaciar queda una comilla o un backtick sueltos, hay algo sin cerrar y todo
           lo que sigue se analizaría como código cuando MySQL lo leería como dato (o al revés). No
           se adivina. */
        if (strpos(str_replace("''", '', $limpio), "'") !== false || strpos($limpio, '`') !== false) {
            return $this->rechazo('consulta_invalida', 'La consulta tiene una comilla simple o un backtick sin cerrar.');
        }

        /* 🔴 FUERA DE UN LITERAL, SOLO ASCII IMPRIMIBLE. MySQL compara los identificadores con una
           collation que PLIEGA letras: `api_Key` con la K de Kelvin (U+212A) o `apİ_key` con la İ
           turca (U+0130) son, para MySQL, la columna `api_key`, y para una expresión regular son
           otro texto. Lo mismo `admİn_settİngs` e `İNFORMATİON_SCHEMA`. Ningún nombre de tabla o de
           columna del admin necesita otra cosa que ASCII; los textos entre comillas sí admiten ñ y
           acentos, y a esta altura ya están vaciados. */
        if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $limpio) !== 0) {
            return $this->rechazo('consulta_invalida', 'Fuera de un texto entre comillas simples solo se admiten caracteres ASCII: sin acentos, eñes ni otros símbolos en nombres de tablas, columnas o alias.');
        }

        /* Las llaves son la sintaxis de escape de ODBC (`{OJ otra.tabla b LEFT OUTER JOIN …}`): otra
           forma de introducir una tabla, que ninguna regla de más abajo reconoce. */
        if (strpos($limpio, '{') !== false || strpos($limpio, '}') !== false) {
            return $this->rechazo('consulta_invalida', 'No se admiten llaves ({ }) fuera de un texto entre comillas simples.');
        }

        /* Un literal con algo pegado adelante (`N'…'`, `X'41'`, `b'01'`, `_utf8'…'`), un nombre que
           empieza con guion bajo (`_latin1 '…'`, `_binary`) o un literal hexadecimal o de bits
           (`0x41`, `0b01`): son formas de escribir un texto con otra codificación, o bytes sueltos. */
        if (preg_match('/[A-Za-z0-9_]\'\'/', $limpio) !== 0
            || preg_match('/(?<![A-Za-z0-9_])_[A-Za-z0-9_]+/', $limpio) !== 0
            || preg_match('/(?<![A-Za-z0-9_])0[xXbB][0-9A-Fa-f]+/', $limpio) !== 0) {
            return $this->rechazo('literal_no_permitido', 'No se admiten literales hexadecimales o de bits (0x…, X\'…\', b\'…\'), ni textos con un juego de caracteres declarado (N\'…\', _utf8\'…\', _binary). Escribí el texto entre comillas simples, con un espacio antes.');
        }

        /* Recién ahora, sin literales: `email = 'a@b.com'` pasa y `SELECT @@version` no. Un
           comentario o un `;` que estén ANTES de la comilla que pretende esconderlos sobreviven al
           vaciado, así que no se puede usar una comilla dentro de un comentario para tapar código. */
        foreach ([';', '--', '/*', '*/', '#', '@'] as $vedado) {
            if (strpos($limpio, $vedado) !== false) {
                return $this->rechazo('varias_sentencias', 'No se permiten varias sentencias, comentarios ni variables SQL (sin `;`, `--`, `/*`, `#` ni `@` fuera de un texto entre comillas simples).');
            }
        }

        if (strpos($limpio, '"') !== false) {
            return $this->rechazo('comillas_dobles', 'Escribí los textos entre comillas simples: las comillas dobles no se admiten.');
        }

        /* `columna->'$.ruta'` y `columna->>'$.ruta'` son JSON_EXTRACT con otra letra: sacan un
           escalar de un JSON y lo devuelven bajo el alias que se quiera. La ruta va dentro de un
           literal (ya vaciado), así que el patrón sensible no la ve: se rechaza el operador. */
        if (strpos($limpio, '->') !== false) {
            return $this->rechazo('json_no_permitido', 'No se admiten los operadores `->` ni `->>` ni las funciones que extraen una parte de un JSON: las columnas JSON se devuelven enteras, con sus claves reservadas ya tapadas.');
        }

        if ($this->patron_sensible !== '' && preg_match('~' . $this->patron_sensible . '~i', $limpio)) {
            return $this->rechazo('campo_sensible', 'La consulta nombra un campo o una tabla reservados (claves, tokens, secretos). Esos datos no se consultan.');
        }

        foreach ($this->columnas_reservadas as $columna) {
            if (preg_match('~(?<![\p{L}\p{N}_])' . preg_quote($columna, '~') . '(?![\p{L}\p{N}_])~iu', $limpio)) {
                return $this->rechazo('campo_sensible', 'La consulta nombra `' . $columna . '`, una columna reservada: su contenido no se consulta.');
            }
        }

        foreach ($this->tablas_vedadas as $tabla) {
            if (preg_match('~\b' . preg_quote($tabla, '~') . '\b~i', $limpio)) {
                return $this->rechazo('tabla_vedada', 'La tabla `' . $tabla . '` no se puede consultar: guarda credenciales o secretos.');
            }
        }

        if (preg_match('~^SHOW\s+TABLES\s*$~i', $limpio)) {
            return ['ok' => true, 'sql' => $sql, 'clase' => 'esquema'];
        }

        if (preg_match('~^SHOW\s+(?:FULL\s+)?COLUMNS\s+(?:FROM|IN)\s+[a-zA-Z]\w*\s*$~i', $limpio)) {
            return ['ok' => true, 'sql' => $sql, 'clase' => 'esquema'];
        }

        if (preg_match('~^(?:DESCRIBE|DESC)\s+[a-zA-Z]\w*\s*$~i', $limpio)) {
            return ['ok' => true, 'sql' => $sql, 'clase' => 'esquema'];
        }

        if (preg_match('~\b(?:' . self::PROHIBIDAS . ')\b~i', $limpio)) {
            return $this->rechazo('operacion_no_permitida', 'La consulta contiene una operación que no está habilitada (solo lectura, sin UNION, WITH, TABLE ni VALUES).');
        }

        if (! preg_match('~^(?:EXPLAIN\s+)?SELECT\b~i', $limpio)) {
            return $this->rechazo('solo_select', 'Solo se permiten SELECT, EXPLAIN SELECT, SHOW TABLES, SHOW [FULL] COLUMNS FROM tabla y DESCRIBE tabla.');
        }

        if (preg_match('~\b(?:FROM|JOIN)\s+\w+\s*\.~i', $limpio)) {
            return $this->rechazo('otra_base', 'La consulta debe usar únicamente tablas de la base del admin, sin `base.tabla`.');
        }

        if ($this->tiene_un_comodin_dentro_de_parentesis($limpio)) {
            return $this->rechazo('comodin_en_parentesis', 'Dentro de un paréntesis, `*` solo se admite como COUNT(*) o como multiplicación. Nombrá las columnas.');
        }

        if (preg_match_all('~\b([A-Za-z_]\w*)\s*\(~', $limpio, $coincidencias)) {
            foreach ($coincidencias[1] as $funcion) {
                $mayuscula = strtoupper($funcion);

                if (in_array($mayuscula, ['JSON_EXTRACT', 'JSON_UNQUOTE', 'JSON_VALUE', 'JSON_SEARCH', 'JSON_KEYS', 'JSON_QUERY', 'JSON_TABLE'], true)) {
                    return $this->rechazo('json_no_permitido', 'No se admite `' . $funcion . '(`: las columnas JSON se devuelven enteras, con sus claves reservadas ya tapadas; no se puede extraer una parte.');
                }

                if (isset(self::FUNCIONES_QUITADAS[$mayuscula])) {
                    return $this->rechazo('funcion_no_permitida', 'No se admite `' . $funcion . '(`: las funciones que devuelven un fragmento o una transformación de un texto no están habilitadas (los textos se devuelven enteros, hasta 4000 caracteres).');
                }

                if (! in_array($mayuscula, self::FUNCIONES, true) && ! in_array($mayuscula, self::AGRUPADORES, true)) {
                    return $this->rechazo('funcion_no_permitida', 'La consulta usa `' . $funcion . '(`, que no está en la lista de funciones habilitadas (tampoco se admiten subconsultas en FROM, JOIN ni SELECT).');
                }
            }
        }

        if (preg_match('~\.\s*[A-Za-z_]\w*\s*\(~', $limpio)) {
            return $this->rechazo('funcion_no_permitida', 'No se permiten funciones de otras bases.');
        }

        if ($this->tiene_una_subconsulta_fuera_de_in_o_exists($limpio)) {
            return $this->rechazo('subconsulta_no_permitida', 'Una subconsulta solo se admite inmediatamente después de IN o de EXISTS. No se admiten tablas derivadas (`FROM (SELECT …) t`, también después de una coma) ni subconsultas en la lista del SELECT o dentro de una función.');
        }

        if ($this->usa_un_calificador_que_no_es_de_la_consulta($limpio)) {
            return $this->rechazo('otra_base', 'La consulta debe usar únicamente tablas de la base del admin: no se admite `base.tabla`, y en `x.columna` la `x` tiene que ser una tabla o un alias de esta misma consulta.');
        }

        if ($this->ordena_o_agrupa_sin_nombrar_la_columna($limpio)) {
            return $this->rechazo('orden_posicional', 'En ORDER BY y GROUP BY cada término tiene que empezar con el nombre de una columna (no se admite ordenar por posición: `ORDER BY 3`). Si la consulta usa `*`, tienen que ser columnas explícitas.');
        }

        /* `EXPLAIN SELECT …` devuelve el plan, no datos: del otro lado se trata como una consulta de
           esquema. */
        return ['ok' => true, 'sql' => $sql, 'clase' => preg_match('~^EXPLAIN\b~i', $limpio) ? 'esquema' : 'select'];
    }

    /**
     * ¿Hay un `(SELECT` que no venga inmediatamente después de `IN` o de `EXISTS`?
     *
     * 🔴 ES LO QUE CIERRA DE VERDAD LAS TABLAS DERIVADAS Y LAS SUBCONSULTAS ESCALARES. La regla de
     * funciones mira la palabra pegada al paréntesis, así que `FROM a, (SELECT …) t`,
     * `SELECT x, (SELECT …)`, `((SELECT …))` y `COALESCE((SELECT …))` —un paréntesis después de una
     * coma o de otro paréntesis— se le escapaban. Y a través de una tabla derivada MySQL informa
     * como nombre de origen de una columna el ALIAS que le puso la subconsulta
     * (`(SELECT api_key AS name FROM clients) clients` → `clients.name`), que es justo en lo que
     * confía la reja por origen de `ClaudeAdminSqlService`.
     *
     * @param string $limpio Consulta sin literales ni backticks.
     *
     * @return bool
     */
    private function tiene_una_subconsulta_fuera_de_in_o_exists($limpio)
    {
        if (! preg_match_all("~''|[A-Za-z_]\w*|\d+(?:\.\d+)?|\S~", (string) $limpio, $coincidencias)) {
            return false;
        }

        $tokens   = $coincidencias[0];
        $cantidad = count($tokens);

        for ($i = 0; $i < $cantidad - 1; $i++) {
            if ($tokens[$i] !== '(' || strtoupper($tokens[$i + 1]) !== 'SELECT') {
                continue;
            }

            $anterior = $i > 0 ? strtoupper($tokens[$i - 1]) : '';

            if ($anterior !== 'IN' && $anterior !== 'EXISTS') {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿La sentencia tiene un comentario (`/*`, `--`, `#`) fuera de un texto entre comillas?
     *
     * Es pública porque `ClaudeAdminSqlService` la vuelve a preguntar por su cuenta justo antes de
     * ejecutar: un comentario puede ser una PISTA para el optimizador
     * (`/*+ SET_VAR(optimizer_switch='derived_merge=on') * /`), que es lo que desarma la reja del
     * plan. Si la sentencia no se puede analizar, contesta que sí.
     *
     * @param mixed $sql Sentencia.
     *
     * @return bool
     */
    public function tiene_un_comentario($sql)
    {
        if (! is_string($sql)) {
            return true;
        }

        $limpio = preg_replace("/`[^`]*`|'(?:\\\\.|''|[^'\\\\])*'/su", "''", $sql);

        if ($limpio === null) {
            return true;
        }

        foreach (['/*', '*/', '--', '#'] as $marca) {
            if (strpos($limpio, $marca) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las piezas de la consulta ya limpia: literales vaciados (`''`), palabras, números y signos.
     *
     * @param string $limpio Consulta sin literales ni backticks.
     *
     * @return array<int, string>
     */
    private function piezas($limpio)
    {
        if (! preg_match_all("~''|[A-Za-z_]\w*|\d+(?:\.\d+)?|\S~", (string) $limpio, $coincidencias)) {
            return [];
        }

        return $coincidencias[0];
    }

    /**
     * ¿Es una palabra (un identificador o una palabra reservada)?
     *
     * @param string $pieza Pieza.
     *
     * @return bool
     */
    private function es_palabra($pieza)
    {
        return preg_match('~^[A-Za-z_]\w*$~', (string) $pieza) === 1;
    }

    /**
     * ¿La consulta califica algo con un nombre que no es una tabla o un alias DECLARADO en ella?
     *
     * 🔴 CIERRA LA CLASE «OTRA BASE», no los ejemplos. La regla anterior buscaba `FROM x.` y
     * `JOIN x.`; `clients a STRAIGHT_JOIN otra_base.clients b` y `{OJ otra.t b LEFT OUTER JOIN …}`
     * se le escapaban porque la tabla no venía después de una de esas dos palabras. Ahora:
     *
     *  1. en POSICIÓN DE TABLA (después de `FROM`, de `JOIN`, de una coma del FROM, y también
     *     adentro de un paréntesis abierto en esa posición) el nombre nunca puede ir seguido de un
     *     punto;
     *  2. cada tabla y cada alias que aparece en posición de tabla queda DECLARADO;
     *  3. en el resto de la consulta, todo `x.y` exige que `x` esté declarado, y `x.y.z` no se
     *     admite nunca.
     *
     * Así, una base ajena solo podría nombrarse en una posición de tabla que esta función no
     * reconozca Y con un alias declarado que se llame igual que esa base; `STRAIGHT_JOIN` y las
     * llaves de ODBC, que son las otras dos formas de introducir una tabla, están prohibidas aparte.
     *
     * @param string $limpio Consulta sin literales ni backticks.
     *
     * @return bool
     */
    private function usa_un_calificador_que_no_es_de_la_consulta($limpio)
    {
        $piezas   = $this->piezas($limpio);
        $cantidad = count($piezas);

        /* Lo que puede venir después del nombre de una tabla y NO es su alias. */
        $no_es_alias = ['ON', 'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'CROSS', 'NATURAL', 'OUTER', 'USING', 'GROUP', 'ORDER',
            'HAVING', 'LIMIT', 'FOR', 'UNION', 'SET', 'STRAIGHT_JOIN', 'WINDOW', 'PARTITION', 'USE', 'IGNORE', 'FORCE', 'AS'];

        $declarados = [];
        $niveles    = [false];
        $espera     = false;

        for ($i = 0; $i < $cantidad; $i++) {
            $pieza     = $piezas[$i];
            $mayuscula = strtoupper($pieza);
            $ultimo    = count($niveles) - 1;

            if ($pieza === '(') {
                /* Un paréntesis abierto en posición de tabla sigue en posición de tabla. */
                $niveles[] = $espera;
                continue;
            }

            if ($pieza === ')') {
                if (count($niveles) > 1) {
                    array_pop($niveles);
                }
                $espera = false;
                continue;
            }

            if ($mayuscula === 'FROM' || $mayuscula === 'JOIN' || ($pieza === ',' && $niveles[$ultimo])) {
                $niveles[$ultimo] = true;
                $espera           = true;
                continue;
            }

            if (in_array($mayuscula, ['WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'WINDOW', 'FOR'], true)) {
                $niveles[$ultimo] = false;
                $espera           = false;
                continue;
            }

            if (! $espera) {
                continue;
            }

            $espera = false;

            if (! $this->es_palabra($pieza)) {
                /* En posición de tabla tiene que haber un nombre. */
                return true;
            }

            if ($i + 1 < $cantidad && $piezas[$i + 1] === '.') {
                return true;
            }

            $declarados[] = strtolower($pieza);

            $j = $i + 1;
            if ($j < $cantidad && strtoupper($piezas[$j]) === 'AS') {
                $j++;
            }
            if ($j < $cantidad && $this->es_palabra($piezas[$j]) && ! in_array(strtoupper($piezas[$j]), $no_es_alias, true)) {
                $declarados[] = strtolower($piezas[$j]);
            }
        }

        for ($i = 1; $i < $cantidad; $i++) {
            if ($piezas[$i] !== '.') {
                continue;
            }

            /* x.y.z */
            if ($i >= 2 && $piezas[$i - 2] === '.') {
                return true;
            }

            if ($this->es_palabra($piezas[$i - 1]) && ! in_array(strtolower($piezas[$i - 1]), $declarados, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Algún término de un `ORDER BY` o de un `GROUP BY` no empieza con un nombre?
     *
     * 🔴 `SELECT * FROM clients ORDER BY 25` ordena por la columna 25 —`api_key`— sin nombrarla, y
     * el orden de las filas es información: con filtros sobre columnas legibles se reconstruye el
     * valor. Un término posicional es un entero suelto, pero también `25 DESC`, `(25)`, `+25` o
     * `25+0`; en vez de perseguir las formas, se exige que cada término EMPIECE con una palabra (el
     * nombre de una columna, un alias o una función).
     *
     * Y si la lista del SELECT tiene un `*`, cada término tiene que ser una columna explícita
     * (`columna` o `alias.columna`, con `ASC` / `DESC` opcional): con `*` no hay alias del SELECT a
     * los que referirse, así que cualquier otra cosa es una expresión sobre columnas que no se ven.
     *
     * @param string $limpio Consulta sin literales ni backticks.
     *
     * @return bool
     */
    private function ordena_o_agrupa_sin_nombrar_la_columna($limpio)
    {
        $piezas   = $this->piezas($limpio);
        $cantidad = count($piezas);

        /* ¿Hay un `*` en la lista del SELECT de afuera (antes del primer FROM, fuera de paréntesis)? */
        $con_asterisco = false;
        $profundidad   = 0;
        for ($i = 0; $i < $cantidad; $i++) {
            if ($piezas[$i] === '(') {
                $profundidad++;
            } elseif ($piezas[$i] === ')') {
                $profundidad = max(0, $profundidad - 1);
            } elseif ($profundidad === 0 && strtoupper($piezas[$i]) === 'FROM') {
                break;
            } elseif ($profundidad === 0 && $piezas[$i] === '*') {
                $con_asterisco = true;
            }
        }

        $fin_de_clausula = ['LIMIT', 'HAVING', 'ORDER', 'GROUP', 'FOR', 'WINDOW', 'UNION', 'ROWS', 'RANGE'];

        for ($i = 0; $i < $cantidad - 1; $i++) {
            $mayuscula = strtoupper($piezas[$i]);

            if (($mayuscula !== 'ORDER' && $mayuscula !== 'GROUP') || strtoupper($piezas[$i + 1]) !== 'BY') {
                continue;
            }

            $termino     = [];
            $profundidad = 0;
            $j           = $i + 2;

            while (true) {
                $pieza = $j < $cantidad ? $piezas[$j] : null;
                $corta = $pieza === null
                    || ($profundidad === 0 && ($pieza === ',' || $pieza === ')' || in_array(strtoupper($pieza), $fin_de_clausula, true)));

                if ($corta) {
                    if ($termino === [] || ! $this->es_palabra($termino[0])) {
                        return true;
                    }

                    if ($con_asterisco && preg_match('~^[A-Za-z_]\w*( \. [A-Za-z_]\w*)?( (ASC|DESC))?$~i', implode(' ', $termino)) !== 1) {
                        return true;
                    }

                    $termino = [];

                    if ($pieza !== ',') {
                        break;
                    }

                    $j++;
                    continue;
                }

                if ($pieza === '(') {
                    $profundidad++;
                } elseif ($pieza === ')') {
                    $profundidad--;
                }

                $termino[] = $pieza;
                $j++;
            }
        }

        return false;
    }

    /**
     * ¿Hay un `*` COMODÍN adentro de un paréntesis que no sea exactamente `COUNT(*)`?
     *
     * Un `*` dentro de un paréntesis es una multiplicación —y pasa— solo si tiene un operando de
     * cada lado: antes, un identificador que no sea un modificador de SELECT, un número, un
     * literal o un `)`; después, un identificador que no sea `FROM`, un número, un literal, un `(`
     * o un signo. Cualquier otra cosa es un comodín: `(SELECT * FROM`, `(t.*)`, `(a, *)`,
     * `COUNT(DISTINCT *)`, `( * )`.
     *
     * 🔴 Las dos condiciones, no una. Mirar solo lo de antes obliga a conocer TODOS los
     * modificadores de SELECT de MySQL (el que falte es un agujero); mirar además lo de después lo
     * cierra, porque a un comodín lo sigue siempre una coma, `FROM` o un `)`.
     *
     * @param string $limpio Consulta sin literales ni backticks.
     *
     * @return bool
     */
    private function tiene_un_comodin_dentro_de_parentesis($limpio)
    {
        $sin_count = preg_replace('~\bCOUNT\s*\(\s*\*\s*\)~i', 'COUNT(1)', $limpio);

        if (! preg_match_all("~''|[A-Za-z_]\w*|\d+(?:\.\d+)?|\S~u", (string) $sin_count, $coincidencias)) {
            return false;
        }

        $tokens      = $coincidencias[0];
        $cantidad    = count($tokens);
        $profundidad = 0;
        $operando    = '~^(?:[A-Za-z_]\w*|\d+(?:\.\d+)?)$~';

        for ($i = 0; $i < $cantidad; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $profundidad++;
                continue;
            }

            if ($token === ')') {
                $profundidad = $profundidad > 0 ? $profundidad - 1 : 0;
                continue;
            }

            if ($token !== '*' || $profundidad === 0) {
                continue;
            }

            $anterior  = $i > 0 ? $tokens[$i - 1] : '';
            $siguiente = $i + 1 < $cantidad ? $tokens[$i + 1] : '';

            $operando_antes = $anterior === ')' || $anterior === "''"
                || (preg_match($operando, $anterior) && ! in_array(strtoupper($anterior), self::MODIFICADORES_DE_SELECT, true));

            $operando_despues = $siguiente === '(' || $siguiente === "''" || $siguiente === '-' || $siguiente === '+'
                || (preg_match($operando, $siguiente) && strtoupper($siguiente) !== 'FROM');

            if (! $operando_antes || ! $operando_despues) {
                return true;
            }
        }

        return false;
    }

    /**
     * Forma única del rechazo.
     *
     * @param string $codigo  Código estable, para el que consume.
     * @param string $mensaje Mensaje legible.
     *
     * @return array<string, mixed>
     */
    private function rechazo($codigo, $mensaje)
    {
        return ['ok' => false, 'codigo' => $codigo, 'error' => $mensaje];
    }
}

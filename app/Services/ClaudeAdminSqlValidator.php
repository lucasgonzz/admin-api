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
 * única barrera. `ClaudeAdminSqlService` la corre en una transacción READ ONLY y redacta el
 * resultado; el monitor valida además por su cuenta antes de mandarla.
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
     * ⚠️ `IN`, `EXISTS`, `NOT`, `OVER` y `AS` no son funciones: están porque la regla mira toda
     * palabra seguida de paréntesis.
     */
    const FUNCIONES = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'COALESCE', 'IFNULL', 'NULLIF', 'IF', 'CONCAT', 'CONCAT_WS',
        'LOWER', 'UPPER', 'LTRIM', 'RTRIM', 'LENGTH', 'CHAR_LENGTH', 'ROUND', 'ABS', 'CEIL', 'CEILING',
        'FLOOR', 'DATE', 'DATE_FORMAT', 'YEAR', 'MONTH', 'DAY', 'DAYOFMONTH', 'HOUR', 'MINUTE', 'SECOND', 'NOW',
        'CURDATE', 'CURRENT_DATE', 'CONVERT_TZ', 'DATE_ADD', 'DATE_SUB', 'DATEDIFF', 'TIMESTAMPDIFF',
        'JSON_LENGTH', 'JSON_VALID', 'STR_TO_DATE', 'GREATEST', 'LEAST', 'GROUP_CONCAT', 'DATABASE', 'VERSION',
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
    const AGRUPADORES = ['AND', 'OR', 'NOT', 'WHERE', 'ON', 'HAVING', 'WHEN', 'THEN', 'ELSE'];

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
     * `UNION`, `WITH`, `TABLE`, `VALUES` y `SHARE` (ver el docblock de la clase).
     */
    const PROHIBIDAS = 'INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|REVOKE|CALL|DO|SET|INTO|OUTFILE|DUMPFILE'
        . '|LOAD_FILE|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|LOCK|UNLOCK|PROCEDURE|HANDLER|PREPARE|EXECUTE|DEALLOCATE'
        . '|KILL|SHUTDOWN|INSTALL|UNINSTALL|FLUSH|RESET|PURGE|INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|MYSQL|SYS'
        . '|UNION|WITH|TABLE|VALUES|SHARE';

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
     * @return array<string, mixed> `['ok' => true, 'sql' => string]` (la consulta tal cual llegó,
     *                              sin los espacios de los bordes: es la que se ejecuta) o
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
            return ['ok' => true, 'sql' => $sql];
        }

        if (preg_match('~^SHOW\s+(?:FULL\s+)?COLUMNS\s+(?:FROM|IN)\s+[a-zA-Z_]\w*\s*$~i', $limpio)) {
            return ['ok' => true, 'sql' => $sql];
        }

        if (preg_match('~^(?:DESCRIBE|DESC)\s+[a-zA-Z_]\w*\s*$~i', $limpio)) {
            return ['ok' => true, 'sql' => $sql];
        }

        if (preg_match('~\b(?:' . self::PROHIBIDAS . ')\b~i', $limpio)) {
            return $this->rechazo('operacion_no_permitida', 'La consulta contiene una operación que no está habilitada (solo lectura, sin UNION, WITH, TABLE ni VALUES).');
        }

        if (! preg_match('~^(?:EXPLAIN\s+)?SELECT\b~i', $limpio)) {
            return $this->rechazo('solo_select', 'Solo se permiten SELECT, EXPLAIN SELECT, SHOW TABLES, SHOW [FULL] COLUMNS FROM tabla y DESCRIBE tabla.');
        }

        if (preg_match('~\b(?:FROM|JOIN)\s+\w+\s*\.~i', $limpio) || $this->califica_una_tabla_con_otra_base($limpio)) {
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

        return ['ok' => true, 'sql' => $sql];
    }

    /**
     * ¿El nombre de alguna tabla (después de FROM, de JOIN o de una coma del FROM) viene calificado
     * con un punto? Cubre la sintaxis vieja `FROM a, otra_base.b` y sus subconsultas.
     *
     * @param string $limpio Consulta sin literales ni backticks.
     *
     * @return bool
     */
    private function califica_una_tabla_con_otra_base($limpio)
    {
        preg_match_all('~[A-Za-z_]\w*|[(),.]~', $limpio, $coincidencias);

        $tokens       = $coincidencias[0];
        $niveles      = [false];
        $espera_tabla = false;
        $cantidad     = count($tokens);

        for ($indice = 0; $indice < $cantidad; $indice++) {
            $token     = $tokens[$indice];
            $mayuscula = strtoupper($token);
            $ultimo    = count($niveles) - 1;

            if ($token === '(') {
                $niveles[]    = false;
                $espera_tabla = false;
            } elseif ($token === ')') {
                if (count($niveles) > 1) {
                    array_pop($niveles);
                }
                $espera_tabla = false;
            } elseif ($mayuscula === 'FROM' || $mayuscula === 'JOIN' || ($token === ',' && $niveles[$ultimo])) {
                $niveles[$ultimo] = true;
                $espera_tabla     = true;
            } elseif (in_array($mayuscula, ['WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION'], true)) {
                $niveles[$ultimo] = false;
                $espera_tabla     = false;
            } elseif ($espera_tabla) {
                $espera_tabla = false;

                /* Al consumir el nombre, el próximo punto sería una calificación de base. */
                if ($indice + 1 < $cantidad && $tokens[$indice + 1] === '.') {
                    return true;
                }
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

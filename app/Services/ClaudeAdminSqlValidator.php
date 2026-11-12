<?php

namespace App\Services;

/**
 * Decide si una consulta SQL se puede correr, de solo lectura, sobre la base del ADMIN
 * (`POST claude/support-codex/admin-sql`, misión `acceso-soporte-al-admin-lectura`, 11/11/2026).
 *
 * Es un servicio PURO: recibe texto y contesta sí o no, sin tocar la base. Por eso se prueba con
 * tests unitarios, barrera por barrera.
 *
 * 🔴 ES UN PORTE, MÁS ESTRICTO, DE `validar_sql` DE `herramientas/soporte-codex/diagnostico.py`
 * (repo de conocimiento), que es la que valida el SQL contra el sistema de un cliente. La lista
 * positiva de funciones y la de palabras prohibidas son LAS MISMAS; si se toca una, se toca la
 * otra. Lo que esta agrega, porque la base del admin guarda las claves de todos los clientes:
 *
 *  - sin `UNION`, sin `WITH`, sin `TABLE`/`VALUES` y sin `SHOW CREATE TABLE`. Las cuatro primeras
 *    son formas de RENOMBRAR columnas sin nombrarlas (`SELECT 1,2 UNION SELECT * FROM clients`,
 *    `(TABLE clients) AS t(a, b, …)`), y la redacción del resultado es por nombre de columna;
 *  - `*` adentro de un paréntesis solo como `COUNT(*)`, por el mismo motivo (tablas derivadas);
 *  - sin comillas dobles: con `ANSI_QUOTES` serían identificadores, y acá se vaciarían como si
 *    fueran un dato literal;
 *  - el patrón de nombres sensibles ampliado y la lista de tablas vedadas, las dos en
 *    `config/claude_admin_sql.php`.
 *
 * ⚠️ Lo que NO hace: no garantiza que la consulta sea SQL válido (eso lo dice MySQL) ni es la
 * única barrera. `ClaudeAdminSqlService` la corre en una transacción READ ONLY y redacta el
 * resultado; el monitor valida además por su cuenta antes de mandarla.
 */
class ClaudeAdminSqlValidator
{
    /**
     * Lista positiva de lo que puede ir pegado a un `(`. Igual a `_FUNCIONES` de `diagnostico.py`.
     *
     * ⚠️ `IN`, `EXISTS`, `NOT`, `OVER` y `AS` no son funciones: están porque la regla mira toda
     * palabra seguida de paréntesis. `AND (`, `OR (`, `WHERE (` y `FROM (` NO están, igual que en
     * el original: una condición agrupada se escribe sin el paréntesis pegado a esas palabras, o
     * se reordena. Es incómodo y es a propósito: `FROM (` es una tabla derivada.
     */
    const FUNCIONES = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'COALESCE', 'IFNULL', 'NULLIF', 'IF', 'CONCAT', 'CONCAT_WS',
        'LOWER', 'UPPER', 'TRIM', 'LTRIM', 'RTRIM', 'LENGTH', 'CHAR_LENGTH', 'ROUND', 'ABS', 'CEIL', 'CEILING',
        'FLOOR', 'DATE', 'DATE_FORMAT', 'YEAR', 'MONTH', 'DAY', 'DAYOFMONTH', 'HOUR', 'MINUTE', 'SECOND', 'NOW',
        'CURDATE', 'CURRENT_DATE', 'CONVERT_TZ', 'DATE_ADD', 'DATE_SUB', 'DATEDIFF', 'TIMESTAMPDIFF', 'CAST',
        'CONVERT', 'JSON_EXTRACT', 'JSON_UNQUOTE', 'JSON_LENGTH', 'JSON_VALID', 'REPLACE', 'SUBSTRING', 'SUBSTR',
        'LEFT', 'RIGHT', 'STR_TO_DATE', 'GREATEST', 'LEAST', 'GROUP_CONCAT', 'DATABASE', 'VERSION', 'IN', 'EXISTS',
        'NOT', 'OVER', 'AS',
    ];

    /**
     * Palabras que no pueden aparecer en un SELECT. Las de `_PROHIBIDOS` de `diagnostico.py` más
     * `UNION`, `WITH`, `TABLE` y `VALUES` (ver el docblock de la clase).
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

    /**
     * @param array<string, mixed>|null $config Config de `claude_admin_sql`; null = la del proyecto.
     */
    public function __construct(array $config = null)
    {
        $config = $config === null ? (array) config('claude_admin_sql') : $config;

        $this->max_caracteres  = isset($config['max_caracteres']) ? (int) $config['max_caracteres'] : 12000;
        $this->patron_sensible = isset($config['patron_sensible']) ? (string) $config['patron_sensible'] : '';
        $this->tablas_vedadas  = [];

        $vedadas = isset($config['tablas_vedadas']) ? (array) $config['tablas_vedadas'] : [];
        foreach ($vedadas as $tabla => $motivo) {
            $this->tablas_vedadas[] = strtolower((string) $tabla);
        }
    }

    /**
     * Valida una consulta.
     *
     * @param mixed $sql La consulta tal como llegó.
     *
     * @return array<string, mixed> `['ok' => true, 'sql' => string]` (ya sin espacios de los bordes)
     *                              o `['ok' => false, 'codigo' => string, 'error' => string]`.
     */
    public function validar($sql)
    {
        if (! is_string($sql) || trim($sql) === '' || mb_strlen($sql, 'UTF-8') > $this->max_caracteres) {
            return $this->rechazo('consulta_invalida', 'La consulta debe tener entre 1 y ' . $this->max_caracteres . ' caracteres.');
        }

        $sql = trim($sql);

        foreach ([';', '--', '/*', '*/', '#', "\0", '@'] as $vedado) {
            if (strpos($sql, $vedado) !== false) {
                return $this->rechazo('varias_sentencias', 'No se permiten varias sentencias, comentarios ni variables SQL (sin `;`, `--`, `/*`, `#` ni `@`).');
            }
        }

        if (strpos($sql, '"') !== false) {
            return $this->rechazo('comillas_dobles', 'Escribí los textos entre comillas simples: las comillas dobles no se admiten.');
        }

        /* Las cadenas se vacían antes de buscar palabras: un dato literal no es una instrucción. */
        $limpio = preg_replace("/'(?:\\\\.|''|[^'\\\\])*'/su", "''", $sql);
        if ($limpio === null) {
            return $this->rechazo('consulta_invalida', 'La consulta tiene caracteres que no se pueden analizar (¿no es UTF-8?).');
        }

        $limpio = str_replace('`', '', $limpio);

        /* Si después de vaciar las cadenas queda una comilla suelta, hay un literal sin cerrar y
           todo lo que sigue se analizaría como si fuera código cuando MySQL lo leería como dato
           (o al revés). No se adivina. */
        if (strpos(str_replace("''", '', $limpio), "'") !== false) {
            return $this->rechazo('consulta_invalida', 'La consulta tiene una comilla simple sin cerrar.');
        }

        if ($this->patron_sensible !== '' && preg_match('~' . $this->patron_sensible . '~i', $limpio)) {
            return $this->rechazo('campo_sensible', 'La consulta nombra un campo o una tabla reservados (claves, tokens, secretos). Esos datos no se consultan.');
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
            return $this->rechazo('operacion_no_permitida', 'La consulta contiene una operación que no está habilitada (solo lectura, sin UNION ni WITH).');
        }

        if (! preg_match('~^(?:EXPLAIN\s+)?SELECT\b~i', $limpio)) {
            return $this->rechazo('solo_select', 'Solo se permiten SELECT, EXPLAIN SELECT, SHOW TABLES, SHOW [FULL] COLUMNS FROM tabla y DESCRIBE tabla.');
        }

        if (preg_match('~\b(?:FROM|JOIN)\s+\w+\s*\.~i', $limpio) || $this->califica_una_tabla_con_otra_base($limpio)) {
            return $this->rechazo('otra_base', 'La consulta debe usar únicamente tablas de la base del admin, sin `base.tabla`.');
        }

        if ($this->tiene_un_comodin_dentro_de_parentesis($limpio)) {
            return $this->rechazo('comodin_en_parentesis', 'Dentro de un paréntesis, `*` solo se admite como COUNT(*). Nombrá las columnas.');
        }

        if (preg_match_all('~\b([A-Za-z_]\w*)\s*\(~', $limpio, $coincidencias)) {
            foreach ($coincidencias[1] as $funcion) {
                if (! in_array(strtoupper($funcion), self::FUNCIONES, true)) {
                    return $this->rechazo('funcion_no_permitida', 'La consulta usa `' . $funcion . '(`, que no está en la lista de funciones habilitadas.');
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
     * ¿Hay un `*` adentro de un paréntesis que no sea exactamente `COUNT(*)`?
     *
     * 🔴 Es más ancha de lo necesario a propósito: también rechaza una multiplicación entre
     * paréntesis (`SUM(a * b)`). Distinguir un comodín de un producto exige conocer todos los
     * modificadores de SELECT de MySQL, y un modificador que falte es un agujero por el que una
     * tabla derivada renombra `api_key`. La cuenta se hace afuera del paréntesis o del lado del
     * que consulta.
     *
     * @param string $limpio Consulta sin literales ni backticks.
     *
     * @return bool
     */
    private function tiene_un_comodin_dentro_de_parentesis($limpio)
    {
        $sin_count = preg_replace('~\bCOUNT\s*\(\s*\*\s*\)~i', 'COUNT(1)', $limpio);

        $profundidad = 0;
        $largo       = strlen($sin_count);

        for ($i = 0; $i < $largo; $i++) {
            $caracter = $sin_count[$i];

            if ($caracter === '(') {
                $profundidad++;
            } elseif ($caracter === ')') {
                $profundidad = $profundidad > 0 ? $profundidad - 1 : 0;
            } elseif ($caracter === '*' && $profundidad > 0) {
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

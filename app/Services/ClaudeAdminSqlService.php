<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Corre una consulta SQL de SOLO LECTURA sobre la base del admin y devuelve el resultado ya
 * redactado (`POST claude/support-codex/admin-sql`, misión `acceso-soporte-al-admin-lectura`,
 * 11/11/2026).
 *
 * Tres barreras, en este orden, y ninguna confía en la anterior:
 *
 *  1. `ClaudeAdminSqlValidator` rechaza lo que no sea una lectura admitida, antes de tocar MySQL.
 *  2. La consulta corre en una transacción `READ ONLY` que SIEMPRE termina en rollback, con tope
 *     de tiempo. Si el validador dejara pasar una escritura, MySQL la rechaza (error 1792).
 *  3. El resultado se redacta por NOMBRE de columna (y por clave, recursivo, dentro de un valor
 *     JSON): lo que matchea `claude_admin_sql.patron_sensible` sale como `[dato reservado]`. Es
 *     lo que cubre `SELECT * FROM clients`, que no nombra `api_key` y por eso pasa el validador.
 *
 * 🔴 CORRE EN UNA CONEXIÓN APARTE, NO EN LA DE LA APLICACIÓN. `SET SESSION TRANSACTION READ ONLY`
 * y `START TRANSACTION` son estado de la SESIÓN de MySQL: hechos sobre la conexión por defecto
 * dejarían al resto del request (y, con un worker de larga vida, al request siguiente) en solo
 * lectura si algo fallara a mitad de camino, y un `START TRANSACTION` confirmaría de prepo
 * cualquier transacción abierta —la de un test con `DatabaseTransactions`, por ejemplo—. La
 * conexión se arma clonando la config de la conexión por defecto, se usa para UNA consulta y se
 * descarta (`DB::purge`), así que no hay sesión que restaurar; igual se la restaura antes de
 * soltarla, por si el día de mañana alguien la reutiliza.
 */
class ClaudeAdminSqlService
{
    /** Nombre de la conexión descartable. */
    const CONEXION = 'claude_admin_sql_lectura';

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
     * Valida, corre y redacta.
     *
     * @param mixed $sql La consulta tal como llegó.
     *
     * @return array<string, mixed> Con `ok` true: `read_only`, `columns`, `rows`, `row_count`,
     *                              `truncated`. Con `ok` false: `codigo` y `error`.
     */
    public function consultar($sql)
    {
        $validacion = $this->validador->validar($sql);

        if (! $validacion['ok']) {
            return $validacion;
        }

        $resultado = $this->ejecutar_en_solo_lectura($validacion['sql']);

        if (! $resultado['ok']) {
            return $resultado;
        }

        $filas = [];
        foreach ($resultado['rows'] as $fila) {
            $filas[] = $this->redactar_fila($fila);
        }

        return [
            'ok'        => true,
            'read_only' => true,
            'columns'   => $resultado['columns'],
            'rows'      => $filas,
            'row_count' => count($filas),
            'truncated' => $resultado['truncated'],
        ];
    }

    /**
     * Corre UNA sentencia en una transacción de solo lectura, sobre una conexión aparte, y siempre
     * hace rollback.
     *
     * 🔴 NO VALIDA: recibe la sentencia tal cual. Es pública para que un test pueda mandarle un
     * `UPDATE` y comprobar que la barrera de MySQL existe de verdad, sin depender del validador.
     * El único que la llama en la aplicación es `consultar()`, con una consulta ya validada.
     *
     * @param string $sql Sentencia a ejecutar.
     *
     * @return array<string, mixed> Con `ok` true: `columns`, `rows` (sin redactar), `truncated`.
     *                              Con `ok` false: `codigo` = `consulta_fallida`, `error`, `sqlstate`
     *                              y `codigo_mysql`.
     */
    public function ejecutar_en_solo_lectura($sql)
    {
        $max_filas = (int) config('claude_admin_sql.max_filas', 200);
        $pdo       = null;

        config(['database.connections.' . self::CONEXION => config('database.connections.' . config('database.default'))]);

        try {
            $pdo = DB::connection(self::CONEXION)->getPdo();

            $this->limitar_el_tiempo($pdo);

            /* El validador lee la consulta con las reglas de MySQL por defecto: `\'` escapa una
               comilla y las comillas dobles no son identificadores. Si el servidor tuviera
               `NO_BACKSLASH_ESCAPES` o `ANSI_QUOTES`, validador y MySQL leerían cosas distintas; se
               los saca de ESTA sesión, que es descartable. */
            $pdo->exec("SET SESSION sql_mode = REPLACE(REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_BACKSLASH_ESCAPES', ''), 'ANSI_QUOTES', ''), 'ANSI', '')");

            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->exec('START TRANSACTION READ ONLY');

            try {
                $consulta = $pdo->query($sql);

                $columnas = [];
                $cantidad = $consulta->columnCount();
                for ($i = 0; $i < $cantidad; $i++) {
                    $meta   = $consulta->getColumnMeta($i);
                    $nombre = is_array($meta) && isset($meta['name']) ? (string) $meta['name'] : (string) $i;

                    if (! in_array($nombre, $columnas, true)) {
                        $columnas[] = $nombre;
                    }
                }

                $filas     = [];
                $recortado = false;
                while (($fila = $consulta->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if (count($filas) >= $max_filas) {
                        $recortado = true;
                        break;
                    }

                    $filas[] = $fila;
                }

                $consulta->closeCursor();
            } finally {
                /* Siempre rollback: no hay nada que confirmar en una lectura. */
                $pdo->exec('ROLLBACK');
            }

            return ['ok' => true, 'columns' => $columnas, 'rows' => $filas, 'truncated' => $recortado];
        } catch (\Throwable $e) {
            return $this->fallo($e);
        } finally {
            if ($pdo !== null) {
                try {
                    $pdo->exec('SET SESSION TRANSACTION READ WRITE');
                } catch (\Throwable $e) {
                    /* La conexión se descarta abajo: si no se pudo restaurar, se pierde con ella. */
                }
            }

            DB::purge(self::CONEXION);
        }
    }

    /**
     * Tope de tiempo de la consulta. `max_execution_time` es de MySQL (5.7.8+, en milisegundos);
     * MariaDB lo llama `max_statement_time` (en segundos). Si ninguna existe, la consulta corre
     * sin tope de base en vez de fallar: el límite de filas y el timeout de PHP siguen ahí.
     *
     * @param PDO $pdo Conexión descartable.
     *
     * @return void
     */
    private function limitar_el_tiempo(PDO $pdo)
    {
        $segundos = (int) config('claude_admin_sql.max_segundos', 8);

        if ($segundos < 1) {
            return;
        }

        try {
            $pdo->exec('SET SESSION max_execution_time = ' . ($segundos * 1000));

            return;
        } catch (\Throwable $e) {
            /* No es MySQL 5.7.8+: se prueba la variable de MariaDB. */
        }

        try {
            $pdo->exec('SET SESSION max_statement_time = ' . $segundos);
        } catch (\Throwable $e) {
            /* Sin tope de base. */
        }
    }

    /**
     * Arma el fallo SIN el mensaje crudo del driver, que puede citar valores de la base (una clave
     * duplicada, un dato que no se pudo convertir). Sale el SQLSTATE, el código de MySQL y un
     * resumen fijo por código; el mensaje completo queda en el log del admin.
     *
     * @param \Throwable $e Excepción atrapada.
     *
     * @return array<string, mixed>
     */
    private function fallo(\Throwable $e)
    {
        $sqlstate     = null;
        $codigo_mysql = null;

        if ($e instanceof \PDOException && is_array($e->errorInfo)) {
            $sqlstate     = isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : null;
            $codigo_mysql = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
        }

        $resumenes = [
            1054 => 'Una de las columnas que nombra la consulta no existe. Mirá las columnas con SHOW COLUMNS FROM tabla.',
            1064 => 'MySQL no pudo interpretar la consulta: revisá la sintaxis.',
            1146 => 'Una de las tablas que nombra la consulta no existe. Mirá las tablas con SHOW TABLES.',
            1052 => 'Una columna es ambigua: calificala con el alias de su tabla.',
            1055 => 'La consulta agrupa mal: toda columna del SELECT tiene que estar en el GROUP BY o dentro de una función de agregación.',
            1792 => 'La consulta intenta escribir y la transacción es de solo lectura.',
            3024 => 'La consulta superó el tiempo máximo de ejecución. Acotala con WHERE o LIMIT.',
            1969 => 'La consulta superó el tiempo máximo de ejecución. Acotala con WHERE o LIMIT.',
        ];

        $resumen = $codigo_mysql !== null && isset($resumenes[$codigo_mysql])
            ? $resumenes[$codigo_mysql]
            : 'MySQL rechazó la consulta.';

        Log::warning('claude admin-sql: consulta fallida', [
            'sqlstate'     => $sqlstate,
            'codigo_mysql' => $codigo_mysql,
            'clase'        => get_class($e),
        ]);

        return [
            'ok'           => false,
            'codigo'       => 'consulta_fallida',
            'error'        => $resumen,
            'sqlstate'     => $sqlstate,
            'codigo_mysql' => $codigo_mysql,
        ];
    }

    /**
     * Redacta una fila: cada valor por el nombre de su columna.
     *
     * @param array<string, mixed> $fila Fila tal como la devolvió MySQL.
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
     * Redacta un valor según la clave bajo la que viene.
     *
     *  - Clave que matchea el patrón sensible → la máscara, sin mirar el valor.
     *  - Arreglo → recursivo, por clave.
     *  - Texto que es un JSON (objeto o lista) → se decodifica, se redacta por clave y se vuelve a
     *    codificar.
     *  - Cualquier otro texto → se tapan un `Bearer …`, los `clave=valor` / `clave: valor` de
     *    nombres sensibles (una línea de .env pegada en un log) y lo que sigue a «contraseña» /
     *    «clave» con la MISMA regla que usa el contexto del ticket
     *    (`SupportImplementationContextService::ocultar_claves`): un mensaje del equipo que dice
     *    «la clave es 1234» no puede salir por acá si no sale por el contexto.
     *
     * @param mixed  $valor Valor a redactar.
     * @param string $clave Nombre de columna o clave del JSON.
     *
     * @return mixed
     */
    public function redactar($valor, $clave = '')
    {
        $patron  = (string) config('claude_admin_sql.patron_sensible');
        $mascara = (string) config('claude_admin_sql.mascara', '[dato reservado]');

        if ($clave !== '' && $patron !== '' && preg_match('~' . $patron . '~i', $clave)) {
            return $mascara;
        }

        if ($clave !== '' && $this->es_una_columna_reservada($clave)) {
            return $mascara;
        }

        if (is_array($valor)) {
            foreach ($valor as $k => $v) {
                $valor[$k] = $this->redactar($v, (string) $k);
            }

            return $valor;
        }

        if (! is_string($valor)) {
            return $valor;
        }

        $primero = substr(ltrim($valor), 0, 1);
        if ($primero === '{' || $primero === '[') {
            $json = json_decode($valor, true);

            if (is_array($json)) {
                return json_encode($this->redactar($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }

        $valor = $this->tapar_etiquetas_de_credenciales($valor, $patron, $mascara);

        $tapado = preg_replace('~(Bearer\s+)[A-Za-z0-9._\~+/\-]+~i', '$1' . $mascara, $valor);
        $tapado = $tapado === null ? $valor : $tapado;

        /* El valor entre comillas se tapa ENTERO, con sus espacios: es el caso de un JSON cortado
           por la mitad o pegado a otro texto, que ya no se puede decodificar y redactar por clave. */
        $con_claves = preg_replace(
            '~((?:[A-Za-z0-9_]*(?:password|passwd|secret|token|api_?key|private_?key|authorization))["\']?\s*[:=]\s*)(?:"[^"]*"?|\'[^\']*\'?|[^\s,}\]]+)~i',
            '$1' . $mascara,
            $tapado
        );
        $tapado = $con_claves === null ? $tapado : $con_claves;

        $oculto = SupportImplementationContextService::ocultar_claves($tapado);
        $tapado = $oculto === null ? $tapado : $oculto;

        return $this->tapar_despues_de_una_etiqueta_de_clave($tapado);
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
     * 🔴 LA ETIQUETA NO NECESITA EMPEZAR UNA PALABRA. `CONCAT('x', body)` devuelve
     * «xContraseña: 1234»: si se exigiera un borde de palabra antes de la etiqueta, pegarle una
     * letra adelante alcanzaría para que el valor saliera pelado (lo encontró el test que recorre
     * todas las funciones habilitadas). El costo es tapar también después de «enclave» o «bypass».
     * Sí se exige que la etiqueta TERMINE la palabra: «pinturería» o «tokenizar» no disparan nada.
     *
     * No toca un nombre de etiqueta XML (`<Token>`, `</ar:Token>`): de esos se ocupa
     * `tapar_etiquetas_de_credenciales`, que deja el resto del documento legible.
     *
     * @param string $valor Texto.
     *
     * @return string
     */
    private function tapar_despues_de_una_etiqueta_de_clave($valor)
    {
        $etiquetas = '(?:contrase(?:ñ|n|\\?)as?|claves?|passwords?|passwd|pass|pwd|pins?|tokens?)';
        $resto     = '([ \\t]*[:=]?[ \\t]*(?:\\r?\\n[ \\t]*)?)[^\\r\\n]+';

        $tapado = preg_replace(
            '~(?<![</])(?<![\\p{L}\\p{N}_]:)(' . $etiquetas . ')(?![\\p{L}\\p{N}_])' . $resto . '~iu',
            '$1$2' . SupportImplementationContextService::MASCARA_DE_CLAVE,
            $valor
        );

        if ($tapado !== null) {
            return $tapado;
        }

        /* No es UTF-8 válido: la misma regla, por bytes. */
        $tapado = preg_replace(
            '~(?<![</])(?<![A-Za-z0-9_]:)(' . $etiquetas . ')(?![A-Za-z0-9_])' . $resto . '~i',
            '$1$2' . SupportImplementationContextService::MASCARA_DE_CLAVE,
            $valor
        );

        return $tapado === null ? $valor : $tapado;
    }

    /**
     * ¿El nombre (de columna o de clave de JSON) está en `claude_admin_sql.columnas_reservadas`?
     * Sin distinguir mayúsculas.
     *
     * @param string $nombre Nombre a mirar.
     *
     * @return bool
     */
    private function es_una_columna_reservada($nombre)
    {
        $nombre = mb_strtolower((string) $nombre, 'UTF-8');

        foreach ((array) config('claude_admin_sql.columnas_reservadas') as $reservada => $motivo) {
            if (mb_strtolower((string) $reservada, 'UTF-8') === $nombre) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tapa el contenido de las etiquetas XML/HTML de credenciales: las que se llaman `Token`,
     * `Sign`, `Password` o `Clave`, y toda etiqueta cuyo nombre matchee el patrón sensible. Con o
     * sin prefijo de namespace (`<ar:Token>`), con atributos, sin distinguir mayúsculas, y también
     * cuando el XML viene escapado (`&lt;Token&gt;…&lt;/Token&gt;`), que es como queda un SOAP
     * guardado adentro de otro.
     *
     * 🔴 POR QUÉ: el ticket de acceso de ARCA viaja como `<Auth><Token>…</Token><Sign>…</Sign>` y
     * ninguna de las otras reglas de texto (Bearer, `clave=valor`) lo ve. La columna que lo guarda
     * está reservada; esto es la segunda reja, para el próximo XML que aparezca en otra columna.
     *
     * @param string $valor   Texto.
     * @param string $patron  Patrón sensible (sin delimitadores).
     * @param string $mascara Máscara.
     *
     * @return string
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
            '~<(' . $nombre . ')(\s[^<>]*)?>.*?</\1\s*>~is',
            '<$1$2>' . $mascara . '</$1>',
            $valor
        );
        $tapado = $tapado === null ? $valor : $tapado;

        $escapado = preg_replace(
            '~&lt;(' . $nombre . ')((?:\s(?:(?!&gt;).)*)?)&gt;.*?&lt;/\1\s*&gt;~is',
            '&lt;$1$2&gt;' . $mascara . '&lt;/$1&gt;',
            $tapado
        );

        return $escapado === null ? $tapado : $escapado;
    }

    /**
     * Corta un texto al largo máximo por valor, y lo dice.
     *
     * @param mixed $valor Valor ya redactado.
     *
     * @return mixed
     */
    private function recortar($valor)
    {
        if (! is_string($valor)) {
            return $valor;
        }

        $max = (int) config('claude_admin_sql.max_caracteres_valor', 4000);

        if (mb_check_encoding($valor, 'UTF-8')) {
            return mb_strlen($valor, 'UTF-8') > $max ? mb_substr($valor, 0, $max, 'UTF-8') . ' [recortado]' : $valor;
        }

        return strlen($valor) > $max ? substr($valor, 0, $max) . ' [recortado]' : $valor;
    }
}

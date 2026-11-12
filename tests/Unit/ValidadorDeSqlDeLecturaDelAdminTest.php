<?php

namespace Tests\Unit;

use App\Services\ClaudeAdminSqlValidator;
use Tests\TestCase;

/**
 * El validador de `POST claude/support-codex/admin-sql`, barrera por barrera (misión
 * `acceso-soporte-al-admin-lectura`, 11/11/2026).
 *
 * Es la primera de las tres rejas (validador, transacción de solo lectura, redacción). Acá se
 * prueba sola, sin base: qué deja pasar y, sobre todo, con qué código rechaza cada cosa. Los casos
 * de RENOMBRADO de columnas (`UNION`, `WITH`, `TABLE`, comodín dentro de un paréntesis) son los que
 * importan de verdad: la redacción del resultado es por nombre de columna, así que una consulta que
 * le cambia el nombre a `api_key` sin nombrarla se llevaría la clave de todos los clientes.
 */
class ValidadorDeSqlDeLecturaDelAdminTest extends TestCase
{
    /**
     * Valida con la config real del proyecto.
     *
     * @param mixed $sql Consulta.
     *
     * @return array<string, mixed>
     */
    private function validar($sql): array
    {
        return (new ClaudeAdminSqlValidator())->validar($sql);
    }

    /**
     * Afirma que la consulta se rechaza con ese código.
     *
     * @param string $codigo Código esperado.
     * @param mixed  $sql    Consulta.
     *
     * @return void
     */
    private function assert_rechaza(string $codigo, $sql): void
    {
        $resultado = $this->validar($sql);

        $this->assertFalse($resultado['ok'], 'Tendría que rechazar: ' . (is_string($sql) ? $sql : gettype($sql)));
        $this->assertSame($codigo, $resultado['codigo'], 'Código inesperado para: ' . (is_string($sql) ? $sql : gettype($sql)));
        $this->assertNotSame('', trim((string) $resultado['error']));
    }

    /**
     * Afirma que la consulta pasa.
     *
     * @param string $sql Consulta.
     *
     * @return void
     */
    private function assert_admite(string $sql): void
    {
        $resultado = $this->validar($sql);

        $this->assertTrue($resultado['ok'], 'Tendría que admitir: ' . $sql . ' — rechazó con ' . (isset($resultado['codigo']) ? $resultado['codigo'] : '?'));
        $this->assertSame(trim($sql), $resultado['sql']);
    }

    public function test_admite_las_lecturas_que_soporte_necesita(): void
    {
        $this->assert_admite('SELECT id, name, slug, estado FROM clients WHERE is_active = 1 ORDER BY id DESC LIMIT 20');
        $this->assert_admite('SELECT * FROM clients WHERE id = 5');
        $this->assert_admite('SELECT c.name, v.version FROM clients c JOIN versions v ON v.id = c.current_version_id WHERE c.slug = \'fenix\'');
        $this->assert_admite('SELECT COUNT(*) AS total, estado FROM clients GROUP BY estado');
        $this->assert_admite('SELECT id FROM versions WHERE status IN (\'published\', \'draft\') AND title LIKE \'%stock%\'');
        $this->assert_admite('SELECT c.id FROM clients c WHERE EXISTS (SELECT 1 FROM client_ecommerces e WHERE e.client_id = c.id)');
        $this->assert_admite('  select DATE_FORMAT(created_at, \'%Y-%m\') as mes, count(*) from support_tickets group by mes  ');
        $this->assert_admite('EXPLAIN SELECT id FROM clients WHERE slug = \'x\'');
        $this->assert_admite('SHOW TABLES');
        $this->assert_admite('SHOW COLUMNS FROM clients');
        $this->assert_admite('SHOW FULL COLUMNS FROM `versions`');
        $this->assert_admite('DESCRIBE client_ecommerces');
        $this->assert_admite('desc versions');
    }

    public function test_un_dato_literal_no_es_una_instruccion(): void
    {
        /* Lo que está entre comillas simples se vacía antes de analizar: buscar el texto
           «password» o «delete» en un mensaje es una lectura legítima. */
        $this->assert_admite('SELECT id FROM support_messages WHERE body LIKE \'%password%\'');
        $this->assert_admite('SELECT id FROM support_messages WHERE body = \'DROP TABLE clients UNION information_schema\' ');

        /* Y los caracteres vedados también pueden ir DENTRO de un literal: un mail, un texto con
           punto y coma, un numeral. Se buscan con los literales ya vaciados. */
        $this->assert_admite('SELECT id FROM leads WHERE email = \'a@b.test\'');
        $this->assert_admite('SELECT id FROM support_messages WHERE body = \'hola; chau -- y /* esto */ #1\'');
        $this->assert_admite('SELECT id FROM leads WHERE name = \'O\\\'Brien\' OR name = \'D\'\'Angelo\'');
        $this->assert_admite('SELECT id FROM leads WHERE name = \'termina en barra \\\\\' AND email = \'a@b.test\'');

        /* La consulta que se ejecuta es la que llegó, con sus literales intactos. */
        $this->assertSame(
            'SELECT id FROM leads WHERE email = \'a@b.test\'',
            $this->validar('  SELECT id FROM leads WHERE email = \'a@b.test\'  ')['sql']
        );
    }

    public function test_el_vaciado_de_literales_no_se_puede_usar_para_esconder_codigo(): void
    {
        /* 🔴 Cada una de estas intenta que el validador vea un literal donde MySQL ve código (o al
           revés), para que `api_key` quede «adentro de un texto» y no se analice. */

        /* Fuera de un literal, los caracteres vedados siguen vedados aunque haya literales cerca. */
        $this->assert_rechaza('varias_sentencias', 'SELECT id FROM clients WHERE name = \'a\'; DELETE FROM clients');
        $this->assert_rechaza('varias_sentencias', 'SELECT id FROM clients WHERE name = \'a\' -- comentario');
        /* Con una comilla suelta en el comentario se rechaza antes, por el literal sin cerrar. */
        $this->assert_rechaza('consulta_invalida', 'SELECT id FROM clients WHERE name = \'a\' -- \'');
        $this->assert_rechaza('varias_sentencias', 'SELECT id FROM clients WHERE name = \'a\' AND id = @x');
        $this->assert_rechaza('varias_sentencias', 'SELECT \'a\' # comentario');
        $this->assert_rechaza('consulta_invalida', 'SELECT \'a\' # \'');

        /* Una comilla adentro de un comentario: para MySQL el comentario no abre ningún literal y
           `api_key` es código. El `/*` y el `--` quedan antes de la comilla y sobreviven al vaciado. */
        $this->assert_rechaza('varias_sentencias', 'SELECT 1 /* \' */ , api_key , /* \' */ 2 FROM clients');
        $this->assert_rechaza('varias_sentencias', "SELECT 1 -- '\n , api_key -- '\n FROM clients");
        $this->assert_rechaza('varias_sentencias', "SELECT 1 # '\n , api_key # '\n FROM clients");

        /* La barra invertida: `\'` NO cierra el literal, `\\'` sí. */
        $this->assert_rechaza('consulta_invalida', 'SELECT id FROM clients WHERE name = \'a\\\' OR 1=1');
        $this->assert_rechaza('campo_sensible', 'SELECT id FROM clients WHERE name = \'a\\\\\' OR api_key = \'x\'');
        $this->assert_rechaza('campo_sensible', 'SELECT id FROM clients WHERE name = \'a\'\'b\' OR api_key = \'\'');

        /* Un alias entre backticks con una comilla adentro: para MySQL es un nombre; sin la regla
           de backticks, acá abriría un literal que se traga `api_key AS k`. */
        $this->assert_rechaza('consulta_invalida', 'SELECT 1 AS `a\'`, api_key AS k, 2 AS `\'b` FROM clients');
        $this->assert_rechaza('consulta_invalida', 'SELECT id AS `con espacio` FROM clients');
        $this->assert_rechaza('consulta_invalida', 'SELECT id FROM `clients');
        $this->assert_rechaza('campo_sensible', 'SELECT `api_key` AS k FROM `clients`');

        /* Comillas dobles fuera de un literal: no se admiten (con ANSI_QUOTES serían nombres). */
        $this->assert_rechaza('comillas_dobles', 'SELECT "api_key" FROM clients');
        $this->assert_rechaza('comillas_dobles', 'SELECT 1 AS "a\'", api_key, 2 AS "\'b" FROM clients');
        $this->assert_admite('SELECT id FROM leads WHERE notes = \'dijo "hola"\'');
    }

    public function test_un_parentesis_de_agrupacion_se_admite_y_una_tabla_derivada_no(): void
    {
        $this->assert_admite('SELECT id FROM clients WHERE is_active = 1 AND (estado = \'a\' OR estado = \'b\')');
        $this->assert_admite('SELECT id FROM clients WHERE (estado = \'a\' OR estado = \'b\') AND NOT (is_active = 0)');
        $this->assert_admite('SELECT c.id FROM clients c JOIN versions v ON (v.id = c.current_version_id) WHERE c.id > 0 OR (c.id = 0)');
        $this->assert_admite('SELECT estado, COUNT(*) FROM clients GROUP BY estado HAVING (COUNT(*) > 1)');
        $this->assert_admite('SELECT CASE WHEN (is_active = 1) THEN (id + 1) ELSE (0) END AS x FROM clients');

        /* 🔴 `FROM (`, `JOIN (` y `SELECT (` siguen prohibidos. */
        $this->assert_rechaza('funcion_no_permitida', 'SELECT t.id FROM (SELECT id FROM clients) t');
        $this->assert_rechaza('funcion_no_permitida', 'SELECT c.id FROM clients c JOIN (SELECT id FROM versions) v ON v.id = c.id');
        $this->assert_rechaza('funcion_no_permitida', 'SELECT (SELECT name FROM versions LIMIT 1) FROM clients');
        $this->assert_rechaza('comodin_en_parentesis', 'SELECT t.id FROM (SELECT * FROM clients) t');
        $this->assert_rechaza('comodin_en_parentesis', 'SELECT id FROM clients WHERE (SELECT * FROM versions LIMIT 1) = 1');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT id FROM clients WHERE (id) IN (TABLE versions)');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT id FROM clients WHERE (id, name) IN (VALUES ROW(1, 2))');

        /* Y un nombre sensible adentro de un paréntesis de agrupación sigue siendo sensible. */
        $this->assert_rechaza('campo_sensible', 'SELECT id FROM clients WHERE is_active = 1 AND (api_key LIKE \'a%\' OR id = 1)');
    }

    public function test_una_multiplicacion_dentro_de_un_parentesis_se_admite_y_un_comodin_no(): void
    {
        $this->assert_admite('SELECT SUM(precio_plan * cantidad_empleados) FROM clients');
        $this->assert_admite('SELECT ROUND(total_mensualidad * 1.21, 2) FROM clients');
        $this->assert_admite('SELECT ROUND((precio_plan + 1) * (cantidad_empleados - 1), 2) FROM clients');
        $this->assert_admite('SELECT ROUND(2 * precio_plan * -1, 2) FROM clients');
        $this->assert_admite('SELECT id FROM clients WHERE (c.precio_plan * 2) > 10');

        foreach ([
            'SELECT id FROM clients WHERE EXISTS (SELECT * FROM versions)',
            'SELECT id FROM clients WHERE id IN (SELECT DISTINCT * FROM versions)',
            'SELECT id FROM clients WHERE id IN (SELECT SQL_NO_CACHE * FROM versions)',
            'SELECT id FROM clients WHERE id IN (SELECT ALL *, id FROM versions)',
            'SELECT id FROM clients WHERE id IN (SELECT v.* FROM versions v)',
            'SELECT id FROM clients WHERE id IN (SELECT id, * FROM versions)',
            'SELECT COUNT(DISTINCT *) FROM clients',
            'SELECT id FROM clients WHERE ( * ) = 1',
            'SELECT id FROM clients WHERE (id, *) = (1, 2)',
            'SELECT id FROM clients WHERE id IN (SELECT id * FROM versions)',
            'SELECT GROUP_CONCAT(c.*) FROM clients c',
        ] as $sql) {
            $this->assert_rechaza('comodin_en_parentesis', $sql);
        }
    }

    public function test_rechaza_una_consulta_que_nombra_una_columna_reservada(): void
    {
        $reservadas = array_keys((array) config('claude_admin_sql.columnas_reservadas'));

        foreach (['request', 'response', 'raw', 'setup_data', 'ecommerce_setup_data', 'approval_payload', 'env_manual_values'] as $minima) {
            $this->assertContains($minima, $reservadas, 'Falta una columna reservada obligatoria: ' . $minima);
        }

        foreach ($reservadas as $columna) {
            foreach ([
                'SELECT ' . $columna . ' FROM clients',
                'SELECT ' . mb_strtoupper($columna, 'UTF-8') . ' AS x FROM clients',
                'SELECT id FROM clients WHERE c.' . $columna . ' LIKE \'a%\'',
                'SELECT id FROM clients ORDER BY ' . $columna,
                'SELECT JSON_EXTRACT(' . $columna . ', \'$.a\') FROM clients',
            ] as $sql) {
                $this->assert_rechaza('campo_sensible', $sql);
            }
        }

        /* 🔴 El caso que originó la lista: el SOAP de ARCA con el ticket de acceso. */
        $this->assert_rechaza('campo_sensible', 'SELECT request, response FROM mensualidad_invoices');
        $this->assert_rechaza('campo_sensible', 'SELECT id FROM mensualidad_invoices WHERE request LIKE \'%Token%\'');

        /* Es por PALABRA: un nombre que solo la contiene, o el mismo texto dentro de un literal, pasan. */
        $this->assert_admite('SELECT requested_at, logo_url, pinned_at, deadline FROM leads');
        $this->assert_admite('SELECT id FROM support_messages WHERE body LIKE \'%request%\' OR body = \'la clave\'');

        /* Y cada reservada dice por qué. */
        foreach ((array) config('claude_admin_sql.columnas_reservadas') as $columna => $motivo) {
            $this->assertGreaterThan(20, strlen(trim((string) $motivo)), 'La columna reservada "' . $columna . '" no dice por qué.');
        }
    }

    public function test_rechaza_lo_que_no_es_una_consulta_de_texto_con_largo_valido(): void
    {
        $this->assert_rechaza('consulta_invalida', null);
        $this->assert_rechaza('consulta_invalida', ['SELECT 1']);
        $this->assert_rechaza('consulta_invalida', '');
        $this->assert_rechaza('consulta_invalida', "   \n ");
        $this->assert_rechaza('consulta_invalida', 'SELECT 1 FROM clients WHERE name = \'' . str_repeat('a', 12000) . '\'');
        $this->assert_rechaza('consulta_invalida', 'SELECT id FROM clients WHERE name = \'sin cerrar');
    }

    public function test_rechaza_varias_sentencias_comentarios_y_variables(): void
    {
        $this->assert_rechaza('varias_sentencias', 'SELECT 1; SELECT 2');
        $this->assert_rechaza('varias_sentencias', 'SELECT 1;');
        $this->assert_rechaza('varias_sentencias', 'SELECT id FROM clients -- comentario');
        $this->assert_rechaza('varias_sentencias', 'SELECT id /* oculto */ FROM clients');
        $this->assert_rechaza('varias_sentencias', 'SELECT id FROM clients # comentario');
        $this->assert_rechaza('varias_sentencias', 'SELECT @@version');
        $this->assert_rechaza('varias_sentencias', "SELECT id\0 FROM clients");
        $this->assert_rechaza('comillas_dobles', 'SELECT id FROM clients WHERE name = "Fenix"');
    }

    public function test_rechaza_toda_escritura_y_toda_sentencia_que_no_sea_de_lectura(): void
    {
        $this->assert_rechaza('operacion_no_permitida', 'UPDATE clients SET name = \'x\' WHERE id = 1');
        $this->assert_rechaza('operacion_no_permitida', 'DELETE FROM clients WHERE id = 1');
        $this->assert_rechaza('operacion_no_permitida', 'INSERT INTO versions (version) VALUES (\'9.9.9\')');
        $this->assert_rechaza('operacion_no_permitida', 'DROP TABLE clients');
        $this->assert_rechaza('operacion_no_permitida', 'TRUNCATE TABLE clients');
        $this->assert_rechaza('operacion_no_permitida', 'ALTER TABLE clients ADD x INT');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT id FROM clients INTO OUTFILE \'/tmp/x\'');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT id FROM clients FOR UPDATE');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT id FROM clients FOR SHARE');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT SLEEP(10)');
        $this->assert_rechaza('operacion_no_permitida', 'SET SESSION TRANSACTION READ WRITE');
        $this->assert_rechaza('operacion_no_permitida', 'SHOW CREATE TABLE clients');
        $this->assert_rechaza('solo_select', 'SHOW PROCESSLIST');
        $this->assert_rechaza('solo_select', 'SHOW STATUS');
        /* `variables` además es una columna reservada: se rechaza antes, por eso. */
        $this->assert_rechaza('campo_sensible', 'SHOW VARIABLES');
        $this->assert_rechaza('solo_select', 'EXPLAIN ANALYZE SELECT id FROM clients');
        $this->assert_rechaza('solo_select', 'ANALYZE clients');
    }

    public function test_rechaza_las_formas_de_renombrar_columnas_sin_nombrarlas(): void
    {
        /* 🔴 Cada una de estas devolvería `api_key` bajo otro nombre, y la redacción es por nombre. */
        $this->assert_rechaza('operacion_no_permitida', 'SELECT 1, 2, 3 UNION SELECT * FROM clients');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT 1 UNION ALL SELECT id FROM clients');
        $this->assert_rechaza('operacion_no_permitida', 'WITH t AS (SELECT id FROM clients) SELECT id FROM t');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT c25 FROM (TABLE clients) AS t (c1, c2, c25)');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT a FROM (VALUES ROW(1)) AS t (a)');
        $this->assert_rechaza('comodin_en_parentesis', 'SELECT t.id FROM clients c JOIN (SELECT * FROM clients) t ON t.id = c.id');
        $this->assert_rechaza('comodin_en_parentesis', 'SELECT id FROM clients WHERE EXISTS (SELECT * FROM versions)');
        $this->assert_rechaza('comodin_en_parentesis', 'SELECT id FROM clients WHERE id IN (SELECT c.* FROM clients c)');
        $this->assert_rechaza('comodin_en_parentesis', 'SELECT COUNT(DISTINCT *) FROM clients');

        /* Y una tabla derivada, aun nombrando columnas, tampoco: `FROM (` no está en la lista. */
        $this->assert_rechaza('funcion_no_permitida', 'SELECT t.id FROM (SELECT id FROM clients) t');
    }

    public function test_count_con_asterisco_es_el_unico_comodin_admitido_dentro_de_un_parentesis(): void
    {
        $this->assert_admite('SELECT COUNT(*) FROM clients');
        $this->assert_admite('SELECT count( * ) FROM clients c WHERE c.id IN (SELECT client_id FROM client_ecommerces)');
    }

    public function test_rechaza_una_consulta_que_nombra_un_campo_sensible(): void
    {
        foreach ([
            'SELECT api_key FROM clients',
            'SELECT `api_key` FROM clients',
            'SELECT id FROM clients WHERE inbound_api_key LIKE \'a%\'',
            'SELECT id FROM clients ORDER BY API_KEY',
            'SELECT password FROM admins',
            'SELECT remember_token FROM admins',
            'SELECT provisioning_secrets FROM client_apis',
            'SELECT env_manual_values FROM client_installations',
            'SELECT form_token FROM implementations',
            'SELECT password_cifrada FROM clients',
            'SELECT google_refresh_token_encrypted FROM clients',
            'SELECT p256dh FROM clients',
            'SELECT clave_fiscal FROM clients',
            'SELECT session_id FROM clients',
            'SELECT private_key FROM clients',
            'SELECT credentials FROM clients',
            'SELECT * FROM client_ai_token_usages',
            'SHOW COLUMNS FROM personal_access_tokens',
            'DESCRIBE mensualidad_invoice_pdf_access_tokens',
        ] as $sql) {
            $this->assert_rechaza('campo_sensible', $sql);
        }
    }

    public function test_rechaza_toda_tabla_vedada_del_config_en_select_show_columns_y_describe(): void
    {
        $vedadas = array_keys((array) config('claude_admin_sql.tablas_vedadas'));

        $this->assertNotEmpty($vedadas);

        foreach ($vedadas as $tabla) {
            foreach ([
                'SELECT * FROM ' . $tabla,
                'SELECT COUNT(*) FROM `' . strtoupper($tabla) . '`',
                'SELECT c.id FROM clients c JOIN ' . $tabla . ' x ON x.id = c.id',
                'SHOW COLUMNS FROM ' . $tabla,
                'DESCRIBE ' . $tabla,
            ] as $sql) {
                $resultado = $this->validar($sql);

                $this->assertFalse($resultado['ok'], 'Tendría que rechazar: ' . $sql);
                /* Varias vedadas tienen además un nombre sensible (…_tokens): cualquiera de los
                   dos códigos es un rechazo correcto. */
                $this->assertContains($resultado['codigo'], ['tabla_vedada', 'campo_sensible'], $sql);
            }
        }

        /* 🔴 Las dos conversaciones de implementación: guardan la entrega del acceso (usuario y
           contraseña del cliente). El resultado saldría tapado, pero filtrando por texto se puede
           inferir; por eso no se consultan de ninguna forma, ni para contar. */
        foreach (['implementation_messages', 'ecommerce_implementation_messages'] as $tabla) {
            $this->assertArrayHasKey($tabla, (array) config('claude_admin_sql.tablas_vedadas'));

            $this->assert_rechaza('tabla_vedada', 'SELECT id FROM ' . $tabla . ' WHERE body LIKE \'%Contraseña: a%\'');
            $this->assert_rechaza('tabla_vedada', 'SELECT COUNT(*) FROM ' . $tabla);
            $this->assert_rechaza('tabla_vedada', 'SELECT i.id FROM implementations i JOIN ' . $tabla . ' m ON m.implementation_id = i.id');
            $this->assert_rechaza('tabla_vedada', 'SELECT id FROM implementations WHERE EXISTS (SELECT 1 FROM ' . $tabla . ' m WHERE m.body LIKE \'%a%\')');
            $this->assert_rechaza('tabla_vedada', 'SHOW COLUMNS FROM ' . $tabla);
            $this->assert_rechaza('tabla_vedada', 'DESCRIBE `' . $tabla . '`');
        }

        /* Las otras tablas de la implementación siguen consultables. */
        $this->assert_admite('SELECT id, client_id, notes FROM implementations');

        $this->assert_rechaza('tabla_vedada', 'SELECT * FROM admin_settings');
        $this->assert_rechaza('tabla_vedada', 'SELECT * FROM jobs');
        $this->assert_rechaza('tabla_vedada', 'DESCRIBE whatsapp_config');
    }

    public function test_toda_tabla_vedada_y_toda_revisada_declara_su_motivo(): void
    {
        $vedadas   = (array) config('claude_admin_sql.tablas_vedadas');
        $revisadas = (array) config('claude_admin_sql.tablas_revisadas');

        foreach (array_merge($vedadas, $revisadas) as $tabla => $motivo) {
            $this->assertIsString($tabla, 'Las tablas van como clave, con el motivo como valor.');
            $this->assertGreaterThan(20, strlen(trim((string) $motivo)), 'La tabla "' . $tabla . '" no dice por qué está en la lista.');
        }

        $this->assertSame([], array_values(array_intersect(array_keys($vedadas), array_keys($revisadas))), 'Una tabla no puede estar vedada y revisada a la vez.');
    }

    public function test_rechaza_otra_base_y_las_bases_del_sistema(): void
    {
        $this->assert_rechaza('otra_base', 'SELECT id FROM otra_base.clients');
        $this->assert_rechaza('otra_base', 'SELECT c.id FROM clients c JOIN otra.versions v ON v.id = c.id');
        $this->assert_rechaza('otra_base', 'SELECT a.id FROM clients a, otra.versions b');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT table_name FROM information_schema.tables');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT user FROM mysql.user');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT id FROM `performance_schema`.`threads`');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT id FROM sys.version');
    }

    public function test_rechaza_una_funcion_que_no_esta_en_la_lista_positiva(): void
    {
        $this->assert_rechaza('funcion_no_permitida', 'SELECT USER()');
        $this->assert_rechaza('funcion_no_permitida', 'SELECT HEX(name) FROM clients');
        $this->assert_rechaza('funcion_no_permitida', 'SELECT JSON_OBJECT(\'a\', name) FROM clients');
        $this->assert_rechaza('funcion_no_permitida', 'SELECT id FROM clients WHERE is_active = 1 AND USER() = \'x\'');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT LOAD_FILE(\'/etc/passwd\')');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT BENCHMARK(1000000, 1)');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT GET_LOCK(\'x\', 10)');
    }

    public function test_las_funciones_habilitadas_mas_las_quitadas_son_la_lista_del_monitor(): void
    {
        /* Copia literal de `_FUNCIONES` de herramientas/soporte-codex/diagnostico.py (repo de
           conocimiento) al 11/11/2026. Esta lista es un SUBCONJUNTO de aquella: lo que falta está
           en FUNCIONES_QUITADAS, con el motivo. Si allá agregan una función, este test obliga a
           decidir acá si entra o si se quita. */
        $del_monitor = explode(' ', 'COUNT SUM AVG MIN MAX COALESCE IFNULL NULLIF IF CONCAT CONCAT_WS LOWER UPPER TRIM LTRIM RTRIM LENGTH CHAR_LENGTH ROUND ABS CEIL CEILING FLOOR DATE DATE_FORMAT YEAR MONTH DAY DAYOFMONTH HOUR MINUTE SECOND NOW CURDATE CURRENT_DATE CONVERT_TZ DATE_ADD DATE_SUB DATEDIFF TIMESTAMPDIFF CAST CONVERT JSON_EXTRACT JSON_UNQUOTE JSON_LENGTH JSON_VALID REPLACE SUBSTRING SUBSTR LEFT RIGHT STR_TO_DATE GREATEST LEAST GROUP_CONCAT DATABASE VERSION IN EXISTS NOT OVER AS');

        $propias  = ClaudeAdminSqlValidator::FUNCIONES;
        $quitadas = array_keys(ClaudeAdminSqlValidator::FUNCIONES_QUITADAS);

        $this->assertSame([], array_values(array_intersect($propias, $quitadas)), 'Una función no puede estar habilitada y quitada a la vez.');

        $union = array_merge($propias, $quitadas);
        sort($del_monitor);
        sort($union);

        $this->assertSame($del_monitor, $union);

        foreach (ClaudeAdminSqlValidator::FUNCIONES_QUITADAS as $funcion => $motivo) {
            $this->assertGreaterThan(20, strlen(trim((string) $motivo)), 'La función quitada "' . $funcion . '" no dice por qué.');
        }

        /* La lista final, escrita: cambiarla es una decisión de seguridad, no un detalle. */
        $esperadas = ['ABS', 'AS', 'AVG', 'CEIL', 'CEILING', 'CHAR_LENGTH', 'COALESCE', 'CONCAT', 'CONCAT_WS', 'CONVERT_TZ', 'COUNT',
            'CURDATE', 'CURRENT_DATE', 'DATABASE', 'DATE', 'DATEDIFF', 'DATE_ADD', 'DATE_FORMAT', 'DATE_SUB', 'DAY', 'DAYOFMONTH',
            'EXISTS', 'FLOOR', 'GREATEST', 'GROUP_CONCAT', 'HOUR', 'IF', 'IFNULL', 'IN', 'JSON_LENGTH', 'JSON_VALID', 'LEAST',
            'LENGTH', 'LOWER', 'LTRIM', 'MAX', 'MIN', 'MINUTE', 'MONTH', 'NOT', 'NOW', 'NULLIF', 'OVER', 'ROUND', 'RTRIM', 'SECOND',
            'STR_TO_DATE', 'SUM', 'TIMESTAMPDIFF', 'UPPER', 'VERSION', 'YEAR'];
        sort($esperadas);
        sort($propias);

        $this->assertSame($esperadas, $propias);
    }

    public function test_rechaza_toda_forma_de_extraer_una_parte_de_un_json(): void
    {
        /* 🔴 Reproducido por la revisión: cada una devolvía la contraseña sola, bajo un alias. La
           ruta va dentro de un literal, así que el patrón sensible no la ve. Se usa una columna
           JSON que NO está reservada, para que lo que rechace sea la regla de JSON. */
        foreach ([
            'SELECT contract_financiacion->>\'$.admin_password\' AS x FROM clients',
            'SELECT contract_financiacion->\'$.admin_password\' AS x FROM clients',
            'SELECT contract_financiacion ->> \'$.user_setup.token\' FROM clients',
            'SELECT contract_financiacion->>\'$.employees[0].password\' FROM clients',
            'SELECT id FROM clients WHERE contract_financiacion->>\'$.admin_password\' LIKE \'a%\'',
            'SELECT id FROM clients ORDER BY contract_financiacion->\'$.a\'',
            'SELECT JSON_EXTRACT(contract_financiacion, \'$.admin_password\') AS x FROM clients',
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(contract_financiacion, \'$.admin_password\')) FROM clients',
            'SELECT JSON_EXTRACT(contract_financiacion, \'$.*\') FROM clients',
            'SELECT GROUP_CONCAT(JSON_EXTRACT(contract_financiacion, \'$.admin_password\')) FROM clients',
            'SELECT CONCAT(\'x\', JSON_UNQUOTE(contract_financiacion)) FROM clients',
            'SELECT JSON_VALUE(contract_financiacion, \'$.admin_password\') FROM clients',
            'SELECT JSON_SEARCH(contract_financiacion, \'one\', \'a%\') FROM clients',
            'SELECT JSON_KEYS(contract_financiacion) FROM clients',
            'SELECT json_extract (contract_financiacion, \'$.a\') FROM clients',
        ] as $sql) {
            $this->assert_rechaza('json_no_permitido', $sql);
        }

        /* Y las tres columnas que guardan credenciales no se pueden ni nombrar. */
        $this->assert_rechaza('campo_sensible', 'SELECT data FROM implementation_stages');
        $this->assert_rechaza('campo_sensible', 'SELECT s.data FROM ecommerce_implementation_stages s');
        $this->assert_rechaza('campo_sensible', 'SELECT JSON_LENGTH(data) FROM agent_implementation_stages');

        /* Lo que sigue pudiéndose hacer con un JSON legible: traerlo entero, contarlo, validarlo. */
        $this->assert_admite('SELECT contract_financiacion, JSON_LENGTH(contract_financiacion), JSON_VALID(contract_financiacion) FROM clients');
        /* Y una flecha dentro de un texto no es un operador. */
        $this->assert_admite('SELECT id FROM support_messages WHERE body LIKE \'%paso 1 -> paso 2%\'');
    }

    public function test_rechaza_las_funciones_que_devuelven_un_fragmento_o_separan_la_etiqueta_del_valor(): void
    {
        /* 🔴 El redactor tapa lo que sigue a «Contraseña: » mirando el texto ENTERO. Cada una de
           estas devuelve el valor sin su etiqueta, o rompe la etiqueta. */
        foreach ([
            'SELECT SUBSTRING(body, 14) FROM support_messages',
            'SELECT SUBSTRING(body FROM 14 FOR 20) FROM support_messages',
            'SELECT SUBSTR(body, 14, 20) FROM support_messages',
            'SELECT RIGHT(body, 20) FROM support_messages',
            'SELECT LEFT(contract_financiacion, 120) FROM clients',
            'SELECT TRIM(LEADING \'Contraseña: \' FROM body) FROM support_messages',
            'SELECT TRIM(body) FROM support_messages',
            'SELECT CAST(body AS CHAR) FROM support_messages',
            'SELECT CAST(contract_financiacion AS CHAR(50)) FROM clients',
            'SELECT CONVERT(body USING ascii) FROM support_messages',
            'SELECT CONVERT(body, CHAR(10)) FROM support_messages',
            'SELECT substring (body, 14) FROM support_messages',
            'SELECT UPPER(RIGHT(body, 5)) FROM support_messages',
        ] as $sql) {
            $this->assert_rechaza('funcion_no_permitida', $sql);
        }

        /* Con `CHARACTER SET` cae antes, por la palabra SET: «Contraseña» no se puede volver «Contrase?a». */
        $this->assert_rechaza('operacion_no_permitida', 'SELECT CAST(body AS CHAR CHARACTER SET ascii) FROM support_messages');

        /* Ya estaban afuera: prohibida como palabra, o nunca estuvieron en la lista. */
        $this->assert_rechaza('operacion_no_permitida', 'SELECT REPLACE(body, \'Contraseña: \', \'\') FROM support_messages');
        foreach (['MID(body, 14)', 'REVERSE(body)', 'SUBSTRING_INDEX(body, \':\', -1)', 'REGEXP_SUBSTR(body, \'[0-9]+\')', 'REGEXP_REPLACE(body, \'a\', \'b\')',
            'INSERT(body, 1, 12, \'\')', 'LPAD(body, 5, \'x\')', 'RPAD(body, 5, \'x\')', 'HEX(body)', 'TO_BASE64(body)', 'ASCII(body)', 'ORD(body)',
            'LOCATE(\'a\', body)', 'INSTR(body, \'a\')', 'ELT(1, body)', 'MAKE_SET(1, body)', 'EXPORT_SET(1, body, body)', 'WEIGHT_STRING(body)',
            'JSON_ARRAY(body)', 'JSON_OBJECT(\'a\', body)', 'JSON_QUOTE(body)', 'UNHEX(body)', 'MD5(body)', 'SHA2(body, 256)', 'COMPRESS(body)'] as $llamada) {
            $resultado = $this->validar('SELECT ' . $llamada . ' FROM support_messages');

            $this->assertFalse($resultado['ok'], 'Tendría que rechazar: ' . $llamada);
            $this->assertContains($resultado['codigo'], ['funcion_no_permitida', 'operacion_no_permitida', 'json_no_permitido'], $llamada);
        }

        /* Lo que queda: no cortan ni separan. */
        $this->assert_admite('SELECT LOWER(body), UPPER(body), LTRIM(body), RTRIM(body), LENGTH(body), CONCAT(id, body) FROM support_messages');
    }

    public function test_el_validador_acepta_una_config_propia(): void
    {
        $validador = new ClaudeAdminSqlValidator([
            'max_caracteres'  => 30,
            'patron_sensible' => 'reservado',
            'tablas_vedadas'  => ['prohibida' => 'motivo de prueba, suficientemente largo'],
        ]);

        $this->assertTrue($validador->validar('SELECT api_key FROM clients')['ok']);
        $this->assertSame('campo_sensible', $validador->validar('SELECT reservado FROM x')['codigo']);
        $this->assertSame('tabla_vedada', $validador->validar('SELECT id FROM prohibida')['codigo']);
        $this->assertSame('consulta_invalida', $validador->validar('SELECT id FROM clients WHERE id = 1000')['codigo']);
    }
}

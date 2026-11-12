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

        /* ⚠️ Salvo los caracteres vedados (`;`, `--`, `#`, `@`…): esos se buscan en el texto CRUDO,
           igual que en el validador del monitor, así que tampoco pueden ir dentro de un literal.
           Un mail se busca con LIKE y `_` en lugar de la arroba. */
        $this->assert_rechaza('varias_sentencias', 'SELECT id FROM support_messages WHERE body = \'hola; chau\'');
        $this->assert_rechaza('varias_sentencias', 'SELECT id FROM leads WHERE email = \'a@b.test\'');
        $this->assert_admite('SELECT id FROM leads WHERE email LIKE \'a_b.test\'');
        $this->assert_admite('SELECT id FROM leads WHERE name = \'O\\\'Brien\' OR name = \'D\'\'Angelo\'');
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
        $this->assert_rechaza('solo_select', 'SHOW VARIABLES');
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
        $this->assert_rechaza('funcion_no_permitida', 'SELECT id FROM clients WHERE is_active = 1 AND (estado = \'a\' OR estado = \'b\')');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT LOAD_FILE(\'/etc/passwd\')');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT BENCHMARK(1000000, 1)');
        $this->assert_rechaza('operacion_no_permitida', 'SELECT GET_LOCK(\'x\', 10)');
    }

    public function test_la_lista_de_funciones_es_la_del_validador_del_monitor(): void
    {
        /* Copia literal de `_FUNCIONES` de herramientas/soporte-codex/diagnostico.py (repo de
           conocimiento) al 11/11/2026. Si una de las dos listas cambia, este test lo denuncia de
           este lado; del otro no hay quién. */
        $del_monitor = explode(' ', 'COUNT SUM AVG MIN MAX COALESCE IFNULL NULLIF IF CONCAT CONCAT_WS LOWER UPPER TRIM LTRIM RTRIM LENGTH CHAR_LENGTH ROUND ABS CEIL CEILING FLOOR DATE DATE_FORMAT YEAR MONTH DAY DAYOFMONTH HOUR MINUTE SECOND NOW CURDATE CURRENT_DATE CONVERT_TZ DATE_ADD DATE_SUB DATEDIFF TIMESTAMPDIFF CAST CONVERT JSON_EXTRACT JSON_UNQUOTE JSON_LENGTH JSON_VALID REPLACE SUBSTRING SUBSTR LEFT RIGHT STR_TO_DATE GREATEST LEAST GROUP_CONCAT DATABASE VERSION IN EXISTS NOT OVER AS');

        $propias = ClaudeAdminSqlValidator::FUNCIONES;

        sort($del_monitor);
        sort($propias);

        $this->assertSame($del_monitor, $propias);
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

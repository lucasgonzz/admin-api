<?php

namespace Tests\Feature;

use App\Services\ClaudeAdminSqlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST claude/support-codex/admin-sql`: SQL de solo lectura sobre la base del admin (misión
 * `acceso-soporte-al-admin-lectura`, 11/11/2026).
 *
 * El validador tiene sus tests unitarios (`Tests\Unit\ValidadorDeSqlDeLecturaDelAdminTest`). Acá se
 * prueba lo que solo se ve con la base de verdad:
 *
 *  1. 🔴 Que `SELECT * FROM clients` NO devuelva `api_key` ni `inbound_api_key`. Es la consulta
 *     más natural que va a escribir soporte y no nombra ningún campo sensible: la única reja que
 *     la cubre es la redacción por nombre de columna.
 *  2. 🔴 Que la transacción sea de SOLO LECTURA de verdad: se le manda un UPDATE directo al
 *     ejecutor, salteando el validador, y MySQL lo tiene que rechazar.
 *  3. Los dos tests de build contra el esquema real (tablas con `value` / `payload` / `*_values`,
 *     y columnas de nombre sensible), que rompen cuando una migración agrega un secreto sin que
 *     nadie decida qué hacer con él en `config/claude_admin_sql.php`.
 *
 * ⚠️ ESTA CLASE NO USA `DatabaseTransactions`, A PROPÓSITO. La consulta corre en una conexión
 * APARTE (ver `ClaudeAdminSqlService`), que no ve lo que un test dejó sin confirmar en la suya. Por
 * eso la fixture —un cliente— se CONFIRMA en `setUp()` y se borra en `tearDown()`; y `setUp()` barre
 * además cualquier resto de una corrida anterior que haya muerto a mitad de camino.
 */
class AdminSqlDeSoloLecturaParaSoporteTest extends TestCase
{
    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-admin-sql';

    /** Prefijo del slug de la fixture, para poder barrer restos. */
    const PREFIJO = 'fixture-admin-sql-';

    /** Valores que NO pueden aparecer en ninguna respuesta. */
    const API_KEY         = 'CANARIO-api-key-9f3a1c';
    const INBOUND_API_KEY = 'CANARIO-inbound-7b2e4d';

    /** El SOAP que se le manda a ARCA, con el ticket de acceso centinela. */
    const SOAP_REQUEST = '<soapenv:Envelope><soapenv:Body><ar:FECAESolicitar><ar:Auth><ar:Token>CANARIO-TOKEN-ARCA</ar:Token><ar:Sign>CANARIO-SIGN-ARCA</ar:Sign><ar:Cuit>20111111112</ar:Cuit></ar:Auth></ar:FECAESolicitar></soapenv:Body></soapenv:Envelope>';

    /** La respuesta cruda de ARCA. */
    const SOAP_RESPONSE = '<soap:Envelope><soap:Body><FECAESolicitarResponse><Resultado>A</Resultado><Token>CANARIO-TOKEN-RESPUESTA</Token></FECAESolicitarResponse></soap:Body></soap:Envelope>';

    /** @var int Id del cliente de la fixture. */
    private $client_id;

    /** @var string Slug del cliente de la fixture. */
    private $slug;

    /**
     * Setea la clave de ingesta y confirma la fixture.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        $this->borrar_fixtures();

        $this->slug      = self::PREFIJO . Str::lower(Str::random(10));
        $this->client_id = (int) DB::table('clients')->insertGetId([
            'uuid'            => (string) Str::uuid(),
            'name'            => 'Fixture de admin-sql',
            'company_name'    => 'Fixture SRL',
            'slug'            => $this->slug,
            'api_url'         => 'https://ejemplo.test',
            'api_key'         => self::API_KEY,
            'inbound_api_key' => self::INBOUND_API_KEY,
            'is_active'       => 1,
            'setup_data'      => json_encode([
                'rubro'       => 'ferretería',
                'db_password' => 'CANARIO-db-pass',
                'anidado'     => ['api_key' => 'CANARIO-anidado', 'ciudad' => 'Gualeguay'],
            ]),
            'contract_financiacion' => json_encode([
                'cuotas'      => 3,
                'db_password' => 'CANARIO-db-pass-2',
                'anidado'     => [
                    'api_key' => 'CANARIO-anidado-2',
                    'ciudad'  => 'Gualeguay',
                    'request' => 'CANARIO-reservada-anidada',
                    'lista'   => [['Clave' => 'CANARIO-clave-json', 'visible' => 'sí']],
                ],
            ]),
            'cobranzas_observaciones' => 'Le pasamos el acceso: la contraseña es CANARIO-clave-1234 y paga los 10.',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        /* 🔴 El caso que originó `columnas_reservadas`: una factura con el SOAP crudo de ARCA, que
           lleva el ticket de acceso (`Token` y `Sign`) en `request` y vuelve en `response`. */
        DB::table('mensualidad_invoices')->insert([
            'client_id'       => $this->client_id,
            'periodo'         => '2026-11',
            'cbte_tipo'       => 11,
            'cbte_letra'      => 'C',
            'afip_produccion' => 0,
            'resultado'       => 'A',
            'error_message'   => 'Observación 10016 de ARCA',
            'request'         => self::SOAP_REQUEST,
            'response'        => self::SOAP_RESPONSE,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Borra la fixture, pase lo que pase con el test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        try {
            $this->borrar_fixtures();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Borra todo cliente de fixture (el de este test y los restos de corridas muertas).
     *
     * @return void
     */
    private function borrar_fixtures(): void
    {
        $ids = DB::table('clients')->where('slug', 'like', self::PREFIJO . '%')->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        DB::table('mensualidad_invoices')->whereIn('client_id', $ids)->delete();
        DB::table('clients')->whereIn('id', $ids)->delete();
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'X-Claude-Task-Key' => self::CLAVE,
            'Accept'            => 'application/json',
        ];
    }

    /**
     * Manda una consulta al endpoint.
     *
     * @param mixed $sql Consulta.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    private function consultar($sql)
    {
        return $this->postJson('/api/claude/support-codex/admin-sql', ['sql' => $sql], $this->headers());
    }

    /**
     * Las tablas reales de la base de testing.
     *
     * @return array<int, string>
     */
    private function tablas_del_esquema(): array
    {
        $tablas = [];

        foreach (DB::select('SELECT table_name AS tabla FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name') as $fila) {
            $tablas[] = (string) $fila->tabla;
        }

        return $tablas;
    }

    /**
     * Las columnas reales de una tabla, en orden.
     *
     * @param string $tabla Tabla.
     *
     * @return array<int, string>
     */
    private function columnas_de(string $tabla): array
    {
        $columnas = [];

        foreach (DB::select('SELECT column_name AS columna FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position', [$tabla]) as $fila) {
            $columnas[] = (string) $fila->columna;
        }

        return $columnas;
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta y los verbos
     |----------------------------------------------------------------------------------------- */

    public function test_sin_clave_devuelve_401(): void
    {
        $this->postJson('/api/claude/support-codex/admin-sql', ['sql' => 'SELECT 1'])->assertStatus(401);
    }

    public function test_solo_admite_post(): void
    {
        $this->getJson('/api/claude/support-codex/admin-sql?sql=' . urlencode('SELECT 1'), $this->headers())->assertStatus(405);
        $this->putJson('/api/claude/support-codex/admin-sql', ['sql' => 'SELECT 1'], $this->headers())->assertStatus(405);
        $this->patchJson('/api/claude/support-codex/admin-sql', ['sql' => 'SELECT 1'], $this->headers())->assertStatus(405);
        $this->deleteJson('/api/claude/support-codex/admin-sql', ['sql' => 'SELECT 1'], $this->headers())->assertStatus(405);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. La forma de la respuesta
     |----------------------------------------------------------------------------------------- */

    public function test_una_consulta_valida_devuelve_la_forma_del_contrato(): void
    {
        $respuesta = $this->consultar('SELECT id, name, slug, is_active FROM clients WHERE slug = \'' . $this->slug . '\'');

        $respuesta->assertStatus(200);
        $this->assertSame(['ok', 'read_only', 'columns', 'rows', 'row_count', 'truncated'], array_keys($respuesta->json()));
        $this->assertTrue($respuesta->json('ok'));
        $this->assertTrue($respuesta->json('read_only'));
        $this->assertSame(['id', 'name', 'slug', 'is_active'], $respuesta->json('columns'));
        $this->assertSame(1, $respuesta->json('row_count'));
        $this->assertFalse($respuesta->json('truncated'));
        $this->assertSame(
            [['id' => $this->client_id, 'name' => 'Fixture de admin-sql', 'slug' => $this->slug, 'is_active' => 1]],
            $respuesta->json('rows')
        );
    }

    public function test_una_consulta_sin_filas_devuelve_las_columnas_igual(): void
    {
        $respuesta = $this->consultar('SELECT id, name FROM clients WHERE id = -1');

        $respuesta->assertStatus(200);
        $this->assertSame(['id', 'name'], $respuesta->json('columns'));
        $this->assertSame([], $respuesta->json('rows'));
        $this->assertSame(0, $respuesta->json('row_count'));
    }

    public function test_show_tables_show_columns_describe_y_explain_funcionan(): void
    {
        $tablas = $this->consultar('SHOW TABLES')->assertStatus(200);
        $this->assertGreaterThan(50, $tablas->json('row_count'));
        $this->assertStringContainsString('"clients"', $tablas->getContent());

        $columnas = $this->consultar('SHOW COLUMNS FROM versions')->assertStatus(200);
        $this->assertContains('version', array_column($columnas->json('rows'), 'Field'));

        $describe = $this->consultar('DESCRIBE client_ecommerces')->assertStatus(200);
        $this->assertContains('ecommerce_version_id', array_column($describe->json('rows'), 'Field'));

        $this->consultar('EXPLAIN SELECT id FROM clients WHERE id = ' . $this->client_id)->assertStatus(200);
    }

    public function test_corta_en_200_filas_y_lo_declara(): void
    {
        /* 155 tablas x 155 tablas no hace falta: las migraciones corridas son varios cientos. */
        $total = (int) DB::table('migrations')->count();
        $this->assertGreaterThan(200, $total, 'La fixture natural de este test (la tabla migrations) ya no tiene más de 200 filas.');

        $respuesta = $this->consultar('SELECT id, migration FROM migrations ORDER BY id');

        $respuesta->assertStatus(200);
        $this->assertSame(200, $respuesta->json('row_count'));
        $this->assertCount(200, $respuesta->json('rows'));
        $this->assertTrue($respuesta->json('truncated'));

        $this->assertFalse($this->consultar('SELECT id FROM migrations ORDER BY id LIMIT 200')->json('truncated'));
    }

    public function test_corta_los_textos_largos_a_4000_caracteres(): void
    {
        $respuesta = $this->consultar('SELECT CONCAT(\'' . str_repeat('ñ', 2500) . '\', \'' . str_repeat('a', 2500) . '\') AS largo');

        $respuesta->assertStatus(200);

        $valor = $respuesta->json('rows.0.largo');

        $this->assertStringEndsWith(' [recortado]', $valor);
        $this->assertSame(4000 + mb_strlen(' [recortado]'), mb_strlen($valor, 'UTF-8'));
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Los rechazos
     |----------------------------------------------------------------------------------------- */

    public function test_cada_barrera_rechaza_con_422_error_y_codigo(): void
    {
        $casos = [
            'varias_sentencias'      => 'SELECT 1; SELECT 2',
            'comillas_dobles'        => 'SELECT id FROM clients WHERE name = "x"',
            'campo_sensible'         => 'SELECT api_key FROM clients',
            'tabla_vedada'           => 'SELECT * FROM admin_settings',
            'operacion_no_permitida' => 'SELECT 1 UNION SELECT 2',
            'solo_select'            => 'SHOW PROCESSLIST',
            'otra_base'              => 'SELECT id FROM otra.clients',
            'comodin_en_parentesis'  => 'SELECT id FROM clients WHERE EXISTS (SELECT * FROM versions)',
            'funcion_no_permitida'   => 'SELECT USER()',
        ];

        foreach ($casos as $codigo => $sql) {
            $respuesta = $this->consultar($sql);

            $respuesta->assertStatus(422);
            $this->assertSame($codigo, $respuesta->json('codigo'), $sql);
            $this->assertNotSame('', trim((string) $respuesta->json('error')), $sql);
            $this->assertArrayNotHasKey('rows', $respuesta->json());
        }
    }

    public function test_sin_sql_o_con_un_sql_que_no_es_texto_devuelve_422(): void
    {
        foreach ([[], ['sql' => null], ['sql' => ['SELECT 1']], ['sql' => 5], ['sql' => ''], ['sql' => '   ']] as $cuerpo) {
            $respuesta = $this->postJson('/api/claude/support-codex/admin-sql', $cuerpo, $this->headers());

            $respuesta->assertStatus(422);
            $this->assertSame('consulta_invalida', $respuesta->json('codigo'), json_encode($cuerpo));
        }

        $this->consultar('SELECT 1 FROM clients WHERE name = \'' . str_repeat('a', 12000) . '\'')
            ->assertStatus(422)
            ->assertJsonPath('codigo', 'consulta_invalida');
    }

    public function test_un_update_un_delete_y_un_insert_no_pasan_y_no_cambian_nada(): void
    {
        foreach ([
            'UPDATE clients SET name = \'pisado\' WHERE id = ' . $this->client_id,
            'DELETE FROM clients WHERE id = ' . $this->client_id,
            'INSERT INTO versions (version, status) VALUES (\'0.0.0\', \'draft\')',
            'REPLACE INTO versions (version) VALUES (\'0.0.0\')',
            'TRUNCATE TABLE clients',
            'DROP TABLE clients',
        ] as $sql) {
            $this->consultar($sql)->assertStatus(422)->assertJsonPath('codigo', 'operacion_no_permitida');
        }

        $this->assertSame('Fixture de admin-sql', DB::table('clients')->where('id', $this->client_id)->value('name'));
        $this->assertSame(0, DB::table('versions')->where('version', '0.0.0')->count());
    }

    public function test_si_mysql_rechaza_la_consulta_devuelve_consulta_fallida_sin_el_mensaje_del_driver(): void
    {
        $tabla = $this->consultar('SELECT id FROM tabla_que_no_existe_zzz');

        $tabla->assertStatus(422);
        $this->assertSame('consulta_fallida', $tabla->json('codigo'));
        $this->assertSame(1146, $tabla->json('codigo_mysql'));
        $this->assertSame('42S02', $tabla->json('sqlstate'));
        /* Ni el mensaje crudo de MySQL ni el nombre de la base. */
        $this->assertStringNotContainsString('tabla_que_no_existe_zzz', $tabla->getContent());
        $this->assertStringNotContainsString((string) DB::getDatabaseName(), $tabla->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $tabla->getContent());

        $columna = $this->consultar('SELECT columna_inventada_zzz FROM clients');
        $columna->assertStatus(422);
        $this->assertSame('consulta_fallida', $columna->json('codigo'));
        $this->assertSame(1054, $columna->json('codigo_mysql'));

        $sintaxis = $this->consultar('SELECT FROM WHERE');
        $sintaxis->assertStatus(422);
        $this->assertSame('consulta_fallida', $sintaxis->json('codigo'));
        $this->assertSame(1064, $sintaxis->json('codigo_mysql'));
    }

    /* ------------------------------------------------------------------------------------------
     | 4. La redacción
     |----------------------------------------------------------------------------------------- */

    public function test_select_asterisco_de_clients_no_devuelve_ninguna_clave(): void
    {
        foreach ([
            'SELECT * FROM clients WHERE id = ' . $this->client_id,
            'SELECT c.* FROM clients c WHERE c.id = ' . $this->client_id,
            'SELECT c.*, v.version FROM clients c LEFT JOIN versions v ON v.id = c.current_version_id WHERE c.id = ' . $this->client_id,
        ] as $sql) {
            $respuesta = $this->consultar($sql);

            $respuesta->assertStatus(200);
            $this->assertSame(1, $respuesta->json('row_count'), $sql);

            $fila = $respuesta->json('rows.0');

            $this->assertSame('[dato reservado]', $fila['api_key'], $sql);
            $this->assertSame('[dato reservado]', $fila['inbound_api_key'], $sql);
            $this->assertSame('Fixture de admin-sql', $fila['name'], $sql);
            $this->assertSame($this->slug, $fila['slug'], $sql);
            $this->assertContains('api_key', $respuesta->json('columns'), $sql);

            /* 🔴 Lo que importa: ningún canario en ninguna parte del cuerpo. */
            $cuerpo = $respuesta->getContent();
            $this->assertStringNotContainsString(self::API_KEY, $cuerpo, $sql);
            $this->assertStringNotContainsString(self::INBOUND_API_KEY, $cuerpo, $sql);
            $this->assertStringNotContainsString('CANARIO', $cuerpo, $sql);
        }
    }

    public function test_la_redaccion_es_recursiva_dentro_de_un_valor_json(): void
    {
        $respuesta = $this->consultar('SELECT contract_financiacion FROM clients WHERE id = ' . $this->client_id);

        $respuesta->assertStatus(200);

        $datos = json_decode($respuesta->json('rows.0.contract_financiacion'), true);

        $this->assertSame(3, $datos['cuotas']);
        /* Clave que matchea el patrón sensible, en la raíz y anidada. */
        $this->assertSame('[dato reservado]', $datos['db_password']);
        $this->assertSame('[dato reservado]', $datos['anidado']['api_key']);
        /* 🔴 Clave RESERVADA anidada (`request`), y otra dentro de una lista y con mayúscula (`Clave`). */
        $this->assertSame('[dato reservado]', $datos['anidado']['request']);
        $this->assertSame('[dato reservado]', $datos['anidado']['lista'][0]['Clave']);
        $this->assertSame('sí', $datos['anidado']['lista'][0]['visible']);
        $this->assertSame('Gualeguay', $datos['anidado']['ciudad']);
        $this->assertStringNotContainsString('CANARIO', $respuesta->getContent());
    }

    public function test_el_soap_de_arca_con_el_ticket_de_acceso_no_sale_por_ningun_camino(): void
    {
        /* 1. No se puede nombrar: ni para leerla ni para filtrar por ella. */
        foreach ([
            'SELECT request, response FROM mensualidad_invoices',
            'SELECT response FROM mensualidad_invoices WHERE client_id = ' . $this->client_id,
            'SELECT id FROM mensualidad_invoices WHERE request LIKE \'%CANARIO%\'',
            'SELECT LENGTH(request) FROM mensualidad_invoices',
            'SELECT SUBSTRING(`request`, 1, 200) AS x FROM mensualidad_invoices',
        ] as $sql) {
            $this->consultar($sql)->assertStatus(422)->assertJsonPath('codigo', 'campo_sensible');
        }

        /* 2. Con `SELECT *` la fila sale, y las dos columnas van reservadas enteras. */
        foreach ([
            'SELECT * FROM mensualidad_invoices WHERE client_id = ' . $this->client_id,
            'SELECT i.*, c.name FROM mensualidad_invoices i JOIN clients c ON c.id = i.client_id WHERE c.id = ' . $this->client_id,
        ] as $sql) {
            $respuesta = $this->consultar($sql);

            $respuesta->assertStatus(200);
            $this->assertSame(1, $respuesta->json('row_count'), $sql);
            $this->assertSame('[dato reservado]', $respuesta->json('rows.0.request'), $sql);
            $this->assertSame('[dato reservado]', $respuesta->json('rows.0.response'), $sql);
            $this->assertSame('2026-11', $respuesta->json('rows.0.periodo'), $sql);
            $this->assertSame('Observación 10016 de ARCA', $respuesta->json('rows.0.error_message'), $sql);
            $this->assertStringNotContainsString('CANARIO', $respuesta->getContent(), $sql);
        }

        /* 3. Lo que sí se puede leer de una factura sigue saliendo. */
        $this->consultar('SELECT id, periodo, resultado, cae, error_message FROM mensualidad_invoices WHERE client_id = ' . $this->client_id)
            ->assertStatus(200)
            ->assertJsonPath('rows.0.resultado', 'A');
    }

    public function test_el_redactor_tapa_las_etiquetas_xml_de_credenciales_en_cualquier_texto(): void
    {
        $servicio = app(ClaudeAdminSqlService::class);

        /* El mismo SOAP, guardado en una columna de nombre inocente: la segunda reja. */
        $fila = $servicio->redactar_fila([
            'nota'     => self::SOAP_REQUEST,
            'escapado' => 'Falló: &lt;Auth&gt;&lt;Token&gt;CANARIO-ESCAPADO&lt;/Token&gt;&lt;Sign&gt;CANARIO-SIGN-2&lt;/Sign&gt;&lt;/Auth&gt; y siguió',
            'variado'  => "<TOKEN>CANARIO-MAYUS</TOKEN> <password attr=\"x\">CANARIO-PASS</password>\n<Clave>\nCANARIO-MULTILINEA\n</Clave> <ApiKey>CANARIO-PATRON</ApiKey> <Resultado>A</Resultado>",
            'en_json'  => json_encode(['xml' => '<Sign>CANARIO-EN-JSON</Sign>', 'ok' => '<b>negrita</b>']),
        ]);

        foreach ($fila as $columna => $valor) {
            $this->assertStringNotContainsString('CANARIO', (string) $valor, $columna);
        }

        $this->assertStringContainsString('<ar:Token>[dato reservado]</ar:Token>', $fila['nota']);
        $this->assertStringContainsString('<ar:Sign>[dato reservado]</ar:Sign>', $fila['nota']);
        /* Lo que no es credencial queda como estaba. */
        $this->assertStringContainsString('<ar:Cuit>20111111112</ar:Cuit>', $fila['nota']);
        $this->assertStringContainsString('&lt;Token&gt;[dato reservado]&lt;/Token&gt;', $fila['escapado']);
        $this->assertStringContainsString('y siguió', $fila['escapado']);
        $this->assertStringContainsString('<Resultado>A</Resultado>', $fila['variado']);
        $this->assertStringContainsString('<password attr="x">[dato reservado]</password>', $fila['variado']);
        $this->assertStringContainsString('negrita', $fila['en_json']);
    }

    public function test_en_un_texto_libre_se_tapa_lo_que_sigue_a_contrasena(): void
    {
        $respuesta = $this->consultar('SELECT cobranzas_observaciones FROM clients WHERE id = ' . $this->client_id);

        $respuesta->assertStatus(200);
        $this->assertSame(
            'Le pasamos el acceso: la contraseña es [oculta] y paga los 10.',
            $respuesta->json('rows.0.cobranzas_observaciones')
        );
    }

    public function test_el_redactor_tapa_bearer_y_pares_clave_valor_de_un_log(): void
    {
        $servicio = app(ClaudeAdminSqlService::class);

        $fila = $servicio->redactar_fila([
            'texto'   => 'curl -H "Authorization: Bearer abc.DEF-123" y DB_PASSWORD=supersecreta MAIL_HOST=smtp.test',
            'otra'    => 'APP_KEY no, pero META_ACCESS_TOKEN: EAAG123 sí',
            'numero'  => 15,
            'nulo'    => null,
            'token'   => 'se-va-entero',
            'detalle' => ['secret' => 'x', 'visible' => 'y'],
            /* Columnas reservadas: se van enteras, sin mirar el valor y sin distinguir mayúsculas. */
            'line'    => 'php artisan migrate',
            'RAW'     => ['a' => 1],
            'datos'   => '{"ip":"1.2.3.4"}',
        ]);

        $this->assertSame('[dato reservado]', $fila['line']);
        $this->assertSame('[dato reservado]', $fila['RAW']);
        $this->assertSame('[dato reservado]', $fila['datos']);

        $this->assertStringNotContainsString('abc.DEF-123', $fila['texto']);
        $this->assertStringNotContainsString('supersecreta', $fila['texto']);
        $this->assertStringContainsString('MAIL_HOST=smtp.test', $fila['texto']);
        $this->assertStringNotContainsString('EAAG123', $fila['otra']);
        $this->assertSame(15, $fila['numero']);
        $this->assertNull($fila['nulo']);
        $this->assertSame('[dato reservado]', $fila['token']);
        $this->assertSame(['secret' => '[dato reservado]', 'visible' => 'y'], $fila['detalle']);
    }

    /* ------------------------------------------------------------------------------------------
     | 5. La transacción de solo lectura
     |----------------------------------------------------------------------------------------- */

    public function test_la_transaccion_es_de_solo_lectura_aunque_el_validador_no_estuviera(): void
    {
        $servicio = app(ClaudeAdminSqlService::class);

        /* 🔴 Directo al ejecutor, sin validador: la reja acá es MySQL. */
        foreach ([
            'UPDATE clients SET name = \'pisado\' WHERE id = ' . $this->client_id,
            'DELETE FROM clients WHERE id = ' . $this->client_id,
            'INSERT INTO versions (uuid, version, status) VALUES (\'' . Str::uuid() . '\', \'0.0.0\', \'draft\')',
        ] as $sql) {
            $resultado = $servicio->ejecutar_en_solo_lectura($sql);

            $this->assertFalse($resultado['ok'], $sql);
            $this->assertSame('consulta_fallida', $resultado['codigo'], $sql);
            $this->assertSame(1792, $resultado['codigo_mysql'], 'MySQL tendría que rechazar con 1792 (READ ONLY transaction): ' . $sql);
        }

        $this->assertSame('Fixture de admin-sql', DB::table('clients')->where('id', $this->client_id)->value('name'));
        $this->assertSame(0, DB::table('versions')->where('version', '0.0.0')->count());
    }

    public function test_la_sesion_de_la_consulta_tiene_solo_lectura_y_tope_de_tiempo(): void
    {
        $resultado = app(ClaudeAdminSqlService::class)->ejecutar_en_solo_lectura(
            'SELECT @@SESSION.transaction_read_only AS solo_lectura, @@SESSION.max_execution_time AS tope'
        );

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, (int) $resultado['rows'][0]['solo_lectura']);
        $this->assertSame(8000, (int) $resultado['rows'][0]['tope']);
    }

    public function test_la_conexion_de_la_aplicacion_no_queda_en_solo_lectura_ni_con_la_conexion_extra_abierta(): void
    {
        $this->consultar('SELECT id FROM clients WHERE id = ' . $this->client_id)->assertStatus(200);
        $this->consultar('SELECT id FROM tabla_que_no_existe_zzz')->assertStatus(422);

        $sesion = DB::selectOne('SELECT @@SESSION.transaction_read_only AS solo_lectura, @@SESSION.max_execution_time AS tope');

        $this->assertSame(0, (int) $sesion->solo_lectura);
        $this->assertSame(0, (int) $sesion->tope);

        /* Y se puede seguir escribiendo por la conexión de siempre. */
        DB::table('clients')->where('id', $this->client_id)->update(['company_name' => 'Fixture SRL (editada)']);
        $this->assertSame('Fixture SRL (editada)', DB::table('clients')->where('id', $this->client_id)->value('company_name'));

        /* La conexión descartable se purgó: no queda colgada en el manager. */
        $this->assertArrayNotHasKey(ClaudeAdminSqlService::CONEXION, DB::getConnections());
    }

    /* ------------------------------------------------------------------------------------------
     | 6. Los tests de build contra el esquema real
     |----------------------------------------------------------------------------------------- */

    public function test_toda_tabla_con_columna_value_payload_o_values_esta_vedada_o_revisada(): void
    {
        $vedadas   = array_keys((array) config('claude_admin_sql.tablas_vedadas'));
        $revisadas = array_keys((array) config('claude_admin_sql.tablas_revisadas'));

        $filas = DB::select(
            'SELECT table_name AS tabla, column_name AS columna FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND (column_name IN (\'value\', \'payload\') OR column_name LIKE \'%\\_values\') '
            . 'ORDER BY table_name, column_name'
        );

        $this->assertNotEmpty($filas, 'No se encontró ninguna tabla con value/payload/*_values: la consulta al esquema quedó mal.');

        $sin_decidir = [];
        foreach ($filas as $fila) {
            if (! in_array($fila->tabla, $vedadas, true) && ! in_array($fila->tabla, $revisadas, true)) {
                $sin_decidir[] = $fila->tabla . '.' . $fila->columna;
            }
        }

        $this->assertSame(
            [],
            $sin_decidir,
            'Hay tablas con una columna value/payload/*_values que no están ni en tablas_vedadas ni en tablas_revisadas de '
            . 'config/claude_admin_sql.php. Mirá qué guardan y agregalas a una de las dos, con el motivo: ' . implode(', ', $sin_decidir)
        );

        /* Una revisada tiene que existir: si la tabla se borró, la entrada quedó vieja. */
        $tablas = $this->tablas_del_esquema();
        foreach ($revisadas as $tabla) {
            $this->assertContains($tabla, $tablas, 'tablas_revisadas nombra una tabla que ya no existe: ' . $tabla);
        }
    }

    public function test_toda_columna_de_texto_libre_o_json_de_una_tabla_no_vedada_esta_decidida(): void
    {
        /* 🔴 FAIL-CLOSED. Un texto libre o un JSON pueden traer cualquier cosa (el SOAP de ARCA
           con su ticket de acceso estaba en una columna `request`). Toda columna así, de una tabla
           que se puede consultar, tiene que estar decidida a mano: o no se sirve (patrón sensible
           o `columnas_reservadas`), o alguien miró qué guarda y lo anotó en
           `columnas_de_texto_revisadas`. Una migración que agregue una y no lo decida rompe acá. */
        $vedadas    = array_keys((array) config('claude_admin_sql.tablas_vedadas'));
        $reservadas = array_map('mb_strtolower', array_keys((array) config('claude_admin_sql.columnas_reservadas')));
        $revisadas  = (array) config('claude_admin_sql.columnas_de_texto_revisadas');
        $patron     = '~' . config('claude_admin_sql.patron_sensible') . '~i';

        $filas = DB::select(
            'SELECT table_name AS tabla, column_name AS columna, data_type AS tipo FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() '
            . 'AND (data_type IN (\'json\', \'text\', \'tinytext\', \'mediumtext\', \'longtext\') OR data_type LIKE \'%blob\') '
            . 'ORDER BY table_name, column_name'
        );

        $this->assertGreaterThan(100, count($filas), 'La consulta al esquema devolvió muy pocas columnas de texto: quedó mal.');

        $sin_decidir = [];
        $vistas      = [];
        $por_camino  = ['patron' => 0, 'reservada' => 0, 'revisada' => 0];

        foreach ($filas as $fila) {
            if (in_array($fila->tabla, $vedadas, true)) {
                continue;
            }

            $clave    = $fila->tabla . '.' . $fila->columna;
            $vistas[] = $clave;

            if (preg_match($patron, $fila->columna)) {
                $por_camino['patron']++;
                continue;
            }

            if (in_array(mb_strtolower($fila->columna), $reservadas, true)) {
                $por_camino['reservada']++;
                continue;
            }

            if (isset($revisadas[$clave])) {
                $por_camino['revisada']++;
                continue;
            }

            $sin_decidir[] = $clave . ' (' . $fila->tipo . ')';
        }

        $this->assertSame(
            [],
            $sin_decidir,
            'Hay columnas de texto libre o JSON sin decidir en config/claude_admin_sql.php. Mirá qué escribe el código en cada una: si '
            . 'puede traer credenciales, tokens, cuerpos crudos de terceros, valores de .env o datos de formato libre, agregá su nombre a '
            . '`columnas_reservadas`; si es legible, agregá `tabla.columna` a `columnas_de_texto_revisadas` con el motivo. En la duda, '
            . 'reservala: ' . implode(', ', $sin_decidir)
        );

        /* Que midió por los tres caminos. */
        $this->assertGreaterThan(0, $por_camino['patron']);
        $this->assertGreaterThan(10, $por_camino['reservada']);
        $this->assertGreaterThan(100, $por_camino['revisada']);

        /* Y que el mapa de revisadas no quedó viejo ni se contradice: cada entrada es una columna
           de texto que existe, de una tabla no vedada, que NO está reservada, y dice por qué. */
        foreach ($revisadas as $clave => $motivo) {
            $this->assertContains($clave, $vistas, 'columnas_de_texto_revisadas nombra algo que no es una columna de texto de una tabla consultable: ' . $clave);
            $this->assertGreaterThan(20, strlen(trim((string) $motivo)), 'La columna revisada "' . $clave . '" no dice por qué es legible.');

            $columna = substr($clave, strpos($clave, '.') + 1);
            $this->assertNotContains(mb_strtolower($columna), $reservadas, 'La columna "' . $clave . '" figura como legible, pero su nombre está reservado: nunca se serviría.');
            $this->assertSame(0, preg_match($patron, $columna), 'La columna "' . $clave . '" figura como legible, pero su nombre matchea el patrón sensible.');
        }
    }

    public function test_en_toda_tabla_no_vedada_las_columnas_de_nombre_sensible_salen_redactadas(): void
    {
        $vedadas    = array_keys((array) config('claude_admin_sql.tablas_vedadas'));
        $reservadas = array_keys((array) config('claude_admin_sql.columnas_reservadas'));
        $nombre     = '~' . config('claude_admin_sql.patron_sensible') . '~i';
        /* «Sensible» = matchea el patrón O es una columna reservada. */
        $patron     = '~' . config('claude_admin_sql.patron_sensible') . '|^(?:' . implode('|', array_map('preg_quote', $reservadas)) . ')$~iu';
        $servicio   = app(ClaudeAdminSqlService::class);

        $tablas_consultadas  = 0;
        $columnas_sensibles  = 0;
        $rechazadas_por_nombre = 0;

        foreach ($this->tablas_del_esquema() as $tabla) {
            if (in_array($tabla, $vedadas, true)) {
                /* Vedada: ni siquiera se puede preguntar por ella. */
                $this->assertFalse($servicio->consultar('SELECT * FROM `' . $tabla . '` LIMIT 1')['ok'], $tabla);
                continue;
            }

            $columnas = $this->columnas_de($tabla);
            $this->assertNotEmpty($columnas, $tabla);

            /* (a) Una fila de mentira con un canario en CADA columna pasa por el mismo redactor que
               usa el endpoint: las de nombre sensible salen con la máscara, las demás intactas. */
            $fila = [];
            foreach ($columnas as $columna) {
                $fila[$columna] = 'canario-' . $columna;
            }

            $redactada = $servicio->redactar_fila($fila);

            foreach ($columnas as $columna) {
                if (preg_match($patron, $columna)) {
                    $columnas_sensibles++;
                    $this->assertSame('[dato reservado]', $redactada[$columna], 'La columna ' . $tabla . '.' . $columna . ' matchea el patrón sensible y NO salió redactada.');
                } else {
                    $this->assertSame('canario-' . $columna, $redactada[$columna], 'La columna ' . $tabla . '.' . $columna . ' no es sensible y salió alterada.');
                }
            }

            /* (b) Y el `SELECT *` de verdad: o se rechaza (el nombre de la tabla ya es sensible), o
               devuelve las columnas con su nombre REAL —que es lo que hace valer (a)— y ningún
               valor de una columna sensible sin la máscara. */
            $resultado = $servicio->consultar('SELECT * FROM `' . $tabla . '` LIMIT 1');

            if (! $resultado['ok']) {
                $this->assertSame('campo_sensible', $resultado['codigo'], 'El SELECT * de ' . $tabla . ' falló por algo que no es su nombre: ' . $resultado['codigo']);
                $this->assertSame(1, preg_match($nombre, $tabla), $tabla);
                $rechazadas_por_nombre++;
                continue;
            }

            $tablas_consultadas++;
            $this->assertSame($columnas, $resultado['columns'], 'El SELECT * de ' . $tabla . ' no devolvió las columnas con su nombre real.');

            foreach ($resultado['rows'] as $fila_real) {
                foreach ($fila_real as $columna => $valor) {
                    if (preg_match($patron, (string) $columna)) {
                        $this->assertSame('[dato reservado]', $valor, $tabla . '.' . $columna);
                    }
                }
            }
        }

        /* Que el test midió algo: hay tablas consultadas, columnas sensibles vistas (clients.api_key
           como mínimo) y al menos una tabla rechazada por su nombre (client_ai_token_usages). */
        $this->assertGreaterThan(100, $tablas_consultadas);
        $this->assertGreaterThan(10, $columnas_sensibles);
        $this->assertGreaterThan(0, $rechazadas_por_nombre);
    }
}

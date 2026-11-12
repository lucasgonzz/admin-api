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

        /* 🔴 El endpoint nace APAGADO (`CLAUDE_ADMIN_SQL_HABILITADO`). Estos tests lo ejercitan, así
           que lo prenden explícitamente; los que prueban el interruptor lo vuelven a apagar. */
        config(['claude_admin_sql.habilitado' => true]);

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
                'cuotas'         => 3,
                'db_password'    => 'CANARIO-db-pass-2',
                'admin_password' => 'CANARIO con espacios adentro',
                'user_setup'     => ['token' => 'CANARIO-token-setup'],
                'employees'      => [['name' => 'Ana', 'password' => 'CANARIO-empleado']],
                'anidado'     => [
                    'api_key' => 'CANARIO-anidado-2',
                    'ciudad'  => 'Gualeguay',
                    'request' => 'CANARIO-reservada-anidada',
                    'lista'   => [['Clave' => 'CANARIO-clave-json', 'visible' => 'sí']],
                ],
            ]),
            'cobranzas_observaciones' => "Contraseña: CANARIO-al-principio\nLe pasamos el acceso: la contraseña es CANARIO-clave-1234 y paga los 10.\nEl password del wifi: CANARIO-wifi\nla contraseña del router es CANARIO-router\nTercera línea legible.",
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
     * Corre `$callback` con una variable de entorno puesta (o quitada, con null) y la restaura.
     *
     * @param string      $nombre   Variable.
     * @param string|null $valor    Valor, o null para que no exista.
     * @param callable    $callback Lo que se corre.
     *
     * @return void
     */
    private function con_la_variable(string $nombre, $valor, callable $callback): void
    {
        $antes = [
            'env'    => array_key_exists($nombre, $_ENV) ? $_ENV[$nombre] : null,
            'server' => array_key_exists($nombre, $_SERVER) ? $_SERVER[$nombre] : null,
            'getenv' => getenv($nombre),
        ];

        if ($valor === null) {
            unset($_ENV[$nombre], $_SERVER[$nombre]);
            putenv($nombre);
        } else {
            $_ENV[$nombre]    = $valor;
            $_SERVER[$nombre] = $valor;
            putenv($nombre . '=' . $valor);
        }

        try {
            $callback();
        } finally {
            unset($_ENV[$nombre], $_SERVER[$nombre]);
            putenv($nombre);

            if ($antes['env'] !== null) {
                $_ENV[$nombre] = $antes['env'];
            }
            if ($antes['server'] !== null) {
                $_SERVER[$nombre] = $antes['server'];
            }
            if ($antes['getenv'] !== false) {
                putenv($nombre . '=' . $antes['getenv']);
            }
        }
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
        DB::table('clients')->where('id', $this->client_id)->update([
            'ai_plan_sync_message' => str_repeat('ñ', 2500) . str_repeat('a', 2500),
        ]);

        $respuesta = $this->consultar('SELECT ai_plan_sync_message AS largo FROM clients WHERE id = ' . $this->client_id);

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
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

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

        /* `Auth` también es una etiqueta de credenciales: se va el bloque entero, con su Token y su Sign. */
        $this->assertStringContainsString('<ar:Auth>[dato reservado]</ar:Auth>', $fila['nota']);
        /* Lo que no es credencial queda como estaba. */
        $this->assertStringContainsString('</ar:FECAESolicitar></soapenv:Body></soapenv:Envelope>', $fila['nota']);
        /* El XML escapado se tapa dos veces: la etiqueta, y además el resto de la línea después de
           «Token» (la regla de etiquetas de clave solo perdona un nombre de etiqueta pegado a `<`). */
        $this->assertStringStartsWith('Falló: &lt;Auth', $fila['escapado']);
        $this->assertStringContainsString('<Resultado>A</Resultado>', $fila['variado']);
        $this->assertStringContainsString('<password attr="x">[dato reservado]</password>', $fila['variado']);
        $this->assertStringContainsString('negrita', $fila['en_json']);
    }

    public function test_en_un_texto_libre_se_tapa_lo_que_sigue_a_contrasena(): void
    {
        $respuesta = $this->consultar('SELECT cobranzas_observaciones FROM clients WHERE id = ' . $this->client_id);

        $respuesta->assertStatus(200);
        $this->assertSame(
            "Contraseña: [oculta]\nLe pasamos el acceso: la contraseña [oculta]\nEl password [oculta]\nla contraseña [oculta]\nTercera línea legible.",
            $respuesta->json('rows.0.cobranzas_observaciones')
        );
        $this->assertStringNotContainsString('CANARIO', $respuesta->getContent());
    }

    public function test_el_redactor_tapa_el_resto_de_la_linea_despues_de_una_etiqueta_de_clave(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

        /* Texto => lo que tiene que quedar legible. En todos, el centinela no sale. */
        $casos = [
            /* Los dos que reprodujo la revisión. */
            'El password del wifi: CENTINELA'              => ['El password'],
            'la contraseña del router es 1234CENTINELA'    => ['la contraseña'],
            /* Etiquetas cortas y en inglés, sin distinguir mayúsculas ni la eñe. */
            'PASS: CENTINELA'                              => ['PASS'],
            'pwd=CENTINELA'                                => ['pwd'],
            'su PIN de la tarjeta is CENTINELA'            => ['su PIN'],
            'el token nuevo que le dimos ayer: CENTINELA'  => ['el token'],
            'Contrasena CENTINELA'                         => ['Contrasena'],
            'CONTRASEÑAS: CENTINELA y CENTINELA'           => ['CONTRASEÑAS'],
            'usuario: ana, clave: CENTINELA'               => ['usuario: ana, clave'],
            'passwd CENTINELA'                             => ['passwd'],
            /* La etiqueta al final de la línea: el valor está en la siguiente. */
            "Contraseña:\nCENTINELA\nGracias"              => ['Contraseña', 'Gracias'],
            /* Y lo de las otras líneas no se toca. */
            "Hola\nclave CENTINELA\nNos vemos el martes"   => ["Hola\nclave", "\nNos vemos el martes"],
            /* 🔴 La etiqueta no necesita empezar la palabra: un prefijo pegado no la esconde. */
            'xContraseña: CENTINELA'                       => ['xContraseña'],
            '123clave CENTINELA'                           => ['123clave'],
            'MI_PASSWORD es CENTINELA'                     => ['MI_PASSWORD'],
            /* `nombre: valor` con un nombre sensible, reservado o con una etiqueta adentro. */
            'api-key: CENTINELA'                           => ['api-key'],
            '"session_id": "CENTINELA"'                    => ['session_id'],
            'setup_data = CENTINELA'                       => ['setup_data'],
            'clave_fiscal: CENTINELA'                      => ['clave_fiscal'],
            'user_pin: CENTINELA y pin_code=CENTINELA'     => ['user_pin'],
            /* Un JSON cortado o pegado a otro texto ya no se puede redactar por clave. */
            'x{"admin_password": "CENTINELA con espacios", "rubro": "CENTINELA"' => ['admin_password'],
            'x{"credentials": {"a": "CENTINELA"}}'         => ['credentials'],
        ];

        foreach ($casos as $texto => $quedan) {
            $salida = $servicio->redactar($texto, 'body');

            $this->assertIsString($salida, $texto);
            $this->assertStringNotContainsString('CENTINELA', $salida, $texto);
            $this->assertStringNotContainsString('con espacios', $salida, $texto);
            $this->assertMatchesRegularExpression('/\[oculta\]|\[dato reservado\]/', $salida, $texto);

            foreach ($quedan as $trozo) {
                $this->assertStringContainsString($trozo, $salida, $texto);
            }
        }

        /* Una palabra que EMPIEZA con la etiqueta y sigue no dispara nada. */
        foreach (['Compás de espera', 'pinturería del centro', 'passport listo', 'tokenizar no', 'pasó a la tarde'] as $inocente) {
            $this->assertSame($inocente, $servicio->redactar($inocente, 'body'));
        }
    }

    public function test_las_claves_de_un_json_se_tapan_por_subcadena_de_etiqueta(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

        $json = json_encode([
            'pass'        => 'CENTINELA-1',
            'pwd'         => 'CENTINELA-2',
            'user_pin'    => 'CENTINELA-3',
            'clave_admin' => 'CENTINELA-4',
            'pin_code'    => 'CENTINELA-5',
            'api-key'     => 'CENTINELA-6',
            'Credentials' => ['x' => 'CENTINELA-7'],
            'contrasenia' => 'CENTINELA-8',
            'rubro'       => 'kiosco',
            'anidado'     => ['lista' => [['secretKey' => 'CENTINELA-9', 'ciudad' => 'Gualeguay']]],
        ]);

        $salida = $servicio->redactar($json, 'contract_financiacion');
        $datos  = json_decode($salida, true);

        $this->assertStringNotContainsString('CENTINELA', $salida);
        $this->assertSame('kiosco', $datos['rubro']);
        $this->assertSame('Gualeguay', $datos['anidado']['lista'][0]['ciudad']);

        foreach (['pass', 'pwd', 'user_pin', 'clave_admin', 'pin_code', 'api-key', 'Credentials', 'contrasenia'] as $clave) {
            $this->assertSame('[dato reservado]', $datos[$clave], $clave);
        }
    }

    public function test_el_redactor_falla_cerrado_con_un_texto_que_no_es_utf8(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

        /* 🔴 Reproducido: «CONTRASEÑA: valor» seguido de un byte 0xFF hacía fallar las expresiones
           regulares con /u (devuelven null) y el respaldo por bytes no reconocía la Ñ. Un texto que
           no es UTF-8 válido no se puede leer: no sale. */
        foreach ([
            "CONTRASEÑA: CENTINELA\xFF",
            "\xFFContraseña: CENTINELA",
            "password: CENTINELA \xC3\x28",
            "\xFF",
        ] as $roto) {
            $this->assertSame('[dato reservado]', $servicio->redactar($roto, 'body'), bin2hex($roto));
        }

        /* Y adentro de un arreglo, igual. */
        $this->assertSame(['a' => '[dato reservado]', 'b' => 'bien'], $servicio->redactar(['a' => "clave: CENTINELA\xFF", 'b' => 'bien'], 'x'));
    }

    public function test_ninguna_funcion_habilitada_devuelve_un_texto_ni_un_secreto(): void
    {
        /* 🔴 DEFENSA EN PROFUNDIDAD. La fixture tiene un cuerpo con «Contraseña: CANARIO…» y un JSON
           con `admin_password`, `user_setup.token` y `employees[0].password`. Se recorre TODA función
           habilitada con las formas simples de llamarla sobre esas dos columnas. Cada consulta puede
           salir bien, ser rechazada o fallar en MySQL. Lo que no puede:
             - devolver un canario;
             - devolver un TEXTO: una expresión solo sale si es un número, una fecha o NULL.
           Si alguien agrega a la lista una función que corta, extrae o transforma, este test lo
           denuncia. Va directo al servicio (validador + plan + origen + redactor, lo mismo que el
           endpoint): por HTTP el limitador del grupo api corta a los 60 pedidos. */
        $columnas = ['cobranzas_observaciones', 'contract_financiacion'];
        $formas   = [
            '{F}({C})', '{F}({C}, 1)', '{F}({C}, 14)', '{F}({C}, 1, 20)', '{F}({C}, {C})', '{F}(1, {C})', '{F}({C}, id)', '{F}(id, {C}, {C})',
            '{F}(DISTINCT {C})', '{F}({C}) OVER ()', '{F}({C} + 0)', '{F}({C}, INTERVAL 1 DAY)', '{F}({F}({C}))', '{F}(id > 0, {C}, {C})',
            '{F}({C} IS NULL, 1, {C})',
        ];

        $funciones = array_values(array_diff(\App\Services\ClaudeAdminSqlValidator::FUNCIONES, ['IN', 'EXISTS', 'NOT', 'OVER', 'AS']));
        $this->assertGreaterThan(30, count($funciones));

        $servicio  = app(ClaudeAdminSqlServiceParaTests::class);
        $corridas  = 0;
        $con_filas = 0;

        foreach ($funciones as $funcion) {
            foreach ($columnas as $columna) {
                foreach ($formas as $forma) {
                    $expresion = strtr($forma, ['{F}' => $funcion, '{C}' => $columna]);
                    $sql       = 'SELECT ' . $expresion . ' AS x FROM clients WHERE id = ' . $this->client_id;

                    $resultado = $servicio->consultar($sql);
                    $corridas++;

                    $cuerpo = (string) json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

                    $this->assertNotSame('', $cuerpo, $sql);
                    $this->assertFalse(stripos($cuerpo, 'CANARIO'), 'Devolvió un secreto: ' . $sql . ' → ' . $cuerpo);

                    if (! $resultado['ok']) {
                        continue;
                    }

                    foreach ($resultado['rows'] as $fila) {
                        foreach ($fila as $valor) {
                            $this->assertTrue(
                                $valor === null || is_int($valor) || is_float($valor) || $valor === '[dato reservado]'
                                    || (is_string($valor) && preg_match('/^-?\d+(\.\d+)?$|^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2}(\.\d+)?)?$|^-?\d+:\d{2}:\d{2}(\.\d+)?$/', $valor) === 1),
                                'Una expresión devolvió un texto: ' . $sql . ' → ' . $cuerpo
                            );
                        }
                    }

                    if ($resultado['row_count'] > 0) {
                        $con_filas++;
                    }
                }
            }
        }

        /* Que midió algo: muchas corrieron de verdad y devolvieron una fila. */
        $this->assertGreaterThan(900, $corridas);
        $this->assertGreaterThan(100, $con_filas);
    }

    public function test_las_consultas_que_extraian_secretos_de_un_json_o_de_un_cuerpo_ya_no_los_devuelven(): void
    {
        $id = $this->client_id;

        /* Las de las tres rondas de revisión, tal cual, contra la base de verdad: rechazadas. */
        $rechazadas = [
            'SELECT data->>\'$.admin_password\' AS x FROM implementation_stages',
            'SELECT JSON_EXTRACT(data, \'$.admin_password\') AS x FROM implementation_stages',
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(data, \'$.admin_password\')) AS x FROM implementation_stages',
            'SELECT data->>\'$.user_setup.token\' AS x FROM implementation_stages',
            'SELECT data->>\'$.employees[0].password\' AS x FROM implementation_stages',
            'SELECT JSON_EXTRACT(data, \'$.*\') AS x FROM implementation_stages',
            'SELECT GROUP_CONCAT(data->>\'$.admin_password\') AS x FROM implementation_stages',
            'SELECT CONCAT(\'x\', JSON_EXTRACT(data, \'$.admin_password\')) AS x FROM implementation_stages',
            'SELECT data FROM implementation_stages',
            'SELECT contract_financiacion->>\'$.admin_password\' AS x FROM clients WHERE id = ' . $id,
            'SELECT JSON_EXTRACT(contract_financiacion, \'$.admin_password\') AS x FROM clients WHERE id = ' . $id,
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(contract_financiacion, \'$.user_setup.token\')) AS x FROM clients WHERE id = ' . $id,
            'SELECT contract_financiacion->>\'$.employees[0].password\' AS x FROM clients WHERE id = ' . $id,
            'SELECT JSON_EXTRACT(contract_financiacion, \'$.*\') AS x FROM clients WHERE id = ' . $id,
            'SELECT SUBSTRING(contract_financiacion, 120, 40) AS x FROM clients WHERE id = ' . $id,
            'SELECT SUBSTRING(cobranzas_observaciones, 14) AS x FROM clients WHERE id = ' . $id,
            'SELECT RIGHT(cobranzas_observaciones, 20) AS x FROM clients WHERE id = ' . $id,
            'SELECT TRIM(LEADING \'Contraseña: \' FROM cobranzas_observaciones) AS x FROM clients WHERE id = ' . $id,
            'SELECT CAST(cobranzas_observaciones AS CHAR CHARACTER SET ascii) AS x FROM clients WHERE id = ' . $id,
            'SELECT CONVERT(cobranzas_observaciones USING ascii) AS x FROM clients WHERE id = ' . $id,
            /* Ronda 3. */
            "SELECT api_\u{212A}ey AS x FROM clients WHERE id = " . $id,
            "SELECT ap\u{0130}_key AS x FROM clients WHERE id = " . $id,
            "SELECT * FROM adm\u{0130}n_sett\u{0130}ngs",
            "SELECT table_name FROM \u{0130}NFORMAT\u{0130}ON_SCHEMA.tables",
            'SELECT CONCAT(BINARY UPPER(cobranzas_observaciones), 0xFF) AS x FROM clients WHERE id = ' . $id,
            'SELECT STR_TO_DATE(cobranzas_observaciones, \'El PIN es %f\') AS x FROM clients WHERE id = ' . $id,
            'SELECT DATE_FORMAT(STR_TO_DATE(cobranzas_observaciones, \'Contraseña: %f\'), \'%f\') AS x FROM clients WHERE id = ' . $id,
            'SELECT CONCAT(\'x\', contract_financiacion) AS x FROM clients WHERE id = ' . $id,
            'SELECT c.id FROM clients c, (SELECT id, slug FROM versions) t WHERE c.id = ' . $id,
            'SELECT id, (SELECT MAX(version) FROM versions) AS x FROM clients WHERE id = ' . $id,
            'SELECT name FROM (SELECT api_key AS name FROM clients) clients',
            'SELECT LOWER(cobranzas_observaciones) AS x FROM clients WHERE id = ' . $id,
            'SELECT GROUP_CONCAT(cobranzas_observaciones) AS x FROM clients WHERE id = ' . $id,
        ];

        foreach ($rechazadas as $sql) {
            $respuesta = $this->consultar($sql);

            $respuesta->assertStatus(422);
            $this->assertNotSame('consulta_fallida', $respuesta->json('codigo'), 'Tendría que rechazarla el validador, no MySQL: ' . $sql);
            $this->assertStringNotContainsString('CANARIO', $respuesta->getContent(), $sql);
        }

        /* Lo que sigue pudiéndose: las dos columnas ENTERAS, tapadas. */
        $respuesta = $this->consultar('SELECT cobranzas_observaciones, contract_financiacion FROM clients WHERE id = ' . $id);

        $respuesta->assertStatus(200);
        $this->assertStringNotContainsString('CANARIO', $respuesta->getContent());
        $this->assertStringContainsString('Tercera línea legible.', $respuesta->json('rows.0.cobranzas_observaciones'));
        $this->assertSame(3, json_decode($respuesta->json('rows.0.contract_financiacion'), true)['cuotas']);
    }

    /* ------------------------------------------------------------------------------------------
     | 4 bis. La reja por ORIGEN de cada columna, sin el validador
     |----------------------------------------------------------------------------------------- */

    public function test_la_reja_por_origen_tapa_una_columna_sensible_aunque_tenga_alias(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);
        $id       = $this->client_id;

        /* 🔴 Directo al servicio, SIN validador: lo que rechaza o tapa acá es lo que MySQL informa
           del origen de cada columna, no el texto de la consulta. */
        foreach ([
            'SELECT api_key AS x FROM clients WHERE id = ' . $id,
            'SELECT c.api_key x, c.inbound_api_key AS y FROM clients c WHERE c.id = ' . $id,
            /* Las letras que MySQL pliega: Kelvin (U+212A) → k, İ turca (U+0130) → i. */
            "SELECT api_\u{212A}ey AS x FROM clients WHERE id = " . $id,
            "SELECT ap\u{0130}_key AS x FROM clients WHERE id = " . $id,
            /* Columnas reservadas con alias. */
            'SELECT setup_data AS x FROM clients WHERE id = ' . $id,
            'SELECT request AS x, response AS y FROM mensualidad_invoices WHERE client_id = ' . $id,
        ] as $sql) {
            $resultado = $servicio->sin_validar($sql);
            $cuerpo    = (string) json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            $this->assertStringNotContainsString('CANARIO', $cuerpo, $sql);

            /* Si MySQL no pliega la letra en este servidor, la consulta falla por columna inexistente:
               también vale. Si corre, sale la máscara. */
            if ($resultado['ok']) {
                $this->assertSame(1, $resultado['row_count'], $sql);
                foreach ($resultado['rows'][0] as $valor) {
                    $this->assertSame('[dato reservado]', $valor, $sql);
                }
            } else {
                $this->assertSame('consulta_fallida', $resultado['codigo'], $sql);
            }
        }

        /* El alias común corre seguro: que no sea un test que solo ve fallos. */
        $this->assertTrue($servicio->sin_validar('SELECT api_key AS x FROM clients WHERE id = ' . $id)['ok']);
    }

    public function test_la_reja_por_origen_tapa_toda_expresion_de_texto(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);
        $id       = $this->client_id;

        /* Sin validador. Cada expresión devuelve texto (o bytes): sale la máscara, sea lo que sea. */
        $expresiones = [
            'CONCAT(\'x\', api_key)', 'UPPER(api_key)', 'LOWER(name)', 'CONCAT(name, slug)', 'MAX(name)', 'MIN(api_key)',
            'GROUP_CONCAT(api_key)', 'IF(id > 0, api_key, name)', 'COALESCE(api_key, name)', 'SUBSTRING(api_key, 2)',
            'JSON_EXTRACT(contract_financiacion, \'$.admin_password\')', 'contract_financiacion->>\'$.admin_password\'',
            'CONCAT(\'x\', contract_financiacion)', 'CONCAT(BINARY UPPER(cobranzas_observaciones), 0xFF)',
            'BINARY api_key', 'HEX(api_key)', 'TO_BASE64(api_key)', 'CAST(api_key AS CHAR)', 'REVERSE(api_key)',
            'DATE_FORMAT(NOW(), api_key)', '\'literal\'', 'RIGHT(cobranzas_observaciones, 20)', 'api_key COLLATE utf8mb4_bin',
            '(SELECT api_key FROM clients WHERE id = ' . $id . ')', 'CASE WHEN id > 0 THEN api_key ELSE name END',
        ];

        foreach ($expresiones as $expresion) {
            $sql       = 'SELECT ' . $expresion . ' AS x FROM clients WHERE id = ' . $id;
            $resultado = $servicio->sin_validar($sql);

            $this->assertTrue($resultado['ok'], 'Tendría que correr (sin validador) y salir tapada: ' . $sql . ' → ' . json_encode($resultado));
            $this->assertSame([['x' => '[dato reservado]']], $resultado['rows'], $sql);
        }

        /* 🔴 Hallazgo 3: STR_TO_DATE convierte en FECHA los dígitos que siguen a una etiqueta. Como
           expresión de tipo fecha saldría; por eso la función está fuera de la lista (el validador la
           rechaza). Acá se fija qué pasa si llegara al servicio: MySQL la informa como fecha u hora. */
        $this->assertSame('funcion_no_permitida', $servicio->consultar('SELECT STR_TO_DATE(name, \'%f\') AS x FROM clients')['codigo']);

        /* Y las expresiones numéricas y de fecha sí salen, como números y fechas. */
        $resultado = $servicio->sin_validar(
            'SELECT COUNT(*) AS n, MAX(id) AS maximo, LENGTH(name) AS largo, DATE(created_at) AS dia, YEAR(created_at) AS anio, '
            . 'NOW() AS ahora, NULL AS nada, 1.5 AS decimal_, id + 1 AS suma, id > 0 AS verdadero '
            . 'FROM clients WHERE id = ' . $id . ' GROUP BY id, name, created_at'
        );

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $fila = $resultado['rows'][0];
        $this->assertSame(1, $fila['n']);
        $this->assertSame($id, $fila['maximo']);
        $this->assertSame(mb_strlen('Fixture de admin-sql'), $fila['largo']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $fila['dia']);
        $this->assertSame((int) date('Y'), (int) $fila['anio']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fila['ahora']);
        $this->assertNull($fila['nada']);
        $this->assertSame($id + 1, $fila['suma']);
        $this->assertSame(1, $fila['verdadero']);
    }

    public function test_la_reja_por_origen_rechaza_una_tabla_vedada_y_otra_base(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

        /* Sin validador. `orgtable` dice de qué tabla viene cada columna, con alias o sin él. */
        foreach ([
            'SELECT * FROM admin_settings',
            'SELECT a.id FROM admin_settings a',
            'SELECT a.id AS x FROM admin_settings AS a LIMIT 1',
            "SELECT * FROM adm\u{0130}n_sett\u{0130}ngs",
            'SELECT c.id, e.id AS otro FROM clients c LEFT JOIN env_templates e ON e.id = c.id',
            'SELECT id FROM jobs',
            'SELECT body FROM implementation_messages',
        ] as $sql) {
            $resultado = $servicio->sin_validar($sql);

            $this->assertFalse($resultado['ok'], $sql);
            /* Con la letra plegada, si este servidor no la pliega la tabla «no existe»: también vale. */
            $this->assertContains($resultado['codigo'], strpos($sql, "\u{0130}") === false ? ['tabla_vedada'] : ['tabla_vedada', 'consulta_fallida'], $sql);
            $this->assertArrayNotHasKey('rows', $resultado, $sql);
        }

        $otra = $servicio->sin_validar('SELECT table_name FROM information_schema.tables LIMIT 1');
        $this->assertFalse($otra['ok']);
        $this->assertArrayNotHasKey('rows', $otra);
    }

    public function test_el_plan_rechaza_las_tablas_derivadas_y_el_union_aunque_el_validador_no_estuviera(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);
        $id       = $this->client_id;

        /* 🔴 EL LÍMITE DE LA REJA POR ORIGEN, MEDIDO: a través de una tabla derivada MySQL informa
           como origen de la columna el ALIAS que le puso la subconsulta. La última de estas dice
           venir de `clients.name`, que es una columna real y legible. Por eso el servicio mira además
           el PLAN (con `derived_merge=off`) y rechaza toda tabla derivada y todo UNION. */
        foreach ([
            'SELECT x FROM (SELECT api_key AS x FROM clients WHERE id = ' . $id . ') t',
            'SELECT x FROM (SELECT api_key AS x FROM clients LIMIT 5) t',
            'SELECT name FROM (SELECT api_key AS name FROM clients WHERE id = ' . $id . ') clients',
            'SELECT name FROM (SELECT DISTINCT api_key AS name FROM clients) clients',
            'SELECT c.id, t.slug FROM clients c, (SELECT id, api_key AS slug FROM clients) t WHERE t.id = c.id',
            'SELECT name FROM clients WHERE id = -1 UNION SELECT api_key FROM clients WHERE id = ' . $id,
            'SELECT name FROM clients WHERE id = -1 UNION ALL SELECT api_key FROM clients',
            'WITH t AS (SELECT api_key AS name FROM clients) SELECT name FROM t',
            'SELECT name FROM (TABLE clients) AS t (id, uuid, name)',
        ] as $sql) {
            $resultado = $servicio->sin_validar($sql);
            $cuerpo    = (string) json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            $this->assertFalse($resultado['ok'], 'El servicio tendría que rechazarla por su plan: ' . $sql . ' → ' . $cuerpo);
            $this->assertContains($resultado['codigo'], ['subconsulta_no_permitida', 'consulta_fallida'], $sql);
            $this->assertStringNotContainsString('CANARIO', $cuerpo, $sql);
        }

        $this->assertSame('subconsulta_no_permitida', $servicio->sin_validar('SELECT name FROM (SELECT api_key AS name FROM clients WHERE id = ' . $id . ') clients')['codigo']);
        $this->assertSame('subconsulta_no_permitida', $servicio->sin_validar('SELECT name FROM clients WHERE id = -1 UNION SELECT api_key FROM clients')['codigo']);

        /* Lo que sí se admite: IN y EXISTS. */
        foreach ([
            'SELECT id FROM clients WHERE id IN (SELECT client_id FROM mensualidad_invoices) AND id = ' . $id,
            'SELECT c.id FROM clients c WHERE EXISTS (SELECT 1 FROM mensualidad_invoices i WHERE i.client_id = c.id) AND c.id = ' . $id,
            'SELECT id FROM clients WHERE id NOT IN (SELECT client_id FROM client_ecommerces WHERE client_id IS NOT NULL) AND id = ' . $id,
        ] as $sql) {
            $resultado = $servicio->consultar($sql);

            $this->assertTrue($resultado['ok'], $sql . ' → ' . json_encode($resultado));
            $this->assertSame([['id' => $id]], $resultado['rows'], $sql);
        }
    }

    public function test_un_texto_solo_sale_como_columna_entera_y_la_coercion_a_numero_queda_como_residual(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);
        $id       = $this->client_id;

        /* ⚠️ RESIDUAL CONOCIDO, FIJADO POR ESTE TEST. Una expresión numérica sobre un texto devuelve
           su PREFIJO numérico (`'1234abc' + 0` → 1234), y el validador no sabe de qué tipo es una
           columna. Sobre «Contraseña: 1234» no sale nada (el texto no empieza con dígitos): da 0.
           Sobre un texto que ES solo un número, sale el número. Es la misma clase que preguntar con
           LIKE (inferencia), aceptada y documentada en el contrato; lo que guarda credenciales por
           diseño está reservado o vedado. */
        DB::table('clients')->where('id', $id)->update(['ai_plan_sync_message' => '4321 es el PIN']);

        $resultado = $servicio->consultar('SELECT cobranzas_observaciones + 0 AS a, ROUND(cobranzas_observaciones) AS b, ai_plan_sync_message + 0 AS c FROM clients WHERE id = ' . $id);

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertEquals(0, $resultado['rows'][0]['a']);
        $this->assertEquals(0, $resultado['rows'][0]['b']);
        $this->assertEquals(4321, $resultado['rows'][0]['c']);

        /* Y la columna entera, por el camino normal, sale tapada. */
        $entera = $servicio->consultar('SELECT ai_plan_sync_message FROM clients WHERE id = ' . $id);
        $this->assertStringStartsWith('4321 es el PIN', $entera['rows'][0]['ai_plan_sync_message']);
    }

    public function test_lo_legitimo_sigue_andando(): void
    {
        $id = $this->client_id;

        $this->consultar('SELECT id, name, slug FROM clients WHERE slug = \'' . $this->slug . '\' AND (is_active = 1 OR estado = \'prueba\')')
            ->assertStatus(200)
            ->assertJsonPath('rows.0.name', 'Fixture de admin-sql');

        $this->consultar('SELECT COUNT(*) AS total FROM clients')->assertStatus(200);
        $this->assertGreaterThan(0, $this->consultar('SELECT COUNT(*) AS total FROM clients')->json('rows.0.total'));

        $grupos = $this->consultar('SELECT estado, is_active, COUNT(*) AS n, MAX(created_at) AS ultimo FROM clients GROUP BY estado, is_active ORDER BY n DESC')->assertStatus(200);
        $this->assertSame(['estado', 'is_active', 'n', 'ultimo'], $grupos->json('columns'));
        $this->assertIsInt($grupos->json('rows.0.n'));
        $this->assertIsString($grupos->json('rows.0.estado'));

        $this->consultar(
            'SELECT c.id, c.name, v.version, u.status, u.finished_at FROM clients c '
            . 'LEFT JOIN versions v ON v.id = c.current_version_id '
            . 'LEFT JOIN client_version_upgrades u ON u.client_id = c.id AND u.to_version_id = v.id '
            . 'WHERE c.id = ' . $id . ' ORDER BY u.id DESC LIMIT 5'
        )->assertStatus(200)->assertJsonPath('rows.0.name', 'Fixture de admin-sql');

        $this->consultar('SELECT id, body, created_at FROM support_messages ORDER BY id DESC LIMIT 3')->assertStatus(200);
        $this->consultar('SELECT id, email FROM clients WHERE email = \'a@b.test\' OR name LIKE \'%ñandú%\'')->assertStatus(200);
        $this->consultar('SELECT YEAR(created_at) AS anio, MONTH(created_at) AS mes, COUNT(*) AS n FROM support_tickets GROUP BY anio, mes')->assertStatus(200);
        $this->consultar('SELECT id, DATEDIFF(NOW(), created_at) AS dias, DATE(created_at) AS dia FROM clients WHERE id = ' . $id)->assertStatus(200)->assertJsonPath('rows.0.dias', 0);
        $this->consultar('SHOW TABLES')->assertStatus(200);
        $this->consultar('DESCRIBE clients')->assertStatus(200);
        $this->consultar('SHOW COLUMNS FROM versions')->assertStatus(200);
        $this->consultar('EXPLAIN SELECT id FROM clients WHERE id = ' . $id)->assertStatus(200);
    }

    public function test_el_redactor_tapa_bearer_y_pares_clave_valor_de_un_log(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

        $fila = $servicio->redactar_fila([
            'texto'   => "MAIL_HOST=smtp.test\ncurl -H \"Authorization: Bearer abc.DEF-123\" y DB_PASSWORD=supersecreta",
            'otra'    => 'APP_KEY no, pero META_ACCESS_TOKEN: EAAG123 sí',
            'numero'  => 15,
            'nulo'    => null,
            'token'   => 'se-va-entero',
            'anidado' => ['secret' => 'x', 'visible' => 'y'],
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
        $this->assertSame(['secret' => '[dato reservado]', 'visible' => 'y'], $fila['anidado']);
    }

    /* ------------------------------------------------------------------------------------------
     | 5. La transacción de solo lectura
     |----------------------------------------------------------------------------------------- */

    public function test_la_transaccion_es_de_solo_lectura_aunque_el_validador_no_estuviera(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

        /* 🔴 Directo al ejecutor, sin validador y sin la reja del plan (se la saltea marcándola
           como consulta de esquema): la reja acá es MySQL. */
        foreach ([
            'UPDATE clients SET name = \'pisado\' WHERE id = ' . $this->client_id,
            'DELETE FROM clients WHERE id = ' . $this->client_id,
            'INSERT INTO versions (uuid, version, status) VALUES (\'' . Str::uuid() . '\', \'0.0.0\', \'draft\')',
        ] as $sql) {
            $resultado = $servicio->sin_validar($sql, true);

            $this->assertFalse($resultado['ok'], $sql);
            $this->assertSame('consulta_fallida', $resultado['codigo'], $sql);
            $this->assertSame(1792, $resultado['codigo_mysql'], 'MySQL tendría que rechazar con 1792 (READ ONLY transaction): ' . $sql);

            /* Y por el camino de un SELECT ni llega a MySQL: el plan de una escritura no se admite. */
            $this->assertFalse($servicio->sin_validar($sql)['ok'], $sql);
        }

        /* Varias sentencias: mysqli no las acepta en una sola llamada. */
        $this->assertFalse($servicio->sin_validar('SELECT 1; SELECT 2', true)['ok']);

        $this->assertSame('Fixture de admin-sql', DB::table('clients')->where('id', $this->client_id)->value('name'));
        $this->assertSame(0, DB::table('versions')->where('version', '0.0.0')->count());
    }

    public function test_la_sesion_de_la_consulta_tiene_solo_lectura_y_tope_de_tiempo(): void
    {
        $resultado = app(ClaudeAdminSqlServiceParaTests::class)->sin_validar(
            'SELECT @@SESSION.transaction_read_only AS solo_lectura, @@SESSION.max_execution_time AS tope, '
            . '@@SESSION.optimizer_switch LIKE \'%derived_merge=off%\' AS sin_fusion, @@SESSION.character_set_results AS juego',
            true
        );

        $this->assertTrue($resultado['ok'], json_encode($resultado));
        $this->assertSame(1, (int) $resultado['rows'][0]['solo_lectura']);
        $this->assertSame(8000, (int) $resultado['rows'][0]['tope']);
        $this->assertSame(1, (int) $resultado['rows'][0]['sin_fusion']);
        $this->assertSame('utf8mb4', $resultado['rows'][0]['juego']);
    }

    public function test_apagado_el_interruptor_contesta_503_sin_ejecutar_nada(): void
    {
        config(['claude_admin_sql.habilitado' => false]);

        foreach (['SELECT id FROM clients', 'UPDATE clients SET name = \'x\'', ''] as $sql) {
            $respuesta = $this->consultar($sql);

            $respuesta->assertStatus(503);
            $this->assertSame(['error' => 'La consulta SQL del admin está deshabilitada.', 'codigo' => 'deshabilitado'], $respuesta->json());
        }

        /* Sin la clave sigue siendo 401: el interruptor no abre nada. */
        $this->postJson('/api/claude/support-codex/admin-sql', ['sql' => 'SELECT 1'])->assertStatus(401);

        config(['claude_admin_sql.habilitado' => true]);

        $this->consultar('SELECT id FROM clients WHERE id = ' . $this->client_id)->assertStatus(200);
    }

    public function test_con_la_configuracion_por_defecto_el_endpoint_esta_deshabilitado(): void
    {
        /* 🔴 NACE APAGADO. Se relee el archivo de config tal como lo carga la aplicación, sin la
           variable en el entorno: lo que valga ahí es lo que vale en un servidor que no la definió. */
        $this->con_la_variable('CLAUDE_ADMIN_SQL_HABILITADO', null, function () {
            $por_defecto = require config_path('claude_admin_sql.php');

            $this->assertFalse($por_defecto['habilitado']);

            config(['claude_admin_sql.habilitado' => $por_defecto['habilitado']]);

            $respuesta = $this->consultar('SELECT id FROM clients WHERE id = ' . $this->client_id);

            $respuesta->assertStatus(503);
            $this->assertSame('deshabilitado', $respuesta->json('codigo'));
            $this->assertArrayNotHasKey('rows', $respuesta->json());
        });
    }

    public function test_el_interruptor_solo_se_prende_con_un_valor_afirmativo(): void
    {
        foreach (['off', 'no', '0', 'false', '', 'OFF', 'False', 'apagado', 'null', ' '] as $valor) {
            $this->con_la_variable('CLAUDE_ADMIN_SQL_HABILITADO', $valor, function () use ($valor) {
                $this->assertFalse((require config_path('claude_admin_sql.php'))['habilitado'], 'Tendría que apagar: [' . $valor . ']');
            });
        }

        foreach (['true', '1', 'on', 'yes', 'TRUE', 'On'] as $valor) {
            $this->con_la_variable('CLAUDE_ADMIN_SQL_HABILITADO', $valor, function () use ($valor) {
                $this->assertTrue((require config_path('claude_admin_sql.php'))['habilitado'], 'Tendría que prender: [' . $valor . ']');
            });
        }
    }

    public function test_la_conexion_usa_el_usuario_dedicado_si_esta_configurado(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);
        $defecto  = (array) config('database.connections.' . config('database.default'));

        /* Sin configurar nada: la conexión por defecto, tal cual. */
        config(['claude_admin_sql.conexion' => null, 'claude_admin_sql.db_username' => null, 'claude_admin_sql.db_password' => null]);
        $this->assertSame($defecto['username'], $servicio->parametros()['username']);
        $this->assertSame($defecto['database'], $servicio->parametros()['database']);

        /* Usuario y clave dedicados: reemplazan SOLO eso. */
        config(['claude_admin_sql.db_username' => 'soporte_lectura', 'claude_admin_sql.db_password' => 'una-clave-de-prueba']);
        $parametros = $servicio->parametros();
        $this->assertSame('soporte_lectura', $parametros['username']);
        $this->assertSame('una-clave-de-prueba', $parametros['password']);
        $this->assertSame($defecto['host'], $parametros['host']);
        $this->assertSame($defecto['database'], $parametros['database']);
        $this->assertSame('mysql', $parametros['driver']);

        /* Usuario sin clave: clave vacía, nunca la del usuario de la aplicación. */
        config(['claude_admin_sql.db_username' => 'soporte_lectura', 'claude_admin_sql.db_password' => null]);
        $this->assertSame('', $servicio->parametros()['password']);

        /* Otra conexión de config/database.php, por nombre. */
        config([
            'claude_admin_sql.db_username' => null,
            'claude_admin_sql.db_password' => null,
            'claude_admin_sql.conexion'    => 'admin_solo_lectura',
            'database.connections.admin_solo_lectura' => ['driver' => 'mysql', 'host' => 'otro.test', 'port' => '3307', 'database' => 'admin', 'username' => 'lector', 'password' => 'x'],
        ]);
        $this->assertSame('lector', $servicio->parametros()['username']);
        $this->assertSame('otro.test', $servicio->parametros()['host']);

        /* Una conexión que no existe: no disponible, sin caer a la por defecto. */
        config(['claude_admin_sql.conexion' => 'no_existe']);
        $this->assertNull($servicio->parametros());
        $resultado = $servicio->consultar('SELECT id FROM clients');
        $this->assertSame('no_disponible', $resultado['codigo']);
        $this->assertSame(503, $resultado['http']);

        /* 🔴 Y que de verdad se conecta con esos parámetros: con un usuario que no existe, no hay
           conexión y no hay respaldo. (Falla el login contra el MySQL local; no sale a ningún lado.) */
        config(['claude_admin_sql.conexion' => null, 'claude_admin_sql.db_username' => 'usuario_que_no_existe_zzz', 'claude_admin_sql.db_password' => 'x']);
        $resultado = $servicio->consultar('SELECT id FROM clients');
        $this->assertFalse($resultado['ok']);
        $this->assertContains($resultado['codigo'], ['no_disponible', 'consulta_fallida']);
        $this->assertArrayNotHasKey('rows', $resultado);

        config(['claude_admin_sql.conexion' => null, 'claude_admin_sql.db_username' => null, 'claude_admin_sql.db_password' => null]);
        $this->assertTrue($servicio->consultar('SELECT id FROM clients WHERE id = ' . $this->client_id)['ok']);
    }

    public function test_el_servicio_no_tiene_un_camino_publico_que_ejecute_sin_validar(): void
    {
        $publicos = [];
        foreach ((new \ReflectionClass(ClaudeAdminSqlService::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $metodo) {
            $publicos[] = $metodo->getName();
        }
        sort($publicos);

        /* `consultar` valida siempre; `redactar` y `redactar_fila` no ejecutan nada. Si aparece otro
           método público acá, hay que mirar que no ejecute SQL sin pasar por el validador. */
        $this->assertSame(['__construct', 'consultar', 'redactar', 'redactar_fila'], $publicos);

        /* Y el servicio rechaza los comentarios por su cuenta, aunque se saltee el validador: una
           pista del optimizador prendería de nuevo la fusión de tablas derivadas y desarmaría la
           reja del plan. */
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

        foreach ([
            'SELECT /*+ SET_VAR(optimizer_switch=\'derived_merge=on\') */ name FROM (SELECT api_key AS name FROM clients) clients',
            'SELECT /*+ MERGE(clients) */ name FROM (SELECT api_key AS name FROM clients) clients',
            'SELECT id /* comentario */ FROM clients',
            "SELECT id FROM clients -- comentario\n WHERE id = 1",
            "SELECT id FROM clients # comentario\n WHERE id = 1",
            'SELECT /*!80000 api_key AS */ name FROM clients',
        ] as $sql) {
            $resultado = $servicio->sin_validar($sql);

            $this->assertFalse($resultado['ok'], $sql);
            $this->assertSame('varias_sentencias', $resultado['codigo'], $sql);
            $this->assertStringNotContainsString('CANARIO', (string) json_encode($resultado), $sql);
        }

        /* Un `#` o un `--` adentro de un texto no es un comentario. */
        $this->assertTrue($servicio->sin_validar('SELECT id FROM clients WHERE name = \'a -- b # c /* d */\' OR id = ' . $this->client_id)['ok']);
    }

    public function test_ordenar_por_posicion_y_calificar_con_otra_base_se_rechazan_por_el_camino_completo(): void
    {
        foreach ([
            'SELECT * FROM clients ORDER BY 25',
            'SELECT * FROM clients ORDER BY 25 DESC',
            'SELECT id, name FROM clients ORDER BY id, 2',
            'SELECT * FROM clients ORDER BY +25',
            'SELECT * FROM clients ORDER BY 25+0',
            'SELECT * FROM clients GROUP BY 25',
            'SELECT * FROM clients ORDER BY LENGTH(name)',
        ] as $sql) {
            $this->consultar($sql)->assertStatus(422)->assertJsonPath('codigo', 'orden_posicional');
        }

        $this->consultar('SELECT * FROM clients ORDER BY (25)')->assertStatus(422)->assertJsonPath('codigo', 'funcion_no_permitida');

        $base = DB::getDatabaseName();

        foreach ([
            'SELECT a.id FROM clients a STRAIGHT_JOIN ' . $base . '.clients b' => 'operacion_no_permitida',
            'SELECT a.id FROM {OJ ' . $base . '.clients b LEFT OUTER JOIN clients a ON a.id = b.id}' => 'consulta_invalida',
            'SELECT a.id FROM clients a NATURAL JOIN ' . $base . '.versions b' => 'otra_base',
            'SELECT a.id FROM clients a CROSS JOIN ' . $base . '.versions b' => 'otra_base',
            'SELECT a.id FROM clients a, (' . $base . '.versions b)' => 'otra_base',
            'SELECT ' . $base . '.clients.id FROM clients' => 'otra_base',
        ] as $sql => $codigo) {
            $this->consultar($sql)->assertStatus(422)->assertJsonPath('codigo', $codigo);
        }

        /* Lo legítimo: ordenar por columnas, con o sin `*`. */
        $this->consultar('SELECT * FROM clients WHERE id = ' . $this->client_id . ' ORDER BY name, id DESC')->assertStatus(200);
        $this->consultar('SELECT c.* FROM clients c WHERE c.id = ' . $this->client_id . ' ORDER BY c.name ASC')->assertStatus(200);
        $this->consultar('SELECT estado, COUNT(*) AS n FROM clients GROUP BY estado ORDER BY n DESC, estado')->assertStatus(200);
    }

    public function test_el_redactor_tapa_la_linea_siguiente_urls_con_clave_claves_privadas_y_basic(): void
    {
        $servicio = app(ClaudeAdminSqlServiceParaTests::class);

        $casos = [
            /* La etiqueta termina la línea: se saltean las líneas en blanco. */
            "Contraseña:\n\n\nCENTINELA\nGracias"                     => ['Contraseña', 'Gracias'],
            "Te paso la clave\r\n\r\nCENTINELA\r\nSaludos"            => ['Te paso la clave', 'Saludos'],
            "api_key =\n\n   CENTINELA\nfin"                          => ['api_key', 'fin'],
            /* Forma NFD: «n» + tilde combinante. */
            /* URL con usuario y clave. */
            'mysql://admin:CENTINELA@db.test:3306/base'               => ['mysql://admin:', '@db.test:3306/base'],
            'Subí por ftp://u123:CENTINELA@ftp.test y avisame'        => ['ftp://u123:', '@ftp.test'],
            /* Clave privada en PEM, con y sin cierre. */
            "antes\n-----BEGIN RSA PRIVATE KEY-----\nCENTINELA\nCENTINELA\n-----END RSA PRIVATE KEY-----\ndespués" => ['antes', 'después'],
            "-----BEGIN PRIVATE KEY-----\nCENTINELA\nCENTINELA"       => [],
            /* Basic y Bearer. */
            'Authorization: Basic Q0VOVElORUxBOkNFTlRJTkVMQQ=='       => [],
            'curl -H "X: Basic Q0VOVElORUxBOkNFTlRJTkVMQQ==" https://x.test' => ['curl -H'],
            /* Etiquetas nuevas. */
            'credenciales del panel: CENTINELA'                       => ['credenciales'],
            'la credencial es CENTINELA'                              => ['la credencial'],
            'auth CENTINELA'                                          => ['auth'],
            'Cookie: laravel_session=CENTINELA'                       => ['Cookie'],
            'session CENTINELA'                                       => ['session'],
            'apikey CENTINELA'                                        => ['apikey'],
            'el secret es CENTINELA'                                  => ['el secret'],
            /* Las mismas etiquetas, como nombre de etiqueta XML. */
            '<Credenciales>CENTINELA</Credenciales><Otro>visible</Otro>' => ['<Otro>visible</Otro>'],
            '<auth><cookie>CENTINELA</cookie></auth> <b>visible</b>'  => ['<b>visible</b>'],
            '<ns:Secret id="1">CENTINELA</ns:Secret>'                 => [],
        ];

        foreach ($casos as $texto => $quedan) {
            $salida = $servicio->redactar($texto, 'body');

            $this->assertIsString($salida, $texto);
            $this->assertStringNotContainsString('CENTINELA', $salida, $texto);
            $this->assertStringNotContainsString('Q0VOVElORUxB', $salida, $texto);

            foreach ($quedan as $trozo) {
                $this->assertStringContainsString($trozo, $salida, $texto);
            }
        }

        /* Forma NFD («n» + tilde combinante): se normaliza a NFC si la extensión intl está. */
        if (class_exists('Normalizer')) {
            $this->assertStringNotContainsString('CENTINELA', $servicio->redactar("contrasen\u{0303}a: CENTINELA", 'body'));
            $this->assertStringNotContainsString('CENTINELA', $servicio->redactar("la contrasen\u{0303}a del router es CENTINELA", 'body'));
        }

        /* Una URL sin clave y un «Basic» que no es una credencial no se tocan. */
        $this->assertSame('Entrá a https://ejemplo.test/ruta?a=1', $servicio->redactar('Entrá a https://ejemplo.test/ruta?a=1', 'body'));
        $this->assertSame('Plan Basic mensual', $servicio->redactar('Plan Basic mensual', 'body'));
    }

    public function test_si_la_conexion_por_defecto_no_es_mysql_contesta_503_no_disponible(): void
    {
        $original = config('database.connections.' . config('database.default') . '.driver');

        config(['database.connections.' . config('database.default') . '.driver' => 'sqlite']);

        try {
            $resultado = app(ClaudeAdminSqlServiceParaTests::class)->consultar('SELECT id FROM clients');
        } finally {
            config(['database.connections.' . config('database.default') . '.driver' => $original]);
        }

        $this->assertFalse($resultado['ok']);
        $this->assertSame('no_disponible', $resultado['codigo']);
        $this->assertSame(503, $resultado['http']);
        $this->assertArrayNotHasKey('rows', $resultado);
    }

    public function test_la_conexion_de_la_aplicacion_no_queda_en_solo_lectura(): void
    {
        $this->consultar('SELECT id FROM clients WHERE id = ' . $this->client_id)->assertStatus(200);
        $this->consultar('SELECT id FROM tabla_que_no_existe_zzz')->assertStatus(422);

        $sesion = DB::selectOne('SELECT @@SESSION.transaction_read_only AS solo_lectura, @@SESSION.max_execution_time AS tope, @@SESSION.optimizer_switch AS optimizador');

        $this->assertSame(0, (int) $sesion->solo_lectura);
        $this->assertSame(0, (int) $sesion->tope);
        $this->assertStringNotContainsString('derived_merge=off', (string) $sesion->optimizador);

        /* Y se puede seguir escribiendo por la conexión de siempre. */
        DB::table('clients')->where('id', $this->client_id)->update(['company_name' => 'Fixture SRL (editada)']);
        $this->assertSame('Fixture SRL (editada)', DB::table('clients')->where('id', $this->client_id)->value('company_name'));
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
        $servicio   = app(ClaudeAdminSqlServiceParaTests::class);

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

/**
 * El servicio, con sus dos métodos protegidos a la vista. SOLO para los tests: es la forma de probar
 * las rejas del lado del resultado (sesión de solo lectura, plan, origen de cada columna) sin que la
 * aplicación tenga un camino público que ejecute SQL sin validar.
 */
class ClaudeAdminSqlServiceParaTests extends ClaudeAdminSqlService
{
    /**
     * Ejecuta SIN pasar por el validador.
     *
     * @param string $sql        Sentencia.
     * @param bool   $de_esquema True para tratarla como SHOW / DESCRIBE / EXPLAIN.
     *
     * @return array<string, mixed>
     */
    public function sin_validar($sql, $de_esquema = false)
    {
        return $this->ejecutar_y_filtrar($sql, $de_esquema);
    }

    /**
     * Los parámetros con los que se abriría la conexión.
     *
     * @return array<string, mixed>|null
     */
    public function parametros()
    {
        return $this->parametros_de_conexion();
    }
}

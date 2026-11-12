<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientEcommerce;
use App\Models\ClientVersionUpgrade;
use App\Models\EcommerceVersion;
use App\Models\Version;
use App\Models\VersionNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET claude/support-codex/client-versions` y `GET claude/support-codex/feature-lookup` (misión
 * `acceso-soporte-al-admin-lectura`, 11/11/2026).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 El CONTRATO con el monitor: los nombres de campo de las dos respuestas. Del otro lado hay
 *     un consumidor escrito contra exactamente estos nombres (`herramientas/soporte-codex/` del repo
 *     de conocimiento); renombrar uno acá lo rompe sin que ningún test de allá se entere.
 *  2. Que «última» y «al día» salgan del orden SEMÁNTICO del número. Las fixtures usan `9000.9.0` y
 *     `9000.10.0` a propósito: como texto, la `.10.` queda antes que la `.9.`.
 *  3. Que un borrador no lo «tenga» nadie, y que lo que no se puede saber salga `null` y no `false`.
 *
 * ⚠️ Las versiones de las fixtures arrancan en `9000.` para quedar por encima de cualquier fila que
 * la base de testing del slot ya tenga: `ultima_publicada` se calcula sobre TODAS las publicadas.
 */
class VersionesYFuncionalidadesParaSoporteTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-lectura-del-admin';

    /**
     * Setea la clave de ingesta: el middleware es fail-closed y sin esto todo daría 401.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
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
     * Cliente mínimo.
     *
     * @param string   $nombre     Nombre del cliente.
     * @param int|null $version_id Versión actual.
     *
     * @return Client
     */
    private function crear_cliente(string $nombre, $version_id = null): Client
    {
        $client                     = new Client();
        $client->name               = $nombre;
        $client->company_name       = 'Empresa ' . $nombre;
        $client->slug               = Str::slug($nombre) . '-' . Str::lower(Str::random(8));
        $client->api_url            = 'https://ejemplo.test';
        $client->api_key            = 'clave-api-del-test';
        $client->inbound_api_key    = 'clave-inbound-del-test';
        $client->is_active          = true;
        $client->current_version_id = $version_id;
        $client->save();

        return $client;
    }

    /**
     * Versión del sistema.
     *
     * @param string      $numero       Número de versión.
     * @param string      $status       draft | published | archived.
     * @param string|null $published_at Fecha de publicación.
     * @param string|null $titulo       Título.
     * @param string|null $descripcion  Descripción.
     *
     * @return Version
     */
    private function crear_version(string $numero, string $status = 'published', $published_at = null, $titulo = null, $descripcion = null): Version
    {
        $version               = new Version();
        $version->version      = $numero;
        $version->title        = $titulo === null ? 'Versión ' . $numero : $titulo;
        $version->description  = $descripcion;
        $version->status       = $status;
        $version->published_at = $published_at;
        $version->save();

        return $version;
    }

    /**
     * Novedad de una versión.
     *
     * @param Version $version Versión dueña.
     * @param string  $titulo  Título.
     * @param string  $cuerpo  Cuerpo.
     * @param bool    $activa  Si está activa.
     *
     * @return VersionNotification
     */
    private function crear_novedad(Version $version, string $titulo, string $cuerpo, bool $activa = true): VersionNotification
    {
        $novedad             = new VersionNotification();
        $novedad->version_id = $version->id;
        $novedad->title      = $titulo;
        $novedad->body       = $cuerpo;
        $novedad->sort_order = 0;
        $novedad->is_active  = $activa;
        $novedad->save();

        return $novedad;
    }

    /**
     * Versión de tienda.
     *
     * @param string      $numero       Número de versión.
     * @param string      $status       draft | published | archived.
     * @param string|null $titulo       Título.
     * @param string|null $published_at Fecha de publicación.
     *
     * @return EcommerceVersion
     */
    private function crear_version_de_tienda(string $numero, string $status, $titulo = null, $published_at = null): EcommerceVersion
    {
        return EcommerceVersion::create([
            'version'      => $numero,
            'title'        => $titulo === null ? 'Tienda ' . $numero : $titulo,
            'description'  => null,
            'status'       => $status,
            'published_at' => $published_at,
        ]);
    }

    /**
     * Tienda de un cliente.
     *
     * @param Client   $client     Cliente dueño.
     * @param int|null $version_id Versión de tienda instalada.
     *
     * @return ClientEcommerce
     */
    private function crear_tienda(Client $client, $version_id = null): ClientEcommerce
    {
        $tienda                       = new ClientEcommerce();
        $tienda->client_id            = $client->id;
        $tienda->domain               = 'tienda-' . Str::lower(Str::random(8)) . '.test';
        $tienda->status               = 'active';
        $tienda->ecommerce_version_id = $version_id;
        $tienda->save();

        return $tienda;
    }

    /**
     * Las tres versiones del sistema de casi todos los tests: dos publicadas (la `.10.` es la
     * última aunque como texto quede antes que la `.9.`) y un borrador posterior.
     *
     * @return array<string, Version>
     */
    private function versiones_del_sistema(): array
    {
        return [
            'vieja'    => $this->crear_version('9000.9.0', 'published', '2020-10-01 10:00:00'),
            'ultima'   => $this->crear_version('9000.10.0', 'published', '2020-11-01 10:00:00'),
            'borrador' => $this->crear_version('9000.11.0', 'draft'),
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta y los verbos
     |----------------------------------------------------------------------------------------- */

    public function test_sin_clave_las_dos_rutas_devuelven_401(): void
    {
        $this->getJson('/api/claude/support-codex/client-versions')->assertStatus(401);
        $this->getJson('/api/claude/support-codex/feature-lookup?q=stock')->assertStatus(401);
    }

    public function test_las_dos_rutas_son_solo_get(): void
    {
        foreach (['client-versions', 'feature-lookup'] as $ruta) {
            $this->postJson('/api/claude/support-codex/' . $ruta, [], $this->headers())->assertStatus(405);
            $this->putJson('/api/claude/support-codex/' . $ruta, [], $this->headers())->assertStatus(405);
            $this->deleteJson('/api/claude/support-codex/' . $ruta, [], $this->headers())->assertStatus(405);
        }
    }

    /* ------------------------------------------------------------------------------------------
     | 2. client-versions
     |----------------------------------------------------------------------------------------- */

    public function test_client_versions_devuelve_la_forma_del_contrato_para_un_cliente_atrasado(): void
    {
        $versiones = $this->versiones_del_sistema();
        $cliente   = $this->crear_cliente('Ferretería Atrasada', $versiones['vieja']->id);

        $api               = new ClientApi();
        $api->client_id    = $cliente->id;
        $api->url          = 'https://api.ejemplo.test';
        $api->path         = 'ejemplo/api';
        $api->hosting_type = 'vps';
        $api->save();

        $cliente->active_client_api_id = $api->id;
        $cliente->tiene_ecommerce      = true;
        $cliente->save();

        ClientVersionUpgrade::create([
            'client_id'      => $cliente->id,
            'to_version_id'  => $versiones['vieja']->id,
            'status'         => 'pendiente',
            'scheduled_date' => now()->toDateString(),
        ]);
        $ultimo_upgrade = ClientVersionUpgrade::create([
            'client_id'      => $cliente->id,
            'to_version_id'  => $versiones['ultima']->id,
            'status'         => 'pendiente',
            'scheduled_date' => now()->toDateString(),
        ]);

        $tienda_vieja  = $this->crear_version_de_tienda('9000.1.0', EcommerceVersion::STATUS_PUBLISHED);
        $tienda_ultima = $this->crear_version_de_tienda('9000.2.0', EcommerceVersion::STATUS_PUBLISHED);
        $this->crear_version_de_tienda('9000.3.0', EcommerceVersion::STATUS_DRAFT);
        $tienda        = $this->crear_tienda($cliente, $tienda_vieja->id);

        $respuesta = $this->getJson('/api/claude/support-codex/client-versions?client_id=' . $cliente->id, $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonStructure([
            'data' => [[
                'client_id', 'name', 'company_name', 'slug', 'estado', 'is_active',
                'sistema' => ['version', 'version_id', 'published_at', 'ultima_publicada', 'al_dia', 'versiones_pendientes', 'hosting_type', 'ultimo_upgrade' => ['id', 'status', 'to_version', 'finished_at']],
                'tiene_ecommerce',
                'ecommerce' => [['id', 'domain', 'status', 'version', 'ultima_publicada', 'al_dia']],
            ]],
            'ultima_version_sistema', 'ultima_version_ecommerce', 'next_after_id', 'server_time',
        ]);

        $this->assertCount(1, $respuesta->json('data'));

        $item = $respuesta->json('data.0');

        $this->assertSame($cliente->id, $item['client_id']);
        $this->assertSame('Ferretería Atrasada', $item['name']);
        $this->assertSame('Empresa Ferretería Atrasada', $item['company_name']);
        $this->assertSame($cliente->slug, $item['slug']);
        $this->assertSame('activo', $item['estado']);
        $this->assertTrue($item['is_active']);
        $this->assertTrue($item['tiene_ecommerce']);

        $this->assertSame('9000.9.0', $item['sistema']['version']);
        $this->assertSame($versiones['vieja']->id, $item['sistema']['version_id']);
        $this->assertStringStartsWith('2020-10-01T', $item['sistema']['published_at']);
        /* 🔴 Semántico: la 9000.10.0 es posterior a la 9000.9.0, y el borrador 9000.11.0 no cuenta. */
        $this->assertSame('9000.10.0', $item['sistema']['ultima_publicada']);
        $this->assertFalse($item['sistema']['al_dia']);
        $this->assertSame(1, $item['sistema']['versiones_pendientes']);
        $this->assertSame('vps', $item['sistema']['hosting_type']);
        $this->assertSame($ultimo_upgrade->id, $item['sistema']['ultimo_upgrade']['id']);
        $this->assertSame('pendiente', $item['sistema']['ultimo_upgrade']['status']);
        $this->assertSame('9000.10.0', $item['sistema']['ultimo_upgrade']['to_version']);
        $this->assertNull($item['sistema']['ultimo_upgrade']['finished_at']);

        $this->assertCount(1, $item['ecommerce']);
        $this->assertSame($tienda->id, $item['ecommerce'][0]['id']);
        $this->assertSame($tienda->domain, $item['ecommerce'][0]['domain']);
        $this->assertSame('active', $item['ecommerce'][0]['status']);
        $this->assertSame('9000.1.0', $item['ecommerce'][0]['version']);
        $this->assertSame('9000.2.0', $item['ecommerce'][0]['ultima_publicada']);
        $this->assertFalse($item['ecommerce'][0]['al_dia']);

        $this->assertSame('9000.10.0', $respuesta->json('ultima_version_sistema'));
        $this->assertSame('9000.2.0', $respuesta->json('ultima_version_ecommerce'));
        $this->assertSame($tienda_ultima->version, $respuesta->json('ultima_version_ecommerce'));
        $this->assertNull($respuesta->json('next_after_id'));
        $this->assertNotEmpty($respuesta->json('server_time'));

        /* Ni una clave en la respuesta. */
        $this->assertStringNotContainsString('clave-api-del-test', $respuesta->getContent());
        $this->assertStringNotContainsString('clave-inbound-del-test', $respuesta->getContent());
    }

    public function test_client_versions_de_un_cliente_al_dia_sin_tienda_ni_frente_ni_upgrades(): void
    {
        $versiones = $this->versiones_del_sistema();
        $cliente   = $this->crear_cliente('Kiosco Al Día', $versiones['ultima']->id);

        $item = $this->getJson('/api/claude/support-codex/client-versions?client_id=' . $cliente->id, $this->headers())
            ->assertStatus(200)
            ->json('data.0');

        $this->assertSame('9000.10.0', $item['sistema']['version']);
        $this->assertTrue($item['sistema']['al_dia']);
        $this->assertSame(0, $item['sistema']['versiones_pendientes']);
        $this->assertNull($item['sistema']['hosting_type']);
        $this->assertNull($item['sistema']['ultimo_upgrade']);
        $this->assertFalse($item['tiene_ecommerce']);
        $this->assertSame([], $item['ecommerce']);
    }

    public function test_client_versions_de_un_cliente_sin_version_registrada_devuelve_null_y_no_false(): void
    {
        $this->versiones_del_sistema();
        $cliente = $this->crear_cliente('Negocio Sin Versión', null);
        $this->crear_tienda($cliente, null);

        $item = $this->getJson('/api/claude/support-codex/client-versions?client_id=' . $cliente->id, $this->headers())
            ->assertStatus(200)
            ->json('data.0');

        $this->assertNull($item['sistema']['version']);
        $this->assertNull($item['sistema']['version_id']);
        $this->assertNull($item['sistema']['published_at']);
        $this->assertNull($item['sistema']['al_dia']);
        $this->assertNull($item['sistema']['versiones_pendientes']);
        $this->assertSame('9000.10.0', $item['sistema']['ultima_publicada']);

        /* La tienda instalada por la vía vieja no tiene versión: tampoco se afirma nada. */
        $this->assertCount(1, $item['ecommerce']);
        $this->assertNull($item['ecommerce'][0]['version']);
        $this->assertNull($item['ecommerce'][0]['al_dia']);
    }

    public function test_client_versions_con_un_cliente_inexistente_devuelve_404_con_la_forma_del_bloque(): void
    {
        $respuesta = $this->getJson('/api/claude/support-codex/client-versions?client_id=999999999', $this->headers());

        $respuesta->assertStatus(404);
        $this->assertArrayHasKey('error', $respuesta->json());
        $this->assertStringContainsString('999999999', $respuesta->json('error'));
    }

    public function test_client_versions_valida_los_parametros_con_422(): void
    {
        foreach (['client_id=abc', 'client_id=0', 'after_id=-1', 'limit=abc', 'q=' . str_repeat('a', 101)] as $query) {
            $respuesta = $this->getJson('/api/claude/support-codex/client-versions?' . $query, $this->headers());

            $respuesta->assertStatus(422);
            $this->assertSame('parámetros inválidos', $respuesta->json('error'), $query);
            $this->assertArrayHasKey('errores', $respuesta->json());
        }
    }

    public function test_client_versions_busca_por_nombre_razon_social_y_slug(): void
    {
        $marca = 'zorzal' . Str::lower(Str::random(8));
        $uno   = $this->crear_cliente('Almacén ' . $marca);
        $dos   = $this->crear_cliente('Otro negocio');
        $dos->company_name = 'Sociedad ' . $marca . ' SRL';
        $dos->save();
        $tres  = $this->crear_cliente('Tercero');
        $tres->slug = $marca . '-slug';
        $tres->save();
        $this->crear_cliente('No coincide');

        $ids = array_column(
            (array) $this->getJson('/api/claude/support-codex/client-versions?q=' . strtoupper($marca), $this->headers())->assertStatus(200)->json('data'),
            'client_id'
        );

        $this->assertSame([$uno->id, $dos->id, $tres->id], $ids);

        /* Los comodines de LIKE se buscan como texto: `%` no trae todo. */
        $this->assertSame([], $this->getJson('/api/claude/support-codex/client-versions?q=' . urlencode($marca . '%inexistente'), $this->headers())->json('data'));
    }

    public function test_client_versions_pagina_por_cursor_sin_repetir_ni_saltear(): void
    {
        $marca = 'pagina' . Str::lower(Str::random(8));
        $ids   = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->crear_cliente('Cliente ' . $marca . ' ' . $i)->id;
        }

        $vistos   = [];
        $after_id = null;
        $vueltas  = 0;

        do {
            $url = '/api/claude/support-codex/client-versions?q=' . $marca . '&limit=2' . ($after_id === null ? '' : '&after_id=' . $after_id);
            $respuesta = $this->getJson($url, $this->headers())->assertStatus(200);

            $this->assertLessThanOrEqual(2, count($respuesta->json('data')));

            foreach ($respuesta->json('data') as $item) {
                $vistos[] = $item['client_id'];
            }

            $after_id = $respuesta->json('next_after_id');
            $this->assertSame($after_id !== null, $respuesta->json('has_more'));
            $vueltas++;
        } while ($after_id !== null && $vueltas < 10);

        $this->assertSame($ids, $vistos);
        $this->assertSame(3, $vueltas);

        /* Un limit fuera de rango se recorta, no falla. */
        $this->getJson('/api/claude/support-codex/client-versions?q=' . $marca . '&limit=100000', $this->headers())->assertStatus(200);
        $this->assertCount(1, $this->getJson('/api/claude/support-codex/client-versions?q=' . $marca . '&limit=0', $this->headers())->assertStatus(200)->json('data'));
    }

    /* ------------------------------------------------------------------------------------------
     | 3. feature-lookup
     |----------------------------------------------------------------------------------------- */

    public function test_feature_lookup_exige_q_de_2_a_100_caracteres_y_un_sistema_valido(): void
    {
        foreach (['', 'q=a', 'q=' . str_repeat('a', 101), 'q=stock&sistema=admin', 'q=stock&client_id=abc'] as $query) {
            $respuesta = $this->getJson('/api/claude/support-codex/feature-lookup?' . $query, $this->headers());

            $respuesta->assertStatus(422);
            $this->assertSame('parámetros inválidos', $respuesta->json('error'), $query);
        }
    }

    public function test_feature_lookup_con_un_cliente_inexistente_devuelve_404(): void
    {
        $respuesta = $this->getJson('/api/claude/support-codex/feature-lookup?q=stock&client_id=999999999', $this->headers());

        $respuesta->assertStatus(404);
        $this->assertArrayHasKey('error', $respuesta->json());
    }

    public function test_feature_lookup_dice_si_el_cliente_tiene_cada_version_publicada_o_en_borrador(): void
    {
        $marca     = 'quetzal' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();
        $cliente   = $this->crear_cliente('Cliente En La Vieja', $versiones['vieja']->id);

        $this->crear_novedad($versiones['vieja'], 'Exportar ' . $marca . ' a Excel', '<p>Ahora el listado de <b>' . $marca . '</b> se exporta.</p>');
        $this->crear_novedad($versiones['ultima'], 'Filtros de ' . $marca, 'Se agregan filtros por fecha.');
        $this->crear_novedad($versiones['borrador'], 'Etiquetas de ' . $marca, 'Todavía en desarrollo.');
        $this->crear_novedad($versiones['ultima'], 'Novedad apagada de ' . $marca, 'No tiene que salir.', false);

        $respuesta = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . strtoupper($marca) . '&client_id=' . $cliente->id, $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonStructure([
            'q',
            'resultados' => [[
                'sistema', 'version', 'version_status', 'publicada', 'published_at', 'titulo', 'texto', 'origen',
                'cliente' => ['tiene', 'version_cliente', 'motivo'],
            ]],
            'cliente' => ['client_id', 'name', 'sistema' => ['version', 'ultima_publicada', 'al_dia'], 'tiene_ecommerce', 'ecommerce'],
            'aviso',
        ]);

        $this->assertSame(strtoupper($marca), $respuesta->json('q'));
        $this->assertSame('La disponibilidad es por versión; una extensión o el plan pueden condicionarla.', $respuesta->json('aviso'));
        $this->assertSame($cliente->id, $respuesta->json('cliente.client_id'));
        $this->assertSame('9000.9.0', $respuesta->json('cliente.sistema.version'));

        $resultados = $respuesta->json('resultados');

        /* Más nuevas primero: el borrador (recién creado), la última publicada, la vieja. La
           novedad apagada no sale. */
        $this->assertSame(['9000.11.0', '9000.10.0', '9000.9.0'], array_column($resultados, 'version'));

        $borrador = $resultados[0];
        $this->assertSame('empresa', $borrador['sistema']);
        $this->assertSame('novedad', $borrador['origen']);
        $this->assertSame('draft', $borrador['version_status']);
        $this->assertFalse($borrador['publicada']);
        $this->assertNull($borrador['published_at']);
        $this->assertFalse($borrador['cliente']['tiene']);
        $this->assertStringContainsString('todavía no se publicó', $borrador['cliente']['motivo']);

        $ultima = $resultados[1];
        $this->assertSame('published', $ultima['version_status']);
        $this->assertTrue($ultima['publicada']);
        $this->assertStringStartsWith('2020-11-01T', $ultima['published_at']);
        /* 🔴 Semántico: el cliente está en la 9000.9.0 y la novedad salió en la 9000.10.0. Como
           texto, "9000.9.0" >= "9000.10.0" y esto daría true. */
        $this->assertFalse($ultima['cliente']['tiene']);
        $this->assertSame('9000.9.0', $ultima['cliente']['version_cliente']);
        $this->assertStringContainsString('le falta actualizar', $ultima['cliente']['motivo']);

        $vieja = $resultados[2];
        $this->assertTrue($vieja['cliente']['tiene']);
        $this->assertSame('9000.9.0', $vieja['cliente']['version_cliente']);
        $this->assertSame('Exportar ' . $marca . ' a Excel', $vieja['titulo']);
        $this->assertSame('Ahora el listado de ' . $marca . ' se exporta.', $vieja['texto']);
    }

    public function test_feature_lookup_sin_cliente_o_con_un_cliente_sin_version_no_afirma_nada(): void
    {
        $marca     = 'tucan' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();
        $this->crear_novedad($versiones['vieja'], 'Novedad ' . $marca, 'Cuerpo.');

        $sin_cliente = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca, $this->headers())->assertStatus(200);

        $this->assertNull($sin_cliente->json('cliente'));
        $this->assertCount(1, $sin_cliente->json('resultados'));
        $this->assertTrue($sin_cliente->json('resultados.0.publicada'));
        $this->assertNull($sin_cliente->json('resultados.0.cliente.tiene'));
        $this->assertNull($sin_cliente->json('resultados.0.cliente.version_cliente'));
        $this->assertNotSame('', (string) $sin_cliente->json('resultados.0.cliente.motivo'));

        $cliente     = $this->crear_cliente('Sin Versión', null);
        $sin_version = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&client_id=' . $cliente->id, $this->headers())->assertStatus(200);

        $this->assertSame($cliente->id, $sin_version->json('cliente.client_id'));
        $this->assertNull($sin_version->json('resultados.0.cliente.tiene'));
        $this->assertStringContainsString('no tiene versión registrada', $sin_version->json('resultados.0.cliente.motivo'));
    }

    public function test_feature_lookup_exige_todas_las_palabras_y_busca_tambien_en_la_version(): void
    {
        $marca     = 'colibri' . Str::lower(Str::random(8));
        $otra      = 'hornero' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();

        $this->crear_novedad($versiones['vieja'], 'Sobre ' . $marca, 'Texto que además nombra ' . $otra . '.');
        $this->crear_novedad($versiones['ultima'], 'Solo ' . $marca, 'Sin la otra palabra.');
        $this->crear_version('9000.9.1', 'published', '2020-10-15 10:00:00', 'Hotfix de ' . $marca, 'Corrige ' . $otra . ' en el listado.');

        $dos_palabras = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . urlencode($otra . '   ' . $marca), $this->headers())->assertStatus(200)->json('resultados');

        $this->assertSame(['9000.9.1', '9000.9.0'], array_column($dos_palabras, 'version'));
        $this->assertSame(['version', 'novedad'], array_column($dos_palabras, 'origen'));
        $this->assertSame('Hotfix de ' . $marca, $dos_palabras[0]['titulo']);
        $this->assertSame('Corrige ' . $otra . ' en el listado.', $dos_palabras[0]['texto']);

        $una_palabra = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca, $this->headers())->assertStatus(200);
        $this->assertCount(3, $una_palabra->json('resultados'));
        $this->assertFalse($una_palabra->json('hay_mas'));

        $con_limite = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&limit=2', $this->headers())->assertStatus(200);
        $this->assertCount(2, $con_limite->json('resultados'));
        $this->assertTrue($con_limite->json('hay_mas'));

        /* Nada coincide: lista vacía, no error. */
        $this->assertSame([], $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . 'inexistente', $this->headers())->assertStatus(200)->json('resultados'));
    }

    public function test_feature_lookup_recorta_el_texto_a_600_caracteres(): void
    {
        $marca     = 'benteveo' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();
        $this->crear_novedad($versiones['vieja'], 'Larga ' . $marca, str_repeat('ñandú ', 300));

        $texto = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca, $this->headers())->assertStatus(200)->json('resultados.0.texto');

        $this->assertSame(600, mb_strlen($texto, 'UTF-8'));
        $this->assertStringEndsWith('…', $texto);
    }

    public function test_feature_lookup_de_tienda_compara_contra_la_version_de_la_tienda_del_cliente(): void
    {
        $marca     = 'carpincho' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();

        $tienda_vieja = $this->crear_version_de_tienda('9000.1.0', EcommerceVersion::STATUS_PUBLISHED, 'Carrito ' . $marca, '2020-09-01 10:00:00');
        $this->crear_version_de_tienda('9000.2.0', EcommerceVersion::STATUS_PUBLISHED, 'Buscador ' . $marca, '2020-10-01 10:00:00');
        /* Una novedad del SISTEMA con la misma palabra, para probar el filtro `sistema`. */
        $this->crear_novedad($versiones['ultima'], 'Sistema ' . $marca, 'Cuerpo.');

        $con_tienda = $this->crear_cliente('Con Tienda', $versiones['ultima']->id);
        $this->crear_tienda($con_tienda, $tienda_vieja->id);

        $respuesta  = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&sistema=tienda&client_id=' . $con_tienda->id, $this->headers())->assertStatus(200);
        $resultados = $respuesta->json('resultados');

        $this->assertSame(['tienda', 'tienda'], array_column($resultados, 'sistema'));
        $this->assertSame(['9000.2.0', '9000.1.0'], array_column($resultados, 'version'));
        $this->assertSame(['version', 'version'], array_column($resultados, 'origen'));

        /* 🔴 El cliente está al día en el SISTEMA; lo que cuenta acá es la versión de su tienda. */
        $this->assertFalse($resultados[0]['cliente']['tiene']);
        $this->assertSame('9000.1.0', $resultados[0]['cliente']['version_cliente']);
        $this->assertTrue($resultados[1]['cliente']['tiene']);
        $this->assertSame('9000.1.0', $resultados[1]['cliente']['version_cliente']);

        /* Sin tienda: null. */
        $sin_tienda = $this->crear_cliente('Sin Tienda', $versiones['ultima']->id);
        $resultados = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&sistema=tienda&client_id=' . $sin_tienda->id, $this->headers())->assertStatus(200)->json('resultados');
        $this->assertNull($resultados[0]['cliente']['tiene']);
        $this->assertStringContainsString('no tiene tienda', $resultados[0]['cliente']['motivo']);

        /* Tienda sin versión registrada: null. */
        $tienda_vieja_via = $this->crear_cliente('Tienda Vía Vieja', $versiones['ultima']->id);
        $this->crear_tienda($tienda_vieja_via, null);
        $resultados = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&sistema=tienda&client_id=' . $tienda_vieja_via->id, $this->headers())->assertStatus(200)->json('resultados');
        $this->assertNull($resultados[0]['cliente']['tiene']);
        $this->assertStringContainsString('no tiene versión registrada', $resultados[0]['cliente']['motivo']);

        /* `sistema=empresa` deja afuera las de tienda, y sin `sistema` salen los dos. */
        $empresa = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&sistema=empresa', $this->headers())->assertStatus(200)->json('resultados');
        $this->assertSame(['empresa'], array_column($empresa, 'sistema'));

        $todos = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca, $this->headers())->assertStatus(200)->json('resultados');
        $sistemas = array_column($todos, 'sistema');
        sort($sistemas);
        $this->assertSame(['empresa', 'tienda', 'tienda'], $sistemas);
    }

    public function test_feature_lookup_con_varias_tiendas_exige_que_todas_la_tengan(): void
    {
        $marca     = 'yaguarete' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();

        $vieja = $this->crear_version_de_tienda('9000.1.0', EcommerceVersion::STATUS_PUBLISHED, 'Otra cosa', '2020-09-01 10:00:00');
        $nueva = $this->crear_version_de_tienda('9000.2.0', EcommerceVersion::STATUS_PUBLISHED, 'Buscador ' . $marca, '2020-10-01 10:00:00');

        $cliente = $this->crear_cliente('Dos Tiendas', $versiones['ultima']->id);
        $this->crear_tienda($cliente, $nueva->id);
        $this->crear_tienda($cliente, $vieja->id);

        $resultado = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&client_id=' . $cliente->id, $this->headers())->assertStatus(200)->json('resultados.0');

        $this->assertFalse($resultado['cliente']['tiene']);
        $this->assertSame('9000.1.0', $resultado['cliente']['version_cliente']);
        $this->assertStringContainsString('2 tiendas', $resultado['cliente']['motivo']);
    }

    /* ------------------------------------------------------------------------------------------
     | 4. feature-lookup: archivadas, palabras, resumen y tiendas instaladas
     |----------------------------------------------------------------------------------------- */

    public function test_una_version_archivada_que_se_publico_se_compara_por_numero_y_una_que_no_da_null(): void
    {
        $marca     = 'aguara' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();

        $archivada = $this->crear_version('9000.9.5', 'archived', '2020-10-10 10:00:00');
        $nunca     = $this->crear_version('9000.9.6', 'archived', null);
        $this->crear_novedad($archivada, 'Remitos ' . $marca, 'Salió y después se archivó la versión.');
        $this->crear_novedad($nunca, 'Etiquetas ' . $marca, 'Se archivó sin publicarse.');

        /* Cliente posterior a la archivada: la tiene, aunque hoy la versión no esté «publicada». */
        $posterior  = $this->crear_cliente('Posterior', $versiones['ultima']->id);
        $resultados = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&client_id=' . $posterior->id, $this->headers())->assertStatus(200)->json('resultados');
        $por_version = array_column($resultados, null, 'version');

        $this->assertSame('archived', $por_version['9000.9.5']['version_status']);
        $this->assertFalse($por_version['9000.9.5']['publicada']);
        $this->assertStringStartsWith('2020-10-10T', $por_version['9000.9.5']['published_at']);
        $this->assertTrue($por_version['9000.9.5']['cliente']['tiene']);
        $this->assertStringContainsString('archivada', $por_version['9000.9.5']['cliente']['motivo']);

        /* Archivada sin fecha de publicación: no se puede afirmar nada. Ni true ni false. */
        $this->assertSame('archived', $por_version['9000.9.6']['version_status']);
        $this->assertNull($por_version['9000.9.6']['published_at']);
        $this->assertNull($por_version['9000.9.6']['cliente']['tiene']);
        $this->assertSame('9000.10.0', $por_version['9000.9.6']['cliente']['version_cliente']);
        $this->assertStringContainsString('no tiene fecha de publicación', $por_version['9000.9.6']['cliente']['motivo']);

        /* Cliente anterior a la archivada: no la tiene, y es por número, no por el estado. */
        $anterior   = $this->crear_cliente('Anterior', $versiones['vieja']->id);
        $resultados = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&client_id=' . $anterior->id, $this->headers())->assertStatus(200)->json('resultados');
        $por_version = array_column($resultados, null, 'version');

        $this->assertFalse($por_version['9000.9.5']['cliente']['tiene']);
        $this->assertStringContainsString('le falta actualizar', $por_version['9000.9.5']['cliente']['motivo']);
        $this->assertNull($por_version['9000.9.6']['cliente']['tiene']);
    }

    public function test_solo_un_borrador_da_el_false_categorico_de_todavia_no_se_publico(): void
    {
        $marca     = 'surubi' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();
        $this->crear_novedad($versiones['borrador'], 'Borrador ' . $marca, 'En desarrollo.');

        $cliente   = $this->crear_cliente('Al día', $versiones['ultima']->id);
        $resultado = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&client_id=' . $cliente->id, $this->headers())->assertStatus(200)->json('resultados.0');

        $this->assertSame('draft', $resultado['version_status']);
        $this->assertFalse($resultado['cliente']['tiene']);
        $this->assertStringContainsString('todavía no se publicó', $resultado['cliente']['motivo']);
    }

    public function test_la_busqueda_saca_palabras_vacias_plurales_y_no_distingue_acentos(): void
    {
        $servicio = app(\App\Services\ClaudeSupportAdminLecturaService::class);

        $this->assertSame(['factura', 'electrónica'], $servicio->palabras('  ¿Cómo  es para las FACTURAS electrónicas? '));
        /* La lista de vacías es corta a propósito: un verbo como «hago» se busca. */
        $this->assertSame(['hago', 'factura'], $servicio->palabras('cómo hago facturas'));
        $this->assertSame(['client', 'lista', 'precio'], $servicio->palabras('clientes con listas de precios'));
        /* Hasta 4 letras no se toca el plural; dos letras fuera de la lista se buscan (QR, ML). */
        $this->assertSame(['mes', 'tres', 'qr', 'ml'], $servicio->palabras('mes tres QR y ML a'));
        $this->assertSame(['exportar'], $servicio->palabras('exportar exportar EXPORTAR'));
        $this->assertSame([], $servicio->palabras('cómo es la de un para qué'));

        $marca     = 'tero' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();
        $this->crear_novedad($versiones['vieja'], 'Factura electrónica ' . $marca, 'Ahora se emite desde el listado con código QR.');

        /* Plural + sin acento + palabras vacías en el medio: encuentra la novedad en singular y con tilde. */
        foreach ([
            'facturas electronicas ' . $marca,
            '¿qué es la de las FACTURAS ELECTRÓNICAS para ' . $marca . '?',
            'qr ' . $marca,
            'listados ' . $marca,
        ] as $q) {
            $resultados = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . urlencode($q), $this->headers())->assertStatus(200)->json('resultados');

            $this->assertCount(1, $resultados, $q);
            $this->assertSame('Factura electrónica ' . $marca, $resultados[0]['titulo'], $q);
        }

        /* Sigue siendo AND: una palabra útil que no está deja la búsqueda en cero. */
        $this->assertSame([], $this->getJson('/api/claude/support-codex/feature-lookup?q=' . urlencode('remitos ' . $marca), $this->headers())->assertStatus(200)->json('resultados'));
    }

    public function test_una_busqueda_sin_ninguna_palabra_util_devuelve_422_claro(): void
    {
        foreach (['de la', 'cómo es para qué', 'a o y', '¿? --'] as $q) {
            $respuesta = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . urlencode($q), $this->headers());

            $respuesta->assertStatus(422);
            $this->assertSame('busqueda_sin_palabras', $respuesta->json('codigo'), $q);
            $this->assertStringContainsString('palabra útil', $respuesta->json('error'), $q);
        }
    }

    public function test_el_resumen_del_cliente_se_calcula_sobre_todas_las_coincidencias_y_no_solo_la_pagina(): void
    {
        $marca     = 'chaja' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();
        $medio     = $this->crear_version('9000.9.5', 'published', '2020-10-10 10:00:00');

        /* La misma función en dos versiones: salió en la 9000.9.0 y se mejoró en la 9000.10.0. */
        $this->crear_novedad($versiones['vieja'], 'Exportación ' . $marca, 'Primera versión.');
        $this->crear_novedad($versiones['ultima'], 'Mejoras en ' . $marca, 'Más columnas.');
        $this->crear_novedad($versiones['borrador'], 'Futuro de ' . $marca, 'En desarrollo.');

        /* Cliente entre medio: tiene la original, le falta la mejora. */
        $cliente   = $this->crear_cliente('Entre Medio', $medio->id);
        $respuesta = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&client_id=' . $cliente->id, $this->headers())->assertStatus(200);

        $this->assertSame(
            'Si la misma función aparece en varias versiones, la que decide si el cliente la tiene es la más vieja publicada; las posteriores son mejoras.',
            $respuesta->json('lectura')
        );
        $this->assertSame(
            ['tiene_alguna' => true, 'version_mas_vieja_que_la_trae' => '9000.9.0', 'sistema' => 'empresa'],
            $respuesta->json('resumen_cliente')
        );
        $this->assertSame([false, false, true], array_column(array_column($respuesta->json('resultados'), 'cliente'), 'tiene'));

        /* 🔴 Con limit=1 la página trae solo el borrador (tiene: false). El resumen no cambia. */
        $pagina = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&limit=1&client_id=' . $cliente->id, $this->headers())->assertStatus(200);

        $this->assertCount(1, $pagina->json('resultados'));
        $this->assertSame('9000.11.0', $pagina->json('resultados.0.version'));
        $this->assertFalse($pagina->json('resultados.0.cliente.tiene'));
        $this->assertTrue($pagina->json('hay_mas'));
        $this->assertSame(
            ['tiene_alguna' => true, 'version_mas_vieja_que_la_trae' => '9000.9.0', 'sistema' => 'empresa'],
            $pagina->json('resumen_cliente')
        );

        /* Cliente anterior a todas: no tiene ninguna, y la más vieja que la trae sigue siendo la misma. */
        $viejo = $this->crear_cliente('Muy Viejo', $this->crear_version('9000.8.0', 'published', '2020-09-01 10:00:00')->id);
        $this->assertSame(
            ['tiene_alguna' => false, 'version_mas_vieja_que_la_trae' => '9000.9.0', 'sistema' => 'empresa'],
            $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&client_id=' . $viejo->id, $this->headers())->assertStatus(200)->json('resumen_cliente')
        );

        /* Sin cliente: la clave está y vale null; `lectura` viaja igual. */
        $sin_cliente = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca, $this->headers())->assertStatus(200);
        $this->assertArrayHasKey('resumen_cliente', $sin_cliente->json());
        $this->assertNull($sin_cliente->json('resumen_cliente'));
        $this->assertNotEmpty($sin_cliente->json('lectura'));

        /* Solo coincide un borrador: nadie la trae todavía. */
        $solo_borrador = 'ñacurutu' . Str::lower(Str::random(8));
        $this->crear_novedad($versiones['borrador'], 'Nueva ' . $solo_borrador, 'En desarrollo.');
        $this->assertSame(
            ['tiene_alguna' => false, 'version_mas_vieja_que_la_trae' => null, 'sistema' => null],
            $this->getJson('/api/claude/support-codex/feature-lookup?q=' . urlencode($solo_borrador) . '&client_id=' . $cliente->id, $this->headers())->assertStatus(200)->json('resumen_cliente')
        );
    }

    public function test_para_tienda_solo_deciden_las_tiendas_instaladas(): void
    {
        $marca     = 'mulita' . Str::lower(Str::random(8));
        $versiones = $this->versiones_del_sistema();

        $vieja = $this->crear_version_de_tienda('9000.1.0', EcommerceVersion::STATUS_PUBLISHED, 'Otra cosa', '2020-09-01 10:00:00');
        $nueva = $this->crear_version_de_tienda('9000.2.0', EcommerceVersion::STATUS_PUBLISHED, 'Buscador ' . $marca, '2020-10-01 10:00:00');

        /* Una tienda instalada en la versión nueva y otra a medio instalar en la vieja: la segunda no decide. */
        $cliente = $this->crear_cliente('Una Instalada', $versiones['ultima']->id);
        $this->crear_tienda($cliente, $nueva->id);
        $a_medias         = $this->crear_tienda($cliente, $vieja->id);
        $a_medias->status = 'installing';
        $a_medias->save();

        $respuesta = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&sistema=tienda&client_id=' . $cliente->id, $this->headers())->assertStatus(200);
        $resultado = $respuesta->json('resultados.0');

        $this->assertTrue($resultado['cliente']['tiene']);
        $this->assertSame('9000.2.0', $resultado['cliente']['version_cliente']);
        $this->assertStringContainsString('1 tienda que no está instalada', $resultado['cliente']['motivo']);
        $this->assertSame(['tiene_alguna' => true, 'version_mas_vieja_que_la_trae' => '9000.2.0', 'sistema' => 'tienda'], $respuesta->json('resumen_cliente'));
        /* En el ítem del cliente siguen figurando las dos, con su estado. */
        $this->assertSame(['active', 'installing'], array_column($respuesta->json('cliente.ecommerce'), 'status'));

        /* Solo una tienda pendiente: no hay ninguna instalada, no se afirma nada. */
        $pendiente      = $this->crear_cliente('Solo Pendiente', $versiones['ultima']->id);
        $tienda         = $this->crear_tienda($pendiente, $nueva->id);
        $tienda->status = 'pending';
        $tienda->save();

        $resultado = $this->getJson('/api/claude/support-codex/feature-lookup?q=' . $marca . '&sistema=tienda&client_id=' . $pendiente->id, $this->headers())->assertStatus(200)->json('resultados.0');

        $this->assertNull($resultado['cliente']['tiene']);
        $this->assertNull($resultado['cliente']['version_cliente']);
        $this->assertStringContainsString('ninguna tienda instalada', $resultado['cliente']['motivo']);
    }
}

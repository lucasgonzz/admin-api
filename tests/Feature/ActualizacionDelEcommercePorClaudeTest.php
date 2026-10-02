<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ClaudeEcommerceOpsController;
use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Models\EcommerceVersion;
use App\Models\EnvTemplate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los frenos de la actualización del ecommerce por Claude
 * (`claude/ecommerce/updates` y `claude/ecommerce/updates/batch`).
 *
 * Este test existe por un motivo puntual y no por completitud: estas dos rutas arrancan un pipeline
 * SSH REAL contra el hosting de un negocio —clonan y compilan `tienda-spa` en el VPS de builds,
 * suben SPA y API por SFTP, corren `composer install` allá— y una corrida arrancada no se deshace.
 * Lo que se protege acá, en orden de importancia:
 *
 *  1. 🔴 Que los dos `dispatch()` vayan a la conexión `database` y NO corran el pipeline adentro del
 *     request. Con `QUEUE_CONNECTION=sync` un dispatch pelado ejecuta el pipeline entero dentro del
 *     request HTTP y lo mata `max_execution_time`. ⚠️ El `return $job->connection === 'database'` de
 *     la aserción NO es decorativo: `QueueFake::connection()` devuelve `$this` sin mirar el nombre,
 *     así que un `assertPushed` pelado pasaría igual con un dispatch SIN `onConnection` —o sea, no
 *     probaría nada—. Está documentado en `tests/Feature/DemoSetupFueraDelRequestTest.php:148-152`.
 *     Y es exactamente la regresión más probable acá, porque el PANEL despacha pelado.
 *  2. 🔴 Que la instalación inicial (`mode = 'install'`) tenga UNA sola puerta por `claude/*`:
 *     `POST claude/ecommerce/installs`, con sus frenos (decisión de Lucas del 2/10/2026, que
 *     reemplazó a la prohibición de antes). Se verifica por comportamiento Y leyendo el fuente.
 *  3. Que TODO freno que rechaza devuelva 422 y no escriba absolutamente nada: ni corrida, ni job.
 *  4. Que el lote simule por defecto y que `confirm_client_count` + `confirm_token` sean exactos.
 *  5. Que `confirm_client_name` no revele el nombre correcto cuando falla.
 *  6. Que la salud de una corrida colgada diga la verdad incómoda: nadie la destraba.
 */
class ActualizacionDelEcommercePorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-ecommerce';

    /**
     * Setea la clave de ingesta: en el .env del slot está vacía y el middleware es fail-closed, así
     * que sin esto todo devolvería 401 y los tests medirían el middleware, no el endpoint.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);
    }

    /* ------------------------------------------------------------------------------------------
     | Armado del escenario
     |----------------------------------------------------------------------------------------- */

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
     * Cliente del admin.
     *
     * @param string $nombre Nombre del negocio (es lo que confirma confirm_client_name).
     *
     * @return Client
     */
    private function crear_cliente(string $nombre): Client
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->company_name    = 'Empresa ' . $nombre;
        $client->slug            = Str::slug($nombre !== '' ? $nombre : 'sin-nombre') . '-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    /**
     * Tienda del cliente, configurada y lista para actualizarse.
     *
     * @param Client $client    Cliente dueño.
     * @param bool   $completa  False deja spa_url/api_url/domain vacíos (tienda a medio configurar).
     *
     * @return ClientEcommerce
     */
    private function crear_tienda(Client $client, bool $completa = true): ClientEcommerce
    {
        $dominio = Str::slug($client->name !== '' ? $client->name : 'tienda') . '-' . Str::random(6) . '.com.ar';

        $tienda            = new ClientEcommerce();
        $tienda->client_id = $client->id;
        $tienda->domain    = $completa ? $dominio : '';
        $tienda->spa_url   = $completa ? 'https://' . $dominio : '';
        $tienda->api_url   = $completa ? 'https://api.' . $dominio : '';
        $tienda->status    = 'active';
        $tienda->save();

        return $tienda;
    }

    /**
     * Deja las credenciales SSH globales cargadas (VPS de builds + hosting compartido).
     *
     * Son globales, no por cliente: una fila por tipo en `client_ssh_credentials`. Se borra lo que
     * haya antes para que el test no dependa de lo que tenga sembrada la base del slot.
     *
     * @return void
     */
    private function cargar_credenciales_ssh(): void
    {
        ClientSshCredential::query()->delete();

        foreach (['vps', 'shared_hosting'] as $tipo) {
            $credencial           = new ClientSshCredential();
            $credencial->type     = $tipo;
            $credencial->host     = $tipo . '.ejemplo.test';
            $credencial->port     = 22;
            $credencial->username = 'deploy';
            $credencial->password = 'secreta';
            $credencial->save();
        }
    }

    /**
     * Escenario completo: cliente + tienda configurada + credenciales SSH.
     *
     * @param string $nombre Nombre del cliente.
     *
     * @return array{cliente: Client, tienda: ClientEcommerce}
     */
    private function escenario_listo(string $nombre): array
    {
        $this->cargar_credenciales_ssh();

        $cliente = $this->crear_cliente($nombre);
        $tienda  = $this->crear_tienda($cliente);

        return ['cliente' => $cliente, 'tienda' => $tienda];
    }

    /**
     * Cantidad de corridas de ecommerce que hay en la base ahora mismo.
     *
     * @return int
     */
    private function corridas_totales(): int
    {
        return ClientEcommerceInstallation::query()->count();
    }

    /**
     * Cuerpo de la respuesta como texto, con los acentos SIN escapar.
     *
     * 🔴 No es cosmética, y se descubrió midiendo: `getContent()` devuelve el JSON crudo, donde
     * "Ferretería" viaja escapado como "Ferretería". Un
     * `assertStringNotContainsString('Ferretería', $respuesta->getContent())` sobre eso pasa
     * SIEMPRE —incluso si el nombre estuviera efectivamente en la respuesta—, o sea que el test que
     * verifica que el freno no revela el nombre no verificaría NADA. Y al revés: un
     * `assertStringContainsString` de un mensaje con acentos falla aunque el mensaje esté. Se
     * decodifica y se vuelve a serializar con JSON_UNESCAPED_UNICODE para que las comparaciones
     * midan lo que dicen medir.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a leer.
     *
     * @return string
     */
    private function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE);
    }

    /* ------------------------------------------------------------------------------------------
     | 1. La puerta: sin clave no entra nadie
     |----------------------------------------------------------------------------------------- */

    /**
     * El middleware es fail-closed: sin el header, ninguna de las seis rutas contesta.
     *
     * @return void
     */
    public function test_sin_clave_las_rutas_de_ecommerce_devuelven_401(): void
    {
        $this->getJson('/api/claude/ecommerce/stores')->assertStatus(401);
        $this->getJson('/api/claude/ecommerce/installations')->assertStatus(401);
        $this->getJson('/api/claude/ecommerce/installations/1')->assertStatus(401);
        $this->getJson('/api/claude/ecommerce/installations/1/logs')->assertStatus(401);
        $this->postJson('/api/claude/ecommerce/updates', ['client_id' => 1])->assertStatus(401);
        $this->postJson('/api/claude/ecommerce/updates/batch', ['client_ids' => [1]])->assertStatus(401);
    }

    /* ------------------------------------------------------------------------------------------
     | 2. El corazón: encola en `database` y NO corre el pipeline adentro del request
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El test más importante del archivo.
     *
     * `Queue::fake()` prueba las dos mitades a la vez: que el job se despachó, y que NO se ejecutó
     * inline (si corriera inline, el fake no lo habría interceptado y la corrida no seguiría en
     * `pendiente`). El `return` del closure afirma la CONEXIÓN, que es lo que un `assertPushed`
     * pelado no mira.
     *
     * @return void
     */
    public function test_la_actualizacion_encola_en_la_conexion_database_y_no_corre_el_pipeline(): void
    {
        Queue::fake();

        $escenario = $this->escenario_listo('Panadería Rosa');

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $escenario['cliente']->id,
            'confirm_client_name' => 'Panadería Rosa',
        ], $this->headers());

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('mode', 'update');
        $respuesta->assertJsonPath('status', 'pendiente');
        $respuesta->assertJsonPath('created_via', 'claude');
        $respuesta->assertJsonPath('conexion_de_cola', 'database');

        /* 🔴 El `return` NO es decorativo: QueueFake::connection() devuelve $this sin mirar el
           nombre, así que sin comparar la propiedad esto pasaría con un dispatch pelado. */
        Queue::assertPushed(RunEcommerceInstallationJob::class, function ($job) {
            return $job->connection === 'database';
        });

        $corrida = ClientEcommerceInstallation::query()
            ->where('client_ecommerce_id', $escenario['tienda']->id)
            ->first();

        $this->assertNotNull($corrida);
        $this->assertSame('update', $corrida->mode);
        /* Si el pipeline hubiera corrido adentro del request, acá habría `instalando` o `fallida`. */
        $this->assertSame('pendiente', $corrida->status);
        $this->assertSame('claude', $corrida->created_via);
    }

    /* ------------------------------------------------------------------------------------------
     | 3. Los frenos del endpoint de a uno
     |----------------------------------------------------------------------------------------- */

    /**
     * El nombre equivocado rechaza, no escribe nada, y NO dice cuál era el nombre correcto: si lo
     * dijera dejaría de ser un freno y sería un formulario a completar.
     *
     * @return void
     */
    public function test_la_actualizacion_de_a_uno_con_el_nombre_equivocado_no_encola_nada_ni_revela_el_nombre(): void
    {
        Queue::fake();

        $escenario = $this->escenario_listo('Ferretería del Centro');
        $antes     = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $escenario['cliente']->id,
            'confirm_client_name' => 'Otro Negocio',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Ferretería del Centro', $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * Un cliente SIN nombre cargado no se puede confirmar, y el freno se mantiene cerrado: se dice
     * la causa real en vez de mentir con "el nombre no coincide".
     *
     * @return void
     */
    public function test_un_cliente_sin_nombre_no_se_puede_actualizar_de_a_uno(): void
    {
        Queue::fake();

        $this->cargar_credenciales_ssh();
        $cliente = $this->crear_cliente('');
        $this->crear_tienda($cliente);

        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'cualquier cosa',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('NO tiene nombre cargado', $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * Una tienda a medio configurar (sin spa_url / api_url / dominio) no arranca nada. Espeja
     * `EcommerceInstallationController::assert_ecommerce_is_configured()`.
     *
     * @return void
     */
    public function test_una_tienda_sin_configurar_no_encola_nada(): void
    {
        Queue::fake();

        $this->cargar_credenciales_ssh();
        $cliente = $this->crear_cliente('Kiosco Sin Configurar');
        $this->crear_tienda($cliente, false);

        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Kiosco Sin Configurar',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('falta configuración de la tienda', $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * Un cliente sin tienda creada tampoco arranca nada — y el error dice cómo registrarla (y que
     * registrarla no es instalarla).
     *
     * @return void
     */
    public function test_un_cliente_sin_tienda_no_encola_nada(): void
    {
        Queue::fake();

        $this->cargar_credenciales_ssh();
        $cliente = $this->crear_cliente('Cliente Sin Tienda');

        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Cliente Sin Tienda',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no tiene una tienda', $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * Sin credenciales SSH globales el job moriría adentro de connect_build_vps(): se corta antes,
     * con un 422 legible. Espeja `assert_deploy_prerequisites()`.
     *
     * @return void
     */
    public function test_sin_credenciales_ssh_no_se_encola_nada(): void
    {
        Queue::fake();

        $cliente = $this->crear_cliente('Tienda Sin Llaves');
        $this->crear_tienda($cliente);

        /* Después de crear el escenario: las credenciales son globales y la base del slot puede
           tenerlas sembradas. */
        ClientSshCredential::query()->delete();

        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $cliente->id,
            'confirm_client_name' => 'Tienda Sin Llaves',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('credenciales SSH', $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * Dos corridas sobre la misma tienda no se solapan: comparten el mismo pipeline SSH/SFTP.
     * Espeja `EcommerceInstallationController::assert_no_running_installation()`.
     *
     * @return void
     */
    public function test_una_tienda_con_una_corrida_en_curso_no_encola_otra(): void
    {
        Queue::fake();

        $escenario = $this->escenario_listo('Tienda Ocupada');

        ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $escenario['tienda']->id,
            'mode'                => 'update',
            'status'              => 'instalando',
        ]);

        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $escenario['cliente']->id,
            'confirm_client_name' => 'Tienda Ocupada',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya hay una corrida en curso', $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /* ------------------------------------------------------------------------------------------
     | 4. Los frenos del lote
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El lote simula por defecto: sin `dry_run` explícito no crea ninguna corrida ni encola nada.
     *
     * @return void
     */
    public function test_el_lote_de_ecommerce_simula_por_defecto_y_no_crea_ninguna_corrida(): void
    {
        Queue::fake();

        $a = $this->escenario_listo('Tienda A');
        $b = $this->escenario_listo('Tienda B');

        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$a['cliente']->id, $b['cliente']->id],
        ], $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('actualizarian', 2);
        $this->assertNotEmpty($respuesta->json('confirm_token'));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * `confirm_client_count` tiene que coincidir EXACTO con la cantidad real: un número de más o de
     * menos no crea nada.
     *
     * @return void
     */
    public function test_un_confirm_client_count_equivocado_en_el_lote_de_ecommerce_no_crea_nada(): void
    {
        Queue::fake();

        $a = $this->escenario_listo('Tienda Uno');
        $b = $this->escenario_listo('Tienda Dos');

        $simulacion = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$a['cliente']->id, $b['cliente']->id],
        ], $this->headers());

        $token = $simulacion->json('confirm_token');
        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids'           => [$a['cliente']->id, $b['cliente']->id],
            'dry_run'              => false,
            'confirm_client_count' => 5,
            'confirm_token'        => $token,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('actualizarian', 2);

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * 🔴 El token ata la confirmación al CONJUNTO, no sólo a la cantidad: simular con dos tiendas y
     * después confirmar OTRAS dos del mismo tamaño no pasa.
     *
     * @return void
     */
    public function test_un_token_de_otro_conjunto_no_habilita_el_lote(): void
    {
        Queue::fake();

        $a = $this->escenario_listo('Tienda Simulada A');
        $b = $this->escenario_listo('Tienda Simulada B');
        $c = $this->escenario_listo('Tienda Cambiada C');
        $d = $this->escenario_listo('Tienda Cambiada D');

        $simulacion = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$a['cliente']->id, $b['cliente']->id],
        ], $this->headers());

        $token_de_otro_conjunto = $simulacion->json('confirm_token');
        $antes                  = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids'           => [$c['cliente']->id, $d['cliente']->id],
            'dry_run'              => false,
            'confirm_client_count' => 2,
            'confirm_token'        => $token_de_otro_conjunto,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('confirm_token no corresponde', $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * 🔴 El lote NO acepta filtros: sólo `client_ids[]`. Un parámetro de más se rechaza en vez de
     * ignorarse en silencio, que es lo peligroso (el llamador creería haber filtrado).
     *
     * @return void
     */
    public function test_el_lote_de_ecommerce_no_acepta_filtros_solo_ids(): void
    {
        Queue::fake();

        $a     = $this->escenario_listo('Tienda Filtrada');
        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$a['cliente']->id],
            'status'     => 'active',
        ], $this->headers());

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('NO acepta filtros', $this->cuerpo($respuesta));
        $this->assertStringContainsString('status', $this->cuerpo($respuesta));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * Por encima del tope se rechaza el lote ENTERO, no se recorta: recortar dejaría corriendo un
     * subconjunto que nadie eligió.
     *
     * @return void
     */
    public function test_un_lote_de_ecommerce_por_encima_del_tope_se_rechaza_entero(): void
    {
        Queue::fake();

        $antes = $this->corridas_totales();
        $ids   = range(1, ClaudeEcommerceOpsController::MAX_LOTE_ECOMMERCE + 1);

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => $ids,
        ], $this->headers());

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('max_lote', ClaudeEcommerceOpsController::MAX_LOTE_ECOMMERCE);
        $respuesta->assertJsonPath('recibidos', count($ids));

        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->corridas_totales());
    }

    /**
     * El camino real del lote: crea las N corridas marcadas como de Claude y encola los N jobs en la
     * conexión `database`.
     *
     * @return void
     */
    public function test_el_lote_real_crea_las_corridas_y_las_encola_en_la_conexion_database(): void
    {
        Queue::fake();

        $a = $this->escenario_listo('Tienda Real A');
        $b = $this->escenario_listo('Tienda Real B');

        $simulacion = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$a['cliente']->id, $b['cliente']->id],
        ], $this->headers());

        $token = $simulacion->json('confirm_token');

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids'           => [$a['cliente']->id, $b['cliente']->id],
            'dry_run'              => false,
            'confirm_client_count' => 2,
            'confirm_token'        => $token,
        ], $this->headers());

        $respuesta->assertStatus(202);
        $respuesta->assertJsonPath('dry_run', false);
        $respuesta->assertJsonPath('creadas', 2);

        Queue::assertPushed(RunEcommerceInstallationJob::class, 2);
        Queue::assertPushed(RunEcommerceInstallationJob::class, function ($job) {
            return $job->connection === 'database';
        });

        $corridas = ClientEcommerceInstallation::query()
            ->whereIn('client_ecommerce_id', [$a['tienda']->id, $b['tienda']->id])
            ->get();

        $this->assertCount(2, $corridas);
        foreach ($corridas as $corrida) {
            $this->assertSame('update', $corrida->mode);
            $this->assertSame('pendiente', $corrida->status);
            $this->assertSame('claude', $corrida->created_via);
        }
    }

    /**
     * Cooldown del lote: una tienda que Claude actualizó hace menos de `COOLDOWN_HORAS_ECOMMERCE`
     * queda omitida. Lo que evita es el doble disparo del mismo lote.
     *
     * @return void
     */
    public function test_una_tienda_actualizada_por_claude_hace_poco_queda_omitida_del_lote(): void
    {
        Queue::fake();

        $a = $this->escenario_listo('Tienda Recién Actualizada');
        $b = $this->escenario_listo('Tienda Disponible');

        ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $a['tienda']->id,
            'mode'                => 'update',
            'status'              => 'completada',
            'created_via'         => ClientEcommerceInstallation::CREATED_VIA_CLAUDE,
        ]);

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$a['cliente']->id, $b['cliente']->id],
        ], $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('actualizarian', 1);

        $omitidos = $respuesta->json('omitidos');
        $this->assertCount(1, $omitidos);
        $this->assertSame($a['cliente']->id, $omitidos[0]['client_id']);
        $this->assertStringContainsString('hace menos de', $omitidos[0]['motivo']);
    }

    /**
     * 🔴 Un cliente sin nombre queda omitido del lote.
     *
     * No está en la lista de omisiones del plan y se agregó a propósito: el `confirm_token` del lote
     * reemplaza a `confirm_client_name` incorporando el nombre de cada cliente, y el endpoint de a
     * uno se niega en redondo a operar sobre un cliente sin nombre. Si el lote lo aceptara, sería la
     * puerta de al lado de ese freno: un lote de uno solo.
     *
     * @return void
     */
    public function test_un_cliente_sin_nombre_queda_omitido_del_lote(): void
    {
        Queue::fake();

        $this->cargar_credenciales_ssh();
        $sin_nombre = $this->crear_cliente('');
        $this->crear_tienda($sin_nombre);

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$sin_nombre->id],
        ], $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('actualizarian', 0);

        $omitidos = $respuesta->json('omitidos');
        $this->assertCount(1, $omitidos);
        $this->assertStringContainsString('no tiene nombre cargado', $omitidos[0]['motivo']);
    }

    /* ------------------------------------------------------------------------------------------
     | 5. La instalación inicial: UNA sola puerta, con sus frenos (decisión de Lucas, 2/10/2026)
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 La ÚNICA forma de crear una instalación inicial (`mode = 'install'`) por `claude/*` es
     * `POST claude/ecommerce/installs`, y sólo pasando sus frenos.
     *
     * Hasta el 2/10/2026 este test fijaba la regla vieja —"ninguna ruta claude/* crea una instalación
     * inicial"—. Ese día Lucas pidió explícitamente poder instalar una tienda por API, así que la
     * REGLA cambió por decisión del dueño (no se aflojó una aserción para que algo pase): este test
     * fija la nueva, con las mismas tres rejas de antes apuntadas a la puerta nueva:
     *  a) Por comportamiento: updates y su lote siguen creando sólo `mode = update`; installs sin
     *     `dry_run=false` no crea nada; con `dry_run=false` crea exactamente UNA corrida `install`.
     *  b) Por fuente, en TODOS los controladores de las rutas `api/claude/*` (y sus traits y padres de
     *     app/), no sólo en éste: `self::MODO_INSTALACION` y el modo `'install'` sólo adentro de
     *     `installs_json()`; UN solo `ClientEcommerceInstallation::create(`, adentro de `crear_corrida()`;
     *     ningún otro camino de alta (`new`, `firstOrCreate`, `DB::table(...)->insert`, SQL crudo); y
     *     todo `crear_corrida()` fuera de installs con `self::MODO_ACTUALIZACION`. Ver
     *     `puertas_de_instalacion_fuera_de_installs()`.
     *  c) Por ruteo: ninguna ruta `api/claude/*` apunta a `EcommerceInstallationController` (el
     *     controlador del panel), y la única que llega a `installs_json` es
     *     `POST api/claude/ecommerce/installs`.
     *
     * @return void
     */
    public function test_la_unica_instalacion_inicial_por_claude_es_installs_con_sus_frenos(): void
    {
        Queue::fake();

        EcommerceVersion::query()->delete();
        EcommerceVersion::create(['version' => '1.0.0', 'status' => 'published', 'published_at' => now()]);
        if (! EnvTemplate::where('scope', 'tienda')->exists()) {
            EnvTemplate::create(['key' => 'APP_ENV', 'value' => 'production', 'scope' => 'tienda']);
        }

        /* (a) Comportamiento. */
        $a = $this->escenario_listo('Tienda Regla A');
        $b = $this->escenario_listo('Tienda Regla B');
        $c = $this->escenario_listo('Tienda Regla Install');

        $api               = new ClientApi();
        $api->client_id    = $c['cliente']->id;
        $api->url          = 'https://api-regla-install.test';
        $api->path         = 'regla/' . Str::random(6);
        $api->hosting_type = 'shared_hosting';
        $api->save();
        $c['cliente']->active_client_api_id = $api->id;
        $c['cliente']->save();
        $c['tienda']->update(['status' => 'pending']);

        $instalaciones_antes = ClientEcommerceInstallation::query()->where('mode', 'install')->count();

        $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $a['cliente']->id,
            'confirm_client_name' => 'Tienda Regla A',
        ], $this->headers())->assertStatus(202);

        $simulacion = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$b['cliente']->id],
        ], $this->headers());

        $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids'           => [$b['cliente']->id],
            'dry_run'              => false,
            'confirm_client_count' => 1,
            'confirm_token'        => $simulacion->json('confirm_token'),
        ], $this->headers())->assertStatus(202);

        $this->assertSame(
            $instalaciones_antes,
            ClientEcommerceInstallation::query()->where('mode', 'install')->count(),
            'updates o su lote crearon una instalación inicial.'
        );

        $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $c['cliente']->id,
            'confirm_client_name' => 'Tienda Regla Install',
        ], $this->headers())->assertStatus(200)->assertJsonPath('dry_run', true);

        $this->assertSame(
            $instalaciones_antes,
            ClientEcommerceInstallation::query()->where('mode', 'install')->count(),
            'installs creó una instalación sin dry_run=false.'
        );

        $this->postJson('/api/claude/ecommerce/installs', [
            'client_id'           => $c['cliente']->id,
            'confirm_client_name' => 'Tienda Regla Install',
            'dry_run'             => false,
        ], $this->headers())->assertStatus(202)->assertJsonPath('mode', 'install');

        $this->assertSame($instalaciones_antes + 1, ClientEcommerceInstallation::query()->where('mode', 'install')->count());
        $this->assertSame(
            1,
            ClientEcommerceInstallation::query()->where('mode', 'install')->where('client_ecommerce_id', $c['tienda']->id)->count()
        );

        /* (b) Fuente: TODOS los controladores de las rutas api/claude/* (más sus traits y padres de
           app/), no sólo éste. Rechequeo independiente del 2/10/2026: la versión anterior de esta reja
           miraba un solo archivo, y otra puerta en otro controlador no la veía. */
        $this->assertSame(['install', 'update'], ClaudeEcommerceOpsController::MODES);
        $this->assertSame('update', ClaudeEcommerceOpsController::MODO_ACTUALIZACION);
        $this->assertSame('install', ClaudeEcommerceOpsController::MODO_INSTALACION);

        /* Que la reja mire de verdad: si el escaneo no encontrara archivos, no podría fallar nunca. */
        $archivos = array_map('basename', $this->archivos_de_claude());
        $this->assertContains('ClaudeEcommerceOpsController.php', $archivos);
        $this->assertContains('ClaudeUpgradeOpsController.php', $archivos);
        $this->assertContains('RespuestasParaClaude.php', $archivos, 'El escaneo no sigue los traits de los controladores.');
        $this->assertGreaterThanOrEqual(20, count($archivos));

        /* La única alta: crear_corrida(), con el modo como parámetro validado contra MODES. */
        $fuente = $this->codigo_sin_comentarios(app_path('Http/Controllers/Api/ClaudeEcommerceOpsController.php'));
        $encontrado = preg_match("/ClientEcommerceInstallation::create\(\[(.*?)\]\);/s", $fuente, $coincidencia);
        $this->assertSame(1, $encontrado, 'No se encontró la creación de la corrida: revisá el regex.');
        $this->assertMatchesRegularExpression('/\'mode\'\s*=>\s*\$modo\b/', $coincidencia[1]);
        $this->assertStringContainsString('in_array($modo, self::MODES, true)', $fuente, 'crear_corrida() dejó de validar el modo.');

        $violaciones = $this->puertas_de_instalacion_fuera_de_installs();
        $this->assertSame([], $violaciones, 'Hay otra puerta para crear instalaciones por claude/*: ' . implode(' | ', $violaciones));

        /* (c) Ruteo. */
        $rutas_a_installs = [];
        foreach (Route::getRoutes() as $ruta) {
            if (strpos($ruta->uri(), 'api/claude/') !== 0) {
                continue;
            }

            $accion = $ruta->getActionName();
            $this->assertStringNotContainsString(
                'EcommerceInstallationController',
                $accion,
                'La ruta ' . $ruta->uri() . ' apunta al controlador del panel.'
            );

            if (substr($accion, -strlen('@installs_json')) === '@installs_json') {
                $rutas_a_installs[] = implode('|', $ruta->methods()) . ' ' . $ruta->uri();
            }
        }
        $this->assertSame(['POST api/claude/ecommerce/installs'], $rutas_a_installs);
    }

    /* ------------------------------------------------------------------------------------------
     | 6. Lectura: logs truncados y salud honesta
     |----------------------------------------------------------------------------------------- */

    /**
     * Los logs se truncan y la respuesta lo DECLARA: una salida cortada que no se anuncia se lee
     * como si fuera completa, y los pasos de compilación traen la salida cruda de `npm run build`.
     *
     * @return void
     */
    public function test_los_logs_de_una_corrida_se_truncan_y_lo_declaran(): void
    {
        $escenario = $this->escenario_listo('Tienda Con Logs');

        $corrida = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $escenario['tienda']->id,
            'mode'                => 'update',
            'status'              => 'completada',
        ]);

        $corrida->add_log('compile_spa', str_repeat('x', 3000), 'info');

        $respuesta = $this->getJson(
            '/api/claude/ecommerce/installations/' . $corrida->id . '/logs?max_line_chars=100',
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('max_line_chars', 100);
        $respuesta->assertJsonPath('data.0.truncada', true);
        $respuesta->assertJsonPath('data.0.largo_original', 3000);
        $this->assertSame(100, mb_strlen($respuesta->json('data.0.line')));
    }

    /**
     * 🔴 Una corrida colgada se reporta como stale Y la respuesta dice la verdad incómoda: acá NO
     * hay ningún proceso que la destrabe, a diferencia de los deployments de empresa.
     *
     * @return void
     */
    public function test_una_corrida_colgada_se_reporta_como_stale_y_dice_que_nadie_la_destraba(): void
    {
        $escenario = $this->escenario_listo('Tienda Colgada');

        $corrida = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $escenario['tienda']->id,
            'mode'                => 'update',
            'status'              => 'instalando',
        ]);
        $corrida->started_at = now()->subMinutes(40);
        $corrida->created_at = now()->subMinutes(40);
        $corrida->save();

        $respuesta = $this->getJson('/api/claude/ecommerce/installations/' . $corrida->id, $this->headers());

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('salud.corrida_stale', true);
        $respuesta->assertJsonPath('salud.stale_minutos', 15);
        $this->assertStringContainsString('A MANO', $respuesta->json('salud.nota'));
        $this->assertStringContainsString('vencer-colgados', $respuesta->json('salud.nota'));

        /* Y la corrida sigue igual: la salud REPORTA, no toca nada. */
        $this->assertSame('instalando', $corrida->refresh()->status);
    }

    /**
     * El listado de corridas filtra por cliente y RECORTA `failure_reason`, declarándolo: el motivo
     * de fallo de un pipeline trae la salida cruda de `npm run build` y una página de 100 corridas
     * con eso adentro no entra en ninguna ventana de contexto.
     *
     * @return void
     */
    public function test_el_listado_de_corridas_filtra_por_cliente_y_recorta_el_motivo_de_fallo(): void
    {
        $mio  = $this->escenario_listo('Tienda Propia');
        $otro = $this->escenario_listo('Tienda Ajena');

        $corrida = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $mio['tienda']->id,
            'mode'                => 'update',
            'status'              => 'fallida',
            'created_via'         => ClientEcommerceInstallation::CREATED_VIA_CLAUDE,
            'failure_reason'      => str_repeat('e', 1200),
        ]);

        ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $otro['tienda']->id,
            'mode'                => 'update',
            'status'              => 'completada',
        ]);

        $respuesta = $this->getJson(
            '/api/claude/ecommerce/installations?client_id=' . $mio['cliente']->id,
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('count', 1);
        $respuesta->assertJsonPath('data.0.id', $corrida->id);
        $respuesta->assertJsonPath('data.0.client_id', $mio['cliente']->id);
        $respuesta->assertJsonPath('data.0.created_via', 'claude');
        $respuesta->assertJsonPath('data.0.failure_reason_truncada', true);
        $this->assertSame(500, mb_strlen($respuesta->json('data.0.failure_reason')));

        /* Una fecha impar se rechaza en vez de ignorarse: un filtro descartado en silencio devuelve
           MÁS filas de las pedidas, que es el error caro acá. */
        $this->getJson('/api/claude/ecommerce/installations?desde=el+martes', $this->headers())
            ->assertStatus(422);
    }

    /**
     * 🔴 Una fecha que NO ES UNA FECHA se rechaza con 422, aunque `Carbon::parse()` la acepte.
     *
     * El agujero medido: `desde=x` no hacía saltar ningún error. `Carbon::parse('x')` no lanza nada
     * y devuelve la fecha y hora de AHORA, así que el `if ($desde === null)` que promete el 422 nunca
     * se cumplía y la consulta salía filtrada por `created_at >= <ahora>` — cero filas, siempre, sin
     * que nadie se enterara de que había un filtro puesto. Es la misma familia que ya se arregló en
     * el filtro `fecha_desde` de `GET claude/query`, y por eso este endpoint usa LA MISMA función
     * (`ClaudeQueryService::fecha_estricta()`) y no una copia: dos definiciones de "qué es una fecha
     * válida" se desincronizan y arreglar una deja la otra rota.
     *
     * Los cuatro casos de abajo son los cuatro que `Carbon::parse()` deja pasar: basura suelta, una
     * expresión relativa, un día que no existe y la fecha cero de MySQL.
     *
     * @return void
     */
    public function test_una_fecha_invalida_en_el_listado_de_corridas_es_422_y_no_filtra_por_ahora(): void
    {
        $escenario = $this->escenario_listo('Tienda Con Historial');

        $corrida = ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $escenario['tienda']->id,
            'mode'                => 'update',
            'status'              => 'completada',
        ]);

        /* Control: sin filtro, la corrida está. Si el 422 no llegara, el filtro por "ahora" la
           escondería y la respuesta sería un 200 con cero filas — el desenlace silencioso. */
        $this->getJson('/api/claude/ecommerce/installations', $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $corrida->id);

        $impares = ['x', 'next monday', '2026-02-30', '0000-00-00'];

        foreach ($impares as $impar) {
            foreach (['desde', 'hasta'] as $parametro) {
                $respuesta = $this->getJson(
                    '/api/claude/ecommerce/installations?' . $parametro . '=' . urlencode($impar),
                    $this->headers()
                );

                $respuesta->assertStatus(422);
                $this->assertStringContainsString(
                    'no es una fecha válida',
                    $this->cuerpo($respuesta),
                    'El parámetro ' . $parametro . '=' . $impar . ' no fue rechazado.'
                );

                /* El 422 dice qué SÍ se acepta, con los mismos ejemplos que publica GET claude/query. */
                $this->assertNotEmpty($respuesta->json('formatos_validos'));
            }
        }

        /* Y una fecha bien escrita sigue filtrando como corresponde. */
        $this->getJson('/api/claude/ecommerce/installations?desde=2000-01-01', $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('count', 1);

        $this->getJson('/api/claude/ecommerce/installations?hasta=2000-01-01', $this->headers())
            ->assertStatus(200)
            ->assertJsonPath('count', 0);
    }

    /**
     * `GET claude/ecommerce/stores` publica `puede_actualizarse` con el motivo, y lo calcula con el
     * MISMO método que después usa el POST: si fueran dos cálculos, el listado diría "sí" y el POST
     * contestaría 422.
     *
     * @return void
     */
    public function test_stores_dice_por_que_una_tienda_no_se_puede_actualizar(): void
    {
        $escenario = $this->escenario_listo('Tienda Trabada');

        ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $escenario['tienda']->id,
            'mode'                => 'update',
            'status'              => 'instalando',
        ]);

        $respuesta = $this->getJson(
            '/api/claude/ecommerce/stores?client_id=' . $escenario['cliente']->id,
            $this->headers()
        );

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('count', 1);
        $respuesta->assertJsonPath('data.0.puede_actualizarse', false);
        $this->assertStringContainsString('ya hay una corrida en curso', $respuesta->json('data.0.motivo'));
        $respuesta->assertJsonPath('data.0.ultima_corrida.status', 'instalando');

        /* Y el POST coincide con lo que el listado dijo. */
        $post = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $escenario['cliente']->id,
            'confirm_client_name' => 'Tienda Trabada',
        ], $this->headers());

        $post->assertStatus(422);
    }

    /* ------------------------------------------------------------------------------------------
     | 7. La ventana `pendiente`: el freno que no cubría el reintento
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 Dos POST seguidos sobre la misma tienda: el segundo rebota sin crear nada.
     *
     * El caso que lo originó: se pide la actualización del cliente X, el HTTP da timeout del lado
     * del que llama, y se reintenta dentro del minuto. La corrida nace en `pendiente` y el worker
     * tarda hasta `LATENCIA_MAXIMA_SEGUNDOS` (60) en levantarla, así que un freno que mira sólo
     * `instalando` no ve NADA y crea una SEGUNDA corrida con su segundo job sobre la misma tienda.
     * Las dos pelean por el lock del clone de `tienda-spa` en el VPS de builds — la misma contención
     * de la que `MAX_LOTE_ECOMMERCE = 5` deriva su número.
     *
     * `Queue::fake()` es justamente lo que reproduce la ventana: el job queda encolado y la corrida
     * se queda en `pendiente`, que es el estado real durante el minuto de latencia.
     *
     * @return void
     */
    public function test_un_segundo_post_dentro_de_la_ventana_pendiente_rebota_sin_crear_nada(): void
    {
        Queue::fake();

        $escenario = $this->escenario_listo('Tienda Reintentada');

        $primero = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $escenario['cliente']->id,
            'confirm_client_name' => 'Tienda Reintentada',
        ], $this->headers());

        $primero->assertStatus(202);
        $primero->assertJsonPath('status', 'pendiente');

        $despues_del_primero = $this->corridas_totales();

        /* El reintento, con la primera corrida todavía en `pendiente` y sin worker que la haya
           tomado. Antes de este arreglo devolvía 202 y creaba una segunda corrida. */
        $segundo = $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $escenario['cliente']->id,
            'confirm_client_name' => 'Tienda Reintentada',
        ], $this->headers());

        $segundo->assertStatus(422);
        $this->assertStringContainsString('ya hay una corrida en curso', $this->cuerpo($segundo));

        $this->assertSame($despues_del_primero, $this->corridas_totales(), 'El reintento no puede crear una segunda corrida.');
        Queue::assertPushed(RunEcommerceInstallationJob::class, 1);

        /* Los tres caminos dicen lo mismo: el listado también la marca ocupada. */
        $stores = $this->getJson(
            '/api/claude/ecommerce/stores?client_id=' . $escenario['cliente']->id,
            $this->headers()
        )->assertStatus(200);

        $stores->assertJsonPath('data.0.puede_actualizarse', false);
        $this->assertStringContainsString('ya hay una corrida en curso', $stores->json('data.0.motivo'));

        /* Y el lote, que es el tercero. */
        $lote = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => [$escenario['cliente']->id],
        ], $this->headers())->assertStatus(200);

        $this->assertSame(0, $lote->json('actualizarian'));
        $this->assertStringContainsString('ya hay una corrida en curso', $this->cuerpo($lote));
    }

    /**
     * 🔴 El lote de ecommerce también contesta en español.
     *
     * `mensajes_de_validacion()` del trait no traía `array`, así que `client_ids: "x"` caía a
     * `resources/lang/en` y devolvía "The client ids must be an array." Es el mismo defecto que el
     * `exists` del lote de empresa: el trait era la lista incompleta.
     *
     * @return void
     */
    public function test_el_lote_de_ecommerce_contesta_en_espanol_cuando_client_ids_no_es_una_lista(): void
    {
        $antes = $this->corridas_totales();

        $respuesta = $this->postJson('/api/claude/ecommerce/updates/batch', [
            'client_ids' => 'x',
        ], $this->headers())->assertStatus(422);

        $texto = $this->cuerpo($respuesta);

        $this->assertStringContainsString('tiene que ser una lista', $texto);
        $this->assertStringNotContainsString('must be an array', $texto);
        $this->assertSame($antes, $this->corridas_totales());
    }

    /* ------------------------------------------------------------------------------------------
     | La reja de fuente de la regla de instalación (rechequeo independiente del 2/10/2026)
     |----------------------------------------------------------------------------------------- */

    /**
     * Archivos de app/ que atienden rutas api/claude/*: los controladores (por las rutas y por el
     * nombre Claude*.php) y, de cada uno, sus traits y sus clases padre que vivan en app/.
     *
     * @return array<int, string> Rutas absolutas, sin repetir.
     */
    private function archivos_de_claude(): array
    {
        $clases = [];
        foreach (Route::getRoutes() as $ruta) {
            if (strpos($ruta->uri(), 'api/claude/') !== 0) {
                continue;
            }
            $accion = $ruta->getActionName();
            if (strpos($accion, '@') !== false) {
                $clases[] = substr($accion, 0, strpos($accion, '@'));
            }
        }
        foreach ((array) glob(app_path('Http/Controllers/Api/Claude*.php')) as $archivo) {
            $clases[] = 'App\\Http\\Controllers\\Api\\' . basename($archivo, '.php');
        }

        $archivos  = [];
        $pendientes = array_values(array_unique($clases));
        $vistas     = [];
        while (! empty($pendientes)) {
            $clase = array_shift($pendientes);
            if (isset($vistas[$clase]) || (! class_exists($clase) && ! trait_exists($clase))) {
                continue;
            }
            $vistas[$clase] = true;

            $reflexion = new \ReflectionClass($clase);
            $archivo   = (string) $reflexion->getFileName();
            if ($archivo === '' || strpos(str_replace('\\', '/', $archivo), str_replace('\\', '/', app_path())) !== 0) {
                continue;
            }
            $archivos[$archivo] = true;

            foreach ($reflexion->getTraitNames() as $trait) {
                $pendientes[] = $trait;
            }
            if ($reflexion->getParentClass() !== false) {
                $pendientes[] = $reflexion->getParentClass()->getName();
            }
        }

        return array_keys($archivos);
    }

    /**
     * Contenido de un archivo con las líneas de comentario en blanco (se conservan los saltos para
     * que los números de línea sigan valiendo): un docblock que HABLA de la regla no la rompe.
     *
     * @param string $archivo
     *
     * @return string
     */
    private function codigo_sin_comentarios(string $archivo): string
    {
        $salida = '';
        foreach ((array) file($archivo) as $linea) {
            $recortada = ltrim((string) $linea);
            $es_comentario = strpos($recortada, '*') === 0 || strpos($recortada, '/*') === 0 || strpos($recortada, '//') === 0;
            $salida .= $es_comentario ? "\n" : rtrim((string) $linea, "\r\n") . "\n";
        }

        return $salida;
    }

    /**
     * Número de línea (desde 1) de un offset del texto.
     *
     * @param string $texto
     * @param int    $offset
     *
     * @return int
     */
    private function linea_de(string $texto, int $offset): int
    {
        return substr_count(substr($texto, 0, $offset), "\n") + 1;
    }

    /**
     * Argumentos de primer nivel de una llamada, a partir del paréntesis que la abre.
     *
     * @param string $texto
     * @param int    $abre  Offset del "(".
     *
     * @return array<int, string>
     */
    private function argumentos_de_la_llamada(string $texto, int $abre): array
    {
        $profundidad = 0;
        $actual      = '';
        $argumentos  = [];
        for ($i = $abre; $i < strlen($texto); $i++) {
            $c = $texto[$i];
            if ($c === '(' || $c === '[') {
                $profundidad++;
                if ($profundidad === 1) {
                    continue;
                }
            } elseif ($c === ')' || $c === ']') {
                $profundidad--;
                if ($profundidad === 0) {
                    $argumentos[] = trim($actual);
                    break;
                }
            } elseif ($c === ',' && $profundidad === 1) {
                $argumentos[] = trim($actual);
                $actual       = '';
                continue;
            }
            $actual .= $c;
        }

        return $argumentos;
    }

    /**
     * Todo lo que, en los archivos de claude/*, puede crear una corrida de instalación por fuera de
     * `installs_json()`. Vacío = la única puerta es installs, pasando por `crear_corrida()`.
     *
     * Se marca:
     *  - `self::MODO_INSTALACION` fuera de installs_json (salvo su propia declaración);
     *  - `'install'` usado como modo (`'mode' => 'install'`, `->mode = 'install'`) en cualquier lado
     *    fuera de installs_json, y en ClaudeEcommerceOpsController cualquier `'install'` fuera de las
     *    declaraciones de constantes y de installs_json;
     *  - `ClientEcommerceInstallation::create(` en cualquier lado que no sea `crear_corrida()` (y que
     *    haya exactamente uno), `new ClientEcommerceInstallation`, `forceCreate`/`firstOrCreate`/
     *    `updateOrCreate`/`insert` sobre el modelo, `installations()->create/save`, y
     *    `DB::table('client_ecommerce_installations')` con insert/upsert, o un `insert into` crudo;
     *  - cualquier `->crear_corrida(` fuera de installs_json cuyo modo no sea `self::MODO_ACTUALIZACION`
     *    (y dentro de installs_json, cuyo modo no sea `self::MODO_INSTALACION`).
     *
     * @return array<int, string> Descripción de cada violación ("archivo:línea qué").
     */
    private function puertas_de_instalacion_fuera_de_installs(): array
    {
        $installs  = new \ReflectionMethod(ClaudeEcommerceOpsController::class, 'installs_json');
        $crear     = new \ReflectionMethod(ClaudeEcommerceOpsController::class, 'crear_corrida');
        $principal = str_replace('\\', '/', (string) $installs->getFileName());

        $dentro = function (string $archivo, int $linea, \ReflectionMethod $metodo) use ($principal): bool {
            return str_replace('\\', '/', $archivo) === $principal
                && $linea >= $metodo->getStartLine() && $linea <= $metodo->getEndLine();
        };

        $violaciones = [];
        $creates     = 0;

        foreach ($this->archivos_de_claude() as $archivo) {
            $codigo  = $this->codigo_sin_comentarios($archivo);
            $nombre  = basename($archivo);
            $lineas  = explode("\n", $codigo);
            $es_principal = str_replace('\\', '/', $archivo) === $principal;

            foreach ($lineas as $i => $texto) {
                $numero = $i + 1;

                if (strpos($texto, 'MODO_INSTALACION') !== false
                    && strpos($texto, 'const MODO_INSTALACION') === false
                    && ! $dentro($archivo, $numero, $installs)) {
                    $violaciones[] = $nombre . ':' . $numero . ' usa MODO_INSTALACION fuera de installs_json';
                }

                if (preg_match('/[\'"]mode[\'"]\s*=>\s*[\'"]install[\'"]|->mode\s*=\s*[\'"]install[\'"]/', $texto) === 1
                    && ! $dentro($archivo, $numero, $installs)) {
                    $violaciones[] = $nombre . ':' . $numero . " escribe mode 'install'";
                }

                if ($es_principal && preg_match('/[\'"]install[\'"]/', $texto) === 1
                    && strpos(ltrim($texto), 'const ') !== 0
                    && ! $dentro($archivo, $numero, $installs)) {
                    $violaciones[] = $nombre . ':' . $numero . " usa el literal 'install' fuera de installs_json";
                }

                if (strpos($texto, 'ClientEcommerceInstallation::create(') !== false) {
                    $creates++;
                    if (! $dentro($archivo, $numero, $crear)) {
                        $violaciones[] = $nombre . ':' . $numero . ' crea corridas fuera de crear_corrida()';
                    }
                }

                if (preg_match('/new\s+[\\\\\w]*ClientEcommerceInstallation\b|ClientEcommerceInstallation::(forceCreate|firstOrCreate|updateOrCreate|insert|insertGetId|upsert)\(|installations\(\)->(create|save|forceCreate|firstOrCreate|updateOrCreate)\(/', $texto) === 1) {
                    $violaciones[] = $nombre . ':' . $numero . ' crea una corrida por otro camino';
                }

                if (preg_match('/insert\s+into\s+`?client_ecommerce_installations/i', $texto) === 1) {
                    $violaciones[] = $nombre . ':' . $numero . ' inserta corridas con SQL crudo';
                }
            }

            /* DB::table(...) sobre la tabla: se mira la sentencia entera (hasta el `;`), que puede
               ocupar varias líneas. Las lecturas son legítimas; un insert/upsert no. */
            $desde = 0;
            while (($pos = strpos($codigo, "DB::table('client_ecommerce_installations", $desde)) !== false) {
                $fin       = strpos($codigo, ';', $pos);
                $sentencia = substr($codigo, $pos, $fin === false ? null : $fin - $pos);
                if (preg_match('/->(insert|insertGetId|insertOrIgnore|upsert|updateOrInsert)\(/', $sentencia) === 1) {
                    $violaciones[] = $nombre . ':' . $this->linea_de($codigo, $pos) . ' inserta corridas con DB::table';
                }
                $desde = $pos + 1;
            }

            /* Cada llamada a crear_corrida(): el segundo argumento es el modo. */
            $desde = 0;
            while (($pos = strpos($codigo, '->crear_corrida(', $desde)) !== false) {
                $linea      = $this->linea_de($codigo, $pos);
                $argumentos = $this->argumentos_de_la_llamada($codigo, $pos + strlen('->crear_corrida'));
                $modo       = isset($argumentos[1]) ? $argumentos[1] : '';
                $esperado   = $dentro($archivo, $linea, $installs) ? 'self::MODO_INSTALACION' : 'self::MODO_ACTUALIZACION';
                if ($modo !== $esperado) {
                    $violaciones[] = $nombre . ':' . $linea . ' llama crear_corrida() con modo «' . $modo . '» (se esperaba ' . $esperado . ')';
                }
                $desde = $pos + 1;
            }
        }

        if ($creates !== 1) {
            $violaciones[] = 'hay ' . $creates . ' ClientEcommerceInstallation::create( en claude/* (tiene que haber exactamente uno, en crear_corrida())';
        }

        return $violaciones;
    }
}

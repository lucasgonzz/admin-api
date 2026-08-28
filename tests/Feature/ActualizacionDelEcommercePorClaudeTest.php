<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ClaudeEcommerceOpsController;
use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
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
 *  2. 🔴 Que NINGUNA ruta `claude/*` cree una instalación inicial (`mode = 'install'`). Es una
 *     decisión de Lucas y acá tiene su reja: se verifica por comportamiento Y leyendo el fuente del
 *     controlador.
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
     * Un cliente sin tienda creada tampoco arranca nada — y el error dice explícitamente que Claude
     * no hace la instalación inicial.
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
     | 5. La decisión de Lucas: nada de instalación inicial
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 NINGUNA ruta `claude/*` crea una `ClientEcommerceInstallation` con `mode = 'install'`.
     *
     * Tres rejas, porque una sola se puede saltear sin querer:
     *  a) Por comportamiento: se ejercitan los dos endpoints de escritura y no aparece ninguna fila
     *     con `mode = 'install'`.
     *  b) Por fuente: todo `'mode' =>` del controlador tiene que ser `self::MODO_ACTUALIZACION`.
     *     Es la que agarra al que mañana copie y pegue un método del panel.
     *  c) Por ruteo: ninguna ruta `api/claude/*` puede apuntar a `EcommerceInstallationController`,
     *     que es el controlador del panel y sí instala desde cero.
     *
     * @return void
     */
    public function test_ninguna_ruta_claude_crea_una_instalacion_inicial(): void
    {
        Queue::fake();

        /* (a) Comportamiento. */
        $a = $this->escenario_listo('Tienda Sin Install A');
        $b = $this->escenario_listo('Tienda Sin Install B');

        $instalaciones_antes = ClientEcommerceInstallation::query()->where('mode', 'install')->count();

        $this->postJson('/api/claude/ecommerce/updates', [
            'client_id'           => $a['cliente']->id,
            'confirm_client_name' => 'Tienda Sin Install A',
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
            'Alguna ruta claude/* creó una instalación inicial de ecommerce.'
        );

        /* (b) Fuente: el único `mode` que se escribe es la constante de actualización.

           Se sacan las líneas de comentario antes de buscar. No es cosmética: el propio docblock de
           la clase HABLA de `'mode' =>` para explicar la regla, y sin este filtro el test rompía por
           su propia documentación — que es la forma más tonta de que una reja termine borrada por
           molesta en vez de arreglada. */
        $fuente = file_get_contents(app_path('Http/Controllers/Api/ClaudeEcommerceOpsController.php'));
        $this->assertNotFalse($fuente);

        $solo_codigo = [];
        foreach (explode("\n", $fuente) as $linea) {
            $recortada = ltrim($linea);
            if ($recortada === '' || strpos($recortada, '*') === 0 || strpos($recortada, '/*') === 0 || strpos($recortada, '//') === 0) {
                continue;
            }
            $solo_codigo[] = $linea;
        }
        $fuente = implode("\n", $solo_codigo);

        /* Toda la escritura de corridas está centralizada en un solo `create()`: si mañana aparece
           un segundo, esta aserción rompe y obliga a mirarlo. Es la reja que agarra al que copie y
           pegue un método de EcommerceInstallationController, que es de donde saldría un `install`. */
        $this->assertSame(
            1,
            substr_count($fuente, 'ClientEcommerceInstallation::create('),
            'Hay más de un lugar que crea corridas de ecommerce: la regla de "sólo update" tiene que poder '
                . 'verificarse en uno solo.'
        );

        $encontrado = preg_match("/ClientEcommerceInstallation::create\(\[(.*?)\]\);/s", $fuente, $coincidencia);
        $this->assertSame(1, $encontrado, 'No se encontró la creación de la corrida: revisá el regex.');
        $this->assertMatchesRegularExpression(
            "/'mode'\s*=>\s*self::MODO_ACTUALIZACION/",
            $coincidencia[1],
            'ClaudeEcommerceOpsController escribe un `mode` que no es la constante de actualización.'
        );

        $this->assertSame('update', ClaudeEcommerceOpsController::MODO_ACTUALIZACION);

        /* (c) Ruteo: el controlador del panel (que sí instala desde cero) no está colgado de claude/*. */
        foreach (Route::getRoutes() as $ruta) {
            if (strpos($ruta->uri(), 'api/claude/') !== 0) {
                continue;
            }

            $accion = $ruta->getActionName();
            $this->assertStringNotContainsString(
                'EcommerceInstallationController',
                $accion,
                'La ruta ' . $ruta->uri() . ' apunta al controlador del panel, que instala tiendas desde cero.'
            );
        }
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
}

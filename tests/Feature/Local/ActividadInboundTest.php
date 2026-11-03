<?php

namespace Tests\Feature\Local;

use App\Helpers\AppTime;
use App\Models\Client;
use App\Models\LocalActividadCliente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST api/inbound/local/actividad` (misión el-local, 3/11/2026, contrato C): el sistema de cada cliente
 * avisa inicios de sesión, uso, escaneos y respuestas del agente del negocio. Su emisor llega en la Fase 2;
 * este test fija el contrato del lado del admin para que esa misión toque solo empresa.
 *
 * Lo que importa: la clave `X-Admin-Api-Key` es obligatoria siempre y es lo ÚNICO que identifica al cliente;
 * un evento repetido no se duplica (ni dentro del lote, ni en un reintento, ni mandado en otra zona horaria,
 * ni con el usuario vacío); un evento inválido no deja guardado ninguno; y la poda borra lo de más de 7 días.
 */
class ActividadInboundTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Cliente activo con su clave de entrada.
     *
     * @param string $nombre
     *
     * @return Client
     */
    private function negocio(string $nombre = 'Ferre de prueba'): Client
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->phone           = '+5493417770' . random_int(100, 999);
        $client->is_active       = true;
        $client->inbound_api_key = Str::random(40);
        $client->save();

        return $client;
    }

    /**
     * Header con la clave del cliente.
     *
     * @param Client $client
     *
     * @return array<string, string>
     */
    private function headers(Client $client): array
    {
        return ['X-Admin-Api-Key' => $client->inbound_api_key, 'Accept' => 'application/json'];
    }

    /**
     * Tres eventos como los que mandará la Fase 2.
     *
     * @return array<int, array<string, mixed>>
     */
    private function eventos(): array
    {
        return [
            ['tipo' => 'login', 'ocurrido_at' => '2026-11-03T13:40:12-03:00', 'usuario' => 'Pablo', 'detalle' => null],
            ['tipo' => 'escaneo', 'ocurrido_at' => '2026-11-03T13:45:00-03:00', 'usuario' => 'Pablo', 'detalle' => ['comprobante' => 'FA-0001']],
            ['tipo' => 'agente_negocio', 'ocurrido_at' => '2026-11-03T16:50:00Z', 'usuario' => null, 'detalle' => []],
        ];
    }

    /**
     * Sin clave, con una clave inválida o con la de un cliente inactivo: 401 y nada guardado. 🔴 Aunque la
     * integración legacy esté sin exigir clave y el body traiga un `client_uuid` válido.
     *
     * @return void
     */
    public function test_sin_clave_valida_devuelve_401_aun_con_la_integracion_legacy_apagada(): void
    {
        config(['services.admin_inbound_integration.require_api_key' => false]);
        $client = $this->negocio();

        $this->postJson('/api/inbound/local/actividad', ['eventos' => $this->eventos()])->assertStatus(401);
        $this->postJson('/api/inbound/local/actividad', ['client_uuid' => $client->uuid, 'eventos' => $this->eventos()])->assertStatus(401);
        $this->postJson('/api/inbound/local/actividad', ['eventos' => $this->eventos()], ['X-Admin-Api-Key' => 'invalida'])->assertStatus(401);

        $client->is_active = false;
        $client->save();
        $this->postJson('/api/inbound/local/actividad', ['eventos' => $this->eventos()], $this->headers($client))->assertStatus(401);

        $this->assertSame(0, LocalActividadCliente::query()->where('client_id', $client->id)->count());
    }

    /**
     * Con la clave: 200 `{ok: true, guardados: 3}` y tres filas del cliente de la clave, con la hora pasada a
     * la del admin (la que vino en UTC también).
     *
     * @return void
     */
    public function test_con_clave_guarda_los_eventos_del_cliente_de_la_clave(): void
    {
        $client = $this->negocio();
        $otro   = $this->negocio('Otro negocio');

        $respuesta = $this->postJson('/api/inbound/local/actividad', [
            // Un client_uuid ajeno en el body no cambia nada: el cliente sale de la clave.
            'client_uuid' => $otro->uuid,
            'eventos'     => $this->eventos(),
        ], $this->headers($client));

        $respuesta->assertStatus(200);
        $this->assertSame(['ok' => true, 'guardados' => 3], $respuesta->json());

        $filas = LocalActividadCliente::query()->where('client_id', $client->id)->orderBy('ocurrido_at')->get();
        $this->assertCount(3, $filas);
        $this->assertSame(0, LocalActividadCliente::query()->where('client_id', $otro->id)->count());

        $this->assertSame('login', $filas[0]->tipo);
        $this->assertSame('Pablo', $filas[0]->usuario);
        $this->assertNull($filas[0]->detalle);
        $this->assertSame('2026-11-03 13:40:12', $filas[0]->ocurrido_at->format('Y-m-d H:i:s'));

        $this->assertSame('escaneo', $filas[1]->tipo);
        $this->assertSame(['comprobante' => 'FA-0001'], $filas[1]->detalle);

        $this->assertSame('agente_negocio', $filas[2]->tipo);
        $this->assertNull($filas[2]->usuario);
        $this->assertNull($filas[2]->detalle, 'Un detalle vacío se guarda como null.');
        $this->assertSame('2026-11-03 13:50:00', $filas[2]->ocurrido_at->format('Y-m-d H:i:s'), 'La hora en UTC se guarda en la hora del admin.');
    }

    /**
     * 🔴 No duplica: el mismo lote reenviado (un reintento), el mismo instante mandado en otra zona, un
     * repetido dentro del lote y un evento sin usuario repetido. Todos siguen contando como guardados.
     *
     * @return void
     */
    public function test_un_evento_repetido_no_se_duplica(): void
    {
        $client = $this->negocio();

        $this->postJson('/api/inbound/local/actividad', ['eventos' => $this->eventos()], $this->headers($client))
            ->assertStatus(200)->assertJsonPath('guardados', 3);

        // Reintento idéntico.
        $this->postJson('/api/inbound/local/actividad', ['eventos' => $this->eventos()], $this->headers($client))
            ->assertStatus(200)->assertJsonPath('guardados', 3);

        // El login de 13:40:12 -03:00 es el mismo instante que 16:40:12Z; y el agente_negocio sin usuario,
        // repetido dos veces en el mismo lote.
        $this->postJson('/api/inbound/local/actividad', ['eventos' => [
            ['tipo' => 'login', 'ocurrido_at' => '2026-11-03T16:40:12Z', 'usuario' => 'Pablo'],
            ['tipo' => 'agente_negocio', 'ocurrido_at' => '2026-11-03T13:50:00-03:00'],
            ['tipo' => 'agente_negocio', 'ocurrido_at' => '2026-11-03T13:50:00-03:00', 'usuario' => ''],
        ]], $this->headers($client))->assertStatus(200);

        $this->assertSame(3, LocalActividadCliente::query()->where('client_id', $client->id)->count());

        // Lo que sí es distinto entra: otro usuario en el mismo instante.
        $this->postJson('/api/inbound/local/actividad', ['eventos' => [
            ['tipo' => 'login', 'ocurrido_at' => '2026-11-03T13:40:12-03:00', 'usuario' => 'Marta'],
        ]], $this->headers($client))->assertStatus(200);

        $this->assertSame(4, LocalActividadCliente::query()->where('client_id', $client->id)->count());
    }

    /**
     * Un evento inválido es 422 `{error, errors}` y no se guarda ninguno del lote: tipo fuera del enum,
     * fecha que no es fecha, más de 100 eventos, detalle de más de 2 KB, lista vacía.
     *
     * @return void
     */
    public function test_un_lote_invalido_es_422_y_no_guarda_nada(): void
    {
        $client = $this->negocio();

        $con_tipo_inventado = $this->eventos();
        $con_tipo_inventado[] = ['tipo' => 'baile', 'ocurrido_at' => '2026-11-03T13:40:12-03:00'];
        $this->postJson('/api/inbound/local/actividad', ['eventos' => $con_tipo_inventado], $this->headers($client))
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors' => ['eventos.3.tipo']]);

        $this->postJson('/api/inbound/local/actividad', ['eventos' => [['tipo' => 'login', 'ocurrido_at' => 'ayer']]], $this->headers($client))
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors' => ['eventos.0.ocurrido_at']]);

        $muchos = [];
        for ($i = 0; $i < 101; $i++) {
            $muchos[] = ['tipo' => 'actividad', 'ocurrido_at' => AppTime::now()->subSeconds($i)->toIso8601String()];
        }
        $this->postJson('/api/inbound/local/actividad', ['eventos' => $muchos], $this->headers($client))->assertStatus(422);

        $pesado = [['tipo' => 'otro', 'ocurrido_at' => '2026-11-03T13:40:12-03:00', 'detalle' => ['texto' => str_repeat('a', 2100)]]];
        $this->postJson('/api/inbound/local/actividad', ['eventos' => $pesado], $this->headers($client))
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors' => ['eventos.0.detalle']]);

        $this->postJson('/api/inbound/local/actividad', ['eventos' => []], $this->headers($client))->assertStatus(422);

        $this->assertSame(0, LocalActividadCliente::query()->where('client_id', $client->id)->count());
    }

    /**
     * `local:podar` borra lo de más de 7 días y deja lo reciente.
     *
     * @return void
     */
    public function test_la_poda_borra_lo_de_mas_de_siete_dias(): void
    {
        $client = $this->negocio();
        $ahora  = AppTime::now();

        foreach ([['viejo', 8], ['viejo-tambien', 30], ['reciente', 1]] as $fila) {
            DB::table('local_actividad_clientes')->insert([
                'client_id'   => $client->id,
                'tipo'        => 'actividad',
                'usuario'     => $fila[0],
                'detalle'     => null,
                'ocurrido_at' => $ahora->copy()->subDays($fila[1])->format('Y-m-d H:i:s'),
                'huella'      => sha1($fila[0]),
                'created_at'  => $ahora->copy()->subDays($fila[1])->format('Y-m-d H:i:s'),
            ]);
        }

        $this->artisan('local:podar')->assertExitCode(0);

        $quedan = LocalActividadCliente::query()->where('client_id', $client->id)->pluck('usuario')->all();
        $this->assertSame(['reciente'], $quedan);
    }
}

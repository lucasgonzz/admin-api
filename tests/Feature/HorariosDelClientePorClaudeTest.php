<?php

namespace Tests\Feature;

use App\Jobs\SyncClientScheduleJob;
use App\Models\Client;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `PUT claude/clients/{id}/schedule`: la carga de horarios comerciales sin pasar por el modal del
 * admin, que es lo que le permite a Claude bajar los horarios de un lote de clientes de una.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 Que `dry_run` sea el DEFAULT y que en dry-run no se escriba ni una fila. Este endpoint
 *     REEMPLAZA el conjunto entero: un PUT disparado sin querer contra el cliente equivocado le
 *     borra los horarios que tenía. El freno tiene que estar cerrado cuando nadie lo abrió.
 *  2. 🔴 Que `confirm_client_name` sea obligatorio para escribir y que el error NO revele el
 *     nombre correcto. Si lo revelara dejaría de ser un freno y sería un formulario a completar.
 *  3. Que un payload inválido devuelva 422 SIN escribir nada, con la forma de error del bloque
 *     `claude/*` (`error` + `detalles`) y no con la de Laravel: quien consume esta API no puede
 *     recibir dos cuerpos distintos según qué validación falló.
 *  4. Que la regla del modelo sea la MISMA que la de la SPA — es el mismo servicio, y estos tests
 *     lo verifican por el lado de Claude para que una divergencia futura rompa las dos puntas.
 *  5. Que el push al empresa-api se ENCOLE en la conexión `database` y nunca corra adentro del
 *     request.
 */
class HorariosDelClientePorClaudeTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta usada en las requests del test. */
    const CLAVE = 'clave-de-prueba-claude-horarios';

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

    /**
     * Headers del bloque `claude/*`.
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
     * Cliente mínimo para colgarle horarios.
     *
     * @param string $nombre Nombre del negocio: es lo que confirma `confirm_client_name`.
     *
     * @return Client
     */
    private function crear_cliente(string $nombre = 'Ferretería Rioplatense'): Client
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->slug            = 'cliente-horarios-claude-' . Str::random(8);
        $client->api_url         = 'https://ejemplo.test';
        $client->api_key         = 'clave-api';
        $client->inbound_api_key = 'clave-inbound';
        $client->is_active       = true;
        $client->save();

        return $client;
    }

    /**
     * Carga una fila de día con sus rangos directamente en la base (sin pasar por la API).
     *
     * @param Client                        $client  Cliente dueño del horario.
     * @param string                        $day_key Clave del día ('todos', 'martes', …).
     * @param array<int, array<int, string>> $rangos  Pares [desde, hasta] en formato 'H:i'.
     *
     * @return void
     */
    private function cargar_dia(Client $client, string $day_key, array $rangos = []): void
    {
        $dia            = new ClientScheduleDay();
        $dia->client_id = $client->id;
        $dia->day_key   = $day_key;
        $dia->save();

        $orden = 0;
        foreach ($rangos as $par) {
            $rango                         = new ClientScheduleRange();
            $rango->client_schedule_day_id = $dia->id;
            $rango->start_time             = $par[0];
            $rango->end_time               = $par[1];
            $rango->sort_order             = $orden;
            $rango->save();
            $orden++;
        }
    }

    /**
     * URL del endpoint para un cliente.
     *
     * @param Client|string $client Cliente, o el segmento crudo de la ruta.
     *
     * @return string
     */
    private function url($client): string
    {
        $segmento = $client instanceof Client ? (string) $client->id : (string) $client;

        return '/api/claude/clients/' . $segmento . '/schedule';
    }

    /**
     * Cantidad de días y de rangos cargados de un cliente.
     *
     * @param Client $client Cliente.
     *
     * @return array<string, int>
     */
    private function conteos(Client $client): array
    {
        $dias = DB::table('client_schedule_days')->where('client_id', $client->id)->pluck('id');

        return [
            'dias'   => $dias->count(),
            'rangos' => DB::table('client_schedule_ranges')->whereIn('client_schedule_day_id', $dias)->count(),
        ];
    }

    /* =============================================================================================
     | 1) El freno del dry_run
     |============================================================================================ */

    /** Sin `dry_run` explícito NO se escribe nada, aunque el payload sea perfecto. */
    public function test_sin_dry_run_explicito_no_escribe_nada(): void
    {
        $client = $this->crear_cliente();

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '08:00', 'hasta' => '13:00']]],
            ],
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dry_run', true);
        $respuesta->assertJsonPath('escribio', false);

        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** El dry-run muestra qué había y qué quedaría: es un REEMPLAZO, no un agregado. */
    public function test_el_dry_run_muestra_el_antes_y_el_despues_completos(): void
    {
        $client = $this->crear_cliente();
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run' => true,
            'dias'    => [
                ['dia' => 'sabado', 'rangos' => [['desde' => '09:00', 'hasta' => '13:00']]],
            ],
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('dias_antes.0.dia', 'todos');
        $respuesta->assertJsonPath('dias_despues.0.dia', 'sabado');
        $respuesta->assertJsonCount(1, 'dias_despues');

        // Lo que importa: el 'todos' que había NO aparece en el después. Se borraría.
        $this->assertSame(['dias' => 1, 'rangos' => 1], $this->conteos($client));
    }

    /** El dry-run valida el payload igual: si está mal, avisa sin necesidad de confirmar nada. */
    public function test_el_dry_run_valida_el_payload_y_rechaza_lo_invalido(): void
    {
        $client = $this->crear_cliente();

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run' => true,
            'dias'    => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '18:00', 'hasta' => '09:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /* =============================================================================================
     | 2) El freno del nombre
     |============================================================================================ */

    /** Con `dry_run=false` y sin `confirm_client_name`, es 422 y no se escribe nada. */
    public function test_escribir_sin_confirm_client_name_es_422_y_no_escribe(): void
    {
        $client = $this->crear_cliente();

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run' => false,
            'dias'    => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '08:00', 'hasta' => '13:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /**
     * 🔴 Con el nombre equivocado es 422, no se escribe nada, y el error NO dice el nombre correcto:
     * si lo dijera, quien se equivocó de cliente lo copiaría y le pisaría los horarios a otro.
     */
    public function test_confirm_client_name_equivocado_no_escribe_ni_revela_el_nombre(): void
    {
        $client = $this->crear_cliente('Panadería La Esquina');
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run'             => false,
            'confirm_client_name' => 'Otro Negocio Cualquiera',
            'dias'                => [],
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Panadería La Esquina', $respuesta->getContent());

        // Y lo que había sigue estando: el rechazo no borró nada de camino.
        $this->assertSame(['dias' => 1, 'rangos' => 1], $this->conteos($client));
    }

    /** El nombre se compara con trim + minúsculas, igual que el resto de los frenos del bloque. */
    public function test_el_nombre_se_confirma_sin_distinguir_mayusculas_ni_espacios(): void
    {
        $client = $this->crear_cliente('Distribuidora Del Norte');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run'             => false,
            'confirm_client_name' => '  distribuidora del norte  ',
            'dias'                => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '08:00', 'hasta' => '13:00']]],
            ],
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('escribio', true);
        $this->assertSame(['dias' => 1, 'rangos' => 1], $this->conteos($client));
    }

    /* =============================================================================================
     | 3) El guardado de verdad
     |============================================================================================ */

    /** El camino feliz: guarda los días con sus rangos y el GET los devuelve. */
    public function test_guarda_los_dias_con_sus_rangos_y_el_get_los_devuelve(): void
    {
        $client = $this->crear_cliente('Corralón Sur');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run'             => false,
            'confirm_client_name' => 'Corralón Sur',
            'dias'                => [
                ['dia' => 'lunes', 'rangos' => [
                    ['desde' => '08:00', 'hasta' => '12:00'],
                    ['desde' => '15:30', 'hasta' => '19:30'],
                ]],
                ['dia' => 'sabado', 'rangos' => [['desde' => '08:00', 'hasta' => '12:30']]],
            ],
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('escribio', true);
        $respuesta->assertJsonPath('sync_encolado', true);
        $this->assertSame(['dias' => 2, 'rangos' => 3], $this->conteos($client));

        $get = $this->withHeaders($this->headers())->getJson($this->url($client));
        $get->assertStatus(200);
        $get->assertJsonPath('dias_cargados.0.dia', 'lunes');
        $get->assertJsonPath('dias_cargados.0.rangos.0.desde', '08:00');
        $get->assertJsonPath('dias_cargados.0.rangos.1.desde', '15:30');
        $get->assertJsonPath('dias_cargados.1.dia', 'sabado');
    }

    /** 🔴 Es un reemplazo: lo que no viaja en el payload se borra, no se conserva. */
    public function test_el_guardado_reemplaza_el_conjunto_entero(): void
    {
        $client = $this->crear_cliente('Kiosco Central');
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($client, 'domingo');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run'             => false,
            'confirm_client_name' => 'Kiosco Central',
            'dias'                => [
                ['dia' => 'viernes', 'rangos' => [['desde' => '10:00', 'hasta' => '20:00']]],
            ],
        ]);

        $respuesta->assertStatus(200);

        $dias = DB::table('client_schedule_days')->where('client_id', $client->id)->pluck('day_key')->all();
        $this->assertSame(['viernes'], $dias);

        // Y no quedan rangos huérfanos apuntando a los días que se borraron.
        $this->assertSame(['dias' => 1, 'rangos' => 1], $this->conteos($client));
    }

    /** Un día con cero rangos es CERRADO, y eso se guarda como una fila sin rangos. */
    public function test_un_dia_sin_rangos_se_guarda_como_cerrado(): void
    {
        $client = $this->crear_cliente('Verdulería Norte');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run'             => false,
            'confirm_client_name' => 'Verdulería Norte',
            'dias'                => [
                ['dia' => 'todos', 'rangos' => [['desde' => '09:00', 'hasta' => '18:00']]],
                ['dia' => 'domingo', 'rangos' => []],
            ],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame(['dias' => 2, 'rangos' => 1], $this->conteos($client));

        $get = $this->withHeaders($this->headers())->getJson($this->url($client) . '?dias=7');
        $get->assertStatus(200);

        // El domingo resuelto tiene que decir 'cerrado', no 'sin_configurar': la diferencia es la
        // que decide si el post-cierre de una actualización puede arrancar.
        $resueltos = $get->json('resueltos');
        $domingo   = null;
        foreach ($resueltos as $dia) {
            if ($dia['dia'] === 'domingo') {
                $domingo = $dia;
                break;
            }
        }

        $this->assertNotNull($domingo, 'La ventana de 7 días tiene que incluir un domingo.');
        $this->assertSame('cerrado', $domingo['estado']);
        $this->assertSame('dia_propio', $domingo['origen']);
    }

    /** Un conjunto vacío borra todo y deja al cliente `sin_configurar` (que no es `cerrado`). */
    public function test_un_conjunto_vacio_borra_todo_y_deja_sin_configurar(): void
    {
        $client = $this->crear_cliente('Bazar Once');
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run'             => false,
            'confirm_client_name' => 'Bazar Once',
            'dias'                => [],
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado_ahora', 'sin_configurar');
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /* =============================================================================================
     | 4) La validación del payload, con la forma de error del bloque
     |============================================================================================ */

    /** Dos rangos solapados del mismo día son 422 y no escriben nada. */
    public function test_dos_rangos_solapados_son_422_y_no_escriben_nada(): void
    {
        $client = $this->crear_cliente('Almacén Sol');
        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run'             => false,
            'confirm_client_name' => 'Almacén Sol',
            'dias'                => [
                ['dia' => 'lunes', 'rangos' => [
                    ['desde' => '08:00', 'hasta' => '14:00'],
                    ['desde' => '13:00', 'hasta' => '19:00'],
                ]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 1, 'rangos' => 1], $this->conteos($client));
    }

    /** Un día fuera de la enumeración es 422 con la forma del bloque: `error` + `detalles`. */
    public function test_un_dia_fuera_de_la_enumeracion_es_422_con_la_forma_del_bloque(): void
    {
        $client = $this->crear_cliente('Pinturería Este');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run' => false,
            'dias'    => [
                ['dia' => 'lunes_a_viernes', 'rangos' => []],
            ],
        ]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonStructure(['error', 'detalles', 'ayuda', 'client_id']);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** Una hora con formato inválido es 422 y no escribe nada. */
    public function test_una_hora_con_formato_invalido_es_422(): void
    {
        $client = $this->crear_cliente('Librería Oeste');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run' => false,
            'dias'    => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '8am', 'hasta' => '13:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** El mismo día repetido es 422: el unique de la base no es una respuesta que se pueda mostrar. */
    public function test_el_mismo_dia_repetido_es_422(): void
    {
        $client = $this->crear_cliente('Mueblería Sur');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run' => false,
            'dias'    => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '08:00', 'hasta' => '13:00']]],
                ['dia' => 'lunes', 'rangos' => [['desde' => '15:00', 'hasta' => '19:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /* =============================================================================================
     | 5) Ruta, autenticación y el push encolado
     |============================================================================================ */

    /** Un cliente que no existe es 404 con la forma del bloque, no una excepción de Eloquent. */
    public function test_un_cliente_inexistente_es_404(): void
    {
        $respuesta = $this->withHeaders($this->headers())->putJson($this->url('99999999'), [
            'dias' => [],
        ]);

        $respuesta->assertStatus(404);
        $respuesta->assertJsonStructure(['error']);
    }

    /** La ruta acepta el uuid del cliente, igual que el resto del bloque. */
    public function test_la_ruta_acepta_el_uuid_del_cliente(): void
    {
        $client = $this->crear_cliente('Ferretería Del Uuid');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url((string) $client->uuid), [
            'dry_run'             => false,
            'confirm_client_name' => 'Ferretería Del Uuid',
            'dias'                => [
                ['dia' => 'todos', 'rangos' => [['desde' => '09:00', 'hasta' => '18:00']]],
            ],
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('client.id', (int) $client->id);
        $this->assertSame(['dias' => 1, 'rangos' => 1], $this->conteos($client));
    }

    /** Sin el header X-Claude-Task-Key la ruta devuelve 401: el middleware es fail-closed. */
    public function test_sin_clave_de_ingesta_devuelve_401(): void
    {
        $client = $this->crear_cliente();

        $respuesta = $this->withHeaders(['Accept' => 'application/json'])->putJson($this->url($client), [
            'dias' => [],
        ]);

        $respuesta->assertStatus(401);
    }

    /**
     * 🔴 El push al empresa-api se ENCOLA en la conexión `database` explícita. Con
     * QUEUE_CONNECTION=sync, un dispatch pelado lo correría inline y le sumaría hasta ~45 segundos
     * de HTTP a este request por un efecto secundario que a quien guarda no le importa.
     */
    public function test_el_push_al_cliente_se_encola_en_la_conexion_database(): void
    {
        Queue::fake();

        $client = $this->crear_cliente('Cliente Encolado');

        $respuesta = $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dry_run'             => false,
            'confirm_client_name' => 'Cliente Encolado',
            'dias'                => [
                ['dia' => 'todos', 'rangos' => [['desde' => '09:00', 'hasta' => '18:00']]],
            ],
        ]);

        $respuesta->assertStatus(200);

        Queue::assertPushed(SyncClientScheduleJob::class, function ($job) {
            return $job->connection === 'database';
        });
    }

    /** En dry-run no se encola nada: no pasó nada que contarle al cliente. */
    public function test_el_dry_run_no_encola_ningun_push(): void
    {
        Queue::fake();

        $client = $this->crear_cliente();

        $this->withHeaders($this->headers())->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'todos', 'rangos' => [['desde' => '09:00', 'hasta' => '18:00']]],
            ],
        ])->assertStatus(200);

        Queue::assertNothingPushed();
    }
}

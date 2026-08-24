<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Services\ClientScheduleResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La API Sanctum de horarios del cliente (GET/PUT admin/client/{clientId}/horarios), que es lo que
 * consume la pestaña "Horarios" del modal del cliente en admin-spa.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 Que una validación que falla devuelva 422 SIN escribir ni una fila. El PUT borra todo y
 *     recrea: si se validara a mitad de camino, un payload inválido dejaría al cliente sin
 *     horarios. Por eso cada test de 422 cuenta las filas después.
 *  2. Que el reemplazo del conjunto no deje rangos huérfanos apuntando a días borrados.
 *  3. Que la enumeración de días y el timezone viajen desde el back, para que la SPA no los
 *     hardcodee ni los adivine.
 */
class HorariosDelClienteTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin logueado por Sanctum: todas estas rutas viven bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de horarios';
        $admin->email    = 'horarios-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Cliente mínimo para colgarle horarios.
     *
     * @return Client
     */
    private function crear_cliente(): Client
    {
        $client                  = new Client();
        $client->name            = 'Cliente de horarios API';
        $client->slug            = 'cliente-horarios-api-' . Str::random(8);
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
     * @return ClientScheduleDay
     */
    private function cargar_dia(Client $client, string $day_key, array $rangos = []): ClientScheduleDay
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

        return $dia;
    }

    /**
     * URL de los horarios de un cliente.
     *
     * @param Client $client Cliente.
     *
     * @return string
     */
    private function url(Client $client): string
    {
        return '/api/admin/client/' . $client->id . '/horarios';
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
        $ids_de_dias = ClientScheduleDay::where('client_id', $client->id)->pluck('id')->all();

        return [
            'dias'   => count($ids_de_dias),
            'rangos' => count($ids_de_dias) === 0
                ? 0
                : ClientScheduleRange::whereIn('client_schedule_day_id', $ids_de_dias)->count(),
        ];
    }

    /** 10) El PUT persiste el conjunto entero, y el GET devuelve exactamente lo mismo. */
    public function test_el_put_guarda_los_dias_con_sus_rangos_y_el_get_los_devuelve()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'todos', 'rangos' => [['desde' => '09:00', 'hasta' => '18:00']]],
                ['dia' => 'martes', 'rangos' => [
                    ['desde' => '08:00', 'hasta' => '13:00'],
                    ['desde' => '16:00', 'hasta' => '21:00'],
                ]],
                // Día con fila y sin rangos: así se dice "el domingo cerramos".
                ['dia' => 'domingo', 'rangos' => []],
            ],
        ]);

        $respuesta->assertStatus(200);

        $conteos = $this->conteos($client);
        $this->assertSame(3, $conteos['dias'], 'Tienen que quedar las tres filas de día.');
        $this->assertSame(3, $conteos['rangos'], 'Tienen que quedar los tres rangos (el domingo no tiene ninguno).');

        // El GET devuelve el mismo conjunto, en el orden de presentación de DAY_KEYS.
        $get = $this->getJson($this->url($client));
        $get->assertStatus(200);

        $dias = $get->json('dias');
        $this->assertCount(3, $dias);
        $this->assertSame(['todos', 'martes', 'domingo'], array_column($dias, 'dia'));
        $this->assertSame('Todos los días', $dias[0]['dia_label']);
        $this->assertSame([['desde' => '09:00', 'hasta' => '18:00']], $dias[0]['rangos']);
        $this->assertSame(
            [['desde' => '08:00', 'hasta' => '13:00'], ['desde' => '16:00', 'hasta' => '21:00']],
            $dias[1]['rangos']
        );
        $this->assertSame([], $dias[2]['rangos'], 'El domingo viaja con cero rangos: cerrado.');

        // La respuesta del PUT es el mismo payload que la del GET, ya releído de la base.
        $this->assertSame($get->json('dias'), $respuesta->json('dias'));
    }

    /** 11) 🔴 Un rango con la hora de cierre anterior o igual a la de apertura: 422 y cero filas. */
    public function test_un_rango_que_termina_antes_de_empezar_es_422_y_no_escribe_nada()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '18:00', 'hasta' => '09:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** 11 bis) `hasta` igual a `desde` tampoco vale: el rango tiene que durar algo. */
    public function test_un_rango_de_duracion_cero_es_422()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '09:00', 'hasta' => '09:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** 12) 🔴 Dos rangos del mismo día que se pisan: 422 y cero filas. */
    public function test_dos_rangos_solapados_del_mismo_dia_son_422_y_no_escriben_nada()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'martes', 'rangos' => [
                    ['desde' => '09:00', 'hasta' => '14:00'],
                    ['desde' => '13:00', 'hasta' => '18:00'],
                ]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** 12 bis) Dos rangos que se TOCAN (uno termina donde arranca el otro) sí son válidos. */
    public function test_dos_rangos_que_se_tocan_no_se_consideran_solapados()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'martes', 'rangos' => [
                    ['desde' => '09:00', 'hasta' => '13:00'],
                    ['desde' => '13:00', 'hasta' => '18:00'],
                ]],
            ],
        ]);

        $respuesta->assertStatus(200);
        $this->assertSame(['dias' => 1, 'rangos' => 2], $this->conteos($client));
    }

    /** 13) Una clave de día que no existe en la enumeración: 422. */
    public function test_un_dia_fuera_de_la_enumeracion_es_422()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'lunez', 'rangos' => [['desde' => '09:00', 'hasta' => '18:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** 13 bis) Una hora que no está en formato 'H:i': 422. */
    public function test_una_hora_con_formato_invalido_es_422()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'lunes', 'rangos' => [['desde' => '9', 'hasta' => '25:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** 14) El mismo día dos veces en el payload: 422 (y nunca llega al unique de la base). */
    public function test_el_mismo_dia_repetido_es_422()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'martes', 'rangos' => [['desde' => '09:00', 'hasta' => '13:00']]],
                ['dia' => 'martes', 'rangos' => [['desde' => '16:00', 'hasta' => '21:00']]],
            ],
        ]);

        $respuesta->assertStatus(422);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
    }

    /** 15) 🔴 Reemplazar el conjunto no deja rangos huérfanos apuntando a días borrados. */
    public function test_reemplazar_el_conjunto_no_deja_rangos_huerfanos()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $dia_viejo = $this->cargar_dia($client, 'todos', [['09:00', '18:00'], ['20:00', '22:00']]);
        $this->cargar_dia($client, 'miercoles', [['10:00', '15:00']]);

        $respuesta = $this->putJson($this->url($client), [
            'dias' => [
                ['dia' => 'todos', 'rangos' => [['desde' => '08:00', 'hasta' => '12:00']]],
            ],
        ]);

        $respuesta->assertStatus(200);

        // Un solo día y un solo rango: el conjunto viejo desapareció entero.
        $this->assertSame(['dias' => 1, 'rangos' => 1], $this->conteos($client));

        // Y los rangos del día viejo se fueron con él (cascade), no quedaron colgados.
        $this->assertSame(
            0,
            ClientScheduleRange::where('client_schedule_day_id', $dia_viejo->id)->count(),
            'Los rangos del día borrado tienen que haberse ido con él.'
        );

        $huerfanos = DB::table('client_schedule_ranges')
            ->leftJoin('client_schedule_days', 'client_schedule_ranges.client_schedule_day_id', '=', 'client_schedule_days.id')
            ->whereNull('client_schedule_days.id')
            ->count();

        $this->assertSame(0, $huerfanos, 'No puede quedar ningún rango apuntando a un día que ya no existe.');
    }

    /** 16) `dias: []` borra todo, y el cliente vuelve a quedar SIN CONFIGURAR (que no es cerrado). */
    public function test_un_conjunto_vacio_borra_todo_y_deja_al_cliente_sin_configurar()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($client, 'domingo');

        $respuesta = $this->putJson($this->url($client), ['dias' => []]);

        $respuesta->assertStatus(200);
        $this->assertSame(['dias' => 0, 'rangos' => 0], $this->conteos($client));
        $this->assertSame([], $respuesta->json('dias'));

        // 🔴 Los siete días resueltos quedan 'sin_configurar', NUNCA 'cerrado': sin configuración
        // no se sabe si el negocio está abierto, y asumir que está cerrado es lo que arranca el
        // post-cierre de una actualización sobre un negocio con gente adentro.
        $estados = array_column($respuesta->json('resueltos_proximos_7_dias'), 'estado');
        $this->assertCount(7, $estados);
        $this->assertSame(
            [ClientScheduleResolver::ESTADO_SIN_CONFIGURAR],
            array_values(array_unique($estados))
        );
    }

    /** 17) El GET declara el timezone y la enumeración de días, para que la SPA no los invente. */
    public function test_el_get_devuelve_el_timezone_y_la_enumeracion_de_dias()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $this->cargar_dia($client, 'todos', [['09:00', '18:00']]);
        $this->cargar_dia($client, 'martes', [['08:00', '13:00']]);

        $respuesta = $this->getJson($this->url($client));

        $respuesta->assertStatus(200);
        $this->assertSame(config('app.timezone'), $respuesta->json('timezone'));

        $day_keys = $respuesta->json('day_keys');
        $this->assertSame(ClientScheduleDay::DAY_KEYS, array_column($day_keys, 'key'));
        $this->assertSame('Todos los días', $day_keys[0]['label']);
        $this->assertSame('Miércoles', $day_keys[3]['label']);

        // La semana resuelta viaja calculada por el back: la SPA no reimplementa la precedencia.
        $resueltos = $respuesta->json('resueltos_proximos_7_dias');
        $this->assertCount(7, $resueltos);

        foreach ($resueltos as $dia) {
            $this->assertSame(config('app.timezone'), $dia['timezone']);

            if ($dia['dia'] === 'martes') {
                $this->assertSame(ClientScheduleResolver::ORIGEN_DIA_PROPIO, $dia['origen']);
                $this->assertSame([['desde' => '08:00', 'hasta' => '13:00']], $dia['rangos']);
            } else {
                $this->assertSame(ClientScheduleResolver::ORIGEN_TODOS_LOS_DIAS, $dia['origen']);
                $this->assertSame([['desde' => '09:00', 'hasta' => '18:00']], $dia['rangos']);
            }
        }
    }

    /** El {clientId} también acepta el uuid del cliente, igual que el resto del admin. */
    public function test_las_rutas_aceptan_el_uuid_del_cliente()
    {
        $this->admin_logueado();
        $client = $this->crear_cliente();

        $url = '/api/admin/client/' . $client->uuid . '/horarios';

        $put = $this->putJson($url, [
            'dias' => [['dia' => 'sabado', 'rangos' => [['desde' => '09:00', 'hasta' => '13:00']]]],
        ]);

        $put->assertStatus(200);

        $get = $this->getJson($url);
        $get->assertStatus(200);
        $this->assertSame('sabado', $get->json('dias.0.dia'));
    }
}

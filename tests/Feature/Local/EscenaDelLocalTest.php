<?php

namespace Tests\Feature\Local;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\ClientScheduleDay;
use App\Models\ClientScheduleRange;
use App\Models\LocalOficinaSlot;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\Local\EscenaService;
use App\Services\Local\OficinaIngestService;
use App\Services\SupportCodexHealthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `GET api/admin/local/escena` (misión el-local, 3/11/2026, contrato B): lo que dibuja la vista `/local`.
 *
 * Es el contrato con `director.js` de la SPA: la forma exacta del JSON (claves y orden de los bloques, los seis
 * agentes siempre), y las reglas que cambian lo que se ve: un negocio con horario abierto está `abierto`; un
 * ticket con reserva vigente de Martín pone al cliente en Soporte y al robot a trabajar; una misión pendiente
 * cae en la bandeja «Para Lucas» (y sale cuando Lucas la inicia); un slot reportado aparece en las oficinas; y
 * sin pulso del reporter, `reporter.ok` es false.
 *
 * 🔴 Este test NO vacía las tablas compartidas (clientes, tickets, mensajes): la base de testing del slot puede
 * venir con fixture, así que se busca por id lo que se sembró y no se afirman totales globales. Solo se vacían
 * las dos cosas que son de esta misión y que nadie más escribe (`local_oficina_slots` y el pulso del reporter).
 * Todo corre adentro de la transacción del test. El cache de 20 s se borra antes de cada pedido.
 */
class EscenaDelLocalTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de Claude para reportar slots por el endpoint real. */
    const CLAVE = 'clave-de-claude-para-la-escena';

    /** Regex de una fecha ISO-8601 con zona. */
    const ISO = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE]);

        LocalOficinaSlot::query()->delete();
        AdminSetting::where('key', OficinaIngestService::PULSO_KEY)->delete();
        AdminSetting::where('key', SupportCodexHealthService::KEY)->delete();
    }

    /**
     * Suelta el reloj congelado de los tests que lo congelan.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Congela el reloj (AppTime::now() usa Carbon::now(), que respeta setTestNow) en una hora de hoy, en hora
     * Argentina.
     *
     * @param string $hora 'H:i'.
     *
     * @return void
     */
    private function congelar_a_las(string $hora): void
    {
        Carbon::setTestNow(null);
        $momento = AppTime::now()->setTimeFromTimeString($hora . ':00');
        Carbon::setTestNow($momento);
    }

    /* ------------------------------------------------------------------------------------------
     | Siembra
     |----------------------------------------------------------------------------------------- */

    /**
     * @return Admin
     */
    private function crear_admin(): Admin
    {
        return Admin::create([
            'name'     => 'Lucas',
            'email'    => 'el-local-' . uniqid() . '@test.local',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * @param string $nombre
     * @param bool   $activo
     *
     * @return Client
     */
    private function crear_cliente(string $nombre, bool $activo = true): Client
    {
        $client               = new Client();
        $client->name         = $nombre;
        $client->company_name = $nombre;
        $client->slug         = Str::slug($nombre) . '-' . Str::lower(Str::random(6));
        $client->phone        = '+5493417770' . random_int(100, 999);
        $client->is_active    = $activo;
        $client->save();

        return $client;
    }

    /**
     * Horario «todos los días» de 00:00 a 24:00: abierto a cualquier hora en que corra el test (el rango es
     * [desde, hasta) y la hora del instante es 'H:i', así que 23:59 < 24:00).
     *
     * @param Client $client
     *
     * @return void
     */
    private function abierto_todo_el_dia(Client $client): void
    {
        $dia = ClientScheduleDay::create(['client_id' => $client->id, 'day_key' => 'todos']);
        ClientScheduleRange::create([
            'client_schedule_day_id' => $dia->id,
            'start_time'             => '00:00:00',
            'end_time'               => '24:00:00',
            'sort_order'             => 0,
        ]);
    }

    /**
     * Horario cargado sin ningún rango: «cerramos todos los días».
     *
     * @param Client $client
     *
     * @return void
     */
    private function cerrado_todo_el_dia(Client $client): void
    {
        ClientScheduleDay::create(['client_id' => $client->id, 'day_key' => 'todos']);
    }

    /**
     * @param Client $client
     * @param string $status
     *
     * @return SupportTicket
     */
    private function crear_ticket(Client $client, string $status = 'open'): SupportTicket
    {
        return SupportTicket::create([
            'client_id'      => $client->id,
            'client_user_id' => 0,
            'name'           => 'Recargos consolidados',
            'status'         => $status,
            'source'         => 'whatsapp',
            'whatsapp_phone' => $client->phone,
            'opened_at'      => AppTime::now()->subHour(),
        ]);
    }

    /**
     * Mensaje del cliente en el ticket, hace `$minutos` minutos.
     *
     * @param SupportTicket $ticket
     * @param int           $minutos
     *
     * @return SupportMessage
     */
    private function mensaje_del_cliente(SupportTicket $ticket, int $minutos = 2): SupportMessage
    {
        $mensaje = SupportMessage::create([
            'support_ticket_id'      => $ticket->id,
            'sender_type'            => 'user',
            'kind'                   => 'text',
            'body'                   => 'No me deja facturar',
            'is_ai_suggestion_draft' => false,
            'delivered_at'           => now(),
        ]);

        $cuando = AppTime::now()->subMinutes($minutos)->format('Y-m-d H:i:s');
        DB::table('support_messages')->where('id', $mensaje->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);

        return $mensaje;
    }

    /**
     * Pulso fresco del monitor de soporte (como lo deja `SupportCodexHealthService::heartbeat()`: segundos).
     *
     * @param int $hace_segundos
     *
     * @return void
     */
    private function pulso_de_soporte(int $hace_segundos = 10): void
    {
        AdminSetting::updateOrCreate(
            ['key' => SupportCodexHealthService::KEY],
            ['value' => json_encode(['last_seen_at' => time() - $hace_segundos, 'status' => 'ok'])]
        );
    }

    /**
     * Fila del hilo del dueño con el asistente, con `created_at` hace `$minutos` minutos.
     *
     * @param Client $client
     * @param string $estado
     * @param int    $minutos
     * @param string $direccion
     *
     * @return ClientAssistantMessage
     */
    private function mensaje_al_asistente(Client $client, string $estado, int $minutos, string $direccion = ClientAssistantMessage::DIRECCION_ENTRANTE): ClientAssistantMessage
    {
        $fila = ClientAssistantMessage::create([
            'client_id' => $client->id,
            'telefono'  => '+5493417770997',
            'direccion' => $direccion,
            'tipo'      => 'text',
            'texto'     => 'mensaje de prueba',
            'estado'    => $estado,
        ]);

        $cuando = AppTime::now()->subMinutes($minutos)->format('Y-m-d H:i:s');
        DB::table('client_assistant_messages')->where('id', $fila->id)->update(['created_at' => $cuando, 'updated_at' => $cuando]);

        return $fila;
    }

    /**
     * Pide la escena (sin cache) y devuelve el JSON.
     *
     * @param Admin $admin
     *
     * @return array<string, mixed>
     */
    private function escena(Admin $admin): array
    {
        Cache::forget(EscenaService::CACHE_KEY);

        return $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/local/escena')
            ->assertStatus(200)
            ->json();
    }

    /**
     * @param array<string, mixed> $escena
     * @param int                  $id
     *
     * @return array<string, mixed>|null
     */
    private function negocio_de(array $escena, int $id): ?array
    {
        foreach ($escena['negocios'] as $negocio) {
            if ($negocio['id'] === $id) {
                return $negocio;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $escena
     * @param string               $id
     *
     * @return array<string, mixed>
     */
    private function agente_de(array $escena, string $id): array
    {
        foreach ($escena['agentes'] as $agente) {
            if ($agente['id'] === $id) {
                return $agente;
            }
        }

        $this->fail('El agente "' . $id . '" no está en la escena.');
    }

    /**
     * @param array<string, mixed> $escena
     * @param string               $id
     *
     * @return array<string, mixed>|null
     */
    private function item_de_bandeja(array $escena, string $id): ?array
    {
        foreach ($escena['bandeja'] as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $escena
     * @param string               $id
     *
     * @return array<string, mixed>|null
     */
    private function evento_de(array $escena, string $id): ?array
    {
        foreach ($escena['eventos'] as $evento) {
            if ($evento['id'] === $id) {
                return $evento;
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------------------------------
     | Tests
     |----------------------------------------------------------------------------------------- */

    /**
     * Va adentro de `auth:sanctum`: sin sesión, 401.
     *
     * @return void
     */
    public function test_sin_sesion_devuelve_401(): void
    {
        $this->getJson('/api/admin/local/escena')->assertStatus(401);
    }

    /**
     * La forma exacta del contrato B: los siete bloques en orden, las claves de cada pieza y los seis agentes
     * siempre, en el orden de las estaciones.
     *
     * @return void
     */
    public function test_la_forma_del_json_es_la_del_contrato(): void
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente('Forma del contrato');
        $this->mensaje_del_cliente($this->crear_ticket($client));
        DB::table('admin_tasks')->insert([
            'created_by_admin_id' => $admin->id,
            'created_via'         => 'claude',
            'title'               => 'Revisar la forma',
            'is_done'             => false,
            'sort_order'          => 0,
            'created_at'          => AppTime::now()->format('Y-m-d H:i:s'),
            'updated_at'          => AppTime::now()->format('Y-m-d H:i:s'),
        ]);

        $escena = $this->escena($admin);

        $this->assertSame(['calculado_en', 'resumen', 'negocios', 'agentes', 'bandeja', 'oficina', 'eventos'], array_keys($escena));
        $this->assertMatchesRegularExpression(self::ISO, $escena['calculado_en']);

        $this->assertSame(
            ['negocios_abiertos', 'negocios_cerrados', 'negocios_sin_horario', 'tickets_abiertos', 'para_lucas', 'misiones_en_curso', 'slots_libres', 'slots_total'],
            array_keys($escena['resumen'])
        );
        foreach ($escena['resumen'] as $clave => $valor) {
            $this->assertIsInt($valor, 'resumen.' . $clave . ' tiene que ser entero.');
        }
        $this->assertSame(35, $escena['resumen']['slots_total'], 'Sin reportes, la oficina se dibuja con 35 slots.');

        $negocio = $this->negocio_de($escena, (int) $client->id);
        $this->assertNotNull($negocio);
        $this->assertSame(
            ['id', 'nombre', 'slug', 'logo_url', 'estado', 'activo_reciente', 'ultima_actividad_at', 'estacion', 'estacion_desde_at', 'estacion_detalle'],
            array_keys($negocio)
        );
        $this->assertSame('Forma del contrato', $negocio['nombre']);
        $this->assertSame($client->slug, $negocio['slug']);
        $this->assertNull($negocio['logo_url']);
        $this->assertMatchesRegularExpression(self::ISO, $negocio['ultima_actividad_at']);

        $this->assertSame(['soporte', 'facturas', 'negocio', 'asistente', 'informes', 'comercial'], array_column($escena['agentes'], 'id'));
        foreach ($escena['agentes'] as $agente) {
            $this->assertSame(['id', 'estado', 'atendiendo', 'pendientes', 'ultimo_evento_at', 'detalle'], array_keys($agente));
            $this->assertContains($agente['estado'], ['trabajando', 'libre', 'apagado', 'sin_senal']);
            $this->assertIsArray($agente['atendiendo']);
            $this->assertIsInt($agente['pendientes']);
        }
        $this->assertSame('sin_senal', $this->agente_de($escena, 'facturas')['estado'], 'Sin actividad de escaneo, facturas está sin señal.');
        $this->assertSame('sin_senal', $this->agente_de($escena, 'negocio')['estado']);

        $this->assertNotEmpty($escena['bandeja']);
        foreach ($escena['bandeja'] as $item) {
            $this->assertSame(['id', 'tipo', 'titulo', 'client_id', 'creado_at', 'ruta'], array_keys($item));
            $this->assertContains($item['tipo'], ['caso_delegado', 'revision', 'mision_pendiente', 'aprobacion', 'tarea']);
        }
        $this->assertLessThanOrEqual(50, count($escena['bandeja']));
        $this->assertGreaterThanOrEqual(count($escena['bandeja']), $escena['resumen']['para_lucas']);

        $this->assertSame(['reporter', 'planificador', 'slots'], array_keys($escena['oficina']));
        $this->assertSame(['ok', 'last_seen_at', 'version'], array_keys($escena['oficina']['reporter']));
        $this->assertSame(['estado'], array_keys($escena['oficina']['planificador']));

        $this->assertNotEmpty($escena['eventos']);
        foreach ($escena['eventos'] as $evento) {
            $this->assertSame(['id', 'tipo', 'agente', 'client_id', 'ocurrido_at', 'detalle'], array_keys($evento));
            $this->assertMatchesRegularExpression(self::ISO, $evento['ocurrido_at']);
        }
        $ordenados = array_column($escena['eventos'], 'ocurrido_at');
        $copia     = $ordenados;
        usort($copia, function ($a, $b) {
            return strtotime($a) - strtotime($b);
        });
        $this->assertSame($copia, $ordenados, 'Los eventos van del más viejo al más nuevo.');
    }

    /**
     * El estado de cada negocio sale de su horario: abierto, cerrado o sin horario. Un cliente inactivo no
     * entra al local. Y van ordenados por nombre.
     *
     * @return void
     */
    public function test_el_estado_de_cada_negocio_sale_de_su_horario(): void
    {
        // A las 15:00: de madrugada un negocio sin horario sale cerrado (ver el test de abajo).
        $this->congelar_a_las('15:00');

        $admin    = $this->crear_admin();
        $abierto  = $this->crear_cliente('Zeta abierto');
        $cerrado  = $this->crear_cliente('Alfa cerrado');
        $sin      = $this->crear_cliente('Medio sin horario');
        $inactivo = $this->crear_cliente('Inactivo', false);

        $this->abierto_todo_el_dia($abierto);
        $this->cerrado_todo_el_dia($cerrado);

        $escena = $this->escena($admin);

        $this->assertSame('abierto', $this->negocio_de($escena, (int) $abierto->id)['estado']);
        $this->assertSame('cerrado', $this->negocio_de($escena, (int) $cerrado->id)['estado']);
        $this->assertSame('sin_horario', $this->negocio_de($escena, (int) $sin->id)['estado']);
        $this->assertNull($this->negocio_de($escena, (int) $inactivo->id), 'Un cliente inactivo no está en el local.');

        $ids = array_column($escena['negocios'], 'id');
        $this->assertLessThan(
            array_search((int) $abierto->id, $ids, true),
            array_search((int) $cerrado->id, $ids, true),
            'Alfa va antes que Zeta: ordenados por nombre.'
        );

        $sin_nada = $this->negocio_de($escena, (int) $sin->id);
        $this->assertFalse($sin_nada['activo_reciente']);
        $this->assertNull($sin_nada['ultima_actividad_at']);
        $this->assertNull($sin_nada['estacion']);
    }

    /**
     * 🔴 De madrugada (00:00 inclusive a 06:00 exclusive, hora Argentina) un negocio SIN horario cargado se
     * presume cerrado (regla de Lucas del 2/11/2026); a las 06:00 y de día vuelve a `sin_horario`. Un negocio con
     * horario no cambia por esto.
     *
     * @return void
     */
    public function test_sin_horario_de_madrugada_cuenta_como_cerrado(): void
    {
        $admin   = $this->crear_admin();
        $sin     = $this->crear_cliente('Sin horario de madrugada');
        $abierto = $this->crear_cliente('Abierto las 24 horas');
        $this->abierto_todo_el_dia($abierto);

        foreach (['03:00' => 'cerrado', '00:00' => 'cerrado', '05:59' => 'cerrado', '06:00' => 'sin_horario', '15:00' => 'sin_horario'] as $hora => $esperado) {
            $this->congelar_a_las($hora);
            $escena = $this->escena($admin);

            $this->assertSame($esperado, $this->negocio_de($escena, (int) $sin->id)['estado'], 'A las ' . $hora . ' un negocio sin horario sale ' . $esperado . '.');
            $this->assertSame('abierto', $this->negocio_de($escena, (int) $abierto->id)['estado'], 'A las ' . $hora . ' el que tiene horario sigue su horario.');
        }
    }

    /**
     * 🔴 Un ticket abierto con reserva vigente de Martín pone al cliente en Soporte (con el número de ticket) y
     * al robot de Soporte a trabajar atendiéndolo — siempre que el monitor dé pulso. Sin pulso, el robot está
     * apagado aunque haya reservas.
     *
     * @return void
     */
    public function test_un_ticket_con_reserva_vigente_pone_al_cliente_en_soporte_y_al_agente_a_trabajar(): void
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente('Ferre con ticket');
        $ticket = $this->crear_ticket($client);

        SupportCodexCase::create([
            'support_ticket_id' => $ticket->id,
            'state'             => 'ready',
            'worker_id'         => 'monitor-local',
            'lease_expires_at'  => AppTime::now()->addMinutes(10),
        ]);

        // Además le escribió al asistente hace 20 minutos (sigue en «enviado»). La reserva empezó hace 5
        // (vence en 10, dura 15): es lo más reciente, así que está en Soporte.
        $this->mensaje_al_asistente($client, ClientAssistantMessage::ESTADO_ENVIADO, 20);

        $this->pulso_de_soporte();
        $escena = $this->escena($admin);

        $negocio = $this->negocio_de($escena, (int) $client->id);
        $this->assertSame('soporte', $negocio['estacion']);
        $this->assertSame('ticket #' . $ticket->id, $negocio['estacion_detalle']);
        $this->assertMatchesRegularExpression(self::ISO, $negocio['estacion_desde_at']);

        $soporte = $this->agente_de($escena, 'soporte');
        $this->assertSame('trabajando', $soporte['estado']);
        $this->assertContains((int) $client->id, $soporte['atendiendo']);
        $this->assertStringStartsWith('Martín atendiendo', (string) $soporte['detalle']);

        $asistente = $this->agente_de($escena, 'asistente');
        $this->assertSame('trabajando', $asistente['estado'], 'Un mensaje en «enviado» tiene al asistente pensando.');
        $this->assertContains((int) $client->id, $asistente['atendiendo']);

        // Sin pulso del monitor: apagado, aunque la reserva siga vigente.
        AdminSetting::where('key', SupportCodexHealthService::KEY)->delete();
        $this->assertSame('apagado', $this->agente_de($this->escena($admin), 'soporte')['estado']);

        // Pulso de hace 6 minutos: también apagado.
        $this->pulso_de_soporte(360);
        $this->assertSame('apagado', $this->agente_de($this->escena($admin), 'soporte')['estado']);
    }

    /**
     * Un mensaje del cliente en los últimos 15 minutos (sin reserva) también lo pone en Soporte, lo marca activo
     * y deja un evento `ticket_mensaje` con id estable. Uno de hace 20 minutos lo deja activo pero no en Soporte.
     * Y un mensaje al asistente esperando, sin nada de soporte, lo pone en el Asistente.
     *
     * @return void
     */
    public function test_un_mensaje_reciente_del_cliente_lo_pone_en_soporte_y_deja_un_evento(): void
    {
        $admin = $this->crear_admin();

        $reciente = $this->crear_cliente('Escribió recién');
        $ticket   = $this->crear_ticket($reciente);
        $mensaje  = $this->mensaje_del_cliente($ticket, 2);

        $hace_rato = $this->crear_cliente('Escribió hace rato');
        $this->mensaje_del_cliente($this->crear_ticket($hace_rato), 20);

        $al_asistente = $this->crear_cliente('Le habla al asistente');
        ClientAssistantMessage::create([
            'client_id' => $al_asistente->id,
            'telefono'  => '+5493417770998',
            'direccion' => ClientAssistantMessage::DIRECCION_ENTRANTE,
            'tipo'      => 'text',
            'texto'     => 'anotame una venta',
            'estado'    => ClientAssistantMessage::ESTADO_ESPERANDO,
        ]);

        $escena = $this->escena($admin);

        $uno = $this->negocio_de($escena, (int) $reciente->id);
        $this->assertSame('soporte', $uno['estacion']);
        $this->assertSame('ticket #' . $ticket->id, $uno['estacion_detalle']);
        $this->assertTrue($uno['activo_reciente']);

        $dos = $this->negocio_de($escena, (int) $hace_rato->id);
        $this->assertNull($dos['estacion'], 'Hace 20 minutos ya no está parado en Soporte.');
        $this->assertTrue($dos['activo_reciente'], 'Pero sí estuvo activo en los últimos 30 minutos.');

        $tres = $this->negocio_de($escena, (int) $al_asistente->id);
        $this->assertSame('asistente', $tres['estacion']);
        $this->assertTrue($tres['activo_reciente']);
        $this->assertGreaterThanOrEqual(1, $this->agente_de($escena, 'asistente')['pendientes'], 'Un mensaje en «esperando» cuenta como pendiente.');
        $this->assertContains((int) $al_asistente->id, $this->agente_de($escena, 'asistente')['atendiendo']);

        $evento = $this->evento_de($escena, 'sm-' . $mensaje->id);
        $this->assertNotNull($evento, 'El mensaje del cliente aparece como evento con id sm-<id>.');
        $this->assertSame('ticket_mensaje', $evento['tipo']);
        $this->assertSame('soporte', $evento['agente']);
        $this->assertSame((int) $reciente->id, $evento['client_id']);
        $this->assertSame('ticket #' . $ticket->id, $evento['detalle']);
    }

    /**
     * Segundos de la `created_at` de una fila, leída de la base en la hora del admin.
     *
     * @param string $tabla
     * @param int    $id
     *
     * @return int
     */
    private function ts_de(string $tabla, int $id): int
    {
        return \Carbon\Carbon::parse((string) DB::table($tabla)->where('id', $id)->value('created_at'), config('app.timezone'))->getTimestamp();
    }

    /**
     * 🔴 La estación es la del agente al que el cliente le habló ÚLTIMO, no una prioridad fija: con soporte hace
     * 10 minutos y el asistente hace 2, está en el Asistente; al revés, en Soporte. `estacion_desde_at` es la
     * hora de esa última interacción.
     *
     * @return void
     */
    public function test_la_estacion_es_la_de_la_interaccion_mas_reciente(): void
    {
        $admin = $this->crear_admin();

        $al_asistente = $this->crear_cliente('Último al asistente');
        $this->mensaje_del_cliente($this->crear_ticket($al_asistente), 10);
        $fila = $this->mensaje_al_asistente($al_asistente, ClientAssistantMessage::ESTADO_RECIBIDO, 2);

        $a_soporte = $this->crear_cliente('Último a soporte');
        $ticket    = $this->crear_ticket($a_soporte);
        $mensaje   = $this->mensaje_del_cliente($ticket, 2);
        $this->mensaje_al_asistente($a_soporte, ClientAssistantMessage::ESTADO_RECIBIDO, 10);

        $escena = $this->escena($admin);

        $uno = $this->negocio_de($escena, (int) $al_asistente->id);
        $this->assertSame('asistente', $uno['estacion']);
        $this->assertSame($this->ts_de('client_assistant_messages', (int) $fila->id), strtotime($uno['estacion_desde_at']));

        $dos = $this->negocio_de($escena, (int) $a_soporte->id);
        $this->assertSame('soporte', $dos['estacion']);
        $this->assertSame('ticket #' . $ticket->id, $dos['estacion_detalle']);
        $this->assertSame($this->ts_de('support_messages', (int) $mensaje->id), strtotime($dos['estacion_desde_at']));

        // Un mensaje del cliente en un ticket CERRADO no lo lleva a Soporte.
        $cerrado = $this->crear_cliente('Ticket cerrado');
        $this->mensaje_del_cliente($this->crear_ticket($cerrado, 'closed'), 1);
        $this->assertNull($this->negocio_de($this->escena($admin), (int) $cerrado->id)['estacion']);
    }

    /**
     * Facturas y negocio también son estaciones (contrato C): un `escaneo` reciente lleva al cliente a Facturas
     * y un `agente_negocio` reciente al robot del negocio, con su detalle, y entran en la misma regla de «la
     * más reciente gana». Los robots trabajan con esos clientes en `atendiendo`.
     *
     * @return void
     */
    public function test_un_escaneo_reciente_lleva_al_cliente_a_facturas(): void
    {
        $admin = $this->crear_admin();

        $escanea = $this->crear_cliente('Escanea ahora');
        $this->mensaje_del_cliente($this->crear_ticket($escanea), 5);
        $this->actividad($escanea, 'escaneo', 1);

        $bot = $this->crear_cliente('Su bot atiende');
        $this->actividad($bot, 'agente_negocio', 3);

        $viejo = $this->crear_cliente('Escaneó hace rato');
        $this->actividad($viejo, 'escaneo', 15);

        $escena = $this->escena($admin);

        $uno = $this->negocio_de($escena, (int) $escanea->id);
        $this->assertSame('facturas', $uno['estacion'], 'El escaneo de hace 1 minuto es más nuevo que el ticket de hace 5.');
        $this->assertSame('escaneando una factura', $uno['estacion_detalle']);

        $dos = $this->negocio_de($escena, (int) $bot->id);
        $this->assertSame('negocio', $dos['estacion']);
        $this->assertSame('atendiendo a sus clientes', $dos['estacion_detalle']);

        $this->assertNull($this->negocio_de($escena, (int) $viejo->id)['estacion'], 'Un escaneo de hace 15 minutos ya no cuenta.');

        $facturas = $this->agente_de($escena, 'facturas');
        $this->assertSame('trabajando', $facturas['estado']);
        $this->assertContains((int) $escanea->id, $facturas['atendiendo']);
        $this->assertNotContains((int) $viejo->id, $facturas['atendiendo']);

        $negocio = $this->agente_de($escena, 'negocio');
        $this->assertSame('trabajando', $negocio['estado']);
        $this->assertContains((int) $bot->id, $negocio['atendiendo']);
    }

    /**
     * Una fila de `local_actividad_clientes` de hace `$minutos` minutos.
     *
     * @param Client $client
     * @param string $tipo
     * @param int    $minutos
     *
     * @return int Id de la fila.
     */
    private function actividad(Client $client, string $tipo, int $minutos): int
    {
        $cuando = AppTime::now()->subMinutes($minutos)->format('Y-m-d H:i:s');

        return (int) DB::table('local_actividad_clientes')->insertGetId([
            'client_id'   => $client->id,
            'tipo'        => $tipo,
            'usuario'     => null,
            'detalle'     => null,
            'ocurrido_at' => $cuando,
            'huella'      => sha1($tipo . $cuando . $client->id),
            'created_at'  => $cuando,
        ]);
    }

    /**
     * 🔴 `agrupado` es un estado FINAL (la foto viajó pegada a otra fila): no deja al dueño en el mostrador, no
     * lo pone en `atendiendo` y no suma pendientes. `recibido` sí: lo pone en el Asistente y al robot a trabajar.
     *
     * @return void
     */
    public function test_agrupado_no_cuenta_y_recibido_pone_al_cliente_en_el_asistente(): void
    {
        $admin    = $this->crear_admin();
        $agrupado = $this->crear_cliente('Solo una foto agrupada');
        $recibido = $this->crear_cliente('Le acaba de escribir');

        $antes = $this->agente_de($this->escena($admin), 'asistente')['pendientes'];

        $this->mensaje_al_asistente($agrupado, ClientAssistantMessage::ESTADO_AGRUPADO, 1);
        $this->mensaje_al_asistente($recibido, ClientAssistantMessage::ESTADO_RECIBIDO, 1);

        $escena    = $this->escena($admin);
        $asistente = $this->agente_de($escena, 'asistente');

        $this->assertNull($this->negocio_de($escena, (int) $agrupado->id)['estacion'], 'Una fila agrupada no lo deja en el mostrador.');
        $this->assertNotContains((int) $agrupado->id, $asistente['atendiendo']);
        $this->assertSame($antes, $asistente['pendientes'], 'Ni la fila agrupada ni la recibida suman pendientes.');

        $this->assertSame('asistente', $this->negocio_de($escena, (int) $recibido->id)['estacion']);
        $this->assertSame('trabajando', $asistente['estado']);
        $this->assertContains((int) $recibido->id, $asistente['atendiendo']);

        // Un `esperando` sí suma un pendiente.
        $this->mensaje_al_asistente($agrupado, ClientAssistantMessage::ESTADO_ESPERANDO, 1);
        $this->assertSame($antes + 1, $this->agente_de($this->escena($admin), 'asistente')['pendientes']);
    }

    /**
     * 🔴 La bandeja «Para Lucas»: una misión pendiente cae como `mision_pendiente` (con las primeras 70 letras y
     * la ruta del ticket) y deja de estar cuando Lucas la inicia; una revisión manual, un ticket delegado sin
     * caso y una tarea de Claude también están.
     *
     * @return void
     */
    public function test_la_bandeja_para_lucas(): void
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente('Ferre Total de prueba');

        $con_mision = $this->crear_ticket($client);
        $mision     = 'Corregir los recargos consolidados de la cuenta corriente cuando el pago se reparte entre varios movimientos.';
        SupportCodexCase::create(['support_ticket_id' => $con_mision->id, 'state' => 'mission_pending', 'mission' => $mision]);

        $iniciada = $this->crear_ticket($client);
        SupportCodexCase::create([
            'support_ticket_id'  => $iniciada->id,
            'state'              => 'mission_pending',
            'mission'            => 'Una misión que Lucas ya inició.',
            'mission_thread_id'  => 'hilo-de-codex',
            'mission_started_at' => AppTime::now()->subMinutes(5),
        ]);

        $en_revision = $this->crear_ticket($client);
        SupportCodexCase::create(['support_ticket_id' => $en_revision->id, 'state' => 'manual_review', 'review_reason' => 'Pide un reintegro']);

        $delegado = $this->crear_ticket($client);
        $delegado->escalated_at      = AppTime::now()->subMinutes(3);
        $delegado->escalation_reason = 'Quiere hablar con Lucas';
        $delegado->save();

        $tarea_id = DB::table('admin_tasks')->insertGetId([
            'created_by_admin_id' => $admin->id,
            'created_via'         => 'claude',
            'title'               => 'Llamar a Ferre Total',
            'is_done'             => false,
            'sort_order'          => 0,
            'created_at'          => AppTime::now()->format('Y-m-d H:i:s'),
            'updated_at'          => AppTime::now()->format('Y-m-d H:i:s'),
        ]);

        $escena = $this->escena($admin);

        $item = $this->item_de_bandeja($escena, 'soporte-mision-' . $con_mision->id);
        $this->assertNotNull($item, 'La misión pendiente está en la bandeja.');
        $this->assertSame('mision_pendiente', $item['tipo']);
        $this->assertSame('Misión · Ferre Total de prueba: ' . rtrim(mb_substr($mision, 0, 70)) . '…', $item['titulo']);
        $this->assertSame((int) $client->id, $item['client_id']);
        $this->assertSame('/soporte/ticket/' . $con_mision->id, $item['ruta']);
        $this->assertMatchesRegularExpression(self::ISO, $item['creado_at']);

        $this->assertNull($this->item_de_bandeja($escena, 'soporte-mision-' . $iniciada->id), 'La misión ya iniciada pasó a las oficinas.');

        $revision = $this->item_de_bandeja($escena, 'soporte-revision-' . $en_revision->id);
        $this->assertNotNull($revision);
        $this->assertSame('revision', $revision['tipo']);
        $this->assertStringContainsString('Pide un reintegro', $revision['titulo']);

        $caso = $this->item_de_bandeja($escena, 'soporte-caso-' . $delegado->id);
        $this->assertNotNull($caso);
        $this->assertSame('caso_delegado', $caso['tipo']);
        $this->assertSame('Ticket #' . $delegado->id . ' · Ferre Total de prueba: Quiere hablar con Lucas', $caso['titulo']);

        $tarea = $this->item_de_bandeja($escena, 'tarea-' . $tarea_id);
        $this->assertNotNull($tarea);
        $this->assertSame('tarea', $tarea['tipo']);
        $this->assertSame('/tareas', $tarea['ruta']);

        $this->assertNotNull($this->evento_de($escena, 'esc-' . $delegado->id), 'La delegación de hace 3 minutos es un evento.');
    }

    /**
     * Un slot reportado por el endpoint real del reporter aparece en `oficina.slots` con sus campos, el reporter
     * queda `ok`, el planificador trabaja (hay un slot en fase `plan`) y el resumen cuenta misiones y libres.
     *
     * @return void
     */
    public function test_un_slot_reportado_aparece_en_las_oficinas(): void
    {
        $admin = $this->crear_admin();

        $slots = [];
        for ($n = 1; $n <= 35; $n++) {
            $slots[] = ['slot' => $n, 'proyecto' => 'admin', 'estado' => 'libre', 'agentes' => []];
        }
        $slots[14] = [
            'slot' => 15, 'proyecto' => 'admin', 'estado' => 'trabajando', 'rama' => 'el-local', 'mision' => 'el-local',
            'fase' => 'plan', 'claim_iniciado_at' => '2026-11-03T10:05:00-03:00', 'ultima_actividad_at' => '2026-11-03T13:40:12Z',
            'sucios' => 2,
            'agentes' => [['rol' => 'planificador', 'runtime' => 'claude', 'estado' => 'busy', 'desde_at' => '2026-11-03T10:06:00-03:00', 'nombre' => null]],
        ];

        $this->postJson('/api/claude/local/oficina', [
            'reporter' => ['version' => '1.0.0', 'host' => 'ASUS', 'slots_total' => 35],
            'sesiones' => ['claude_busy' => 1, 'claude_idle' => 0, 'codex_activos' => 0],
            'slots'    => $slots,
        ], ['X-Claude-Task-Key' => self::CLAVE])->assertStatus(200);

        $escena  = $this->escena($admin);
        $oficina = $escena['oficina'];

        $this->assertTrue($oficina['reporter']['ok']);
        $this->assertSame('1.0.0', $oficina['reporter']['version']);
        $this->assertMatchesRegularExpression(self::ISO, $oficina['reporter']['last_seen_at']);
        $this->assertSame('trabajando', $oficina['planificador']['estado']);

        $this->assertCount(35, $oficina['slots']);
        $this->assertSame(range(1, 35), array_column($oficina['slots'], 'slot'), 'Ordenados por slot.');

        $quince = $oficina['slots'][14];
        $this->assertSame(
            ['slot', 'proyecto', 'estado', 'rama', 'mision', 'fase', 'claim_iniciado_at', 'ultima_actividad_at', 'sucios', 'agentes', 'reportado_at'],
            array_keys($quince)
        );
        $this->assertSame('trabajando', $quince['estado']);
        $this->assertSame('el-local', $quince['mision']);
        $this->assertSame('plan', $quince['fase']);
        $this->assertSame('2026-11-03T10:05:00-03:00', $quince['claim_iniciado_at']);
        $this->assertSame('2026-11-03T10:40:12-03:00', $quince['ultima_actividad_at']);
        $this->assertSame(2, $quince['sucios']);
        $this->assertSame('planificador', $quince['agentes'][0]['rol']);
        $this->assertMatchesRegularExpression(self::ISO, $quince['reportado_at']);

        $this->assertSame(1, $escena['resumen']['misiones_en_curso']);
        $this->assertSame(34, $escena['resumen']['slots_libres']);
        $this->assertSame(35, $escena['resumen']['slots_total']);
    }

    /**
     * Sin pulso del reporter (nunca mandó, o hace más de 5 minutos) `reporter.ok` es false.
     *
     * @return void
     */
    public function test_sin_pulso_del_reporter_ok_es_false(): void
    {
        $admin = $this->crear_admin();

        $reporter = $this->escena($admin)['oficina']['reporter'];
        $this->assertFalse($reporter['ok']);
        $this->assertNull($reporter['last_seen_at']);
        $this->assertNull($reporter['version']);

        AdminSetting::set(OficinaIngestService::PULSO_KEY, json_encode([
            'last_seen_at' => AppTime::now()->subMinutes(6)->toIso8601String(),
            'version'      => '1.0.0',
            'host'         => 'ASUS',
            'slots_total'  => 35,
            'sesiones'     => [],
        ]));

        $reporter = $this->escena($admin)['oficina']['reporter'];
        $this->assertFalse($reporter['ok'], 'Un pulso de hace 6 minutos ya no cuenta.');
        $this->assertMatchesRegularExpression(self::ISO, $reporter['last_seen_at']);
    }

    /**
     * La actividad que avisa el sistema del cliente (contrato C) llega a la escena: un escaneo pone al robot de
     * facturas a trabajar con ese cliente, marca al negocio activo y deja un evento `actividad`.
     *
     * @return void
     */
    public function test_la_actividad_del_cliente_llega_a_la_escena(): void
    {
        $admin  = $this->crear_admin();
        $client = $this->crear_cliente('Escanea facturas');
        $client->inbound_api_key = Str::random(40);
        $client->save();

        $this->postJson('/api/inbound/local/actividad', ['eventos' => [
            ['tipo' => 'escaneo', 'ocurrido_at' => AppTime::now()->subMinute()->toIso8601String(), 'usuario' => 'Pablo'],
        ]], ['X-Admin-Api-Key' => $client->inbound_api_key])->assertStatus(200);

        $escena = $this->escena($admin);

        $facturas = $this->agente_de($escena, 'facturas');
        $this->assertSame('trabajando', $facturas['estado']);
        $this->assertSame([(int) $client->id], array_values(array_intersect($facturas['atendiendo'], [(int) $client->id])));

        $negocio = $this->negocio_de($escena, (int) $client->id);
        $this->assertTrue($negocio['activo_reciente']);

        $fila = DB::table('local_actividad_clientes')->where('client_id', $client->id)->first();
        $evento = $this->evento_de($escena, 'act-' . $fila->id);
        $this->assertNotNull($evento);
        $this->assertSame('actividad', $evento['tipo']);
        $this->assertSame('facturas', $evento['agente']);
        $this->assertSame('escaneo', $evento['detalle']);
    }

    /**
     * El cache de 20 s: dos pedidos seguidos devuelven el mismo cálculo.
     *
     * @return void
     */
    public function test_la_escena_se_cachea(): void
    {
        $admin = $this->crear_admin();
        Cache::forget(EscenaService::CACHE_KEY);

        $primera = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/local/escena')->assertStatus(200)->json();
        $this->crear_cliente('Llegó después del primer pedido');
        $segunda = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/local/escena')->assertStatus(200)->json();

        $this->assertSame($primera, $segunda, 'Dentro de los 20 s se sirve lo cacheado.');
        $this->assertTrue(Cache::has(EscenaService::CACHE_KEY));
    }
}

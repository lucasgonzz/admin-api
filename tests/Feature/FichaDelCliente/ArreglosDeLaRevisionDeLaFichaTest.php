<?php

namespace Tests\Feature\FichaDelCliente;

use App\Events\SupportMessageReceived;
use App\Models\Admin;
use App\Models\SupportTicket;
use App\Services\SupportWhatsappOpenerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los arreglos de la revisión independiente de la ficha del cliente (misión cruzada `ficha-del-cliente`,
 * 11/11/2026): la búsqueda por términos, `primer_mensaje` y el resumen de los tickets del sistema en el índice, de
 * dónde sale `name_source` (y qué títulos pisa Martín), la migración de los títulos existentes y el resumen editable
 * desde la bandeja.
 */
class ArreglosDeLaRevisionDeLaFichaTest extends TestCase
{
    use DatabaseTransactions;
    use AndamiajeDeLaFicha;

    /** @var array<string, string> */
    private $headers = ['X-Claude-Task-Key' => 'arreglos-ficha-test', 'Accept' => 'application/json'];

    /** @var string */
    private $base = '/api/claude/support-codex/tickets/';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'arreglos-ficha-test']);
        Http::fake();
        Event::fake([SupportMessageReceived::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-11 13:00:00'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return Admin
     */
    private function admin(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Lucas ' . Str::random(4);
        $admin->email    = 'lucas+' . Str::random(10) . '@comerciocity.com';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Varias palabras: si la frase completa no aparece, busca cualquier término (≥ 3 caracteres, hasta 5) y ordena
     * por cuántos coinciden y después por fecha; `terminos`, `busqueda` y `coinciden` en la respuesta. Si la frase
     * aparece, solo esos mensajes, como frase.
     *
     * @return void
     */
    public function test_busqueda_por_terminos(): void
    {
        $client = $this->cliente();
        $viejo  = $this->ticket($client, 'closed', 30);
        $dos    = $this->mensaje($viejo, 'La impresora fiscal no imprime el ticket');
        $uno_a  = $this->mensaje($viejo, 'La impresora ya anda');
        $actual = $this->ticket($client);
        $uno_b  = $this->mensaje($actual, 'Tengo otro problema con el controlador fiscal');
        $this->mensaje($actual, 'Nada que ver');

        $terminos = $this->getJson($this->base . $actual->id . '/history-search?q=' . urlencode('¿impresora fiscal rota?'), $this->headers)->assertOk();
        $this->assertSame('terminos', $terminos->json('busqueda'));
        $this->assertSame(['impresora', 'fiscal', 'rota'], $terminos->json('terminos'));
        $this->assertSame([(int) $dos->id, (int) $uno_b->id, (int) $uno_a->id], array_column($terminos->json('coincidencias'), 'message_id'));
        $this->assertSame([2, 1, 1], array_column($terminos->json('coincidencias'), 'coinciden'));
        $this->assertStringContainsString('controlador fiscal', $terminos->json('coincidencias.1.fragmento'));

        $frase = $this->getJson($this->base . $actual->id . '/history-search?q=' . urlencode('impresora fiscal'), $this->headers)->assertOk();
        $this->assertSame('frase', $frase->json('busqueda'));
        $this->assertSame([(int) $dos->id], array_column($frase->json('coincidencias'), 'message_id'));
        $this->assertSame(2, $frase->json('coincidencias.0.coinciden'));

        /* Palabras cortas: la frase es el único término. Más de 5: los primeros 5. */
        $this->assertSame(['ya an'], $this->getJson($this->base . $actual->id . '/history-search?q=' . urlencode('ya an'), $this->headers)->assertOk()->json('terminos'));
        $this->assertSame(['uno', 'dos', 'tres', 'cuatro', 'cinco'], $this->getJson($this->base . $actual->id . '/history-search?q=' . urlencode('uno dos tres cuatro cinco seis UNO'), $this->headers)->assertOk()->json('terminos'));
    }

    /**
     * indice_tickets: `primer_mensaje` (≤ 150) solo en los tickets sin título; el resumen de un ticket del sistema
     * sin identidad verificada va en null, como en `history`.
     *
     * @return void
     */
    public function test_el_indice_suma_primer_mensaje_y_oculta_el_resumen_del_sistema_sin_identidad(): void
    {
        $client = $this->cliente();
        $sin_titulo = $this->ticket($client, 'closed', 9);
        $this->mensaje($sin_titulo, 'Buenas', 'admin');
        $this->mensaje($sin_titulo, str_repeat('No me deja facturar a consumidor final. ', 6));
        $con_titulo = $this->ticket($client, 'closed', 8, null, ['name' => 'Facturación']);
        $this->mensaje($con_titulo, 'Otra consulta');
        $del_sistema = $this->ticket($client, 'closed', 7, null, ['source' => 'erp', 'agentes_erp' => false, 'client_user_id' => 99, 'summary' => 'Resumen privado']);
        $actual = $this->ticket($client);

        $porId = array_column($this->getJson($this->base . $actual->id, $this->headers)->assertOk()->json('ticket.indice_tickets.tickets'), null, 'id');

        $primero = $porId[(int) $sin_titulo->id]['primer_mensaje'];
        $this->assertSame(150, mb_strlen($primero));
        $this->assertStringStartsWith('No me deja facturar', $primero, 'Es el primer mensaje del CLIENTE.');
        $this->assertNull($porId[(int) $con_titulo->id]['primer_mensaje']);
        $this->assertNull($porId[(int) $del_sistema->id]['resumen']);
        $this->assertNull($porId[(int) $del_sistema->id]['titulo']);
    }

    /**
     * De dónde sale `name_source` y qué títulos pisa Martín: el alta desde el panel y el opener con un nombre del
     * operador → lucas; el opener con un nombre automático → sistema (Martín lo pisa); el asunto del sistema del
     * cliente → cliente (no lo pisa). El contexto expone name_source.
     *
     * @return void
     */
    public function test_origen_del_titulo_y_lo_que_pisa_martin(): void
    {
        $admin  = $this->admin();
        $client = $this->cliente();

        $alta = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/support-ticket', [
            'client_id' => $client->id, 'client_user_id' => 5, 'name' => 'Consulta de Lucas',
        ])->assertStatus(201);
        $this->assertSame('lucas', SupportTicket::find($alta->json('model.id'))->name_source);

        $opener = app(SupportWhatsappOpenerService::class);
        $del_operador = $opener->resolve_or_create_ticket($client, (string) $client->phone, $admin, 'Lo que escribió Lucas')['ticket'];
        $this->assertSame('lucas', $del_operador->fresh()->name_source);
        $del_operador->status = 'closed';
        $del_operador->save();
        $automatico = $opener->resolve_or_create_ticket($client, (string) $client->phone, $admin, 'Prueba gratuita', 'sistema')['ticket'];
        $this->assertSame('sistema', $automatico->fresh()->name_source);
        $automatico->status = 'closed';
        $automatico->save();
        $sin_nombre = $opener->resolve_or_create_ticket($client, (string) $client->phone, $admin, '')['ticket'];
        $this->assertNull($sin_nombre->fresh()->name_source);

        /* Martín pisa el automático y conserva el de Lucas. */
        $token = $this->reservar($automatico);
        $this->postJson($this->base . $automatico->id . '/title', ['lease_token' => $token, 'titulo' => 'Alta de la prueba'], $this->headers)
            ->assertOk()->assertJsonPath('titulo_conservado', false);
        $this->assertSame('martin', $automatico->fresh()->name_source);
        $token = $this->reservar($del_operador);
        $this->postJson($this->base . $del_operador->id . '/title', ['lease_token' => $token, 'titulo' => 'Otro'], $this->headers)
            ->assertOk()->assertJsonPath('titulo_conservado', true)->assertJsonPath('titulo', 'Lo que escribió Lucas');

        /* El asunto que manda el cliente desde su sistema: cliente, y Martín no lo pisa. */
        $this->postJson('/api/inbound/support/messages', [
            'client_uuid' => $client->fresh()->uuid, 'ticket_uuid' => 'ticket-' . uniqid(), 'message_uuid' => 'mensaje-' . uniqid(),
            'client_user_id' => 5, 'sender_user_name' => 'Usuario del ERP', 'ticket_name' => 'No anda el lector', 'body' => 'Hola',
        ])->assertStatus(201);
        $del_cliente = SupportTicket::where('client_id', $client->id)->where('name', 'No anda el lector')->firstOrFail();
        $this->assertSame('cliente', $del_cliente->name_source);
        $token = $this->reservar($del_cliente);
        $this->postJson($this->base . $del_cliente->id . '/title', ['lease_token' => $token, 'titulo' => 'Lector de códigos', 'resumen' => 'Se reconfiguró el lector.'], $this->headers)
            ->assertOk()->assertJsonPath('titulo_conservado', true)->assertJsonPath('resumen', 'Se reconfiguró el lector.');
        $this->assertSame('No anda el lector', $del_cliente->fresh()->name);

        /* El contexto lo expone al lado de name y summary. */
        $contexto = $this->getJson($this->base . $automatico->id, $this->headers)->assertOk()->json('ticket');
        $this->assertSame('martin', $contexto['name_source']);
        $this->assertSame('Alta de la prueba', $contexto['name']);
    }

    /**
     * La migración de datos: los títulos no vacíos sin origen pasan a lucas, los de tickets del sistema a cliente; los
     * vacíos y los que ya tenían origen no se tocan.
     *
     * @return void
     */
    public function test_la_migracion_marca_los_titulos_existentes(): void
    {
        $client   = $this->cliente();
        $whatsapp = $this->ticket($client, 'closed', 5, null, ['name' => 'Puesto a mano']);
        $erp      = $this->ticket($client, 'closed', 4, null, ['name' => 'Asunto del ERP', 'source' => 'erp']);
        $vacio    = $this->ticket($client, 'closed', 3, null, ['name' => '  ']);
        $nulo     = $this->ticket($client, 'closed', 2);
        $marcado  = $this->ticket($client, 'closed', 1, null, ['name' => 'De Martín', 'name_source' => 'martin']);

        if (! class_exists('MarcarNameSourceDeLosTitulosExistentes', false)) {
            require base_path('database/migrations/2026_11_11_190300_marcar_name_source_de_los_titulos_existentes.php');
        }
        (new \MarcarNameSourceDeLosTitulosExistentes())->up();

        $this->assertSame('lucas', $whatsapp->fresh()->name_source);
        $this->assertSame('cliente', $erp->fresh()->name_source);
        $this->assertNull($vacio->fresh()->name_source);
        $this->assertNull($nulo->fresh()->name_source);
        $this->assertSame('martin', $marcado->fresh()->name_source);
    }

    /**
     * El resumen editable desde la bandeja: se guarda (≤ 600) con summary_updated_at, sin el detector de datos
     * sensibles (lo escribe Lucas); reenviar el mismo no cambia la fecha; vacío lo borra; más de 600, 422.
     *
     * @return void
     */
    public function test_el_resumen_se_edita_desde_la_bandeja(): void
    {
        $admin  = $this->admin();
        $ticket = $this->ticket($this->cliente());
        $ruta   = '/api/admin/support-ticket/' . $ticket->id;

        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['summary' => '  Se le dio la clave: 1234 al encargado.  '])->assertOk();
        $ticket->refresh();
        $this->assertSame('Se le dio la clave: 1234 al encargado.', $ticket->summary);
        $fecha = $ticket->summary_updated_at;
        $this->assertNotNull($fecha);

        Carbon::setTestNow(now()->addHour());
        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['summary' => 'Se le dio la clave: 1234 al encargado.', 'status' => 'open'])->assertOk();
        $this->assertTrue($ticket->fresh()->summary_updated_at->equalTo($fecha), 'El mismo resumen no cambia la fecha.');

        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['summary' => str_repeat('a', 601)])->assertStatus(422);
        $this->assertSame('Se le dio la clave: 1234 al encargado.', $ticket->fresh()->summary);

        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['summary' => ''])->assertOk();
        $this->assertNull($ticket->fresh()->summary);
        $this->assertTrue($ticket->fresh()->summary_updated_at->greaterThan($fecha));
    }
}

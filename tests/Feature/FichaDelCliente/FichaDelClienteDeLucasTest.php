<?php

namespace Tests\Feature\FichaDelCliente;

use App\Models\Admin;
use App\Models\ClientSupportContext;
use App\Models\ClientSupportMemoryEntry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las rutas de Lucas de la ficha del cliente (misión cruzada `ficha-del-cliente`, 11/11/2026): por API
 * (`claude/client-memory`, con la clave de ingesta) y por el panel (`admin/client/{id}/support-memory`, con la sesión
 * Sanctum del admin), con las mismas reglas que Martín y `source: lucas`.
 */
class FichaDelClienteDeLucasTest extends TestCase
{
    use DatabaseTransactions;
    use AndamiajeDeLaFicha;

    /** @var array<string, string> */
    private $headers = ['X-Claude-Task-Key' => 'ficha-lucas-test', 'Accept' => 'application/json'];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'ficha-lucas-test']);
        Http::fake();
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
     * API: GET con y sin historial, POST con source lucas; sin la clave 401; client_id obligatorio y existente.
     *
     * @return void
     */
    public function test_la_api_de_lucas(): void
    {
        $client = $this->cliente();
        $vigente = $this->entrada($client, 'Prefiere audios.', ['categoria' => 'preferencia']);
        $this->entrada($client, 'Vieja', ['status' => 'archivada', 'archived_reason' => 'Ya no']);

        $this->getJson('/api/claude/client-memory?client_id=' . $client->id)->assertStatus(401);
        $this->getJson('/api/claude/client-memory', $this->headers)->assertStatus(422)->assertJsonPath('error', 'parámetros inválidos');
        $this->getJson('/api/claude/client-memory?client_id=999999999', $this->headers)->assertStatus(422);

        $solo = $this->getJson('/api/claude/client-memory?client_id=' . $client->id, $this->headers)->assertOk();
        $this->assertSame([(int) $vigente->id], array_column($solo->json('entradas'), 'id'));
        $this->assertSame(1, $solo->json('total_vigentes'));
        $this->assertSame(1, $solo->json('ficha_cliente.total_vigentes'));

        $todo = $this->getJson('/api/claude/client-memory?client_id=' . $client->id . '&incluir_historial=1', $this->headers)->assertOk();
        $this->assertCount(2, $todo->json('entradas'));
        $this->assertSame('Ya no', $todo->json('entradas.0.archived_reason'));

        $alta = $this->postJson('/api/claude/client-memory', ['client_id' => $client->id, 'operaciones' => [
            ['accion' => 'reemplazar', 'id' => $vigente->id, 'texto' => 'Prefiere audios cortos.'],
        ]], $this->headers)->assertOk();
        $nueva = ClientSupportMemoryEntry::find($alta->json('resultados.0.id'));
        $this->assertSame('lucas', $nueva->source);
        $this->assertSame('reemplazada', $vigente->fresh()->status);

        $this->postJson('/api/claude/client-memory', ['client_id' => $client->id, 'operaciones' => [
            ['accion' => 'agregar', 'categoria' => 'otro', 'texto' => 'Su contraseña: Perro1'],
        ]], $this->headers)->assertStatus(422)->assertJsonPath('error', 'dato_sensible');
    }

    /**
     * Panel: sin sesión 401; con sesión lista todo (con el historial por defecto), agrega (admin_id), edita
     * (reemplaza), da de baja (archiva) y devuelve el payload releído; una entrada de otro cliente es 422.
     *
     * @return void
     */
    public function test_el_panel(): void
    {
        $client = $this->cliente();
        $otro   = $this->cliente('Otro');
        $ajena  = $this->entrada($otro, 'Del otro');
        $ticket = $this->ticket($client, 'closed', 3, null, ['name' => 'Importar lista', 'summary' => 'Se importó la lista de Bronzen.']);
        $this->mensaje($ticket, 'Hola');
        $base   = '/api/admin/client/' . $client->id . '/support-memory';

        $this->getJson($base)->assertStatus(401);
        $this->postJson($base, ['categoria' => 'decision', 'texto' => 'x'])->assertStatus(401);

        $admin = $this->admin();
        $vacio = $this->actingAs($admin, 'sanctum')->getJson($base)->assertOk();
        $this->assertSame([], $vacio->json('entradas'));
        $this->assertSame(ClientSupportMemoryEntry::CATEGORIAS, $vacio->json('categorias'));
        $this->assertSame(60, $vacio->json('tope_vigentes'));
        $this->assertSame([(int) $ticket->id], array_column($vacio->json('tickets'), 'id'));
        $this->assertSame('Importar lista', $vacio->json('tickets.0.titulo'));
        $this->assertSame('Se importó la lista de Bronzen.', $vacio->json('tickets.0.resumen'));
        $this->assertSame(1, $vacio->json('tickets.0.mensajes'));

        $alta = $this->actingAs($admin, 'sanctum')->postJson($base, ['categoria' => 'compromiso', 'texto' => 'Le mandamos el video el lunes.', 'vigente_hasta' => '2026-11-16'])
            ->assertStatus(201)->assertJsonPath('ok', true)->assertJsonPath('resultado.accion', 'agregar');
        $id = $alta->json('resultado.id');
        $this->assertSame([$id], array_column($alta->json('entradas'), 'id'));
        $this->assertSame((int) $admin->id, $alta->json('entradas.0.admin_id'));
        $this->assertSame($admin->name, $alta->json('entradas.0.admin_name'));
        $this->assertSame('lucas', $alta->json('entradas.0.source'));

        $edicion = $this->actingAs($admin, 'sanctum')->putJson($base . '/' . $id, ['texto' => 'Le mandamos el video el martes.'])->assertOk();
        $nueva = $edicion->json('resultado.id');
        $this->assertNotSame($id, $nueva);
        $estados = array_column($edicion->json('entradas'), 'status', 'id');
        $this->assertSame('vigente', $estados[$nueva]);
        $this->assertSame('reemplazada', $estados[$id]);
        $porId = array_column($edicion->json('entradas'), null, 'id');
        $this->assertSame($nueva, $porId[$id]['reemplazada_por']);
        $this->assertSame('2026-11-16', $porId[$nueva]['vigente_hasta'], 'Sin vigente_hasta, hereda el de la vieja.');
        $this->assertSame('compromiso', $porId[$nueva]['categoria']);

        $baja = $this->actingAs($admin, 'sanctum')->postJson($base . '/' . $nueva . '/archive', ['motivo' => 'Ya se mandó.'])->assertOk();
        $porId = array_column($baja->json('entradas'), null, 'id');
        $this->assertSame('archivada', $porId[$nueva]['status']);
        $this->assertSame('Ya se mandó.', $porId[$nueva]['archived_reason']);
        $this->assertSame(0, $baja->json('total_vigentes'));

        $soloVigentes = $this->actingAs($admin, 'sanctum')->getJson($base . '?incluir_historial=0')->assertOk();
        $this->assertSame([], $soloVigentes->json('entradas'));

        $this->actingAs($admin, 'sanctum')->putJson($base . '/' . $ajena->id, ['texto' => 'Pisado'])->assertStatus(422)->assertJsonPath('error', 'entrada_ajena');
        $this->actingAs($admin, 'sanctum')->postJson($base . '/' . $ajena->id . '/archive', ['motivo' => 'x'])->assertStatus(422)->assertJsonPath('error', 'entrada_ajena');
        $this->actingAs($admin, 'sanctum')->postJson($base, ['categoria' => 'inventada', 'texto' => 'x'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson($base, ['categoria' => 'otro', 'texto' => 'La clave es 1234'])->assertStatus(422)->assertJsonPath('error', 'dato_sensible');
        $this->actingAs($admin, 'sanctum')->postJson($base . '/' . $id . '/archive', [])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/client/999999999/support-memory')->assertStatus(404);
        $this->assertSame('Del otro', $ajena->fresh()->texto);
    }

    /**
     * Panel, ficha operativa: GET y PUT de la prosa de Lucas; crea la fila si no había, y nunca toca notas_internas.
     * Vacía = la borra. Sin sesión, 401.
     *
     * @return void
     */
    public function test_la_ficha_operativa_desde_el_panel(): void
    {
        $client = $this->cliente();
        $ruta   = '/api/admin/client/' . $client->id . '/support-memory/ficha-operativa';
        $admin  = $this->admin();

        $this->putJson($ruta, ['ficha_operativa' => 'x'])->assertStatus(401);
        $this->actingAs($admin, 'sanctum')->getJson($ruta)->assertOk()->assertJson(['client_id' => (int) $client->id, 'ficha_operativa' => null]);

        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['ficha_operativa' => '  Habla con Gastón.  '])->assertOk()->assertJsonPath('ficha_operativa', 'Habla con Gastón.');
        $this->assertSame('panel', ClientSupportContext::where('client_id', $client->id)->value('created_via'));

        ClientSupportContext::where('client_id', $client->id)->update(['notas_internas' => 'Nota privada']);
        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['ficha_operativa' => 'Prosa nueva'])->assertOk();
        $this->assertSame('Nota privada', ClientSupportContext::where('client_id', $client->id)->value('notas_internas'));
        $this->actingAs($admin, 'sanctum')->getJson($ruta)->assertOk()->assertJsonPath('ficha_operativa', 'Prosa nueva')->assertJsonMissing(['notas_internas' => 'Nota privada']);

        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['ficha_operativa' => null])->assertOk()->assertJsonPath('ficha_operativa', null);
        $this->assertSame('Nota privada', ClientSupportContext::where('client_id', $client->id)->value('notas_internas'));
        $this->actingAs($admin, 'sanctum')->putJson($ruta, [])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson($ruta, ['ficha_operativa' => str_repeat('a', 20001)])->assertStatus(422);

        /* Por uuid también (el patrón de las rutas del cliente). */
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/client/' . $client->fresh()->uuid . '/support-memory/ficha-operativa')->assertOk();
    }
}

<?php

namespace Tests\Feature\FichaDelCliente;

use App\Models\Client;
use App\Models\ClientSupportMemoryEntry;
use Illuminate\Database\QueryException;
use Tests\TestCase;

/**
 * El `lockForUpdate` sobre las vigentes de una ficha VACÍA toma un bloqueo de hueco y dos altas encimadas del mismo
 * cliente pueden terminar en deadlock (misión cruzada `ficha-del-cliente`, 11/11/2026, arreglo de la revisión): la
 * transacción se reintenta una vez.
 *
 * 🔴 SIN `DatabaseTransactions` a propósito: Laravel solo reintenta la transacción de más afuera (adentro de otra,
 * un deadlock sube como `DeadlockException`), así que el test tiene que correr con la del servicio como la primera.
 * Borra lo que crea en `tearDown()`.
 */
class ReintentoDeLaFichaAnteUnDeadlockTest extends TestCase
{
    /** @var array<string, string> */
    private $headers = ['X-Claude-Task-Key' => 'deadlock-ficha-test', 'Accept' => 'application/json'];

    /** @var Client|null */
    private $client;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.claude_task_ingest.key' => 'deadlock-ficha-test']);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ClientSupportMemoryEntry::flushEventListeners();
        if ($this->client !== null) {
            ClientSupportMemoryEntry::where('client_id', $this->client->id)->delete();
            $this->client->forceDelete();
        }
        parent::tearDown();
    }

    /**
     * El primer intento choca con un deadlock simulado al crear la entrada: el segundo la escribe una sola vez.
     *
     * @return void
     */
    public function test_un_deadlock_se_reintenta_una_vez(): void
    {
        $this->client = Client::create([
            'name' => 'Deadlock ' . uniqid(), 'company_name' => 'Negocio deadlock', 'phone' => '+549341' . random_int(1000000, 9999999),
            'is_active' => true, 'api_url' => 'https://api-deadlock.test', 'api_key' => 'secreto',
        ]);

        $intentos = 0;
        ClientSupportMemoryEntry::creating(function () use (&$intentos) {
            $intentos++;
            if ($intentos === 1) {
                throw new QueryException('insert into client_support_memory_entries', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        $this->postJson('/api/claude/client-memory', ['client_id' => $this->client->id, 'operaciones' => [
            ['accion' => 'agregar', 'categoria' => 'decision', 'texto' => 'La carga arranca el lunes.'],
        ]], $this->headers)->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(2, $intentos);
        $this->assertSame(1, ClientSupportMemoryEntry::where('client_id', $this->client->id)->count());
    }
}

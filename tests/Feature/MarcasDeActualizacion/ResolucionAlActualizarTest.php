<?php

namespace Tests\Feature\MarcasDeActualizacion;

use App\Jobs\RefreshClientUpgradeMarksJob;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Services\ClientUpgradeMarkService;
use App\Services\ReleaseArtifactService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\SoporteMartin2511\SoporteMartinHelpers;
use Tests\TestCase;

/**
 * CA8 — Resolución de la marca «requiere actualización»: la versión objetivo es la primera publicada cuyo tag
 * contiene los commits de la corrección (GitHub sustituido por un `ReleaseArtifactService` de mentira); se
 * resuelve sola cuando el cliente termina un upgrade a esa versión o una posterior, deja la misión de soporte
 * instalada sin acuerdo (Martín la verifica) y, si GitHub falla, cae a la fecha de publicación.
 */
class ResolucionAlActualizarTest extends TestCase
{
    use DatabaseTransactions;
    use SoporteMartinHelpers;

    /** GitHub de mentira: qué versiones contienen el commit y si responde. */
    private $github;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparar_puente();
        $this->github = new class extends ReleaseArtifactService {
            /** @var array<int, string> Versiones cuyo tag contiene los commits. */
            public $contienen = ['4.3.86'];
            /** @var bool Si es true, GitHub «no responde». */
            public $caido = false;
            /** @var int Cuántas consultas recibió. */
            public $consultas = 0;

            public function commit_in_tag(string $repo, string $sha, string $version): bool
            {
                ++$this->consultas;
                if ($this->caido) {
                    throw new \RuntimeException('GitHub caído (simulado).');
                }
                return in_array($version, $this->contienen, true);
            }
        };
        $this->app->instance(ReleaseArtifactService::class, $this->github);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Versión publicada del catálogo.
     *
     * @param  string $codigo
     * @param  Carbon $publicada
     * @return Version
     */
    private function version(string $codigo, Carbon $publicada): Version
    {
        return Version::create(['uuid' => (string) Str::uuid(), 'version' => $codigo, 'title' => 'Versión ' . $codigo,
            'status' => 'published', 'published_at' => $publicada]);
    }

    /**
     * Upgrade del cliente a una versión, cerrado en `terminada` (pasa por el hook del modelo).
     *
     * @param  Client  $cliente
     * @param  Version $destino
     * @return ClientVersionUpgrade
     */
    private function actualizar(Client $cliente, Version $destino): ClientVersionUpgrade
    {
        $api = ClientApi::create(['uuid' => (string) Str::uuid(), 'client_id' => $cliente->id,
            'url' => 'https://api-destino.test', 'path' => 'destino/' . Str::random(6), 'hosting_type' => 'shared_hosting']);
        $upgrade = ClientVersionUpgrade::create(['client_id' => $cliente->id, 'from_version_id' => $cliente->fresh()->current_version_id,
            'to_version_id' => $destino->id, 'status' => 'pendiente', 'scheduled_date' => now()->toDateString(),
            'target_client_api_id' => $api->id]);
        $upgrade->update(['status' => 'terminada']);
        return $upgrade;
    }

    /**
     * Lista las marcas del cliente por el panel (con un admin humano).
     *
     * @param  int    $client_id
     * @param  string $estado
     * @return array<string, mixed>
     */
    private function marcas(int $client_id, string $estado = 'abiertas'): array
    {
        $admin = Admin::firstOrCreate(['email' => 'lucas-marcas@test.local'], ['name' => 'Lucas', 'password' => bcrypt('prueba')]);
        return $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/client-upgrade-marks?estado=' . $estado . '&client_id=' . $client_id)->assertOk()->json();
    }

    public function test_resuelve_por_tag_al_instalar_la_version_que_contiene_el_arreglo(): void
    {
        Queue::fake();
        $actual = $this->version('4.3.84', now()->subDays(10));
        $ticket = $this->ticket(['current_version_id' => $actual->id]);
        $cliente = Client::findOrFail($ticket->client_id);
        $mission = $this->mision_con_evidencia($ticket, ['empresa-api' => str_repeat('a', 40)]);
        $v85 = $this->version('4.3.85', now()->addHour());
        $v86 = $this->version('4.3.86', now()->addHours(2));

        $this->artisan('client-upgrade-marks:refresh')->assertExitCode(0);
        $lista = $this->marcas($cliente->id);
        $this->assertSame('actualizar', $lista['clients'][0]['recomendacion']);
        $this->assertSame('4.3.86', $lista['clients'][0]['version_objetivo']);
        $this->assertSame('4.3.86', $lista['clients'][0]['marks'][0]['available_version']);
        $this->assertSame('tag', $lista['clients'][0]['marks'][0]['available_by']);
        $this->assertSame('lista_para_actualizar', $lista['clients'][0]['marks'][0]['estado']);
        $this->assertSame('4.3.84', $lista['clients'][0]['current_version']);

        // A 4.3.85 (no contiene el arreglo): la marca sigue abierta.
        $this->actualizar($cliente, $v85);
        Queue::assertPushed(RefreshClientUpgradeMarksJob::class, function ($job) use ($cliente) {
            return (int) $job->client_id === (int) $cliente->id;
        });
        (new RefreshClientUpgradeMarksJob($cliente->id))->handle(app(ClientUpgradeMarkService::class));
        $this->assertSame('lista_para_actualizar', $this->marcas($cliente->id)['clients'][0]['marks'][0]['estado']);
        $this->assertTrue((bool) $cliente->fresh()->requires_upgrade);
        $this->assertNull($mission->fresh()->installed_at);

        // A 4.3.86: se resuelve, el cliente sale del filtro y la misión queda instalada sin acuerdo.
        $this->actualizar($cliente, $v86);
        (new RefreshClientUpgradeMarksJob($cliente->id))->handle(app(ClientUpgradeMarkService::class));
        $this->assertCount(0, $this->marcas($cliente->id)['clients']);
        $resuelta = $this->marcas($cliente->id, 'resueltas')['clients'][0]['marks'][0];
        $this->assertSame('instalada', $resuelta['resolved_reason']);
        $this->assertSame('4.3.86', $resuelta['resolved_version']);
        $this->assertFalse((bool) $cliente->fresh()->requires_upgrade);
        $this->assertNotNull($mission->fresh()->installed_at);
        $this->assertNull($mission->fresh()->installed_schedule_id);

        $this->getJson($this->base . '/tickets/' . $ticket->id, $this->headers)
            ->assertJsonPath('ticket.mission_delivery.installed_via', 'actualizacion')
            ->assertJsonPath('ticket.mission_delivery.version', '4.3.86')
            ->assertJsonPath('ticket.mission_delivery.target', 'sistema');
        $ultimo = DB::table('support_messages')->where('support_ticket_id', $ticket->id)->max('id');
        $this->postJson($this->base . '/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'pc-lucas', 'expected_message_id' => $ultimo, 'supports_delivery_verification' => true,
        ], $this->headers)->assertOk()->assertJsonPath('ticket.reason', 'mission_delivery_verification');
    }

    public function test_con_github_caido_resuelve_por_fecha(): void
    {
        $this->github->caido = true;
        $ticket = $this->ticket();
        $cliente = Client::findOrFail($ticket->client_id);
        $this->mision_con_evidencia($ticket, ['empresa-api' => str_repeat('a', 40)]);
        $this->version('4.3.85', now()->subDay());
        $this->version('4.3.86', now()->addHour());

        $this->artisan('client-upgrade-marks:refresh')->assertExitCode(0);
        $marca = $this->marcas($cliente->id)['clients'][0]['marks'][0];
        $this->assertSame('4.3.86', $marca['available_version']);
        $this->assertSame('fecha', $marca['available_by']);
        $this->assertSame('lista_para_actualizar', $marca['estado']);
        $this->assertGreaterThan(0, $this->github->consultas);
    }
}

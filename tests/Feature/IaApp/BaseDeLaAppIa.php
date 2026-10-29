<?php

namespace Tests\Feature\IaApp;

use App\Models\Client;
use App\Models\ClientApi;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * Lo compartido por las pruebas de la app del agente del dueño en el admin (misión cruzada
 * `app-ia-y-memoria`, 29/10/2026).
 *
 * 🔴 Nada sale a la red: `fakear()` cambia la fábrica por una `HttpFactorySinSalida` limpia (los stubs de
 * `Http::fake()` se acumulan y gana el primero) y NO pone comodín: un pedido que la prueba no falseó lo
 * frena el freno de la suite y la prueba falla.
 */
abstract class BaseDeLaAppIa extends TestCase
{
    use DatabaseTransactions;

    /** Clave de `claude/*` de las pruebas. */
    const CLAVE_CLAUDE = 'clave-de-prueba-app-ia';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE_CLAUDE]);
    }

    /**
     * @param array<string, mixed> $stubs
     *
     * @return void
     */
    protected function fakear(array $stubs): void
    {
        Http::swap(new HttpFactorySinSalida());
        Http::fake($stubs);
    }

    /**
     * @return array<string, string>
     */
    protected function headers_claude(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE_CLAUDE, 'Accept' => 'application/json'];
    }

    /**
     * Un cliente activo con su frente en el shared (la base de la API lleva `/public`).
     *
     * @param string      $slug
     * @param string|null $host  Host de la API (por defecto `api-{slug}.test`).
     * @param bool        $activo
     *
     * @return Client
     */
    protected function crear_cliente(string $slug, ?string $host = null, bool $activo = true): Client
    {
        $client                  = new Client();
        $client->name            = 'Dueño de ' . $slug;
        $client->company_name    = 'Negocio ' . $slug;
        $client->slug            = $slug . '-' . strtolower(Str::random(4));
        $client->is_active       = $activo;
        $client->api_key         = 'clave-admin-' . $slug . '-' . Str::random(10);
        $client->inbound_api_key = 'clave-entrada-' . $slug . '-' . Str::random(10);
        $client->save();

        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://' . ($host !== null ? $host : 'api-' . $client->slug . '.test');
        $api->spa_url      = 'https://' . $client->slug . '.test';
        $api->path         = 'ejemplo/' . $client->slug;
        $api->hosting_type = 'shared_hosting';
        $api->save();

        $client->active_client_api_id = $api->id;
        $client->save();

        return $client->fresh();
    }

    /**
     * La base de la API del cliente como la arma el resolver (shared ⇒ `/public`).
     *
     * @param Client $client
     *
     * @return string
     */
    protected function base(Client $client): string
    {
        return 'https://api-' . $client->slug . '.test/public';
    }
}

<?php

namespace Tests\Feature\AccesoSoporte;

use App\Models\Client;
use App\Models\ClientApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\CatalogoDelClientePorClaude\BaseDelCatalogoPorClaude;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Andamiaje común de los tests de `POST claude/clients/{id}/acceso-soporte` (misión cruzada `acceso-soporte-firmado`, 30/10/2026).
 *
 * Reusa el de `catalogo/*` (clave de ingesta, headers, constructores de cliente y frentes, captura del log) y suma lo propio del
 * acceso de soporte: un cliente con su frente activo, un token de prueba de 64 alfanuméricos y la forma de falsear al `empresa-api`
 * del cliente.
 *
 * 🔴 LAS DOS COSAS QUE ESTE BLOQUE NO NEGOCIA, y que todos los archivos del directorio vigilan:
 *
 *  - la `clients.api_key` del cliente NO sale por ningún lado (respuesta, error, log);
 *  - el TOKEN solo existe en el campo `url` de la respuesta de éxito: ni en un error, ni en el log.
 *
 * Nada sale a internet: el `TestCase` base cambia el cliente HTTP por uno que lanza ante todo pedido que ningún `Http::fake()` atienda.
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDelAccesoDeSoporte extends BaseDelCatalogoPorClaude
{
    /** Nombre del cliente de los tests (el que se confirma en `confirm_client_name`). */
    const NOMBRE = 'Doblep Distribuciones';

    /** URL del `empresa-api` del frente de shared hosting de los tests (la API vive bajo `/public`). */
    const URL_DE_EMISION = 'https://api-doblep.ejemplo.test/public/api/admin-sync/acceso-soporte';

    /** @var string La `clients.api_key` del cliente de los tests. */
    protected $clave;

    /** @var string El token que "emite" el `empresa-api` falso: 64 alfanuméricos. */
    protected $token;

    /** @var Client El cliente de los tests, con su frente de shared hosting activo. */
    protected $cliente;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clave   = 'sk-acceso-' . Str::random(30);
        $this->token   = Str::random(64);
        $this->cliente = $this->crear_cliente(self::NOMBRE, $this->clave);

        $this->crear_frente($this->cliente, 'doblep', 'shared_hosting', null, true);
    }

    /**
     * La URL del endpoint para un cliente.
     *
     * @param Client|int|string|null $cliente Cliente, id o uuid (el de los tests si es null).
     *
     * @return string
     */
    protected function url_del_endpoint($cliente = null): string
    {
        $cliente = $cliente === null ? $this->cliente : $cliente;
        $id      = $cliente instanceof Client ? $cliente->id : $cliente;

        return '/api/claude/clients/' . $id . '/acceso-soporte';
    }

    /**
     * Llama al endpoint con la clave de ingesta.
     *
     * @param array<string, mixed> $pedido  Cuerpo del pedido.
     * @param Client|null          $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir(array $pedido = [], Client $cliente = null)
    {
        return $this->postJson($this->url_del_endpoint($cliente), $pedido, $this->headers());
    }

    /**
     * Llama al endpoint para EMITIR de verdad (`dry_run = false`, con el nombre bien confirmado).
     *
     * @param array<string, mixed> $extra   Parámetros que se suman o pisan.
     * @param Client|null          $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_de_verdad(array $extra = [], Client $cliente = null)
    {
        $nombre = $cliente === null ? self::NOMBRE : $cliente->name;

        return $this->pedir(array_merge(['dry_run' => false, 'confirm_client_name' => $nombre], $extra), $cliente);
    }

    /**
     * Pone una fábrica de HTTP LIMPIA y la falsea con lo dado (los stubs de `Http::fake()` se acumulan: un segundo `fake()` no pisa al
     * primero). Parte de `HttpFactorySinSalida` para que lo que ningún stub atienda no pueda salir a internet.
     *
     * @param array<string, mixed> $stubs Lo que se le pasa a `Http::fake()`.
     *
     * @return void
     */
    protected function http_falso(array $stubs): void
    {
        Http::swap(new HttpFactorySinSalida());

        Http::fake($stubs);
    }

    /**
     * Falsea al cliente: contesta lo mismo a cualquier pedido.
     *
     * @param mixed                $cuerpo  Cuerpo (array = JSON, string = texto crudo).
     * @param int                  $status  HTTP.
     * @param array<string, mixed> $headers Headers de la respuesta.
     *
     * @return void
     */
    protected function el_cliente_contesta($cuerpo, int $status = 200, array $headers = []): void
    {
        $this->http_falso(['*' => Http::response($cuerpo, $status, $headers)]);
    }

    /**
     * Falsea al cliente con la respuesta buena del contrato C1.
     *
     * @return void
     */
    protected function el_cliente_emite(): void
    {
        $this->el_cliente_contesta($this->emision_valida(), 200);
    }

    /**
     * El cuerpo de un 200 válido del contrato C1.
     *
     * @return array<string, mixed>
     */
    protected function emision_valida(): array
    {
        return ['ok' => true, 'token' => $this->token, 'vence_en_segundos' => 120, 'usuario_id' => 12];
    }

    /**
     * El cuerpo JSON de un pedido que salió hacia el cliente.
     *
     * @param \Illuminate\Http\Client\Request $request Pedido capturado.
     *
     * @return array<string, mixed>
     */
    protected function cuerpo_enviado($request): array
    {
        $cuerpo = json_decode($request->body(), true);

        return is_array($cuerpo) ? $cuerpo : [];
    }

    /**
     * Un frente (`ClientApi`) a medida: para los casos con una URL o un SPA que `crear_frente()` no arma.
     *
     * @param Client               $client    Cliente dueño.
     * @param array<string, mixed> $atributos `url`, `spa_url`, `hosting_type`, `path`... a sumar o pisar.
     * @param bool                 $activa    true: queda como la API activa del cliente.
     *
     * @return ClientApi
     */
    protected function crear_frente_a_medida(Client $client, array $atributos, bool $activa = false): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-amedida.' . self::DOMINIO;
        $api->path         = 'amedida/api';
        $api->spa_url      = 'https://amedida.' . self::DOMINIO;
        $api->hosting_type = 'shared_hosting';

        foreach ($atributos as $campo => $valor) {
            $api->{$campo} = $valor;
        }

        $api->save();

        if ($activa) {
            $client->active_client_api_id = $api->id;
            $client->save();
        }

        return $api;
    }

    /**
     * 🔴 El token no está en la respuesta: ni tal cual ni escapado como JSON.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a revisar.
     * @param string                           $donde     Para el mensaje de la falla.
     *
     * @return void
     */
    protected function assertSinElToken($respuesta, string $donde = ''): void
    {
        $this->assertStringNotContainsString($this->token, $this->cuerpo($respuesta), $donde . ' La respuesta lleva el token.');
        $this->assertStringNotContainsString($this->token, (string) $respuesta->getContent(), $donde . ' La respuesta (cruda) lleva el token.');
    }

    /**
     * 🔴 Ninguna línea de log lleva el token.
     *
     * @param \Psr\Log\AbstractLogger $registro El registro devuelto por `capturar_el_log()`.
     *
     * @return void
     */
    protected function assertLogSinElToken($registro): void
    {
        foreach ($registro->lineas as $linea) {
            $this->assertStringNotContainsString($this->token, $linea, 'Una línea de log lleva el token.');
        }
    }
}

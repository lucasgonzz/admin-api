<?php

namespace Tests\Feature\CatalogoDelClientePorClaude;

use App\Models\Client;
use App\Models\ClientApi;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

/**
 * Andamiaje común de los tests de `claude/clients/{id}/catalogo/*` (misión cruzada
 * `implementacion-dos-sistemas`, 6/10/2026): la clave de ingesta, los headers, los constructores de
 * un cliente con sus frentes y las dos aserciones que gobiernan TODO este bloque.
 *
 * 🔴 LAS DOS ASERCIONES QUE NO SE NEGOCIAN:
 *
 *  - `assertSinLaClave()`: la `api_key` del cliente NO está en el cuerpo de la respuesta, ni en su
 *    forma escapada como JSON. Cada test que devuelve algo la llama con el VALOR de la clave, no
 *    con una forma parecida.
 *  - `capturar_el_log()`: reemplaza el log por un registro propio para poder afirmar qué se escribió
 *    y que ninguna línea lleva la clave.
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDelCatalogoPorClaude extends TestCase
{
    use DatabaseTransactions;

    /** Clave de ingesta de las requests de los tests. */
    const CLAVE_DE_INGESTA = 'clave-de-prueba-claude-catalogo-del-cliente';

    /**
     * Dominio de las APIs de los clientes de los tests.
     *
     * 🔴 `.test` A PROPÓSITO y no `comerciocity.com` (5/10/2026): una fixture con una URL real es una
     * llamada que un test puede llegar a hacer, y la suite no tiene que poder pegarle a ningún sistema
     * de un cliente. Un host `.test` no resuelve en ningún lado.
     */
    const DOMINIO = 'ejemplo.test';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.claude_task_ingest.key' => self::CLAVE_DE_INGESTA]);
    }

    /**
     * Headers con la clave de ingesta.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['X-Claude-Task-Key' => self::CLAVE_DE_INGESTA, 'Accept' => 'application/json'];
    }

    /**
     * Cuerpo de la respuesta como texto, con los acentos y las barras SIN escapar: `getContent()`
     * escapa los no-ASCII y las barras, y arruina cualquier comparación de texto.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a leer.
     *
     * @return string
     */
    protected function cuerpo($respuesta): string
    {
        return (string) json_encode($respuesta->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 🔴 La clave del cliente no está en la respuesta: ni tal cual ni escapada como JSON.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a revisar.
     * @param string                           $clave     El VALOR de `clients.api_key`.
     * @param string                           $donde     Para el mensaje de la falla.
     *
     * @return void
     */
    protected function assertSinLaClave($respuesta, string $clave, string $donde = ''): void
    {
        $this->assertNotSame('', $clave, 'El test tiene que pasar el valor real de la clave.');

        $this->assertStringNotContainsString($clave, $this->cuerpo($respuesta), $donde . ' La respuesta lleva el valor de la clave del cliente.');
        $this->assertStringNotContainsString($clave, (string) $respuesta->getContent(), $donde . ' La respuesta (cruda) lleva el valor de la clave del cliente.');
    }

    /**
     * Cliente de prueba, sin frentes.
     *
     * @param string               $nombre    `clients.name` (el que se confirma en `confirm_client_name`).
     * @param string|null          $api_key   Clave del cliente; null genera una de 40 caracteres, '' la deja vacía.
     * @param array<string, mixed> $atributos Atributos a sumar o pisar.
     *
     * @return Client
     */
    protected function crear_cliente(string $nombre = 'Doblep Distribuciones', ?string $api_key = null, array $atributos = []): Client
    {
        $client                  = new Client();
        $client->name            = $nombre;
        $client->company_name    = 'Negocio de ' . $nombre;
        $client->slug            = Str::slug($nombre) . '-' . Str::random(8);
        $client->api_url         = '';
        $client->api_key         = $api_key === null ? Str::random(40) : $api_key;
        $client->inbound_api_key = 'inbound-' . Str::random(20);
        $client->phone           = '+549341' . random_int(1000000, 9999999);
        $client->is_active       = true;
        $client->user_id         = random_int(500000, 900000) * 100;

        foreach ($atributos as $campo => $valor) {
            $client->{$campo} = $valor;
        }

        $client->save();

        return $client->refresh();
    }

    /**
     * Un frente (`ClientApi`) del cliente.
     *
     * @param Client      $client       Cliente dueño.
     * @param string      $sub          Subdominio (`doblep`): de él salen la URL, el path y el SPA.
     * @param string      $hosting_type `shared_hosting` o `vps`.
     * @param string|null $vps_path     Carpeta del VPS (solo para `vps`).
     * @param bool        $activa       true: queda como la API activa del cliente.
     *
     * @return ClientApi
     */
    protected function crear_frente(Client $client, string $sub, string $hosting_type = 'shared_hosting', ?string $vps_path = null, bool $activa = false): ClientApi
    {
        $api               = new ClientApi();
        $api->client_id    = $client->id;
        $api->url          = 'https://api-' . $sub . '.' . self::DOMINIO;
        $api->path         = $sub . '/api';
        $api->spa_url      = 'https://' . $sub . '.' . self::DOMINIO;
        $api->hosting_type = $hosting_type;
        $api->vps_path     = $vps_path;
        $api->save();

        if ($activa) {
            $client->active_client_api_id = $api->id;
            $client->save();
        }

        return $api;
    }

    /**
     * Reemplaza el log por un registro propio y lo devuelve, para afirmar qué se escribió.
     *
     * @return AbstractLogger Con `$lineas`: cada línea es "nivel mensaje {contexto en JSON}", y
     *                        `$registros`: lo mismo estructurado (nivel, mensaje, contexto).
     */
    protected function capturar_el_log(): AbstractLogger
    {
        $registro = new class extends AbstractLogger {
            /** @var array<int, string> */
            public $lineas = [];

            /** @var array<int, array<string, mixed>> */
            public $registros = [];

            public function log($level, $message, array $context = [])
            {
                $this->lineas[]    = $level . ' ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $this->registros[] = ['nivel' => $level, 'mensaje' => (string) $message, 'contexto' => $context];
            }
        };

        Log::swap($registro);

        return $registro;
    }

    /**
     * 🔴 Ninguna línea de log lleva la clave.
     *
     * @param AbstractLogger $registro El registro devuelto por `capturar_el_log()`.
     * @param string         $clave    El VALOR de la clave.
     *
     * @return void
     */
    protected function assertLogSinLaClave(AbstractLogger $registro, string $clave): void
    {
        foreach ($registro->lineas as $linea) {
            $this->assertStringNotContainsString($clave, $linea, 'Una línea de log lleva la clave del cliente.');
        }
    }
}

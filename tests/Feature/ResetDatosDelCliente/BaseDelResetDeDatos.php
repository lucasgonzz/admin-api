<?php

namespace Tests\Feature\ResetDatosDelCliente;

use App\Models\Admin;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\CatalogoDelClientePorClaude\BaseDelCatalogoPorClaude;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Andamiaje común de los tests del reset de datos de negocio de un cliente (misión cruzada `reset-datos-cliente`,
 * contratos C1, C2 y C3 del PLAN): `POST claude/clients/{id}/reset-datos` y las tres rutas del panel
 * `admin/client/{id}/reset-datos/{simular|ejecutar|registros}`.
 *
 * Reusa el de `catalogo/*` (clave de ingesta, headers, constructores de cliente y frentes, captura del log) y suma lo
 * propio del reset: un cliente con su frente de shared hosting activo, las dos respuestas buenas de empresa-api (C1), la
 * forma de falsear al `empresa-api` del cliente y la lectura de la tabla `client_data_resets`.
 *
 * 🔴 LO QUE ESTE BLOQUE NO NEGOCIA, y que todos los archivos del directorio vigilan:
 *
 *  - la `clients.api_key` del cliente NO sale por ningún lado (respuesta, error, log, registro);
 *  - el `token_de_simulacion` solo viaja en la respuesta de la SIMULACIÓN (es el que la persona necesita para ejecutar):
 *    nunca queda en el log ni en `client_data_resets`;
 *  - una ejecución con el nombre mal confirmado NO le pega al cliente.
 *
 * Nada sale a internet: el `TestCase` base cambia el cliente HTTP por uno que lanza ante todo pedido que ningún
 * `Http::fake()` atienda.
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDelResetDeDatos extends BaseDelCatalogoPorClaude
{
    /** Nombre del cliente de los tests (el que se confirma en `confirm_client_name`). */
    const NOMBRE = 'Doblep Distribuciones';

    /** URL del endpoint de reset del `empresa-api` del frente de shared hosting de los tests (la API vive bajo `/public`). */
    const URL_DEL_RESET = 'https://api-doblep.ejemplo.test/public/api/admin-sync/reset-datos-negocio';

    /** Nombre de la base de datos que informa el `empresa-api` falso (lo que hay que repetir en `confirmar_base_de_datos`). */
    const BASE = 'empresa_doblep';

    /** @var string La `clients.api_key` del cliente de los tests. */
    protected $clave;

    /** @var string El `token_de_simulacion` que "emite" el `empresa-api` falso: 40 alfanuméricos. */
    protected $token;

    /** @var Client El cliente de los tests, con su frente de shared hosting activo. */
    protected $cliente;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clave   = 'sk-reset-' . Str::random(30);
        $this->token   = Str::random(40);
        $this->cliente = $this->crear_cliente(self::NOMBRE, $this->clave);

        $this->crear_frente($this->cliente, 'doblep', 'shared_hosting', null, true);
    }

    /* ------------------------------------------------------------------------------------------
     | Puerta de Claude (`claude/`, contrato C2)
     |----------------------------------------------------------------------------------------- */

    /**
     * La URL del endpoint de Claude para un cliente.
     *
     * @param Client|int|string|null $cliente Cliente, id o uuid (el de los tests si es null).
     * @param string                 $sufijo  Lo que va después de `reset-datos` (`''` o `'/registros'`).
     *
     * @return string
     */
    protected function url_de_claude($cliente = null, string $sufijo = ''): string
    {
        $cliente = $cliente === null ? $this->cliente : $cliente;
        $id      = $cliente instanceof Client ? $cliente->id : $cliente;

        return '/api/claude/clients/' . $id . '/reset-datos' . $sufijo;
    }

    /**
     * Llama al endpoint de Claude con la clave de ingesta.
     *
     * @param array<string, mixed> $pedido  Cuerpo del pedido.
     * @param Client|null          $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir(array $pedido = [], Client $cliente = null)
    {
        return $this->postJson($this->url_de_claude($cliente), $pedido, $this->headers());
    }

    /**
     * Llama al endpoint de Claude para EJECUTAR de verdad (`dry_run = false`, nombre bien confirmado, base y token de la
     * simulación).
     *
     * @param array<string, mixed> $extra   Parámetros que se suman o pisan.
     * @param Client|null          $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_de_verdad(array $extra = [], Client $cliente = null)
    {
        $nombre = $cliente === null ? self::NOMBRE : $cliente->name;

        return $this->pedir(array_merge($this->pedido_de_ejecucion($nombre), $extra), $cliente);
    }

    /**
     * El cuerpo de un pedido de ejecución bien armado (sin los flags opcionales).
     *
     * @param string $nombre Lo que se manda en `confirm_client_name`.
     *
     * @return array<string, mixed>
     */
    protected function pedido_de_ejecucion(string $nombre = self::NOMBRE): array
    {
        return [
            'dry_run'                 => false,
            'confirm_client_name'     => $nombre,
            'confirmar_base_de_datos' => self::BASE,
            'token_de_simulacion'     => $this->token,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     | Puerta del panel (`admin/`, contrato C3)
     |----------------------------------------------------------------------------------------- */

    /**
     * La URL de una ruta del panel para un cliente.
     *
     * @param string                 $accion  `simular`, `ejecutar` o `registros`.
     * @param Client|int|string|null $cliente Cliente o id (el de los tests si es null).
     *
     * @return string
     */
    protected function url_del_panel(string $accion, $cliente = null): string
    {
        $cliente = $cliente === null ? $this->cliente : $cliente;
        $id      = $cliente instanceof Client ? $cliente->id : $cliente;

        return '/api/admin/client/' . $id . '/reset-datos/' . $accion;
    }

    /**
     * Crea un admin del panel (inaccesible por contraseña: se entra por Sanctum).
     *
     * @param string $nombre Nombre del admin (es el `solicitante` que viaja a empresa-api).
     *
     * @return Admin
     */
    protected function crear_admin(string $nombre = 'Operadora Panel'): Admin
    {
        return Admin::create(['name' => $nombre, 'email' => Str::random(12) . '@reset.test', 'password' => 'inaccesible']);
    }

    /* ------------------------------------------------------------------------------------------
     | El `empresa-api` falso (contrato C1)
     |----------------------------------------------------------------------------------------- */

    /**
     * Pone una fábrica de HTTP LIMPIA y la falsea con lo dado (los stubs de `Http::fake()` se acumulan: un segundo `fake()`
     * no pisa al primero). Parte de `HttpFactorySinSalida` para que lo que ningún stub atienda no pueda salir a internet.
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
     * Falsea al cliente con la simulación buena del contrato C1.
     *
     * @return void
     */
    protected function el_cliente_simula(): void
    {
        $this->el_cliente_contesta($this->simulacion_valida(), 200);
    }

    /**
     * Falsea al cliente con la ejecución buena del contrato C1.
     *
     * @return void
     */
    protected function el_cliente_ejecuta(): void
    {
        $this->el_cliente_contesta($this->ejecucion_valida(), 200);
    }

    /**
     * El cuerpo de un 200 de SIMULACIÓN válido del contrato C1.
     *
     * @return array<string, mixed>
     */
    protected function simulacion_valida(): array
    {
        return [
            'ok'                        => true,
            'dry_run'                   => true,
            'base'                      => self::BASE,
            'ejecutable'                => true,
            'bloqueos'                  => [],
            'a_vaciar'                  => ['articles' => 3, 'sales' => 2, 'clients' => 2, 'current_acounts' => 2],
            'total_filas'               => 9,
            'ajustes_previstos'         => ['cajas' => 'saldo en cero'],
            'archivos_previstos'        => ['imagenes' => 1, 'carpetas' => ['vender-items' => true]],
            'respaldo_estimado_bytes'   => 20480,
            'espacio_libre_bytes'       => 9999999999,
            'token_de_simulacion'       => $this->token,
            'token_vence_en'            => '2026-11-21T15:00:00Z',
            'registro_id'               => 41,
        ];
    }

    /**
     * El cuerpo de un 200 de EJECUCIÓN válido del contrato C1.
     *
     * @return array<string, mixed>
     */
    protected function ejecucion_valida(): array
    {
        return [
            'ok'           => true,
            'dry_run'      => false,
            'estado'       => 'ok',
            'base'         => self::BASE,
            'registro_id'  => 42,
            'vaciadas'     => ['articles' => 3, 'sales' => 2, 'clients' => 2, 'current_acounts' => 2],
            'total_filas'  => 9,
            'ajustes'      => ['cajas' => 1],
            'ajustes_salteados' => [],
            'respaldo'     => ['ruta_relativa' => 'respaldos-reset-datos/20261121-empresa_doblep.sql.gz', 'bytes' => 20480, 'sha256' => str_repeat('a', 64)],
            'archivos'     => ['movidos' => 2, 'carpetas' => ['vender-items'], 'errores' => []],
            'verificacion' => ['tablas_con_filas' => [], 'auto_increment_sin_reiniciar' => []],
            'duracion_ms'  => 1800,
        ];
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
     * El valor de un header de un pedido capturado (el primero), o null si no está.
     *
     * @param \Illuminate\Http\Client\Request $request Pedido capturado.
     * @param string                          $nombre  Nombre del header.
     *
     * @return string|null
     */
    protected function header_enviado($request, string $nombre)
    {
        $valores = $request->header($nombre);

        return is_array($valores) && count($valores) > 0 ? (string) $valores[0] : null;
    }

    /* ------------------------------------------------------------------------------------------
     | El registro del admin (`client_data_resets`)
     |----------------------------------------------------------------------------------------- */

    /**
     * Las filas de `client_data_resets` de un cliente, de la más vieja a la más nueva.
     *
     * @param Client|null $cliente Cliente (el de los tests si es null).
     *
     * @return \Illuminate\Support\Collection
     */
    protected function registros_del_admin(Client $cliente = null)
    {
        $cliente = $cliente === null ? $this->cliente : $cliente;

        return DB::table('client_data_resets')->where('client_id', $cliente->id)->orderBy('id')->get();
    }

    /**
     * Todo lo guardado en `client_data_resets` como un solo texto (todas las columnas de todas las filas), para afirmar
     * que un secreto no quedó en ninguna.
     *
     * @return string
     */
    protected function registro_completo_como_texto(): string
    {
        return (string) json_encode(DB::table('client_data_resets')->get()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /* ------------------------------------------------------------------------------------------
     | Las aserciones que gobiernan el bloque
     |----------------------------------------------------------------------------------------- */

    /**
     * 🔴 El token de simulación no está en la respuesta: ni tal cual ni escapado como JSON. Para las respuestas que NO son
     * la simulación (esa lo lleva a propósito).
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta a revisar.
     * @param string                           $donde     Para el mensaje de la falla.
     *
     * @return void
     */
    protected function assertSinElToken($respuesta, string $donde = ''): void
    {
        $this->assertStringNotContainsString($this->token, $this->cuerpo($respuesta), $donde . ' La respuesta lleva el token de simulación.');
        $this->assertStringNotContainsString($this->token, (string) $respuesta->getContent(), $donde . ' La respuesta (cruda) lleva el token de simulación.');
    }

    /**
     * 🔴 Ninguna línea de log lleva el token de simulación.
     *
     * @param \Psr\Log\AbstractLogger $registro El registro devuelto por `capturar_el_log()`.
     *
     * @return void
     */
    protected function assertLogSinElToken($registro): void
    {
        foreach ($registro->lineas as $linea) {
            $this->assertStringNotContainsString($this->token, $linea, 'Una línea de log lleva el token de simulación.');
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\ClientAiTokenUsage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\AsistenteWhatsapp\BaseDelCanal;

/**
 * Los dos endpoints de lectura del consumo de IA: la pestaña de un cliente y el resumen global.
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *  1. 🔴 **Que el costo se calcule bien y que un modelo sin precio salga `null`, no cero.** Es toda
 *     la razón de ser de `config/ia_precios.php`: un renglón sin plata se ve y se pregunta; un
 *     total que dice 0 porque nadie cargó el precio se cree. Los dos casos viven en el mismo test a
 *     propósito, porque lo que importa es que convivan en la misma respuesta sin contaminarse.
 *  2. **Que el resumen global no cruce rangos de fecha.** Un `whereBetween` mal escrito acá no
 *     rompe nada visible: devuelve un número más grande, y nadie tiene con qué compararlo.
 *
 * Hereda de `BaseDelCanal` por su `fakear_http()`: ninguno de estos caminos tiene por qué salir a
 * la red, y el comodín del `setUp()` garantiza que si alguno lo hiciera, no llegue a ningún lado.
 */
class ConsumoDeTokensDelClienteTest extends BaseDelCanal
{
    /**
     * Admin logueado por Sanctum: las dos rutas viven bajo auth:sanctum.
     *
     * @return Admin
     */
    private function admin_logueado(): Admin
    {
        $admin           = new Admin();
        $admin->name     = 'Admin de tokens';
        $admin->email    = 'tokens-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Deja una fila de consumo ya espejada en el admin.
     *
     * @param Client $client   Cliente dueño.
     * @param string $fecha    Día.
     * @param string $proceso  Acción.
     * @param string $modelo   Modelo.
     * @param array<string, int> $tokens Contadores a pisar sobre los ceros.
     * @param int    $llamadas Llamadas agregadas.
     *
     * @return ClientAiTokenUsage
     */
    private function sembrar_consumo(
        Client $client,
        string $fecha,
        string $proceso,
        string $modelo,
        array $tokens = [],
        int $llamadas = 1
    ): ClientAiTokenUsage {
        $fila            = new ClientAiTokenUsage();
        $fila->client_id = $client->id;
        $fila->fecha     = $fecha;
        $fila->proceso   = $proceso;
        $fila->proveedor = $modelo === 'text-embedding-3-small' ? 'openai' : 'anthropic';
        $fila->modelo    = $modelo;
        $fila->llamadas  = $llamadas;

        $fila->input_tokens                = (int) ($tokens['input_tokens'] ?? 0);
        $fila->output_tokens               = (int) ($tokens['output_tokens'] ?? 0);
        $fila->cache_creation_input_tokens = (int) ($tokens['cache_creation_input_tokens'] ?? 0);
        $fila->cache_read_input_tokens     = (int) ($tokens['cache_read_input_tokens'] ?? 0);

        $fila->save();

        return $fila;
    }

    /**
     * El endpoint de un cliente costea con la tabla de precios y marca el modelo desconocido.
     *
     * Las cuentas, con los precios del 17/9/2026 (dólares por millón de tokens):
     *
     *   claude-sonnet-5 → 1.000.000 input × 2,00 = 2,00
     *                   +   100.000 output × 10,00 = 1,00
     *                   +   500.000 cache_read × 0,20 = 0,10
     *                   = **3,10 USD**
     *
     *   modelo-inventado-9 → no está en la tabla → **null**, y el total lo nombra como faltante.
     *
     * @return void
     */
    public function test_el_endpoint_del_cliente_costea_bien_y_un_modelo_sin_precio_sale_en_null(): void
    {
        $this->admin_logueado();

        $client = $this->crear_cliente();

        $this->sembrar_consumo($client, '2026-09-16', 'chat_mensaje', 'claude-sonnet-5', [
            'input_tokens'            => 1000000,
            'output_tokens'           => 100000,
            'cache_read_input_tokens' => 500000,
        ], 10);

        $this->sembrar_consumo($client, '2026-09-17', 'whatsapp_sugerencia', 'modelo-inventado-9', [
            'input_tokens' => 200000,
        ], 4);

        $response = $this->getJson(
            '/api/admin/client/' . $client->id . '/tokens?desde=2026-09-15&hasta=2026-09-17'
        );

        $response->assertStatus(200);

        $payload = $response->json();

        // El total suma SOLO lo que tiene precio, y nombra lo que no pudo costear.
        $this->assertEqualsWithDelta(3.10, $payload['totales']['costo_usd'], 0.0001);
        $this->assertSame(1800000, $payload['totales']['tokens']);
        $this->assertSame(14, $payload['totales']['llamadas']);
        $this->assertSame(['modelo-inventado-9'], $payload['totales']['modelos_sin_precio']);

        // Por modelo: el conocido con su costo, el desconocido en null (que NO es cero).
        $por_modelo = [];
        foreach ($payload['por_modelo'] as $grupo) {
            $por_modelo[$grupo['modelo']] = $grupo;
        }

        $this->assertEqualsWithDelta(3.10, $por_modelo['claude-sonnet-5']['costo_usd'], 0.0001);
        $this->assertTrue($por_modelo['claude-sonnet-5']['tiene_precio']);

        $this->assertNull(
            $por_modelo['modelo-inventado-9']['costo_usd'],
            'Un modelo sin precio cargado tiene que salir en null: cero significaría que no costó nada.'
        );
        $this->assertFalse($por_modelo['modelo-inventado-9']['tiene_precio']);

        // La serie por día separa los dos días, y el día del modelo desconocido no inventa un costo.
        $por_dia = [];
        foreach ($payload['por_dia'] as $grupo) {
            $por_dia[substr((string) $grupo['fecha'], 0, 10)] = $grupo;
        }

        $this->assertEqualsWithDelta(3.10, $por_dia['2026-09-16']['costo_usd'], 0.0001);
        $this->assertNull($por_dia['2026-09-17']['costo_usd']);

        // Y el desglose por acción, que es la granularidad que pidió Lucas.
        $procesos = [];
        foreach ($payload['por_proceso'] as $grupo) {
            $procesos[] = $grupo['proceso'];
        }

        $this->assertContains('chat_mensaje', $procesos);
        $this->assertContains('whatsapp_sugerencia', $procesos);
    }

    /**
     * El resumen global suma a todos los clientes y no se trae nada de afuera del rango.
     *
     * @return void
     */
    public function test_el_resumen_global_suma_los_clientes_y_no_cruza_el_rango_de_fechas(): void
    {
        $this->admin_logueado();

        /* La tabla arranca vacía para que el total sea comprobable. El borrado corre adentro de la
         * transacción de la prueba, así que no toca nada de lo que haya en la base del slot. */
        ClientAiTokenUsage::query()->delete();

        $uno = $this->crear_cliente('+5493411111111');
        $dos = $this->crear_cliente('+5493412222222');

        // Adentro del rango: 1.000.000 input de Sonnet 5 = 2,00 USD.
        $this->sembrar_consumo($uno, '2026-09-10', 'chat_mensaje', 'claude-sonnet-5', [
            'input_tokens' => 1000000,
        ], 3);

        // Adentro del rango: 1.000.000 input de Haiku 4.5 = 1,00 USD.
        $this->sembrar_consumo($dos, '2026-09-11', 'chat_titulo', 'claude-haiku-4-5', [
            'input_tokens' => 1000000,
        ], 7);

        /* 🔴 AFUERA del rango, y diez veces más grande que todo lo demás junto: si el
         * `whereBetween` estuviera mal, el total saltaría de 3 a 23 dólares y el test lo grita. */
        $this->sembrar_consumo($uno, '2026-08-01', 'chat_mensaje', 'claude-sonnet-5', [
            'input_tokens' => 10000000,
        ], 40);

        $response = $this->getJson('/api/admin/tokens/resumen?desde=2026-09-10&hasta=2026-09-12');

        $response->assertStatus(200);

        $payload = $response->json();

        $this->assertEqualsWithDelta(3.00, $payload['totales']['costo_usd'], 0.0001);
        $this->assertSame(2000000, $payload['totales']['tokens']);
        $this->assertSame(10, $payload['totales']['llamadas']);

        // El ranking trae a los dos clientes, con el más caro arriba y con su nombre resuelto.
        $this->assertCount(2, $payload['por_cliente']);
        $this->assertSame((int) $uno->id, $payload['por_cliente'][0]['client_id']);
        $this->assertEqualsWithDelta(2.00, $payload['por_cliente'][0]['costo_usd'], 0.0001);
        $this->assertSame($uno->resolve_display_name(), $payload['por_cliente'][0]['cliente']);

        $this->assertSame((int) $dos->id, $payload['por_cliente'][1]['client_id']);
        $this->assertEqualsWithDelta(1.00, $payload['por_cliente'][1]['costo_usd'], 0.0001);

        // La serie por día tiene solo los dos días con consumo del rango, nunca el de agosto.
        $fechas = [];
        foreach ($payload['por_dia'] as $grupo) {
            $fechas[] = substr((string) $grupo['fecha'], 0, 10);
        }

        $this->assertSame(['2026-09-10', '2026-09-11'], $fechas);
    }
}

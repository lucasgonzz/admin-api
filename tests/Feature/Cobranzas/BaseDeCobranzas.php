<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Admin;
use App\Models\Client;
use App\Models\MensualidadInvoice;
use App\Models\MensualidadPago;
use App\Models\MensualidadPeriodo;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lo compartido por las pruebas del módulo de Cobranzas (misión modulo-cobranzas, 18/9/2026).
 *
 * Dos decisiones que valen para todas:
 *
 * 1. **El reloj está clavado.** Todo el módulo compara meses contra "el mes corriente", así que
 *    una prueba que dependa de la fecha real pasa hoy y falla el 1° del mes que viene. El
 *    `setUp()` fija el 15/9/2026 con `Carbon::setTestNow()`, que es lo que lee `AppTime::now()`
 *    (en testing no hay reloj virtual de base) y el `now()` de Laravel. El `tearDown()` lo suelta.
 * 2. **Nada sale a la red.** El botón "traer empleados" le pega al empresa-api del cliente; el
 *    comodín de `Http::fake()` del `setUp()` garantiza que una prueba que se olvide del fake no
 *    llegue a ningún lado.
 *
 * No hay `database/factories/` en este repo: los ayudantes arman los modelos a mano.
 */
abstract class BaseDeCobranzas extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" de todas las pruebas: 15 de septiembre de 2026. Mes corriente = 2026-09. */
    const HOY = '2026-09-15 10:00:00';

    /** El mes corriente que sale de HOY. */
    const MES_CORRIENTE = '2026-09';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HOY, 'America/Argentina/Buenos_Aires'));

        $this->fakear_http();
    }

    /**
     * Fakea las llamadas HTTP, descartando lo que hubiera de antes.
     *
     * Mismo patrón que `Tests\Feature\AsistenteWhatsapp\BaseDelCanal::fakear_http()`, y por el
     * mismo motivo: `Http::fake()` ACUMULA y gana el primero que matchea, así que el comodín del
     * `setUp()` le ganaría al stub específico de la prueba si no se cambia la fábrica entera.
     * El comodín va siempre y va último.
     *
     * @param array<string, mixed> $stubs Stubs por patrón de URL, en orden de prioridad.
     *
     * @return void
     */
    protected function fakear_http(array $stubs = []): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());

        if (! array_key_exists('*', $stubs)) {
            $stubs['*'] = Http::response([], 200);
        }

        Http::fake($stubs);
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
     * Admin logueado por Sanctum: todas las rutas del módulo viven bajo auth:sanctum.
     *
     * @param string $nombre
     *
     * @return Admin
     */
    protected function admin_logueado(string $nombre = 'Admin de cobranzas'): Admin
    {
        $admin = $this->crear_admin($nombre);

        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * Un admin sin loguear.
     *
     * @param string $nombre
     *
     * @return Admin
     */
    protected function crear_admin(string $nombre = 'Admin de cobranzas'): Admin
    {
        $admin           = new Admin();
        $admin->name     = $nombre;
        $admin->email    = 'cobranzas-' . Str::random(8) . '@test.local';
        $admin->password = bcrypt('secret');
        $admin->save();

        return $admin;
    }

    /**
     * Un cliente con mensualidad cargada. Por defecto: plan 10.000 + 2 empleados × 1.000 =
     * total 12.000, cobrando desde enero de 2026.
     *
     * @param array<string, mixed> $atributos Pisan los defaults.
     *
     * @return Client
     */
    protected function crear_cliente(array $atributos = []): Client
    {
        $client = new Client();
        $client->name               = 'Contacto de prueba';
        $client->company_name       = 'Comercio de prueba ' . Str::random(6);
        $client->is_active          = true;
        $client->precio_plan        = 10000;
        $client->precio_por_cuenta  = 1000;
        $client->cantidad_empleados = 2;
        $client->tiene_ecommerce    = false;
        $client->total_mensualidad  = 12000;
        $client->mensualidad_inicio = '2026-01-01';

        foreach ($atributos as $campo => $valor) {
            $client->{$campo} = $valor;
        }

        $client->save();

        return $client->fresh();
    }

    /**
     * Una fila afirmada de un mes.
     *
     * @param Client      $client
     * @param string      $periodo
     * @param string      $estado
     * @param float|null  $monto_esperado
     * @param bool        $importado
     *
     * @return MensualidadPeriodo
     */
    protected function sembrar_periodo(Client $client, string $periodo, string $estado, ?float $monto_esperado = null, bool $importado = false): MensualidadPeriodo
    {
        return MensualidadPeriodo::create([
            'client_id'      => $client->id,
            'periodo'        => $periodo,
            'estado'         => $estado,
            'monto_esperado' => $monto_esperado,
            'importado'      => $importado,
        ]);
    }

    /**
     * Un pago de un mes.
     *
     * @param Client     $client
     * @param string     $periodo
     * @param float|null $monto
     *
     * @return MensualidadPago
     */
    protected function sembrar_pago(Client $client, string $periodo, ?float $monto): MensualidadPago
    {
        return MensualidadPago::create([
            'client_id'  => $client->id,
            'periodo'    => $periodo,
            'monto'      => $monto,
            'fecha_pago' => '2026-09-10',
            'medio'      => 'Transferencia',
        ]);
    }

    /**
     * Una Factura C de un mes. Autorizada por defecto (resultado 'A' + CAE).
     *
     * @param Client $client
     * @param string $periodo
     * @param bool   $autorizada
     *
     * @return MensualidadInvoice
     */
    protected function sembrar_factura(Client $client, string $periodo, bool $autorizada = true): MensualidadInvoice
    {
        return MensualidadInvoice::create([
            'client_id'       => $client->id,
            'periodo'         => $periodo,
            'cbte_tipo'       => 11,
            'cbte_letra'      => 'C',
            'cbte_numero'     => $autorizada ? 123 : null,
            'punto_venta'     => 4,
            'importe_total'   => 12000,
            'imp_neto'        => 12000,
            'imp_iva'         => 0,
            'cae'             => $autorizada ? '71234567890123' : null,
            'resultado'       => $autorizada ? 'A' : 'R',
            'error_message'   => $autorizada ? null : 'Rechazada en la prueba',
            'afip_produccion' => false,
        ]);
    }
}

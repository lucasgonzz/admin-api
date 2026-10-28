<?php

namespace Tests\Feature\Cobranzas;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LicenciaCuota;

/**
 * Lo compartido por las pruebas de ventas y comisiones (misión ventas-y-comisiones, 28/10/2026).
 *
 * Hereda de `BaseDeCobranzas` el reloj clavado (15/9/2026: una venta sin fecha es de ese día) y el
 * `Http::fake()`. Suma:
 *
 *  - 🔴 **Ningún vendedor de entrada.** El vendedor por defecto de una venta es "el único admin
 *    marcado `es_vendedor`", y la base de testing del slot puede traer admins de antes (el closer de
 *    una migración vieja, por ejemplo). El `setUp()` apaga `es_vendedor` e `is_closer` en todos
 *    —adentro de la transacción de la prueba, así que se revierte—, y cada prueba crea los que
 *    necesita. Sin esto el "vendedor único" dependería de lo que haya en la base.
 *  - Ayudantes para crear un vendedor, un lead promovido a un cliente y para dar de alta una venta
 *    por el endpoint real.
 */
abstract class BaseDeVentas extends BaseDeCobranzas
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Admin::query()->update(['es_vendedor' => false, 'is_closer' => false]);
    }

    /**
     * Un admin marcado como vendedor.
     *
     * @param string $nombre
     *
     * @return Admin
     */
    protected function crear_vendedor(string $nombre = 'Tomás vendedor'): Admin
    {
        $admin = $this->crear_admin($nombre);
        $admin->es_vendedor = true;
        $admin->save();

        return $admin->fresh();
    }

    /**
     * Un lead promovido al cliente (solo el enganche `promoted_client_id`), con la modalidad del
     * contrato que se pida.
     *
     * @param Client      $client
     * @param string|null $modalidad 'licencia' | 'suscripcion' | null.
     *
     * @return Lead
     */
    protected function crear_lead_promovido(Client $client, ?string $modalidad = null): Lead
    {
        $lead = new Lead();
        $lead->contact_name       = 'Lead de ventas';
        $lead->company_name       = 'Lead promovido ' . uniqid();
        $lead->email              = 'lead-ventas@test.local';
        $lead->status             = 'cerrado_ganado';
        $lead->contract_modalidad = $modalidad;
        $lead->promoted_client_id = $client->id;
        $lead->save();

        return $lead->refresh();
    }

    /**
     * Da de alta una venta por el endpoint del admin y devuelve la respuesta.
     *
     * @param Client               $client
     * @param array<string, mixed> $datos
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function alta_de_venta(Client $client, array $datos)
    {
        return $this->postJson('/api/admin/client/' . $client->id . '/ventas', $datos);
    }

    /**
     * Las cuotas de una venta, por número.
     *
     * @param int $venta_id
     *
     * @return \Illuminate\Support\Collection
     */
    protected function cuotas_de(int $venta_id)
    {
        return LicenciaCuota::where('venta_id', $venta_id)->orderBy('numero')->get();
    }

    /**
     * La venta del JSON de la lista del cliente (`GET client/{id}/ventas`) con ese id.
     *
     * @param Client $client
     * @param int    $venta_id
     *
     * @return array<string, mixed>
     */
    protected function venta_de_la_lista(Client $client, int $venta_id): array
    {
        $lista = $this->getJson('/api/admin/client/' . $client->id . '/ventas');
        $lista->assertStatus(200);

        foreach ((array) $lista->json('ventas') as $venta) {
            if ((int) $venta['id'] === $venta_id) {
                return $venta;
            }
        }

        $this->fail('La venta ' . $venta_id . ' no está en la lista del cliente ' . $client->id . '.');

        return [];
    }
}

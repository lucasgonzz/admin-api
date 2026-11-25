<?php

namespace Tests\Feature\MarcasDeActualizacion;

use App\Models\Admin;
use App\Models\Client;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA9 — El listado de clientes del panel muestra y filtra «Requiere actualización» (contrato C6): la columna
 * viaja en cada fila y en el meta, el filtro genérico de checkbox trae sólo los marcados y la edición del
 * cliente no la puede prender a mano (la mantiene sólo el servicio de marcas).
 */
class ListadoDeClientesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Admin humano del panel.
     *
     * @return Admin
     */
    private function lucas(): Admin
    {
        return Admin::firstOrCreate(['email' => 'lucas-listado-marcas@test.local'], ['name' => 'Lucas', 'password' => bcrypt('prueba')]);
    }

    public function test_columna_filtro_y_meta_de_requiere_actualizacion(): void
    {
        $marcado = Client::create(['name' => 'Marcado', 'company_name' => 'Marcado SA', 'is_active' => true, 'api_url' => 'https://marcado.test', 'api_key' => 'k1']);
        $libre = Client::create(['name' => 'Libre', 'company_name' => 'Libre SA', 'is_active' => true, 'api_url' => 'https://libre.test', 'api_key' => 'k2']);
        DB::table('clients')->where('id', $marcado->id)->update(['requires_upgrade' => true]);
        $this->actingAs($this->lucas(), 'sanctum');

        $filas = collect($this->getJson('/api/admin/client')->assertOk()->json('models'))->keyBy('id');
        $this->assertTrue($filas[$marcado->id]['requires_upgrade']);
        $this->assertFalse($filas[$libre->id]['requires_upgrade']);

        $filtradas = $this->postJson('/api/admin/search/client/null/1?page=1', [
            'filters' => [['key' => 'requires_upgrade', 'type' => 'checkbox', 'checkbox' => 1]],
            'per_page' => 200,
        ])->assertOk()->json('data');
        $ids = array_map('intval', array_column($filtradas, 'id'));
        $this->assertContains((int) $marcado->id, $ids);
        $this->assertNotContains((int) $libre->id, $ids);
        foreach ($filtradas as $fila) {
            $this->assertTrue((bool) $fila['requires_upgrade']);
        }

        $this->putJson('/api/admin/client/' . $libre->id, ['requires_upgrade' => true, 'name' => 'Libre'])->assertOk();
        $this->assertFalse((bool) Client::findOrFail($libre->id)->requires_upgrade);

        $propiedades = collect($this->getJson('/api/admin/meta/client')->assertOk()->json('properties'));
        $propiedad = $propiedades->firstWhere('key', 'requires_upgrade');
        $this->assertNotNull($propiedad);
        $this->assertSame('Requiere actualización', $propiedad['text']);
        $this->assertSame('checkbox', $propiedad['type']);
        $this->assertTrue($propiedad['only_show']);
        $this->assertTrue($propiedad['use_to_filter_in_search']);
    }
}

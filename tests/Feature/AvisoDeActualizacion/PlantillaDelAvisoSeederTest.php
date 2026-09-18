<?php

namespace Tests\Feature\AvisoDeActualizacion;

use App\Models\AdminSetting;
use App\Services\AsistenteWhatsappSettings;
use Database\Seeders\PlantillaDelAvisoDeActualizacionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * El seeder que deja escrita la plantilla del aviso: la escribe si falta y no pisa la que alguien
 * eligió a mano. Lo corre el deploy del admin (`pendientes.json`).
 */
class PlantillaDelAvisoSeederTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Sin la fila, el seeder la escribe con el nombre y el idioma con que la plantilla se creó en
     * Meta, y el servicio pasa a verla.
     *
     * @return void
     */
    public function test_sin_la_fila_la_escribe_y_el_servicio_la_ve()
    {
        $this->assertSame('', AsistenteWhatsappSettings::plantilla_de_actualizaciones(), 'precondición: no hay fila');

        $this->seed(PlantillaDelAvisoDeActualizacionSeeder::class);
        AdminSetting::flush_memo();

        $this->assertSame('cc_sistema_actualizado', AsistenteWhatsappSettings::plantilla_de_actualizaciones());
        $this->assertSame('es_AR', AsistenteWhatsappSettings::idioma_de_la_plantilla_de_actualizaciones());
    }

    /**
     * Una plantilla elegida a mano no se toca, aunque el seeder corra de nuevo.
     *
     * @return void
     */
    public function test_no_pisa_una_plantilla_elegida_a_mano()
    {
        AdminSetting::set(AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME, 'cc_otra_plantilla');
        AdminSetting::flush_memo();

        $this->seed(PlantillaDelAvisoDeActualizacionSeeder::class);
        AdminSetting::flush_memo();

        $this->assertSame('cc_otra_plantilla', AsistenteWhatsappSettings::plantilla_de_actualizaciones());
    }

    /**
     * Correrlo dos veces deja lo mismo que una: es lo que le permite al deploy reintentarlo.
     *
     * @return void
     */
    public function test_es_idempotente()
    {
        $this->seed(PlantillaDelAvisoDeActualizacionSeeder::class);
        $this->seed(PlantillaDelAvisoDeActualizacionSeeder::class);
        AdminSetting::flush_memo();

        $this->assertSame('cc_sistema_actualizado', AsistenteWhatsappSettings::plantilla_de_actualizaciones());
        $this->assertSame(
            1,
            AdminSetting::where('key', AsistenteWhatsappSettings::KEY_ACTUALIZACION_TEMPLATE_NAME)->count(),
            'una sola fila para la clave'
        );
    }
}

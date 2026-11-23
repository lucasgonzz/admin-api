<?php

namespace Tests\Feature\TableroDeJuan;

use App\Models\PipelineActivity;
use App\Models\PipelineStage;

/**
 * CA1 de la etapa E1 (misión embudo-juan-pipeline): el seeder standalone `PipelineLeadTableroJuanSeeder` arma las
 * 8 columnas del tablero de Juan sobre el pipeline «Leads» que ya existe.
 *
 * Qué se protege, y por qué cada cosa:
 *  1. Las 8 columnas, en orden, con las claves que el resto del código ya usa (`PipelineLeadFunnelService` mueve
 *     por clave: si se cambia una clave, el flujo de Meet del closer deja de mover tarjetas).
 *  2. Que las tarjetas que hoy están en «Esperando la llamada» y «En seguimiento» queden en la MISMA etapa: el
 *     seeder renombra y reordena, no migra datos ni mueve nada.
 *  3. Que sea idempotente y que no pise un nombre que alguien editó a mano desde Configuración.
 *  4. Que las etapas propias que alguien haya agregado queden al final, en su orden.
 *  5. Que sin el pipeline sembrado no haga nada (ni tire).
 *  6. Que `DatabaseSeeder` lo llame DESPUÉS de `PipelineLeadSeeder` (si no, el pipeline no existiría todavía).
 *
 * Hoy están EN ROJO: la clase `PipelineLeadTableroJuanSeeder` no existe.
 */
class SeederDelTableroTest extends BaseDelTableroDeJuan
{
    /**
     * Las etapas del pipeline de leads en el orden del tablero: `[stage_key, nombre visible, type]`.
     *
     * @return array<int, array<int, string>>
     */
    private function columnas_esperadas(): array
    {
        return [
            ['interesados', 'Interesados', 'open'],
            ['esperando_llamada', 'Volver a llamar', 'open'],
            ['en_prueba_sola', 'En prueba por su cuenta', 'open'],
            ['implementacion_acordada', 'Implementación acordada', 'open'],
            ['en_implementacion', 'En implementación', 'open'],
            ['en_seguimiento', 'Para cerrar', 'open'],
            ['cerrado', 'Cliente', 'won'],
            ['no_avanza', 'No avanza', 'lost'],
        ];
    }

    /**
     * CA1. Con el pipeline viejo y después el seeder del tablero, quedan 8 etapas en orden, con sus claves, nombres y
     * tipos; las dos que se renombran pierden el campo que traían y «Cerrado» conserva sus campos.
     *
     * @return void
     */
    public function test_ocho_columnas_en_orden_con_sus_claves(): void
    {
        $pipeline = $this->sembrar_el_tablero();

        $etapas = PipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('sort_order')->orderBy('id')->get();

        $this->assertCount(8, $etapas, 'El tablero de Juan tiene exactamente 8 columnas.');

        foreach ($this->columnas_esperadas() as $posicion => $esperada) {
            $etapa = $etapas[$posicion];
            $this->assertSame($esperada[0], $etapa->stage_key, 'La columna ' . $posicion . ' tiene que ser «' . $esperada[0] . '».');
            $this->assertSame($esperada[1], $etapa->name, 'El nombre visible de la columna ' . $posicion . '.');
            $this->assertSame($esperada[2], $etapa->type, 'El tipo de la columna ' . $posicion . '.');
            $this->assertSame($posicion, (int) $etapa->sort_order, 'El `sort_order` de «' . $esperada[0] . '» va de 0 a 7 sin saltos.');
        }

        /* La fecha de la llamada pasa a ser la próxima acción (`next_action_at`): ya no hay campo propio en estas dos. */
        $this->assertEmpty($this->etapa_por_clave('esperando_llamada')->fields, '«Volver a llamar» no lleva campos propios.');
        $this->assertEmpty($this->etapa_por_clave('en_seguimiento')->fields, '«Para cerrar» no lleva campos propios.');

        /* «Cliente» conserva el paquete y el precio acordado. */
        $claves_de_cliente = array_column($this->etapa_por_clave('cerrado')->fields, 'key');
        $this->assertContains('paquete', $claves_de_cliente);
        $this->assertContains('precio_acordado', $claves_de_cliente);
    }

    /**
     * CA1. Las tarjetas que estaban en «Esperando la llamada», «En seguimiento» e «Interesados» siguen en
     * la misma `stage_id` y el seeder no escribe actividad ni mueve nada.
     *
     * @return void
     */
    public function test_las_tarjetas_quedan_en_su_fila(): void
    {
        $this->sembrar_pipeline_de_leads_viejo();

        $tarjetas = [];
        foreach (['interesados', 'esperando_llamada', 'en_seguimiento'] as $clave) {
            $lead              = $this->crear_lead_del_tablero('contactado');
            $tarjeta           = $this->dar_de_alta_en($lead, $clave);
            $tarjetas[$clave]  = ['id' => (int) $tarjeta->id, 'stage_id' => (int) $tarjeta->stage_id];
        }

        $actividades_antes = PipelineActivity::query()->count();

        $this->correr_el_seeder_del_tablero();

        foreach ($tarjetas as $clave => $datos) {
            $tarjeta = \App\Models\PipelineOpportunity::query()->findOrFail($datos['id']);
            $this->assertSame($datos['stage_id'], (int) $tarjeta->stage_id, 'La tarjeta de «' . $clave . '» no se mueve de etapa.');
            $this->assertSame($clave, $this->etapa_por_clave($clave)->stage_key);
            $this->assertSame($datos['stage_id'], (int) $this->etapa_por_clave($clave)->id, 'La etapa «' . $clave . '» conserva su id (se renombra, no se recrea).');
        }

        $this->assertSame($actividades_antes, PipelineActivity::query()->count(), 'El seeder no escribe actividades.');
    }

    /**
     * CA1. Correrlo dos veces no duplica etapas y no cambia nada la segunda vez; una etapa que alguien renombró a mano
     * («Llamar») no se pisa, y una que sigue con su nombre sembrado sí se renombra.
     *
     * @return void
     */
    public function test_idempotente_y_no_pisa_lo_editado(): void
    {
        $pipeline = $this->sembrar_pipeline_de_leads_viejo();

        /* Alguien editó a mano el nombre de «Esperando la llamada» y de «En seguimiento»; «Cerrado» quedó como vino. */
        PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('stage_key', 'esperando_llamada')->update(['name' => 'Llamar']);
        PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('stage_key', 'en_seguimiento')->update(['name' => 'Seguimiento a mano']);

        $this->correr_el_seeder_del_tablero();

        $this->assertSame('Llamar', $this->etapa_por_clave('esperando_llamada')->name, 'Un nombre editado a mano no se pisa.');
        $this->assertSame('Seguimiento a mano', $this->etapa_por_clave('en_seguimiento')->name, 'Un nombre editado a mano no se pisa.');
        $this->assertSame('Cliente', $this->etapa_por_clave('cerrado')->name, 'Un nombre que sigue siendo el sembrado viejo sí se renombra.');

        $foto = PipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('id')->get(['id', 'name', 'stage_key', 'sort_order', 'type'])->toArray();

        /* Segunda corrida: nada cambia. */
        $this->correr_el_seeder_del_tablero();

        $despues = PipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('id')->get(['id', 'name', 'stage_key', 'sort_order', 'type'])->toArray();
        $this->assertSame($foto, $despues, 'Correr el seeder dos veces no cambia ni duplica nada.');
        $this->assertCount(8, $despues);

        /* Una sola etapa por clave. */
        $claves = array_column($despues, 'stage_key');
        $this->assertSame(count($claves), count(array_unique($claves)), 'No puede haber dos etapas con la misma clave.');
    }

    /**
     * CA1. Las etapas que alguien agregó por su cuenta (con otra clave) quedan después de las 8, en su orden relativo.
     *
     * @return void
     */
    public function test_las_etapas_propias_quedan_al_final(): void
    {
        $pipeline = $this->sembrar_pipeline_de_leads_viejo();

        /* Una etapa propia metida en el medio del orden viejo (sort_order 2, entre «Esperando la llamada» y «En seguimiento»). */
        PipelineStage::create([
            'pipeline_id' => $pipeline->id,
            'name'        => 'Etapa propia',
            'stage_key'   => 'etapa_propia',
            'color'       => '#123456',
            'type'        => 'open',
            'sort_order'  => 2,
            'fields'      => [],
        ]);

        $this->correr_el_seeder_del_tablero();

        $etapas = PipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('sort_order')->orderBy('id')->get();

        $this->assertCount(9, $etapas, 'Las 8 del tablero más la propia.');
        $this->assertSame('etapa_propia', $etapas[8]->stage_key, 'La etapa propia queda al final.');
        $this->assertSame(array_column($this->columnas_esperadas(), 0), $etapas->take(8)->pluck('stage_key')->all(), 'Las 8 del tablero ocupan los primeros ocho lugares.');
    }

    /**
     * CA1. Sin el pipeline `lead` sembrado, el seeder no hace nada y no tira excepción.
     *
     * @return void
     */
    public function test_sin_pipeline_no_hace_nada(): void
    {
        $this->borrar_pipeline_por_slug('lead');

        $clase = 'Database\\Seeders\\PipelineLeadTableroJuanSeeder';
        $this->assertTrue(class_exists($clase), 'Falta el seeder ' . $clase . ' (CA1).');

        $etapas_antes = PipelineStage::query()->count();

        $this->seed($clase);

        $this->assertSame(0, \App\Models\Pipeline::query()->where('slug', 'lead')->count(), 'No crea el pipeline que no existía.');
        $this->assertSame($etapas_antes, PipelineStage::query()->count(), 'No toca ninguna etapa de otros pipelines.');
    }

    /**
     * CA1. `DatabaseSeeder` llama al seeder del tablero y lo hace DESPUÉS de `PipelineLeadSeeder`.
     *
     * @return void
     */
    public function test_database_seeder_lo_llama_despues_del_pipeline_de_leads(): void
    {
        $fuente = (string) file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $posicion_viejo = strpos($fuente, 'PipelineLeadSeeder::class');
        $posicion_nuevo = strpos($fuente, 'PipelineLeadTableroJuanSeeder::class');

        $this->assertNotFalse($posicion_viejo, 'DatabaseSeeder tiene que seguir llamando a PipelineLeadSeeder.');
        $this->assertNotFalse($posicion_nuevo, 'DatabaseSeeder tiene que llamar a PipelineLeadTableroJuanSeeder.');
        $this->assertGreaterThan($posicion_viejo, $posicion_nuevo, 'El tablero se siembra DESPUÉS del pipeline de leads.');
    }
}

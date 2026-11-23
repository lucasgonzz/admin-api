<?php

namespace Tests\Feature\TextosDelEmbudo;

use App\Models\Pipeline;
use Tests\Feature\TableroDeJuan\BaseDelTableroDeJuan;

/**
 * El subtítulo del pipeline «Leads» (misión embudo-juan-pipeline, E3): hablaba del cierre «después de la demo», que el tablero de Juan ya
 * no es. `PipelineLeadTableroJuanSeeder` lo cambia sólo si sigue con el texto sembrado viejo (lo editado a mano no se pisa).
 *
 * Se escribió junto con el cambio (no antes): lo pidió Lucas al ver la pantalla.
 */
class SubtituloDelTableroTest extends BaseDelTableroDeJuan
{
    /**
     * @return void
     */
    public function test_el_subtitulo_ya_no_habla_de_la_demo_y_lo_editado_no_se_pisa(): void
    {
        $pipeline = $this->sembrar_el_tablero();
        $this->assertStringNotContainsString('demo', (string) $pipeline->fresh()->description);
        $this->assertStringContainsString('Juan', (string) $pipeline->fresh()->description);

        Pipeline::query()->whereKey($pipeline->id)->update(['description' => 'Texto que puso Lucas.']);
        $this->correr_el_seeder_del_tablero();

        $this->assertSame('Texto que puso Lucas.', (string) $pipeline->fresh()->description);
    }
}

<?php

namespace Database\Seeders;

use App\Models\LeadPipelineStatus;
use Illuminate\Database\Seeder;

/**
 * Seeder STANDALONE del estado `acepto_probar` («Aceptó probar») de la charla antes de la demo
 * (misión leads-charla-antes-de-la-demo, 27/11/2026).
 *
 * Es el que se corre en las bases de producción que ya tienen el catálogo `lead_pipeline_statuses`
 * (`php artisan db:seed --class=LeadPipelineStatusCharlaSeeder --force`). Las bases nuevas toman el estado de
 * `LeadPipelineStatus::DEFAULT_STATUSES`, que ya lo trae.
 *
 * Qué hace, y nada más:
 *  - da de alta `acepto_probar` (grupo «Calificación», `#ff922b`) justo detrás de `calificado`, si no existe,
 *    corriendo un lugar los estados que venían detrás (así `link_enviado` sigue después y el guardia de «no retroceder
 *    de tramo», que compara `sort_order`, no se rompe);
 *  - es idempotente: corrido dos veces, el resultado es el mismo.
 *
 * Sin el seeder el estado igual se crea (`LeadPipelineStatus::ensure_exists()`), pero al FINAL del orden.
 * Ningún lead cambia de estado: este seeder no toca `leads`.
 */
class LeadPipelineStatusCharlaSeeder extends Seeder
{
    /**
     * Ejecuta el seed.
     *
     * @return void
     */
    public function run()
    {
        $this->dar_de_alta('acepto_probar', 'calificado');
    }

    /**
     * Crea el estado si no existe, con su color y justo detrás de `$detras_de` en el orden del catálogo.
     *
     * @param string $slug      Estado nuevo.
     * @param string $detras_de Slug detrás del cual va; si no existe, va al final.
     *
     * @return void
     */
    private function dar_de_alta(string $slug, string $detras_de): void
    {
        /* Idempotente: si ya existe no se toca nada. */
        if (LeadPipelineStatus::query()->where('slug', $slug)->exists()) {
            return;
        }

        $ancla = LeadPipelineStatus::query()->where('slug', $detras_de)->first();

        if ($ancla !== null) {
            /* Se abre el hueco: los que venían detrás del ancla se corren uno, y el nuevo ocupa el lugar siguiente. */
            $orden = (int) $ancla->sort_order + 1;
            LeadPipelineStatus::query()->where('sort_order', '>=', $orden)->increment('sort_order');
        } else {
            $orden = (int) LeadPipelineStatus::query()->max('sort_order') + 1;
        }

        LeadPipelineStatus::create([
            'slug'       => $slug,
            'label'      => LeadPipelineStatus::DEFAULT_STATUSES[$slug],
            'color'      => LeadPipelineStatus::DEFAULT_COLORS[$slug],
            'sort_order' => $orden,
        ]);
    }
}

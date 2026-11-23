<?php

namespace Database\Seeders;

use App\Models\LeadPipelineStatus;
use Illuminate\Database\Seeder;

/**
 * Seeder STANDALONE de los estados simplificados del lead (misión embudo-juan-pipeline, E1, 23/11/2026).
 *
 * Es el que se corre en las bases de producción que ya tienen el catálogo `lead_pipeline_statuses`
 * (`php artisan db:seed --class=LeadPipelineStatusJuanSeeder`). Las bases nuevas toman los estados de
 * `LeadPipelineStatus::DEFAULT_STATUSES`, que ya los trae.
 *
 * Qué hace, y nada más:
 *  - da de alta `en_implementacion` (grupo «Cierre», `#6610f2`) detrás de `implementacion_pedida`, si no existe;
 *  - cambia tres etiquetas («Link enviado» a «Quiere probar», «Cerrado ganado» a «Cliente», «Cerrado perdido» a
 *    «Perdido») SÓLO si todavía tienen la etiqueta vieja por defecto, para no pisar una edición a mano;
 *  - es idempotente: corrido dos veces, el resultado es el mismo.
 *
 * Ningún slug cambia y ningún lead cambia de estado: este seeder no toca `leads`.
 */
class LeadPipelineStatusJuanSeeder extends Seeder
{
    /**
     * Etiquetas que cambian: slug => [etiqueta vieja por defecto, etiqueta nueva].
     *
     * @var array<string, array<int, string>>
     */
    private const ETIQUETAS = [
        'link_enviado'    => ['Link enviado', 'Quiere probar'],
        'cerrado_ganado'  => ['Cerrado ganado', 'Cliente'],
        'cerrado_perdido' => ['Cerrado perdido', 'Perdido'],
    ];

    /**
     * Ejecuta el seed.
     *
     * @return void
     */
    public function run()
    {
        $this->dar_de_alta('en_implementacion', 'implementacion_pedida');

        foreach (self::ETIQUETAS as $slug => $etiquetas) {
            $fila = LeadPipelineStatus::query()->where('slug', $slug)->first();

            /* Comparación exacta (distingue mayúsculas): sólo se cambia la etiqueta por defecto de antes. */
            if ($fila !== null && (string) $fila->label === $etiquetas[0]) {
                $fila->label = $etiquetas[1];
                $fila->save();
            }
        }
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

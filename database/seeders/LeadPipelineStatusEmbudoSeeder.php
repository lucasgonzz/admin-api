<?php

namespace Database\Seeders;

use App\Models\LeadPipelineStatus;
use Illuminate\Database\Seeder;

/**
 * Seeder STANDALONE de los estados del embudo nuevo (misión embudo-whatsapp-precios, E2, 21/11/2026).
 *
 * Es el que se corre en las bases de producción que ya tienen el catálogo `lead_pipeline_statuses`
 * (`php artisan db:seed --class=LeadPipelineStatusEmbudoSeeder`). Las bases nuevas no lo necesitan: el seeder general
 * ({@see LeadPipelineStatusSeeder}) toma los estados de `LeadPipelineStatus::DEFAULT_STATUSES`, que ya los trae.
 *
 * Qué hace, y nada más:
 *  - da de alta `link_enviado` (grupo «Prueba», `#0dcaf0`) e `implementacion_pedida` (grupo «Cierre», `#fd7e14`) si no
 *    existen;
 *  - cambia la etiqueta de `contactado` a «Conversando» y la de `prueba_activa` a «En prueba» SÓLO si todavía tienen
 *    la etiqueta vieja por defecto, para no pisar una edición a mano;
 *  - es idempotente: corrido dos veces, el resultado es el mismo.
 *
 * Los estados se MAPEAN, no se renombran (PLAN §2.2.1): ningún slug cambia y ningún lead cambia de estado. Este
 * seeder no toca `leads`.
 */
class LeadPipelineStatusEmbudoSeeder extends Seeder
{
    /**
     * Estados nuevos y el estado detrás del cual se insertan en el orden del catálogo.
     *
     * 🔴 El orden NO es cosmético: `LeadAiService` compara `sort_order` para el guardia de «no retroceder de tramo»
     * (un lead entre `solicita_disponibilidad` y `closer_activo` no vuelve atrás). `link_enviado` va detrás de
     * `calificado`, ANTES del tramo de la demo, para que el lead que recibió el link y después pide la demo pueda
     * pasar a `solicita_disponibilidad`. `implementacion_pedida` va detrás de `prueba_activa`, antes de `closer_activo`.
     *
     * @var array<string, string> slug nuevo => slug detrás del cual va
     */
    private const NUEVOS = [
        'link_enviado'          => 'calificado',
        'implementacion_pedida' => 'prueba_activa',
    ];

    /**
     * Etiquetas que cambian: slug => [etiqueta vieja por defecto, etiqueta nueva].
     *
     * @var array<string, array<int, string>>
     */
    private const ETIQUETAS = [
        'contactado'    => ['Contactado', 'Conversando'],
        'prueba_activa' => ['Prueba activa', 'En prueba'],
    ];

    /**
     * Ejecuta el seed de los estados del embudo.
     *
     * @return void
     */
    public function run()
    {
        foreach (self::NUEVOS as $slug => $detras_de) {
            $this->dar_de_alta($slug, $detras_de);
        }

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
     * @param string $slug Estado nuevo.
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

<?php

namespace App\Services;

use App\Models\Pipeline;
use App\Models\PipelineOpportunity;
use Database\Seeders\PipelineLeadSeeder;

/**
 * Las reglas de recurso del perfil «Sólo Leads» en el tablero de Juan (misión embudo-juan-pipeline, E1).
 *
 * La política (`AdminSoloLeadsPolicy`) sigue siendo pura: sólo mira método y uri. Lo que depende de la base (a qué pipeline
 * pertenece el `{id}` de la URL) lo resuelve esta clase, que la llama el middleware `SoloLeadsAllowlist`.
 */
class SoloLeadsRecursos
{
    /** El `{id}` de la ruta es el pipeline «Leads» (slug `lead`). */
    const PIPELINE_DE_LEADS = 'pipeline_de_leads';

    /** El `{id}` de la ruta es una oportunidad del pipeline «Leads» que tiene un lead como sujeto. */
    const OPORTUNIDAD_DE_LEADS = 'oportunidad_de_leads';

    /**
     * Dice si el recurso de la ruta cumple la regla.
     *
     * @param string               $regla      Una de las constantes de esta clase.
     * @param array<string, mixed> $parametros Parámetros resueltos de la ruta (`Route::parameters()`).
     *
     * @return bool false si la regla no existe, el `{id}` no llega o el recurso no es del pipeline de leads.
     */
    public function cumple(string $regla, array $parametros): bool
    {
        if (! isset($parametros['id']) || ! is_scalar($parametros['id']) || ! ctype_digit((string) $parametros['id'])) {
            return false;
        }

        $id = (int) $parametros['id'];

        $pipeline_de_leads = Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->value('id');
        if ($pipeline_de_leads === null) {
            return false;
        }

        switch ($regla) {
            case self::PIPELINE_DE_LEADS:
                return $id === (int) $pipeline_de_leads;

            case self::OPORTUNIDAD_DE_LEADS:
                return PipelineOpportunity::query()
                    ->whereKey($id)
                    ->where('pipeline_id', (int) $pipeline_de_leads)
                    ->whereNotNull('lead_id')
                    ->exists();
        }

        return false;
    }
}

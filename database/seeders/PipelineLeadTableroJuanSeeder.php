<?php

namespace Database\Seeders;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Pipelines\PipelineFieldsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Arma las 8 columnas del tablero de Juan sobre el pipeline «Leads» que ya existe (misión embudo-juan-pipeline, E1).
 *
 * Seeder standalone e idempotente (para producción: el pipeline ya está sembrado por `PipelineLeadSeeder`, que NO se toca
 * porque sus tests dependen de él). Reglas:
 *  - Reusa las filas y conserva las claves (`stage_key`): `PipelineLeadFunnelService` mueve por clave.
 *  - Renombra una etapa sólo si todavía tiene el nombre sembrado viejo: lo que alguien editó a mano no se pisa.
 *  - Busca cada fila por `stage_key`; si falta, la crea.
 *  - Fija `sort_order` de 0 a 7 para las 8 claves; las etapas propias que haya agregado alguien quedan después, en su orden.
 *  - No mueve ninguna tarjeta ni escribe actividad.
 *  - Sin el pipeline sembrado, no hace nada.
 */
class PipelineLeadTableroJuanSeeder extends Seeder
{
    /**
     * Las 8 columnas en orden: clave => [nombre nuevo, nombre sembrado viejo (null si es una etapa nueva), tipo, color,
     * ¿se le sacan los campos al renombrar?].
     *
     * @var array<string, array<int, mixed>>
     */
    const COLUMNAS = [
        'interesados'             => ['Interesados', null, 'open', '#0d6efd', false],
        'esperando_llamada'       => ['Volver a llamar', 'Esperando la llamada', 'open', '#6f42c1', true],
        'en_prueba_sola'          => ['En prueba por su cuenta', null, 'open', '#20c997', false],
        'implementacion_acordada' => ['Implementación acordada', null, 'open', '#fd7e14', false],
        'en_implementacion'       => ['En implementación', null, 'open', '#6610f2', false],
        'en_seguimiento'          => ['Para cerrar', 'En seguimiento', 'open', '#ffc107', true],
        'cerrado'                 => ['Cliente', 'Cerrado', 'won', '#198754', false],
        'no_avanza'               => ['No avanza', null, 'lost', '#dc3545', false],
    ];

    /**
     * Corre el seeder.
     *
     * @param PipelineFieldsService|null $campos Normalizador de campos (se resuelve solo).
     *
     * @return void
     */
    public function run(PipelineFieldsService $campos = null)
    {
        if ($campos === null) {
            $campos = app(PipelineFieldsService::class);
        }

        $pipeline = Pipeline::query()->where('slug', PipelineLeadSeeder::SLUG)->first();
        if ($pipeline === null) {
            return;
        }

        DB::transaction(function () use ($pipeline, $campos) {
            /* El subtítulo del pipeline hablaba de «después de la demo»: sólo se cambia si sigue con el texto sembrado viejo. */
            if ((string) $pipeline->description === 'Cierre de los leads que confirmaron interés después de la demo.') {
                $pipeline->description = 'Tablero de Juan: de los leads que piden probar o implementar, hasta que son clientes.';
                $pipeline->save();
            }

            $orden = 0;

            foreach (self::COLUMNAS as $clave => $definicion) {
                list($nombre, $nombre_viejo, $tipo, $color, $sin_campos) = $definicion;

                $etapa = PipelineStage::query()->where('pipeline_id', $pipeline->id)->where('stage_key', $clave)->first();

                if ($etapa === null) {
                    $etapa = new PipelineStage();
                    $etapa->pipeline_id = $pipeline->id;
                    $etapa->stage_key   = $clave;
                    $etapa->name        = $nombre;
                    $etapa->color       = $color;
                    $etapa->type        = $tipo;
                    $etapa->fields      = $campos->normalizar_definicion([], 'fields');
                } elseif ($nombre_viejo !== null && $etapa->name === $nombre_viejo) {
                    /* Sigue con el nombre sembrado: se renombra (y pierde el campo que tenía, la fecha ahora es la próxima acción). */
                    $etapa->name = $nombre;
                    if ($sin_campos) {
                        $etapa->fields = $campos->normalizar_definicion([], 'fields');
                    }
                }

                $etapa->sort_order = $orden;
                $etapa->save();
                $orden++;
            }

            /* Las etapas propias van al final, en el orden que ya tenían. */
            $propias = PipelineStage::query()
                ->where('pipeline_id', $pipeline->id)
                ->whereNotIn('stage_key', array_keys(self::COLUMNAS))
                ->orWhere(function ($consulta) use ($pipeline) {
                    $consulta->where('pipeline_id', $pipeline->id)->whereNull('stage_key');
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($propias as $etapa) {
                $etapa->sort_order = $orden;
                $etapa->save();
                $orden++;
            }
        });
    }
}

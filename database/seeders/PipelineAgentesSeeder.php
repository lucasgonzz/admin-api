<?php

namespace Database\Seeders;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Pipelines\PipelineFieldsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El pipeline "Agentes" del CRM (misión pipelines-crm, 27/9/2026): ofrecerles el sistema de
 * agentes (el asistente) a los clientes activos. Es el primer uso del módulo.
 *
 * 🔴 IDEMPOTENTE Y SIN PISAR: si ya existe un pipeline con `slug = 'agentes'`, no hace NADA. No
 * "completa" etapas que falten ni corrige nombres: una vez sembrado, el pipeline es de Lucas, que
 * lo edita desde la pantalla de Configuración, y un seeder que corriera de nuevo en un deploy no
 * puede deshacerle una etapa renombrada ni un campo que agregó. Por eso la clave es el slug y no el
 * nombre (renombrar el pipeline tampoco lo duplica).
 *
 * Va a producción por `pendientes.json` del deploy de admin y también se llama desde
 * `DatabaseSeeder`.
 *
 * Las definiciones de campos pasan por `PipelineFieldsService::normalizar_definicion()`, el mismo
 * que valida lo que se carga desde la SPA: el seeder no puede sembrar algo que la pantalla después
 * no sepa editar.
 */
class PipelineAgentesSeeder extends Seeder
{
    /** Slug fijo del pipeline, para la idempotencia. */
    const SLUG = 'agentes';

    /**
     * Siembra el pipeline "Agentes" si no existe.
     *
     * @param PipelineFieldsService|null $campos Lo inyecta el contenedor (`db:seed --class=`); si
     *                                           alguien llama `run()` a mano, se resuelve acá.
     *
     * @return void
     */
    public function run(PipelineFieldsService $campos = null)
    {
        if ($campos === null) {
            $campos = app(PipelineFieldsService::class);
        }

        if (Pipeline::query()->where('slug', self::SLUG)->exists()) {
            return;
        }

        DB::transaction(function () use ($campos) {
            $pipeline = Pipeline::create([
                'name'                => 'Agentes',
                'slug'                => self::SLUG,
                'description'         => 'Ofrecimiento del sistema de agentes (tu asistente) a los clientes activos.',
                'lost_reasons'        => ['Precio', 'No lo necesita', 'No confía en la IA', 'No es el momento', 'Otro'],
                'sort_order'          => ((int) Pipeline::query()->max('sort_order')) + 1,
                'created_by_admin_id' => null,
            ]);

            foreach ($this->etapas() as $orden => $etapa) {
                PipelineStage::create([
                    'pipeline_id' => $pipeline->id,
                    'name'        => $etapa['name'],
                    'color'       => $etapa['color'],
                    'type'        => $etapa['type'],
                    'sort_order'  => $orden,
                    'fields'      => $campos->normalizar_definicion($etapa['fields'], 'fields'),
                ]);
            }
        });
    }

    /**
     * Las ocho etapas, en orden. El motivo de pérdida de "Perdido" no es un campo: lo pide el
     * sistema al mover, de `lost_reasons`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function etapas()
    {
        return [
            [
                'name'   => 'Por contactar',
                'type'   => PipelineStage::TYPE_OPEN,
                'color'  => '#adb5bd',
                'fields' => [],
            ],
            [
                'name'   => 'Contactado',
                'type'   => PipelineStage::TYPE_OPEN,
                'color'  => '#0dcaf0',
                /* `canal` NO es obligatorio (ronda de arreglos R3): "Contactado" es el movimiento
                   más frecuente y no tiene por qué frenar. Lucas lo puede volver obligatorio desde
                   Configuración si lo necesita. */
                'fields' => [
                    ['key' => 'canal', 'label' => 'Canal', 'type' => 'select', 'required' => false, 'agenda' => false, 'options' => ['WhatsApp', 'Llamada', 'Mail', 'Presencial']],
                ],
            ],
            [
                'name'   => 'Interesado',
                'type'   => PipelineStage::TYPE_OPEN,
                'color'  => '#0d6efd',
                'fields' => [
                    ['key' => 'que_le_intereso', 'label' => 'Qué le interesó', 'type' => 'textarea', 'required' => false, 'agenda' => false, 'options' => []],
                ],
            ],
            [
                'name'   => 'Reunión agendada',
                'type'   => PipelineStage::TYPE_OPEN,
                'color'  => '#6f42c1',
                'fields' => [
                    ['key' => 'fecha_reunion', 'label' => 'Fecha y hora', 'type' => 'datetime', 'required' => true, 'agenda' => true, 'options' => []],
                    ['key' => 'que_quiere_ver', 'label' => 'Qué quiere ver', 'type' => 'textarea', 'required' => false, 'agenda' => false, 'options' => []],
                    ['key' => 'participantes', 'label' => 'Quién participa', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
                ],
            ],
            [
                'name'   => 'Reunión hecha',
                'type'   => PipelineStage::TYPE_OPEN,
                'color'  => '#fd7e14',
                /* Sin "Próximo paso" (ronda de arreglos R3): competía con la "Próxima acción" del
                   modal de mover, que es la que alimenta la agenda. Dos lugares para decir lo mismo
                   terminan con uno vacío y el otro sin fecha. */
                'fields' => [
                    ['key' => 'como_fue', 'label' => 'Cómo fue', 'type' => 'textarea', 'required' => false, 'agenda' => false, 'options' => []],
                ],
            ],
            [
                'name'   => 'Más adelante',
                'type'   => PipelineStage::TYPE_OPEN,
                'color'  => '#ffc107',
                'fields' => [
                    ['key' => 'fecha_retomar', 'label' => 'Retomar el', 'type' => 'date', 'required' => true, 'agenda' => true, 'options' => []],
                ],
            ],
            [
                'name'   => 'Ganado',
                'type'   => PipelineStage::TYPE_WON,
                'color'  => '#198754',
                'fields' => [
                    ['key' => 'paquete', 'label' => 'Paquete', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
                    ['key' => 'precio_acordado', 'label' => 'Precio acordado', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
                ],
            ],
            [
                'name'   => 'Perdido',
                'type'   => PipelineStage::TYPE_LOST,
                'color'  => '#dc3545',
                'fields' => [],
            ],
        ];
    }
}

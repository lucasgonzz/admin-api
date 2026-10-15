<?php

namespace Database\Seeders;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Pipelines\PipelineFieldsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El pipeline "Leads" del CRM (misión embudo-lead-pipelines, 13/10/2026): el embudo de cierre de
 * Tomás, desde que el lead confirma interés después de la demo hasta que cierra o no avanza.
 *
 * A diferencia del pipeline "Agentes", que se carga a mano, **este se alimenta solo**:
 * `PipelineLeadFunnelService` da de alta la oportunidad cuando el lead pasa a `closer_activo` y la
 * mueve con el estado de su `LeadCall`. Por eso las etapas de acá no son decorativas: sus
 * `stage_key` son el contrato con esa automatización.
 *
 * 🔴 IDEMPOTENTE Y SIN PISAR: si ya existe un pipeline con `slug = 'lead'`, no hace NADA. No
 * "completa" etapas que falten ni corrige nombres. Mismo criterio que `PipelineAgentesSeeder` y por
 * la misma razón: el seeder va a producción en cada deploy por `pendientes.json`, y una vez
 * sembrado el pipeline es de Tomás, que lo edita desde Configuración. Un seeder que corriera de
 * nuevo no puede deshacerle una etapa renombrada ni un campo que agregó. La clave es el slug y no
 * el nombre, así que renombrar el pipeline tampoco lo duplica.
 *
 * Va a producción por `pendientes.json` del deploy de admin y también se llama desde
 * `DatabaseSeeder`.
 *
 * Las definiciones de campos pasan por `PipelineFieldsService::normalizar_definicion()`, el mismo
 * que valida lo que se carga desde la SPA: el seeder no puede sembrar algo que la pantalla después
 * no sepa editar.
 */
class PipelineLeadSeeder extends Seeder
{
    /** Slug fijo del pipeline, para la idempotencia y para que el SPA lo pueda linkear. */
    const SLUG = 'lead';

    /** Clave de la primera etapa: donde nace la oportunidad cuando el lead confirma interés. */
    const ETAPA_INTERESADOS = 'interesados';

    /** Clave de la etapa a la que se pasa cuando la videollamada tiene fecha. */
    const ETAPA_ESPERANDO_LLAMADA = 'esperando_llamada';

    /** Clave de la etapa a la que se pasa cuando la videollamada ya se hizo. */
    const ETAPA_EN_SEGUIMIENTO = 'en_seguimiento';

    /** Clave de la etapa ganada. */
    const ETAPA_CERRADO = 'cerrado';

    /** Clave de la etapa perdida. */
    const ETAPA_NO_AVANZA = 'no_avanza';

    /** Clave del campo donde se guarda la fecha de la videollamada. */
    const CAMPO_FECHA_LLAMADA = 'fecha_llamada';

    /**
     * Siembra el pipeline "Leads" si no existe.
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
                'name'                => 'Leads',
                'slug'                => self::SLUG,
                'description'         => 'Cierre de los leads que confirmaron interés después de la demo.',
                'lost_reasons'        => ['Precio', 'No lo necesita', 'Se fue con otro sistema', 'No es el momento', 'No contesta', 'Otro'],
                'sort_order'          => ((int) Pipeline::query()->max('sort_order')) + 1,
                'created_by_admin_id' => null,
            ]);

            foreach ($this->etapas() as $orden => $etapa) {
                PipelineStage::create([
                    'pipeline_id' => $pipeline->id,
                    'name'        => $etapa['name'],
                    'stage_key'   => $etapa['stage_key'],
                    'color'       => $etapa['color'],
                    'type'        => $etapa['type'],
                    'sort_order'  => $orden,
                    'fields'      => $campos->normalizar_definicion($etapa['fields'], 'fields'),
                ]);
            }
        });
    }

    /**
     * Las cinco etapas, en orden.
     *
     * 🔴 "Interesados" va PRIMERA y abierta porque el alta automática no nombra la etapa: pide la
     * primera abierta (`PipelineOpportunityService::etapa_inicial()` con `etapa_id` null). Si
     * alguien insertara una etapa abierta antes, las oportunidades nuevas nacerían ahí.
     *
     * 🔴 NINGÚN CAMPO ES OBLIGATORIO, y no es desprolijidad. Los campos de la etapa destino se
     * validan igual al mover que al dar de alta (`PipelineOpportunityService::mover()`, regla 2):
     * un campo obligatorio haría que el movimiento AUTOMÁTICO —el que dispara la `LeadCall`— tirara
     * 422, y el embudo se quedaría quieto sin que nada lo denunciara. Tomás puede volver obligatorio
     * cualquiera de estos desde Configuración, pero entonces va a tener que moverlas a mano.
     *
     * 🔴 `fecha_llamada` NO es campo de agenda (`agenda => false`), que es lo que más llama la
     * atención de esta definición. Un campo agenda con valor fija la próxima acción, y una próxima
     * acción con hora crea un evento *opaque* en el Google Calendar del responsable
     * (`PipelineCalendarSync`) — o sea, le tapa huecos de demo. Y la videollamada YA tiene su evento
     * y su Meet, creados por `CloserGoogleCalendarEventService` cuando se agendó. Marcarla como
     * agenda le comería a Tomás el doble de calendario por la misma llamada. Acá la fecha es un dato
     * para mirar, nada más.
     *
     * El motivo de pérdida de "No avanza" no es un campo: lo pide el sistema al mover, de
     * `lost_reasons`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function etapas()
    {
        return [
            [
                'name'      => 'Interesados',
                'stage_key' => self::ETAPA_INTERESADOS,
                'type'      => PipelineStage::TYPE_OPEN,
                'color'     => '#0d6efd',
                'fields'    => [],
            ],
            [
                'name'      => 'Esperando la llamada',
                'stage_key' => self::ETAPA_ESPERANDO_LLAMADA,
                'type'      => PipelineStage::TYPE_OPEN,
                'color'     => '#6f42c1',
                'fields'    => [
                    ['key' => self::CAMPO_FECHA_LLAMADA, 'label' => 'Fecha de la videollamada', 'type' => 'datetime', 'required' => false, 'agenda' => false, 'options' => []],
                ],
            ],
            [
                'name'      => 'En seguimiento',
                'stage_key' => self::ETAPA_EN_SEGUIMIENTO,
                'type'      => PipelineStage::TYPE_OPEN,
                'color'     => '#fd7e14',
                /* Sin campo de "próximo paso": competiría con la "Próxima acción" del modal de
                   mover, que es la que alimenta la agenda. Mismo criterio que "Reunión hecha" del
                   pipeline "Agentes": dos lugares para decir lo mismo terminan con uno vacío y el
                   otro sin fecha. */
                'fields'    => [
                    ['key' => 'como_fue', 'label' => 'Cómo fue la llamada', 'type' => 'textarea', 'required' => false, 'agenda' => false, 'options' => []],
                ],
            ],
            [
                'name'      => 'Cerrado',
                'stage_key' => self::ETAPA_CERRADO,
                'type'      => PipelineStage::TYPE_WON,
                'color'     => '#198754',
                'fields'    => [
                    ['key' => 'paquete', 'label' => 'Paquete', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
                    ['key' => 'precio_acordado', 'label' => 'Precio acordado', 'type' => 'text', 'required' => false, 'agenda' => false, 'options' => []],
                ],
            ],
            [
                'name'      => 'No avanza',
                'stage_key' => self::ETAPA_NO_AVANZA,
                'type'      => PipelineStage::TYPE_LOST,
                'color'     => '#dc3545',
                'fields'    => [],
            ],
        ];
    }
}

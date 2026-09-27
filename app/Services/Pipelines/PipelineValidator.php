<?php

namespace App\Services\Pipelines;

use Illuminate\Support\Facades\Validator;

/**
 * Validación de FORMA de los requests del CRM de pipelines, con mensajes en español.
 *
 * Es el validador de Laravel de siempre (mismas reglas, mismo formato de `errors`), con dos
 * diferencias que importan para la SPA:
 *  - los mensajes por regla están en español (el locale del admin es `en` y no hay traducciones);
 *  - si falla, tira `PipelineRuleException::validacion()`, cuyo `message` es el primer error y no
 *    el "The given data was invalid." fijo de Laravel 8.
 *
 * Solo valida la forma (tipos, largos, listas). Las reglas de negocio (qué etapa es válida, qué
 * campo es obligatorio en la etapa destino) viven en los servicios.
 */
class PipelineValidator
{
    /**
     * Mensajes por regla. `:Attribute` es el nombre con mayúscula inicial (lo resuelve Laravel).
     *
     * Las reglas de tamaño (`max`, `min`) van por tipo porque Laravel elige el mensaje según si el
     * atributo es texto, número, lista o archivo, y las cuatro claves tienen que estar.
     *
     * @var array<string, string|array<string, string>>
     */
    const MENSAJES = [
        'required' => 'Falta :attribute.',
        'present'  => 'Falta :attribute.',
        'string'   => ':Attribute tiene que ser texto.',
        'integer'  => ':Attribute tiene que ser un número entero.',
        'numeric'  => ':Attribute tiene que ser un número.',
        'array'    => ':Attribute tiene que ser una lista.',
        'boolean'  => ':Attribute tiene que ser sí o no.',
        'in'       => ':Attribute no es un valor válido.',
        'regex'    => ':Attribute no tiene un formato válido.',
        'exists'   => ':Attribute no existe.',
        'distinct' => ':Attribute está repetido.',
        'max'      => [
            'string'  => ':Attribute no puede tener más de :max caracteres.',
            'array'   => ':Attribute no puede tener más de :max elementos.',
            'numeric' => ':Attribute no puede ser mayor que :max.',
            'file'    => ':Attribute no puede pesar más de :max KB.',
        ],
        'min'      => [
            'string'  => ':Attribute tiene que tener al menos :min caracteres.',
            'array'   => ':Attribute tiene que tener al menos :min elemento(s).',
            'numeric' => ':Attribute tiene que ser al menos :min.',
            'file'    => ':Attribute tiene que pesar al menos :min KB.',
        ],
    ];

    /**
     * Nombres legibles de los atributos que usan los endpoints del módulo. Los comodines (`*`) los
     * resuelve Laravel para los elementos de una lista.
     *
     * @var array<string, string>
     */
    const ATRIBUTOS = [
        'name'               => 'el nombre',
        'description'        => 'la descripción',
        'lost_reasons'       => 'los motivos de pérdida',
        'lost_reasons.*'     => 'el motivo de pérdida',
        'archived'           => 'archivado',
        'stages'             => 'las etapas',
        'stages.*'           => 'la etapa',
        'stages.*.name'      => 'el nombre de la etapa',
        'stages.*.color'     => 'el color de la etapa',
        'stages.*.type'      => 'el tipo de etapa',
        'stages.*.fields'    => 'los campos de la etapa',
        'stage_ids'          => 'la lista de etapas',
        'stage_ids.*'        => 'la etapa',
        'color'              => 'el color',
        'type'               => 'el tipo',
        'fields'             => 'los campos',
        'stage_id'           => 'la etapa',
        'owner_admin_id'     => 'el responsable',
        'note'               => 'la nota',
        'body'               => 'la nota',
        'channel'            => 'el canal',
        'occurred_at'        => 'la fecha',
        'lost_reason'        => 'el motivo de pérdida',
        'next_action_at'     => 'la fecha de la próxima acción',
        'next_action_note'   => 'la nota de la próxima acción',
        'subjects'           => 'la lista de clientes o leads',
        'subjects.*'         => 'el cliente o lead',
        'subjects.*.type'    => 'el tipo de sujeto',
        'subjects.*.id'      => 'el id del sujeto',
        'estado'             => 'el estado',
        'subject_type'       => 'el tipo de sujeto',
        'q'                  => 'la búsqueda',
        'agenda'             => 'el filtro de agenda',
        'include_archived'   => 'ver archivados',
        'solo_activos'       => 'solo activos',
        'lead_status'        => 'el estado del lead',
        'limit'              => 'el límite',
        'offset'             => 'el desplazamiento',
        'client_id'          => 'el cliente',
        'lead_id'            => 'el lead',
        'pipeline_id'        => 'el pipeline',
    ];

    /**
     * Valida `$datos` contra `$reglas`. Si falla, tira la excepción con los errores por campo.
     *
     * @param array<string, mixed> $datos  Lo que vino en el request.
     * @param array<string, mixed> $reglas Reglas de Laravel.
     *
     * @return array<string, mixed> Los datos validados (solo las claves con regla).
     *
     * @throws PipelineRuleException
     */
    public static function validar(array $datos, array $reglas)
    {
        $validator = Validator::make($datos, $reglas, self::MENSAJES, self::ATRIBUTOS);

        if ($validator->fails()) {
            throw PipelineRuleException::validacion($validator->errors()->toArray());
        }

        return $validator->validated();
    }
}

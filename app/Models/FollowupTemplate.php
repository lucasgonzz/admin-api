<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla Meta aprobada para seguimientos automáticos directos por estado del lead.
 *
 * Cada fila representa la plantilla que corresponde enviar en un día concreto
 * dentro de la instancia de seguimiento de un estado (ver FollowupTemplatesSeeder).
 */
class FollowupTemplate extends Model
{
    protected $guarded = [];

    /**
     * Casts de tipos de los campos editables.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'activa'                     => 'boolean',
        'dia_numero'                 => 'integer',
        /* Cast booleano para el campo de bifurcación de seguimiento por ingreso a demo. */
        'solo_si_ingreso_confirmado' => 'boolean',
    ];

    /**
     * Atributos derivados que viajan en el JSON de la plantilla.
     *
     * Se calculan a partir de `estado` + `solo_si_ingreso_confirmado` + `template_name`:
     * no hay columnas nuevas en la tabla.
     *
     * @var array<int, string>
     */
    protected $appends = ['categoria', 'categoria_label', 'categoria_orden', 'variables'];

    /**
     * Scope estándar para contrato homogéneo con fullModel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * Accessor `categoria`: slug estable usado por el frontend para agrupar
     * las plantillas del selector, derivado de `estado` (que mezcla estados
     * reales del pipeline con centinelas como `recordatorio`,
     * `manual_recuperacion` y `manual_check_demo`).
     *
     * @return string
     */
    public function getCategoriaAttribute()
    {
        /* Estado crudo de la fila, casteado a string por seguridad. */
        $estado = (string) $this->estado;

        if ($estado === 'manual_recuperacion') {
            return 'recuperacion';
        }
        if ($estado === 'manual_check_demo') {
            return 'check_demo';
        }
        if ($estado === 'recordatorio') {
            return 'recordatorio';
        }
        /* Los dos seguimientos de demo_agendada se separan por el flag de ingreso. */
        if ($estado === 'demo_agendada') {
            return $this->solo_si_ingreso_confirmado
                ? 'seguimiento_demo_en_curso'
                : 'seguimiento_demo_agendada';
        }

        /* Estados normales del pipeline de leads: se agrupan como "seguimiento_<estado>". */
        $pipeline = ['nuevo', 'contactado', 'calificado', 'demo_realizada', 'mail2_enviado'];
        if (in_array($estado, $pipeline, true)) {
            return 'seguimiento_' . $estado;
        }

        return 'otros';
    }

    /**
     * Accessor `categoria_label`: título legible del grupo para mostrar
     * como encabezado de sección en el modal selector de plantillas.
     *
     * @return string
     */
    public function getCategoriaLabelAttribute()
    {
        /* Slug de categoría ya resuelto por el accessor `categoria`. */
        $categoria = $this->categoria;

        /* Mapeo slug -> etiqueta humana mostrada en el modal. */
        $labels = [
            'recuperacion'              => 'Retomar conversación',
            'check_demo'                => 'Chequeos durante la demo',
            'recordatorio'              => 'Recordatorios de demo',
            'seguimiento_nuevo'         => 'Seguimiento — Lead nuevo',
            'seguimiento_contactado'    => 'Seguimiento — Contactado',
            'seguimiento_calificado'    => 'Seguimiento — Calificado',
            'seguimiento_demo_agendada' => 'Seguimiento — Demo agendada (no ingresó)',
            'seguimiento_demo_en_curso' => 'Seguimiento — Demo empezada sin terminar',
            'seguimiento_demo_realizada'=> 'Seguimiento — Demo realizada (closer)',
            'seguimiento_mail2_enviado' => 'Seguimiento — Cierre (closer)',
            'otros'                     => 'Otras plantillas',
        ];

        return isset($labels[$categoria]) ? $labels[$categoria] : 'Otras plantillas';
    }

    /**
     * Accessor `categoria_orden`: posición del grupo dentro del modal
     * (las categorías manuales de recuperación/chequeo van primero).
     *
     * @return int
     */
    public function getCategoriaOrdenAttribute()
    {
        /* Slug de categoría ya resuelto por el accessor `categoria`. */
        $categoria = $this->categoria;

        /* Mapeo slug -> orden numérico de presentación. */
        $orden = [
            'recuperacion'               => 1,
            'check_demo'                 => 2,
            'recordatorio'               => 3,
            'seguimiento_nuevo'          => 4,
            'seguimiento_contactado'     => 5,
            'seguimiento_calificado'     => 6,
            'seguimiento_demo_agendada'  => 7,
            'seguimiento_demo_en_curso'  => 8,
            'seguimiento_demo_realizada' => 9,
            'seguimiento_mail2_enviado'  => 10,
            'otros'                      => 99,
        ];

        return isset($orden[$categoria]) ? $orden[$categoria] : 99;
    }

    /**
     * Accessor `variables`: describe qué significa cada placeholder `{{n}}`
     * presente en `body_template` de ESTA plantilla puntual, incluyendo de
     * qué campo del lead se autocompleta (si corresponde) y si el motivo
     * puede ser redactado por IA (usado por el endpoint del prompt 390).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getVariablesAttribute()
    {
        /* Cuerpo de la plantilla donde se buscan los placeholders {{n}}. */
        $body = (string) $this->body_template;
        if ($body === '') {
            return [];
        }

        /* Acumulador de variables detectadas, en el orden en que se evalúan. */
        $variables = [];

        /* {{1}} es siempre el nombre del contacto en todas las plantillas de ComercioCity. */
        if (strpos($body, '{{1}}') !== false) {
            $variables[] = [
                'placeholder'    => '{{1}}',
                'field'          => 'contact_name',
                'label'          => 'Nombre del contacto',
                'ai_suggestable' => false,
            ];
        }

        /*
         * {{2}} significa cosas distintas según la plantilla:
         *   - cc_recuperacion_motivo  -> motivo de la demora (lo redacta Claude o el admin)
         *   - recordatorios de demo   -> hora de la demo (sale del lead)
         */
        if (strpos($body, '{{2}}') !== false) {
            if ($this->template_name === 'cc_recuperacion_motivo') {
                $variables[] = [
                    'placeholder'    => '{{2}}',
                    'field'          => null,
                    'label'          => 'Motivo de la demora',
                    'ai_suggestable' => true,
                ];
            } else {
                $variables[] = [
                    'placeholder'    => '{{2}}',
                    'field'          => 'demo_start_time',
                    'label'          => 'Hora de la demo (HH:MM)',
                    'ai_suggestable' => false,
                ];
            }
        }

        return $variables;
    }
}

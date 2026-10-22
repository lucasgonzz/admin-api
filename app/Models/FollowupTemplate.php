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
     * ¿Se puede mandar esta plantilla? El gate de los tres frenos (fila existente, `activa` y
     * `meta_status = APPROVED`), sin la respuesta HTTP.
     *
     * Extraído el 22/10/2026 (misión demo-autogestionada-landing) de
     * `ClaudeLeadsOutboundController::plantilla_para_enviar()`, que lo sigue usando con sus mismos 422:
     * la confirmación por WhatsApp de la reserva desde la landing (D10) tiene que mirar exactamente lo
     * mismo antes de mandar `cc_demo_reservada_landing_v2`, y no tenía cómo llamar a un método protegido de
     * un controller. El porqué de cada freno está en el docblock de ese método.
     *
     * @param string $template_name Nombre ya trimeado.
     *
     * @return array{fila: FollowupTemplate|null, motivo: string|null} `motivo` null = se puede mandar.
     *         Si no: `nombre_vacio`, `plantilla_no_registrada`, `plantilla_inactiva` o
     *         `plantilla_no_aprobada` (en los dos últimos `fila` viene cargada, para el mensaje).
     */
    public static function estado_para_enviar(string $template_name): array
    {
        if ($template_name === '') {
            return ['fila' => null, 'motivo' => 'nombre_vacio'];
        }

        $fila = self::query()->where('template_name', $template_name)->first();
        if ($fila === null) {
            return ['fila' => null, 'motivo' => 'plantilla_no_registrada'];
        }

        if ((bool) $fila->activa !== true) {
            return ['fila' => $fila, 'motivo' => 'plantilla_inactiva'];
        }

        $meta_status = $fila->meta_status !== null ? strtoupper((string) $fila->meta_status) : '';
        if ($meta_status !== 'APPROVED') {
            return ['fila' => $fila, 'motivo' => 'plantilla_no_aprobada'];
        }

        return ['fila' => $fila, 'motivo' => null];
    }

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
        /* Campaña masiva (22/10/2026): el rechazo de Meta (`whatsapp_delivery_status = 'fallido'`)
           de un mensaje de esta plantilla no pinta rojo ni cuenta en la tarjeta. Ver
           Lead::apply_condicion_entrega_fallida_que_cuenta(). */
        'no_marca_error_de_entrega'  => 'boolean',
        /* El JSON de componentes tal como se le mandó a Meta, con los ejemplos de cada variable.
           Es de donde el accessor `variables` saca una muestra real para rotular cada hueco. */
        'components'                 => 'array',
        'meta_synced_at'             => 'datetime',
    ];

    /**
     * Qué significan `{{2}}` y `{{3}}` en las plantillas que ya existen.
     *
     * 🔴 ESTA TABLA EXISTE PORQUE EL SIGNIFICADO DE UN HUECO NO ESTÁ EN NINGÚN LADO DEL SISTEMA.
     * `followup_templates` guarda el texto con sus `{{n}}` y nada más: qué va en cada uno vive en
     * `comercial/plantillas_chequeo_diario.md` del repo de conocimiento, que es un documento y no
     * una fuente que el código pueda leer. Las etiquetas de abajo son copia literal de las tablas
     * "variables" de ese archivo (bloques A, B y C, versión del 2/9/2026).
     *
     * Para una plantilla NUEVA no hace falta tocar esto: las que crea
     * `POST claude/templates/meta` guardan sus ejemplos en `components`, y el accessor rotula cada
     * hueco con la muestra real. Esta tabla es para las que se cargaron antes de que esa columna
     * existiera.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    /* 🔴 `ai_suggestable` en true SOLO en `cc_recuperacion_motivo`: en el panel ese flag muestra
       «Sugerir con IA», y el botón llama siempre a `suggest-recovery-reason`, que redacta el MOTIVO
       DE LA DEMORA. En cualquier otro hueco llenaría «la respuesta» o «el momento que propuso el
       lead» con un motivo de demora. Hasta que haya una sugerencia que entienda cada hueco, el resto
       va en false (chequeo independiente del 15/10/2026). */
    const SIGNIFICADO_POR_PLANTILLA = [
        'cc_recuperacion_motivo' => [
            2 => ['label' => 'Motivo de la demora', 'ai_suggestable' => true],
        ],
        'cc_coord_dia_acordado' => [
            2 => ['label' => 'El momento que propuso el lead, EN SUS PALABRAS (ej. «el viernes a la mañana»)', 'ai_suggestable' => false],
        ],
        'cc_coord_horario_sin_confirmar' => [
            2 => ['label' => 'El día u horario que se le había ofrecido (ej. «el viernes a las 16»)', 'ai_suggestable' => false],
        ],
        'cc_coord_espera_lead' => [
            2 => ['label' => 'El impedimento que nombró el lead, en sus palabras (ej. «lo de la luz en el local»)', 'ai_suggestable' => false],
        ],
        'cc_coord_consulta_sin_cerrar' => [
            2 => ['label' => 'El tema exacto que quedó abierto (ej. «la migración desde tu Excel»)', 'ai_suggestable' => false],
        ],
        'cc_closer_confirmar_horario' => [
            2 => ['label' => 'Cuándo se acordó la llamada (ej. «hoy», «el jueves»)', 'ai_suggestable' => false],
        ],
        'cc_closer_confirmar_horario_v2' => [
            2 => ['label' => 'Cuándo se acordó la llamada (ej. «hoy», «el jueves»)', 'ai_suggestable' => false],
        ],
        'cc_respuesta_pendiente' => [
            2 => ['label' => 'Qué preguntó el lead (ej. «cómo es la migración de tus datos»)', 'ai_suggestable' => false],
            3 => ['label' => 'La respuesta, breve y CERRADA CON PUNTO (después va texto fijo)', 'ai_suggestable' => false],
        ],
        'cc_post_llamada_retomar' => [
            2 => ['label' => 'El punto que más le pesaba, del resumen de la llamada', 'ai_suggestable' => false],
        ],
        'cc_nutre_contenido' => [
            2 => ['label' => 'El dolor, EN LAS PALABRAS del lead (ej. «el stock que nunca sabés si está actualizado»)', 'ai_suggestable' => false],
            3 => ['label' => 'Link al reel o publicación, CERRADO CON PUNTO', 'ai_suggestable' => false],
        ],
        'cc_nutre_testimonio' => [
            2 => ['label' => 'El dolor compartido con el cliente del testimonio', 'ai_suggestable' => false],
            3 => ['label' => 'Link al reel del testimonio, CERRADO CON PUNTO', 'ai_suggestable' => false],
        ],
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
     * 🔴 REESCRITO EL 13/10/2026 (misión `plantillas-meta-y-leads-humano`, punto 5 de M5) PORQUE
     * ROTULABA MAL, Y ROTULAR MAL ACÁ SE TRADUCE EN UN MENSAJE MAL ESCRITO A UN LEAD REAL. La
     * versión anterior tenía dos defectos, los dos denunciados en
     * `comercial/plantillas_chequeo_diario.md` y medidos sobre las diez plantillas del chequeo
     * diario cargadas el 3/9/2026:
     *
     *   1. A `{{2}}` lo llamaba «Hora de la demo (HH:MM)» en TODAS las plantillas menos una. En
     *      `cc_coord_dia_acordado` ese hueco es «el viernes» en las palabras del lead; en
     *      `cc_coord_espera_lead` es «lo de la luz en el local»; en `cc_respuesta_pendiente` es la
     *      pregunta que quedó colgada. De diez plantillas, en NUEVE la etiqueta era falsa. Quien
     *      mandaba una desde el selector del panel leía «Hora de la demo» y escribía una hora.
     *   2. A `{{3}}` directamente no lo listaba, y lo usan tres plantillas
     *      (`cc_respuesta_pendiente`, `cc_nutre_contenido`, `cc_nutre_testimonio`). El hueco
     *      quedaba invisible: el mensaje salía con un `{{3}}` sin valor y Meta lo descarta con
     *      `(#131008) Required parameter is missing`.
     *
     * Ahora recorre TODOS los `{{n}}` que el cuerpo tenga, en orden, y resuelve cada etiqueta por
     * cuatro vías en este orden de confianza:
     *
     *   1. `{{1}}` es siempre el nombre del contacto (todas las plantillas de ComercioCity abren
     *      con el nombre, y Meta prohíbe empezar el cuerpo con una variable).
     *   2. {@see self::SIGNIFICADO_POR_PLANTILLA} para las plantillas que ya existían.
     *   3. El ejemplo guardado en `components` para las que creó `POST claude/templates/meta`.
     *   4. Y solo si nada de lo anterior sabe: `{{2}}` sigue siendo la hora de la demo en las
     *      plantillas que el cron de seguimientos PUEDE alcanzar (las que no son `manual_*`). Ver
     *      el bloque 🔴 de ese tramo: ahí este accessor no rotula, RELLENA el valor que sale por
     *      WhatsApp, y cambiarlo dejaría seguimientos sin salir.
     *
     * 🔴 Y SI NINGUNA VÍA SABE, DICE QUE NO SABE. Devuelve «Hueco {{n}} — ver el texto de la
     * plantilla» con `ai_suggestable` en false, en vez de inventar un nombre de campo. Una etiqueta
     * honesta hace que quien la manda lea el texto; una etiqueta inventada hace que confíe.
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

        /* Números de hueco presentes, en orden de aparición y sin repetir: un `{{2}}` que aparece
           dos veces es UNA variable (Meta manda un solo parámetro y lo renderiza en los dos
           lugares), así que listarla dos veces haría contar mal los parámetros al enviar. */
        $numeros = [];
        if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $coincidencias) !== false) {
            foreach ($coincidencias[1] as $crudo) {
                $numero = (int) $crudo;
                if (! in_array($numero, $numeros, true)) {
                    $numeros[] = $numero;
                }
            }
        }
        sort($numeros);

        /* Ejemplos que se le mandaron a Meta, si esta plantilla los tiene guardados. */
        $ejemplos = $this->ejemplos_de_los_componentes();

        /* Mapa del nombre de esta plantilla, si está en la tabla de significados conocidos. */
        $nombre = (string) $this->template_name;
        $conocidos = isset(self::SIGNIFICADO_POR_PLANTILLA[$nombre])
            ? self::SIGNIFICADO_POR_PLANTILLA[$nombre]
            : [];

        $variables = [];

        foreach ($numeros as $numero) {
            $placeholder = '{{' . $numero . '}}';

            if ($numero === 1) {
                $variables[] = [
                    'placeholder'    => $placeholder,
                    'field'          => 'contact_name',
                    'label'          => 'Nombre del contacto',
                    'ai_suggestable' => false,
                ];
                continue;
            }

            if (isset($conocidos[$numero])) {
                $variables[] = [
                    'placeholder'    => $placeholder,
                    'field'          => null,
                    'label'          => (string) $conocidos[$numero]['label'],
                    'ai_suggestable' => (bool) $conocidos[$numero]['ai_suggestable'],
                ];
                continue;
            }

            /* El ejemplo que se le mandó a Meta es la mejor pista que puede tener una plantilla
               creada por API: es el valor real con el que se la aprobó. */
            if (isset($ejemplos[$numero - 1]) && trim((string) $ejemplos[$numero - 1]) !== '') {
                $variables[] = [
                    'placeholder'    => $placeholder,
                    'field'          => null,
                    'label'          => 'Hueco ' . $placeholder . ' — ejemplo: «' . trim((string) $ejemplos[$numero - 1]) . '»',
                    'ai_suggestable' => false,
                ];
                continue;
            }

            /*
             * 🔴 ÚLTIMO RECURSO PARA `{{2}}`, Y SOLO EN UNA PLANTILLA QUE EL CRON PUEDE ALCANZAR.
             *
             * Acá se conserva el comportamiento viejo —`{{2}}` es la hora de la demo y la completa
             * el sistema— y NO es una concesión: `LeadFollowupService::build_template_variable_pairs()`
             * usa el `field` de este accessor para RELLENAR cada `{{n}}` desde el lead. O sea que
             * este accessor no solo rotula una etiqueta en el panel: decide qué valor sale por
             * WhatsApp en un seguimiento automático. Poner `field => null` acá dejaría el parámetro
             * vacío, y el guard de `WhatsappSendService::send_template()` cortaría el envío: un
             * seguimiento que hoy sale dejaría de salir.
             *
             * El corte es `manual_*` y es el que ya usa todo el resto del sistema: una plantilla con
             * `estado` que no es un slug real del pipeline NUNCA la levanta
             * `find_template_for()` (busca con `where('estado', $lead->status)`), así que sus
             * variables las completa siempre una persona o `/leads`, con valores explícitos. Ahí
             * inventar «Hora de la demo» era justamente el defecto medido: de las diez plantillas
             * del chequeo diario, en nueve la etiqueta era falsa. En las que SÍ puede alcanzar el
             * cron (los slugs del pipeline y `recordatorio`) el único `{{2}}` que existió nunca fue
             * otra cosa que la hora, y acá no se puede medir la tabla real de producción para
             * afirmar lo contrario sobre las seis plantillas activas de `demo_agendada`.
             *
             * O sea: se arregla donde el defecto estaba (las manuales) y no se toca el camino que
             * anda (las automáticas). Una plantilla manual nueva con `{{2}}` cae en el bloque de
             * abajo y dice honestamente que no sabe.
             */
            if ($numero === 2 && strpos((string) $this->estado, 'manual_') !== 0) {
                $variables[] = [
                    'placeholder'    => $placeholder,
                    'field'          => 'demo_start_time',
                    'label'          => 'Hora de la demo (HH:MM)',
                    'ai_suggestable' => false,
                ];
                continue;
            }

            /* Ver el bloque 🔴 del docblock: cuando no se sabe, se dice. */
            $variables[] = [
                'placeholder'    => $placeholder,
                'field'          => null,
                'label'          => 'Hueco ' . $placeholder . ' — ver el texto de la plantilla',
                'ai_suggestable' => false,
            ];
        }

        return $variables;
    }

    /**
     * Los valores de ejemplo del componente BODY, tal como se le mandaron a Meta.
     *
     * `components` es `[['type' => 'BODY', 'text' => …, 'example' => ['body_text' => [[v1, v2]]]]]`:
     * un array de arrays, porque Meta acepta varias muestras del mismo conjunto de variables. Se
     * usa la primera.
     *
     * @return array<int, string>
     */
    protected function ejemplos_de_los_componentes(): array
    {
        $componentes = $this->components;
        if (! is_array($componentes)) {
            return [];
        }

        foreach ($componentes as $componente) {
            if (! is_array($componente)) {
                continue;
            }
            $tipo = isset($componente['type']) ? strtoupper((string) $componente['type']) : '';
            if ($tipo !== 'BODY') {
                continue;
            }
            if (! isset($componente['example']['body_text'][0]) || ! is_array($componente['example']['body_text'][0])) {
                continue;
            }

            $ejemplos = [];
            foreach ($componente['example']['body_text'][0] as $valor) {
                $ejemplos[] = is_array($valor) ? '' : (string) $valor;
            }

            return $ejemplos;
        }

        return [];
    }
}

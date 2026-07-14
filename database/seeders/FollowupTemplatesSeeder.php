<?php

namespace Database\Seeders;

use App\Models\FollowupTemplate;
use Illuminate\Database\Seeder;

/**
 * Siembra las plantillas Meta aprobadas usadas para enviar seguimientos
 * automáticos directos según estado del lead y número de día.
 *
 * Incluye `body_template` con el texto literal aprobado en Meta para los 10
 * estados activos. Los estados gestionados por el closer (demo_realizada y
 * mail2_enviado) quedan con body_template = null porque no se envían por plantilla.
 */
class FollowupTemplatesSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        // Plantillas por estado y día. El orden por dia_numero dentro de cada
        // estado define qué plantilla recibe el primer, segundo, etc. seguimiento.
        // body_template usa {{1}} como variable de nombre de contacto (igual que Meta).
        $templates = [
            [
                'estado'        => 'nuevo',
                'dia_numero'    => 2,
                'template_name' => 'cc_seg_nuevo_d2',
                'body_template' => 'Hola {{1}}! Hace un par de días te escribimos de ComercioCity y no tuvimos respuesta. ¿Pudiste ver el mensaje? Si tenés alguna consulta o se te cruzó algo, estamos por acá.',
            ],
            [
                'estado'        => 'nuevo',
                'dia_numero'    => 5,
                'template_name' => 'cc_seg_nuevo_d5',
                'body_template' => 'Hola {{1}}, te escribo de ComercioCity. Sé que a veces los mensajes se pierden entre tantas cosas... si todavía te interesa ver cómo podemos ayudarte a ordenar tu negocio, avisame y te cuento.',
            ],
            [
                'estado'        => 'contactado',
                'dia_numero'    => 1,
                'template_name' => 'cc_seg_contactado_d1',
                'body_template' => 'Hola {{1}}! Habíamos quedado en retomar y se me fue de vista. ¿Pudiste pensar en lo que te había preguntado sobre tu negocio? Cualquier cosa que necesites, acá estoy.',
            ],
            [
                'estado'        => 'contactado',
                'dia_numero'    => 4,
                'template_name' => 'cc_seg_contactado_d4',
                'body_template' => 'Hola {{1}}, te escribo de ComercioCity. Entiendo que el día a día no da mucho tiempo... si querés, en dos minutos me contás a qué se dedica tu empresa y cuánta gente trabaja con vos, y yo te digo si tenemos algo que te sirva.',
            ],
            [
                'estado'        => 'calificado',
                'dia_numero'    => 1,
                'template_name' => 'cc_seg_calificado_d1',
                'body_template' => 'Hola {{1}}! Quedamos en agendar la demo de ComercioCity y no tuve respuesta. ¿Tenés algún horario esta semana que te quede cómodo? La demo la hacés vos solo, dura aproximadamente una hora.',
            ],
            [
                'estado'        => 'calificado',
                'dia_numero'    => 3,
                'template_name' => 'cc_seg_calificado_d3',
                'body_template' => 'Hola {{1}}, te escribo de nuevo porque me parece que puede servirte lo que tenemos. La demo es autogestionada, la hacés cuando quieras, dura 1 hora... y después coordinamos una llamada breve de 10 minutos. ¿Lo hacemos esta semana?',
            ],
            [
                'estado'        => 'calificado',
                'dia_numero'    => 6,
                'template_name' => 'cc_seg_calificado_d6',
                'body_template' => 'Hola {{1}}, último mensaje de mi parte. Si en algún momento te interesa ver ComercioCity funcionando, escribime y lo coordinamos. Quedamos a disposición.',
            ],
            [
                'estado'        => 'demo_agendada',
                'dia_numero'    => 1,
                'template_name' => 'cc_seg_demo_agendada_d1',
                'body_template' => 'Hola {{1}}! Teníamos la demo de ComercioCity agendada y no llegaste a hacerla. ¿Se te complicó algo? Podemos reagendar para cuando mejor te quede.',
            ],
            [
                'estado'        => 'demo_agendada',
                'dia_numero'    => 3,
                'template_name' => 'cc_seg_demo_agendada_d3',
                'body_template' => 'Hola {{1}}, ¿cómo estás? Entiendo que el tiempo no siempre acompaña. Si querés retomar la demo de ComercioCity, avisame y te busco un nuevo horario.',
            ],
            [
                'estado'        => 'demo_agendada',
                'dia_numero'    => 7,
                'template_name' => 'cc_seg_demo_agendada_d7',
                'body_template' => 'Hola {{1}}, último intento de mi parte. Si en algún momento querés ver el sistema, escribime. Quedamos disponibles cuando lo decidas.',
            ],
            // Estas plantillas NO existen en Meta: el seguimiento lo maneja el closer de forma personalizada.
            // body_template queda null intencionalmente.
            ['estado' => 'demo_realizada', 'dia_numero' => 1, 'template_name' => 'cc_seg_demo_realizada_d1', 'activa' => false, 'body_template' => null],
            ['estado' => 'demo_realizada', 'dia_numero' => 3, 'template_name' => 'cc_seg_demo_realizada_d3', 'activa' => false, 'body_template' => null],
            ['estado' => 'demo_realizada', 'dia_numero' => 6, 'template_name' => 'cc_seg_demo_realizada_d6', 'activa' => false, 'body_template' => null],
            ['estado' => 'mail2_enviado',  'dia_numero' => 1, 'template_name' => 'cc_seg_cierre_d1',         'activa' => false, 'body_template' => null],
            ['estado' => 'mail2_enviado',  'dia_numero' => 2, 'template_name' => 'cc_seg_cierre_d2',         'activa' => false, 'body_template' => null],
            ['estado' => 'mail2_enviado',  'dia_numero' => 4, 'template_name' => 'cc_seg_cierre_d4',         'activa' => false, 'body_template' => null],
            // Plantillas para leads que YA iniciaron la demo pero no confirmaron que terminaron.
            // solo_si_ingreso_confirmado=true: se envían cuando demo_ingreso_confirmado = true.
            [
                'estado'                     => 'demo_agendada',
                'dia_numero'                 => 1,
                'template_name'              => 'cc_seg_demo_en_curso_d1',
                'body_template'              => 'Hola {{1}}! Habías entrado a la demo de ComercioCity... ¿pudiste terminar de recorrerla? Si te quedó algo pendiente, podés seguir cuando quieras.',
                'solo_si_ingreso_confirmado' => true,
            ],
            [
                'estado'                     => 'demo_agendada',
                'dia_numero'                 => 3,
                'template_name'              => 'cc_seg_demo_en_curso_d3',
                'body_template'              => 'Hola {{1}}, ¿cómo estás? Quedaste con la demo empezada de ComercioCity. ¿Pudiste terminarla? Cualquier duda que te haya quedado, avisame.',
                'solo_si_ingreso_confirmado' => true,
            ],
            [
                'estado'                     => 'demo_agendada',
                'dia_numero'                 => 6,
                'template_name'              => 'cc_seg_demo_en_curso_d6',
                'body_template'              => 'Hola {{1}}, último mensaje de mi parte. Si en algún momento querés terminar de ver la demo o charlar con alguien del equipo, escribime. Quedamos disponibles.',
                'solo_si_ingreso_confirmado' => true,
            ],
            // Plantilla MANUAL-ONLY de recuperación: "la falla fue nuestra" (caso Lead #253, 7/7/2026).
            // estado='manual_recuperacion' es un valor centinela que NO es ningún estado real del
            // pipeline (nuevo/contactado/calificado/demo_agendada/etc.) — por diseño, para que
            // LeadFollowupService::find_template_for() (que filtra por ->where('estado', $lead->status))
            // JAMÁS la seleccione automáticamente. Solo aparece en el selector manual del footer de la
            // conversación (TemplatePickerModal.vue, sección "Todas las plantillas", que lista por
            // activa=true sin filtrar por estado). dia_numero=1 es arbitrario, no se usa para ordenar
            // ningún seguimiento automático de esta fila.
            [
                'estado'        => 'manual_recuperacion',
                'dia_numero'    => 1,
                'template_name' => 'cc_recuperacion_demora_propia',
                'body_template' => 'Hola {{1}}! Perdoná la demora... se nos pasó tu mensaje y recién lo estoy viendo. ¿Seguís interesado? Si querés lo retomamos justo por donde habíamos quedado.',
            ],

            // ------------------------------------------------------------------
            // Plantillas MANUAL-ONLY de recuperación de conversación.
            // estado='manual_recuperacion' es un centinela: no es ningún estado real del
            // pipeline, así que find_template_for() nunca las selecciona automáticamente.
            // ------------------------------------------------------------------
            [
                'estado'        => 'manual_recuperacion',
                'dia_numero'    => 2,
                'template_name' => 'cc_retomamos_conversacion',
                'body_template' => 'Hola {{1}}! Quedó nuestra conversación por la mitad y no quiero que se pierda. ¿Te parece si la retomamos por donde habíamos quedado?',
                // activa=false hasta que Meta apruebe la plantilla. Lucas la activa desde el panel
                // de plantillas (Cuenta > Plantillas de seguimiento) cuando esté aprobada.
                'activa'        => false,
            ],
            [
                'estado'        => 'manual_recuperacion',
                'dia_numero'    => 3,
                'template_name' => 'cc_recuperacion_motivo',
                // {{2}} = motivo de la demora. Lo redacta Claude leyendo la conversación
                // (ver LeadRecoveryReasonService, prompt 390) o lo escribe el admin a mano.
                'body_template' => 'Hola {{1}}! Perdoná la demora en responderte, {{2}}. ¿Retomamos por donde habíamos quedado?',
                'activa'        => false,
            ],

            // ------------------------------------------------------------------
            // Plantillas del ciclo de demo que YA existen y están aprobadas en Meta, pero que
            // hasta ahora vivían solo hardcodeadas en los Commands (CheckDemoIngress,
            // CheckDemoFin, CheckDemoFinSeguimiento). Se registran acá con el estado centinela
            // 'manual_check_demo' ÚNICAMENTE para que el setter las pueda enviar a mano desde el
            // selector de la conversación cuando la ventana de 24hs ya está cerrada.
            // Los Commands NO cambian: siguen usando su propia constante TEMPLATE_NAME.
            // ------------------------------------------------------------------
            [
                'estado'        => 'manual_check_demo',
                'dia_numero'    => 1,
                'template_name' => 'cc_check_ingreso_demo',
                'body_template' => '¡Hola {{1}}! ¿Cómo vas? ¿Pudiste entrar a la demo?',
            ],
            [
                'estado'        => 'manual_check_demo',
                'dia_numero'    => 2,
                'template_name' => 'cc_check_fin_demo',
                'body_template' => '¡Hola {{1}}! ¿Pudiste recorrer la demo completa? 😊',
            ],
            [
                'estado'        => 'manual_check_demo',
                'dia_numero'    => 3,
                'template_name' => 'cc_check_fin_seguimiento_demo',
                'body_template' => '¡Hola {{1}}! ¿Pudiste terminar de recorrer la demo?',
            ],
        ];

        foreach ($templates as $row) {
            // Para las filas que traen 'activa' explícito (demo_realizada, mail2_enviado), respetar ese valor.
            // Para el resto de los estados, usar activa=true como valor por defecto.
            $activa = array_key_exists('activa', $row) ? $row['activa'] : true;

            /*
             * La clave incluye template_name para soportar múltiples plantillas con el mismo
             * (estado, dia_numero): ahora hay dos filas con estado=demo_agendada y dia_numero=1,
             * una para ingreso_confirmado=false y otra para ingreso_confirmado=true.
             */
            FollowupTemplate::updateOrCreate(
                ['estado' => $row['estado'], 'dia_numero' => $row['dia_numero'], 'template_name' => $row['template_name']],
                array_merge($row, [
                    'language_code'              => 'es_AR',
                    'activa'                     => $activa,
                    'solo_si_ingreso_confirmado' => $row['solo_si_ingreso_confirmado'] ?? false,
                ])
            );
        }
    }
}

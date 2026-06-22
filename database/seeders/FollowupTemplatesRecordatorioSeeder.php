<?php

namespace Database\Seeders;

use App\Models\FollowupTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeder standalone para agregar las plantillas Meta de recordatorio de demo.
 * Estas plantillas NO son de seguimiento automático — se envían por comandos
 * específicos (SendMorningDemoReminder, SendDemoReminders) y también quedan
 * disponibles para envío manual desde el modal de conversación.
 *
 * Idempotente: usa updateOrCreate con (estado, dia_numero, template_name).
 *
 * ⚠️ Antes de ejecutar, verificar que las plantillas estén aprobadas en Meta.
 */
class FollowupTemplatesRecordatorioSeeder extends Seeder
{
    public function run()
    {
        $templates = [
            [
                // Recordatorio que se envía el mismo día de la demo (a la mañana).
                // Plantilla con dos variables: {{1}} = nombre, {{2}} = hora de la demo.
                // Comando: SendMorningDemoReminder
                'estado'        => 'recordatorio',
                'dia_numero'    => 0,
                'template_name' => 'cc_recordatorio_manana_demo_v2',
                'body_template' => 'Hola {{1}}! Te recuerdo que hoy a las {{2}} tenés agendada la demo de ComercioCity. 🗓️ Revisá el mail que te enviamos para tener todo listo antes de entrar. Reservá unos 60 minutos sin interrupciones para aprovecharla al máximo. ¡Cualquier consulta estoy por acá! 😊',
                'language_code' => 'es_AR',
                'activa'        => true,
            ],
            [
                // Recordatorio que se envía minutos antes de la demo (pre-demo).
                // Plantilla con una variable: {{1}} = nombre del contacto.
                // Comando: SendDemoReminders
                'estado'        => 'recordatorio',
                'dia_numero'    => 1,
                'template_name' => 'cc_recordatorio_demo',
                'body_template' => 'Hola {{1}}! En unos minutos ya tenés disponible el acceso a la demo de ComercioCity. Un consejo antes de entrar: empezá por el video introductorio que te mandamos al mail, son 3 minutos y te van a ayudar a entender qué mirar cuando entrés al sistema. Cualquier duda que surja mientras recorrés la plataforma, escribime por acá. 👋',
                'language_code' => 'es_AR',
                'activa'        => true,
            ],
        ];

        foreach ($templates as $row) {
            FollowupTemplate::updateOrCreate(
                [
                    'estado'        => $row['estado'],
                    'dia_numero'    => $row['dia_numero'],
                    'template_name' => $row['template_name'],
                ],
                array_merge($row, [
                    'solo_si_ingreso_confirmado' => false,
                ])
            );
        }

        $this->command->info('FollowupTemplatesRecordatorioSeeder: 2 plantillas de recordatorio insertadas/actualizadas.');
    }
}

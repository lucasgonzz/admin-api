<?php

namespace Database\Seeders;

use App\Models\FollowupRule;
use Illuminate\Database\Seeder;

/**
 * Siembra reglas iniciales de seguimiento automático por estado del lead.
 */
class FollowupRulesSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        $rules = [
            ['estado' => 'nuevo', 'horas_espera' => 48, 'max_followups' => 1, 'descripcion' => 'No respondió el mensaje de bienvenida.'],
            ['estado' => 'contactado', 'horas_espera' => 24, 'max_followups' => 2, 'descripcion' => 'Respondió bienvenida pero no las preguntas de calificación.'],
            ['estado' => 'calificado', 'horas_espera' => 24, 'max_followups' => 3, 'descripcion' => 'No confirmó horario de demo.'],
            ['estado' => 'demo_agendada', 'horas_espera' => 24, 'max_followups' => 3, 'descripcion' => 'Confirmó pero no hizo la demo o no respondió.'],
            ['estado' => 'demo_realizada', 'horas_espera' => 24, 'max_followups' => 3, 'descripcion' => 'Hizo la demo pero no respondió qué le pareció.'],
            ['estado' => 'mail2_enviado', 'horas_espera' => 24, 'max_followups' => 3, 'descripcion' => 'Recibió la propuesta pero no respondió.'],
        ];

        foreach ($rules as $row) {
            FollowupRule::updateOrCreate(
                ['estado' => $row['estado']],
                array_merge($row, ['activa' => true])
            );
        }
    }
}

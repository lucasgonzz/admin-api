<?php

namespace Database\Seeders;

use App\Models\FollowupTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeder standalone para desactivar las plantillas Meta de los estados
 * demo_realizada y mail2_enviado en instalaciones existentes.
 *
 * Estas plantillas no existen en Meta Business Manager: el seguimiento en
 * esas etapas lo maneja el closer (Tommy) de forma personalizada via WhatsApp.
 * Sin esta desactivación, LeadFollowupService intentaría enviarlas y Kapso
 * rechazaría el envío.
 *
 * Idempotente: solo actualiza la columna activa, sin tocar otros campos.
 */
class FollowupTemplatesDesactivarDemoRealizadaCierreSeeder extends Seeder
{
    /**
     * Desactiva todas las plantillas de seguimiento de demo_realizada y mail2_enviado.
     *
     * @return void
     */
    public function run()
    {
        /* Estados cuyas plantillas se gestionan manualmente por el closer, no de forma automática. */
        $estados = ['demo_realizada', 'mail2_enviado'];

        FollowupTemplate::whereIn('estado', $estados)
            ->update(['activa' => false]);

        $this->command->info('Plantillas de demo_realizada y mail2_enviado desactivadas.');
    }
}

<?php

namespace Database\Seeders;

use App\Models\LeadMessage;
use Illuminate\Database\Seeder;

/**
 * Seeder de producción para marcar los mensajes de pausa existentes como status events.
 *
 * Busca todos los LeadMessage cuyo contenido corresponde al mensaje de pausa
 * automática y establece is_status_event = true, de modo que ya no generen
 * badge de "sin leer" ni actualicen last_message_at hacia adelante.
 *
 * Idempotente: solo actualiza registros que aún tienen is_status_event = false.
 */
class AddIsStatusEventToLeadMessagesSeeder extends Seeder
{
    /**
     * Texto exacto del mensaje de pausa automática generado por LeadFollowupService::pause_lead().
     */
    const PAUSE_CONTENT = 'Lead pasado a En Pausa automáticamente por inactividad.';

    /**
     * Actualiza todos los mensajes de pausa existentes marcándolos como status events.
     *
     * @return void
     */
    public function run()
    {
        /* Cantidad de registros actualizados para el informe del comando. */
        $updated = LeadMessage::where('content', self::PAUSE_CONTENT)
            ->where('is_status_event', false)
            ->update(['is_status_event' => true]);

        $this->command->info("AddIsStatusEventToLeadMessagesSeeder: {$updated} mensaje(s) de pausa marcados como status event.");
    }
}

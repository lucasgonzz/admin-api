<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca manual de "no leído" por admin sobre un lead (equivalente a "Marcar como no leído" de WhatsApp).
 *
 * No reemplaza el sistema real de lectura (`lead_message_reads`): es un flag visual independiente,
 * per-admin igual que el resto del sistema de lectura. Se limpia automáticamente cuando el admin
 * vuelve a abrir la conversación (ver LeadController::mark_whatsapp_messages_read_json) o al
 * volver a togglear desde la grilla (LeadController::toggle_manual_unread_json).
 */
class CreateLeadManualUnreadMarksTable extends Migration
{
    /**
     * Crea la tabla `lead_manual_unread_marks`.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('lead_manual_unread_marks', function (Blueprint $table) {
            $table->id();
            // Lead marcado como no leído (referencia a leads.id).
            $table->unsignedBigInteger('lead_id');
            // Admin que hizo la marca manual (referencia a admins.id). Per-usuario, igual que lead_message_reads.
            $table->unsignedBigInteger('admin_id');
            // Momento en que se marcó manualmente.
            $table->timestamp('marked_at');

            // Un admin no puede tener dos marcas manuales para el mismo lead.
            $table->unique(['lead_id', 'admin_id'], 'lmum_lead_admin_uq');

            // FKs con borrado en cascada: al eliminar el lead o el admin se limpian las marcas.
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->foreign('admin_id')->references('id')->on('admins')->onDelete('cascade');
        });
    }

    /**
     * Elimina la tabla `lead_manual_unread_marks`.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('lead_manual_unread_marks');
    }
}

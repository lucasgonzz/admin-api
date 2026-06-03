<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes de la conversación WhatsApp del lead (lead, setter, sistema/Claude).
 *
 * Sin FK declarativa: relación por `lead_id` en Eloquent.
 */
class CreateLeadMessagesTable extends Migration
{
    /**
     * Crea la tabla `lead_messages`.
     */
    public function up()
    {
        Schema::create('lead_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            // lead | setter | sistema (Claude)
            $table->string('sender', 20);
            $table->text('content');
            $table->text('ai_reasoning')->nullable();
            // enviado | sugerido | aprobado | rechazado
            $table->string('status', 20)->default('enviado')->index();
            $table->boolean('is_followup')->default(false)->index();
            // Si Claude indicó que el setter debe verificar con Lucas antes de enviar.
            $table->boolean('requiere_verificacion')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla `lead_messages`.
     */
    public function down()
    {
        Schema::dropIfExists('lead_messages');
    }
}

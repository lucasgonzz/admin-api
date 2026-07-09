<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega actions_override_log a lead_messages: guarda el diff entre lo que sugirió Claude y lo
 * que el admin efectivamente dejó al aprobar el mensaje (edición/desactivación de acciones antes
 * de aprobar), para poder analizar después qué tan seguido el admin corrige al agente. Cada
 * elemento del array: ['campo' => ..., 'sugerido_por_claude' => ..., 'elegido_por_admin' => ...].
 * Null cuando el admin no cambió ninguna acción respecto de lo sugerido (prompt 318).
 */
class AddActionsOverrideLogToLeadMessagesTable extends Migration
{
    /**
     * Agrega la columna json nullable, sin lógica asociada todavía (solo esquema).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->json('actions_override_log')->nullable()->after('applied_actions_summary');
        });
    }

    /**
     * Elimina la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('actions_override_log');
        });
    }
}

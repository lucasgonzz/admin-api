<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos de escalado a la tabla support_tickets.
 *
 * escalated_at: timestamp en que Claude marcó el ticket como escalado a humano.
 * escalation_reason: motivo corto del escalado, visible para el operador.
 */
return new class extends Migration
{
    /**
     * Agrega las columnas de escalado al final de la tabla.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            /* Momento exacto en que Claude decidió escalar el caso. */
            $table->timestamp('escalated_at')->nullable()->after('alert_sent_at');

            /* Texto corto que Claude genera para explicar por qué escala. */
            $table->text('escalation_reason')->nullable()->after('escalated_at');
        });
    }

    /**
     * Elimina las columnas de escalado si se revierte la migración.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['escalated_at', 'escalation_reason']);
        });
    }
};

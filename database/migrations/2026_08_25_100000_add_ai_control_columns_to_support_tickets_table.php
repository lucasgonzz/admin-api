<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Control del agente de IA por ticket, espejando lo que ya existe en `leads`.
 *
 * Hasta ahora el agente de soporte solo tenía interruptores GLOBALES en `admin_settings`:
 * o estaba prendido para todos los tickets o para ninguno. En leads, en cambio, cada
 * conversación decide por su cuenta (`leads.claude_auto_reply` y
 * `leads.requiere_verificacion_mensajes`). Estas dos columnas traen ese mismo control a
 * soporte, que es lo que Lucas pidió al hablar de "botones en el header".
 *
 * 🔴 `requiere_verificacion_mensajes` arranca en **true**, al revés que en leads (que arranca
 * en false). Es un pedido explícito: el agente de clientes no manda nada sin que una persona
 * lo lea primero, salvo que se lo apague ticket por ticket.
 */
class AddAiControlColumnsToSupportTicketsTable extends Migration
{
    /**
     * Agrega los dos interruptores por ticket.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            /* Agente prendido para este ticket. Apagarlo no borra los borradores que ya existan. */
            $table->boolean('claude_auto_reply')
                  ->default(true)
                  ->after('escalation_reason')
                  ->comment('Agente de IA activo para este ticket. Apagado, no se generan sugerencias nuevas.');

            /* Toda sugerencia queda esperando aprobación humana en vez de mandarse sola. */
            $table->boolean('requiere_verificacion_mensajes')
                  ->default(true)
                  ->after('claude_auto_reply')
                  ->comment('La sugerencia del agente espera aprobación humana; nunca se autoenvía.');
        });
    }

    /**
     * Revierte los dos interruptores por ticket.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['claude_auto_reply', 'requiere_verificacion_mensajes']);
        });
    }
}

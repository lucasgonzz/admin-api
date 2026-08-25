<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag notify_support_escalation_whatsapp al modelo Admin.
 *
 * Hasta ahora, cuando el agente escalaba un ticket de soporte a revisión humana, lo único
 * que pasaba era un aviso Pusher: si el operador no tenía el admin abierto, no se enteraba.
 * Con este flag, el escalado también sale por WhatsApp al `phone_number` del perfil.
 *
 * Es el mismo mecanismo que ya usa `notify_lead_escalation_whatsapp` para el agente de leads,
 * y a propósito arranca apagado: quien lo quiera lo prende en su perfil.
 */
class AddNotifySupportEscalationWhatsappToAdminsTable extends Migration
{
    /**
     * Agrega la columna boolean después de notify_lead_escalation_whatsapp.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            /* Flag para recibir WhatsApp cuando el agente escala un ticket de soporte. */
            $table->boolean('notify_support_escalation_whatsapp')
                  ->default(false)
                  ->after('notify_lead_escalation_whatsapp')
                  ->comment('Recibir WhatsApp cuando el agente escala un ticket de soporte a revisión humana.');
        });
    }

    /**
     * Elimina la columna de notificación de escalados de soporte.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('notify_support_escalation_whatsapp');
        });
    }
}

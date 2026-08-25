<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag notify_support_escalation_whatsapp al modelo Admin.
 *
 * Hasta ahora, cuando el agente escalaba un ticket de soporte a revisión humana, lo único
 * que pasaba era un aviso Pusher: si el operador no tenía el admin abierto, no se enteraba.
 * Con este flag, el escalado también sale por WhatsApp al `phone_number` del perfil.
 *
 * Es el mismo mecanismo que ya usa `notify_lead_escalation_whatsapp` para el agente de leads.
 * Arranca apagado para todos MENOS para el dueño por defecto de soporte: lo que se pidió fue
 * "que me avise a mí", y dejar la función entera dependiendo de que alguien se acuerde de
 * prender un check en su perfil es entregarla apagada. Al que ya es responsable de soporte le
 * llega desde el primer escalado; el resto la prende si la quiere.
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

        /* El dueño por defecto de soporte lo recibe sin tener que ir a prenderlo. Si no hay
         * ninguno marcado, no se toca a nadie y el flag queda apagado para todos. */
        DB::table('admins')
            ->where('is_default_support_owner', true)
            ->update(['notify_support_escalation_whatsapp' => true]);
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

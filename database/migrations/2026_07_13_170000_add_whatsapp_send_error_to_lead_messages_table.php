<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motivo legible del fallo de envío por WhatsApp de un mensaje saliente del lead.
 *
 * Se llena cuando el envío no se confirmó (whatsapp_message_id null): guarda el detalle real
 * (excepción, status HTTP de Kapso/Meta, número inválido, config inactiva, etc.) capturado por
 * WhatsappSendService::$last_send_error, o el motivo conocido en el call site (ventana 24hs cerrada,
 * lead sin teléfono). Null cuando el mensaje se envió bien o no aplica.
 */
class AddWhatsappSendErrorToLeadMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->text('whatsapp_send_error')->nullable();
        });
    }

    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn('whatsapp_send_error');
        });
    }
}

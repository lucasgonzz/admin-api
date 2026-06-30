<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de estado de entrega real de WhatsApp para mensajes salientes de leads.
 *
 * whatsapp_delivery_status: estado de entrega reportado por Kapso (entregado | leido | fallido).
 *   El estado "enviado" se sigue infiriendo por la presencia de whatsapp_message_id, no se persiste acá.
 * whatsapp_delivered_at: momento en que Kapso confirmó que el mensaje llegó al dispositivo.
 * whatsapp_seen_at:      momento en que el lead abrió el mensaje (doble check azul).
 *
 * Sin FK declarativa: relación por lead_id en Eloquent.
 */
class AddWhatsappDeliveryStatusToLeadMessagesTable extends Migration
{
    /**
     * Agrega las 3 columnas de estado de entrega WhatsApp a lead_messages.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            // Estado de entrega real informado por el webhook de Kapso (entregado | leido | fallido).
            $table->string('whatsapp_delivery_status')->nullable();

            // Marca temporal de entrega al dispositivo del lead (evento whatsapp.message.delivered).
            $table->timestamp('whatsapp_delivered_at')->nullable();

            // Marca temporal de lectura por el lead (evento whatsapp.message.read — doble check azul).
            $table->timestamp('whatsapp_seen_at')->nullable();
        });
    }

    /**
     * Revierte las columnas de estado de entrega.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_delivery_status',
                'whatsapp_delivered_at',
                'whatsapp_seen_at',
            ]);
        });
    }
}

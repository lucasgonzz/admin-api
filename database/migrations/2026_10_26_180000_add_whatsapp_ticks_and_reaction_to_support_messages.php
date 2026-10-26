<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticks de entrega de WhatsApp y reacción del operador en los mensajes de soporte.
 *
 * Espejo deliberado de lo que `lead_messages` ya tiene (`whatsapp_delivery_status`,
 * `whatsapp_delivered_at`, `whatsapp_seen_at` y `admin_reaction_*`), para que el hilo de soporte
 * pueda mostrar enviado / entregado / leído en cada mensaje saliente y para que el operador
 * pueda reaccionar a los mensajes que manda el cliente.
 *
 * 🔴 NO se reutilizan `delivered_at` ni `read_at` de `support_messages`: el primero es el espejo
 * con el ERP del cliente y el segundo es «leído por el operador» (alimenta los no leídos de la
 * bandeja). Mezclarlos con los ticks de WhatsApp rompería los contadores.
 *
 * Todo es aditivo: columnas nullable, sin foreign keys y sin índices nuevos (la búsqueda del
 * webhook de estados ya usa el índice único de `whatsapp_message_id`).
 */
class AddWhatsappTicksAndReactionToSupportMessages extends Migration
{
    /**
     * Agrega las columnas de estado de entrega y de reacción del operador.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            // Estado de entrega informado por el webhook de Kapso: entregado | leido | fallido.
            // Null = «enviado» (se infiere por la presencia de whatsapp_message_id).
            $table->string('whatsapp_delivery_status', 20)->nullable();
            // Momento en que WhatsApp avisó que el mensaje llegó al teléfono del cliente.
            $table->timestamp('whatsapp_delivered_at')->nullable();
            // Momento en que el cliente lo leyó (doble check azul).
            $table->timestamp('whatsapp_seen_at')->nullable();
            // Emoji con el que el operador reaccionó a un mensaje del cliente (null = sin reacción).
            $table->string('admin_reaction_emoji', 16)->nullable();
            // Momento en que el operador dejó la reacción.
            $table->timestamp('admin_reaction_at')->nullable();
            // Operador que reaccionó (sin FK, igual que sender_admin_id).
            $table->unsignedBigInteger('admin_reaction_admin_id')->nullable();
            // wamid que devolvió Meta por nuestro POST de reacción.
            $table->string('admin_reaction_whatsapp_message_id', 191)->nullable();
        });
    }

    /**
     * Revierte las columnas agregadas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_delivery_status',
                'whatsapp_delivered_at',
                'whatsapp_seen_at',
                'admin_reaction_emoji',
                'admin_reaction_at',
                'admin_reaction_admin_id',
                'admin_reaction_whatsapp_message_id',
            ]);
        });
    }
}

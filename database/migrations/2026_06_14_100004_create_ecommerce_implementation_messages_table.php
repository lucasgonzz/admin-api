<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensaje WhatsApp del flujo de implementación de ecommerce (entrada o salida).
 */
class CreateEcommerceImplementationMessagesTable extends Migration
{
    /**
     * Crea la tabla ecommerce_implementation_messages.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ecommerce_implementation_messages', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Implementación de ecommerce asociada (índice vía FK con nombre corto).
            $table->unsignedBigInteger('ecommerce_implementation_id');
            // Etapa en la que se registró el mensaje.
            $table->unsignedTinyInteger('stage_number');
            // Dirección del mensaje.
            $table->enum('direction', ['inbound', 'outbound']);
            // Contenido del mensaje.
            $table->text('body');
            // Id externo de WhatsApp para idempotencia.
            $table->string('whatsapp_message_id')->nullable();
            // Momento de envío/recepción.
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('ecommerce_implementation_id', 'ecom_impl_msgs_impl_id_fk')
                ->references('id')
                ->on('ecommerce_implementations')
                ->onDelete('cascade');
        });
    }

    /**
     * Elimina la tabla ecommerce_implementation_messages.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ecommerce_implementation_messages');
    }
}

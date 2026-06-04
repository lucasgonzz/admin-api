<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes WhatsApp del flujo de implementación (entrada y salida).
 */
class CreateImplementationMessagesTable extends Migration
{
    /**
     * Crea la tabla implementation_messages.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('implementation_messages', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Implementación asociada.
            $table->unsignedBigInteger('implementation_id');
            // Etapa en la que se envió o recibió el mensaje.
            $table->unsignedTinyInteger('stage_number');
            // Dirección del mensaje respecto al sistema.
            $table->enum('direction', ['inbound', 'outbound']);
            // Cuerpo del mensaje.
            $table->text('body');
            // Id de WhatsApp para idempotencia (único cuando está definido).
            $table->string('whatsapp_message_id', 100)->nullable()->unique();
            // Momento de envío efectivo.
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('implementation_id')->references('id')->on('implementations')->onDelete('cascade');
            $table->index(['implementation_id', 'stage_number']);
        });
    }

    /**
     * Elimina la tabla implementation_messages.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('implementation_messages');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla recall_configs para almacenar las credenciales de integración con Recall.ai.
 *
 * Es una tabla singleton (se espera un único registro activo por entorno),
 * siguiendo el mismo patrón que whatsapp_config.
 */
class CreateRecallConfigsTable extends Migration
{
    /**
     * Crea la tabla con los campos necesarios para la integración con Recall.ai.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('recall_configs', function (Blueprint $table) {
            $table->id();

            /* Clave de API de Recall.ai para autenticar las peticiones. */
            $table->string('recall_api_key');

            /* Secreto HMAC opcional para validar la firma de los webhooks entrantes de Recall. */
            $table->string('webhook_secret')->nullable();

            /* Indica si esta configuración está activa y debe usarse. */
            $table->boolean('is_active')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla recall_configs.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recall_configs');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Endpoints de API por cliente (URL base + path) para deployment automatizado.
 */
class CreateClientApisTable extends Migration
{
    /**
     * Crea la tabla client_apis.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_apis', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Identificador público para rutas y UI.
            $table->uuid('uuid');

            // Cliente al que pertenece este endpoint.
            $table->unsignedBigInteger('client_id');

            // URL base del servidor (ej. https://ejemplo.com).
            $table->string('url');

            // Path relativo de la API (ej. distri-creo/api).
            $table->string('path');

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla client_apis.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_apis');
    }
}

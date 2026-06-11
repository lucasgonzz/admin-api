<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna setup_data a clients para guardar los datos de configuración
 * recolectados durante la Etapa 1 de implementación y reutilizarlos al ejecutar
 * el UserSetup en empresa-api.
 */
class AddSetupDataToClientsTable extends Migration
{
    /**
     * Agrega la columna JSON setup_data.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // JSON con los datos de configuración recolectados (tipo de negocio, redes, precios, etc.).
            $table->json('setup_data')->nullable()->after('user_id');
        });
    }

    /**
     * Elimina la columna setup_data.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('setup_data');
        });
    }
}

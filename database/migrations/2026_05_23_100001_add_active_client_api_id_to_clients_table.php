<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API activa del cliente para operaciones de deployment.
 */
class AddActiveClientApiIdToClientsTable extends Migration
{
    /**
     * Agrega active_client_api_id a clients.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Endpoint de API seleccionado como activo para este cliente.
            $table->unsignedBigInteger('active_client_api_id')->nullable()->after('current_version_id');
        });
    }

    /**
     * Revierte la columna active_client_api_id.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('active_client_api_id');
        });
    }
}

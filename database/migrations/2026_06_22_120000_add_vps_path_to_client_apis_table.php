<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna vps_path a client_apis.
 *
 * Identificador del cliente en el VPS para el deployment automatizado.
 * Ejemplos de uso:
 *   API SSH:  /home/api-{vps_path}/empresa-api
 *   SPA SSH:  /home/{vps_path}/htdocs/{dominio_spa}
 */
class AddVpsPathToClientApisTable extends Migration
{
    /**
     * Agrega la columna vps_path nullable después de hosting_type.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            /* Identificador corto del cliente en el VPS (ej: arfren2). Solo requerido cuando hosting_type=vps. */
            $table->string('vps_path')->nullable()->after('hosting_type');
        });
    }

    /**
     * Elimina la columna vps_path.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_apis', function (Blueprint $table) {
            $table->dropColumn('vps_path');
        });
    }
}

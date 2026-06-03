<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega relación opcional desde leads hacia demos administradas en admin-api.
 */
class AddDemoIdToLeadsTable extends Migration
{
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Demo seleccionada para el lead dentro del catálogo administrable.
            $table->unsignedBigInteger('demo_id')->nullable()->after('target_client_id');
            // Índice para filtrar/reportar leads por demo elegida.
            $table->index('demo_id');
        });
    }

    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['demo_id']);
            $table->dropColumn('demo_id');
        });
    }
}

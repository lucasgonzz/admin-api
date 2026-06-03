<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivote polimórfico: ítems de versión restringidos a clientes (sin filas = todos).
 */
class CreateVersionItemClientsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('version_item_clients', function (Blueprint $table) {
            $table->id();
            $table->string('version_item_type', 100);
            $table->unsignedBigInteger('version_item_id');
            $table->unsignedBigInteger('client_id');
            $table->timestamps();

            $table->index(['version_item_type', 'version_item_id'], 'vic_item_morph_idx');
            $table->index('client_id', 'vic_client_id_idx');
            $table->unique(
                ['version_item_type', 'version_item_id', 'client_id'],
                'vic_item_client_uq'
            );
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('version_item_clients');
    }
}

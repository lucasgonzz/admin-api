<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reserva bloques globales de user_id (de 100 en 100) para evitar colisiones
 * entre distintos sistemas de clientes.
 *
 * Ejemplo de bloques:
 * - 100  -> usuarios 100-199
 * - 200  -> usuarios 200-299
 */
class CreateUserIdBlocksTable extends Migration
{
    public function up()
    {
        Schema::create('user_id_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('block_start')->unique();
            $table->string('source', 30)->default('lead_create');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')
                ->references('id')->on('leads')
                ->onDelete('set null');

            $table->foreign('client_id')
                ->references('id')->on('clients')
                ->onDelete('set null');

            $table->index('lead_id');
            $table->index('client_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_id_blocks');
    }
}

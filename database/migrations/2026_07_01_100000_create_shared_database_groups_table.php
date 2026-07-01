<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grupos de clientes que comparten la misma base de datos física (p. ej. ERP local + cyberbar).
 */
class CreateSharedDatabaseGroupsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('shared_database_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable()->comment('Nombre descriptivo opcional del grupo');
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shared_database_groups');
    }
}

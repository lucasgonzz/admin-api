<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que slug en clients sea opcional (NULL).
 * El índice unique sigue aplicando a valores no nulos.
 */
class SetNullableSlugToClientsTable extends Migration
{
    /**
     * Aplica nullable en slug de clients.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'slug')) {
                $table->string('slug', 80)->nullable()->change();
            }
        });
    }

    /**
     * Revierte slug a NOT NULL (solo si no hay filas con NULL).
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'slug')) {
                $table->string('slug', 80)->nullable(false)->change();
            }
        });
    }
}

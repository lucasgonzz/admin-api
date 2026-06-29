<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha programada de la actualización (agrupación en timeline admin-spa).
 */
class AddScheduledDateToClientVersionUpgrades extends Migration
{
    /**
     * Agrega scheduled_date después de notes.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            // Fecha en la que se planifica ejecutar o mostrar la actualización.
            $table->date('scheduled_date')->nullable()->after('notes');
        });
    }

    /**
     * Revierte scheduled_date.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->dropColumn('scheduled_date');
        });
    }
}

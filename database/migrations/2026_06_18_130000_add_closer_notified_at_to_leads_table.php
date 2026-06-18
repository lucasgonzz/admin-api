<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timestamp de cuándo se notificó automáticamente al closer que el lead
 * está listo (demo confirmada). Distinto de closer_called_at, que registra
 * cuándo el closer efectivamente hizo la llamada.
 */
class AddCloserNotifiedAtToLeadsTable extends Migration
{
    /**
     * Agrega la columna closer_notified_at nullable después de closer_called_at.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Marca el momento del aviso automático al closer (anti-duplicado).
            $table->timestamp('closer_notified_at')->nullable()->after('closer_called_at');
        });
    }

    /**
     * Revierte la columna closer_notified_at de leads.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('closer_notified_at');
        });
    }
}

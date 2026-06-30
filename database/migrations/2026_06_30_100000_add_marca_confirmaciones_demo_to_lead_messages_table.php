<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega flags de confirmación de demo al mensaje saliente de Claude.
 *
 * Permite mostrar badges en admin-spa cuando, en ese mensaje concreto, el agente
 * confirmó por primera vez el ingreso del lead a la demo o el fin de la demo.
 */
class AddMarcaConfirmacionesDemoToLeadMessagesTable extends Migration
{
    /**
     * Agrega columnas boolean de marca de confirmación después de is_status_event.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            /* True si en este mensaje el agente confirmó por primera vez el ingreso a la demo. */
            $table->boolean('marca_demo_ingreso_confirmado')->default(false)->after('is_status_event');
            /* True si en este mensaje el agente confirmó por primera vez el fin de la demo. */
            $table->boolean('marca_demo_terminada_confirmada')->default(false)->after('marca_demo_ingreso_confirmado');
        });
    }

    /**
     * Revierte las columnas de marca de confirmación de demo.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_messages', function (Blueprint $table) {
            $table->dropColumn([
                'marca_demo_ingreso_confirmado',
                'marca_demo_terminada_confirmada',
            ]);
        });
    }
}

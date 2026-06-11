<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el timestamp closer_called_at a la tabla leads.
 *
 * Registra el momento en que el closer realizó la llamada post-demo al lead,
 * permitiendo trazar la etapa final del pipeline comercial en el panel de operaciones.
 */
class AddCloserCalledAtToLeadsTable extends Migration
{
    /**
     * Agrega la columna closer_called_at nullable después de demo_summary.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            /* Timestamp cuando el closer realizó la llamada post-demo al lead. */
            $table->timestamp('closer_called_at')->nullable()->after('demo_summary');
        });
    }

    /**
     * Elimina la columna closer_called_at.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('closer_called_at');
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag para alertar en la tabla de leads cuando hay sugerencia de seguimiento IA no vista.
 *
 * Sin FK: se actualiza desde {@see \App\Services\LeadAiService} y al marcar visto en admin-spa.
 */
class AddTieneSeguimientoSinVerToLeadsTable extends Migration
{
    /**
     * Agrega columna indexada en `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('tiene_seguimiento_sin_ver')->default(false)->after('requiere_seguimiento')->index();
        });
    }

    /**
     * Elimina la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('tiene_seguimiento_sin_ver');
        });
    }
}

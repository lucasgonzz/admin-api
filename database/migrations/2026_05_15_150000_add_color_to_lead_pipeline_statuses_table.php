<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Color de badge para cada estado del pipeline en admin-spa (hex de fondo).
 */
class AddColorToLeadPipelineStatusesTable extends Migration
{
    /**
     * Agrega columna `color` a `lead_pipeline_statuses`.
     */
    public function up()
    {
        Schema::table('lead_pipeline_statuses', function (Blueprint $table) {
            $table->string('color', 32)->nullable()->after('label');
        });
    }

    /**
     * Elimina columna `color` de `lead_pipeline_statuses`.
     */
    public function down()
    {
        Schema::table('lead_pipeline_statuses', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
}

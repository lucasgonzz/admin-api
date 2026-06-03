<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de estados del pipeline comercial de leads (slug + etiqueta).
 *
 * Sin FK declarativa: los leads referencian el slug en `leads.status`.
 */
class CreateLeadPipelineStatusesTable extends Migration
{
    /**
     * Crea la tabla `lead_pipeline_statuses`.
     */
    public function up()
    {
        Schema::create('lead_pipeline_statuses', function (Blueprint $table) {
            $table->id();
            // Identificador usado en leads.status y sugerencias de Claude.
            $table->string('slug', 64)->unique();
            // Etiqueta legible en admin-spa (select, badges).
            $table->string('label', 120);
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla `lead_pipeline_statuses`.
     */
    public function down()
    {
        Schema::dropIfExists('lead_pipeline_statuses');
    }
}

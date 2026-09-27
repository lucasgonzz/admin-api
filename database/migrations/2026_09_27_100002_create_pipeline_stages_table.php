<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapas de un pipeline del CRM (misión pipelines-crm, 27/9/2026).
 *
 * `type` es `open` | `won` | `lost` y es LA fuente del estado de cada oportunidad: la oportunidad
 * no guarda si está abierta o cerrada, lo deduce de la etapa en la que está. Por eso el `type` de
 * una etapa no se puede cambiar mientras tenga oportunidades adentro (lo frena la API con 422).
 *
 * `fields` es la definición de los campos que la etapa pide al entrar
 * (`[{key, label, type, required, agenda, options}]`), editable desde la SPA sin tocar código. La
 * valida y normaliza `PipelineFieldsService`; `[]` si la etapa no pide nada.
 *
 * Sin foreign keys: la relación con `pipelines` vive en Eloquent.
 */
class CreatePipelineStagesTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Pipeline dueño de la etapa.
            $table->unsignedBigInteger('pipeline_id')->index();

            // Nombre visible ("Reunión agendada").
            $table->string('name', 120);

            // Color de la columna del tablero, hex #rrggbb.
            $table->string('color', 20)->default('#adb5bd');

            // open | won | lost.
            $table->string('type', 10)->default('open');

            // Orden de la columna en el tablero y en el embudo.
            $table->unsignedInteger('sort_order')->default(0);

            // Definición de los campos que se piden al entrar a la etapa.
            $table->json('fields')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pipeline_stages');
    }
}

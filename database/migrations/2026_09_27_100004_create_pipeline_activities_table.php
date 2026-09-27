<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de cada oportunidad del CRM (misión pipelines-crm, 27/9/2026): alta, cambios de etapa,
 * notas sueltas, próxima acción y responsable, con quién y cuándo.
 *
 * 🔴 EL HISTORIAL GUARDA FOTOS, NO REFERENCIAS VIVAS. `from_stage_name` / `to_stage_name` y la
 * lista `[{key, label, type, value}]` de `data` se copian en el momento: si mañana se renombra una
 * etapa o se borra un campo de su definición, lo que se cargó se sigue leyendo igual. Los
 * `*_stage_id` quedan para contar (el embudo), no para mostrar.
 *
 * `pipeline_id` va repetido acá (se deduce de la oportunidad) para que el embudo cuente "cuántas
 * pasaron por cada etapa" con una sola consulta agrupada sobre esta tabla, sin join.
 *
 * `occurred_at` es cuándo pasó (una nota puede cargarse después de la llamada), distinto de
 * `created_at`, que es cuándo se registró.
 */
class CreatePipelineActivitiesTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pipeline_activities', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Oportunidad y pipeline (repetido para el embudo sin join).
            $table->unsignedBigInteger('opportunity_id')->index();
            $table->unsignedBigInteger('pipeline_id')->index();

            // Quién. Nulo si no hubo admin (seeder, proceso).
            $table->unsignedBigInteger('admin_id')->nullable();

            // created | stage_change | note | next_action | owner.
            $table->string('type', 20);

            // Etapa de origen y destino: id para contar, nombre como foto.
            $table->unsignedBigInteger('from_stage_id')->nullable();
            $table->string('from_stage_name', 120)->nullable();
            $table->unsignedBigInteger('to_stage_id')->nullable()->index();
            $table->string('to_stage_name', 120)->nullable();

            // La nota escrita por el admin.
            $table->text('body')->nullable();

            // Solo en notas: call | whatsapp | meeting | email | in_person | other.
            $table->string('channel', 20)->nullable();

            // Foto de los campos cargados, o {from, to} legible en next_action / owner.
            $table->json('data')->nullable();

            // Cuándo pasó (en una nota puede ser anterior a ahora).
            $table->dateTime('occurred_at');

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
        Schema::dropIfExists('pipeline_activities');
    }
}

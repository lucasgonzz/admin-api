<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipelines del CRM del admin (misión pipelines-crm, 27/9/2026): la "categoría" de una campaña de
 * contactos. El primero es "Agentes" (ofrecerles el sistema de agentes a los clientes activos),
 * pero el módulo es abstracto a propósito para reusarlo en otras campañas.
 *
 * 🔴 NO tiene nada que ver con el pipeline de leads (`lead_pipeline_statuses` / `leads.status`),
 * que sigue exactamente como estaba: acá un lead puede ser el SUJETO de una oportunidad, pero su
 * estado comercial no se toca.
 *
 * `slug` existe solo para que `PipelineAgentesSeeder` sea idempotente (`agentes`): la SPA no lo
 * muestra ni lo edita. `lost_reasons` es la lista editable de motivos de pérdida del pipeline
 * (json de strings, `[]` si no hay). `archived_at` saca al pipeline del selector sin borrar nada.
 *
 * Sin foreign keys, como el resto de las migraciones nuevas del repo: las relaciones viven solo en
 * Eloquent.
 */
class CreatePipelinesTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Nombre visible del pipeline ("Agentes").
            $table->string('name', 120);

            // Identificador fijo para el seeder idempotente. No se edita desde la SPA.
            $table->string('slug', 60)->nullable()->index();

            // Para qué es la campaña.
            $table->text('description')->nullable();

            // Motivos de pérdida ofrecidos al mover a una etapa `lost` (lista de strings).
            $table->json('lost_reasons')->nullable();

            // Orden en el selector de pipelines.
            $table->unsignedInteger('sort_order')->default(0);

            // Archivado = fuera del selector salvo "ver archivados". Nulo = activo.
            $table->timestamp('archived_at')->nullable();

            // Quién lo creó (nulo si lo creó el seeder).
            $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();

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
        Schema::dropIfExists('pipelines');
    }
}

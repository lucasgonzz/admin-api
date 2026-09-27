<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oportunidades del CRM (misión pipelines-crm, 27/9/2026): un sujeto (cliente o lead) parado en
 * una etapa de un pipeline.
 *
 * 🔴 El sujeto son DOS columnas nullable, `client_id` y `lead_id`, con exactamente una con valor.
 * No es un `morphTo`: admin-api no tiene morph map y agregar `Client` a uno le cambiaría el
 * `getMorphClass()` a todo el sistema (lo usa el `morphToMany` de `RestrictsToClients` sobre
 * `version_item_clients`). Con dos columnas el eager loading anda sin trucos y "las oportunidades
 * del cliente X" es un `where`.
 *
 * 🔴 No hay columna de estado: abierta / ganada / perdida sale del `type` de la etapa. `closed_at`
 * sí se guarda porque es un HECHO (cuándo se cerró), no un estado.
 *
 * "Una sola oportunidad abierta por sujeto y pipeline" NO es un índice: MySQL no tiene índices
 * únicos parciales y "abierta" depende de la etapa. Lo garantiza `PipelineOpportunityService`
 * dentro de una transacción con `lockForUpdate()` sobre la fila del pipeline.
 *
 * Las fechas son hora local (America/Argentina/Buenos_Aires), `datetime` y no `timestamp`: son
 * fechas de negocio que la SPA manda y lee como `Y-m-d H:i`, no instantes del servidor.
 */
class CreatePipelineOpportunitiesTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pipeline_opportunities', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Pipeline y etapa actual.
            $table->unsignedBigInteger('pipeline_id')->index();
            $table->unsignedBigInteger('stage_id')->index();

            // El sujeto: exactamente uno de los dos tiene valor.
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();

            // Responsable (admin). Nulo = sin responsable.
            $table->unsignedBigInteger('owner_admin_id')->nullable()->index();

            // Próxima acción: fecha (hora local; sin hora = 00:00:00) y nota.
            $table->dateTime('next_action_at')->nullable()->index();
            $table->string('next_action_note', 255)->nullable();

            // Cuándo entró a la etapa actual (para "días en la etapa").
            $table->dateTime('stage_entered_at')->nullable();

            // Cuándo se cerró (ganada o perdida). Se limpia al reabrir.
            $table->dateTime('closed_at')->nullable();

            // Motivo de pérdida. Solo con valor mientras esté en una etapa `lost`.
            $table->string('lost_reason', 255)->nullable();

            // Quién la dio de alta.
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

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
        Schema::dropIfExists('pipeline_opportunities');
    }
}

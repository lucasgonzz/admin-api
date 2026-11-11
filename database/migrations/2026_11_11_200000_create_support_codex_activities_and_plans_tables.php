<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La auditoría de Martín (misión cruzada `auditoria-de-martin`, 11/11/2026): lo que el agente de soporte hizo con las
 * herramientas del monitor en cada ticket o seguimiento (leyó el log, consultó la base, escribió la ficha, respondió) y
 * el plan que fue siguiendo en cada atención. Lo registra el monitor (`soporte.py`) al ejecutar cada comando, sin
 * depender de que Martín lo declare; el panel lo muestra en el encabezado de la conversación y en la tarjeta
 * «Recorrido de Martín».
 *
 * - `support_codex_activities`: una fila = una acción. `dedupe_hash` (sha256 de run_id|command|occurred_at|summary) es
 *   lo que hace idempotente la ingesta: el monitor reintenta lo que no pudo mandar y no se duplica.
 * - `kind`: `escritura` es SOLO lo que se escribe en el sistema del cliente; lo interno del admin (ficha, título, notas,
 *   pasos, misiones, pausas, tour) va como `registro`.
 * - `support_codex_plans`: un plan por ejecución (`run_id` único), que el monitor reemplaza entero (upsert).
 *
 * Aditiva y reversible: dos tablas nuevas, nada existente se toca.
 */
class CreateSupportCodexActivitiesAndPlansTables extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('support_codex_activities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->unsignedBigInteger('implementation_followup_id')->nullable();
            /* Derivado del ticket o del seguimiento al guardar, nunca del pedido. */
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('run_id', 64);
            /* lectura | escritura (sistema del cliente) | registro (interno del admin) | envio | consulta | plan | error */
            $table->string('kind', 20);
            /* El subcomando del monitor: sql, logs, diagnostico, ficha, responder, paso, mision... */
            $table->string('command', 40);
            $table->string('summary', 300);
            /* Chico (≤ 2 KB): {filas, truncado}, {lineas}, {estado}... Nunca datos crudos. */
            $table->json('detail')->nullable();
            $table->boolean('ok');
            $table->dateTime('occurred_at');
            $table->char('dedupe_hash', 64);
            $table->timestamps();

            /* Los dos contadores de la bandeja, sin leer la tabla: qué run_id tocaron el ticket (sca_ticket_run_idx) y
               cuántas acciones y escrituras tiene cada uno (sca_run_kind_ok_idx). También los usa el panel. */
            $table->index(['support_ticket_id', 'run_id'], 'sca_ticket_run_idx');
            $table->index(['run_id', 'kind', 'ok'], 'sca_run_kind_ok_idx');
            $table->index('implementation_followup_id', 'sca_followup_idx');
            $table->index('client_id', 'sca_client_idx');
            $table->unique('dedupe_hash', 'sca_dedupe_unique');
        });

        Schema::create('support_codex_plans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('support_ticket_id')->nullable();
            $table->unsignedBigInteger('implementation_followup_id')->nullable();
            $table->string('run_id', 64);
            $table->string('objetivo', 300)->nullable();
            /* [{texto ≤ 200, estado: pendiente|en_curso|hecho|descartado}], ≤ 15 */
            $table->json('pasos');
            $table->timestamps();

            $table->unique('run_id', 'scp_run_unique');
            $table->index('support_ticket_id', 'scp_ticket_idx');
            $table->index('implementation_followup_id', 'scp_followup_idx');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('support_codex_plans');
        Schema::dropIfExists('support_codex_activities');
    }
}

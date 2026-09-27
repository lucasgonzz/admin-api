<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde salió la próxima acción de una oportunidad del CRM (misión pipelines-crm, ronda de
 * arreglos R1, 27/9/2026): `agenda` | `manual` | null.
 *
 * 🔴 El defecto que la motiva: "Reunión agendada" fija la próxima acción con la fecha de la reunión
 * (su campo agenda). Al pasar a "Reunión hecha", que no tiene campo agenda, esa fecha se conservaba,
 * y al día siguiente la oportunidad figuraba VENCIDA en el tablero, en los chips y en la agenda —
 * justo la señal de "a quién hay que contactar". Para decidir qué sobrevive a un movimiento hace
 * falta saber de dónde vino la fecha: una manual y futura se conserva; una de la agenda de la etapa
 * que se deja, o una vencida, no (la regla vive en `PipelineOpportunityService::mover()`).
 *
 * Migración NUEVA y no una edición de `2026_09_27_100003`, que ya corrió en las bases de los
 * slots y en la de verificación: una create editada no se vuelve a correr y la columna faltaría.
 */
class AddNextActionSourceToPipelineOpportunitiesTable extends Migration
{
    /**
     * Agrega la columna.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pipeline_opportunities', function (Blueprint $table) {
            // agenda (la fijó el campo agenda de una etapa) | manual (la cargó una persona) | null.
            $table->string('next_action_source', 10)->nullable()->after('next_action_note');
        });
    }

    /**
     * Saca la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pipeline_opportunities', function (Blueprint $table) {
            $table->dropColumn('next_action_source');
        });
    }
}

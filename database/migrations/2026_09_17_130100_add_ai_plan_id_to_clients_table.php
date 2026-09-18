<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El paquete de IA asignado a cada cliente (misión foto-sucursal-y-asistente-configurable,
 * 17/9/2026).
 *
 * Es una FK lógica a `ai_plans`, sin clave foránea física: mismo criterio que el resto de las
 * relaciones de `clients` de este proyecto. NULL = el cliente todavía no tiene paquete asignado, que
 * es el estado por defecto de todo el parque y significa "sin tope" (no corta).
 *
 * Guard `hasColumn` porque corre sobre producción y sobre las bases de testing de los slots.
 */
class AddAiPlanIdToClientsTable extends Migration
{
    /**
     * Agrega la columna ai_plan_id.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'ai_plan_id')) {
                $table->unsignedBigInteger('ai_plan_id')->nullable()->after('ai_tokens_sync_message');
            }
        });
    }

    /**
     * Revierte quitando la columna.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'ai_plan_id')) {
                $table->dropColumn('ai_plan_id');
            }
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del último push del paquete de IA al `empresa-api` del cliente (misión foto-sucursal-y-
 * asistente-configurable, 17/9/2026).
 *
 * Calcadas de `ai_tokens_sync_*` (migración 2026_09_17_120100) y por el mismo motivo: el PUT al
 * cliente puede terminar de varias maneras y todas son INFORMACIÓN, no errores del admin. Un cliente
 * que todavía no tiene la ruta `admin-sync/plan-ia` (404) no es un fallo: es lo esperado durante las
 * semanas en que el parque se actualiza, y se distingue de un error real por el estado.
 *
 * - `ai_plan_synced_at`    → momento del último push EXITOSO. Un fallo posterior no lo pisa.
 * - `ai_plan_sync_status`  → success · no_soportado · failed.
 * - `ai_plan_sync_message` → el motivo, cuando no es success.
 *
 * NULL en las tres = nunca se intentó, que NO es lo mismo que un fallo. Sin índices: no se filtra por
 * ellas en ningún camino caliente.
 *
 * Guard `hasColumn` porque corre sobre producción y sobre las bases de testing de los slots.
 */
class AddAiPlanSyncFieldsToClientsTable extends Migration
{
    /**
     * Agrega las tres columnas de estado del push del plan.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'ai_plan_synced_at')) {
                $table->dateTime('ai_plan_synced_at')->nullable()->after('ai_plan_id');
            }

            if (! Schema::hasColumn('clients', 'ai_plan_sync_status')) {
                $table->string('ai_plan_sync_status', 20)->nullable()->after('ai_plan_synced_at');
            }

            if (! Schema::hasColumn('clients', 'ai_plan_sync_message')) {
                $table->text('ai_plan_sync_message')->nullable()->after('ai_plan_sync_status');
            }
        });
    }

    /**
     * Revierte quitando las tres columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $columnas = [];

            foreach (['ai_plan_synced_at', 'ai_plan_sync_status', 'ai_plan_sync_message'] as $columna) {
                if (Schema::hasColumn('clients', $columna)) {
                    $columnas[] = $columna;
                }
            }

            if ($columnas !== []) {
                $table->dropColumn($columnas);
            }
        });
    }
}

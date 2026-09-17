<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de la última recolección del consumo de IA desde el `empresa-api` del cliente.
 *
 * Calcadas de `schedule_sync_*` (migración 2026_08_24_160300) y por el mismo motivo: la llamada al
 * cliente puede terminar de varias maneras y todas son INFORMACIÓN, no errores del admin. Sin esto,
 * un cliente que todavía no tiene la versión con el endpoint se ve igual que un cliente que no
 * gastó nada — que es exactamente el dato que la pestaña tiene que poder distinguir.
 *
 * - `ai_tokens_synced_at`     → momento de la última recolección EXITOSA. Un fallo posterior no la
 *                               pisa: sigue diciendo hasta cuándo el dato es confiable.
 * - `ai_tokens_sync_status`   → success · no_soportado · failed.
 * - `ai_tokens_sync_message`  → el motivo, cuando no es success.
 *
 * NULL en las tres = nunca se intentó, que NO es lo mismo que un fallo. Sin índices a propósito: no
 * se filtra por ellas en ningún camino caliente, y un índice que nadie usa es peso muerto en cada
 * escritura.
 *
 * Guard `hasColumn` en cada una: esta migración corre sobre la base de producción del admin y sobre
 * cinco bases de testing de slots que pueden estar en estados distintos.
 */
class AddAiTokensSyncFieldsToClientsTable extends Migration
{
    /**
     * Agrega las tres columnas de estado de la recolección de tokens.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Última recolección exitosa. Null = nunca trajo nada con éxito.
            if (! Schema::hasColumn('clients', 'ai_tokens_synced_at')) {
                $table->dateTime('ai_tokens_synced_at')->nullable()->after('schedule_sync_message');
            }

            /*
             * success       → el cliente contestó y las filas quedaron guardadas.
             * no_soportado  → 404: su versión de empresa-api todavía no tiene el endpoint. Es lo
             *                 esperado durante semanas y NO es un fallo: no se reintenta ni se avisa.
             * failed        → 401/403 (api_key), 5xx, timeout, o configuración faltante.
             */
            if (! Schema::hasColumn('clients', 'ai_tokens_sync_status')) {
                $table->string('ai_tokens_sync_status', 20)->nullable()->after('ai_tokens_synced_at');
            }

            // Motivo legible cuando el estado no es success.
            if (! Schema::hasColumn('clients', 'ai_tokens_sync_message')) {
                $table->text('ai_tokens_sync_message')->nullable()->after('ai_tokens_sync_status');
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

            foreach (['ai_tokens_synced_at', 'ai_tokens_sync_status', 'ai_tokens_sync_message'] as $columna) {
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del último push de los horarios comerciales al empresa-api del cliente.
 *
 * Calcadas de `client_version_upgrades.default_version_sync_status` (migración 2026_07_22_100000):
 * el push a la instancia del cliente puede terminar de cuatro maneras distintas y todas son
 * información, no errores del admin. Guardarlo permite que la pestaña "Horarios" muestre
 * "Sincronizado el …" o el motivo del fallo sin volver a pegarle a la API del cliente.
 *
 * - `schedule_synced_at`     → momento del último push EXITOSO. Un fallo posterior no lo pisa:
 *                              sigue diciendo cuándo fue la última vez que el cliente quedó al día.
 * - `schedule_sync_status`   → success · manual_required · skipped · failed.
 * - `schedule_sync_message`  → el motivo, cuando no es success.
 *
 * NULL en las tres = nunca se intentó. Sin índices a propósito: no se filtra por ellas en ningún
 * camino caliente, y un índice que nadie usa es peso muerto en cada escritura.
 */
class AddScheduleSyncFieldsToClientsTable extends Migration
{
    /**
     * Agrega las tres columnas de estado de sincronización.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Último push exitoso. Null = nunca sincronizó con éxito.
            $table->dateTime('schedule_synced_at')->nullable()->after('is_active');

            // success | manual_required | skipped | failed. Null = nunca se intentó.
            $table->string('schedule_sync_status', 20)->nullable()->after('schedule_synced_at');

            // Motivo legible cuando el estado no es success.
            $table->text('schedule_sync_message')->nullable()->after('schedule_sync_status');
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
            $table->dropColumn([
                'schedule_synced_at',
                'schedule_sync_status',
                'schedule_sync_message',
            ]);
        });
    }
}

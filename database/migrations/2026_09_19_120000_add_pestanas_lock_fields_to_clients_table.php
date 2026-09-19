<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candado de sesión por pestaña (misión candado-sesion-por-pestana, 19/9/2026).
 *
 * `bloquear_pestanas_duplicadas` es el interruptor que Lucas prende por cliente desde el admin:
 * endurece el candado de sesión de `empresa-api` para que ni dos pestañas del MISMO navegador
 * puedan tener la cuenta abierta a la vez (hoy conviven a propósito, por el fix
 * `candado-sesion-refresh` del 9/9/2026). Default `false`: apagado para todo el parque hasta que
 * alguien lo prenda a mano.
 *
 * Las otras tres columnas son el estado del último push de ese interruptor al empresa-api del
 * cliente, calcadas de `schedule_sync_*` (migración 2026_08_24_160300):
 *
 * - `pestanas_synced_at`    → momento del último push EXITOSO. Un fallo posterior no lo pisa.
 * - `pestanas_sync_status`  → success · manual_required · skipped · failed.
 * - `pestanas_sync_message` → el motivo, cuando no es success.
 *
 * NULL en las tres = nunca se intentó. Sin índices a propósito, mismo criterio que el trío de
 * horarios: no se filtra por ellas en ningún camino caliente.
 */
class AddPestanasLockFieldsToClientsTable extends Migration
{
    /**
     * Agrega el interruptor y las tres columnas de estado de sincronización.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Interruptor que Lucas prende por cliente. Default false: apagado para todo el parque.
            $table->boolean('bloquear_pestanas_duplicadas')->default(false)->after('is_active');

            // Último push exitoso. Null = nunca sincronizó con éxito.
            $table->dateTime('pestanas_synced_at')->nullable()->after('bloquear_pestanas_duplicadas');

            // success | manual_required | skipped | failed. Null = nunca se intentó.
            $table->string('pestanas_sync_status', 20)->nullable()->after('pestanas_synced_at');

            // Motivo legible cuando el estado no es success.
            $table->text('pestanas_sync_message')->nullable()->after('pestanas_sync_status');
        });
    }

    /**
     * Revierte quitando las cuatro columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'bloquear_pestanas_duplicadas',
                'pestanas_synced_at',
                'pestanas_sync_status',
                'pestanas_sync_message',
            ]);
        });
    }
}

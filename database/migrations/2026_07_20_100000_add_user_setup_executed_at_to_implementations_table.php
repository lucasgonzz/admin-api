<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna `user_setup_executed_at` a `implementations` (prompt 477).
 *
 * Registra el momento en que se aplicó con éxito la acción manual `user_setup` del panel
 * de implementación. Sirve de lock de UI: una vez seteada, el botón "Aplicar configuración"
 * queda bloqueado en el panel salvo que el admin use el override `force` (con confirmación
 * en el frontend). No reemplaza la idempotencia del lado de `empresa-api`, solo evita el
 * disparo accidental repetido desde admin-api.
 *
 * Las implementaciones ya existentes quedan en NULL: nunca corrieron el UserSetup por este
 * flujo con lock. Es el comportamiento correcto (ver descripción del prompt 477).
 */
class AddUserSetupExecutedAtToImplementationsTable extends Migration
{
    /**
     * Agrega la columna `user_setup_executed_at` a `implementations`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('implementations', function (Blueprint $table) {
            // Momento en que se aplicó el UserSetup con éxito. NULL = todavía no se corrió.
            // Sirve de lock: una vez seteado, el botón queda bloqueado salvo override explícito (force).
            $table->timestamp('user_setup_executed_at')->nullable()->after('automation_mode');
        });
    }

    /**
     * Revierte el agregado de la columna `user_setup_executed_at`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('implementations', function (Blueprint $table) {
            $table->dropColumn('user_setup_executed_at');
        });
    }
}

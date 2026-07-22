<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado puntual de la etapa update_default_version, independiente del deployment_status
 * general: permite distinguir "empresa confirmó el cambio" de "empresa no tiene el endpoint
 * todavía (versión vieja) — hace falta actualizarlo a mano ahí", sin introducir un valor nuevo
 * de deployment_status que afectaría los computed existentes del panel de admin-spa.
 */
class AddDefaultVersionSyncStatusToClientVersionUpgradesTable extends Migration
{
    public function up()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            // null (todavía no se intentó) | success | manual_required
            $table->string('default_version_sync_status')->nullable()->after('sistema_configurado_at');

            // Mensaje humano para mostrar en el panel (detalle del resultado de la sincronización).
            $table->text('default_version_sync_message')->nullable()->after('default_version_sync_status');
        });
    }

    public function down()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->dropColumn([
                'default_version_sync_status',
                'default_version_sync_message',
            ]);
        });
    }
}

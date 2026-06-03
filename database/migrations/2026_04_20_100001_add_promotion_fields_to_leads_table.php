<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a la tabla `leads` los campos necesarios para el flujo de promoción
 * Lead → Client y el tracking del user-setup remoto:
 *
 * - promoted_client_id: FK al Client creado automáticamente en la promoción.
 * - user_setup_status:  estado del setup real (pendiente / ejecutandose / exitoso / fallido).
 * - user_setup_last_error: mensaje del último error de setup.
 * - user_setup_last_run_at: timestamp de la última corrida del user-setup.
 */
class AddPromotionFieldsToLeadsTable extends Migration
{
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // FK al Client de producción generado al promover el Lead
            $table->unsignedBigInteger('promoted_client_id')
                  ->nullable()
                  ->after('target_client_id');

            // Trazabilidad del user-setup sobre el sistema real
            $table->string('user_setup_status', 20)
                  ->default('pendiente')
                  ->after('demo_setup_last_run_at');
            $table->text('user_setup_last_error')
                  ->nullable()
                  ->after('user_setup_status');
            $table->timestamp('user_setup_last_run_at')
                  ->nullable()
                  ->after('user_setup_last_error');

            // Si se borra el Client de producción, no perdemos el histórico del lead
            $table->foreign('promoted_client_id')
                  ->references('id')
                  ->on('clients')
                  ->onDelete('set null');

            $table->index('promoted_client_id');
        });
    }

    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['promoted_client_id']);
            $table->dropIndex(['promoted_client_id']);
            $table->dropColumn([
                'promoted_client_id',
                'user_setup_status',
                'user_setup_last_error',
                'user_setup_last_run_at',
            ]);
        });
    }
}

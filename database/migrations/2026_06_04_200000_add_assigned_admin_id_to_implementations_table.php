<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la columna assigned_admin_id a la tabla implementations.
 *
 * Permite registrar qué admin es responsable de guiar al cliente durante su implementación.
 * Se usa para personalizar los mensajes de WhatsApp y notificar al responsable al completar etapas.
 */
class AddAssignedAdminIdToImplementationsTable extends Migration
{
    /**
     * Agrega la columna assigned_admin_id con FK a admins.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('implementations', function (Blueprint $table) {
            // Admin responsable de la implementación; nullable porque puede no estar asignado.
            $table->unsignedBigInteger('assigned_admin_id')->nullable()->after('client_id');

            // FK referencial: si el admin se elimina, desvincular sin borrar la implementación.
            $table->foreign('assigned_admin_id')
                ->references('id')
                ->on('admins')
                ->onDelete('set null');
        });
    }

    /**
     * Elimina la columna y su FK.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('implementations', function (Blueprint $table) {
            $table->dropForeign(['assigned_admin_id']);
            $table->dropColumn('assigned_admin_id');
        });
    }
}

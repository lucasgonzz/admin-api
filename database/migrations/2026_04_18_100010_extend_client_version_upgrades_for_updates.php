<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExtendClientVersionUpgradesForUpdates extends Migration
{
    public function up()
    {
        // Paso 1: Ampliar el enum para que acepte tanto valores viejos como nuevos.
        DB::statement("ALTER TABLE client_version_upgrades MODIFY status ENUM('pending','success','failed','pendiente','listo_para_actualizar','actualizandose','terminada','fallida') NOT NULL DEFAULT 'pendiente'");

        // Paso 2: Migrar datos existentes al nuevo vocabulario.
        DB::statement("UPDATE client_version_upgrades SET status = 'terminada' WHERE status = 'success'");
        DB::statement("UPDATE client_version_upgrades SET status = 'fallida'   WHERE status = 'failed'");
        DB::statement("UPDATE client_version_upgrades SET status = 'pendiente' WHERE status = 'pending'");

        // Paso 3: Reducir el enum a solo los valores nuevos.
        DB::statement("ALTER TABLE client_version_upgrades MODIFY status ENUM('pendiente','listo_para_actualizar','actualizandose','terminada','fallida') NOT NULL DEFAULT 'pendiente'");

        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('synced_at');
            $table->timestamp('finished_at')->nullable()->after('started_at');
            $table->timestamp('sistema_actualizado_at')->nullable()->after('finished_at');
            $table->timestamp('migraciones_corridas_at')->nullable()->after('sistema_actualizado_at');
            $table->timestamp('crons_supervisor_at')->nullable()->after('migraciones_corridas_at');
            $table->timestamp('seeders_ejecutados_at')->nullable()->after('crons_supervisor_at');
            $table->timestamp('comandos_ejecutados_at')->nullable()->after('seeders_ejecutados_at');
            $table->timestamp('sistema_configurado_at')->nullable()->after('comandos_ejecutados_at');
        });
    }

    public function down()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->dropColumn([
                'started_at',
                'finished_at',
                'sistema_actualizado_at',
                'migraciones_corridas_at',
                'crons_supervisor_at',
                'seeders_ejecutados_at',
                'comandos_ejecutados_at',
                'sistema_configurado_at',
            ]);
        });

        // Ampliar enum para aceptar valores de reversión.
        DB::statement("ALTER TABLE client_version_upgrades MODIFY status ENUM('pending','success','failed','pendiente','listo_para_actualizar','actualizandose','terminada','fallida') NOT NULL DEFAULT 'pending'");

        // Revertir datos.
        DB::statement("UPDATE client_version_upgrades SET status = 'success' WHERE status = 'terminada'");
        DB::statement("UPDATE client_version_upgrades SET status = 'failed'  WHERE status = 'fallida'");
        DB::statement("UPDATE client_version_upgrades SET status = 'pending' WHERE status IN ('pendiente','listo_para_actualizar','actualizandose')");

        // Reducir al enum original.
        DB::statement("ALTER TABLE client_version_upgrades MODIFY status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending'");
    }
}

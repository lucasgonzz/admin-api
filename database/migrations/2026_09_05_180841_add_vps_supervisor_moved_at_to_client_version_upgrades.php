<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gate de VPS para el post-cierre de un upgrade, análogo a `crons_supervisor_at` pero para
 * clientes en VPS: ahí no hay panel de Hostinger, lo que hay que confirmar es que el worker
 * de supervisor ya se mudó al frente (`<slug>` / `<slug>2`) que va a quedar activo.
 *
 * Hasta esta migración, un cliente en VPS pasaba el post-cierre marcando `crons_supervisor_at`
 * con un texto que no aplica ("mover los crons y el supervisor en el panel de Hostinger"), sin
 * que nada verificara que el supervisor realmente se movió. Es la causa confirmada de que
 * `ananda`, `ferretotal` (dos veces) y `san-cayetano` quedaran con el worker en el frente
 * muerto después de una actualización.
 */
class AddVpsSupervisorMovedAtToClientVersionUpgrades extends Migration
{
    public function up()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->timestamp('vps_supervisor_moved_at')->nullable()->after('crons_supervisor_at');
        });
    }

    public function down()
    {
        Schema::table('client_version_upgrades', function (Blueprint $table) {
            $table->dropColumn('vps_supervisor_moved_at');
        });
    }
}

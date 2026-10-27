<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddContractPlanGestionToContratos extends Migration
{
    public function up()
    {
        foreach (['leads', 'clients'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) {
                // Nullable: los contratos anteriores al 26/10/2026 no tienen plan y conservan su importe.
                $table->string('contract_plan_gestion', 20)->nullable();
            });
        }
    }

    public function down()
    {
        foreach (['leads', 'clients'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) {
                $table->dropColumn('contract_plan_gestion');
            });
        }
    }
}

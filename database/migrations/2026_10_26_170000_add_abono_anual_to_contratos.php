<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAbonoAnualToContratos extends Migration
{
    public function up()
    {
        foreach (['leads', 'clients'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) {
                $table->boolean('contract_pago_anual')->nullable();
                $table->decimal('contract_descuento_anual', 5, 2)->nullable();
            });
        }
    }

    public function down()
    {
        foreach (['leads', 'clients'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) {
                $table->dropColumn(['contract_pago_anual', 'contract_descuento_anual']);
            });
        }
    }
}

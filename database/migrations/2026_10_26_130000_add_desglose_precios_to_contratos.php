<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDesglosePreciosToContratos extends Migration
{
    public function up()
    {
        foreach (['leads', 'clients'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) {
                // Nullable distingue lo firmado antes del nuevo esquema comercial.
                $table->json('contract_precios_sistemas')->nullable();
                $table->decimal('contract_descuento_conjunto', 12, 2)->nullable();
                $table->boolean('contract_mes_gratis')->nullable();
            });
        }
    }

    public function down()
    {
        foreach (['leads', 'clients'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) {
                $table->dropColumn(['contract_precios_sistemas', 'contract_descuento_conjunto', 'contract_mes_gratis']);
            });
        }
    }
}

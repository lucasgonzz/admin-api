<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Misión embudo-whatsapp-precios, etapa E4: la FOTO del plan de gestión en el contrato.
 *
 * El contrato guardaba sólo la clave del plan (`contract_plan_gestion`); si el rango de una clave cambia en la
 * fuente de precios, el PDF de un contrato firmado cambiaría. Acá se guarda, como JSON, el plan tal como
 * estaba cuando se eligió: `{clave, nombre, desde, hasta, usuarios, excedente, abono_lista, politica}`.
 * Sin foreign keys y nullable: los contratos anteriores no tienen foto y siguen como estaban.
 */
class AddContractPlanGestionFotoToContratos extends Migration
{
    public function up()
    {
        foreach (['leads', 'clients'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) {
                // TEXT con JSON (cast array en el modelo); null = contrato sin foto.
                $table->text('contract_plan_gestion_foto')->nullable();
            });
        }
    }

    public function down()
    {
        foreach (['leads', 'clients'] as $nombre) {
            Schema::table($nombre, function (Blueprint $table) {
                $table->dropColumn('contract_plan_gestion_foto');
            });
        }
    }
}

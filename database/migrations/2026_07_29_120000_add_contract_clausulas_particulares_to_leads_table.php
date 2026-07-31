<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columna `contract_clausulas_particulares` a `leads`.
 *
 * Almacena cláusulas particulares del contrato como JSON: array de objetos [{titulo, texto}].
 * Estos campos se cargan desde la pestaña de contrato del admin y se incluyen en el PDF final.
 */
class AddContractClausulasParticularesToLeadsTable extends Migration
{
    /**
     * Agrega columna de cláusulas particulares a `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Cláusulas particulares del contrato: array de objetos [{titulo, texto}]
            // Nullable, se carga opcionalmente desde la pestaña de contrato del admin.
            $table->json('contract_clausulas_particulares')->nullable()->after('contract_fecha_primer_pago_mensual');
        });
    }

    /**
     * Elimina la columna de cláusulas particulares de `leads`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('contract_clausulas_particulares');
        });
    }
}

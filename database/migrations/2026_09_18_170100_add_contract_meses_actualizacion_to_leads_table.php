<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada cuántos meses se actualiza la mensualidad por IPC, en el contrato del LEAD (misión
 * modulo-cobranzas, 18/9/2026).
 *
 * El contrato PDF decía desde siempre "cada seis (6) meses" con texto fijo. Ahora el número es un
 * dato del contrato: se carga en la pestaña Contrato del lead, viaja al PDF en letras y cifra, y
 * al promover el lead se copia al cliente (`clients.contract_meses_actualizacion`), donde el
 * módulo de Cobranzas lo usa para calcular cuándo vence la próxima actualización oficial.
 *
 * Default 6 y nullable: un lead viejo sin el dato sigue generando el mismo contrato de siempre.
 */
class AddContractMesesActualizacionToLeadsTable extends Migration
{
    /**
     * Agrega la columna si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('leads', 'contract_meses_actualizacion')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            // Meses entre actualizaciones de precio por IPC. 6 = el texto histórico del contrato.
            $table->unsignedTinyInteger('contract_meses_actualizacion')
                ->nullable()
                ->default(6)
                ->after('contract_clausulas_particulares');
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('leads', 'contract_meses_actualizacion')) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('contract_meses_actualizacion');
        });
    }
}

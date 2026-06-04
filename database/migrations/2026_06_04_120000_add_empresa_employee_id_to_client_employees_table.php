<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula un ClientEmployee de admin con el User (empleado) del empresa-api del cliente.
 */
class AddEmpresaEmployeeIdToClientEmployeesTable extends Migration
{
    /**
     * Agrega empresa_employee_id para sincronización desde empresa-api.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_employees', function (Blueprint $table) {
            // Id del User empleado en empresa-api; null si el contacto se creó solo en admin.
            $table->unsignedBigInteger('empresa_employee_id')->nullable()->index();
        });
    }

    /**
     * Quita la columna de vínculo con empresa-api.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_employees', function (Blueprint $table) {
            $table->dropColumn('empresa_employee_id');
        });
    }
}

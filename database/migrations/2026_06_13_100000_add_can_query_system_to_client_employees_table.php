<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega can_query_system a client_employees.
 *
 * Habilita a un empleado de cliente a usar el canal "sistema:" de WhatsApp (consultar
 * stock, ventas, facturas y clientes). El permiso lo activa Martín desde el admin:
 * no es auto-activable por el cliente. El dueño (sin client_employee) siempre puede.
 */
class AddCanQuerySystemToClientEmployeesTable extends Migration
{
    /**
     * Agrega la columna can_query_system.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('client_employees', function (Blueprint $table) {
            // Permiso para consultar el sistema por WhatsApp (canal "sistema:").
            $table->boolean('can_query_system')->default(false)->after('phone');
        });
    }

    /**
     * Elimina la columna can_query_system.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_employees', function (Blueprint $table) {
            $table->dropColumn('can_query_system');
        });
    }
}

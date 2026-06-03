<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula tickets de soporte con el empleado del cliente que escribe por WhatsApp.
 */
class AddClientEmployeeIdToSupportTicketsTable extends Migration
{
    /**
     * Agrega client_employee_id a support_tickets.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // Empleado del cliente cuando el ticket proviene de su número (null = dueño/contacto principal).
            $table->unsignedBigInteger('client_employee_id')->nullable()->after('client_id')->index();
        });
    }

    /**
     * Revierte la columna client_employee_id.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('client_employee_id');
        });
    }
}

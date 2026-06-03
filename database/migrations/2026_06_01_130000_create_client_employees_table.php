<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empleados de contacto de un cliente (WhatsApp / soporte).
 */
class CreateClientEmployeesTable extends Migration
{
    /**
     * Crea la tabla client_employees.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('client_employees', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Identificador público para rutas y UI.
            $table->uuid('uuid');
            // Cliente al que pertenece el empleado.
            $table->unsignedBigInteger('client_id')->index();
            // Nombre visible del contacto en soporte.
            $table->string('name');
            // Teléfono WhatsApp (formato libre; se normaliza al comparar).
            $table->string('phone')->index();
            // Notas internas opcionales para operadores.
            $table->text('notes')->nullable();
            // Enlace temporal al crear Client padre con hijos has_many antes de persistir.
            $table->string('temporal_id')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla client_employees.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_employees');
    }
}

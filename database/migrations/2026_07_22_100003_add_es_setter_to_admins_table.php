<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el flag es_setter al modelo Admin.
 *
 * es_setter: identifica a los admins que actúan como "setters" (encargados
 * de avanzar conversaciones de leads). Las tareas que se generan
 * automáticamente a partir de mensajes de leads se asignan a todos los
 * admins que tengan este flag activo.
 *
 * Distinto de is_default_task_assignee: ese flag preselecciona admins
 * cuando Lucas crea una tarea manualmente desde el panel. es_setter es
 * exclusivamente para la asignación automática de tareas originadas por
 * conversaciones de leads. Un mismo admin puede tener ninguno, uno o
 * ambos flags activos.
 */
class AddEsSetterToAdminsTable extends Migration
{
    /**
     * Agrega la columna boolean es_setter después de is_closer.
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('es_setter')->default(false)->after('is_closer');
        });
    }

    /**
     * Elimina la columna es_setter.
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('es_setter');
        });
    }
}

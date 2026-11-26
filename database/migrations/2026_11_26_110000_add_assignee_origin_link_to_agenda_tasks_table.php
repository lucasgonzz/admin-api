<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Responsable, origen del pedido y vínculo de las tareas de la Agenda (misión agenda-openclaw-empuje, 25/11/2026).
 *
 * La Agenda pasa a ser la lista única de tareas de Lucas y OpenClaw la empuja. Para eso cada tarea suma:
 *  - `assignee`: quién la hace. `owner` (el dueño de esa agenda; en la de Lucas, Lucas) u `openclaw`.
 *    Se llama `owner` y no `lucas` porque la Agenda es de cada admin.
 *  - `origin`: de dónde vino el pedido. `panel`, `dots`, `conversacion` u `openclaw`. Es distinto de
 *    `created_via` (por qué ruta entró: `panel` o `claude`), que no se toca.
 *  - `link_type` + `link_ref`: vínculo opcional a una tarjeta del Workboard o a una misión (tipo y referencia).
 *
 * 🔴 Todo con default o nullable: una tarea anterior queda `owner` / `panel` / sin vínculo, y un cliente
 * viejo que no manda nada de esto sigue andando. Sin foreign keys y sin índices (se filtra sobre decenas de
 * filas por admin). Cada columna lleva su `Schema::hasColumn()`: correr la migración dos veces no falla.
 *
 * Backfill idempotente: lo creado por Claude hasta hoy vino de conversaciones, así que las filas con
 * `created_via = 'claude'` que siguen con el origen por defecto (`panel`) pasan a `conversacion`.
 */
class AddAssigneeOriginLinkToAgendaTasksTable extends Migration
{
    /**
     * Agrega las cuatro columnas (las que falten) y completa el origen de lo creado por Claude.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agenda_tasks', function (Blueprint $table) {
            // Quién hace la tarea: owner | openclaw.
            if (! Schema::hasColumn('agenda_tasks', 'assignee')) {
                $table->string('assignee', 20)->default('owner')->after('created_via');
            }

            // De dónde vino el pedido: panel | dots | conversacion | openclaw.
            if (! Schema::hasColumn('agenda_tasks', 'origin')) {
                $table->string('origin', 20)->default('panel')->after('assignee');
            }

            // Tipo del vínculo: workboard | mision (null = sin vínculo).
            if (! Schema::hasColumn('agenda_tasks', 'link_type')) {
                $table->string('link_type', 20)->nullable()->after('origin');
            }

            // Referencia del vínculo: id de la tarjeta o slug de la misión.
            if (! Schema::hasColumn('agenda_tasks', 'link_ref')) {
                $table->string('link_ref', 120)->nullable()->after('link_type');
            }
        });

        // Lo que Claude creó hasta hoy vino de conversaciones. Idempotente: sólo toca lo que sigue en el default.
        DB::table('agenda_tasks')
            ->where('created_via', 'claude')
            ->where('origin', 'panel')
            ->update(['origin' => 'conversacion']);
    }

    /**
     * Quita las cuatro columnas (las que estén).
     *
     * @return void
     */
    public function down()
    {
        $columnas = array_values(array_filter(['assignee', 'origin', 'link_type', 'link_ref'], function ($columna) {
            return Schema::hasColumn('agenda_tasks', $columna);
        }));

        if (! empty($columnas)) {
            Schema::table('agenda_tasks', function (Blueprint $table) use ($columnas) {
                $table->dropColumn($columnas);
            });
        }
    }
}

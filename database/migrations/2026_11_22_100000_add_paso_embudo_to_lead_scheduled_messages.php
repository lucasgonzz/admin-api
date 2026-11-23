<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca los mensajes programados que son un paso de seguimiento del embudo (misión embudo-whatsapp-precios, etapa E3).
 *
 * Los seguimientos automáticos del embudo (3 h y 22 h en `nuevo`, 22 h y día 3 en `contactado`, 2 h, 22 h y día 3 en
 * `link_enviado`) se programan sobre `lead_scheduled_messages`, sin motor nuevo. Dos columnas los distinguen de los que
 * programa un operador desde el panel:
 *  - `paso_embudo`: la clave del paso (`nuevo_3h`, `link_22h`...). Null = lo programó una persona.
 *  - `estado_lead_al_programar`: el estado del lead cuando se programó. El despacho cancela el paso si el lead ya no está en él.
 *
 * Sin claves foráneas (convención del repo) y sin tocar ninguna fila existente: todas quedan en null.
 */
class AddPasoEmbudoToLeadScheduledMessages extends Migration
{
    /**
     * Agrega las dos columnas y el índice de `paso_embudo`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('lead_scheduled_messages', function (Blueprint $table) {
            $table->string('paso_embudo', 40)->nullable()->after('created_by_admin_id');
            $table->string('estado_lead_al_programar', 40)->nullable()->after('paso_embudo');
            $table->index('paso_embudo', 'lsm_paso_embudo_idx');
        });
    }

    /**
     * Saca el índice y las columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('lead_scheduled_messages', function (Blueprint $table) {
            $table->dropIndex('lsm_paso_embudo_idx');
            $table->dropColumn(['paso_embudo', 'estado_lead_al_programar']);
        });
    }
}

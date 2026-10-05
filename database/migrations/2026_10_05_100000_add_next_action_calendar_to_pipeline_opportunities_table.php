<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde quedó sincronizada la próxima acción de una oportunidad del CRM con Google Calendar
 * (misión pipelines-calendario-proxima-accion, 5/10/2026).
 *
 * Cuando un admin fija la próxima acción de una oportunidad y tiene su calendario vinculado
 * (`admin_calendar_connections`), el sistema crea un evento en ESE calendario. Para poder
 * actualizarlo o borrarlo después hace falta recordar dos cosas, y las dos viven acá:
 *
 *  - `next_action_calendar_event_id`: el id del evento en Google.
 *  - `next_action_calendar_admin_id`: de qué admin es el calendario donde se creó. No alcanza con
 *    el id del evento: si otro admin reprograma la acción, el evento viejo hay que borrarlo del
 *    calendario del primero (con el token del primero), no del del que reprogramó.
 *
 * 🔴 Sin foreign keys, como el resto del módulo de pipelines: si el admin se borra, el evento ya no
 * se puede tocar de todos modos y una FK solo agregaría un error al borrarlo.
 *
 * Migración NUEVA y aditiva (dos columnas nullable): las oportunidades existentes quedan sin evento
 * y no hay backfill, solo se sincroniza lo que se fije de acá en adelante. NO se edita la create
 * original (`2026_09_27_100003`), que ya corrió en las bases de los slots y de producción.
 */
class AddNextActionCalendarToPipelineOpportunitiesTable extends Migration
{
    /**
     * Agrega las dos columnas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pipeline_opportunities', function (Blueprint $table) {
            // Admin dueño del calendario donde está el evento (sin FK a propósito). Con índice
            // porque es lo que se mira para saber "¿qué eventos tiene este admin?".
            $table->unsignedBigInteger('next_action_calendar_admin_id')->nullable()->after('next_action_source');

            // Id del evento en Google Calendar (Google los genera de hasta 1024 caracteres, pero
            // en la práctica son de ~26; 255 sobra y entra en una columna indexable).
            $table->string('next_action_calendar_event_id', 255)->nullable()->after('next_action_calendar_admin_id');

            $table->index('next_action_calendar_admin_id', 'pipeline_opps_cal_admin_idx');
        });
    }

    /**
     * Saca las dos columnas (y el índice).
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pipeline_opportunities', function (Blueprint $table) {
            $table->dropIndex('pipeline_opps_cal_admin_idx');
            $table->dropColumn(['next_action_calendar_admin_id', 'next_action_calendar_event_id']);
        });
    }
}

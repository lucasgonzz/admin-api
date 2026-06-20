<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla pivot para notificaciones WhatsApp de mensajes de lead por admin.
 * Reemplaza las columnas notificar_mensajes + notify_admin_id de la tabla leads.
 *
 * Un admin puede suscribirse a múltiples leads y un lead puede tener múltiples
 * admins suscritos. Al recibir un mensaje del lead, se envía un WhatsApp a todos
 * los admins suscritos que tengan phone_number cargado.
 */
class CreateLeadAdminNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('lead_admin_notifications', function (Blueprint $table) {
            /* Referencia al lead al que el admin se suscribe. */
            $table->unsignedBigInteger('lead_id');

            /* Admin que recibirá el WhatsApp al llegar un mensaje del lead. */
            $table->unsignedBigInteger('admin_id');

            /* Clave primaria compuesta: evita duplicados de suscripción. */
            $table->primary(['lead_id', 'admin_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('lead_admin_notifications');
    }
}

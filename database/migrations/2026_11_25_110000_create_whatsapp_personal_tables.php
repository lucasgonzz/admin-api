<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas del número PERSONAL de WhatsApp en coexistencia (misión wa-personal-coexistencia, 25/11/2026).
 *
 * El número personal de Lucas (WhatsApp Business App conectado a Kapso en la misma WABA del institucional)
 * guarda sus chats en tablas PROPIAS, que ningún otro código del admin lee: así lo que entra o sale por ahí
 * no se mezcla con leads, soporte ni el asistente.
 *
 *   - `whatsapp_personal_messages`: un mensaje por fila (entrante, saliente por la API o eco de la app).
 *   - `whatsapp_personal_chats`: un chat por teléfono, con el cursor de lectura (`leido_hasta`).
 *
 * Aditiva y con guardas `hasTable`, como el resto de las migraciones de este repo que crean tablas. Sin claves
 * foráneas: no se relaciona con `clients` ni con `leads` a propósito (privacidad).
 */
class CreateWhatsappPersonalTables extends Migration
{
    /**
     * Crea las dos tablas si no existen.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('whatsapp_personal_messages')) {
            Schema::create('whatsapp_personal_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                // Número de Kapso/Meta por el que pasó el mensaje (el personal).
                $table->string('phone_number_id', 40)->default('');
                // Teléfono del cliente en E.164 (+549…): es la clave del chat.
                $table->string('chat_telefono', 32);
                // in = lo escribió el cliente; out = salió del número personal.
                $table->string('direccion', 3)->default('in');
                // cloud_api (API de Kapso), business_app (eco de la app), history_sync, otro.
                $table->string('origen', 20)->default('cloud_api');
                // wamid de Meta: es único para deduplicar webhooks y ecos.
                $table->string('whatsapp_message_id', 128)->nullable();
                $table->string('tipo', 20)->default('text');
                $table->text('cuerpo')->nullable();
                // Metadatos de media: el binario nunca se guarda, se pide a Kapso bajo demanda.
                $table->string('media_id', 128)->nullable();
                $table->string('media_mime', 80)->nullable();
                $table->string('media_filename', 190)->nullable();
                $table->string('contact_name', 120)->nullable();
                $table->string('reply_to_wamid', 128)->nullable();
                // recibido, pendiente, enviado, entregado, leido, fallido, incierto, simulado.
                $table->string('estado', 20)->default('recibido');
                $table->string('estado_error', 255)->nullable();
                // Idempotencia del envío: el id de la operación y el hash de su contenido.
                $table->string('op_id', 80)->nullable();
                $table->string('op_hash', 64)->nullable();
                // Hora real del mensaje (la de Meta), no la de llegada del webhook.
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->unique('whatsapp_message_id', 'wpm_wamid_uq');
                $table->unique('op_id', 'wpm_op_uq');
                $table->index(['chat_telefono', 'sent_at'], 'wpm_chat_sent_idx');
            });
        }

        if (! Schema::hasTable('whatsapp_personal_chats')) {
            Schema::create('whatsapp_personal_chats', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('chat_telefono', 32);
                $table->string('contact_name', 120)->nullable();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->timestamp('last_inbound_at')->nullable();
                // Cursor interno de «leído»: no manda ningún acuse a Meta.
                $table->timestamp('leido_hasta')->nullable();
                $table->timestamps();

                $table->unique('chat_telefono', 'wpc_tel_uq');
            });
        }
    }

    /**
     * Borra las dos tablas nuevas.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('whatsapp_personal_messages');
        Schema::dropIfExists('whatsapp_personal_chats');
    }
}

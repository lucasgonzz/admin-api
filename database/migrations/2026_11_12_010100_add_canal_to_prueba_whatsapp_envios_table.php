<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `prueba_whatsapp_envios.canal` (misión cruzada prueba-e2e-campana-imperio, 11/11/2026): el registro de
 * envíos de la prueba pasa a llevar también los correos (`correo`) y los mensajes que quedan en la
 * conversación de Soporte del sistema del negocio (`sistema`). Lo que ya había es todo `whatsapp`.
 *
 * Existe para que el tope de UN WhatsApp por día siga contando solo WhatsApps.
 */
class AddCanalToPruebaWhatsappEnviosTable extends Migration
{
    public function up()
    {
        Schema::table('prueba_whatsapp_envios', function (Blueprint $table) {
            $table->string('canal', 20)->default('whatsapp')->after('plantilla');
        });
    }

    public function down()
    {
        Schema::table('prueba_whatsapp_envios', function (Blueprint $table) {
            $table->dropColumn('canal');
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que kapso_api_key y webhook_secret en whatsapp_config sean NULL.
 *
 * WhatsappConfigSeeder ya está pensado para sembrar el registro con esas dos
 * columnas en null cuando KAPSO_API_KEY / KAPSO_WEBHOOK_SECRET no están
 * cargadas en el .env (desarrollo local sin WhatsApp real) — pero la columna
 * seguía siendo NOT NULL desde su creación original
 * (create_whatsapp_config_table), lo que rompía el seeder con un error de
 * integridad (1048) en vez de solo avisar por consola como el comentario
 * del seeder promete.
 */
class MakeKapsoCredentialsNullableInWhatsappConfigTable extends Migration
{
    /**
     * Aplica nullable en kapso_api_key y webhook_secret de whatsapp_config.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('whatsapp_config')) {
            return;
        }

        Schema::table('whatsapp_config', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_config', 'kapso_api_key')) {
                $table->string('kapso_api_key')->nullable()->change();
            }
            if (Schema::hasColumn('whatsapp_config', 'webhook_secret')) {
                $table->string('webhook_secret')->nullable()->change();
            }
        });
    }

    /**
     * Revierte kapso_api_key y webhook_secret a NOT NULL (solo si no hay filas con NULL).
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('whatsapp_config')) {
            return;
        }

        Schema::table('whatsapp_config', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_config', 'kapso_api_key')) {
                $table->string('kapso_api_key')->nullable(false)->change();
            }
            if (Schema::hasColumn('whatsapp_config', 'webhook_secret')) {
                $table->string('webhook_secret')->nullable(false)->change();
            }
        });
    }
}

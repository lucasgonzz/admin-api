<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que api_url, api_key e inbound_api_key en clients sean opcionales (NULL).
 * Esos datos se pueden cargar después en el perfil del cliente.
 */
class SetNullableApiUrlToClientsTable extends Migration
{
    /**
     * Aplica nullable en api_url, api_key e inbound_api_key de clients.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'api_url')) {
                $table->string('api_url', 255)->nullable()->change();
            }
            if (Schema::hasColumn('clients', 'api_key')) {
                $table->string('api_key', 120)->nullable()->change();
            }
            if (Schema::hasColumn('clients', 'inbound_api_key')) {
                $table->string('inbound_api_key', 120)->nullable()->change();
            }
        });
    }

    /**
     * Revierte los tres campos a NOT NULL (solo si no hay filas con NULL).
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'api_url')) {
                $table->string('api_url', 255)->nullable(false)->change();
            }
            if (Schema::hasColumn('clients', 'api_key')) {
                $table->string('api_key', 120)->nullable(false)->change();
            }
            if (Schema::hasColumn('clients', 'inbound_api_key')) {
                $table->string('inbound_api_key', 120)->nullable(false)->change();
            }
        });
    }
}

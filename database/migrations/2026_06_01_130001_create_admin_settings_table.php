<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdminSettingsTable extends Migration
{
    /**
     * Crea tabla clave-valor para configuraciones globales del panel admin.
     */
    public function up()
    {
        Schema::create('admin_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->timestamps();
        });
    }

    /**
     * Revierte creación de admin_settings.
     */
    public function down()
    {
        Schema::dropIfExists('admin_settings');
    }
}

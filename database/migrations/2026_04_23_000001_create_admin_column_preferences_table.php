<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAdminColumnPreferencesTable extends Migration
{
    /**
     * Preferencias de columnas visibles por admin y por recurso (sin FK: convención de proyecto).
     */
    public function up()
    {
        Schema::create('admin_column_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id');
            $table->string('model_name', 80);
            $table->json('properties');
            $table->timestamps();

            $table->index(['admin_id', 'model_name'], 'admin_column_pref_admin_model_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('admin_column_preferences');
    }
}

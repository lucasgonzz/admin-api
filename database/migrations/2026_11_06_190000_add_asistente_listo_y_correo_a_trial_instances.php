<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAsistenteListoYCorreoATrialInstances extends Migration
{
    public function up(): void
    {
        Schema::table('trial_instances', function (Blueprint $table) {
            $table->timestamp('asistente_listo_at')->nullable();
            $table->timestamp('correo_bienvenida_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trial_instances', function (Blueprint $table) {
            $table->dropColumn(['asistente_listo_at', 'correo_bienvenida_at']);
        });
    }
}

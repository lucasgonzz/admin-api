<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIsDefaultSupportOwnerToAdminsTable extends Migration
{
    /**
     * Agrega flag para asignación inicial automática de tickets.
     */
    public function up()
    {
        Schema::table('admins', function (Blueprint $table) {
            // Marca si el admin recibe asignación por defecto de soporte.
            $table->boolean('is_default_support_owner')->default(false)->after('password');
        });
    }

    /**
     * Revierte el flag de asignación por defecto.
     */
    public function down()
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn('is_default_support_owner');
        });
    }
}


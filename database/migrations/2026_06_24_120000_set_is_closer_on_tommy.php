<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marca a Tommy (admin id=3) como closer para que la capa de Google Calendar
 * aplique restricciones de disponibilidad al calcular slots de demo.
 */
class SetIsCloserOnTommy extends Migration
{
    /**
     * Activa is_closer en el admin Tommy si existe en la tabla admins.
     *
     * @return void
     */
    public function up()
    {
        // Tommy es el closer comercial; sin este flag la tercera capa de bloqueo no consulta su calendario.
        DB::table('admins')->where('id', 3)->update(['is_closer' => 1]);
    }

    /**
     * Revierte el flag is_closer de Tommy al valor por defecto.
     *
     * @return void
     */
    public function down()
    {
        DB::table('admins')->where('id', 3)->update(['is_closer' => 0]);
    }
}

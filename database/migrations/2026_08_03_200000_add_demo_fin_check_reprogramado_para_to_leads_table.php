<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `leads` la reprogramación del check de fin de demo (grupo 307, prompt 01).
 *
 * `CheckDemoFin` deja de mandar la pregunta de fin cuando hay una conversación viva (sugerencia
 * pendiente, o un mensaje reciente entrante/saliente) y en su lugar pospone acá el próximo intento.
 * Reemplaza el objetivo temporal `demo_datetime + duración` mientras esté seteada: null = sin
 * reprogramación vigente, usar el cálculo original.
 *
 * Sin foreign keys (regla del repo). Sin condicionar nombres/columnas por env(). Sin índice: se lee
 * solo por id de lead dentro del comando, nunca se busca por su contenido.
 */
return new class extends Migration
{
    /**
     * Agrega la columna `demo_fin_check_reprogramado_para` a `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dateTime('demo_fin_check_reprogramado_para')->nullable()->after('demo_fin_check_enviado');
        });
    }

    /**
     * Revierte las columnas agregadas (vacío, como el resto de migraciones del repo).
     *
     * @return void
     */
    public function down()
    {
    }
};

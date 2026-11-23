<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Implementación contratada del cliente y pausa de su prueba (misión embudo-juan-pipeline, etapa E2).
 *
 * `implementacion_contratada` dice si el negocio contrató que le carguemos el sistema (y Soporte, por lo tanto, puede importarle):
 *  - `no`: sin implementación; Soporte no importa.
 *  - `mitad`: pagó la mitad.
 *  - `completa`: pagó entera.
 *  - `incluida`: contrato anterior; mantiene «importación cuando lo necesite».
 *
 * 🔴 El default es `incluida` a propósito: todos los clientes que ya existen (incluidas las pruebas que están corriendo, a las que
 * se les prometió la carga sin costo) conservan lo que tienen hoy sin un seeder de datos. La regla nueva entra por
 * `AltaDePruebaService`, que escribe `no` en toda prueba nueva.
 *
 * Sin claves foráneas ni índices; todas las columnas nuevas con default.
 */
class AddImplementacionContratadaToClientsTable extends Migration
{
    /**
     * Agrega las siete columnas.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('implementacion_contratada', 20)->default('incluida');
            /* Subconjunto de gestion, tienda y agentes. */
            $table->json('implementacion_paquetes')->nullable();
            $table->dateTime('implementacion_mitad_pagada_at')->nullable();
            $table->dateTime('implementacion_articulos_recibidos_at')->nullable();
            $table->dateTime('implementacion_articulos_ok_at')->nullable();
            /* Pausa de la prueba mientras se implementa. */
            $table->dateTime('prueba_pausada_at')->nullable();
            $table->dateTime('prueba_reanudada_at')->nullable();
        });
    }

    /**
     * Saca las columnas.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'implementacion_contratada',
                'implementacion_paquetes',
                'implementacion_mitad_pagada_at',
                'implementacion_articulos_recibidos_at',
                'implementacion_articulos_ok_at',
                'prueba_pausada_at',
                'prueba_reanudada_at',
            ]);
        });
    }
}

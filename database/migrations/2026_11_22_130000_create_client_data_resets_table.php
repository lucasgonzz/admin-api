<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de los pedidos de reset de datos de negocio de un cliente (misión cruzada `reset-datos-cliente`, 21/11/2026).
 *
 * Una fila por llamada que el admin le hace al `empresa-api` del cliente (`POST api/admin-sync/reset-datos-negocio`), ya sea una
 * simulación (`dry_run = 1`) o una ejecución, venga del panel (`origen = panel`) o de un agente por `claude/` (`origen = claude`).
 * Es el rastro de "quién, cuándo y qué resultado" de una operación que borra datos de producción.
 *
 * 🔴 NUNCA guarda la `clients.api_key` ni el token de simulación: `resumen` lleva sólo conteos, el respaldo (ruta relativa y bytes) y el
 * `registro_id` de empresa.
 *
 * Sin llave foránea a `clients` ni a `admins` a propósito (convención del repo para las tablas de rastro): el rastro tiene que
 * sobrevivir a un cliente borrado. Índice con nombre corto.
 */
class CreateClientDataResetsTable extends Migration
{
    /**
     * Crea la tabla (idempotente: si ya existe no hace nada).
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('client_data_resets')) {
            return;
        }

        Schema::create('client_data_resets', function (Blueprint $table) {
            $table->bigIncrements('id');
            // Cliente al que se le pidió el reset y frente (ClientApi) al que se le habló.
            $table->unsignedBigInteger('client_id')->default(0);
            $table->unsignedBigInteger('client_api_id')->nullable();
            // Admin autenticado del panel; null cuando el pedido vino por `claude/`.
            $table->unsignedBigInteger('admin_id')->nullable();
            // `panel` o `claude`.
            $table->string('origen', 20)->default('claude');
            $table->string('solicitante', 60)->default('');
            $table->string('motivo', 120)->nullable();
            // 1 = simulación (no borra nada), 0 = ejecución.
            $table->boolean('dry_run')->default(true);
            // `ok`, `ok_con_advertencias`, `rechazado`, `error` o `sin_respuesta`.
            $table->string('estado', 30)->default('error');
            // Código estable del error (`clave_rechazada`, `comprobantes_con_cae`...), null si salió bien.
            $table->string('codigo', 60)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            // JSON con conteos, respaldo y registro_id de empresa. Nunca la clave ni el token.
            $table->text('resumen')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'created_at'], 'cdr_client_created_idx');
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('client_data_resets');
    }
}

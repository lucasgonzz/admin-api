<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `ia_imagen_pedidos`: la cola de imágenes que el agente del dueño pide desde la app y que genera
 * la PC de Lucas con su cuenta de ChatGPT (misión cruzada `app-ia-y-memoria`, 29/10/2026).
 *
 * Ciclo: el empresa-api lo da de alta (`POST api/inbound/ia-imagenes`) → la PC lo reserva con un lease
 * de 15 minutos (`claude/ia-imagenes/{id}/reservar`) → entrega la imagen (`resultado`) o el fallo
 * (`fallo`) → el admin se la devuelve al empresa-api (`EntregarImagenIaJob`).
 *
 * Estados: pendiente | reservado | lista | fallida | entregada | entrega_fallida. `resultado` dice qué
 * se le entregó (o se le va a entregar) al cliente: `lista` (con imagen) o `fallida` (con error).
 *
 * 🔴 `lease_hash` es el sha256 del token de reserva; el token en claro solo lo tiene el worker.
 */
class CreateIaImagenPedidosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('ia_imagen_pedidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();

            // El uuid que generó el empresa-api: es la clave de idempotencia del alta y de la entrega.
            $table->string('uuid', 36)->unique();
            $table->unsignedBigInteger('ai_message_id')->nullable();

            $table->text('prompt');
            $table->string('tamano', 20)->default('cuadrada');
            $table->string('estilo', 200)->nullable();

            $table->string('estado', 20)->default('pendiente')->index();
            $table->string('resultado', 10)->nullable();

            // La reserva de la PC (molde support_codex_cases).
            $table->string('worker_id', 150)->nullable();
            $table->string('lease_hash', 64)->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->unsignedTinyInteger('intentos')->default(0);

            // La imagen generada, en el disco privado del admin (storage/app, nunca public).
            $table->string('imagen_path', 255)->nullable();
            $table->string('imagen_mime', 50)->nullable();
            $table->string('error', 500)->nullable();

            // La entrega al empresa-api del cliente.
            $table->unsignedTinyInteger('entrega_intentos')->default(0);
            $table->string('entrega_error', 500)->nullable();
            $table->timestamp('entregado_en')->nullable();

            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ia_imagen_pedidos');
    }
}

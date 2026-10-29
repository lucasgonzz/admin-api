<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `ia_directorio`: el directorio que usa el login de la app `ia.comerciocity.com` para saber a
 * qué sistema pertenece un usuario sin preguntárselo (misión cruzada `app-ia-y-memoria`, 29/10/2026).
 *
 * Varias filas por cliente, una por usuario (único `client_id + usuario_hash`), con su `origen`:
 *  - `sincronizado`: la del dueño según su sistema. La escribe `IaDirectorioService::sincronizar()` con lo
 *    que contesta `GET api/admin-sync/ia-app/dueno` del empresa-api del cliente. Hay una sola por cliente.
 *  - `aprendido`: la que anota el login cuando alguien entra por `negocio` y su sistema lo acepta.
 * 🔴 Que sean filas distintas es a propósito: un login aprendido NUNCA pisa al dueño sincronizado.
 *
 * 🔴 Del usuario se guarda SOLO el hash (HMAC-SHA256 del usuario normalizado, con la APP_KEY del admin
 * como clave). Nunca el usuario en claro y nunca la contraseña: la contraseña no pasa por ninguna
 * tabla del admin.
 */
class CreateIaDirectorioTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('ia_directorio', function (Blueprint $table) {
            $table->id();

            // El cliente del admin.
            $table->unsignedBigInteger('client_id')->index();

            // Hash del usuario normalizado (trim, sin puntos ni espacios, minúsculas). Null si el dueño no
            // tiene usuario cargado en su sistema.
            $table->string('usuario_hash', 64)->nullable()->index();

            // sincronizado (el dueño según su sistema) | aprendido (lo anotó el login por negocio).
            $table->string('origen', 20)->default('sincronizado');

            // Nombre del dueño, para mostrar (no se usa para buscar).
            $table->string('nombre_dueno', 150)->nullable();

            // Si el cliente tiene la extensión «Agentes de IA» activa según la última lectura.
            $table->boolean('extension_activa')->default(false);

            // Cuándo se leyó por última vez del sistema del cliente (null en una fila aprendida).
            $table->timestamp('sincronizado_en')->nullable();

            $table->timestamps();

            $table->unique(['client_id', 'usuario_hash']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ia_directorio');
    }
}

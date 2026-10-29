<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea `ia_directorio`: el directorio que usa el login de la app `ia.comerciocity.com` para saber a
 * qué sistema pertenece un usuario sin preguntárselo (misión cruzada `app-ia-y-memoria`, 29/10/2026).
 *
 * Una fila por cliente: el dueño de ese sistema. La llena `IaDirectorioService::sincronizar()` con lo
 * que contesta `GET api/admin-sync/ia-app/dueno` del empresa-api del cliente, y la aprende el login
 * cuando entra alguien por `negocio`.
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

            // El cliente del admin. Una sola fila por cliente (el dueño de ese sistema).
            $table->unsignedBigInteger('client_id')->unique();

            // Hash del usuario normalizado (trim, sin puntos ni espacios, minúsculas). Null si el dueño no
            // tiene usuario cargado en su sistema.
            $table->string('usuario_hash', 64)->nullable()->index();

            // Nombre del dueño, para mostrar (no se usa para buscar).
            $table->string('nombre_dueno', 150)->nullable();

            // Si el cliente tiene la extensión «Agentes de IA» activa según la última lectura.
            $table->boolean('extension_activa')->default(false);

            // Cuándo se leyó por última vez del sistema del cliente (null = la aprendió el login).
            $table->timestamp('sincronizado_en')->nullable();

            $table->timestamps();
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

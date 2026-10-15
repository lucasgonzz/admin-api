<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los usuarios del centro de recursos (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * No son usuarios del admin: son el espejo de un `User` del `empresa-api` de un cliente, que entró
 * al centro con un pase canjeado (o el dueño, con su link personal). La identidad es
 * (`client_id`, `empresa_user_id`), con un único: el mismo usuario de ese sistema es siempre la misma
 * fila, entre desde donde entre.
 *
 * `empresa_user_id` es nullable a propósito: el link personal del dueño se puede generar desde el
 * panel antes de que el dueño haya entrado nunca desde su sistema, y el admin no siempre sabe su id
 * (`clients.user_id` puede faltar). Esa fila queda con `es_dueno` y el id en null, y el primer pase
 * canjeado del dueño se lo COMPLETA en vez de crear otro usuario (ver `RecursosSesionService`). MySQL
 * deja varios null en un único, así que el índice no estorba.
 *
 * `client_id` con FK en cascada (lo pide el plan de la misión): si se borra el cliente, se va todo lo
 * suyo del centro. Los demás `recursos_*` cuelgan de acá también en cascada.
 */
class CreateRecursosUsuariosTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_usuarios')) {
            return;
        }

        Schema::create('recursos_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('empresa_user_id')->nullable();
            $table->string('nombre', 255);
            $table->string('email', 190)->nullable();
            $table->boolean('es_dueno')->default(false);
            $table->timestamp('ultimo_ingreso_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'empresa_user_id'], 'recursos_usuarios_cliente_usuario_unique');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_usuarios');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las sesiones del centro de recursos (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * 🔴 Tabla PROPIA y no `personal_access_tokens` de Sanctum. Esa tabla es polimórfica y el
 * `auth:sanctum` del admin resuelve cualquier token que encuentre ahí: un token del centro guardado
 * en Sanctum autenticaría en las rutas del panel (`$request->user()` dejaría de ser un `Admin`). El
 * porqué completo está en `App\Http\Middleware\RecursosSesion`.
 *
 * Solo se guarda el sha256 del token (64 caracteres): si se filtra la base, no hay con qué entrar.
 * Vive un año (`config('recursos.sesion_dias')`); `last_used_at` se refresca como mucho cada pocos
 * minutos, no en cada pedido.
 */
class CreateRecursosSesionesTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_sesiones')) {
            return;
        }

        Schema::create('recursos_sesiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recursos_usuario_id')->constrained('recursos_usuarios')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_sesiones');
    }
}

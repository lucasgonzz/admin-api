<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El link personal del dueño al centro de recursos (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * Es la puerta de entrada que no depende de la versión del sistema del cliente: va en el mail "listo"
 * de la implementación y en el botón "Copiar link del dueño" del panel. Reutilizable hasta vencer
 * (90 días): el dueño lo abre en la compu y en el celular, y cada apertura crea una sesión propia.
 *
 * Se guarda el sha256 del token (`token_hash`, por donde se busca al abrirlo) y ADEMÁS el token
 * cifrado con la APP_KEY (`token_cifrado`). El segundo existe porque el panel tiene que poder volver a
 * mostrar el MISMO link vigente las veces que haga falta ("crea o reusa el vigente"): con solo el hash
 * no hay forma de reconstruir la URL, y cada "Copiar link" dejaría un link nuevo más dando vueltas.
 * Cifrado y no en claro, por el mismo motivo que `client_apis.provisioning_secrets`.
 */
class CreateRecursosLinksTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('recursos_links')) {
            return;
        }

        Schema::create('recursos_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recursos_usuario_id')->constrained('recursos_usuarios')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            // Laravel Crypt: el texto cifrado no entra en un varchar, por eso `text`.
            $table->text('token_cifrado')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('usos')->default(0);
            $table->unsignedBigInteger('creado_por_admin_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('recursos_links');
    }
}

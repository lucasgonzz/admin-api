<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El permiso delegado de una tarea de Martín (misión cruzada `importaciones-de-martin`, 11/11/2026): cuando Martín pide
 * una tarea nativa (las fotos de la web de un proveedor o el catálogo PDF de un cliente activo), el admin le emite un
 * token de 24 h acotado al ticket y a un alcance (`pdf` | `web`). La tarea lo usa EN LUGAR de la reserva del ticket solo
 * para `catalogo-articulos` (los dos alcances) e `import-images-proveedor` (solo `web`).
 *
 *  - `token_hash`: sha256 del token. El token en claro sale UNA sola vez, en la respuesta de `task-grant`.
 *  - `client_id`: el cliente del ticket al emitir; si el ticket cambia de cliente, el permiso deja de servir.
 *  - `vence_at`: emisión + 24 h. `revocado_at` queda para cortarlo antes (hoy nadie lo escribe).
 *  - `usos` y `usado_at`: rastro de cuántas veces y cuándo lo usó la tarea.
 *
 * Tabla nueva: un admin viejo no la conoce y no la necesita.
 */
class CreateSupportCodexTaskGrantsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::create('support_codex_task_grants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('support_ticket_id');
            $table->unsignedBigInteger('client_id');
            $table->string('alcance', 10);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('vence_at');
            $table->timestamp('revocado_at')->nullable();
            $table->unsignedInteger('usos')->default(0);
            $table->timestamp('usado_at')->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'alcance']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('support_codex_task_grants');
    }
}

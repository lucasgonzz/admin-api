<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lotes de cambio masivo de variables .env sobre los clientes.
 *
 * Un lote nace como previsualización (nada se escribió todavía) y sólo puede aplicarse presentando
 * su token. Se persiste en base y no en cache a propósito: es la auditoría de qué se cambió, cuándo
 * y a quién, y no puede depender de qué CACHE_DRIVER tenga configurado el admin.
 */
class CreateEnvChangeBatchesTable extends Migration
{
    /**
     * Crea la tabla env_change_batches.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('env_change_batches', function (Blueprint $table) {
            // Identificador interno.
            $table->id();

            // Token que devuelve la previsualización y que la aplicación exige para escribir.
            $table->string('token', 64)->unique();

            // Estado del lote: previewed | applying | applied.
            // 'applying' existe para que un lote cortado a la mitad (timeout con muchos clientes,
            // una sesión SSH colgada) se pueda reanudar: los renglones que quedaron sin escribir
            // siguen en 'previewed' y se retoman. Sin ese estado intermedio, marcar 'applied' antes
            // de escribir dejaba el token quemado y la mitad del lote sin forma de completarse.
            $table->string('status')->default('previewed');

            // Vencimiento de la previsualización. Pasado esto el lote ya no se puede aplicar.
            $table->timestamp('expires_at');

            // Momento en que se aplicó, null mientras siga siendo sólo una previsualización.
            $table->timestamp('applied_at')->nullable();

            // Admin que originó el lote, si la request vino autenticada.
            $table->unsignedBigInteger('admin_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla env_change_batches.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('env_change_batches');
    }
}

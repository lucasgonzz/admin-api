<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla de códigos de confirmación del envío de WhatsApp desde Dotin/ChatGPT (misión
 * dotin-whatsapp-admin, 26/11/2026).
 *
 * Un envío se hace en dos pasos: preparar (emite un código atado a cuenta, teléfono, contenido y `op`) y confirmar
 * (consume el código y envía). 🔴 La tabla NO guarda el texto del mensaje, ni el pedido literal de Lucas (sólo su
 * sha256), ni el código en claro (sólo su sha256). Sin claves foráneas ni unique, con índices de nombre corto y
 * strings acotados. Idempotente: si la tabla ya existe no hace nada.
 */
class CreateMcpWhatsappConfirmationsTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('mcp_whatsapp_confirmations')) {
            return;
        }

        Schema::create('mcp_whatsapp_confirmations', function (Blueprint $table) {
            $table->id();

            // sha256 (hex) del código de confirmación: el código en claro nunca se guarda.
            $table->char('code_hash', 64);

            // Admin y cliente OAuth (el conector de ChatGPT) a los que quedó atado el código.
            $table->unsignedBigInteger('admin_id');
            $table->string('client_id', 60);

            // Cuenta de WhatsApp (`personal`) y teléfono de destino en E.164.
            $table->string('account', 20);
            $table->string('phone', 32);

            // sha256 del contenido (cuenta, teléfono, texto o plantilla, variables, idioma) y del pedido literal de Lucas.
            $table->char('content_hash', 64);
            $table->char('request_hash', 64);

            // Clave de idempotencia que mandó el agente (el envío real usa `mcp:<op>`).
            $table->string('op', 80);

            // Vencimiento del código y momento en que se consumió.
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();

            // Resultado del envío (sin texto): estado HTTP, código de error y id del mensaje guardado.
            $table->smallInteger('result_status')->nullable();
            $table->string('result_code', 40)->nullable();
            $table->unsignedBigInteger('message_id')->nullable();

            $table->timestamps();

            $table->index('code_hash', 'mcpwc_code_idx');
            $table->index('admin_id', 'mcpwc_admin_idx');
        });
    }

    /**
     * Elimina la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mcp_whatsapp_confirmations');
    }
}

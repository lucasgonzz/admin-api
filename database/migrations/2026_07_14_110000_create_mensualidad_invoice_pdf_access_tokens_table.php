<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de tokens de un solo uso para autorizar la vista en vivo (en pestaña
 * del navegador) del PDF de una Factura C de mensualidad (prompt 362),
 * replicando el mismo patrón que `sale_pdf_access_tokens` de empresa-api.
 */
class CreateMensualidadInvoicePdfAccessTokensTable extends Migration
{
    /**
     * Crea la tabla sin FKs (convención del proyecto).
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mensualidad_invoice_pdf_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 128)->unique();
            // Nombre de índice corto explícito: el autogenerado por Laravel
            // (mensualidad_invoice_pdf_access_tokens_mensualidad_invoice_id_index)
            // supera el límite de 64 caracteres de MySQL.
            $table->unsignedBigInteger('mensualidad_invoice_id')->index('miv_pdf_token_invoice_idx');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla creada.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mensualidad_invoice_pdf_access_tokens');
    }
}

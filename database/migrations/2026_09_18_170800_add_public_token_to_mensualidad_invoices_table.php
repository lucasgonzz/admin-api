<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link público y durable del PDF de una Factura C de mensualidad (misión cobranzas-mejoras,
 * 18/9/2026, pedido 10 de Lucas: botón "Enviar por WhatsApp").
 *
 * 🔴 Es DISTINTO de `mensualidad_invoice_pdf_access_tokens` (`MensualidadInvoicePdfAccessToken`,
 * prompt 362): esa tabla es de tokens de un solo uso que viven 2 minutos, pensados para que
 * admin-spa abra el PDF al toque con `window.open()`. Lo que pide Lucas acá es lo opuesto — un
 * link que se pueda volver a abrir "desde cualquier dispositivo, en cualquier momento" — por eso
 * es una columna nueva en la factura misma (un token por factura, se genera una sola vez y se
 * reusa para siempre) y no una fila más en esa tabla de tokens cortos.
 */
class AddPublicTokenToMensualidadInvoicesTable extends Migration
{
    /**
     * Agrega la columna si no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('mensualidad_invoices', 'public_token')) {
            return;
        }

        Schema::table('mensualidad_invoices', function (Blueprint $table) {
            // Nullable: la mayoría de las facturas nunca lo van a necesitar (se genera recién
            // cuando alguien aprieta "Enviar por WhatsApp"). Único: cada token identifica una sola
            // factura sin ambigüedad, y evita colisiones si algún día se genera dos veces seguidas
            // por una carrera de clicks.
            $table->string('public_token', 64)->nullable()->unique()->after('cae_expired_at');
        });
    }

    /**
     * Saca la columna si existe.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('mensualidad_invoices', 'public_token')) {
            return;
        }

        Schema::table('mensualidad_invoices', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
    }
}

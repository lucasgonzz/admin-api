<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `mensualidad_invoices`: registro de cada comprobante (Factura C)
 * emitido contra AFIP (WSFE) por la mensualidad de un Client (prompt 331).
 *
 * Es un comprobante plano (sin Sale, sin artículos, sin cuenta corriente): solo
 * cabecera fiscal + resultado de AFIP. La idempotencia (no re-emitir un período
 * ya autorizado) se resuelve en código (AfipFacturacionService), no con un
 * índice único, para no bloquear reintentos de un intento rechazado.
 */
class CreateMensualidadInvoicesTable extends Migration
{
    /**
     * Crea la tabla mensualidad_invoices.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mensualidad_invoices', function (Blueprint $table) {
            // Identificador interno.
            $table->id();
            // Cliente al que se le factura la mensualidad (sin foreign(), según convención del workspace).
            $table->unsignedBigInteger('client_id')->index();
            // Período facturado, formato 'YYYY-MM'.
            $table->string('periodo', 7);

            // Datos del comprobante AFIP.
            $table->integer('cbte_tipo')->default(11);
            $table->string('cbte_letra', 1)->default('C');
            $table->integer('cbte_numero')->nullable();
            $table->integer('punto_venta')->nullable();

            // Emisor (ComercioCity) y receptor (Client), snapshot al momento de emitir.
            $table->string('cuit_negocio', 50)->nullable();
            $table->string('cuit_cliente', 50)->nullable();
            $table->integer('doc_tipo')->nullable();
            $table->string('doc_nro', 50)->nullable();

            // Importes: Factura C no discrimina IVA (imp_neto = importe_total, imp_iva = 0).
            $table->decimal('importe_total', 12, 2)->nullable();
            $table->decimal('imp_neto', 12, 2)->nullable();
            $table->decimal('imp_iva', 12, 2)->nullable();

            // Condición IVA del receptor enviada a AFIP (CondicionIVAReceptorId).
            $table->integer('condicion_iva_receptor_id')->nullable();

            // Resultado de AFIP.
            $table->string('cae', 20)->nullable();
            $table->date('cae_expired_at')->nullable();
            $table->string('resultado', 1)->nullable();
            $table->text('error_message')->nullable();

            // Auditoría: request/response SOAP crudos para poder investigar fallos.
            $table->longText('request')->nullable();
            $table->longText('response')->nullable();

            // Ambiente en el que se emitió (homologación/producción), para no confundir CAEs de prueba.
            $table->boolean('afip_produccion')->default(false);

            $table->timestamps();

            // Índice para buscar rápido por cliente + período (no único: ver comentario arriba).
            $table->index(['client_id', 'periodo'], 'mensualidad_invoices_client_periodo_idx');
        });
    }

    /**
     * Elimina la tabla mensualidad_invoices.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('mensualidad_invoices');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `senas` (misión senas-en-ventas, 30/10/2026).
 *
 * Una seña es un pago a cuenta que el cliente deja ANTES (o aparte) de las cuotas de una venta. El
 * caso que la originó: Sebastián (Ferretería Plazoleta), un plan de USD 1.300 en 4 cuotas y una
 * seña de USD 100. La seña no es una cuota más: se APLICA SOLA a las cuotas pendientes más
 * próximas de la venta (con `LicenciaCuotaService::registrar_pago()`, el mismo camino de cualquier
 * cobro), de modo que deuda, cobrado y comisión liberada siguen cuadrando como siempre. Esta tabla
 * guarda el registro de la seña (cuánto, cuándo, quién, con qué nota y a qué cuotas se aplicó) para
 * poder mostrarla («Seña USD 100») y auditarla.
 *
 * Una seña puede registrarse en el LEAD antes de promoverlo: en ese caso queda con `venta_id` y
 * `aplicada_at` en null (pendiente) y se aplica sola cuando la promoción crea la venta del contrato
 * (`SenaService::aplicar_pendientes_del_lead()`).
 *
 * Reglas del repo que se respetan acá:
 *  - SIN foreign keys: `venta_id`, `lead_id`, `client_id` y `registrada_por_admin_id` son enteros
 *    sueltos con índice. Borrar un lead o un admin no puede arrastrar ni trabar una seña.
 *  - Índices con nombre corto (MySQL corta en 64 caracteres).
 *  - Con guarda `hasTable`: correrla dos veces (o sobre una base que ya la tiene) no falla.
 */
class CreateSenasTable extends Migration
{
    /**
     * Crea la tabla si todavía no existe.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('senas')) {
            return;
        }

        Schema::create('senas', function (Blueprint $table) {
            $table->id();

            // Venta a la que se aplicó. Null mientras la seña es de un lead sin promover.
            $table->unsignedBigInteger('venta_id')->nullable()->index('senas_venta_idx');

            // Lead en el que se registró (null si se registró directo en una venta).
            $table->unsignedBigInteger('lead_id')->nullable()->index('senas_lead_idx');

            // Cliente de la venta (null mientras es de un lead sin promover).
            $table->unsignedBigInteger('client_id')->nullable()->index('senas_client_idx');

            // Importe de la seña y su moneda (la de la venta, o la del contrato del lead).
            $table->decimal('monto', 12, 2);
            $table->string('moneda', 3)->default('USD');

            // Cuándo la dejó el cliente.
            $table->date('fecha');

            // Nota libre.
            $table->string('nota', 255)->nullable();

            // Admin que la registró (null si la registró un proceso o Claude sin admin).
            $table->unsignedBigInteger('registrada_por_admin_id')->nullable();

            // De dónde salió: manual (panel) | claude.
            $table->string('origen', 20)->default('manual');

            // A qué cuotas se aplicó: [{cuota_id, monto}], para auditar.
            $table->json('aplicaciones')->nullable();

            // Cuándo se aplicó a las cuotas. Null = pendiente (seña de un lead sin promover).
            $table->dateTime('aplicada_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Borra la tabla si existe.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('senas');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `ventas` (misión ventas-y-comisiones, 28/10/2026).
 *
 * Una venta es lo que se le vende a un cliente —el sistema de gestión, el e-commerce, los agentes,
 * un desarrollo a medida u otro motivo— con sus cuotas (`licencia_cuotas.venta_id`) y la comisión
 * del vendedor que la cerró. Hasta esta misión las "licencias" eran cuotas sueltas colgadas del
 * cliente; ahora las cuotas se agrupan en ventas y cada venta liquida su comisión.
 *
 * Reglas del repo que se respetan acá:
 *  - SIN foreign keys: `client_id`, `lead_id`, `vendedor_admin_id` y `created_by_admin_id` son
 *    enteros sueltos con índice. Borrar un admin o un lead no puede arrastrar ni trabar una venta.
 *  - Índices con nombre corto (MySQL corta en 64 caracteres y el nombre automático no entra).
 *  - Todo lo que no es obligatorio en la práctica es nullable o tiene default.
 */
class CreateVentasTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();

            // Cliente al que se le vendió.
            $table->unsignedBigInteger('client_id')->index('ventas_client_idx');

            // Lead de origen, si la venta nació de la promoción (o se conoce).
            $table->unsignedBigInteger('lead_id')->nullable()->index('ventas_lead_idx');

            // Claves de Venta::PRODUCTOS: gestion | ecommerce | agentes | desarrollo | otro.
            $table->json('productos')->nullable();

            // Texto libre: obligatorio en la práctica para desarrollo y otro (lo exige el front).
            $table->string('descripcion', 255)->nullable();

            // Fecha de la venta. Sin default de base: lo pone el servicio (hoy) al crear.
            $table->date('fecha')->nullable();

            // Total de la venta y su moneda. La comisión se liquida en esta misma moneda.
            $table->decimal('monto_total', 14, 2)->default(0);
            $table->string('moneda', 3)->default('USD');

            // De dónde salió: contrato | manual | claude | planilla.
            $table->string('origen', 20)->default('manual');

            // Vendedor al que se le liquida la comisión (admins.es_vendedor).
            $table->unsignedBigInteger('vendedor_admin_id')->nullable()->index('ventas_vendedor_idx');

            // Porcentaje de comisión y comisión total calculada (monto_total × porcentaje / 100).
            $table->decimal('comision_porcentaje', 5, 2)->nullable();
            $table->decimal('comision_monto', 14, 2)->nullable();

            $table->text('observacion')->nullable();

            // Quién la dio de alta (null si la creó un proceso: la promoción o el comando).
            $table->unsignedBigInteger('created_by_admin_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Borra la tabla.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ventas');
    }
}

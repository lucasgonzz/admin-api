<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El historial de actualizaciones de precio de la mensualidad de cada cliente (misión
 * modulo-cobranzas, 18/9/2026).
 *
 * Hasta hoy los cinco precios (`clients.precio_plan`, `precio_por_cuenta`, `precio_ecommerce`,
 * `precio_mercado_libre`, `precio_tienda_nube`) se editaban en el lugar y no quedaba rastro de
 * cuánto valían antes ni desde cuándo. Lucas necesita saber **hace cuánto no se actualiza
 * oficialmente** la mensualidad de cada cliente para hacerlo cada N meses (los del contrato,
 * `clients.contract_meses_actualizacion`): esa cuenta necesita una fecha, y la fecha necesita
 * una fila.
 *
 * Cada fila es una foto de los cinco precios en una fecha. `es_oficial` distingue la
 * actualización que cuenta para el contrato (la de IPC) de un retoque cualquiera (un precio de
 * módulo que se corrigió, un descuento puntual): la próxima fecha se calcula desde la última
 * OFICIAL. Los cinco precios se copian acá y no se leen del cliente porque el cliente siempre
 * tiene los actuales; la historia es justamente lo que el cliente no tiene.
 *
 * `admin_id` sin FK y nullable: el que importa la planilla no es un admin, y un admin borrado no
 * puede borrar el historial de precios de un cliente.
 */
class CreateMensualidadActualizacionesTable extends Migration
{
    /**
     * Crea la tabla.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('mensualidad_actualizaciones', function (Blueprint $table) {
            $table->id();

            // Cliente al que se le actualizaron los precios. Indexado: siempre se lista por cliente.
            $table->unsignedBigInteger('client_id')->index('mens_act_client_idx');

            // Quién la registró. Nulo cuando la escribe un comando.
            $table->unsignedBigInteger('admin_id')->nullable();

            // Desde cuándo rigen estos precios (fecha, no timestamp: se carga a mano y puede ser pasada).
            $table->date('fecha');

            // Los cinco precios tal como quedaron. Los dos primeros son obligatorios en el cliente.
            $table->decimal('precio_plan', 12, 2);
            $table->decimal('precio_por_cuenta', 12, 2);
            $table->decimal('precio_ecommerce', 12, 2)->nullable();
            $table->decimal('precio_mercado_libre', 12, 2)->nullable();
            $table->decimal('precio_tienda_nube', 12, 2)->nullable();

            // Si es la actualización oficial de la mensualidad (la de IPC, la que cuenta para el contrato).
            $table->boolean('es_oficial')->default(false);

            // Motivo o nota libre ("IPC ago-2026", "le bajamos el ML porque no lo usa").
            $table->text('observacion')->nullable();

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
        Schema::dropIfExists('mensualidad_actualizaciones');
    }
}

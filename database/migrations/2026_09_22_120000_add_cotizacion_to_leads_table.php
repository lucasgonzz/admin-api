<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `leads` la foto de la cotización del sistema hecha desde la solapa Contrato.
 *
 * Guarda con qué números se armó el link de pago de Mercado Pago: qué sistemas se cotizaron, a
 * qué precio en dólares, con qué cotización del dólar, el total en las dos monedas y el link
 * generado. El motivo es de auditoría: una cotización hecha con el dólar de hoy se manda por
 * WhatsApp y se paga días después, así que tiene que poder reconstruirse contra qué se le ofreció
 * al lead sin depender de lo que muestre el panel de Mercado Pago.
 *
 * 🔴 Todas nullable y sin default obligatorio: un lead viejo no se entera, y el `PUT /lead/{id}`
 * de siempre sigue guardando igual. El despliegue no es atómico (el SPA viejo le pega a esta API
 * nueva un rato), así que nada de lo que ya existía se renombra ni se saca.
 */
class AddCotizacionToLeadsTable extends Migration
{
    /**
     * Agrega las siete columnas de la cotización a `leads`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Foto de qué sistemas entraron y a qué precio USD: [{key, label, precio_usd, precio_ars}].
            // JSON y no columnas sueltas porque el catálogo de sistemas puede crecer (hoy son tres)
            // y porque lo que importa es el detalle tal cual se le mostró al lead, no poder filtrar.
            $table->json('contract_cotizacion_items')->nullable()->after('contract_clausulas_particulares');

            // Cotización del dólar usada, cargada a mano por quien cotiza. No hay fuente automática
            // y es a propósito: el precio que se acuerda con el lead no siempre es el dólar del día.
            $table->decimal('contract_cotizacion_dolar', 12, 2)->nullable()->after('contract_cotizacion_items');

            // Totales de esa cotización en las dos monedas. Decimal y no string como
            // contract_precio_licencia: acá los números los calcula el servidor, no se tipean.
            $table->decimal('contract_cotizacion_total_usd', 12, 2)->nullable()->after('contract_cotizacion_dolar');
            $table->decimal('contract_cotizacion_total_ars', 14, 2)->nullable()->after('contract_cotizacion_total_usd');

            // init_point de la preferencia: el link que se le manda al lead. 500 y no 255 porque es
            // una URL de Mercado Pago con el pref_id adentro y no hay contrato de largo máximo.
            $table->string('contract_cotizacion_link_pago', 500)->nullable()->after('contract_cotizacion_total_ars');

            // Id de la preferencia, para poder buscar el pago en el panel de Mercado Pago. Es lo
            // único que permite atar un cobro a este lead mientras no haya webhook.
            $table->string('contract_cotizacion_preference_id', 100)->nullable()->after('contract_cotizacion_link_pago');

            // Cuándo se generó. Sirve para saber si el link ya venció sin tener que preguntárselo
            // a Mercado Pago (la preferencia se crea con vencimiento).
            $table->timestamp('contract_cotizacion_generada_at')->nullable()->after('contract_cotizacion_preference_id');
        });
    }

    /**
     * Elimina las columnas de la cotización de `leads`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'contract_cotizacion_items',
                'contract_cotizacion_dolar',
                'contract_cotizacion_total_usd',
                'contract_cotizacion_total_ars',
                'contract_cotizacion_link_pago',
                'contract_cotizacion_preference_id',
                'contract_cotizacion_generada_at',
            ]);
        });
    }
}

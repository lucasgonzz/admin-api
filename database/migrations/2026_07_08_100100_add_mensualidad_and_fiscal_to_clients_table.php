<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a `clients` los inputs de mensualidad (gestionados a mano en admin)
 * y los datos fiscales del receptor para facturar.
 *
 * La mensualidad se calcula y gestiona directamente en admin (sin depender de
 * que empresa-api tenga la versión con admin-sync). El cálculo del total va
 * en el prompt 329, la UI en el 334 y la facturación en el 331. Este cambio
 * solo agrega columnas, sin lógica.
 */
class AddMensualidadAndFiscalToClientsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            // Inputs de mensualidad (gestionados en admin, editables a mano por Lucas).
            $table->decimal('precio_plan', 12, 2)->nullable()->after('user_id');
            $table->decimal('precio_por_cuenta', 12, 2)->nullable()->after('precio_plan');
            // Cantidad de cuentas empleado: manual para clientes viejos; la sobreescribe
            // el sync opcional del prompt 335 si el cliente ya está actualizado.
            $table->integer('cantidad_empleados')->default(0)->after('precio_por_cuenta');
            $table->boolean('tiene_ecommerce')->default(false)->after('cantidad_empleados');
            $table->boolean('tiene_mercado_libre')->default(false)->after('tiene_ecommerce');
            $table->boolean('tiene_tienda_nube')->default(false)->after('tiene_mercado_libre');
            // Si estos precios específicos son null, el cálculo (prompt 329) usa precio_por_cuenta como fallback.
            $table->decimal('precio_ecommerce', 12, 2)->nullable()->after('tiene_tienda_nube');
            $table->decimal('precio_mercado_libre', 12, 2)->nullable()->after('precio_ecommerce');
            $table->decimal('precio_tienda_nube', 12, 2)->nullable()->after('precio_mercado_libre');
            // Total calculado por admin (prompt 329); no se ingresa a mano.
            $table->decimal('total_mensualidad', 12, 2)->nullable()->after('precio_tienda_nube');
            // Fecha de próximo pago (referencia de admin); para clientes actualizados se
            // puede empujar al cliente vía 335, para los viejos Lucas la sigue actualizando a mano.
            $table->date('payment_expired_at')->nullable()->after('total_mensualidad');

            // Datos fiscales del receptor (para facturar).
            $table->string('afip_cuit', 50)->nullable()->after('payment_expired_at');
            $table->string('afip_razon_social', 120)->nullable()->after('afip_cuit');
            $table->string('afip_condicion_iva', 60)->nullable()->after('afip_razon_social');
            $table->string('afip_domicilio', 200)->nullable()->after('afip_condicion_iva');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'precio_plan',
                'precio_por_cuenta',
                'cantidad_empleados',
                'tiene_ecommerce',
                'tiene_mercado_libre',
                'tiene_tienda_nube',
                'precio_ecommerce',
                'precio_mercado_libre',
                'precio_tienda_nube',
                'total_mensualidad',
                'payment_expired_at',
                'afip_cuit',
                'afip_razon_social',
                'afip_condicion_iva',
                'afip_domicilio',
            ]);
        });
    }
}

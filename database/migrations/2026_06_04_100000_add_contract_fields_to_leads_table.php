<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de contrato ComercioCity por lead (datos dinámicos para PDF).
 *
 * Sin FK declarativa: valores editables desde admin y consumidos por el generador de contrato.
 * `contract_financiacion` almacena JSON: array de cuotas [{monto, fecha}].
 */
class AddContractFieldsToLeadsTable extends Migration
{
    /**
     * Agrega columnas de contrato a `leads` después de `status`.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('leads', function (Blueprint $table) {
            // Datos del cliente para el contrato
            $table->string('contract_client_name')->nullable()->after('status');
            $table->string('contract_client_razon_social')->nullable()->after('contract_client_name');
            $table->string('contract_client_cuit')->nullable()->after('contract_client_razon_social');

            // Pago único
            $table->string('contract_currency')->nullable()->after('contract_client_cuit');
            $table->string('contract_precio_licencia')->nullable()->after('contract_currency');
            $table->date('contract_fecha_emision')->nullable()->after('contract_precio_licencia');
            $table->date('contract_fecha_primer_pago_unico')->nullable()->after('contract_fecha_emision');

            // Financiación — JSON con array de cuotas [{monto, fecha}]
            $table->json('contract_financiacion')->nullable()->after('contract_fecha_primer_pago_unico');

            // Mensualidad
            $table->string('contract_mensualidad_moneda')->nullable()->after('contract_financiacion');
            $table->string('contract_mensualidad_base')->nullable()->after('contract_mensualidad_moneda');
            $table->integer('contract_usuarios_incluidos')->nullable()->after('contract_mensualidad_base');
            $table->integer('contract_usuarios_extra')->nullable()->default(0)->after('contract_usuarios_incluidos');
            $table->string('contract_precio_usuario_extra')->nullable()->after('contract_usuarios_extra');
            $table->integer('contract_perfiles_ecommerce')->nullable()->default(0)->after('contract_precio_usuario_extra');
            $table->string('contract_precio_perfil_ecommerce')->nullable()->after('contract_perfiles_ecommerce');
            $table->date('contract_fecha_primer_pago_mensual')->nullable()->after('contract_precio_perfil_ecommerce');
        });
    }

    /**
     * Elimina todas las columnas de contrato de `leads`.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'contract_client_name',
                'contract_client_razon_social',
                'contract_client_cuit',
                'contract_currency',
                'contract_precio_licencia',
                'contract_fecha_emision',
                'contract_fecha_primer_pago_unico',
                'contract_financiacion',
                'contract_mensualidad_moneda',
                'contract_mensualidad_base',
                'contract_usuarios_incluidos',
                'contract_usuarios_extra',
                'contract_precio_usuario_extra',
                'contract_perfiles_ecommerce',
                'contract_precio_perfil_ecommerce',
                'contract_fecha_primer_pago_mensual',
            ]);
        });
    }
}

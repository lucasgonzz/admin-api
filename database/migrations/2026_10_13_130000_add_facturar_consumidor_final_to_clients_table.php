<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Factura de mensualidad a Consumidor Final, sin CUIT (misión mensualidad-consumidor-final,
 * 13/10/2026).
 *
 * `facturar_consumidor_final` es un tilde por cliente que se prende a mano en la tarjeta
 * Facturación del modal. Con el tilde en 1, `AfipFacturacionService::emitir()` arma la Factura C
 * con DocTipo 99 / DocNro 0 / CondicionIVAReceptorId 5 y NO mira `afip_cuit` ni
 * `afip_condicion_iva` (el dato queda cargado por si el tilde se destilda).
 *
 * 🔴 Default 0 y NOT NULL a propósito: el parque entero de clientes tiene que seguir facturando
 * con CUIT como hasta hoy, y un NULL obligaría a que cada lectura distinga "nunca decidido" de
 * "apagado" cuando no hay ninguna diferencia entre los dos. Sin foreign keys ni índices: no se
 * filtra por esta columna en ningún camino.
 */
class AddFacturarConsumidorFinalToClientsTable extends Migration
{
    /**
     * Agrega `clients.facturar_consumidor_final` (si todavía no existe).
     *
     * El `hasColumn()` deja volver a correr la migración sobre una base donde ya quedó a medias.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('clients', 'facturar_consumidor_final')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            // 0 = factura con CUIT (el camino de siempre). 1 = Consumidor Final sin identificar.
            $table->boolean('facturar_consumidor_final')->default(false);
        });
    }

    /**
     * Saca la columna (si está).
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasColumn('clients', 'facturar_consumidor_final')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('facturar_consumidor_final');
        });
    }
}

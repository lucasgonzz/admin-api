<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: tabla de opciones de métodos de pago para el formulario de implementación.
 *
 * Crea el catálogo de métodos de pago disponibles (Efectivo, Débito, Crédito, Transferencia, Cheque, Mercado Pago)
 * que se exponen en el select del formulario público de configuración de implementación.
 * Esta tabla es una lista de referencia estática idempotente.
 */
class CreateImplementationPaymentMethodOptionsTable extends Migration
{
    /**
     * Ejecuta la migración (crear tabla).
     */
    public function up()
    {
        Schema::create('implementation_payment_method_options', function (Blueprint $table) {
            // Clave primaria.
            $table->id();

            // Valor estable del select (ej: 'efectivo', 'debito'). Clave de unicidad para idempotencia del seeder.
            $table->string('key', 50)->unique();

            // Texto visible en la UI del formulario (ej: 'Efectivo', 'Débito').
            $table->string('label', 100);

            // Orden de aparición en el select (nullable para permitir ordenamiento manual después si es necesario).
            $table->integer('position')->nullable();

            // Timestamps de creación/actualización.
            $table->timestamps();

            // Índice para búsquedas por key (aunque ya es unique).
            $table->index('key');
        });
    }

    /**
     * Revierte la migración (elimina tabla).
     */
    public function down()
    {
        Schema::dropIfExists('implementation_payment_method_options');
    }
}

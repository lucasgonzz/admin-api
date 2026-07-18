<?php

namespace Database\Seeders;

use App\Models\ImplementationPaymentMethodOption;
use Illuminate\Database\Seeder;

/**
 * Catálogo de métodos de pago para el formulario de configuración de implementación.
 *
 * Siembra los 6 métodos de pago estándar (Efectivo, Débito, Crédito, Transferencia, Cheque, Mercado Pago)
 * como opciones seleccionables en el formulario público.
 *
 * IMPORTANTE: Esta lista es ESPEJO EXACTO del CurrentAcountPaymentMethodSeeder de empresa-api.
 * Si en empresa-api se agrega o saca un método de pago por defecto, hay que reflejarlo aquí
 * y actualizar el mapa de keys en grupo 110. Las dos listas deben mantenerse sincronizadas.
 *
 * Usa firstOrCreate por `key` para ser idempotente: se puede correr múltiples veces sin duplicar.
 * Ideal para: `php artisan db:seed --class=ImplementationPaymentMethodOptionSeeder`
 */
class ImplementationPaymentMethodOptionSeeder extends Seeder
{
    /**
     * Ejecuta el seeder de métodos de pago.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Métodos de pago por defecto. Mantener en SINCRONÍA con CurrentAcountPaymentMethodSeeder (empresa-api).
         * Cada fila define: key (valor estable), label (texto visible), position (orden).
         */
        $payment_methods = [
            [
                'key'      => 'efectivo',
                'label'    => 'Efectivo',
                'position' => 1,
            ],
            [
                'key'      => 'debito',
                'label'    => 'Débito',
                'position' => 2,
            ],
            [
                'key'      => 'credito',
                'label'    => 'Crédito',
                'position' => 3,
            ],
            [
                'key'      => 'transferencia',
                'label'    => 'Transferencia',
                'position' => 4,
            ],
            [
                'key'      => 'cheque',
                'label'    => 'Cheque',
                'position' => 5,
            ],
            [
                'key'      => 'mercado_pago',
                'label'    => 'Mercado Pago',
                'position' => 6,
            ],
        ];

        // Idempotencia: usar firstOrCreate por key para no duplicar filas si el seeder se corre otra vez.
        foreach ($payment_methods as $method_data) {
            ImplementationPaymentMethodOption::firstOrCreate(
                ['key' => $method_data['key']],
                ['label' => $method_data['label'], 'position' => $method_data['position']]
            );
        }
    }
}

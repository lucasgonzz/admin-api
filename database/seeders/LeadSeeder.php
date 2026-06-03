<?php

namespace Database\Seeders;

use App\Models\Lead;
use Illuminate\Database\Seeder;

class LeadSeeder extends Seeder
{
    /**
     * Ejecuta la siembra de un lead base para pruebas.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Datos base del lead solicitado para disponer de un registro inicial
         * en entornos de desarrollo.
         */
        $lead_data = [
            /**
             * Se persiste en contact_name porque la tabla leads no tiene
             * columna name y centraliza el dato de nombre de contacto aquí.
             */
            'contact_name' => 'Lucas',
            'email'        => 'lucasgonzalez5500@gmail.com',
        ];

        /**
         * Se usa updateOrCreate para evitar duplicados al correr el seeder
         * múltiples veces.
         */
        Lead::updateOrCreate(
            ['email' => $lead_data['email']],
            $lead_data
        );
    }
}

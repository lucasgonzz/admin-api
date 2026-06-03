<?php

namespace Database\Seeders;

use App\Models\ClientSshCredential;
use Illuminate\Database\Seeder;

/**
 * Credenciales SSH globales por tipo de hosting (shared_hosting, vps).
 * Una fila por tipo; usadas por DeploymentService en upgrades remotos.
 */
class ClientSshCredentialSeeder extends Seeder
{
    /**
     * Ejecuta la carga de credenciales SSH para entorno local / desarrollo.
     *
     * @return void
     */
    public function run()
    {
        /**
         * Catálogo fijo: DeploymentService exige exactamente estos tipos (firstOrFail).
         * Valores por defecto son placeholders; en producción conviene definir las variables
         * de entorno SSH_* o actualizar las filas desde el panel cuando exista ABM.
         */
        $credentials = [
            [
                'type' => 'shared_hosting',
                'host' => env('SSH_SHARED_HOSTING_HOST', 'localhost'),
                'port' => (int) env('SSH_SHARED_HOSTING_PORT', 22),
                'username' => env('SSH_SHARED_HOSTING_USERNAME', 'deploy'),
                'password' => env('SSH_SHARED_HOSTING_PASSWORD', 'changeme'),
            ],
            [
                'type' => 'vps',
                'host' => env('SSH_VPS_HOST', 'localhost'),
                'port' => (int) env('SSH_VPS_PORT', 22),
                'username' => env('SSH_VPS_USERNAME', 'build'),
                'password' => env('SSH_VPS_PASSWORD', 'changeme'),
            ],
        ];

        foreach ($credentials as $credential_data) {
            // Tipo de hosting como clave de unicidad (una fila por type).
            $type = $credential_data['type'];

            ClientSshCredential::updateOrCreate(
                ['type' => $type],
                $credential_data
            );

            if ($this->command !== null) {
                $this->command->info("Credencial SSH lista: {$type} @ {$credential_data['host']}:{$credential_data['port']}");
            }
        }
    }
}

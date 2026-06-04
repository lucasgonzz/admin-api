<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientEmployee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Importa empleados desde el empresa-api del cliente hacia client_employees en admin-api.
 *
 * Solo crea registros nuevos o actualiza los que ya tienen empresa_employee_id.
 * Los contactos creados manualmente en admin (sin empresa_employee_id) no se modifican.
 */
class SyncClientEmployeesFromEmpresaService
{
    /**
     * Ruta relativa del listado de empleados en empresa-api (admin-sync).
     */
    const EMPLOYEES_PATH = 'api/admin-sync/employees';

    /**
     * @var ClientEmpresaApiUrlResolver
     */
    protected $api_url_resolver;

    /**
     * @param ClientEmpresaApiUrlResolver|null $api_url_resolver
     */
    public function __construct(?ClientEmpresaApiUrlResolver $api_url_resolver = null)
    {
        $this->api_url_resolver = $api_url_resolver ?? new ClientEmpresaApiUrlResolver();
    }

    /**
     * Sincroniza empleados del cliente desde su empresa-api.
     *
     * @param Client $client Cliente admin con api_key y URL de empresa-api configuradas.
     *
     * @return array{created: int, updated: int, employees: \Illuminate\Support\Collection, error: string|null}
     */
    public function sync(Client $client): array
    {
        /** URL GET de empleados en el empresa-api del cliente. */
        $sync_url = $this->api_url_resolver->admin_sync_url($client, self::EMPLOYEES_PATH);

        if ($sync_url === '') {
            return $this->error_result(
                'No hay URL válida del empresa-api. Configure una ClientApi (URL con http/https) '
                . 'y asígnela como API activa.'
            );
        }

        if (empty($client->api_key)) {
            return $this->error_result(
                'El cliente no tiene api_key configurada (debe coincidir con ADMIN_API_INBOUND_KEY en empresa-api).'
            );
        }

        try {
            /** Respuesta JSON con models[] desde admin-sync/employees. */
            $response = Http::withHeaders([
                    'X-Admin-Api-Key' => $client->api_key,
                    'Accept'          => 'application/json',
                ])
                ->timeout((int) config('services.client_api.timeout', 15))
                ->retry((int) config('services.client_api.retries', 2), 500)
                ->get($sync_url);

            if (! $response->successful()) {
                Log::warning(
                    'SyncClientEmployeesFromEmpresaService: status '
                    . $response->status()
                    . ' body '
                    . $response->body()
                );

                return $this->error_result(
                    'No se pudo obtener empleados del empresa-api (HTTP ' . $response->status() . ').'
                );
            }

            /** Lista remota de empleados { id, name, phone }. */
            $remote_employees = $response->json('models', []);
            if (! is_array($remote_employees)) {
                $remote_employees = [];
            }

            /** Contadores para el resumen de la operación. */
            $created_count = 0;
            $updated_count = 0;

            foreach ($remote_employees as $remote_row) {
                if (! is_array($remote_row)) {
                    continue;
                }

                /** Id del User en empresa-api. */
                $empresa_employee_id = isset($remote_row['id']) ? (int) $remote_row['id'] : 0;
                if ($empresa_employee_id <= 0) {
                    continue;
                }

                /** Nombre y teléfono a persistir en admin. */
                $name = trim((string) ($remote_row['name'] ?? ''));
                $phone = trim((string) ($remote_row['phone'] ?? ''));

                if ($name === '') {
                    continue;
                }

                /** Registro ya vinculado por sincronización previa. */
                $existing = ClientEmployee::query()
                    ->where('client_id', $client->id)
                    ->where('empresa_employee_id', $empresa_employee_id)
                    ->first();

                if ($existing instanceof ClientEmployee) {
                    $existing->name = $name;
                    $existing->phone = $phone;
                    $existing->save();
                    $updated_count++;
                    continue;
                }

                /** Alta de contacto nuevo vinculado al id de empresa-api. */
                $client->client_employees()->create([
                    'name'                => $name,
                    'phone'               => $phone,
                    'empresa_employee_id' => $empresa_employee_id,
                ]);
                $created_count++;
            }

            /** Colección actualizada para devolver al SPA. */
            $employees = $client->client_employees()->orderBy('name')->get();

            return [
                'created'    => $created_count,
                'updated'    => $updated_count,
                'employees'  => $employees,
                'error'      => null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('SyncClientEmployeesFromEmpresaService exception: ' . $exception->getMessage());

            return $this->error_result(
                'Error al conectar con empresa-api: ' . $exception->getMessage()
            );
        }
    }

    /**
     * Arma resultado de error uniforme.
     *
     * @param string $message Mensaje para el operador.
     *
     * @return array{created: int, updated: int, employees: \Illuminate\Support\Collection, error: string}
     */
    protected function error_result(string $message): array
    {
        return [
            'created'   => 0,
            'updated'   => 0,
            'employees' => collect(),
            'error'     => $message,
        ];
    }
}

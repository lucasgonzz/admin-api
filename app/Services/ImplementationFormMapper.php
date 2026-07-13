<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientEmployee;
use App\Models\Implementation;
use App\Models\ImplementationStage;
use Illuminate\Support\Facades\Log;

/**
 * Traduce las respuestas del formulario web de la Etapa 1 (implementation_stages.data.form_responses)
 * a las estructuras que consume el resto del sistema:
 *
 *  - client.setup_data              → payload del UserSetup remoto en empresa-api.
 *  - client_employees               → empleados del cliente (Etapas 2 y 6).
 *  - implementation.migration_contact_phone → destinatario de la Etapa 3 (recolección de archivos).
 *
 * También construye el resumen legible (`build_summary`) que muestra el panel de admin.
 */
class ImplementationFormMapper
{
    /**
     * Aplica todo el mapeo de una implementación cuyo formulario ya fue enviado.
     *
     * Idempotente: se puede volver a ejecutar sin duplicar empleados ni romper nada.
     *
     * @param Implementation $implementation Implementación con el formulario enviado.
     *
     * @return void
     */
    public function apply(Implementation $implementation): void
    {
        // Respuestas crudas del formulario web; sin ellas no hay nada que mapear.
        $form = $this->read_form_responses($implementation);

        if (empty($form)) {
            Log::channel('daily')->warning('ImplementationFormMapper: sin form_responses para mapear.', [
                'implementation_id' => $implementation->id,
            ]);

            return;
        }

        // Cliente dueño de la implementación: destino de setup_data y de los empleados.
        $client = $implementation->client ?? Client::find($implementation->client_id);

        if ($client === null) {
            return;
        }

        // Mergear sobre lo que ya exista en setup_data (p. ej. logo_url cargado por otro flujo).
        $existing   = is_array($client->setup_data) ? $client->setup_data : [];
        $setup_data = array_merge($existing, $this->build_setup_data($form));

        $client->setup_data = $setup_data;
        $client->save();

        $this->sync_employees($client, $form);
        $this->resolve_migration_contact($implementation, $client, $form);

        Log::channel('daily')->info('ImplementationFormMapper: formulario mapeado.', [
            'implementation_id'       => $implementation->id,
            'client_id'               => $client->id,
            'migration_contact_phone' => $implementation->migration_contact_phone,
        ]);
    }

    /**
     * Lee las respuestas del formulario web desde el stage 1 de la implementación.
     *
     * @param Implementation $implementation
     *
     * @return array<string, mixed>
     */
    public function read_form_responses(Implementation $implementation): array
    {
        $stage = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', 1)
            ->first();

        if ($stage === null || ! is_array($stage->data)) {
            return [];
        }

        $responses = $stage->data['form_responses'] ?? null;

        return is_array($responses) ? $responses : [];
    }

    /**
     * Construye el array setup_data que consume ImplementationUserSetupService::build_payload().
     *
     * @param array<string, mixed> $form Respuestas crudas del formulario.
     *
     * @return array<string, mixed>
     */
    public function build_setup_data(array $form): array
    {
        // Filas de las tablas del formulario (pueden venir vacías o ausentes).
        $price_rows   = is_array($form['price_lists'] ?? null) ? $form['price_lists'] : [];
        $deposit_rows = is_array($form['deposit_names'] ?? null) ? $form['deposit_names'] : [];
        $discounts    = is_array($form['payment_discounts'] ?? null) ? $form['payment_discounts'] : [];

        // Redes sociales en texto libre: se intenta separar instagram y facebook.
        $social = (string) ($form['social_networks'] ?? '');

        return [
            // Empresa.
            'company_name'    => (string) ($form['company_name'] ?? ''),
            'address_company' => (string) ($form['address_company'] ?? ''),
            'social_networks' => $social,
            'instagram'       => $this->extract_social($social, 'instagram'),
            'facebook'        => $this->extract_social($social, 'facebook'),

            // Precios: la bandera para el sistema y los nombres en texto para price_type_1..3.
            'use_price_lists'            => ((string) ($form['price_mode'] ?? '')) === 'lists',
            'price_lists'                => $this->rows_to_string($price_rows),
            // Detalle completo (con márgenes) para que el admin lo vea; empresa-api lo ignora.
            'price_lists_detail'         => $price_rows,
            'cotizar_precios_en_dolares' => ((string) ($form['dollar_prices'] ?? '')) === 'yes',

            // Stock: bandera + nombres en texto para address_1..3.
            'use_deposits'  => ((string) ($form['stock_mode'] ?? '')) === 'deposits',
            'deposit_names' => $this->rows_to_string($deposit_rows),

            // Ventas.
            'iva_included'                       => ((string) ($form['apply_iva'] ?? 'yes')) === 'yes',
            'ask_amount_in_vender'               => ((string) ($form['ask_quantity'] ?? '')) === 'ask',
            'siempre_omitir_en_cuenta_corriente' => ((string) ($form['default_cuenta_corriente'] ?? '')) !== 'default_on',
            'payment_discounts'                  => $discounts,
        ];
    }

    /**
     * Convierte las filas de una tabla del formulario en una cadena separada por comas
     * con el campo `name` de cada fila (formato que espera split_to_named_fields()).
     *
     * @param array<int, mixed> $rows
     *
     * @return string
     */
    private function rows_to_string(array $rows): string
    {
        $names = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Busca en el texto libre de redes sociales la línea correspondiente a una red.
     *
     * @param string $text    Texto libre cargado por el cliente.
     * @param string $network 'instagram' | 'facebook'.
     *
     * @return string Línea encontrada o cadena vacía.
     */
    private function extract_social(string $text, string $network): string
    {
        $lines = preg_split('/[\r\n,;]+/', $text) ?: [];

        foreach ($lines as $line) {
            $clean = trim((string) $line);

            if ($clean === '') {
                continue;
            }

            // PHP 7.4: sin str_contains.
            if (stripos($clean, $network) !== false) {
                return $clean;
            }
        }

        return '';
    }

    /**
     * Crea los ClientEmployee de la tabla `employees` del formulario.
     *
     * Idempotente: no crea un empleado si ya existe uno con el mismo nombre para ese cliente.
     *
     * @param Client               $client
     * @param array<string, mixed> $form
     *
     * @return void
     */
    private function sync_employees(Client $client, array $form): void
    {
        $rows = is_array($form['employees'] ?? null) ? $form['employees'] : [];

        if (empty($rows)) {
            return;
        }

        // Nombres ya cargados para este cliente, normalizados para comparar sin duplicar.
        $existing_names = [];

        ClientEmployee::where('client_id', $client->id)->get()->each(function ($employee) use (&$existing_names) {
            $existing_names[] = mb_strtolower(trim((string) $employee->name));
        });

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            // No duplicar empleados ya cargados (reejecución del mapper, o carga manual previa).
            if (in_array(mb_strtolower($name), $existing_names, true)) {
                continue;
            }

            $phone    = trim((string) ($row['phone'] ?? ''));
            $document = trim((string) ($row['dni'] ?? ''));

            // Normalizar el teléfono argentino a E.164 cuando se cargó alguno.
            $normalized_phone = $phone !== ''
                ? \App\Services\ArgentinePhoneNormalizer::normalize($phone)
                : '';

            ClientEmployee::create([
                'client_id' => $client->id,
                'name'      => $name,
                'phone'     => $normalized_phone !== '' ? $normalized_phone : $phone,
                'notes'     => $document !== '' ? "DNI: {$document}" : '',
            ]);

            $existing_names[] = mb_strtolower($name);
        }
    }

    /**
     * Resuelve el teléfono del responsable de migración y lo persiste en la implementación.
     *
     * Orden de resolución:
     *  1. Fila de `employees` cuyo `name` coincide con `migration_responsible`.
     *  2. ClientEmployee del cliente con ese mismo nombre.
     *  3. Teléfono del cliente (dueño) como fallback.
     *
     * @param Implementation       $implementation
     * @param Client               $client
     * @param array<string, mixed> $form
     *
     * @return void
     */
    private function resolve_migration_contact(
        Implementation $implementation,
        Client $client,
        array $form
    ): void {
        $responsible = trim((string) ($form['migration_responsible'] ?? ''));
        $phone       = '';

        if ($responsible !== '') {
            $rows = is_array($form['employees'] ?? null) ? $form['employees'] : [];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $name = trim((string) ($row['name'] ?? ''));

                if ($name !== '' && mb_strtolower($name) === mb_strtolower($responsible)) {
                    $phone = trim((string) ($row['phone'] ?? ''));
                    break;
                }
            }

            // Segundo intento: buscarlo entre los empleados ya cargados del cliente.
            if ($phone === '') {
                $employee = ClientEmployee::where('client_id', $client->id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($responsible)])
                    ->first();

                if ($employee !== null) {
                    $phone = trim((string) ($employee->phone ?? ''));
                }
            }
        }

        // Fallback: el dueño del negocio.
        if ($phone === '') {
            $phone = trim((string) ($client->phone ?? ''));
        }

        if ($phone === '') {
            return;
        }

        $normalized = \App\Services\ArgentinePhoneNormalizer::normalize($phone);

        $implementation->migration_contact_phone = $normalized !== '' ? $normalized : $phone;
        $implementation->save();
    }

    /**
     * Construye el resumen legible de las respuestas del formulario para el panel de admin.
     *
     * @param array<string, mixed> $form Respuestas crudas del formulario.
     *
     * @return array<int, array<string, string>> Lista de { section, label, value }.
     */
    public function build_summary(array $form): array
    {
        if (empty($form)) {
            return [];
        }

        $summary = [];

        // --- Precios ---
        $price_mode = (string) ($form['price_mode'] ?? '');

        if ($price_mode !== '') {
            $summary[] = [
                'section' => 'Precios',
                'label'   => 'Manejo de precios',
                'value'   => $price_mode === 'lists'
                    ? 'Varias listas de precios'
                    : 'Un único precio de venta por producto',
            ];
        }

        $price_rows = is_array($form['price_lists'] ?? null) ? $form['price_lists'] : [];

        if (! empty($price_rows)) {
            $lines = [];

            foreach ($price_rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $name   = trim((string) ($row['name'] ?? ''));
                $margin = $row['margin'] ?? '';

                if ($name === '') {
                    continue;
                }

                $lines[] = $margin !== '' && $margin !== null
                    ? "{$name} ({$margin}%)"
                    : $name;
            }

            if (! empty($lines)) {
                $summary[] = [
                    'section' => 'Precios',
                    'label'   => 'Listas de precios',
                    'value'   => implode(' · ', $lines),
                ];
            }
        }

        if (array_key_exists('dollar_prices', $form)) {
            $summary[] = [
                'section' => 'Precios',
                'label'   => 'Precios en dólares',
                'value'   => ((string) $form['dollar_prices']) === 'yes' ? 'Sí' : 'No',
            ];
        }

        // --- Stock ---
        $stock_mode = (string) ($form['stock_mode'] ?? '');

        if ($stock_mode !== '') {
            $summary[] = [
                'section' => 'Stock',
                'label'   => 'Administración de stock',
                'value'   => $stock_mode === 'deposits'
                    ? 'Dividido por sucursales o depósitos'
                    : 'Un único stock por producto',
            ];
        }

        $deposit_rows = is_array($form['deposit_names'] ?? null) ? $form['deposit_names'] : [];

        if (! empty($deposit_rows)) {
            $value = $this->rows_to_string($deposit_rows);

            if ($value !== '') {
                $summary[] = [
                    'section' => 'Stock',
                    'label'   => 'Sucursales / depósitos',
                    'value'   => $value,
                ];
            }
        }

        // --- Ventas ---
        $discounts = is_array($form['payment_discounts'] ?? null) ? $form['payment_discounts'] : [];

        if (! empty($discounts)) {
            $lines = [];

            foreach ($discounts as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $method     = trim((string) ($row['method'] ?? ''));
                $type       = trim((string) ($row['type'] ?? ''));
                $percentage = $row['percentage'] ?? '';

                if ($method === '') {
                    continue;
                }

                $lines[] = "{$method}: {$type} {$percentage}%";
            }

            if (! empty($lines)) {
                $summary[] = [
                    'section' => 'Ventas',
                    'label'   => 'Descuentos / recargos por método de pago',
                    'value'   => implode(' · ', $lines),
                ];
            }
        }

        if (array_key_exists('apply_iva', $form)) {
            $summary[] = [
                'section' => 'Ventas',
                'label'   => 'IVA en los precios',
                'value'   => ((string) $form['apply_iva']) === 'yes' ? 'Aplicar IVA' : 'No aplicar IVA',
            ];
        }

        if (array_key_exists('ask_quantity', $form)) {
            $summary[] = [
                'section' => 'Ventas',
                'label'   => 'Cantidad al cargar una venta',
                'value'   => ((string) $form['ask_quantity']) === 'ask'
                    ? 'Preguntar siempre la cantidad'
                    : 'Agregar siempre 1 unidad',
            ];
        }

        if (array_key_exists('default_cuenta_corriente', $form)) {
            $summary[] = [
                'section' => 'Ventas',
                'label'   => 'Cuenta corriente por defecto',
                'value'   => ((string) $form['default_cuenta_corriente']) === 'default_on'
                    ? 'Sí, la venta va a cuenta corriente por defecto'
                    : 'No, se indica manualmente',
            ];
        }

        // --- Empresa ---
        foreach ([
            'company_name'    => 'Nombre de la empresa',
            'address_company' => 'Dirección',
            'social_networks' => 'Redes sociales',
        ] as $key => $label) {
            $value = trim((string) ($form[$key] ?? ''));

            if ($value !== '') {
                $summary[] = [
                    'section' => 'Empresa',
                    'label'   => $label,
                    'value'   => $value,
                ];
            }
        }

        // --- Equipo ---
        $employees = is_array($form['employees'] ?? null) ? $form['employees'] : [];

        foreach ($employees as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $parts = [];

            $dni   = trim((string) ($row['dni'] ?? ''));
            $phone = trim((string) ($row['phone'] ?? ''));

            if ($dni !== '') {
                $parts[] = "DNI {$dni}";
            }

            if ($phone !== '') {
                $parts[] = $phone;
            }

            $summary[] = [
                'section' => 'Equipo',
                'label'   => 'Empleado',
                'value'   => empty($parts) ? $name : $name . ' — ' . implode(' — ', $parts),
            ];
        }

        $responsible = trim((string) ($form['migration_responsible'] ?? ''));

        if ($responsible !== '') {
            $summary[] = [
                'section' => 'Equipo',
                'label'   => 'Responsable de enviar los archivos',
                'value'   => $responsible,
            ];
        }

        return $summary;
    }
}

<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\Lead;

/**
 * Fuente única de verdad sobre qué teléfonos pertenecen a un cliente.
 *
 * Replica exactamente las tres fuentes que ya usa WhatsappWebhookController para enrutar
 * un mensaje entrante a soporte: los empleados del cliente, el teléfono de la ficha, y
 * como último recurso el lead que fue promovido a ese cliente. Si un número no está en
 * ninguna de las tres, el webhook no lo va a reconocer como cliente y la respuesta va a
 * caer en el pipeline de leads: por eso el alta de conversación valida contra acá.
 */
class ClientPhoneDirectory
{
    /**
     * Lista los teléfonos elegibles de un cliente, listos para mostrar en un select.
     *
     * @param Client $client Cliente activo del que se quieren los contactos.
     *
     * @return array<int, array<string, mixed>> Filas con label, phone (E.164), raw_phone,
     *                                          client_employee_id y origin.
     */
    public function phones_for_client(Client $client): array
    {
        $rows = [];
        $seen = [];

        // Empleados primero: el webhook también los prioriza, así el ticket queda con
        // client_employee_id y el hilo no se parte cuando el empleado responde.
        $client->loadMissing('client_employees');
        foreach ($client->client_employees as $client_employee) {
            $raw_phone = trim((string) ($client_employee->phone ?? ''));
            if ($raw_phone === '') {
                continue;
            }

            $normalized = WhatsappNormalizer::normalize($raw_phone);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $employee_name = trim((string) ($client_employee->name ?? ''));

            $seen[$normalized] = true;
            $rows[] = [
                'label'              => $employee_name !== '' ? $employee_name : 'Empleado',
                'phone'              => $normalized,
                'raw_phone'          => $raw_phone,
                'client_employee_id' => (int) $client_employee->id,
                'origin'             => 'employee',
            ];
        }

        // Teléfono de la ficha del cliente.
        $client_phone = trim((string) ($client->phone ?? ''));
        if ($client_phone !== '') {
            $normalized = WhatsappNormalizer::normalize($client_phone);
            if ($normalized !== '' && ! isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $rows[] = [
                    'label'              => $client->resolve_display_name(),
                    'phone'              => $normalized,
                    'raw_phone'          => $client_phone,
                    'client_employee_id' => null,
                    'origin'             => 'client',
                ];
            }
        }

        // Lead promovido: el webhook lo usa como fallback cuando clients.phone está vacío.
        $promoted_leads = Lead::query()
            ->where('promoted_client_id', $client->id)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->get();

        foreach ($promoted_leads as $lead) {
            $raw_phone = trim((string) $lead->phone);
            if ($raw_phone === '') {
                continue;
            }

            $normalized = WhatsappNormalizer::normalize($raw_phone);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }

            $lead_name = trim((string) ($lead->name ?? ''));

            $seen[$normalized] = true;
            $rows[] = [
                'label'              => $lead_name !== '' ? $lead_name . ' (del lead)' : 'Contacto del lead',
                'phone'              => $normalized,
                'raw_phone'          => $raw_phone,
                'client_employee_id' => null,
                'origin'             => 'lead',
            ];
        }

        return $rows;
    }

    /**
     * Busca un teléfono dentro de los contactos del cliente.
     *
     * Compara con phones_match() y no con igualdad de strings: es el mismo criterio con el
     * que el webhook decide de quién es un número entrante, y usar otro haría que el alta
     * acepte un formato que después el webhook no reconoce.
     *
     * @param Client $client Cliente dueño de la conversación.
     * @param string $phone  Teléfono elegido por el operador, en cualquier formato.
     *
     * @return array<string, mixed>|null Fila de phones_for_client(), o null si no pertenece.
     */
    public function resolve_for_client(Client $client, string $phone)
    {
        if (trim($phone) === '') {
            return null;
        }

        foreach ($this->phones_for_client($client) as $row) {
            if (WhatsappNormalizer::phones_match((string) $row['phone'], $phone)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Indica si el cliente tiene al menos un teléfono por el que se lo pueda reconocer.
     *
     * @param Client $client Cliente a evaluar.
     *
     * @return bool
     */
    public function has_any_phone(Client $client): bool
    {
        return count($this->phones_for_client($client)) > 0;
    }
}

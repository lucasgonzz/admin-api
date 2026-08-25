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

            $lead_name = trim((string) ($lead->contact_name ?? ''));

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
     * Primero por igualdad exacta del E.164 y recién después con el phones_match() del
     * webhook: ese último cae a comparar los últimos ocho dígitos, y usarlo solo haría que un
     * cliente con dos contactos de provincias distintas resuelva al equivocado. La pasada
     * laxa se conserva para que un teléfono tipeado a mano en otro formato siga resolviendo,
     * que es el criterio con el que el webhook enruta la respuesta.
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

        $rows = $this->phones_for_client($client);
        $normalized = WhatsappNormalizer::normalize($phone);

        // Primero la igualdad exacta. El select del modal manda el número ya normalizado, así
        // que casi siempre entra por acá; sin esta pasada, un empleado de Rosario y otro de
        // Córdoba que compartan los últimos ocho dígitos se pisan entre sí.
        foreach ($rows as $row) {
            if ($normalized !== '' && (string) $row['phone'] === $normalized) {
                return $row;
            }
        }

        // Recién después el criterio laxo, que es el mismo con el que el webhook enruta: un
        // teléfono tipeado a mano en otro formato tiene que seguir resolviendo.
        foreach ($rows as $row) {
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

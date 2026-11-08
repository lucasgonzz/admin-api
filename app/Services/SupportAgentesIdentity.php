<?php

namespace App\Services;

use App\Helpers\WhatsappNormalizer;
use App\Models\Client;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportAgentesIdentity
{
    public static function firmada(Request $request, Client $client): bool
    {
        $key = (string) $client->inbound_api_key;
        $uuid = $request->input('client_uuid');
        return $client->is_active && $key !== '' && is_string($uuid)
            && hash_equals($key, (string) $request->header('X-Admin-Api-Key'))
            && hash_equals((string) $client->uuid, $uuid)
            && ! Client::where('inbound_api_key', $key)->where('id', '<>', $client->id)->exists();
    }

    public static function es_dueno(Client $client, int $user_id, bool $declara_dueno): bool
    {
        return $declara_dueno && $user_id > 0 && (int) $client->user_id === $user_id;
    }

    public static function puede_ver(SupportTicket $ticket, Client $client, int $user_id, bool $declara_dueno): bool
    {
        if ((int) $ticket->client_id !== (int) $client->id || $user_id < 1
            || ($ticket->source !== 'whatsapp' && $ticket->sistema_exclusivo_at === null)) {
            return false;
        }
        $traspasado = $ticket->sistema_exclusivo_at !== null;
        if ($traspasado && (int) $ticket->client_user_id !== $user_id) {
            return false;
        }
        if ($ticket->client_employee_id === null) {
            return self::es_dueno($client, $user_id, $declara_dueno)
                && ($traspasado || WhatsappNormalizer::equals_estricto((string) $client->phone, (string) $ticket->whatsapp_phone)
                    || WhatsappNormalizer::equals_estricto((string) $client->telefono_personal(), (string) $ticket->whatsapp_phone));
        }

        $contacto = $ticket->client_employee;
        return ! $declara_dueno && $contacto !== null && (int) $contacto->client_id === (int) $client->id
            && (int) $contacto->empresa_employee_id === $user_id
            && ($traspasado || WhatsappNormalizer::equals_estricto((string) $contacto->phone, (string) $ticket->whatsapp_phone));
    }
}

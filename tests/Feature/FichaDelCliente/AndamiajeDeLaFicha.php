<?php

namespace Tests\Feature\FichaDelCliente;

use App\Models\Client;
use App\Models\ClientSupportMemoryEntry;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Str;

/**
 * Los constructores que comparten los tests de la ficha del cliente (misión cruzada `ficha-del-cliente`,
 * 11/11/2026): clientes, tickets, mensajes, entradas de la ficha y la reserva de Martín sobre un ticket (sin pasar por
 * `claim`, que exige el circuito encendido; el mismo atajo de `HistorialDeTicketsDeSoporteParaCodexTest`).
 */
trait AndamiajeDeLaFicha
{
    /**
     * @param string $nombre El nombre.
     *
     * @return Client
     */
    protected function cliente(string $nombre = 'Ferretería Ficha'): Client
    {
        return Client::create([
            'name' => $nombre . ' ' . Str::random(4), 'company_name' => 'Negocio ' . $nombre,
            'phone' => '+549341' . random_int(1000000, 9999999), 'is_active' => true,
            'api_url' => 'https://api-ficha.test', 'api_key' => 'secreto',
        ]);
    }

    /**
     * Un ticket de WhatsApp del cliente.
     *
     * @param Client               $client      El cliente.
     * @param string               $status      open | closed.
     * @param int                  $dias        Hace cuántos días se abrió.
     * @param int|null             $empleado_id El empleado, si el contacto es un empleado.
     * @param array<string, mixed> $extra       Atributos a sumar.
     *
     * @return SupportTicket
     */
    protected function ticket(Client $client, string $status = 'open', int $dias = 0, $empleado_id = null, array $extra = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'client_id' => $client->id, 'client_employee_id' => $empleado_id, 'client_user_id' => 0,
            'status' => $status, 'source' => 'whatsapp', 'whatsapp_phone' => $client->phone,
            'opened_at' => now()->subDays($dias), 'closed_at' => $status === 'closed' ? now()->subDays($dias)->addHours(3) : null,
            'last_client_message_at' => now()->subDays($dias),
        ], $extra));
    }

    /**
     * @param SupportTicket $ticket   El ticket.
     * @param string        $cuerpo   El texto.
     * @param string        $quien    user | admin.
     * @param bool          $borrador true = borrador del agente.
     *
     * @return SupportMessage
     */
    protected function mensaje(SupportTicket $ticket, string $cuerpo, string $quien = 'user', bool $borrador = false): SupportMessage
    {
        return SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => $quien, 'kind' => 'text', 'body' => $cuerpo,
            'delivered_at' => now(), 'is_ai_suggestion_draft' => $borrador,
        ]);
    }

    /**
     * Una reserva vigente de Martín sobre el ticket.
     *
     * @param SupportTicket $ticket El ticket.
     *
     * @return string El token.
     */
    protected function reservar(SupportTicket $ticket): string
    {
        $token = Str::random(64);
        SupportCodexCase::create([
            'support_ticket_id' => $ticket->id, 'state' => 'ready', 'worker_id' => 'pc-lucas',
            'lease_hash' => hash('sha256', $token), 'lease_expires_at' => now()->addMinutes(10),
        ]);

        return $token;
    }

    /**
     * Una entrada de la ficha escrita directo en la base.
     *
     * @param Client               $client El cliente.
     * @param string               $texto  El texto.
     * @param array<string, mixed> $extra  Atributos a pisar.
     *
     * @return ClientSupportMemoryEntry
     */
    protected function entrada(Client $client, string $texto, array $extra = []): ClientSupportMemoryEntry
    {
        return ClientSupportMemoryEntry::create(array_merge([
            'client_id' => $client->id, 'categoria' => 'otro', 'texto' => $texto,
            'status' => ClientSupportMemoryEntry::STATUS_VIGENTE, 'source' => ClientSupportMemoryEntry::SOURCE_MARTIN,
        ], $extra));
    }

    /**
     * Las entradas del cliente, por estado.
     *
     * @param Client $client El cliente.
     * @param string $status El estado.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function entradas(Client $client, string $status = ClientSupportMemoryEntry::STATUS_VIGENTE)
    {
        return ClientSupportMemoryEntry::where('client_id', $client->id)->where('status', $status)->orderBy('id')->get();
    }
}

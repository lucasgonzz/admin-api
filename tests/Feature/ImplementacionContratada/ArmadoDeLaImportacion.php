<?php

namespace Tests\Feature\ImplementacionContratada;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaCicloService;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Ayudantes de los tests de la compuerta de importación (E2): un ticket abierto del dueño con un Excel adjunto en el disco falso,
 * y el pedido a `POST claude/support-codex/tickets/{id}/import-excel`.
 */
trait ArmadoDeLaImportacion
{
    /**
     * Un adjunto Excel en el último mensaje del ticket, con su archivo en el disco falso.
     *
     * @param SupportTicket $ticket
     * @param string        $path
     *
     * @return SupportMessageAttachment
     */
    protected function adjuntar_excel(SupportTicket $ticket, string $path = 'soporte/lista.xlsx'): SupportMessageAttachment
    {
        Storage::fake('public');
        Storage::disk('public')->put($path, 'contenido-del-excel');

        $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->orderByDesc('id')->first();

        return SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk'               => 'public',
            'path'               => $path,
            'mime'               => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size'               => 19,
        ]);
    }

    /**
     * El pedido de importar un Excel del ticket.
     *
     * @param SupportTicket        $ticket
     * @param array<string, mixed> $body
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function importar_del_ticket(SupportTicket $ticket, array $body)
    {
        return $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/import-excel', $body, $this->headers_de_claude());
    }

    /**
     * Cantidad de pedidos HTTP que salieron hacia el importador de la instancia (`ai-excel-import`).
     *
     * @return int
     */
    protected function pedidos_al_importador(): int
    {
        $cantidad = 0;
        foreach (\Illuminate\Support\Facades\Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'ai-excel-import') !== false) {
                $cantidad++;
            }
        }

        return $cantidad;
    }

    /**
     * Deja al cliente como un negocio ACTIVO con el Soporte por WhatsApp habilitado (el camino `automatizar_soporte`).
     *
     * @param Client         $client
     * @param SupportTicket  $ticket
     * @param int            $message_id Mensaje del dueño que acredita el adjunto.
     *
     * @return void
     */
    protected function pasar_a_activo(Client $client, SupportTicket $ticket, int $message_id): void
    {
        $client->estado = PruebaCicloService::CLIENTE_ACTIVO;
        $client->save();
        SupportMessage::whereKey($message_id)->update(['whatsapp_remitente_crudo' => ltrim((string) $client->phone, '+')]);
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);
    }

    /**
     * La reserva del turno del agente de Soporte (`claim`), sin afirmar capacidades: para los clientes sin implementación esas
     * capacidades están apagadas a propósito.
     *
     * @param SupportTicket $ticket
     * @param int           $message_id
     *
     * @return array{lease_token: string, expected_message_id: int}
     */
    protected function reservar_turno_sin_afirmar(SupportTicket $ticket, int $message_id): array
    {
        $reserva = $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/claim', [
            'worker_id'           => 'martin',
            'expected_message_id' => $message_id,
        ], $this->headers_de_claude())->assertOk();

        return ['lease_token' => $reserva->json('lease_token'), 'expected_message_id' => $message_id];
    }
}

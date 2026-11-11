<?php

namespace Tests\Feature\ImportacionesDeMartin;

use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Services\Prueba\PruebaCicloService;
use App\Services\SupportCodexSettings;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\PruebaGratuita\BaseDeLaPrueba;

/**
 * Lo compartido por los tests de la misión cruzada `importaciones-de-martin` (11/11/2026): un negocio ACTIVO con el
 * ticket de WhatsApp de su dueño, adjuntos en un disco falso, la reserva del turno por `claim` y la lectura de los
 * campos de un multipart falso. Nada sale a la red: `BaseDelCanal` fakea HTTP y acá cada test pone sus respuestas.
 *
 * No termina en `Test.php` a propósito, así PHPUnit no lo corre como suite.
 */
abstract class BaseDeImportacionesDeMartin extends BaseDeLaPrueba
{
    /** @var Client */
    protected $client;

    /** @var SupportTicket */
    protected $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        AdminSetting::set(SupportCodexSettings::KEY_ENABLED, '1');
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AT, now()->subHour()->toDateTimeString());
        AdminSetting::set(SupportCodexSettings::KEY_ACTIVATED_AFTER_ID, 0);

        $this->client = $this->cliente_activo();
        $this->ticket = $this->ticket_del_cliente($this->client);
        $this->acreditar($this->ultimo_mensaje($this->ticket));
    }

    /** Un negocio activo (en implementación o ya operando): `clients.estado = activo`. */
    protected function cliente_activo(string $phone = '+5493411234567'): Client
    {
        $client = $this->cliente_en_prueba(5, 7, $phone);
        $client->estado = PruebaCicloService::CLIENTE_ACTIVO;
        $client->save();

        return $client;
    }

    protected function ultimo_mensaje(SupportTicket $ticket): SupportMessage
    {
        return SupportMessage::where('support_ticket_id', $ticket->id)->orderByDesc('id')->first();
    }

    /** El mensaje llegó desde el número registrado del dueño (el remitente crudo que manda Meta). */
    protected function acreditar(SupportMessage $mensaje, ?string $telefono = null): void
    {
        $mensaje->whatsapp_remitente_crudo = ltrim($telefono === null ? (string) $this->client->phone : $telefono, '+');
        $mensaje->save();
    }

    /** Un entrante nuevo del ticket; `$del_dueno` lo acredita con el número del dueño. */
    protected function entrante(SupportTicket $ticket, string $body = 'Hola', bool $del_dueno = true, ?string $telefono = null): SupportMessage
    {
        $mensaje = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'user',
            'kind' => 'text',
            'body' => $body,
            'delivered_at' => now(),
            'whatsapp_message_id' => 'wamid.entrante.' . Str::random(8),
        ]);
        if ($del_dueno || $telefono !== null) {
            $this->acreditar($mensaje, $telefono);
        }
        $ticket->last_client_message_at = $mensaje->delivered_at;
        $ticket->save();

        return $mensaje;
    }

    /** Un adjunto del mensaje dado (o del último del ticket), con su archivo en el disco falso. */
    protected function adjuntar(SupportTicket $ticket, string $path, string $mime, ?SupportMessage $mensaje = null, string $contenido = 'contenido-del-archivo'): SupportMessageAttachment
    {
        Storage::disk('public')->put($path, $contenido);
        $mensaje = $mensaje === null ? $this->ultimo_mensaje($ticket) : $mensaje;

        return SupportMessageAttachment::create([
            'support_message_id' => $mensaje->id,
            'disk' => 'public',
            'path' => $path,
            'mime' => $mime,
            'size' => strlen($contenido),
        ]);
    }

    /**
     * La reserva del turno sobre el último mensaje del ticket.
     *
     * @return array{lease_token: string, expected_message_id: int}
     */
    protected function reservar(?SupportTicket $ticket = null): array
    {
        $ticket = $ticket === null ? $this->ticket : $ticket;
        $ultimo = (int) $this->ultimo_mensaje($ticket)->id;
        $reserva = $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/claim', [
            'worker_id' => 'martin',
            'expected_message_id' => $ultimo,
        ], $this->headers_de_claude())->assertOk();

        return ['lease_token' => (string) $reserva->json('lease_token'), 'expected_message_id' => $ultimo];
    }

    /** El permiso delegado de una tarea. */
    protected function permiso(string $alcance, string $lease_token, ?SupportTicket $ticket = null): string
    {
        $ticket = $ticket === null ? $this->ticket : $ticket;

        return (string) $this->postJson('/api/claude/support-codex/tickets/' . $ticket->id . '/task-grant',
            ['lease_token' => $lease_token, 'alcance' => $alcance], $this->headers_de_claude())
            ->assertStatus(201)->json('tarea_token');
    }

    /** Las rutas del ticket bajo `claude/support-codex/tickets/{id}/`. */
    protected function ruta(string $sufijo, ?SupportTicket $ticket = null): string
    {
        return '/api/claude/support-codex/tickets/' . ($ticket === null ? $this->ticket : $ticket)->id . '/' . $sufijo;
    }

    /**
     * El valor de un campo (no archivo) de un request multipart falso.
     *
     * @param \Illuminate\Http\Client\Request $request
     */
    protected function campo($request, string $nombre): ?string
    {
        foreach ($request->data() as $parte) {
            if (is_array($parte) && isset($parte['name']) && $parte['name'] === $nombre && ! isset($parte['filename'])) {
                return (string) $parte['contents'];
            }
        }

        return null;
    }

    /** Unos bytes que empiezan como un PNG. */
    protected function png(string $resto = 'resto-de-la-imagen'): string
    {
        return "\x89PNG\r\n\x1A\n" . $resto;
    }
}

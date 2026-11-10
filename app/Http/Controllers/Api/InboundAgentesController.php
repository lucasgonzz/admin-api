<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportTicket;
use App\Models\WhatsappConfig;
use App\Services\AsistenteWhatsappSettings;
use App\Services\SupportAgentesIdentity;
use App\Services\SupportCodexSettings;
use Illuminate\Http\Request;

class InboundAgentesController extends Controller
{
    public function canales(Request $request)
    {
        [$client, $user_id, $es_dueno] = $this->identidad($request);
        $codex = SupportCodexSettings::enabled();
        $principal = WhatsappConfig::getActive();
        $asistente = WhatsappConfig::canalListo(WhatsappConfig::PROPOSITO_ASISTENTE);
        // Con Codex, el número principal atiende soporte. El asistente requiere su número propio.
        $puede_usar_asistente = SupportAgentesIdentity::es_dueno($client, $user_id, $es_dueno)
            && $client->asistente_whatsapp_activo;
        return response()->json([
            'asistente' => $puede_usar_asistente ? $this->numero($asistente ?? ($codex ? null : $principal)) : null,
            'soporte' => $codex || AsistenteWhatsappSettings::tickets_habilitados() ? $this->numero($principal) : null,
        ]);
    }

    public function soporte(Request $request)
    {
        [$client, $user_id, $es_dueno] = $this->identidad($request);
        $numeros = SupportTicket::where('client_id', $client->id)->where('client_user_id', $user_id)
            ->where('source', 'erp')->whereNull('sistema_exclusivo_at')->pluck('id', 'uuid');
        $models = [];
        if (! $request->boolean('solo_referencias_erp')) {
            $models = $this->tickets($client)->orderByDesc('last_message_at')->orderByDesc('id')->get()
                ->filter(function (SupportTicket $ticket) use ($client, $user_id, $es_dueno) {
                    return SupportAgentesIdentity::puede_ver($ticket, $client, $user_id, $es_dueno);
                })->map(function (SupportTicket $ticket) {
                    return $this->resumen($ticket);
                })->values();
        }

        // Empresa ya tiene las conversaciones ERP. El mapa solo vincula su UUID al número de Admin.
        return response()->json(['models' => $models, 'ticket_numbers' => (object) $numeros->all()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function conversacion(Request $request, string $uuid)
    {
        [$client, $user_id, $es_dueno] = $this->identidad($request);
        $ticket = $this->tickets($client)->where('uuid', $uuid)->firstOrFail();
        abort_unless(SupportAgentesIdentity::puede_ver($ticket, $client, $user_id, $es_dueno), 404);

        $mensajes = $ticket->messages()->with('attachments');
        $this->filtrar_mensajes_visibles($mensajes);
        $model = $this->resumen($ticket);
        $model['messages'] = $mensajes->get()->map(function (SupportMessage $mensaje) {
                return [
                    'uuid' => $mensaje->uuid,
                    'sender_type' => $mensaje->sender_type,
                    'body' => $mensaje->body,
                    'kind' => $mensaje->kind,
                    'created_at' => optional($mensaje->created_at)->toIso8601String(),
                    'delivered_at' => optional($mensaje->delivered_at)->toIso8601String(),
                    'read_at' => optional($mensaje->read_at)->toIso8601String(),
                    'attachments' => $mensaje->attachments->map(function (SupportMessageAttachment $adjunto) {
                        return $adjunto->only(['id', 'mime', 'size', 'public_url', 'download_url', 'playback_url', 'display_filename']);
                    }),
                ];
            });

        return response()->json(['model' => $model])->header('Cache-Control', 'private, no-store');
    }

    private function identidad(Request $request): array
    {
        $client = $request->attributes->get('sync_client');
        abort_unless(SupportAgentesIdentity::firmada($request, $client), 401);
        $datos = $request->validate(['client_user_id' => 'required|integer|min:1', 'es_dueno' => 'required|boolean']);

        return [$client, (int) $datos['client_user_id'], (bool) $datos['es_dueno']];
    }

    private function tickets(Client $client)
    {
        return SupportTicket::where('client_id', $client->id)->with('client_employee')
            ->withMax(['messages as last_message_at' => function ($messages) {
                $this->filtrar_mensajes_visibles($messages);
            }], 'created_at')->withCasts(['last_message_at' => 'datetime'])
            ->where(function ($query) {
                $query->where('source', 'whatsapp')->orWhereNotNull('sistema_exclusivo_at');
            });
    }

    private function filtrar_mensajes_visibles($mensajes): void
    {
        SupportMessage::apply_mensaje_real_conditions($mensajes, 'support_messages');
        $mensajes->whereIn('sender_type', ['user', 'admin'])
            ->where(function ($visibles) {
                $visibles->where('sender_type', 'user')->orWhereNotNull('whatsapp_message_id')
                    ->orWhereNotNull('synced_to_client_at');
            });
    }

    private function resumen(SupportTicket $ticket): array
    {
        return [
            'uuid' => $ticket->uuid,
            'admin_ticket_id' => (int) $ticket->id,
            'name' => $ticket->name,
            'status' => $ticket->status,
            'opened_at' => optional($ticket->opened_at)->toIso8601String(),
            'closed_at' => optional($ticket->closed_at)->toIso8601String(),
            'updated_at' => optional($ticket->updated_at)->toIso8601String(),
            'last_message_at' => optional($ticket->last_message_at)->toIso8601String(),
            'origen' => 'whatsapp',
            'sistema_exclusivo_at' => optional($ticket->sistema_exclusivo_at)->toIso8601String(),
        ];
    }

    private function numero(?WhatsappConfig $canal): ?string
    {
        $visible = trim((string) optional($canal)->display_phone);
        $digitos = preg_replace('/\D/', '', $visible);
        return preg_match('/^\+?[0-9 ().-]+$/D', $visible) && preg_match('/^[0-9]{8,15}$/D', $digitos)
            ? $digitos : null;
    }
}

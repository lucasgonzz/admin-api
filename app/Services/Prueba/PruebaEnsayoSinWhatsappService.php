<?php

namespace App\Services\Prueba;

use App\Events\SupportMessageReceived;
use App\Models\Admin;
use App\Models\Client;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportClientSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Un cliente de ENSAYO (`clients.es_ensayo`) no recibe NADA por WhatsApp (pedido de Lucas, 12/11/2026, misión
 * ensayo-sin-mision-automatica). Los WhatsApp del ensayo quedaban como no entregados y Meta le baja la reputación a
 * la línea por las plantillas sin respuesta («sería mejor que la comunicación en este caso de prueba sea solo a
 * través de mensajes desde el sistema del cliente en lugar de WhatsApp»).
 *
 * Lo que sale hacia un cliente de ensayo se deja como mensaje de Soporte en la conversación del SISTEMA del negocio:
 *
 *  - Si el dueño ya tiene abierta una conversación del canal sistema (`source = erp`, sin empleado, de su usuario),
 *    se usa esa.
 *  - Si no, se abre UNA «Prueba gratuita» del canal sistema marcada `sistema_exclusivo_at` y se reusa siempre. Esa
 *    marca es la que hace que el sistema del negocio la traiga por `inbound/agentes/soporte` (Agentes → Soporte).
 *
 * El mensaje queda visible en el sistema: en una conversación exclusiva del sistema, por `synced_to_client_at`
 * (el sistema la lee del admin); en una conversación nativa del ERP, por el push de siempre
 * (`SupportClientSyncService::sync_message_to_client`). Nunca se llama a Kapso ni a Meta.
 *
 * Para un cliente que NO es de ensayo esta clase no interviene: quien la llama lo pregunta antes con
 * {@see self::es_ensayo()}.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados ni union types.
 */
class PruebaEnsayoSinWhatsappService
{
    /** Nombre de la conversación del sistema que se abre si el dueño todavía no tiene una. */
    const NOMBRE_DE_LA_CONVERSACION = 'Prueba gratuita';

    /**
     * Si el cliente está marcado como ensayo.
     *
     * @param Client|null $client
     *
     * @return bool false para un cliente ausente.
     */
    public static function es_ensayo(?Client $client): bool
    {
        return $client !== null && (bool) $client->es_ensayo;
    }

    /**
     * La conversación del sistema del dueño donde se dejan los mensajes del ensayo; la abre si no existe.
     *
     * @param Client $client
     *
     * @return SupportTicket|null null si el cliente no tiene usuario del sistema al que dirigirse.
     */
    public function conversacion_del_sistema(Client $client): ?SupportTicket
    {
        // Usuario dueño del sistema del negocio: sin él no hay conversación del sistema que el dueño pueda ver.
        $usuario = (int) $client->user_id;
        if ($usuario < 1) {
            return null;
        }

        $existente = $this->conversacion_abierta($client, $usuario);
        if ($existente !== null) {
            return $existente;
        }

        // Un candado por cliente: dos envíos simultáneos no abren dos conversaciones.
        $reserva = Cache::lock('prueba-ensayo-conversacion-' . $client->id, 30);
        $reserva->block(10);
        try {
            $existente = $this->conversacion_abierta($client, $usuario);
            if ($existente !== null) {
                return $existente;
            }

            return SupportTicket::create([
                'client_id'            => (int) $client->id,
                'client_user_id'       => $usuario,
                'assigned_admin_id'    => null,
                'name'                 => self::NOMBRE_DE_LA_CONVERSACION,
                'name_source'          => 'sistema',
                'status'               => SupportTicket::STATUS_ABIERTO,
                'source'               => 'erp',
                'opened_at'            => now(),
                // Exclusiva del sistema: el sistema del negocio la trae del admin (`inbound/agentes/soporte`).
                'sistema_exclusivo_at' => now(),
            ]);
        } finally {
            $reserva->release();
        }
    }

    /**
     * Deja un mensaje del equipo en la conversación del sistema del dueño y lo hace visible en su sistema.
     *
     * @param Client               $client
     * @param string               $texto  El texto ya resuelto (la plantilla con sus variables o el texto libre).
     * @param array<string, mixed> $extra  Columnas adicionales del mensaje (`ai_generated_at`…).
     * @param int|null             $ticket_preferido A7 (14/11/2026): la conversación donde se originó el aviso (p. ej. la
     *   del circuito de carga, que empresa informa en `support_ticket_id`). Se usa solo si es una conversación del
     *   sistema abierta del dueño de ESTE cliente (`conversacion_preferida()`); si no, la de siempre.
     *
     * @return array{enviado: bool, motivo: string|null, whatsapp_message_id: null, support_ticket_id: int|null, support_message_id: int|null}
     *   La misma forma que `PruebaWhatsappService::mandar_texto_por_ticket()`, con el id del mensaje.
     */
    public function dejar(Client $client, string $texto, array $extra = [], ?int $ticket_preferido = null): array
    {
        $ticket = $ticket_preferido === null ? null : $this->conversacion_preferida($client, $ticket_preferido);
        if ($ticket === null) {
            $ticket = $this->conversacion_del_sistema($client);
        }
        if ($ticket === null) {
            return ['enviado' => false, 'motivo' => 'El negocio no tiene usuario del sistema: no hay conversación donde dejar el mensaje.',
                'whatsapp_message_id' => null, 'support_ticket_id' => null, 'support_message_id' => null];
        }

        $admin   = $this->remitente();
        $mensaje = SupportMessage::create(array_merge([
            'support_ticket_id'      => (int) $ticket->id,
            'sender_type'            => 'admin',
            'sender_admin_id'        => $admin !== null ? (int) $admin->id : null,
            'kind'                   => 'text',
            'body'                   => $texto,
            'delivered_at'           => now(),
            // Aviso del equipo: queda en el hilo sin tapar el entrante que espera respuesta de Martín.
            'is_trial_import_notice' => true,
        ], $extra));

        $visible = false;
        if ($ticket->sistema_exclusivo_at !== null) {
            // El sistema del negocio lee esta conversación del admin: la marca es lo que la hace visible.
            $mensaje->synced_to_client_at = now();
            $mensaje->save();
            $visible = true;
        } else {
            try {
                $visible = app(SupportClientSyncService::class)->sync_message_to_client($mensaje);
            } catch (\Throwable $e) {
                Log::channel('daily')->warning('PruebaEnsayo: no se pudo sincronizar el mensaje al sistema del negocio.', ['message_id' => $mensaje->id, 'error' => $e->getMessage()]);
            }
        }

        if (! $visible) {
            // No llenar el hilo con copias que el negocio no ve en cada reintento.
            $mensaje->delete();

            return ['enviado' => false, 'motivo' => 'El sistema del negocio no confirmó el mensaje.', 'whatsapp_message_id' => null,
                'support_ticket_id' => (int) $ticket->id, 'support_message_id' => null];
        }

        try {
            event(new SupportMessageReceived((int) $mensaje->id));
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaEnsayo: no se pudo emitir el evento del mensaje.', ['message_id' => $mensaje->id, 'error' => $e->getMessage()]);
        }

        return ['enviado' => true, 'motivo' => null, 'whatsapp_message_id' => null,
            'support_ticket_id' => (int) $ticket->id, 'support_message_id' => (int) $mensaje->id];
    }

    /**
     * A7: la conversación pedida, si es elegible con EXACTAMENTE los mismos filtros de pertenencia que
     * `conversacion_abierta()` (este cliente, canal sistema, sin empleado, del usuario dueño, abierta) más el id.
     * Caso real: ticket 126 del cliente de ensayo 85, donde el dueño mandó la planilla; los avisos de la carga caían
     * en la «Prueba gratuita» (125) y el dueño no los veía.
     *
     * @param Client $client
     * @param int    $ticket_id
     *
     * @return SupportTicket|null null si no es elegible (o el cliente no tiene usuario dueño).
     */
    protected function conversacion_preferida(Client $client, int $ticket_id): ?SupportTicket
    {
        $usuario = (int) $client->user_id;
        if ($usuario < 1 || $ticket_id < 1) {
            return null;
        }

        return SupportTicket::query()
            ->whereKey($ticket_id)
            ->where('client_id', (int) $client->id)
            ->where('source', 'erp')
            ->whereNull('client_employee_id')
            ->where('client_user_id', $usuario)
            ->where('status', SupportTicket::STATUS_ABIERTO)
            ->first();
    }

    /**
     * La última conversación abierta del canal sistema del dueño.
     *
     * @param Client $client
     * @param int    $usuario
     *
     * @return SupportTicket|null
     */
    protected function conversacion_abierta(Client $client, int $usuario): ?SupportTicket
    {
        return SupportTicket::query()
            ->where('client_id', (int) $client->id)
            ->where('source', 'erp')
            ->whereNull('client_employee_id')
            ->where('client_user_id', $usuario)
            ->where('status', SupportTicket::STATUS_ABIERTO)
            // Primero la que ya abrió el ensayo (exclusiva del sistema); después la más nueva.
            ->orderByRaw('sistema_exclusivo_at IS NULL')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * El admin que figura como remitente (misma regla que `PruebaWhatsappService::remitente()`).
     *
     * @return Admin|null
     */
    protected function remitente(): ?Admin
    {
        $configurado = config('services.claude_task_ingest.default_creator_admin_id');
        if (! empty($configurado)) {
            $admin = Admin::find((int) $configurado);
            if ($admin !== null) {
                return $admin;
            }
        }

        $por_defecto = Admin::query()->where('is_default_task_assignee', true)->first();

        return $por_defecto !== null ? $por_defecto : Admin::query()->orderBy('id')->first();
    }
}

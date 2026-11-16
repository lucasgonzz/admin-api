<?php

namespace App\Services;

use App\Models\Implementation;
use App\Models\SupportCodexCase;
use App\Models\SupportTicket;
use Carbon\Carbon;

/**
 * Lista las implementaciones de sistema cuyo cliente YA completó el formulario y todavía no tiene corrido el
 * user setup, para que el monitor local de Martín dispare solo el paso «completó el formulario» sin esperar un
 * mensaje del cliente (misión cruzada `monitor-pasos-implementar-auto`, 16/11/2026).
 *
 * Es SOLO LECTURA: no reserva, no marca ni escribe nada. Que un item se haya despachado o no lo lleva el
 * monitor en su estado local (clave `form:<implementation_id>`, una sola vez por implementación), no el admin.
 *
 * Una implementación entra en la lista si cumple TODO esto:
 *  - es de sistema (el modelo `Implementation` es la del sistema; tienda y agentes tienen modelos propios);
 *  - está abierta: `status = in_progress`, el mismo criterio que `SupportImplementationContextService`
 *    (`esta_abierta`: abierta es SOLO `in_progress`; `paused`, `pending` y `completed` no cuentan);
 *  - tiene `form_submitted_at` no nulo (envió el formulario definitivo);
 *  - tiene `user_setup_executed_at` NULO (el user setup se corre una sola vez: si ya corrió, no hay paso);
 *  - su cliente tiene al menos un ticket de soporte ABIERTO (sin ticket no hay dónde devolver el resultado).
 */
class ImplementationFormSteps
{
    /**
     * Tope duro de items por respuesta.
     *
     * @var int
     */
    const LIMITE_MAXIMO = 20;

    /**
     * Cantidad de items por defecto.
     *
     * @var int
     */
    const LIMITE_POR_DEFECTO = 10;

    /**
     * Arma la respuesta completa del endpoint.
     *
     * Con el soporte de Codex apagado (mismo criterio que `SupportCodexService::pending`: prendido Y con fecha de
     * activación) devuelve `enabled:false` e `items:[]` sin consultar nada más.
     *
     * @param Carbon|null $since Si viene, solo formularios enviados en ese instante o después.
     * @param int         $limit Cantidad máxima de items (1..20).
     *
     * @return array<string, mixed> `{enabled, items, generated_at}`.
     */
    public function pending(Carbon $since = null, $limit = self::LIMITE_POR_DEFECTO)
    {
        // Mismo interruptor que `pending` de soporte-codex: sin prender y activado, no se ofrece nada.
        $settings = SupportCodexSettings::to_array();
        $enabled  = (bool) $settings['enabled'] && $settings['activated_at'] !== null;

        $items = [];

        if ($enabled) {
            $items = $this->items($since, (int) $limit);
        }

        return [
            'enabled'      => $enabled,
            'items'        => $items,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Los items, ya filtrados por ticket abierto y recortados al límite.
     *
     * @param Carbon|null $since Corte inferior de `form_submitted_at` (inclusive), o null.
     * @param int         $limit Cantidad máxima de items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function items(Carbon $since = null, $limit = self::LIMITE_POR_DEFECTO)
    {
        $consulta = Implementation::query()
            ->with('client')
            ->where('status', 'in_progress')
            ->whereNotNull('form_submitted_at')
            ->whereNull('user_setup_executed_at')
            ->whereHas('client');

        if ($since !== null) {
            $consulta->where('form_submitted_at', '>=', $since);
        }

        // Más viejo primero: el monitor atiende en el orden en que los clientes completaron el formulario.
        $consulta->orderBy('form_submitted_at')->orderBy('id');

        $items = [];

        /* Los candidatos sin ticket abierto se descartan en PHP (el ticket sale de un resolutor propio y no de
           un JOIN), así que se recorre hasta juntar `limit` en vez de cortar la consulta antes. */
        foreach ($consulta->cursor() as $implementation) {
            $ticket = $this->ticket_abierto_del_cliente((int) $implementation->client_id);

            if ($ticket === null) {
                continue;
            }

            $items[] = $this->item($implementation, $ticket);

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * El ticket de soporte abierto del cliente donde devolver el resultado del paso.
     *
     * Si hay más de uno abierto se elige el de mensaje del cliente MÁS RECIENTE (`last_client_message_at`), porque
     * es la conversación viva: la que Martín está atendiendo o va a retomar. Sin fecha de mensaje (ticket recién
     * abierto desde el ERP) cae a `opened_at`, y a igualdad desempata el id más nuevo, para que la elección sea
     * determinista. No existe un resolutor compartido de «ticket del cliente» (el contexto de implementación
     * recibe el ticket ya resuelto), así que se usa el mismo criterio de «abierto» que el resto del soporte:
     * `SupportTicket::STATUS_ABIERTO`.
     *
     * @param int $client_id Cliente de la implementación.
     *
     * @return SupportTicket|null null = no tiene ticket abierto.
     */
    protected function ticket_abierto_del_cliente($client_id)
    {
        return SupportTicket::query()
            ->where('client_id', $client_id)
            ->where('status', SupportTicket::STATUS_ABIERTO)
            ->orderByRaw('COALESCE(last_client_message_at, opened_at) DESC')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Forma pública de un item del contrato.
     *
     * @param Implementation $implementation La implementación de sistema.
     * @param SupportTicket  $ticket         Su ticket abierto elegido.
     *
     * @return array<string, mixed>
     */
    protected function item(Implementation $implementation, SupportTicket $ticket)
    {
        // El hilo de Codex del caso, si Martín ya lo tiene vinculado (puede no haber caso todavía).
        $caso = SupportCodexCase::where('support_ticket_id', $ticket->id)->first();

        return [
            'implementation_id'      => (int) $implementation->id,
            'type'                   => 'sistema',
            'client_id'              => (int) $implementation->client_id,
            'client_name'            => (string) $implementation->client->name,
            'form_submitted_at'      => $implementation->form_submitted_at->toIso8601String(),
            'current_stage'          => (int) $implementation->current_stage,
            'user_setup_executed_at' => null, // siempre null: es el filtro de la lista.
            'ticket_id'              => (int) $ticket->id,
            'ticket_thread_id'       => $caso !== null && trim((string) $caso->thread_id) !== '' ? (string) $caso->thread_id : null,
        ];
    }
}

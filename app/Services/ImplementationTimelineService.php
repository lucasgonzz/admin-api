<?php

namespace App\Services;

use App\Http\Controllers\Api\ClaudeImplementationOpsController;
use App\Models\AgentImplementation;
use App\Models\AgentImplementationStage;
use App\Models\Client;
use App\Models\EcommerceImplementation;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use App\Models\SupportTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ImplementationTimelineService
{
    private const TYPES = [
        'sistema' => Implementation::class,
        'tienda'  => EcommerceImplementation::class,
        'agentes' => AgentImplementation::class,
    ];

    private const MAIL_STAGES = [
        'bienvenida' => 1,
        'instalando' => 3,
        'instalado' => 3,
        'acceso' => 5,
        'listo' => 5,
        'categorias' => 6,
    ];

    public function businesses(): array
    {
        $businesses = [];
        $ticket_ids = [];

        foreach (self::TYPES as $type => $model) {
            $implementations = $this->latest_implementations($model, $type);
            $messages = $this->outbound_dates($implementations, $type);
            $mails = $type === 'sistema' ? $this->mail_dates($implementations) : collect();

            foreach ($implementations as $implementation) {
                $client = $implementation->client;
                if ($client === null) {
                    continue;
                }

                $id = (int) $client->id;
                $businesses[$id] = $businesses[$id] ?? [
                    'id' => $id,
                    'name' => $client->resolve_display_name() ?: 'Negocio #' . $id,
                    'logo_url' => $this->logo_url($client, $implementation, $type),
                    'implementations' => [],
                ];

                $stages = $this->stages($implementation, $type, $messages, $mails);
                foreach ($stages as $stage) {
                    if ($stage['sent_ticket_id'] !== null) {
                        $ticket_ids[] = $stage['sent_ticket_id'];
                    }
                }

                $businesses[$id]['implementations'][] = [
                    'id' => (int) $implementation->id,
                    'type' => $type,
                    'status' => $implementation->status,
                    'created_at' => $this->iso($implementation->created_at),
                    'stages' => $stages,
                ];
            }
        }

        $ticket_owners = SupportTicket::whereIntegerInRaw('id', array_unique($ticket_ids))->pluck('client_id', 'id');
        foreach ($businesses as &$business) {
            foreach ($business['implementations'] as &$implementation) {
                foreach ($implementation['stages'] as &$stage) {
                    $ticket_id = $stage['sent_ticket_id'];
                    if ($ticket_id !== null && (int) $ticket_owners->get($ticket_id) !== $business['id']) {
                        $stage['sent_ticket_id'] = null;
                    }
                }
                unset($stage);
            }
            unset($implementation);
        }
        unset($business);

        return collect($businesses)->sortByDesc(function (array $business) {
            return max(array_column($business['implementations'], 'created_at'));
        })->values()->all();
    }

    private function latest_implementations(string $model, string $type): Collection
    {
        $columns = ['id', 'client_id', 'status', 'created_at'];
        if ($type === 'sistema') {
            $columns[] = 'form_submitted_at';
        }

        $implementation_rows = $model::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get($columns)
            ->unique('client_id')
            ->values();

        $relations = ['client:id,name,company_name,logo_url,setup_data', 'stages'];
        if ($type !== 'agentes') {
            $relations[] = 'stages.config:id,stage_number,name';
        }

        return $implementation_rows->load($relations);
    }

    private function outbound_dates(Collection $implementations, string $type): Collection
    {
        if ($type === 'agentes' || $implementations->isEmpty()) {
            return collect();
        }

        $prefix = $type === 'sistema' ? 'implementation' : 'ecommerce_implementation';
        $owner_key = $prefix . '_id';

        // Los dos hilos guardan también intentos fallidos. El id externo distingue un saliente confirmado.
        return DB::table($prefix . '_messages')
            ->whereIntegerInRaw($owner_key, $implementations->modelKeys())
            ->where('direction', 'outbound')
            ->whereNotNull('whatsapp_message_id')
            ->where('whatsapp_message_id', '<>', '')
            ->groupBy($owner_key, 'stage_number')
            ->select($owner_key, 'stage_number')
            ->selectRaw('MAX(COALESCE(sent_at, created_at)) AS sent_at')
            ->get()
            ->mapWithKeys(function ($message) use ($owner_key) {
                return [$message->{$owner_key} . ':' . $message->stage_number => $this->iso($message->sent_at)];
            });
    }

    private function mail_dates(Collection $implementations): Collection
    {
        if ($implementations->isEmpty()) {
            return collect();
        }

        return ImplementationMail::whereIntegerInRaw('implementation_id', $implementations->modelKeys())
            ->where('estado', ImplementationMail::ESTADO_ENVIADO)
            ->whereIn('hito', array_keys(self::MAIL_STAGES))
            ->whereNotNull('enviado_at')
            ->orderBy('enviado_at')
            ->get(['implementation_id', 'hito', 'enviado_at'])
            ->mapWithKeys(function (ImplementationMail $mail) {
                $number = self::MAIL_STAGES[$mail->hito];

                return [$mail->implementation_id . ':' . $number => $this->iso($mail->enviado_at)];
            });
    }

    private function stages($implementation, string $type, Collection $messages, Collection $mails): array
    {
        return $implementation->stages->filter(function ($stage) use ($type) {
            return $type !== 'sistema'
                || (int) $stage->stage_number <= Implementation::ULTIMA_ETAPA
                || ! in_array($stage->status, ['pending', 'skipped'], true);
        })->map(function ($stage) use ($implementation, $type, $messages, $mails) {
            $number = (int) $stage->stage_number;
            $operation = $this->sent_operation($stage->data);
            $key = $implementation->id . ':' . $number;
            $whatsapp_at = $messages->get($key);
            $mail_at = $mails->get($key);
            $sent_at = $operation['at'] ?? max($whatsapp_at, $mail_at);
            $completed = $stage->status === 'completed';
            $started_at = $this->iso($stage->started_at);

            // Al omitir una etapa de agentes sin empezarla, el servicio guarda el mismo instante en ambas fechas.
            if ($type === 'agentes' && $stage->status === AgentImplementationStage::STATUS_SKIPPED
                && $started_at !== null && $started_at === $this->iso($stage->completed_at)) {
                $started_at = null;
            }

            return [
                'number' => $number,
                'name' => $this->stage_name($stage, $type),
                'status' => $stage->status,
                'started_at' => $started_at,
                'sent_at' => $sent_at,
                'sent_ticket_id' => $operation['ticket_id'] ?? null,
                'sent_source' => $operation !== null
                    ? 'WhatsApp por soporte'
                    : $this->sent_source($whatsapp_at, $mail_at),
                'completed_at' => $completed ? $this->iso($stage->completed_at) : null,
                'completed_ticket_id' => null,
                'completed_source' => $completed ? $this->completed_source($stage, $implementation, $type) : null,
            ];
        })->values()->all();
    }

    private function sent_source(?string $whatsapp_at, ?string $mail_at): ?string
    {
        if ($mail_at !== null && ($whatsapp_at === null || $mail_at > $whatsapp_at)) {
            return 'Correo de implementación';
        }

        return $whatsapp_at === null ? null : 'Conversación de implementación';
    }

    private function sent_operation($data): ?array
    {
        $operations = $data[AgentImplementationStage::DATA_KEY_WHATSAPP_OPS] ?? null;
        if (! is_array($operations)) {
            return null;
        }

        $latest = null;
        foreach ($operations as $operation) {
            // Un envío parcial puede dejar afuera la instrucción que el cliente tiene que cumplir.
            if (! is_array($operation) || ($operation['estado'] ?? null) !== ImplementationSupportWhatsappService::ESTADO_ENVIADO) {
                continue;
            }

            $at = $this->iso($operation['terminada_at'] ?? null);
            if ($at !== null && ($latest === null || $at > $latest['at'])) {
                $ticket_id = (int) ($operation['ticket_id'] ?? 0);
                $latest = ['at' => $at, 'ticket_id' => $ticket_id > 0 ? $ticket_id : null];
            }
        }

        return $latest;
    }

    private function stage_name($stage, string $type): string
    {
        $number = (int) $stage->stage_number;
        if ($type === 'agentes') {
            return AgentImplementation::stage_name($number) ?: 'Etapa ' . $number;
        }

        if ($type === 'sistema' && $number > Implementation::ULTIMA_ETAPA) {
            return ClaudeImplementationOpsController::ETAPAS_HEREDADAS[$number] ?? 'Etapa ' . $number;
        }

        $configured = trim((string) ($stage->config->name ?? ''));
        if ($type === 'sistema' && $number === 6 && $configured === 'Capacitación') {
            return ClaudeImplementationOpsController::ETAPAS[$number];
        }

        $fallback = $type === 'sistema'
            ? ClaudeImplementationOpsController::ETAPAS
            : SupportImplementationContextService::ETAPAS_DE_LA_TIENDA;

        return $configured !== '' ? $configured : ($fallback[$number] ?? 'Etapa ' . $number);
    }

    private function completed_source($stage, $implementation, string $type): ?string
    {
        if ((int) $stage->stage_number !== 1) {
            return null;
        }

        if ($type === 'sistema' && $implementation->form_submitted_at !== null) {
            return 'Formulario de implementación';
        }

        if ($type === 'tienda' && ($stage->data['current_question'] ?? null) === 'completed'
            && ($stage->data['completed'] ?? null) === true) {
            return 'Conversación de implementación';
        }

        return null;
    }

    private function logo_url(Client $client, $implementation, string $type): ?string
    {
        $first_stage = $type === 'sistema' ? $implementation->stages->firstWhere('stage_number', 1) : null;
        $form = $first_stage->data['form_responses'] ?? $first_stage->data ?? [];
        foreach ([$client->logo_url, $form['logo_url'] ?? null, $client->setup_data['logo_url'] ?? null] as $url) {
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
                && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null) {
                return $url;
            }
        }

        return null;
    }

    private function iso($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->toIso8601String();
        } catch (\Carbon\Exceptions\InvalidFormatException $exception) {
            return null;
        }
    }
}

<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;

class SupportCodexSettings
{
    const KEY_ENABLED = 'support_codex_enabled';
    const KEY_ACTIVATED_AT = 'support_codex_activated_at';
    const KEY_ACTIVATED_AFTER_ID = 'support_codex_activated_after_message_id';

    public static function enabled(): bool
    {
        // Se relee: el operador puede apagarlo mientras una sesión está diagnosticando.
        return in_array(AdminSetting::get(self::KEY_ENABLED), ['1', 'true'], true);
    }

    public static function activated_at(): ?string
    {
        $value = AdminSetting::get(self::KEY_ACTIVATED_AT);
        return $value !== null && trim($value) !== '' ? $value : null;
    }

    public static function manages(SupportTicket $ticket): bool
    {
        if (! self::enabled() || $ticket->source !== 'whatsapp') {
            return false;
        }
        // El corte se evalúa contra el entrante, no contra la apertura: un caso viejo puede
        // retomarse si el cliente escribe otra vez DESPUÉS de activar el nuevo circuito.
        return self::activated_at() !== null && $ticket->last_client_message_at !== null
            && $ticket->last_client_message_at->gte(self::activated_at())
            && \App\Models\SupportMessage::where('support_ticket_id', $ticket->id)
                ->where('sender_type', 'user')->where('id', '>', (int) AdminSetting::get(self::KEY_ACTIVATED_AFTER_ID, 0))->exists();
    }

    public static function set_enabled(bool $enabled): array
    {
        DB::transaction(function () use ($enabled) {
            if ($enabled && self::activated_at() === null) {
                // La marca por ID evita tomar un mensaje previo del MISMO segundo de activación.
                AdminSetting::set(self::KEY_ACTIVATED_AFTER_ID, (int) DB::table('support_messages')->max('id'));
                AdminSetting::set(self::KEY_ACTIVATED_AT, now()->format('Y-m-d H:i:s'));
            }
            AdminSetting::set(self::KEY_ENABLED, $enabled ? '1' : '0');
        });
        return self::to_array();
    }

    public static function to_array(): array
    {
        return ['enabled' => self::enabled(), 'activated_at' => self::activated_at()];
    }
}

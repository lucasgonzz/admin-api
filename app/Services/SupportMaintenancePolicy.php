<?php

namespace App\Services;

use App\Models\ClientEcommerce;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexUpgradeAgreement;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/** La excepción de horario pertenece al acuerdo enviado y aceptado, nunca al texto libre del agente. */
class SupportMaintenancePolicy
{
    public const MINUTES = 15;
    public const TIMEZONE = 'America/Argentina/Buenos_Aires';

    public static function available(): bool
    {
        return Schema::hasColumn('support_codex_upgrade_agreements', 'maintenance_mode')
            && Schema::hasColumn('support_codex_upgrade_agreements', 'store_identity_hash')
            && Schema::hasColumn('support_codex_scheduled_upgrades', 'target')
            && Schema::hasColumn('support_codex_release_claims', 'target');
    }

    public static function coordinated(SupportCodexUpgradeAgreement $agreement): bool
    {
        return $agreement->maintenance_mode === 'coordinated';
    }

    public static function target(SupportCodexUpgradeAgreement $agreement): string
    {
        return $agreement->target ?: 'sistema';
    }

    public static function repos(string $target): array
    {
        return $target === 'tienda' ? ['tienda-api', 'tienda-spa'] : ['empresa-api', 'empresa-spa'];
    }

    public static function assert_scope(SupportTicket $ticket, SupportCodexMissionFixEvidence $evidence, array $proposal): ?ClientEcommerce
    {
        $target = $proposal['target'] ?? 'sistema';
        $commits = array_keys($evidence->payload['commits'] ?? []);
        abort_unless($commits !== [] && array_diff($commits, self::repos($target)) === [], 409,
            'maintenance_scope_changed: La corrección requiere otro alcance de despliegue.');
        if ($target !== 'tienda') {
            abort_if(! empty($proposal['client_ecommerce_id']), 422, 'La propuesta del sistema no admite elegir una tienda.');
            return null;
        }
        $stores = ClientEcommerce::where('client_id', $ticket->client_id)->whereNull('demo_id')->whereIn('status', ! empty($proposal['allow_installing']) ? ['active', 'installing'] : ['active']);
        if (! empty($proposal['client_ecommerce_id'])) {
            $stores->whereKey($proposal['client_ecommerce_id']);
        }
        $stores = $stores->get();
        abort_unless($stores->count() === 1, 409, 'maintenance_store_required: Elegí una única tienda activa del negocio.');
        $store = $stores->first();
        abort_if($store->is_vps(), 409, ClientEcommerce::CLIENTE_VPS_NO_SOPORTADO);
        if (isset($proposal['store_identity_hash'])) {
            abort_unless(hash_equals($proposal['store_identity_hash'], self::store_hash($store)), 409, 'Cambió la tienda de la propuesta.');
        }
        return $store;
    }

    public static function store_hash(ClientEcommerce $store): string
    {
        return hash('sha256', json_encode([$store->id, $store->client_id, $store->domain,
            $store->api_url, $store->spa_url, $store->resolve_spa_path(), $store->resolve_api_path(), $store->is_vps()]));
    }

    public static function window(Carbon $from, int $minutes, bool $explicit = false): array
    {
        abort_unless($minutes === self::MINUTES, 422, 'El corte coordinado requiere una ventana de 15 minutos.');
        $start = $from->copy()->setTimezone(self::TIMEZONE)->ceilMinute();
        if (! $explicit && $start->lessThan(now()->addMinutes(90))) {
            $start = now()->setTimezone(self::TIMEZONE)->addMinutes(90)->ceilMinute();
        }
        if ($start->lessThanOrEqualTo(now())) {
            $start = now()->setTimezone(self::TIMEZONE)->addMinutes(90)->startOfMinute();
        }
        return ['window' => ['starts_at' => $start->toIso8601String(), 'ends_at' => $start->copy()->addMinutes($minutes)->toIso8601String()],
            'reason' => null, 'maintenance_mode' => 'coordinated', 'minimum_minutes' => $minutes];
    }

    public static function force_reason(SupportCodexUpgradeAgreement $agreement): string
    {
        return 'Corte coordinado de soporte acordado en ticket #' . $agreement->support_ticket_id
            . ', acuerdo #' . $agreement->id . ', confirmación #' . $agreement->confirmation_message_id
            . '. Autorización permanente de Lucas del 13/11/2026 para el gate de horario.';
    }
}

<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/** Política opt-in. Nunca convierte un escudo histórico o manual en permiso automático. */
class AgendaDemoAutomaticaPolicy
{
    const KEY_ACTIVA = 'lead_agenda_demo_automatica_condicional';
    const ESTADOS_PRE_DEMO = ['nuevo', 'contactado', 'calificado', 'solicita_disponibilidad', 'demo_agendada', 'demo_pendiente_de_ingreso'];

    public static function activa(): bool
    {
        return in_array((string) AdminSetting::get(self::KEY_ACTIVA, '0'), ['1', 'true'], true);
    }

    public static function es_pre_demo(string $estado): bool
    {
        return in_array($estado, self::ESTADOS_PRE_DEMO, true);
    }

    public function evaluar(Lead $lead, array $parsed = [], ?LeadMessage $mensaje = null, string $etapa = 'consulta'): array
    {
        $motivo = null;
        if (! self::activa()) {
            $motivo = 'politica_desactivada';
        } elseif (! self::es_pre_demo((string) $lead->status)) {
            $motivo = 'post_demo_manual';
        } elseif ($lead->requiere_verificacion_mensajes) {
            $motivo = 'escudo_manual_o_historico';
        } elseif ($lead->requiere_intervencion_humana || ! $lead->claude_auto_reply) {
            $motivo = 'intervencion_explicita';
        } elseif (! empty($parsed['requiere_intervencion_humana']) || ! empty($parsed['requiere_verificacion']) || ($mensaje && $mensaje->requiere_verificacion)) {
            $motivo = 'verificacion_explicita';
        } elseif (! empty($parsed['conflicto_correo']) || ! empty($parsed['agendar_demo']['conflicto_temporal']) || ! empty($parsed['agendar_demo']['inicio_ilegible'])) {
            $motivo = 'datos_incoherentes';
        } elseif (! empty($parsed['confirmar_fin_demo']) || ! empty($parsed['agendar_llamada_closer']) || ! empty($parsed['confirmar_ingreso']) || ! empty($parsed['marcar_no_ingreso'])) {
            $motivo = 'accion_fuera_de_agenda';
        } elseif (! empty($parsed['guardar_email']) && trim((string) $lead->email) !== ''
            && strcasecmp(trim((string) $parsed['guardar_email']), trim((string) $lead->email)) !== 0) {
            $motivo = 'correccion_correo_requiere_operador';
        }

        $pedido = null;
        if ($motivo === null && ! empty($parsed['agendar_demo'])) {
            $pedido = app(AgendaDemoTemporalService::class)->pedido($lead);
            if ($pedido === null || ! in_array($pedido['tipo'], ['ahora', 'momento'], true)) {
                $motivo = 'momento_sin_evidencia';
            } elseif (! $this->misma_intencion($pedido, $parsed['agendar_demo'])) {
                $motivo = 'intencion_desactualizada';
            } else {
                $servicio = app(DemoDirectaService::class);
                $ventana = ! empty($parsed['agendar_demo']['ahora']) ? $servicio->ventana_desde()
                    : (! empty($parsed['agendar_demo']['inicio_resuelto']) ? $servicio->ventana_para(Carbon::parse($parsed['agendar_demo']['inicio_resuelto'], DemoDirectaService::TZ)) : null);
                if ($ventana === null || ! $ventana['cabe']) {
                    $motivo = 'momento_inviable';
                } elseif (empty($parsed['agendar_demo']['ahora']) && $ventana['inicio']->lt(AppTime::now()->addMinutes(LeadAiService::minimo_minutos_para_turno_pedido()))) {
                    $motivo = 'momento_inviable';
                } elseif (! \App\Models\Demo::query()->exists()) {
                    $motivo = 'sin_instancias';
                } elseif ($servicio->instancia_libre($lead, $ventana['inicio'], $ventana['fin']) === null) {
                    $motivo = 'ocupacion_real';
                }
            }
        }
        if ($motivo === null && (! empty($parsed['agendar_demo']) || ! empty($parsed['guardar_email']) || ! empty($parsed['reenviar_mail_demo']))) {
            $correo_actual = app(AgendaDemoTemporalService::class)->correo_reciente($lead);
            $destinatario = trim((string) ($parsed['destinatario_correo'] ?? $parsed['guardar_email'] ?? $lead->email));
            if (count($correo_actual['correos']) > 1) {
                $motivo = 'correo_ambiguo';
            } elseif (count($correo_actual['correos']) === 1 && strcasecmp($correo_actual['correos'][0], $destinatario) !== 0) {
                $motivo = 'correo_desactualizado';
            } elseif (empty($parsed['guardar_email']) && strcasecmp(trim((string) $lead->email), $destinatario) !== 0) {
                $motivo = 'correo_guardado_desactualizado';
            }
        }
        if ($motivo === null && ! empty($parsed['cancelar_demo']) && empty($parsed['agendar_demo'])) {
            $ultimo = LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')->orderByDesc('id')->value('content');
            if (! preg_match('/\b(?:cancel|anul|no voy a|no la voy a|no puedo|dejalo|dejala)/iu', (string) $ultimo)) {
                $motivo = 'cancelacion_sin_evidencia';
            }
        }
        $decision = ['permitido' => $motivo === null, 'motivo' => $motivo ?: 'agenda_sin_conflicto', 'fuente_id' => $pedido['mensaje_id'] ?? null];
        if ($etapa !== 'consulta') {
            Log::info('Agenda demo: decisión de automatización condicional.', ['lead_id' => $lead->id, 'message_id' => $mensaje ? $mensaje->id : null, 'etapa' => $etapa] + $decision);
        }

        return $decision;
    }

    /** No basta que el pedido nuevo siga siendo «momento»: debe ser la MISMA intención. */
    private function misma_intencion(array $actual, array $agendar): bool
    {
        $origen = $agendar['evidencia_temporal'] ?? null;
        if (! is_array($origen) || (int) ($origen['mensaje_id'] ?? 0) !== (int) $actual['mensaje_id']
            || ($origen['tipo'] ?? '') !== $actual['tipo']) {
            return false;
        }
        if (! empty($agendar['ahora'])) {
            return $actual['tipo'] === 'ahora';
        }

        return $actual['tipo'] === 'momento'
            && ($origen['inicio'] ?? '') === $actual['inicio']
            && ($agendar['inicio_resuelto'] ?? '') === $actual['inicio'];
    }
}

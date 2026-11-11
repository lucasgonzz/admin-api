<?php

namespace App\Services;

use App\Mail\SupportCodexHealthMail;
use App\Models\AdminSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Vigilancia desde el servidor: puede avisar aunque la PC ya no llegue al admin. */
class SupportCodexHealthService
{
    const KEY = 'support_codex_monitor_health';
    const CLOSED_KEY_PREFIX = self::KEY . '.closed.';
    const RECIPIENT = 'lucasgonzalez5500@gmail.com';
    const TIMEOUT_SECONDS = 300;
    const REMINDER_SECONDS = 21600;
    const RECOVERY_SECONDS = 900;
    const HEALTHY_SECONDS = 180;
    const RECOVERY_REASONS = ['monitor_stale', 'poll_error', 'app_error', 'queue_stale', 'drain_timeout', 'restart_failed', 'recovery_timeout', 'monitor_recovered'];
    const TIMEZONE = 'America/Argentina/Buenos_Aires';
    const RESUME_HOUR = 7;
    const ERROR_CODES = ['starting', 'poll_stale', 'poll_error', 'queue_stale', 'app_error', 'dispatch_unknown', 'monitor_error'];

    public function heartbeat(array $data): array
    {
        return $this->locked(function (array $state) use ($data) {
            $now = now()->getTimestamp();
            if (($data['source'] ?? 'monitor') === 'supervisor') {
                return $this->supervisor($state, $data, $now);
            }
            $state['monitor_sequence'] = (int) ($state['monitor_sequence'] ?? 0) + 1;
            $state['armed_at'] = $state['armed_at'] ?? $now;
            $state['last_seen_at'] = $now;
            $state['status'] = $data['status'] ?? 'ok';
            $state['error_code'] = $state['status'] === 'error' ? ($data['error_code'] ?? 'monitor_error') : null;
            $state['monitor_version'] = $data['monitor_version'] ?? null;
            if ($state['status'] === 'ok') {
                $state['last_success_at'] = $now;
                $state['first_error_at'] = null;
            } else {
                $state['first_error_at'] = $state['first_error_at'] ?? $now;
            }
            return $this->close_recovered_incident($state, $now);
        });
    }

    private function healthy(array $state, int $now): bool
    {
        return ($state['status'] ?? '') === 'ok' && isset($state['last_success_at'])
            && $now - (int) $state['last_success_at'] < self::HEALTHY_SECONDS;
    }

    private function close_recovered_incident(array $state, int $now): array
    {
        $incident = $state['supervisor_incident'] ?? null;
        if ($incident && $this->healthy($state, $now)
            && (int) ($state['monitor_sequence'] ?? 0) > (int) $incident['monitor_sequence']) {
            // La secuencia distingue también dos pedidos recibidos dentro del mismo segundo.
            // Una fila por UUID: la fila de salud no crece hasta desbordar su columna TEXT.
            // Se escribe bajo el mismo lock y transacción que el cierre del incidente.
            DB::table('admin_settings')->insertOrIgnore(['key' => self::CLOSED_KEY_PREFIX . $incident['id'],
                'value' => '1', 'created_at' => now(), 'updated_at' => now()]);
            $state['supervisor_incident'] = null;
            $state['pending_supervisor_recovery'] = ! empty($state['alert_sent_at']);
            $state['alert_sent_at'] = null;
            $state['incident_started_at'] = null;
        }
        return $state;
    }

    private function supervisor(array $state, array $data, int $now): array
    {
        $state = $this->close_recovered_incident($state, $now);
        $id = strtolower($data['incident_id']);
        // No expirar ni truncar: un UUID retirado nunca puede volver a abrir una avería.
        if (DB::table('admin_settings')->where('key', self::CLOSED_KEY_PREFIX . $id)->exists()) {
            return $state;
        }
        $incident = $state['supervisor_incident'] ?? null;
        if ($incident && $incident['id'] !== $id) {
            abort(409, 'Ya existe una recuperación en curso.');
        }
        if (! $incident) {
            if ($data['recovery_state'] !== 'attempting') {
                abort(409, 'La recuperación debe comenzar con attempting.');
            }
            $incident = ['id' => $id, 'started_at' => $now,
                'monitor_sequence' => (int) ($state['monitor_sequence'] ?? 0), 'attempt' => 0];
            $state['pending_supervisor_recovery'] = false;
        }
        if ((int) $data['attempt'] < (int) $incident['attempt']) {
            return $state;
        }
        // Un fallo terminal no vuelve a esperar por un reintento de transporte tardío.
        if (($incident['state'] ?? '') !== 'failed') {
            $incident['state'] = $data['recovery_state'];
            $incident['reason_code'] = $data['reason_code'];
            $incident['attempt'] = (int) $data['attempt'];
        }
        $state['supervisor_incident'] = $incident;
        $state['last_supervisor_seen_at'] = $now;
        return $state;
    }

    public function test_mail(): void
    {
        $this->send_mail(new SupportCodexHealthMail('test', ['last_seen_at' => null, 'last_success_at' => null, 'reason' => 'prueba']));
    }

    private function send_mail(SupportCodexHealthMail $mail): void
    {
        // Log/array no prueban una salida real en producción y no consumen alertas.
        if (! app()->environment('testing') && config('mail.mailers.support_monitor.transport') !== 'smtp') {
            throw new \RuntimeException('El aviso requiere un transporte SMTP real.');
        }
        Mail::mailer('support_monitor')->to(self::RECIPIENT)->send($mail);
    }

    public function check(): array
    {
        // Apagar expresamente el proveedor es una pausa humana, no una avería.
        if (! SupportCodexSettings::enabled()) {
            return ['result' => 'disabled'];
        }
        return $this->locked(function (array $state) {
            $evaluacion = $this->evaluar($state);
            if ($evaluacion === null) {
                return $state;
            }
            $now = $evaluacion['now'];
            $resumeAt = $evaluacion['resume_at'];
            $unhealthy = $evaluacion['unhealthy'];
            $reason = $evaluacion['reason'];
            $incident = $evaluacion['incident'];
            $kind = null;
            if ($unhealthy && (empty($state['alert_sent_at']) || $now - (int) $state['alert_sent_at'] >= self::REMINDER_SECONDS)) {
                $kind = empty($state['alert_sent_at']) ? 'failure' : 'reminder';
            } elseif (! $incident && ! $unhealthy && ($state['status'] ?? '') === 'ok'
                && (! empty($state['alert_sent_at']) || (! empty($state['pending_supervisor_recovery']) && $this->healthy($state, $now)))
                && (int) ($state['last_seen_at'] ?? 0) >= $resumeAt) {
                $kind = 'recovery';
            }
            // Una falla del SMTP se reintenta al minuto siguiente, sin consumir el aviso.
            if ($kind !== null) {
                try {
                    $this->send_mail(new SupportCodexHealthMail($kind, [
                        'last_seen_at' => $this->date($state['last_seen_at'] ?? null),
                        'last_success_at' => $this->date($state['last_success_at'] ?? null),
                        'reason' => $reason,
                        'recovery_reason' => $incident['reason_code'] ?? null,
                    ]));
                    $state['mail_error_at'] = null;
                    if ($kind === 'recovery') {
                        $state['pending_supervisor_recovery'] = false;
                        $state['alert_sent_at'] = null;
                        $state['incident_started_at'] = null;
                    } else {
                        $state['alert_sent_at'] = $now;
                        $state['incident_started_at'] = $state['incident_started_at'] ?? $now;
                    }
                } catch (\Throwable $error) {
                    $state['mail_error_at'] = $now;
                    Log::warning('No se pudo enviar el aviso del monitor de soporte.', ['error_class' => get_class($error)]);
                }
            }
            return $state;
        });
    }

    /**
     * Si Martín está caído AHORA con el MISMO criterio del correo de vigilancia ({@see self::check()}): en falla
     * o sin pulso hace {@see self::TIMEOUT_SECONDS}, o con una recuperación del supervisor fallida o vencida.
     * Lo usa el respaldo automático de la prueba gratuita (misión prueba-ayuda-unificada-en-soporte, 10/11/2026)
     * para no inventar otra definición de «caído».
     *
     * Solo lee: no toma el candado de la fila ni manda nada. Con el proveedor apagado a mano (pausa humana) o sin
     * pulso armado da false.
     *
     * 🔴 A diferencia del correo, mide TAMBIÉN de noche. El correo calla antes de las 07:00 para no despertar a
     * Lucas, pero el respaldo existe justamente para la noche: un lead que se anota a las 2 de la mañana con la PC
     * de Martín apagada tiene que recibir respuesta. Por eso evalúa sin la hora de reanudar.
     *
     * @return bool
     */
    public function en_falla(): bool
    {
        if (! SupportCodexSettings::enabled()) {
            return false;
        }
        $state = json_decode((string) AdminSetting::where('key', self::KEY)->value('value'), true);
        $evaluacion = $this->evaluar(is_array($state) ? $state : [], false);

        return $evaluacion !== null && $evaluacion['unhealthy'];
    }

    /**
     * El diagnóstico de salud que comparten el correo y el respaldo de la prueba. null = no se evalúa (sin
     * pulso armado ni incidente, o, con horario, antes de la hora de reanudar).
     *
     * @param array<string, mixed> $state
     * @param bool $con_horario true para el correo (no mide antes de las 07:00 y cuenta desde esa hora); false para
     *                          el respaldo de la prueba, que mide a cualquier hora.
     *
     * @return array{now: int, resume_at: int, unhealthy: bool, reason: string, incident: array|null}|null
     */
    private function evaluar(array $state, bool $con_horario = true): ?array
    {
        if (empty($state['armed_at']) && empty($state['supervisor_incident'])) {
            return null;
        }
        $local = now(self::TIMEZONE);
        $resume = $local->copy()->setTime(self::RESUME_HOUR, 0);
        if ($con_horario && $local->lt($resume)) {
            return null;
        }
        $now = $local->getTimestamp();
        $resumeAt = $con_horario ? $resume->getTimestamp() : 0;
        $silent = $now - max($resumeAt, (int) ($state['last_seen_at'] ?? 0)) >= self::TIMEOUT_SECONDS;
        $failing = ! empty($state['first_error_at'])
            && $now - max($resumeAt, (int) $state['first_error_at']) >= self::TIMEOUT_SECONDS;
        $unhealthy = $silent || $failing;
        $reason = $silent ? 'sin_pulso' : ($state['error_code'] ?? 'monitor_error');
        $incident = $state['supervisor_incident'] ?? null;
        if ($incident) {
            $failed = $incident['state'] === 'failed';
            $expired = $now - max($resumeAt, (int) $incident['started_at']) >= self::RECOVERY_SECONDS;
            // Al reanudar a las 07:00 hay un presupuesto nuevo; los reportes no lo renuevan.
            $unhealthy = ($failed || $expired) && $now - $resumeAt >= self::TIMEOUT_SECONDS;
            $reason = $failed ? 'recovery_failed' : 'recovery_unverified';
        }

        return ['now' => $now, 'resume_at' => $resumeAt, 'unhealthy' => $unhealthy, 'reason' => $reason, 'incident' => $incident];
    }

    private function date($timestamp): ?string
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp)->timezone(self::TIMEZONE)->format('d/m/Y H:i:s') : null;
    }

    private function locked(callable $change): array
    {
        // Una única fila durable; el lock serializa pulso, scheduler y comandos manuales.
        return DB::transaction(function () use ($change) {
            DB::table('admin_settings')->insertOrIgnore(['key' => self::KEY, 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $row = AdminSetting::where('key', self::KEY)->lockForUpdate()->firstOrFail();
            $state = json_decode((string) $row->value, true);
            $state = $change(is_array($state) ? $state : []);
            $row->value = json_encode($state);
            $row->save();
            return $state;
        });
    }
}

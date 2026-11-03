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
    const RECIPIENT = 'lucasgonzalez5500@gmail.com';
    const TIMEOUT_SECONDS = 300;
    const REMINDER_SECONDS = 21600;
    const TIMEZONE = 'America/Argentina/Buenos_Aires';
    const RESUME_HOUR = 7;
    const ERROR_CODES = ['starting', 'poll_stale', 'poll_error', 'queue_stale', 'app_error', 'dispatch_unknown', 'monitor_error'];

    public function heartbeat(array $data): array
    {
        return $this->locked(function (array $state) use ($data) {
            $now = now()->getTimestamp();
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
            return $state;
        });
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
            if (empty($state['armed_at'])) {
                return $state;
            }
            $local = now(self::TIMEZONE);
            $resume = $local->copy()->setTime(self::RESUME_HOUR, 0);
            if ($local->lt($resume)) {
                return $state;
            }
            $now = $local->getTimestamp();
            $resumeAt = $resume->getTimestamp();
            $silent = $now - max($resumeAt, (int) $state['last_seen_at']) >= self::TIMEOUT_SECONDS;
            $failing = ! empty($state['first_error_at'])
                && $now - max($resumeAt, (int) $state['first_error_at']) >= self::TIMEOUT_SECONDS;
            $unhealthy = $silent || $failing;
            $kind = null;
            if ($unhealthy && (empty($state['alert_sent_at']) || $now - (int) $state['alert_sent_at'] >= self::REMINDER_SECONDS)) {
                $kind = empty($state['alert_sent_at']) ? 'failure' : 'reminder';
            } elseif (! $unhealthy && ($state['status'] ?? '') === 'ok' && ! empty($state['alert_sent_at'])
                && (int) $state['last_seen_at'] >= $resumeAt) {
                $kind = 'recovery';
            }
            // Una falla del SMTP se reintenta al minuto siguiente, sin consumir el aviso.
            if ($kind !== null) {
                try {
                    $this->send_mail(new SupportCodexHealthMail($kind, [
                        'last_seen_at' => $this->date($state['last_seen_at'] ?? null),
                        'last_success_at' => $this->date($state['last_success_at'] ?? null),
                        'reason' => $silent ? 'sin_pulso' : ($state['error_code'] ?? 'monitor_error'),
                    ]));
                    $state['mail_error_at'] = null;
                    if ($kind === 'recovery') {
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

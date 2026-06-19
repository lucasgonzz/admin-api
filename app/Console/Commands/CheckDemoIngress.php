<?php

namespace App\Console\Commands;

use App\Events\LeadSuggestionCreated;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Genera una sugerencia de check-in si el lead no confirmó acceso a la demo.
 *
 * Se ejecuta cada minuto. Busca leads en estado `demo_agendada` cuya demo
 * comenzó hace X minutos (configurable) sin confirmación de ingreso y sin
 * mensajes recientes que sugieran que ya está dentro.
 */
class CheckDemoIngress extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:check-demo-ingress';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Genera pregunta automática de check-in si el lead no confirmó acceso a la demo';

    /**
     * Palabras clave en mensajes del lead que indican que ya ingresó a la demo.
     *
     * @var array<string>
     */
    private const INGRESO_KEYWORDS = ['ingresé', 'ingrese', 'entré', 'entre', 'pude', 'ya estoy', 'sí', 'si'];

    /**
     * Procesa candidatos y genera sugerencia de check-in si corresponde.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Minutos post-inicio para verificar el ingreso según configuración. */
        $check_minutos = LeadDemoSettings::get_check_ingreso_minutos_post();

        /* Momento actual en timezone Argentina. */
        $now = Carbon::now('America/Argentina/Buenos_Aires');

        /*
         * Ventana de 4 minutos alrededor del momento exacto de check
         * para no perder el trigger con el scheduler de 1 minuto.
         */
        $target_demo_start_before = $now->copy()->subMinutes($check_minutos - 2);
        $target_demo_start_after  = $now->copy()->subMinutes($check_minutos + 2);

        /* Buscar leads con demo agendada, sin check enviado y sin sugerencia pendiente. */
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            ->where('demo_check_ingreso_enviado', false)
            ->where('tiene_sugerencia_pendiente', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->with('messages')
            ->get();

        /* Contador de mensajes generados para el log final. */
        $generated = 0;

        foreach ($candidates as $lead) {
            /* Construir datetime de inicio de demo en timezone Argentina. */
            $demo_datetime = $this->parse_demo_datetime(
                $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                (string) $lead->demo_start_time
            );

            if ($demo_datetime === null) {
                continue;
            }

            /*
             * Verificar que la demo haya empezado hace entre (check_minutos - 2) y (check_minutos + 2)
             * minutos (la demo_datetime debe estar en la ventana $target_demo_start_after..$target_demo_start_before).
             */
            if ($demo_datetime->gt($target_demo_start_before) || $demo_datetime->lt($target_demo_start_after)) {
                continue;
            }

            /* Si hay evidencia de ingreso en los mensajes recientes, no enviar. */
            if ($this->has_ingress_evidence($lead, $demo_datetime)) {
                /* Marcar igualmente para no volver a procesar este lead. */
                $lead->update(['demo_check_ingreso_enviado' => true]);
                continue;
            }

            /* Crear sugerencia de check-in sin llamar a Claude. */
            $contact_name = $lead->contact_name ?? 'cliente';
            LeadMessage::create([
                'lead_id'      => $lead->id,
                'sender'       => 'sistema',
                'status'       => 'sugerido',
                'is_followup'  => false,
                'content'      => "¡Hola {$contact_name}! ¿Pudiste ingresar a la demo sin problemas? 👋",
                'ai_reasoning' => "Check automático de ingreso a la demo. Enviado {$check_minutos} minutos después del inicio.",
            ]);

            /* Marcar flag y activar sugerencia pendiente. */
            $lead->update([
                'demo_check_ingreso_enviado' => true,
                'tiene_sugerencia_pendiente'  => true,
            ]);

            /* Notificar a admin-spa vía socket. */
            LeadSuggestionCreated::dispatch($lead->id);

            Log::info('CheckDemoIngress: check de ingreso generado', [
                'lead_id'       => $lead->id,
                'contact_name'  => $lead->contact_name,
                'demo_datetime' => $demo_datetime->toDateTimeString(),
            ]);

            $generated++;
        }

        $this->info("Checks de ingreso generados: {$generated}");

        return 0;
    }

    /**
     * Verifica si los mensajes del lead después del inicio de la demo contienen
     * evidencia de que ya ingresó (palabras clave o explicación de acceso).
     *
     * En caso de duda, devuelve false para no enviar (es mejor no molestar).
     *
     * @param Lead   $lead          Lead con relación messages cargada.
     * @param Carbon $demo_datetime Datetime de inicio de la demo.
     *
     * @return bool true si hay evidencia de ingreso.
     */
    protected function has_ingress_evidence(Lead $lead, Carbon $demo_datetime): bool
    {
        /* Mensajes del lead enviados DESPUÉS del inicio de la demo. */
        $recent_lead_messages = $lead->messages->filter(function ($msg) use ($demo_datetime) {
            return (string) $msg->sender === 'lead'
                && $msg->created_at !== null
                && $msg->created_at->gt($demo_datetime);
        });

        foreach ($recent_lead_messages as $msg) {
            $content_lower = mb_strtolower((string) $msg->content);
            foreach (self::INGRESO_KEYWORDS as $keyword) {
                if (str_contains($content_lower, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parsea el datetime de inicio de demo a partir de fecha (Y-m-d) y hora (H:i o similar).
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en texto libre.
     *
     * @return Carbon|null
     */
    protected function parse_demo_datetime(string $date, string $time): ?Carbon
    {
        try {
            return Carbon::parse("{$date} {$time}");
        } catch (\Exception $e) {
            return null;
        }
    }
}

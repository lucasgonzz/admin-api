<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadDemoSettings;
use App\Helpers\AppTime;
use App\Services\RecallService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Manda el bot de Recall.ai a la reunión del closer cuando la llamada está próxima a comenzar.
 *
 * Se ejecuta cada minuto. Busca leads en estado `demo_realizada` cuya llamada del closer
 * está dentro de la ventana configurable (recall_bot_minutos_antes) y que aún no tienen
 * un bot de Recall asignado (recall_bot_id IS NULL).
 *
 * La llamada del closer empieza al finalizar la demo más la gracia post-demo:
 *   closer_call_start = demo_date + demo_start_time + duracion_minutos + gracia_minutos_post
 */
class SendRecallBot extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:send-recall-bot';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Manda el bot de Recall.ai a la reunión del closer cuando está próxima';

    /**
     * Busca leads candidatos y envía el bot de Recall a las reuniones próximas.
     *
     * @param RecallService $recall_service Servicio de comunicación con la API de Recall.ai.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(RecallService $recall_service): int
    {
        /* Minutos antes del inicio de la llamada para enviar el bot, según configuración. */
        $minutos_antes = LeadDemoSettings::get_recall_bot_minutos_antes();

        /* Duración de la demo y gracia post-demo para calcular cuándo empieza la llamada. */
        $duracion_minutos  = LeadDemoSettings::get_duracion_minutos();
        $gracia_minutos    = LeadDemoSettings::get_gracia_minutos_post();

        /* Momento actual en timezone Argentina (misma lógica que los demás commands de demo). */
        $now = AppTime::now();

        /* Buscar leads candidatos: demo realizada, con meet_url, sin bot Recall enviado, con fecha de hoy. */
        $candidates = Lead::query()
            ->where('status', 'demo_realizada')
            ->whereNotNull('meet_url')
            ->whereNull('recall_bot_id')
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->get();

        /* Contador de bots enviados para el log final. */
        $enviados = 0;

        foreach ($candidates as $lead) {
            /* Construir el datetime de inicio de la demo combinando fecha y hora. */
            $demo_datetime = $this->parse_demo_datetime(
                $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                (string) $lead->demo_start_time
            );

            /* Si el formato de hora es inválido, saltar sin romper el batch. */
            if ($demo_datetime === null) {
                Log::warning('[SEND_RECALL_BOT] No se pudo parsear demo_start_time.', [
                    'lead_id'         => $lead->id,
                    'demo_start_time' => $lead->demo_start_time,
                ]);
                continue;
            }

            /*
             * Calcular el momento de inicio de la llamada del closer:
             *   closer_call_start = inicio_demo + duración_demo + gracia_post_demo
             */
            $closer_call_start = $demo_datetime->copy()->addMinutes($duracion_minutos + $gracia_minutos);

            /*
             * Ventana de envío del bot:
             *   desde: closer_call_start - minutos_antes - 1 min (tolerancia para el cron de 1 min)
             *   hasta: closer_call_start (la llamada no empezó aún)
             *
             * El bot debe entrar antes de que empiece la llamada, no después.
             */
            $window_start = $closer_call_start->copy()->subMinutes($minutos_antes + 1);
            $window_end   = $closer_call_start->copy();

            /* Solo enviar si estamos dentro de la ventana de tiempo. */
            if ($now->lt($window_start) || $now->gt($window_end)) {
                continue;
            }

            Log::info('[SEND_RECALL_BOT] Enviando bot de Recall al lead.', [
                'lead_id'           => $lead->id,
                'contact_name'      => $lead->contact_name,
                'closer_call_start' => $closer_call_start->toDateTimeString(),
                'meet_url'          => $lead->meet_url,
            ]);

            /* Delegar al servicio que maneja la comunicación con Recall.ai. */
            $recall_service->send_bot_for_lead($lead);

            $enviados++;
        }

        $this->info("Bots de Recall enviados: {$enviados}");

        return 0;
    }

    /**
     * Parsea el datetime de inicio de demo a partir de fecha (Y-m-d) y hora (H:i o similar).
     *
     * Devuelve null si el formato no es válido para evitar errores en el batch.
     *
     * @param string $date Fecha en formato Y-m-d.
     * @param string $time Hora en texto libre (ej: "09:00" o "9:30").
     *
     * @return Carbon|null
     */
    protected function parse_demo_datetime(string $date, string $time): ?Carbon
    {
        try {
            return Carbon::parse("{$date} {$time}", 'America/Argentina/Buenos_Aires');
        } catch (\Exception $e) {
            return null;
        }
    }
}

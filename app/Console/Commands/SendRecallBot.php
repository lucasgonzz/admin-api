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
        /* Comando retirado (grupo 117): el envío del bot pasó a ser por llamada (LeadCall)
         * vía LeadCallController. Este comando escribía recall_bot_id en el lead, que el
         * webhook nuevo ya no rutea. Se deja como no-op para no romper si quedó programado
         * o se corre a mano. */
        Log::info('[SEND_RECALL_BOT] Comando retirado: el bot ahora se manda por llamada (LeadCall). No-op.');

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

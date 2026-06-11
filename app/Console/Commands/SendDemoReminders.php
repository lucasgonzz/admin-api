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
 * Genera automáticamente el mensaje de recordatorio pre-demo como sugerencia pendiente.
 *
 * Se ejecuta cada 5 minutos. Busca leads con demo agendada en los próximos 20 minutos
 * y crea un LeadMessage con el texto fijo del protocolo (Mensaje 3C), sin llamar a Claude.
 * El flag `recordatorio_demo_enviado` evita que se genere más de un recordatorio por demo.
 */
class SendDemoReminders extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:send-demo-reminders';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Genera recordatorios pre-demo como sugerencias pendientes para el setter';

    /**
     * Procesa todos los leads candidatos y genera el recordatorio correspondiente.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        // Momento actual y límite superior de la ventana de anticipación (timezone Argentina).
        $now = Carbon::now('America/Argentina/Buenos_Aires');

        // Ventana de anticipación dinámica: se lee del setting configurable para poder ajustarla
        // sin redeploy; si no hay setting configurado, el default del servicio es 15 minutos.
        $window_minutes = LeadDemoSettings::get_recordatorio_minutos_antes();
        $window_end     = $now->copy()->addMinutes($window_minutes);

        // Leads candidatos: demo agendada hoy, sin recordatorio emitido y sin sugerencia pendiente.
        // Se usa CONVERT_TZ para comparar demo_date (guardado en UTC) contra la fecha en Argentina.
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            ->where('recordatorio_demo_enviado', false)
            ->where('tiene_sugerencia_pendiente', false)
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereRaw("DATE(CONVERT_TZ(demo_date, '+00:00', '-03:00')) = ?", [$now->format('Y-m-d')])
            ->get();

        // Contador de recordatorios generados para el log final.
        $generated = 0;

        foreach ($candidates as $lead) {
            // Construir el datetime completo de inicio de demo combinando fecha y hora.
            $demo_datetime = $this->parse_demo_datetime(
                $lead->demo_date->setTimezone('America/Argentina/Buenos_Aires')->format('Y-m-d'),
                $lead->demo_start_time
            );

            // Si el formato de hora es inválido, saltear para no romper el batch.
            if ($demo_datetime === null) {
                Log::warning('SendDemoReminders: no se pudo parsear demo_start_time', [
                    'lead_id'         => $lead->id,
                    'demo_start_time' => $lead->demo_start_time,
                ]);

                continue;
            }

            // Verificar que la demo esté dentro de la ventana [ahora, ahora + 20 min].
            if ($demo_datetime->lt($now) || $demo_datetime->gt($window_end)) {
                continue;
            }

            // Crear el mensaje de recordatorio como sugerencia pendiente (sin llamar a Claude).
            $this->create_reminder_message($lead, $demo_datetime);

            // Marcar que ya se generó el recordatorio y activar flag de sugerencia.
            $lead->update([
                'recordatorio_demo_enviado' => true,
                'tiene_sugerencia_pendiente' => true,
            ]);

            // Notificar a admin-spa vía socket para actualizar la fila del lead en tiempo real.
            LeadSuggestionCreated::dispatch($lead->id);

            Log::info('SendDemoReminders: recordatorio generado', [
                'lead_id'       => $lead->id,
                'contact_name'  => $lead->contact_name,
                'demo_datetime' => $demo_datetime->toDateTimeString(),
            ]);

            $generated++;
        }

        $this->info("Recordatorios generados: {$generated}");

        return 0;
    }

    /**
     * Construye y persiste el LeadMessage de recordatorio pre-demo.
     *
     * El contenido es el Mensaje 3C del protocolo (hardcodeado, no requiere Claude).
     * El ai_reasoning incluye la hora de la demo para contextualizar al setter.
     *
     * @param Lead   $lead          Lead al que pertenece el mensaje.
     * @param Carbon $demo_datetime Datetime completo de inicio de demo.
     *
     * @return void
     */
    protected function create_reminder_message(Lead $lead, Carbon $demo_datetime): void
    {
        // Nombre de contacto del lead para personalizar el saludo del mensaje.
        $contact_name = $lead->contact_name ?? 'Cliente';

        // Hora formateada de la demo para incluirla en el ai_reasoning del setter.
        $demo_hour = $demo_datetime->format('H:i');

        // Texto fijo del Mensaje 3C del protocolo de recordatorio pre-demo.
        $content = "Hola {$contact_name}! En unos minutos ya tenés disponible el acceso a la demo de ComercioCity.\n\n"
            . "Un consejo antes de entrar: empezá por el video introductorio que te mandamos al mail, "
            . "son 3 minutos y te van a ayudar a entender qué mirar cuando entrés al sistema.\n\n"
            . "Cualquier duda que surja mientras recorrés la plataforma, escribime por acá. 👋";

        // Razonamiento para el setter: explica el origen automático y cuándo enviar el mensaje.
        $ai_reasoning = "Recordatorio automático pre-demo. La demo está programada para las {$demo_hour}. "
            . "Enviá este mensaje 15 minutos antes.";

        LeadMessage::create([
            'lead_id'      => $lead->id,
            'sender'       => 'sistema',
            'content'      => $content,
            'status'       => 'sugerido',
            'is_followup'  => false,
            'ai_reasoning' => $ai_reasoning,
        ]);
    }

    /**
     * Parsea el datetime de inicio de demo a partir de fecha (Y-m-d) y hora (H:i o similar).
     *
     * Devuelve null si el formato no es válido para evitar errores en el batch.
     *
     * @param string $date  Fecha en formato Y-m-d (p. ej. "2026-05-20").
     * @param string $time  Hora en texto libre (p. ej. "09:00" o "9:30").
     *
     * @return Carbon|null
     */
    protected function parse_demo_datetime(string $date, string $time): ?Carbon
    {
        try {
            // Carbon::parse acepta formatos parciales como "9:00" además de "09:00".
            return Carbon::parse("{$date} {$time}");
        } catch (\Exception $e) {
            return null;
        }
    }
}


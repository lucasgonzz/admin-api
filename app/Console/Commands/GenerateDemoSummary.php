<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadDemoSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Genera el resumen del lead con Claude X minutos antes de que finalice la demo.
 *
 * Se ejecuta cada minuto. Busca leads en estado `demo_agendada` cuyo fin de demo
 * se acerca en los próximos X minutos (configurable), carga el historial de mensajes
 * y le pide a Claude un resumen orientado al closer.
 */
class GenerateDemoSummary extends Command
{
    /**
     * Nombre del comando artisan.
     *
     * @var string
     */
    protected $signature = 'leads:generate-demo-summary';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Genera resumen del lead con Claude X minutos antes de que finalice la demo';

    /**
     * System prompt enviado a Claude para generar el resumen del lead.
     *
     * @var string
     */
    private const SYSTEM_PROMPT = 'Sos un asistente de ventas. Tu tarea es generar un resumen breve del perfil de este lead '
        . 'para que el closer pueda llamarlo inmediatamente después de la demo con todo el contexto necesario. '
        . 'El resumen debe incluir: tipo de negocio, cantidad de empleados, dolores principales que mencionó, '
        . 'qué funcionalidades le interesaron (si las mencionó), objeciones que planteó, preguntas que hizo, '
        . 'y cualquier información relevante para el cierre. '
        . 'Máximo 200 palabras. Sin bullets. Prosa natural.';

    /**
     * Procesa candidatos y genera el resumen con Claude.
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Leer parámetros de timing desde configuración. */
        $resumen_minutos = LeadDemoSettings::get_resumen_minutos_antes_fin();
        $duracion        = LeadDemoSettings::get_duracion_minutos();

        /* Momento actual en timezone Argentina. */
        $now = Carbon::now('America/Argentina/Buenos_Aires');

        /* Buscar leads con demo agendada, sin resumen generado aún. */
        $candidates = Lead::query()
            ->where('status', 'demo_agendada')
            ->where(function ($q) {
                $q->whereNull('demo_summary')->orWhere('demo_summary', '');
            })
            ->whereNotNull('demo_date')
            ->whereNotNull('demo_start_time')
            ->whereDate('demo_date', $now->format('Y-m-d'))
            ->with('messages')
            ->get();

        /* Contador de resúmenes generados para el log final. */
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
             * Momento objetivo para generar el resumen:
             * inicio de demo + duración - minutos antes del fin configurados.
             */
            $summary_target = $demo_datetime->copy()->addMinutes($duracion - $resumen_minutos);

            /*
             * Ventana de 4 minutos alrededor del momento objetivo para no perder
             * el trigger exacto con el scheduler de 1 minuto.
             */
            $window_start = $summary_target->copy()->subMinutes(2);
            $window_end   = $summary_target->copy()->addMinutes(2);

            /* Verificar que estemos dentro de la ventana de generación. */
            if ($now->lt($window_start) || $now->gt($window_end)) {
                continue;
            }

            /* Construir historial de mensajes para el user content. */
            $user_content = $this->build_history_content($lead);

            try {
                /* Llamar a Claude para generar el resumen. */
                $summary = $this->call_claude($user_content);

                if ($summary === '') {
                    Log::warning('GenerateDemoSummary: Claude devolvió respuesta vacía', [
                        'lead_id' => $lead->id,
                    ]);
                    continue;
                }

                /* Guardar el resumen en el lead. */
                $lead->update(['demo_summary' => $summary]);

                Log::info('GenerateDemoSummary: resumen generado', [
                    'lead_id'      => $lead->id,
                    'contact_name' => $lead->contact_name,
                ]);

                $generated++;
            } catch (\Throwable $e) {
                Log::error('GenerateDemoSummary: error al generar resumen', [
                    'lead_id' => $lead->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->info("Resúmenes generados: {$generated}");

        return 0;
    }

    /**
     * Construye el contenido de usuario con el historial completo de mensajes del lead.
     *
     * No incluye la pregunta "¿Qué respuesta sugerís?" ya que el objetivo es un resumen,
     * no una sugerencia de respuesta.
     *
     * @param Lead $lead Lead con relación messages cargada.
     *
     * @return string
     */
    protected function build_history_content(Lead $lead): string
    {
        /* Datos básicos del lead para dar contexto al resumen. */
        $txt = "Lead: " . ($lead->contact_name ?? '(sin nombre)') . "\n";
        $txt .= "Empresa: " . ($lead->company_name ?? '(sin empresa)') . "\n";
        $txt .= "Email: " . ($lead->email ?? '(sin email)') . "\n\n";
        $txt .= "HISTORIAL DE MENSAJES:\n";

        /* Ordenar mensajes por fecha de creación. */
        $sorted = $lead->messages->sortBy('created_at');
        foreach ($sorted as $msg) {
            $sender  = (string) $msg->sender;
            $content = trim((string) $msg->content);
            $label   = $sender === 'lead' ? 'LEAD' : 'SETTER';
            $txt .= "[{$label}]: {$content}\n";
        }

        $txt .= "\nGenerá el resumen del lead para el closer.";

        return $txt;
    }

    /**
     * Envía el historial a Claude y devuelve el texto del resumen generado.
     *
     * Usa la misma configuración HTTP que LeadAiService (API key, headers, timeout, TLS).
     *
     * @param string $user_content Historial de mensajes del lead.
     *
     * @throws \RuntimeException Si la API key no está configurada o falla la llamada.
     *
     * @return string Resumen generado por Claude.
     */
    protected function call_claude(string $user_content): string
    {
        /* Validar que la API key esté configurada. */
        $api_key = (string) config('services.anthropic.api_key');
        if ($api_key === '') {
            throw new \RuntimeException('ANTHROPIC_API_KEY no está configurada.');
        }

        /* Construir cliente HTTP con la misma configuración que LeadAiService. */
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(90);

        /* Configuración TLS según entorno (cacert para WAMP en Windows). */
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');
        if (! $verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        /* Modelo configurable, con fallback al sonnet actual. */
        $model = (string) config('services.anthropic.model', 'claude-sonnet-4-20250514');

        $response = $http->post('https://api.anthropic.com/v1/messages', [
            'model'      => $model,
            'max_tokens' => 600,
            'system'     => self::SYSTEM_PROMPT,
            'messages'   => [
                ['role' => 'user', 'content' => $user_content],
            ],
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Error Anthropic HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        /* Extraer texto del bloque de contenido de la respuesta. */
        $body = $response->json();
        $text = '';
        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && isset($block['text'])) {
                    $text .= (string) $block['text'];
                }
            }
        }

        return trim($text);
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

<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Helpers\AppTime;
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
     * System prompt enviado a Claude para generar el resumen estructurado del lead.
     *
     * Solicita ÚNICAMENTE un JSON válido con 7 claves:
     * - resumen_textual: prosa orientada al closer (máx. 200 palabras)
     * - empresa, situacion_actual, funcionalidades, puntos_dolor: campos de texto existentes
     * - precio_sugerido: objeto con scoring interno de precio (uso exclusivo del equipo)
     * - temperatura: objeto con nivel de interés del lead (uso exclusivo del equipo)
     *
     * @var string
     */
    private const SYSTEM_PROMPT = 'Sos un asistente de ventas. Analizá la conversación del lead y devolvé ÚNICAMENTE un JSON válido '
        . '(sin backticks, sin texto adicional, sin explicaciones) con exactamente estas 7 claves: '
        . '- resumen_textual: resumen en prosa natural (máximo 200 palabras) orientado al closer, con tipo de negocio, empleados, dolores, funcionalidades de interés, objeciones y datos clave para el cierre. '
        . '- empresa: una o dos frases sobre a qué se dedica el negocio y cuántos empleados tiene. '
        . '- situacion_actual: qué sistema o herramienta usa actualmente para gestionar su negocio (si no lo mencionó, escribir "No especificó"). '
        . '- funcionalidades: funcionalidades de ComercioCity que le interesaron o preguntó durante la conversación. '
        . '- puntos_dolor: principales dolores o problemas con su situación actual. '
        . '- precio_sugerido: objeto interno (nunca mostrar al lead) con las subclaves precio_base (número 500, 1000 o 1500), incluye_ecommerce (booleano), total (número final en USD), bono (número en USD o null si no aplica) y razonamiento (una o dos frases explicando qué señales detectaste). '
        . 'Para calcular precio_sugerido, detectá estas señales en la conversación. SEÑALES ALTAS: (1) más de una sucursal — menciona dos o más locales, depósitos o puntos de venta; (2) mayorista/distribuidor — se presenta como mayorista, distribuidor o vende a revendedores. '
        . 'SEÑALES MEDIAS: (1) cuenta corriente — menciona fiado, deudas de clientes, cuentas a cobrar/pagar o trabaja con crédito; (2) quiere ecommerce — pide tienda online, integración con Mercado Libre o Tienda Nube, o ventas por internet. '
        . 'Precio base según señales: sin señales altas ni dos medias juntas → precio_base 500; una señal alta O dos señales medias → precio_base 1000; dos señales altas O una alta más una media → precio_base 1500. '
        . 'Si quiere ecommerce, sumá al precio base: base 500 +200=total 700 bono 200; base 1000 +300=total 1300 bono 300; base 1500 +500=total 2000 bono 500. '
        . 'Si NO quiere ecommerce: base 500 bono 100; base 1000 bono null; base 1500 bono null. '
        . '- temperatura: objeto interno con nivel ("alta", "media" o "baja") y razonamiento (una o dos frases basadas en lo que dijo el lead). '
        . 'Nivel alta: menciona urgencia, problema activo que le duele hoy, pregunta por implementación o plazos, dice que lo necesita ya. '
        . 'Nivel media: interés genuino sin apuro, hace preguntas de funcionalidades, no menciona urgencia concreta. '
        . 'Nivel baja: solo averigua, "viendo opciones", no cuenta un dolor concreto, respuestas cortas sin profundidad. '
        . 'Devolvé SOLO el JSON, nada más.';

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
        $now = AppTime::now();

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
                /*
                 * Llamar a Claude: devuelve un array con las 7 claves del resumen estructurado.
                 * call_claude() lanza RuntimeException si el JSON no es válido.
                 */
                $result  = $this->call_claude($user_content);

                /* resumen_textual es el campo principal; sin él no hay resumen útil. */
                $summary = trim((string) ($result['resumen_textual'] ?? ''));

                if ($summary === '') {
                    Log::warning('GenerateDemoSummary: resumen_textual vacío', [
                        'lead_id' => $lead->id,
                    ]);
                    continue;
                }

                /* Claves estructuradas que van en el campo JSON separado (texto + objetos internos). */
                $structured = [
                    'empresa'          => trim((string) ($result['empresa']          ?? '')),
                    'situacion_actual' => trim((string) ($result['situacion_actual'] ?? '')),
                    'funcionalidades'  => trim((string) ($result['funcionalidades']  ?? '')),
                    'puntos_dolor'     => trim((string) ($result['puntos_dolor']     ?? '')),
                    'precio_sugerido'  => is_array($result['precio_sugerido'] ?? null) ? $result['precio_sugerido'] : null,
                    'temperatura'      => is_array($result['temperatura']     ?? null) ? $result['temperatura']     : null,
                ];

                /* Guardar resumen textual + resumen estructurado en el lead. */
                $lead->update([
                    'demo_summary'            => $summary,
                    /* El cast 'array' en Lead::$casts serializa automáticamente $structured a JSON. */
                    'demo_summary_structured' => $structured,
                ]);

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
     * Envía el historial a Claude y devuelve el array con el resumen estructurado.
     *
     * Claude debe responder ÚNICAMENTE con un JSON válido con las claves:
     * resumen_textual, empresa, situacion_actual, funcionalidades, puntos_dolor,
     * precio_sugerido y temperatura.
     *
     * Usa la misma configuración HTTP que LeadAiService (API key, headers, timeout, TLS).
     *
     * @param string $user_content Historial de mensajes del lead.
     *
     * @throws \RuntimeException Si la API key no está configurada, falla la llamada,
     *                           o Claude no devuelve JSON válido.
     *
     * @return array Array PHP con las 7 claves del resumen estructurado.
     */
    protected function call_claude(string $user_content): array
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
            /* max_tokens aumentado a 1000 para dar espacio al JSON estructurado de 7 claves. */
            'max_tokens' => 1000,
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

        /* Concatenar todos los bloques de texto de la respuesta. */
        $body = $response->json();
        $raw  = '';
        if (isset($body['content']) && is_array($body['content'])) {
            foreach ($body['content'] as $block) {
                if (is_array($block) && isset($block['text'])) {
                    $raw .= (string) $block['text'];
                }
            }
        }
        $raw = trim($raw);

        /* Limpiar posibles backticks de markdown que Claude podría agregar. */
        $raw = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/i', '$1', $raw);

        /*
         * Extraer el primer objeto JSON válido entre el primer { y el último }.
         * Cubre casos donde Claude agregue texto antes o después del JSON.
         */
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $json   = substr($raw, $start, $end - $start + 1);
            $parsed = json_decode($json, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        throw new \RuntimeException('Claude no devolvió JSON válido: ' . $raw);
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

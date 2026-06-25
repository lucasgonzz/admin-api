<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Lead;
use App\Models\LeadPartner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Procesa la transcripción de la llamada del closer con Claude y persiste el resumen.
 *
 * Responsabilidades:
 * - Llamar a Claude con la transcripción para extraer el resumen estructurado.
 * - Guardar el JSON del resumen en leads.call_summary.
 * - Notificar al equipo (closer y otros admins suscritos) por WhatsApp con el resumen.
 */
class CallSummaryService
{
    /** Endpoint de la API de Anthropic para mensajes. */
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Modelo de Claude usado para esta tarea: Haiku es suficiente para extracción de JSON
     * y es el más económico para producción.
     */
    private const MODEL = 'claude-haiku-4-5-20251001';

    /** @var WhatsappSendService Servicio de envío WhatsApp para notificar al equipo. */
    private $whatsapp_send_service;

    /**
     * System prompt para la extracción del resumen estructurado de la llamada.
     *
     * Instruye a Claude a devolver únicamente un JSON válido con la estructura definida.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
Sos un asistente de ventas que analiza transcripciones de llamadas de cierre comercial.

Tu tarea es extraer un resumen estructurado de la transcripción que se te proporciona. La llamada es entre un closer comercial (Tommy) y un potencial cliente (lead) sobre un sistema de gestión comercial llamado ComercioCity.

Respondé ÚNICAMENTE con un objeto JSON válido, sin texto adicional, sin explicaciones, sin bloques de código markdown. Solo el JSON puro.

Estructura exacta a devolver:
{
  "fecha_llamada": "YYYY-MM-DD",
  "duracion_minutos": número entero o null,
  "resumen_general": "Texto libre con lo más relevante de la llamada.",
  "lo_que_planteo_el_lead": "Qué dijo el lead, sus dudas y preguntas.",
  "puntos_de_dolor": ["punto 1", "punto 2"],
  "sugerencias_del_closer": ["sugerencia 1", "sugerencia 2"],
  "precio_acordado": {
    "licencia_usd": número o null,
    "mensualidad_usd": número o null,
    "nota": "Texto libre, ej: se mencionó bono acción rápida"
  },
  "modificaciones_requeridas": ["modificación 1"],
  "modulos_de_interes": ["stock", "facturacion", "ecommerce"],
  "personas_adicionales": [
    {
      "nombre": "string o null",
      "telefono": "string o null (solo dígitos, sin espacios ni guiones)",
      "rol": "string corto — ej: socio, esposa, contador, socio comercial"
    }
  ],
  "escenario_cierre": "A",
  "proximo_paso": "Descripción del próximo paso acordado.",
  "transcripcion_completa": "Tommy: hola...\nLead: bien..."
}

Para personas_adicionales: detectá si durante la llamada el lead mencionó o presentó a otras personas
que participan en la decisión de compra o en el negocio (socios, cónyuge, contador, socio comercial, etc.).
Solo incluir personas que el lead mencionó con nombre o número de teléfono, o que participaron activamente
en la llamada. Si no hay ninguna, devolvé [].
No incluir al closer (Tommy) ni al lead principal.
Para personas_adicionales: devolvé [] si no hay información.

Valores válidos de escenario_cierre:
- "A": cerró en llamada (compró o acordó términos definitivos)
- "B": requiere reunión con Lucas (el lead quiere hablar con el dueño/director)
- "C": seguimiento con Claude (interesado pero no decidió aún)
- "D": no quiere avanzar (descartado)
- null: si no queda claro el resultado

Para duracion_minutos: estimá la duración en base al contenido si no está disponible explícitamente.
Para precio_acordado: si no se mencionaron precios, devolvé null en licencia_usd y mensualidad_usd.
Para arrays vacíos: devolvé [] si no hay información para ese campo.
PROMPT;

    /**
     * Constructor.
     *
     * @param WhatsappSendService $whatsapp_send_service Servicio de envío WhatsApp.
     */
    public function __construct(WhatsappSendService $whatsapp_send_service)
    {
        $this->whatsapp_send_service = $whatsapp_send_service;
    }

    /**
     * Procesa la transcripción del lead: extrae el resumen con Claude, lo persiste
     * en el lead y notifica al equipo por WhatsApp.
     *
     * Esta es la función principal del servicio, pensada para llamarse desde el
     * webhook de Recall cuando el bot termina la reunión.
     *
     * @param Lead   $lead            Lead al que pertenece la llamada.
     * @param string $transcript_text Transcripción formateada en texto plano ("Speaker: texto\n...").
     *
     * @return void
     */
    public function process_transcript_for_lead(Lead $lead, string $transcript_text): void
    {
        Log::channel('daily')->info('[CALL_SUMMARY] Procesando transcripción.', [
            'lead_id'          => $lead->id,
            'transcript_chars' => strlen($transcript_text),
        ]);

        /* Llamar a Claude para extraer el resumen estructurado. */
        $summary = $this->extract_summary_from_transcript($lead, $transcript_text);

        if (!$summary) {
            Log::channel('daily')->warning('[CALL_SUMMARY] No se pudo extraer el resumen de la transcripción.', [
                'lead_id' => $lead->id,
            ]);
            return;
        }

        /* Persistir el resumen en el lead. */
        $lead->update(['call_summary' => $summary]);

        Log::channel('daily')->info('[CALL_SUMMARY] Resumen guardado en el lead.', [
            'lead_id'          => $lead->id,
            'escenario_cierre' => $summary['escenario_cierre'] ?? null,
        ]);

        /*
         * Crear socios sugeridos desde la transcripción (pendientes de confirmación del closer).
         * personas_adicionales queda también persistido dentro de call_summary automáticamente.
         */
        $partners_created = 0;
        if (!empty($summary['personas_adicionales']) && is_array($summary['personas_adicionales'])) {
            foreach ($summary['personas_adicionales'] as $persona) {
                /* Datos detectados por Claude en la transcripción de Recall. */
                $nombre   = trim((string) ($persona['nombre']   ?? ''));
                $telefono = trim((string) ($persona['telefono'] ?? ''));
                $rol      = trim((string) ($persona['rol']      ?? ''));

                /* Solo crear si hay al menos nombre o teléfono. */
                if ($nombre === '' && $telefono === '') {
                    continue;
                }

                LeadPartner::create([
                    'lead_id'              => $lead->id,
                    'name'                 => $nombre !== '' ? $nombre : null,
                    'phone'                => $telefono !== '' ? $telefono : null,
                    'notes'                => $rol !== '' ? "Rol: {$rol}" : null,
                    'source'               => 'call_transcript',
                    'pending_confirmation' => true,
                ]);
                $partners_created++;
            }
        }

        /* Refrescar el panel del closer si se crearon socios sugeridos. */
        if ($partners_created > 0) {
            LeadBroadcastService::emit_conversation_updated((int) $lead->id, 0);

            Log::channel('daily')->info('[CALL_SUMMARY] Socios sugeridos creados desde transcripción.', [
                'lead_id'          => $lead->id,
                'partners_created' => $partners_created,
            ]);
        }

        /* Generar sugerencia de seguimiento para el closer basada en el resumen de la llamada. */
        app(CloserFollowupService::class)->generate_followup_from_summary($lead);

        /* Notificar al equipo por WhatsApp. */
        $this->notify_team($lead, $summary);
    }

    /**
     * Llama a Claude con la transcripción y extrae el JSON estructurado del resumen.
     *
     * Usa claude-haiku por ser suficiente para extracción de JSON y más económico.
     * Devuelve null si la API falla o si la respuesta no es JSON válido.
     *
     * @param Lead   $lead            Lead al que pertenece la transcripción.
     * @param string $transcript_text Transcripción en texto plano.
     *
     * @return array|null Array PHP con el resumen estructurado, o null si falla.
     */
    protected function extract_summary_from_transcript(Lead $lead, string $transcript_text): ?array
    {
        /* Validar que la API key esté configurada. */
        $api_key = (string) config('services.anthropic.api_key');
        if ($api_key === '') {
            Log::channel('daily')->error('[CALL_SUMMARY] ANTHROPIC_API_KEY no está configurada.', [
                'lead_id' => $lead->id,
            ]);
            return null;
        }

        /* Construir cliente HTTP con los mismos headers que el resto del proyecto. */
        $http = Http::withHeaders([
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout(90);

        /* Configuración TLS según entorno (WAMP en Windows requiere cacert explícito). */
        $verify_ssl = (bool) config('services.anthropic.verify_ssl', true);
        $ca_bundle  = config('services.anthropic.ca_bundle');
        if (!$verify_ssl) {
            $http = $http->withoutVerifying();
        } elseif (is_string($ca_bundle) && $ca_bundle !== '' && is_file($ca_bundle)) {
            $http = $http->withOptions(['verify' => $ca_bundle]);
        }

        try {
            $response = $http->post(self::ANTHROPIC_API_URL, [
                'model'      => self::MODEL,
                /* max_tokens generoso para acomodar el JSON completo con la transcripción incluida. */
                'max_tokens' => 4000,
                'system'     => self::SYSTEM_PROMPT,
                'messages'   => [
                    ['role' => 'user', 'content' => $transcript_text],
                ],
            ]);

            if ($response->failed()) {
                Log::channel('daily')->warning('[CALL_SUMMARY] Error llamando a Claude API.', [
                    'lead_id' => $lead->id,
                    'status'  => $response->status(),
                    'body'    => substr($response->body(), 0, 500),
                ]);
                return null;
            }

            /* Concatenar todos los bloques de texto de la respuesta (misma lógica que GenerateDemoSummary). */
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

            /* Limpiar posibles backticks de markdown que Claude podría incluir. */
            $raw = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/i', '$1', $raw);

            /*
             * Extraer el primer objeto JSON válido entre el primer { y el último }.
             * Cubre casos donde Claude agregue texto antes o después del JSON.
             */
            $start = strpos($raw, '{');
            $end   = strrpos($raw, '}');
            if ($start === false || $end === false || $end <= $start) {
                Log::channel('daily')->warning('[CALL_SUMMARY] No se encontró un bloque JSON en la respuesta de Claude.', [
                    'lead_id' => $lead->id,
                    'raw'     => substr($raw, 0, 500),
                ]);
                return null;
            }
            $raw = substr($raw, $start, $end - $start + 1);

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                Log::channel('daily')->warning('[CALL_SUMMARY] JSON inválido de Claude.', [
                    'lead_id' => $lead->id,
                    'raw'     => substr($raw, 0, 500),
                ]);
                return null;
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::channel('daily')->error('[CALL_SUMMARY] Excepción al llamar a Claude.', [
                'lead_id' => $lead->id,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Notifica a Tommy (is_closer = true) y a los admins con notify_lead_escalation_whatsapp = true
     * que tienen teléfono cargado, con el resumen de la llamada.
     *
     * @param Lead  $lead    Lead cuya llamada se procesó.
     * @param array $summary Resumen estructurado extraído por Claude.
     *
     * @return void
     */
    protected function notify_team(Lead $lead, array $summary): void
    {
        /* Identificador legible del lead: nombre + empresa, o ID como fallback. */
        $contact_name = trim(
            ($lead->contact_name ?? '') . ' ' . ($lead->company_name ?? '')
        );
        if ($contact_name === '') {
            $contact_name = 'Lead #' . $lead->id;
        }

        /* Extraer los campos relevantes del resumen para el mensaje de WhatsApp. */
        $escenario    = $summary['escenario_cierre'] ?? null;
        $proximo_paso = $summary['proximo_paso'] ?? null;
        $resumen      = $summary['resumen_general'] ?? '';

        /* Etiqueta legible del escenario de cierre para el mensaje. */
        $escenario_label = match ($escenario) {
            'A'     => 'Cerró en llamada',
            'B'     => 'Requiere reunión con Lucas',
            'C'     => 'Seguimiento con Claude',
            'D'     => 'No quiere avanzar',
            default => 'Sin determinar',
        };

        /* Armar el mensaje de WhatsApp con el resumen. */
        $mensaje = "Resumen de llamada: {$contact_name}\n\n"
            . "Resultado: {$escenario_label}\n\n"
            . "Resumen: {$resumen}"
            . ($proximo_paso ? "\n\nPróximo paso: {$proximo_paso}" : '')
            . "\n\nEl resumen completo está en el panel de admin.";

        /* Notificar a los admins que son closer o que están suscritos a escalaciones. */
        $admins_a_notificar = Admin::query()
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->where(function ($q) {
                /* Closer: responsable directo de la llamada. */
                /* Escalación: admins que ya reciben notificaciones importantes de leads. */
                $q->where('is_closer', true)
                  ->orWhere('notify_lead_escalation_whatsapp', true);
            })
            ->get();

        foreach ($admins_a_notificar as $admin) {
            $sent = $this->whatsapp_send_service->send_text(
                trim((string) $admin->phone_number),
                $mensaje
            );

            if ($sent === null) {
                Log::channel('daily')->warning('[CALL_SUMMARY] No se pudo notificar al admin.', [
                    'admin_id' => $admin->id,
                    'lead_id'  => $lead->id,
                ]);
            }
        }
    }
}

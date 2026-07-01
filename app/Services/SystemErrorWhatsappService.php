<?php

namespace App\Services;

use App\Models\Admin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Notifica a los admins suscritos cuando ocurre un error en un envío automático del sistema.
 *
 * Los admins elegibles son aquellos con:
 *   - notify_send_errors_whatsapp = true
 *   - phone_number no vacío
 *
 * El mensaje se envía vía send_text() de WhatsappSendService. Si el envío de la
 * notificación en sí falla, se loguea sin reintentar (evitar recursión).
 *
 * Throttle: para no inundar a los admins si hay una ráfaga de fallos (ej: Kapso caído,
 * clave de API vencida), se envía como máximo 1 WhatsApp cada 10 minutos. Los fallos que
 * ocurren dentro de esa ventana solo se registran en el log (canal daily), no generan
 * un WhatsApp adicional. Decisión tomada con Lucas el 1/7/2026.
 *
 * Nota: send_text() puede retornar string|null (id del mensaje o null si falló).
 * Esta clase maneja ambas firmas para compatibilidad con posibles refactors futuros.
 */
class SystemErrorWhatsappService
{
    /**
     * Clave de cache para el lock de throttle (máximo 1 aviso cada 10 minutos).
     */
    private const THROTTLE_CACHE_KEY = 'whatsapp_send_error_notify_lock';

    /**
     * Duración de la ventana de throttle, en minutos.
     */
    private const THROTTLE_MINUTES = 10;

    /**
     * Servicio de envío de mensajes WhatsApp vía Kapso/Meta.
     *
     * @var WhatsappSendService
     */
    private $whatsapp;

    /**
     * @param WhatsappSendService $whatsapp Servicio de envío saliente.
     */
    public function __construct(WhatsappSendService $whatsapp)
    {
        $this->whatsapp = $whatsapp;
    }

    /**
     * Notifica a todos los admins elegibles sobre un error de envío automático.
     *
     * Consulta los admins con notify_send_errors_whatsapp=true y phone_number válido,
     * y les envía un mensaje de texto libre con el contexto y el error. Si ya se envió
     * un aviso en los últimos 10 minutos, este fallo solo se loguea (throttle).
     * Captura excepciones internamente para no interrumpir el flujo principal.
     *
     * @param string $context Descripción breve del contexto donde ocurrió el error
     *                        (ej: "Seguimiento lead #42", "Recordatorio demo Lead Juan").
     * @param string $error   Mensaje de error a incluir en la notificación.
     *
     * @return void
     */
    public function notify_send_error(string $context, string $error): void
    {
        /* Throttle: si ya se avisó a los admins en los últimos 10 minutos, solo loguear. */
        if (! $this->acquire_throttle_lock()) {
            Log::channel('daily')->info('SystemErrorWhatsappService: aviso suprimido por throttle (ya se notificó en los últimos 10 min).', [
                'context' => $context,
                'error'   => $error,
            ]);

            return;
        }

        /* Obtener admins suscritos a notificaciones de errores con teléfono válido. */
        $admins = Admin::query()
            ->where('notify_send_errors_whatsapp', true)
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->get();

        /* Si no hay admins suscritos, salir sin error. */
        if ($admins->isEmpty()) {
            return;
        }

        /* Cuerpo del mensaje con contexto y detalle del error. */
        $body = "⚠️ *Error de envío - ComercioCity*\n\n"
              . "*Contexto:* {$context}\n"
              . "*Error:* {$error}";

        foreach ($admins as $admin) {
            try {
                /*
                 * Enviar mensaje de texto libre (dentro de ventana de sesión activa del sistema).
                 * skip_failure_notification=true: si ESTE envío (el aviso al admin) también falla,
                 * no debe volver a disparar notify_send_error() — evitaría una recursión de
                 * notificaciones fallidas notificando notificaciones fallidas si Kapso está caído.
                 */
                $result = $this->whatsapp->send_text((string) $admin->phone_number, $body, null, true);

                /*
                 * send_text() retorna string|null: el id del mensaje si tuvo éxito, null si falló.
                 * Si en el futuro retorna array{id, error}, este bloque también lo maneja.
                 */
                $message_id = is_array($result) ? ($result['id'] ?? null) : $result;

                if ($message_id === null) {
                    /* El envío falló: extraer el error si el retorno es array, o usar mensaje genérico. */
                    $send_error = is_array($result) ? ($result['error'] ?? 'desconocido') : 'null retornado';
                    Log::channel('daily')->warning('SystemErrorWhatsappService: no se pudo notificar al admin.', [
                        'admin_id'       => $admin->id,
                        'send_error'     => $send_error,
                        'original_error' => $error,
                    ]);
                }
            } catch (\Throwable $exception) {
                /* Capturar cualquier excepción para no interrumpir el flujo principal. */
                Log::channel('daily')->error('SystemErrorWhatsappService: excepción al notificar admin.', [
                    'admin_id'       => $admin->id,
                    'exception'      => $exception->getMessage(),
                    'original_error' => $error,
                ]);
            }
        }
    }

    /**
     * Intenta tomar el lock de throttle para esta ventana de 10 minutos.
     *
     * Cache::add() es atómico: solo devuelve true la primera vez que se llama dentro
     * de la ventana (crea la clave); las siguientes llamadas mientras la clave siga
     * viva devuelven false. Así garantizamos como máximo 1 aviso cada 10 minutos
     * sin importar cuántos envíos fallen en el medio.
     *
     * @return bool true si se puede notificar ahora, false si hay que esperar.
     */
    private function acquire_throttle_lock(): bool
    {
        return Cache::add(self::THROTTLE_CACHE_KEY, true, now()->addMinutes(self::THROTTLE_MINUTES));
    }
}

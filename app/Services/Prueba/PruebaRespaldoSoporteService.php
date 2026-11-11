<?php

namespace App\Services\Prueba;

use App\Events\SupportMessageReceived;
use App\Jobs\ResponderRespaldoDePruebaJob;
use App\Models\PruebaWhatsappEnvio;
use App\Models\SupportCodexCase;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\DemoChatAiService;
use App\Services\PruebaImportacionService;
use App\Services\PruebaWhatsappService;
use App\Services\SupportClientSyncService;
use App\Services\SupportCodexService;
use App\Services\SupportCodexHealthService;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * El respaldo cuando Martín está caído (A4 de la misión cruzada prueba-ayuda-unificada-en-soporte, 10/11/2026).
 *
 * Entra un mensaje del lead en un ticket de prueba gratuita y se programa {@see ResponderRespaldoDePruebaJob} a
 * los N minutos (`prueba_respaldo_soporte_minutos`, default 3). Si para entonces nadie contestó y la vigilancia
 * del admin dice que support-codex está caído —el MISMO criterio del correo de vigilancia,
 * {@see SupportCodexHealthService::en_falla()}—, contesta el modelo barato del guía de la prueba
 * (`DemoChatAiService::responder_soporte_de_prueba()`: DeepSeek, solo el manual), con un texto que empieza con
 * «Respuesta automática:».
 *
 * Guardas (cualquiera corta sin mandar nada):
 *  - el ticket sigue abierto, de WhatsApp, del dueño, y el cliente sigue en prueba;
 *  - el entrante sigue siendo el último mensaje real del ticket (nadie contestó y no llegó otro, que tiene su
 *    propio job) y Martín no tiene el ticket tomado;
 *  - Martín está caído (si está sano, contesta él), y el ticket no está donde Martín también se calla
 *    (`SupportCodexService::motivo_de_silencio_para_respaldo()`: respuesta automática apagada, revisión humana,
 *    misión u operación en curso, fuera del circuito de Codex);
 *  - como mucho {@see self::TOPE_DIARIO} respuestas automáticas por ticket por día (hora de Argentina);
 *  - la ventana de 24 h abierta (nunca texto libre con la ventana cerrada), solo en WhatsApp;
 *  - sin importación en curso o por revisar en el ticket, sin adjuntos ni mensajes que no sean texto en el turno
 *    del lead, y sin pedido de precio o de contratación: ahí no contesta, lo ve una persona;
 *  - si el modelo falla, no manda nada.
 *
 * 🔴 Cómo lo reconoce Martín al volver: el texto empieza con {@see self::PREFIJO}, el mensaje va con
 * `is_trial_import_notice` (el aviso que queda en el historial sin tapar el entrante, así el ticket le sigue
 * apareciendo pendiente con la pregunta del lead) y cada respuesta queda registrada en `prueba_whatsapp_envios`
 * con `plantilla = respaldo_automatico` y el mensaje en `support_message_id`.
 *
 * Idempotente: un candado por ticket y una fila de envío por entrante (`detalle = entrante:<id>`).
 *
 * 🔴 Dos canales. El ticket de WhatsApp del dueño y, desde el 10/11/2026, la conversación de Soporte DEL SISTEMA
 * (`source = erp`), adonde el botón «Seguir con Soporte» del asistente de la prueba manda la pregunta. En el sistema
 * la ventana de 24 h no aplica; la respuesta llega al sistema del lead por el mismo camino que las respuestas de
 * Martín (`SupportClientSyncService::sync_message_to_client()`), y solo se contesta un entrante que Martín
 * consideraría confiable (`SupportTicket::mensaje_confiable_para_codex()`) del dueño.
 */
class PruebaRespaldoSoporteService
{
    /** Con qué empieza toda respuesta del respaldo. */
    const PREFIJO = 'Respuesta automática: ';

    /** `prueba_whatsapp_envios.plantilla` de las respuestas del respaldo. */
    const PLANTILLA = 'respaldo_automatico';

    /** Respuestas automáticas por ticket y por día. */
    const TOPE_DIARIO = 3;

    /** Cuántos mensajes del ticket van al modelo como historial. */
    const HISTORIAL = 12;

    /**
     * Pedidos de precio del servicio o de contratación, que no contesta el respaldo (los ve una persona).
     *
     * ⚠️ Acotado a propósito: «precio», «costo», «pago», «descuento» y «presupuesto» sueltos son funciones del
     * sistema («¿cómo cambio un precio?» es justo el video 06) y no pueden frenar una duda de uso.
     */
    const PATRON_COMERCIAL = '/(cu[aá]nto\s+(sale|cuesta|vale|cobran|pago|tengo\s+que\s+pagar)|precios?\s+(del|de\s+(la|los|las))\s+(sistema|plan|planes|servicio|suscripci[oó]n|abono|comerciocity)|\bplan(es)?\s+(mensual|anual|de\s+pago|pago)|\babono\b|\bmensualidad\b|\bsuscrip\w*|\bcontrat\w*|\bcotizaci[oó]n\b|\bpagar\s+(el|la)\s+(sistema|plan|suscripci[oó]n|abono|prueba)|\bseguir\s+despu[eé]s\s+de\s+la\s+prueba)/iu';

    /**
     * Programa el job del respaldo para un entrante, si el ticket es de una prueba. Nunca lanza.
     *
     * @param SupportTicket $ticket
     * @param int           $message_id
     *
     * @return bool true si quedó programado.
     */
    public function programar(SupportTicket $ticket, int $message_id): bool
    {
        try {
            $ticket->loadMissing('client');
            if (! $this->es_ticket_del_dueno_en_prueba($ticket)) {
                return false;
            }

            ResponderRespaldoDePruebaJob::dispatch((int) $ticket->id, $message_id)
                ->delay(now()->addMinutes(PruebaSettings::respaldo_soporte_minutos()));

            return true;
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaRespaldoSoporte: no se pudo programar el respaldo.', [
                'ticket_id' => (int) $ticket->id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Contesta el entrante si corresponde. Ver las guardas en el PHPDoc de la clase.
     *
     * @param int $ticket_id
     * @param int $message_id El entrante que programó el job.
     *
     * @return array{estado: string, detalle: string|null}
     *   `estado`: `enviado`, `fallido`, o el motivo por el que no contestó (`no_corresponde`, `ya_respondido`,
     *   `martin_atendiendo`, `martin_sano`, `tope_diario`, `ventana_cerrada`, `importacion_en_curso`,
     *   `adjunto`, `pedido_comercial`, `modelo_fallo`, `en_curso`).
     */
    public function responder(int $ticket_id, int $message_id): array
    {
        $ticket = SupportTicket::with('client')->find($ticket_id);
        if ($ticket === null || $ticket->status !== SupportTicket::STATUS_ABIERTO || ! $this->es_ticket_del_dueno_en_prueba($ticket)) {
            return $this->no('no_corresponde');
        }

        $reserva = Cache::lock('prueba-respaldo-soporte-' . $ticket->id, 120);
        if (! $reserva->get()) {
            return $this->no('en_curso');
        }

        try {
            $motivo = $this->motivo_para_no_contestar($ticket, $message_id);
            if ($motivo !== null) {
                return $this->no($motivo);
            }

            $turno = $this->turno_del_lead($ticket);
            if ($this->tiene_adjunto_o_no_es_texto($turno)) {
                return $this->no('adjunto');
            }
            if (preg_match(self::PATRON_COMERCIAL, implode("\n", $turno->pluck('body')->map(function ($body) {
                return (string) $body;
            })->all())) === 1) {
                return $this->no('pedido_comercial');
            }
            if ($this->hay_importacion($ticket)) {
                return $this->no('importacion_en_curso');
            }

            try {
                $respuesta = app(DemoChatAiService::class)->responder_soporte_de_prueba($this->historial($ticket), $ticket->source === 'erp' ? 'sistema' : 'whatsapp');
            } catch (\Throwable $e) {
                Log::channel('daily')->warning('PruebaRespaldoSoporte: el modelo no respondió; no se manda nada.', [
                    'ticket_id' => (int) $ticket->id,
                    'error'     => $e->getMessage(),
                ]);

                return $this->no('modelo_fallo', $e->getMessage());
            }

            /* El modelo tarda: si mientras tanto contestó alguien o volvió Martín, se descarta. */
            $motivo = $this->motivo_para_no_contestar($ticket->fresh(['client']), $message_id);
            if ($motivo !== null) {
                return $this->no($motivo);
            }

            return $this->mandar($ticket, $message_id, $respuesta);
        } finally {
            $reserva->release();
        }
    }

    /**
     * Las guardas que se miran antes y después del modelo.
     *
     * @param SupportTicket $ticket
     * @param int           $message_id
     *
     * @return string|null
     */
    protected function motivo_para_no_contestar(SupportTicket $ticket, int $message_id): ?string
    {
        if ($ticket->status !== SupportTicket::STATUS_ABIERTO) {
            return 'no_corresponde';
        }

        $ultimo = $this->ultimo_mensaje_real($ticket);
        if ($ultimo === null || (int) $ultimo->id !== $message_id || $ultimo->sender_type !== 'user') {
            return 'ya_respondido';
        }
        if (! $ticket->mensaje_confiable_para_codex($ultimo)) {
            return 'no_corresponde';
        }

        if (PruebaWhatsappEnvio::where('support_ticket_id', (int) $ticket->id)->where('plantilla', self::PLANTILLA)
            ->where('detalle', 'entrante:' . $message_id)->exists()) {
            return 'ya_respondido';
        }

        /* Donde Martín se calla, el respaldo también: respuesta automática apagada por Lucas, revisión humana,
         * misión u operación en curso, ticket fuera del circuito de Codex. La decisión es la de SupportCodexService. */
        $silencio = app(SupportCodexService::class)->motivo_de_silencio_para_respaldo($ticket);
        if ($silencio !== null) {
            return $silencio;
        }

        $caso = SupportCodexCase::where('support_ticket_id', (int) $ticket->id)->first();
        if ($caso !== null && $caso->lease_expires_at !== null && $caso->lease_expires_at->isFuture()) {
            return 'martin_atendiendo';
        }

        if (! app(SupportCodexHealthService::class)->en_falla()) {
            return 'martin_sano';
        }

        /* Cuentan TODOS los intentos del día, también los fallidos: una sincronización que falla igual deja la
         * «Respuesta automática:» en el hilo, y sin esto no habría tope. */
        $hoy = PruebaWhatsappEnvio::where('support_ticket_id', (int) $ticket->id)->where('plantilla', self::PLANTILLA)
            ->where('dia', PruebaWhatsappService::hoy())->count();
        if ($hoy >= self::TOPE_DIARIO) {
            return 'tope_diario';
        }

        if ($ticket->source === 'whatsapp' && ! app(WhatsappSessionWindowService::class)->is_open((string) $ticket->whatsapp_phone)) {
            return 'ventana_cerrada';
        }

        return null;
    }

    /**
     * Manda la respuesta por el ticket y la registra.
     *
     * @param SupportTicket        $ticket
     * @param int                  $message_id
     * @param array<string, mixed> $respuesta `contenido` y `videos` (claves `prueba:NN`).
     *
     * @return array{estado: string, detalle: string|null}
     */
    protected function mandar(SupportTicket $ticket, int $message_id, array $respuesta): array
    {
        $catalogo = app(PruebaVideoCatalogoService::class)->videos();
        $video    = null;
        foreach ((array) ($respuesta['videos'] ?? []) as $clave) {
            if (isset($catalogo[$clave])) {
                $video = $catalogo[$clave];
                break;
            }
        }

        $texto = self::PREFIJO . trim((string) $respuesta['contenido']);
        if ($video !== null) {
            $texto .= "\n" . $video['titulo'] . ': ' . $video['url'];
        }

        $envio = $ticket->source === 'erp'
            ? $this->mandar_al_sistema($ticket, $texto)
            : app(PruebaWhatsappService::class)->mandar_texto_por_ticket($ticket->client, $texto, (int) $ticket->id);
        if ($envio['support_ticket_id'] === null) {
            /* No salió nada ni quedó nada en el hilo (ventana cerrada, contacto inválido). */
            return $this->no($envio['motivo'] === 'ventana_cerrada' ? 'ventana_cerrada' : 'no_corresponde', $envio['motivo']);
        }

        $mensaje_id = SupportMessage::where('support_ticket_id', (int) $ticket->id)->where('sender_type', 'admin')
            ->where('is_trial_import_notice', true)->where('body', $texto)->orderByDesc('id')->value('id');
        $estado = $envio['enviado'] ? PruebaWhatsappEnvio::ESTADO_ENVIADO : PruebaWhatsappEnvio::ESTADO_FALLIDO;

        $this->registrar($ticket, self::PLANTILLA, null, $estado, $mensaje_id, $envio, 'entrante:' . $message_id);

        /* El video que sumó la respuesta cuenta como entregado para `prueba.videos` (Martín no lo repite). */
        if ($video !== null && $envio['enviado'] && ! PruebaWhatsappEnvio::where('client_id', (int) $ticket->client_id)
            ->where('plantilla', PruebaVideoSoporteService::plantilla((string) $video['codigo']))
            ->where('estado', PruebaWhatsappEnvio::ESTADO_ENVIADO)->exists()) {
            $codigo = (string) $video['codigo'];
            $this->registrar($ticket, PruebaVideoSoporteService::plantilla($codigo),
                isset(PruebaVideoSoporteService::HITOS[$codigo]) ? PruebaVideoSoporteService::HITOS[$codigo] : null,
                $estado, $mensaje_id, $envio, 'respaldo automático');
        }

        return ['estado' => $envio['enviado'] ? 'enviado' : 'fallido', 'detalle' => $envio['motivo']];
    }

    /**
     * La respuesta en la conversación de Soporte del sistema: el mensaje queda en el hilo (como aviso que no tapa
     * el entrante, y con `ai_generated_at` porque lo escribió el modelo) y se sincroniza al sistema del lead por
     * el mismo camino que las respuestas de Martín. Devuelve la misma forma que `mandar_texto_por_ticket()`.
     *
     * @param SupportTicket $ticket
     * @param string        $texto
     *
     * @return array{enviado: bool, motivo: string|null, whatsapp_message_id: string|null, support_ticket_id: int|null}
     */
    protected function mandar_al_sistema(SupportTicket $ticket, string $texto): array
    {
        $mensaje = SupportMessage::create([
            'support_ticket_id'      => (int) $ticket->id,
            'sender_type'            => 'admin',
            'sender_admin_id'        => null,
            'kind'                   => 'text',
            'body'                   => $texto,
            'delivered_at'           => now(),
            'ai_generated_at'        => now(),
            'is_trial_import_notice' => true,
        ]);

        $enviado = app(SupportClientSyncService::class)->sync_message_to_client($mensaje);
        if (! $enviado) {
            $mensaje->remote_delivery_status = 'not_received';
            $mensaje->save();
        }

        try {
            event(new SupportMessageReceived((int) $mensaje->id));
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('PruebaRespaldoSoporte: no se pudo emitir el evento del mensaje.', ['message_id' => $mensaje->id]);
        }

        return [
            'enviado'             => $enviado,
            'motivo'              => $enviado ? null : 'El sistema del lead no confirmó el mensaje.',
            'whatsapp_message_id' => null,
            'support_ticket_id'   => (int) $ticket->id,
        ];
    }

    /**
     * Si el ticket es del DUEÑO de un negocio en prueba, por WhatsApp o por la conversación de Soporte del sistema.
     *
     * @param SupportTicket $ticket
     *
     * @return bool
     */
    protected function es_ticket_del_dueno_en_prueba(SupportTicket $ticket): bool
    {
        $ticket->loadMissing('client');
        if ($ticket->client === null || (string) $ticket->client->estado !== PruebaCicloService::CLIENTE_PRUEBA
            || $ticket->client_employee_id !== null) {
            return false;
        }

        if ($ticket->source === 'whatsapp') {
            return true;
        }

        return $ticket->source === 'erp' && (int) $ticket->client_user_id > 0
            && (int) $ticket->client_user_id === (int) $ticket->client->user_id;
    }

    /**
     * @param SupportTicket        $ticket
     * @param string               $plantilla
     * @param string|null          $hito
     * @param string               $estado
     * @param int|null             $mensaje_id
     * @param array<string, mixed> $envio
     * @param string               $detalle
     *
     * @return void
     */
    protected function registrar(SupportTicket $ticket, string $plantilla, ?string $hito, string $estado, $mensaje_id, array $envio, string $detalle): void
    {
        PruebaWhatsappEnvio::create([
            'client_id'           => (int) $ticket->client_id,
            'plantilla'           => $plantilla,
            'hito'                => $hito,
            'estado'              => $estado,
            'support_ticket_id'   => (int) $ticket->id,
            'support_message_id'  => $mensaje_id !== null ? (int) $mensaje_id : null,
            'whatsapp_message_id' => $envio['whatsapp_message_id'],
            'detalle'             => $detalle,
            'dia'                 => PruebaWhatsappService::hoy(),
            'enviado_at'          => $estado === PruebaWhatsappEnvio::ESTADO_ENVIADO ? now() : null,
        ]);
    }

    /**
     * El último mensaje real del ticket, sin los avisos que no tapan un entrante (video, respuestas automáticas):
     * el mismo criterio con el que Martín ve si hay algo pendiente.
     *
     * @param SupportTicket $ticket
     *
     * @return SupportMessage|null
     */
    protected function ultimo_mensaje_real(SupportTicket $ticket): ?SupportMessage
    {
        $query = SupportMessage::where('support_ticket_id', (int) $ticket->id)->where('is_trial_import_notice', false);
        SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');

        return $query->orderByDesc('id')->first();
    }

    /**
     * Los mensajes del lead desde la última intervención del equipo (incluidas las respuestas automáticas).
     *
     * @param SupportTicket $ticket
     *
     * @return \Illuminate\Support\Collection<int, SupportMessage>
     */
    protected function turno_del_lead(SupportTicket $ticket)
    {
        $query = SupportMessage::where('support_ticket_id', (int) $ticket->id);
        SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');
        $ultimo_del_equipo = (clone $query)->where('sender_type', '!=', 'user')->max('id');

        return $query->where('sender_type', 'user')->where('id', '>', (int) $ultimo_del_equipo)
            ->with('attachments')->orderBy('id')->get();
    }

    /**
     * @param \Illuminate\Support\Collection<int, SupportMessage> $turno
     *
     * @return bool
     */
    protected function tiene_adjunto_o_no_es_texto($turno): bool
    {
        foreach ($turno as $mensaje) {
            if ($mensaje->kind !== 'text' || $mensaje->attachments->isNotEmpty() || trim((string) $mensaje->body) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Si en el ticket hay una importación iniciada, en proceso o por revisar. Si no se puede saber, se asume que sí.
     *
     * @param SupportTicket $ticket
     *
     * @return bool
     */
    protected function hay_importacion(SupportTicket $ticket): bool
    {
        if ($ticket->importacion_soporte_iniciada_at !== null) {
            return true;
        }

        try {
            $estado = app(PruebaImportacionService::class)->estado_en_ticket($ticket);
        } catch (\Throwable $e) {
            return true;
        }

        return ($estado['en_proceso'] ?? null) !== false || ! empty($estado['pendientes']);
    }

    /**
     * El historial que va al modelo: los últimos mensajes de texto del ticket, como `lead` o `soporte`.
     *
     * @param SupportTicket $ticket
     *
     * @return array<int, array{rol: string, contenido: string}>
     */
    protected function historial(SupportTicket $ticket): array
    {
        $query = SupportMessage::where('support_ticket_id', (int) $ticket->id)->whereIn('sender_type', ['user', 'admin'])
            ->where('kind', 'text');
        SupportMessage::apply_mensaje_real_conditions($query, 'support_messages');

        return $query->orderByDesc('id')->limit(self::HISTORIAL)->get()->reverse()->map(function (SupportMessage $mensaje) {
            return [
                'rol'       => $mensaje->sender_type === 'user' ? 'lead' : 'soporte',
                'contenido' => mb_substr((string) $mensaje->body, 0, 1500),
            ];
        })->values()->all();
    }

    /**
     * @param string      $estado
     * @param string|null $detalle
     *
     * @return array{estado: string, detalle: string|null}
     */
    protected function no(string $estado, ?string $detalle = null): array
    {
        return ['estado' => $estado, 'detalle' => $detalle];
    }
}

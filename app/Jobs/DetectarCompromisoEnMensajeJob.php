<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\LeadCompromiso;
use App\Models\LeadMessage;
use App\Services\LeadCompromisoIaService;
use App\Services\LeadCompromisoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Detecta si lo que el lead escribió es un compromiso de contacto («el martes te escribo») y, si lo es, lo crea (misión
 * leads-referidos-compromisos, etapa E2).
 *
 * Lo encola el webhook con 90 segundos de demora (para que lleguen los mensajes que vienen pegados), sólo con el motor y la
 * detección con IA prendidos. El job filtra PRIMERO con una expresión (días de la semana, «mañana», «la semana que viene», fechas
 * dd/mm, «te escribo/aviso/llamo», «llamame/escribime», «a la tarde/noche»): casi ningún mensaje la cumple y así casi ninguno
 * llega a la IA. Sólo si la cumple se hace UNA llamada a {@see LeadCompromisoIaService::detectar()}.
 *
 * 🔴 NUNCA lanza una excepción: es un job de segundo plano que no puede romper nada ni reintentarse en bucle. Cualquier falla (IA
 * caída, JSON roto, fecha pasada) deja el lead como estaba.
 */
class DetectarCompromisoEnMensajeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Un solo intento: lo que no se detectó en esta pasada lo toma el compromiso que cargue una persona. */
    public $tries = 1;

    /** Segundos de demora con los que el webhook lo encola. */
    const DEMORA_SEGUNDOS = 90;

    /**
     * @var int Id del lead.
     */
    private $lead_id;

    /**
     * @var int Id del mensaje del lead que se analiza.
     */
    private $message_id;

    /**
     * @param int $lead_id
     * @param int $message_id
     */
    public function __construct(int $lead_id, int $message_id)
    {
        $this->lead_id    = $lead_id;
        $this->message_id = $message_id;
    }

    /**
     * Detecta y, si hay un compromiso, lo crea (origen `ia_mensaje`).
     *
     * @param LeadCompromisoService   $compromisos
     * @param LeadCompromisoIaService $ia
     *
     * @return void
     */
    public function handle(LeadCompromisoService $compromisos, LeadCompromisoIaService $ia): void
    {
        try {
            /* Se vuelve a mirar los interruptores: pasaron 90 segundos y alguien pudo apagarlos. */
            if (! LeadCompromisoService::deteccion_ia_activa()) {
                return;
            }

            $lead    = Lead::query()->find($this->lead_id);
            $mensaje = LeadMessage::query()->find($this->message_id);
            if ($lead === null || $mensaje === null || (int) $mensaje->lead_id !== (int) $lead->id || (string) $mensaje->sender !== 'lead') {
                return;
            }

            /* A un cliente o a un lead cerrado no se le arma un contacto: ya no es del embudo. */
            if (! empty($lead->promoted_client_id) || in_array((string) $lead->status, \App\Models\LeadScheduledMessage::STATUSES_DE_LEAD_QUE_FRENAN, true)) {
                return;
            }

            if (! self::el_texto_sugiere_un_compromiso((string) $mensaje->content)) {
                return;
            }

            /* Idempotencia: un mensaje origina como mucho un compromiso (un reintento del job no lo duplica). */
            $ya_creado = LeadCompromiso::query()
                ->where('lead_id', (int) $lead->id)
                ->where('origen', LeadCompromiso::ORIGEN_IA_MENSAJE)
                ->where('origen_id', (int) $mensaje->id)
                ->exists();
            if ($ya_creado) {
                return;
            }

            $deteccion = $ia->detectar($lead, $mensaje);
            if (! $deteccion['ok'] || ! $deteccion['hay_compromiso']) {
                return;
            }

            $compromisos->crear($lead, [
                'vence_at'      => $deteccion['vence_at'],
                'accion'        => $deteccion['accion'],
                'resumen'       => $this->resumen_para($deteccion['accion'], (string) $deteccion['cita']),
                'cita_del_lead' => $deteccion['cita'],
                'origen'        => LeadCompromiso::ORIGEN_IA_MENSAJE,
                'origen_id'     => (int) $mensaje->id,
            ], null);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('DetectarCompromisoEnMensajeJob: no se pudo detectar un compromiso.', [
                'lead_id'    => $this->lead_id,
                'message_id' => $this->message_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * El resumen que lleva el compromiso detectado: qué se acordó y lo que dijo el lead.
     *
     * @param string $accion `llamar`, `escribir` o `esperar`.
     * @param string $cita   Lo que dijo el lead.
     *
     * @return string
     */
    private function resumen_para(string $accion, string $cita): string
    {
        $que = 'Llamarlo.';
        if ($accion === LeadCompromiso::ACCION_ESCRIBIR) {
            $que = 'Escribirle.';
        } elseif ($accion === LeadCompromiso::ACCION_ESPERAR) {
            $que = 'El lead quedó en avisar; si no avisa, retomarlo.';
        }

        return $que . ($cita !== '' ? ' Dijo por WhatsApp: «' . $cita . '».' : '');
    }

    /**
     * El filtro previo a la IA: ¿el texto habla de un momento o de una promesa de contacto?
     *
     * Es deliberadamente generoso (un falso positivo cuesta una llamada a la IA, que dice que no) pero deja afuera lo que no tiene
     * nada que ver («dale, gracias», «ok», «¿cuánto sale por mes?»). Se compara sin tildes ni mayúsculas.
     *
     * @param string $texto
     *
     * @return bool
     */
    public static function el_texto_sugiere_un_compromiso(string $texto): bool
    {
        $limpio = mb_strtolower($texto);
        $limpio = strtr($limpio, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);

        $patrones = [
            /* Días de la semana. */
            '/\b(?:lunes|martes|miercoles|jueves|viernes|sabado|domingo)s?\b/',
            /* Momentos relativos. */
            '/\b(?:manana|pasado manana|finde|fin de semana)\b/',
            '/\b(?:la )?semana (?:que viene|proxima|entrante)\b|\bproxima semana\b|\b(?:el )?mes que viene\b|\bproximo mes\b/',
            '/\bdespues de (?:las )?(?:fiestas|vacaciones|feriado|el feriado)\b/',
            '/\b(?:en|dentro de) (?:unos|unas|\d+|un par de|dos|tres|cuatro|cinco) (?:dias|semanas)\b/',
            /* Fechas dd/mm y «el 3 de diciembre». */
            '/\b\d{1,2}\/\d{1,2}\b/',
            '/\bel \d{1,2} de (?:enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)\b/',
            /* Promesas de contacto. */
            '/\bte (?:escribo|aviso|llamo|confirmo|contesto|hablo|digo|mando|paso|respondo)\b/',
            '/\bme (?:comunico|pongo en contacto|contacto)\b/',
            '/\b(?:llamame|llamenme|llamen|escribime|escribanme|avisame|avisenme|hablame|contactame)\b/',
            /* Momentos del día. */
            '/\b(?:a|por) la (?:tarde|noche|manana|mananita)\b/',
        ];

        foreach ($patrones as $patron) {
            if (preg_match($patron, $limpio) === 1) {
                return true;
            }
        }

        return false;
    }
}

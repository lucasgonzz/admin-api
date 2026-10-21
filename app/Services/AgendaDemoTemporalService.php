<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Models\LeadMessage;
use Carbon\Carbon;

/** Evidencia temporal del propio lead: nunca se toma una fecha de la prosa de la IA. */
class AgendaDemoTemporalService
{
    /**
     * La última intención explícita manda. Un correo posterior no vuelve a contar «mañana» ni
     * «en media hora» desde el reloj actual. Las expresiones parciales cortan la búsqueda anterior.
     */
    public function pedido(Lead $lead): ?array
    {
        $mensajes = LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')
            ->where('created_at', '>=', AppTime::now()->subDay())->orderByDesc('id')->limit(12)->get();
        foreach ($mensajes as $mensaje) {
            $texto = mb_strtolower(trim((string) $mensaje->content), 'UTF-8');
            $ancla = Carbon::parse($mensaje->sent_at ?: $mensaje->created_at)->setTimezone(DemoDirectaService::TZ);
            $evidencia = ['mensaje_id' => (int) $mensaje->id, 'anclado_en' => $ancla->format('Y-m-d H:i:s')];
            if ($lead->demo_id === null && preg_match('/^(?:s[ií][,!\s]*(?:dale)?|dale|me sirve|vamos|perfecto|listo|[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,})[.!\s]*$/iu', $texto)) {
                $oferta = $this->oferta_inmediata_previa($lead, (int) $mensaje->id);
                if ($oferta !== null) {
                    return $evidencia + ['tipo' => 'ahora', 'oferta_mensaje_id' => $oferta];
                }
            }
            if (preg_match('/\b(?:no puedo|no me sirve|no quiero)\b.*\bahora\b|\bahora\b.*\b(?:no puedo|no me sirve|no quiero)\b/u', $texto)) {
                return $evidencia + ['tipo' => 'ambiguo'];
            }
            if (preg_match_all('/\b(?:pasado\s+ma[ñn]ana|ma[ñn]ana|hoy|lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo)\b/u', $texto, $dias_mencionados, PREG_OFFSET_CAPTURE)) {
                $ultima = end($dias_mencionados[0]);
                $dia = [$ultima[0]];
                // Con varias intenciones no se toma una hora de la cláusula anterior.
                $clausula = count($dias_mencionados[0]) > 1 ? substr($texto, $ultima[1]) : $texto;
                if (preg_match('/\bno (?:puedo|me sirve|quiero)\b|\b(?:hoy|ma[ñn]ana)\s+no\b/u', $clausula)
                    || ! preg_match('/\b(?:a\s+las?\s+)?(\d{1,2})(?::(\d{2})|\s*(?:hs?|horas?)\b)|\ba\s+las?\s+(\d{1,2})\b/u', $clausula, $hora)) {
                    return $evidencia + ['tipo' => 'ambiguo'];
                }
                $hh = ! empty($hora[3]) ? (int) $hora[3] : (int) $hora[1];
                $mm = ! empty($hora[2]) ? (int) $hora[2] : 0;
                if ($hh > 23 || $mm > 59) {
                    return $evidencia + ['tipo' => 'ambiguo'];
                }
                $nombre = strtr($dia[0], ['ñ' => 'n', 'é' => 'e', 'á' => 'a']);
                $fecha = $ancla->copy()->startOfDay();
                if ($nombre === 'manana') {
                    $fecha->addDay();
                } elseif ($nombre === 'pasado manana') {
                    $fecha->addDays(2);
                } elseif ($nombre !== 'hoy') {
                    $dias = ['domingo' => 0, 'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6];
                    $salto = ($dias[$nombre] - $fecha->dayOfWeek + 7) % 7;
                    $fecha->addDays($salto === 0 ? 7 : $salto);
                }
                return $evidencia + ['tipo' => 'momento', 'inicio' => $fecha->setTime($hh, $mm)->format('Y-m-d H:i')];
            }
            if (preg_match('/\ben\s+(media\s+hora|un\s+cuarto\s+de\s+hora|una\s+hora|(\d{1,3})\s+minutos?)\b/u', $texto, $m)) {
                $minutos = ! empty($m[2]) ? (int) $m[2] : (strpos($m[1], 'media') !== false ? 30 : (strpos($m[1], 'cuarto') !== false ? 15 : 60));
                if ($minutos < 1 || $minutos > 240) {
                    return $evidencia + ['tipo' => 'ambiguo'];
                }
                return $evidencia + ['tipo' => 'momento', 'inicio' => $ancla->addMinutes($minutos)->second(0)->format('Y-m-d H:i')];
            }
            if (preg_match('/\bahora\b/u', $texto)) {
                if ($lead->demo_id !== null && $lead->demo_date !== null && $lead->demo_start_time && $lead->demo_end_time) {
                    $inicio = Carbon::parse($lead->demo_date->format('Y-m-d') . ' ' . $lead->demo_start_time, DemoDirectaService::TZ);
                    $fin = Carbon::parse($lead->demo_date->format('Y-m-d') . ' ' . $lead->demo_end_time, DemoDirectaService::TZ)->addMinutes(LeadDemoSettings::get_gracia_minutos_post());
                    $bloqueo = $inicio->copy()->addMinutes(LeadDemoSettings::get_bloqueo_real_minutos());
                    if ($ancla->gte($inicio) && $ancla->lte($fin->max($bloqueo)) && ! preg_match('/\b(?:mejor|cambiar|reagendar|mover)\b/u', $texto)) {
                        return $evidencia + ['tipo' => 'conservar'];
                    }
                }
                return $evidencia + ['tipo' => 'ahora'];
            }
            // Una nueva hora sin día exige interpretarla; no hereda automáticamente el día anterior.
            if (preg_match('/\b\d{1,2}:\d{2}\b|\ba\s+las?\s+\d{1,2}\b|\ba\s+la\s+tarde\b/u', $texto)) {
                return $evidencia + ['tipo' => 'ambiguo'];
            }
            // Ya tiene turno y sólo espera/entra: ningún pedido anterior habilita otra reserva.
            if ($lead->demo_id !== null && preg_match('/\b(?:entro|entrar|un minuto|ya voy|dale|gracias)\b/u', $texto)) {
                return $evidencia + ['tipo' => 'conservar'];
            }
        }

        return null;
    }

    /** Un «sí» se refiere a lo que efectivamente se ofreció, no al minuto de generación. */
    private function oferta_inmediata_previa(Lead $lead, int $antes_de): ?int
    {
        $salientes = LeadMessage::query()->where('lead_id', $lead->id)->where('id', '<', $antes_de)
            ->whereIn('sender', ['sistema', 'setter'])->whereIn('status', LeadMessage::STATUSES_SALIENTE_DESPACHADO)
            ->where('created_at', '>=', AppTime::now()->subDay())->where('is_status_event', false)
            ->orderByDesc('id')->limit(3)->get();
        foreach ($salientes as $saliente) {
            $texto = mb_strtolower((string) $saliente->content, 'UTF-8');
            if (preg_match('/\bno (?:hay|tengo|puedo)\b|\bocupad[ao]\b|\b(?:ma[ñn]ana|lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo)\b/u', $texto)) {
                return null;
            }
            if (preg_match('/\bahora\b|\ben (?:diez|10|unos) minutos\b/u', $texto)) {
                return (int) $saliente->id;
            }
            // Una oferta posterior de otro día/hora corta la oferta inmediata más vieja.
            if (preg_match('/\b(?:hoy|ma[ñn]ana|lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo)\b|\b\d{1,2}:\d{2}\b/u', $texto)) {
                return null;
            }
        }

        return null;
    }

    /** Casillas explícitas: se conserva el mensaje fuente y nunca se infiere desde la respuesta. */
    public function correo_reciente(Lead $lead): array
    {
        $mensajes = LeadMessage::query()->where('lead_id', $lead->id)->where('sender', 'lead')
            ->where('created_at', '>=', AppTime::now()->subDay())->orderByDesc('id')->limit(12)->get();
        foreach ($mensajes as $mensaje) {
            preg_match_all('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string) $mensaje->content, $coincidencias);
            $correos = array_values(array_unique(array_filter($coincidencias[0], function ($correo) { return filter_var($correo, FILTER_VALIDATE_EMAIL) !== false; })));
            if ($correos !== []) {
                return ['mensaje_id' => (int) $mensaje->id, 'correos' => $correos];
            }
        }

        return ['mensaje_id' => null, 'correos' => []];
    }
}

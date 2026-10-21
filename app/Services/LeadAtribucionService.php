<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\WhatsappAdReferral;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Evidencia operativa propia: los parámetros de URL no certifican una atribución de Meta. */
class LeadAtribucionService
{
    public function emitir(array $datos): array
    {
        $reference = bin2hex(random_bytes(16));
        $ahora = AppTime::now();
        $vence = $ahora->copy()->addDays((int) config('atribucion.dias_vigencia', 7));
        DB::table('landing_whatsapp_referencias')->insert([
            'reference_hash' => hash('sha256', $reference),
            'datos' => json_encode($datos),
            'created_at' => $ahora,
            'expires_at' => $vence,
        ]);

        return ['reference' => $reference, 'expires_at' => $vence->toIso8601String()];
    }

    public function consumir(LeadMessage $mensaje): void
    {
        if ($mensaje->sender !== 'lead' || $mensaje->is_status_event) {
            return;
        }
        preg_match_all('/\[CC:([a-f0-9]{32})\]/', (string) $mensaje->content, $matches);
        // Un texto con dos referencias distintas es ambiguo: nunca elegimos una por posición.
        $codigos = array_values(array_unique($matches[1]));
        if (count($codigos) !== 1) {
            return;
        }
        DB::transaction(function () use ($mensaje, $codigos) {
            // El lock del lead serializa dos mensajes distintos que reclaman el mismo contacto.
            $lead = Lead::query()->whereKey($mensaje->lead_id)->lockForUpdate()->first();
            if ($lead === null) {
                return;
            }
            $referencia = DB::table('landing_whatsapp_referencias')
                ->where('reference_hash', hash('sha256', $codigos[0]))->lockForUpdate()->first();
            if ($referencia === null) {
                return;
            }
            if ($referencia->claimed_at !== null) {
                // Reentrega del mismo mensaje: no cambia la evidencia ni consume dos veces.
                return;
            }
            if (Carbon::parse($referencia->expires_at)->lte(AppTime::now())) {
                return;
            }
            DB::table('landing_whatsapp_referencias')->where('id', $referencia->id)->update([
                'claimed_at' => AppTime::now(),
                'claimed_lead_id' => $mensaje->lead_id,
                'claimed_message_id' => $mensaje->id,
            ]);
            $this->guardar_contacto($mensaje, 'referencia_landing', json_decode($referencia->datos, true), (int) $referencia->id, null);
        }, 3);
    }

    public function registrar_ctwa(LeadMessage $mensaje, WhatsappAdReferral $referral): void
    {
        // Para llamar determinista al enlace exigimos wamid, sin el fallback temporal legacy.
        if (trim((string) $mensaje->whatsapp_message_id) === ''
            || (string) $mensaje->whatsapp_message_id !== (string) $referral->wamid) {
            return;
        }
        DB::transaction(function () use ($mensaje, $referral) {
            $lead = Lead::query()->whereKey($mensaje->lead_id)->lockForUpdate()->first();
            if ($lead === null) {
                return;
            }
            $datos = [
                'ad_id' => $referral->source_type === 'ad' ? $referral->source_id : null,
                'ad_name' => $referral->headline,
                'source_id' => $referral->source_id,
                'source_type' => $referral->source_type,
            ];
            $this->guardar_contacto($mensaje, 'meta_ctwa', $datos, null, (int) $referral->id);
        }, 3);
    }

    private function guardar_contacto(LeadMessage $mensaje, string $evidencia, array $datos, ?int $referencia_id, ?int $referral_id): void
    {
        $existente = DB::table('lead_atribucion_contactos')->where('message_id', $mensaje->id)->first();
        $primero = LeadOrigenService::primer_mensaje_entrante((int) $mensaje->lead_id);
        $fila = [
            'lead_id' => $mensaje->lead_id,
            'message_id' => $mensaje->id,
            'referencia_id' => $referencia_id,
            'referral_id' => $referral_id,
            'tipo' => $primero !== null && (int) $primero->id === (int) $mensaje->id ? 'adquisicion' : 'recontacto',
            'evidencia' => $evidencia,
            'datos' => json_encode($datos),
            'contacto_at' => $mensaje->sent_at ?: $mensaje->created_at,
            'created_at' => AppTime::now(),
        ];
        if ($existente === null) {
            DB::table('lead_atribucion_contactos')->insert($fila);
        } elseif ($evidencia === 'meta_ctwa' && $existente->evidencia !== 'meta_ctwa') {
            // CTWA nativo del MISMO mensaje gana; nunca reemplaza otro contacto histórico.
            $fila['referencia_id'] = $existente->referencia_id;
            DB::table('lead_atribucion_contactos')->where('id', $existente->id)->update($fila);
        }
    }

    /**
     * El sufijo técnico no cambia lo que la persona pidió, aunque haya vencido o no acredite anuncio.
     * La autenticidad/consumo se decide por separado; el original se conserva siempre en BD.
     */
    public static function contenido_para_agente(LeadMessage $mensaje): string
    {
        $contenido = (string) $mensaje->content;
        if ($mensaje->sender !== 'lead' || strpos($contenido, '[CC:') === false) {
            return $contenido;
        }
        return trim((string) preg_replace('/\s*\[CC:[a-f0-9]{32}\]\s*$/D', '', $contenido));
    }

    /** Se invoca únicamente DESPUÉS de persistir una reserva nueva, nunca desde un GET. */
    public function registrar_reserva(Lead $lead): void
    {
        if (! $lead->demo_id || ! $lead->demo_date || ! $lead->demo_start_time
            || ! in_array((string) $lead->status, ['demo_agendada', 'ingresando_demo', 'demo_en_curso', 'demo_pendiente_de_ingreso', 'demo_pendiente_de_terminar'], true)) {
            return;
        }
        DB::transaction(function () use ($lead) {
            $actual = Lead::query()->whereKey($lead->id)->lockForUpdate()->first();
            if ($actual === null || ! $actual->demo_id || ! $actual->demo_date || ! $actual->demo_start_time
                || ! in_array((string) $actual->status, ['demo_agendada', 'ingresando_demo', 'demo_en_curso', 'demo_pendiente_de_ingreso', 'demo_pendiente_de_terminar'], true)) {
                return;
            }
            if (DB::table('lead_atribucion_reservas')->where('lead_id', $lead->id)->exists()) {
                return;
            }
            $contacto = DB::table('lead_atribucion_contactos')->where('lead_id', $lead->id)->orderByDesc('message_id')->first();
            DB::table('lead_atribucion_reservas')->insert([
                'lead_id' => $lead->id,
                'contacto_id' => $contacto !== null ? $contacto->id : null,
                'evidencia' => $contacto !== null ? $contacto->evidencia : null,
                'datos' => $contacto !== null ? $contacto->datos : null,
                'demo_id' => $actual->demo_id,
                'demo_date' => $actual->demo_date->format('Y-m-d'),
                'demo_start_time' => $actual->demo_start_time,
                'demo_end_time' => $actual->demo_end_time,
                'reserved_at' => AppTime::now(),
            ]);
        }, 3);
    }

    private function contacto_publicable($fila): ?array
    {
        if ($fila === null) {
            return null;
        }
        $datos = json_decode($fila->datos ?: '{}', true);
        unset($datos['fbclid']);
        return [
            'tipo' => $fila->tipo,
            'evidencia' => $fila->evidencia,
            'contacto_at' => $fila->contacto_at,
            'datos' => $datos,
        ];
    }

    public function detalle(Lead $lead): array
    {
        $contactos = DB::table('lead_atribucion_contactos')->where('lead_id', $lead->id);
        $primero = (clone $contactos)->where('tipo', 'adquisicion')->orderBy('message_id')->first();
        $reciente = (clone $contactos)->where('tipo', 'recontacto')->orderByDesc('message_id')->first();
        $reserva = DB::table('lead_atribucion_reservas')->where('lead_id', $lead->id)->first();
        return [
            'origen' => $lead->origen,
            'adquisicion' => $this->contacto_publicable($primero),
            'recontacto' => $this->contacto_publicable($reciente),
            'reserva' => $reserva === null ? null : [
                'reserved_at' => $reserva->reserved_at,
                'demo_date' => $reserva->demo_date,
                'demo_start_time' => $reserva->demo_start_time,
                'evidencia' => $reserva->evidencia,
                'datos' => array_diff_key(json_decode($reserva->datos ?: '{}', true), ['fbclid' => true]),
            ],
            'nota' => 'Referencia de landing: parámetros declarados en URL, no atribución certificada por Meta. Sin evidencia no se asigna anuncio.',
        ];
    }

    public function metricas(string $desde, string $hasta): array
    {
        // Cohorte por alta del lead: LEFT JOIN preserva todos los desconocidos del denominador.
        $filas = DB::table('leads as l')
            ->leftJoin('lead_atribucion_contactos as c', function ($join) {
                $join->on('c.lead_id', '=', 'l.id')->where('c.tipo', '=', 'adquisicion');
            })
            ->leftJoin('lead_atribucion_reservas as r', 'r.lead_id', '=', 'l.id')
            ->whereBetween('l.created_at', [$desde, $hasta])
            ->selectRaw("COALESCE(c.evidencia, 'sin_atribucion') as evidencia, COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.datos, '$.ad_id')), '') as ad_id, MAX(JSON_UNQUOTE(JSON_EXTRACT(c.datos, '$.ad_name'))) as ad_name, MAX(JSON_UNQUOTE(JSON_EXTRACT(c.datos, '$.campaign_name'))) as campaign_name, COUNT(*) as leads, COUNT(r.id) as reservas")
            ->groupByRaw("COALESCE(c.evidencia, 'sin_atribucion'), COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.datos, '$.ad_id')), '')")
            ->orderByDesc('leads')->get();
        $total = 0;
        $grupos = [];
        foreach ($filas as $fila) {
            $total += (int) $fila->leads;
            $grupos[] = [
                'evidencia' => $fila->evidencia,
                'ad_id' => $fila->ad_id === 'null' ? '' : $fila->ad_id,
                'ad_name' => $fila->ad_name,
                'campaign_name' => $fila->campaign_name,
                'leads' => (int) $fila->leads,
                'reservas' => (int) $fila->reservas,
            ];
        }
        return [
            'desde' => $desde, 'hasta' => $hasta, 'generated_at' => AppTime::now()->toIso8601String(),
            'denominador' => $total, 'grupos' => $grupos,
            'recontactos' => DB::table('lead_atribucion_contactos')->where('tipo', 'recontacto')->whereBetween('contacto_at', [$desde, $hasta])->count(),
            'nota' => 'Cohorte de leads creados en el período. Reservas: primera reserva real registrada desde que existe esta medición, hasta ahora; puede ser posterior al período. Recontactos separados, fuera del denominador. Sin atribución incluye altas sin mensaje o sin evidencia. No se reconstruyen reservas históricas ni se certifican parámetros de URL.',
        ];
    }
}

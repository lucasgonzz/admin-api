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
    /** Evidencia del contacto que deja una reserva hecha desde el formulario de la landing (22/10/2026). */
    const EVIDENCIA_RESERVA_LANDING = 'reserva_landing';

    /**
     * La lista blanca de los datos de atribución que manda la landing, con sus reglas.
     *
     * Movida tal cual de `LeadAtribucionController::emitir` el 22/10/2026 (misión
     * demo-autogestionada-landing): la reserva desde la landing manda el mismo bloque `atribucion`, y
     * tiene que aceptar exactamente lo mismo —ni una clave más (nunca PII ni un lead elegido por el
     * navegador), ni otra regla—.
     *
     * @return array<string, array<int, string>>
     */
    public static function reglas_de_los_datos(): array
    {
        $reglas = [];
        foreach (['campaign_id', 'adset_id', 'ad_id'] as $campo) {
            $reglas[$campo] = ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'];
        }
        foreach (['campaign_name', 'adset_name', 'ad_name', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid'] as $campo) {
            $reglas[$campo] = ['sometimes', 'nullable', 'string', 'max:500', 'not_regex:/[{}\x00-\x1F]/'];
        }

        return $reglas;
    }

    /**
     * Saca los valores nulos o vacíos de los datos ya validados (lo que hacía `emitir` en línea).
     *
     * @param array<string, mixed> $datos
     *
     * @return array<string, mixed>
     */
    public static function sin_vacios(array $datos): array
    {
        return array_filter($datos, function ($valor) {
            return $valor !== null && $valor !== '';
        });
    }

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

    /**
     * El contacto de atribución de una reserva hecha desde el formulario de la landing (misión
     * demo-autogestionada-landing, 22/10/2026, D7 del plan).
     *
     * El `message_id` es el evento `sistema` que la reserva deja en el hilo (la tabla lo exige NOT NULL
     * UNIQUE y no hay mensaje del lead: nunca escribió). Los datos son el bloque `atribucion` del
     * formulario, ya validado con {@see reglas_de_los_datos()}: los mismos campos que una referencia
     * `[CC:…]`, con el `fbclid` incluido (lo usa {@see MetaConversionesService::fbc_del_lead()}).
     *
     * `adquisicion` si el lead no tenía ningún contacto (es como llegó), `recontacto` si ya tenía.
     * Después, `registrar_reserva()` lo toma como el último contacto del lead.
     *
     * @param LeadMessage          $evento Evento `sistema` de la reserva.
     * @param array<string, mixed> $datos  Atribución validada (puede venir vacía).
     *
     * @return void
     */
    public function registrar_contacto_de_reserva(LeadMessage $evento, array $datos): void
    {
        DB::transaction(function () use ($evento, $datos) {
            $lead = Lead::query()->whereKey($evento->lead_id)->lockForUpdate()->first();
            if ($lead === null) {
                return;
            }
            if (DB::table('lead_atribucion_contactos')->where('message_id', $evento->id)->exists()) {
                return;
            }
            $habia_contactos = DB::table('lead_atribucion_contactos')->where('lead_id', $evento->lead_id)->exists();
            DB::table('lead_atribucion_contactos')->insert([
                'lead_id' => $evento->lead_id,
                'message_id' => $evento->id,
                'referencia_id' => null,
                'referral_id' => null,
                'tipo' => $habia_contactos ? 'recontacto' : 'adquisicion',
                'evidencia' => self::EVIDENCIA_RESERVA_LANDING,
                'datos' => json_encode(self::sin_vacios($datos)),
                'contacto_at' => $evento->sent_at ?: $evento->created_at,
                'created_at' => AppTime::now(),
            ]);
        }, 3);
    }

    private function guardar_contacto(LeadMessage $mensaje, string $evidencia, array $datos, ?int $referencia_id, ?int $referral_id): void
    {
        $existente = DB::table('lead_atribucion_contactos')->where('message_id', $mensaje->id)->first();
        $primero = LeadOrigenService::primer_mensaje_entrante((int) $mensaje->lead_id);
        $tipo = $primero !== null && (int) $primero->id === (int) $mensaje->id ? 'adquisicion' : 'recontacto';
        /* 🔴 Una sola adquisición por lead (22/10/2026). Hasta la reserva desde la landing la adquisición
           era siempre el primer mensaje entrante, y había una sola por construcción. Ahora un lead puede
           haber llegado por el formulario (contacto `reserva_landing` de adquisición, sin mensaje suyo)
           y escribir después con una referencia: ese primer mensaje ya no es cómo llegó, es un
           recontacto. Con dos adquisiciones, el LEFT JOIN de `metricas()` contaría al lead dos veces. */
        if ($tipo === 'adquisicion' && DB::table('lead_atribucion_contactos')
            ->where('lead_id', $mensaje->lead_id)
            ->where('tipo', 'adquisicion')
            ->where('message_id', '!=', $mensaje->id)
            ->exists()) {
            $tipo = 'recontacto';
        }
        $fila = [
            'lead_id' => $mensaje->lead_id,
            'message_id' => $mensaje->id,
            'referencia_id' => $referencia_id,
            'referral_id' => $referral_id,
            'tipo' => $tipo,
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

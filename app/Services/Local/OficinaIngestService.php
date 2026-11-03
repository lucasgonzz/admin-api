<?php

namespace App\Services\Local;

use App\Helpers\AppTime;
use App\Models\AdminSetting;
use App\Models\LocalOficinaSlot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Valida y guarda lo que manda el reporter local de la PC de Lucas cada minuto (misión el-local,
 * 3/11/2026, contrato A del plan: `POST api/claude/local/oficina`).
 *
 * Dos escrituras, las dos idempotentes:
 *   1. Un upsert por `slot` en `local_oficina_slots` (una sola consulta para los 35), y el borrado de los
 *      slots que no vinieron en el reporte (solo si el reporte trae alguno).
 *   2. El pulso del reporter en `admin_settings`, clave {@see self::PULSO_KEY}:
 *      `{"last_seen_at", "version", "host", "slots_total", "sesiones"}`. Con eso la escena sabe si el
 *      reporter está vivo (`oficina.reporter.ok`, menos de 5 minutos).
 *
 * 🔴 Lo que no está en el contrato no se guarda: las claves desconocidas del payload se ignoran (no dan
 * 422), para que un reporter más nuevo que el admin no se quede afuera; las conocidas se validan con sus
 * enums exactos.
 *
 * Las fechas llegan ISO con zona (los claims y el reflog en -03:00, los transcripts en UTC) y se guardan en
 * la hora del admin, que es la de `config('app.timezone')`.
 */
class OficinaIngestService
{
    /** Clave de `admin_settings` donde queda el pulso del reporter. */
    const PULSO_KEY = 'local_reporter_pulso';

    /** Tope de slots por reporte (contrato A). */
    const TOPE_SLOTS = 60;

    /** Tope de agentes por slot (contrato A). */
    const TOPE_AGENTES = 20;

    /** Tope de `sucios`: la columna es un smallint sin signo. */
    const TOPE_SUCIOS = 65535;

    /**
     * Reglas de validación del contrato A.
     *
     * 🔴 `slot` y `estado` son obligatorios en cada fila: sin el número no hay qué actualizar, y sin el
     * estado la mesa no se puede dibujar (inventarle "libre" mentiría). Todo lo demás es opcional.
     *
     * @return array<string, string>
     */
    public function reglas()
    {
        return [
            'reporter'               => 'nullable|array',
            'reporter.version'       => 'nullable|string|max:40',
            'reporter.host'          => 'nullable|string|max:120',
            'reporter.enviado_at'    => 'nullable|date',
            'reporter.slots_total'   => 'nullable|integer|min:0|max:' . self::TOPE_SLOTS,

            'sesiones'               => 'nullable|array',
            'sesiones.claude_busy'   => 'nullable|integer|min:0|max:10000',
            'sesiones.claude_idle'   => 'nullable|integer|min:0|max:10000',
            'sesiones.codex_activos' => 'nullable|integer|min:0|max:10000',

            'slots'                         => 'present|array|max:' . self::TOPE_SLOTS,
            'slots.*'                       => 'array',
            'slots.*.slot'                  => 'required|integer|min:1|max:999|distinct',
            'slots.*.proyecto'              => 'nullable|string|max:20',
            'slots.*.estado'                => 'required|string|in:' . implode(',', LocalOficinaSlot::ESTADOS),
            'slots.*.rama'                  => 'nullable|string|max:120',
            'slots.*.mision'                => 'nullable|string|max:120',
            'slots.*.fase'                  => 'nullable|string|in:' . implode(',', LocalOficinaSlot::FASES),
            'slots.*.claim_iniciado_at'     => 'nullable|date',
            'slots.*.ultima_actividad_at'   => 'nullable|date',
            'slots.*.sucios'                => 'nullable|integer|min:0|max:' . self::TOPE_SUCIOS,
            'slots.*.agentes'               => 'nullable|array|max:' . self::TOPE_AGENTES,
            'slots.*.agentes.*'             => 'array',
            'slots.*.agentes.*.rol'         => 'required|string|in:' . implode(',', LocalOficinaSlot::ROLES),
            'slots.*.agentes.*.runtime'     => 'required|string|in:' . implode(',', LocalOficinaSlot::RUNTIMES),
            'slots.*.agentes.*.estado'      => 'required|string|in:' . implode(',', LocalOficinaSlot::ESTADOS_DE_AGENTE),
            'slots.*.agentes.*.desde_at'    => 'nullable|date',
            'slots.*.agentes.*.nombre'      => 'nullable|string|max:120',
        ];
    }

    /**
     * Valida el payload.
     *
     * @param array<string, mixed>  $datos    Body del reporte.
     * @param array<string, string> $mensajes Mensajes de validación en español (los del bloque `claude/*`).
     *
     * @return array<string, array<int, string>>|null Errores por campo, o null si valida.
     */
    public function validar(array $datos, array $mensajes = [])
    {
        $validator = Validator::make($datos, $this->reglas(), $mensajes);

        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        return null;
    }

    /**
     * Guarda un reporte ya validado: upsert de los slots y pulso del reporter, en una transacción.
     *
     * @param array<string, mixed> $datos Body validado.
     *
     * @return array{recibido_at: Carbon, slots: int}
     */
    public function guardar(array $datos)
    {
        $ahora    = AppTime::now();
        $ahora_db = $this->formato_db($ahora);
        $slots    = isset($datos['slots']) && is_array($datos['slots']) ? $datos['slots'] : [];

        $filas = [];
        foreach ($slots as $slot) {
            $filas[] = $this->fila_de($slot, $ahora_db);
        }

        $reporter = isset($datos['reporter']) && is_array($datos['reporter']) ? $datos['reporter'] : [];
        $sesiones = isset($datos['sesiones']) && is_array($datos['sesiones']) ? $datos['sesiones'] : [];

        $pulso = [
            'last_seen_at' => $ahora->toIso8601String(),
            'version'      => $this->texto_o_null(isset($reporter['version']) ? $reporter['version'] : null),
            'host'         => $this->texto_o_null(isset($reporter['host']) ? $reporter['host'] : null),
            'slots_total'  => isset($reporter['slots_total']) && $reporter['slots_total'] !== null
                ? (int) $reporter['slots_total']
                : count($filas),
            'sesiones'     => [
                'claude_busy'   => $this->entero_o_null(isset($sesiones['claude_busy']) ? $sesiones['claude_busy'] : null),
                'claude_idle'   => $this->entero_o_null(isset($sesiones['claude_idle']) ? $sesiones['claude_idle'] : null),
                'codex_activos' => $this->entero_o_null(isset($sesiones['codex_activos']) ? $sesiones['codex_activos'] : null),
            ],
        ];

        DB::transaction(function () use ($filas, $pulso) {
            if (count($filas) > 0) {
                /* Una sola consulta para todos los slots. `upsert` de Eloquent pone created_at/updated_at
                   solo; los casts NO se aplican, por eso `agentes` ya viene codificado en fila_de(). */
                LocalOficinaSlot::query()->upsert($filas, ['slot'], [
                    'proyecto', 'estado', 'rama', 'mision', 'fase', 'claim_iniciado_at',
                    'ultima_actividad_at', 'sucios', 'agentes', 'reportado_at',
                ]);

                /* La tabla es un ESPEJO del pool: el reporter manda todos los slots siempre, así que uno que
                   dejó de venir ya no existe (el pool se achicó) y su mesa no se puede seguir dibujando.
                   🔴 Solo con `slots` no vacío: un reporte vacío (pool.json ilegible) no puede vaciar la oficina. */
                LocalOficinaSlot::query()->whereNotIn('slot', array_column($filas, 'slot'))->delete();
            }

            AdminSetting::set(self::PULSO_KEY, json_encode($pulso));
        });

        return ['recibido_at' => $ahora, 'slots' => count($filas)];
    }

    /**
     * Arma la fila de la tabla para un slot del payload.
     *
     * @param array<string, mixed> $slot     Slot validado.
     * @param string               $ahora_db Momento de recepción ('Y-m-d H:i:s').
     *
     * @return array<string, mixed>
     */
    private function fila_de(array $slot, $ahora_db)
    {
        $agentes = [];
        foreach ((isset($slot['agentes']) && is_array($slot['agentes']) ? $slot['agentes'] : []) as $agente) {
            $desde = $this->fecha_o_null(isset($agente['desde_at']) ? $agente['desde_at'] : null);

            $agentes[] = [
                'rol'      => (string) $agente['rol'],
                'runtime'  => (string) $agente['runtime'],
                'estado'   => (string) $agente['estado'],
                'desde_at' => $desde === null ? null : $desde->toIso8601String(),
                'nombre'   => $this->texto_o_null(isset($agente['nombre']) ? $agente['nombre'] : null),
            ];
        }

        $claim     = $this->fecha_o_null(isset($slot['claim_iniciado_at']) ? $slot['claim_iniciado_at'] : null);
        $actividad = $this->fecha_o_null(isset($slot['ultima_actividad_at']) ? $slot['ultima_actividad_at'] : null);

        return [
            'slot'                => (int) $slot['slot'],
            'proyecto'            => $this->texto_o_null(isset($slot['proyecto']) ? $slot['proyecto'] : null),
            'estado'              => (string) $slot['estado'],
            'rama'                => $this->texto_o_null(isset($slot['rama']) ? $slot['rama'] : null),
            'mision'              => $this->texto_o_null(isset($slot['mision']) ? $slot['mision'] : null),
            'fase'                => $this->texto_o_null(isset($slot['fase']) ? $slot['fase'] : null),
            'claim_iniciado_at'   => $claim === null ? null : $this->formato_db($claim),
            'ultima_actividad_at' => $actividad === null ? null : $this->formato_db($actividad),
            'sucios'              => isset($slot['sucios']) && $slot['sucios'] !== null ? (int) $slot['sucios'] : 0,
            'agentes'             => json_encode($agentes),
            'reportado_at'        => $ahora_db,
        ];
    }

    /**
     * Fecha ISO (con o sin zona) pasada a la hora del admin, o null.
     *
     * Sin zona se interpreta como hora del admin, que es lo que manda el reporter para los claims.
     *
     * @param mixed $valor Valor crudo.
     *
     * @return Carbon|null
     */
    private function fecha_o_null($valor)
    {
        if ($valor === null || $valor === '' || is_array($valor)) {
            return null;
        }

        try {
            return Carbon::parse((string) $valor, config('app.timezone'))->setTimezone(config('app.timezone'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Formato de columna datetime, en la hora del admin.
     *
     * @param Carbon $fecha
     *
     * @return string
     */
    private function formato_db(Carbon $fecha)
    {
        return $fecha->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /**
     * Texto recortado o null si quedó vacío.
     *
     * @param mixed $valor
     *
     * @return string|null
     */
    private function texto_o_null($valor)
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Entero o null.
     *
     * @param mixed $valor
     *
     * @return int|null
     */
    private function entero_o_null($valor)
    {
        if ($valor === null || $valor === '' || is_array($valor) || ! is_numeric($valor)) {
            return null;
        }

        return (int) $valor;
    }
}

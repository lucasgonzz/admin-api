<?php

namespace App\Services\MartinActivity;

use App\Models\ImplementationFollowup;
use App\Models\SupportCodexActivity;
use App\Models\SupportCodexPlan;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * La auditoría de Martín (misión cruzada `auditoria-de-martin`, 11/11/2026): la ingesta de lo que el monitor registra
 * al ejecutar cada comando (`claude/support-codex/activity`), el plan de cada atención (`claude/support-codex/plans`) y
 * la lectura que arma el panel (`admin/support-ticket/{id}/martin-activity` y los dos contadores de la bandeja).
 *
 * Reglas:
 * - El `client_id` sale SIEMPRE del ticket o del seguimiento, nunca del pedido.
 * - Idempotencia por `(run_id, command, occurred_at, summary)`: el monitor reintenta lo que no pudo mandar, así que
 *   un mismo item llega más de una vez. Se calcula un sha256 de esos cuatro campos (con `occurred_at` normalizado a UTC
 *   con microsegundos, para que la misma hora mandada con otro huso no cuente como distinta) y lo frena el índice
 *   único `sca_dedupe_unique` con `INSERT IGNORE`, que también cubre dos tandas iguales que llegan a la vez.
 * - Tamaños: `summary` ≤ 300, `detail` ≤ 2 KB en JSON, hasta 50 items por tanda; el plan, objetivo ≤ 300 y hasta 15
 *   pasos de ≤ 200.
 * - Nada de esto exige la reserva del ticket: la auditoría tiene que quedar aunque la reserva ya se haya usado.
 */
class MartinActivityService
{
    const MAX_ITEMS = 50;
    const LARGO_SUMMARY = 300;
    const LARGO_COMMAND = 40;
    const LARGO_RUN_ID = 64;
    const MAX_DETAIL_BYTES = 2048;

    /** El formato de un `run_id` (un UUID, o algo parecido que arme el monitor): sin barras ni espacios. */
    const PATRON_RUN_ID = '/^[A-Za-z0-9._:-]{1,64}$/';

    /**
     * Las reglas de `POST claude/support-codex/activity`.
     *
     * @return array<string, string>
     */
    public static function reglas_de_actividad()
    {
        return [
            'items'                              => 'required|array|min:1|max:' . self::MAX_ITEMS,
            'items.*'                            => 'required|array',
            'items.*.support_ticket_id'          => 'nullable|integer|min:1|required_without:items.*.implementation_followup_id',
            'items.*.implementation_followup_id' => 'nullable|integer|min:1|required_without:items.*.support_ticket_id',
            'items.*.run_id'                     => 'required|string|max:' . self::LARGO_RUN_ID . '|regex:' . self::PATRON_RUN_ID,
            'items.*.kind'                       => 'required|string|in:' . implode(',', SupportCodexActivity::KINDS),
            'items.*.command'                    => 'required|string|max:' . self::LARGO_COMMAND,
            'items.*.summary'                    => 'required|string|max:' . self::LARGO_SUMMARY,
            'items.*.detail'                     => 'nullable|array',
            'items.*.ok'                         => 'required|boolean',
            'items.*.occurred_at'                => 'required|date',
        ];
    }

    /**
     * Las reglas de `PUT claude/support-codex/plans/{run_id}`.
     *
     * @return array<string, string>
     */
    public static function reglas_de_plan()
    {
        return [
            'support_ticket_id'          => 'nullable|integer|min:1|required_without:implementation_followup_id',
            'implementation_followup_id' => 'nullable|integer|min:1|required_without:support_ticket_id',
            'objetivo'                   => 'nullable|string|max:' . SupportCodexPlan::LARGO_OBJETIVO,
            'pasos'                      => 'present|array|max:' . SupportCodexPlan::MAX_PASOS,
            'pasos.*'                    => 'required|array',
            'pasos.*.texto'              => 'required|string|max:' . SupportCodexPlan::LARGO_PASO,
            'pasos.*.estado'             => 'required|string|in:' . implode(',', SupportCodexPlan::ESTADOS),
        ];
    }

    /**
     * Lo que las reglas de Laravel no pueden ver: el tamaño del `detail` y que el ticket o el seguimiento existan.
     * Devuelve los errores con la misma forma que `$validator->errors()` (`items.N.campo => [mensaje]`); vacío si está
     * todo bien. Se corre DESPUÉS de las reglas.
     *
     * @param array<int, array<string, mixed>> $items Los items ya validados.
     *
     * @return array<string, array<int, string>>
     */
    public function errores_de_actividad(array $items)
    {
        $errores = [];
        list($tickets, $seguimientos) = $this->clientes_de($items);

        foreach (array_values($items) as $indice => $item) {
            if (isset($item['detail']) && strlen((string) json_encode($item['detail'], JSON_UNESCAPED_UNICODE)) > self::MAX_DETAIL_BYTES) {
                $errores['items.' . $indice . '.detail'] = ['El parámetro detail supera los 2 KB: va chico ({filas, truncado}, {lineas}...), nunca datos crudos.'];
            }

            $ticket_id = $this->entero($item, 'support_ticket_id');
            if ($ticket_id !== null && ! array_key_exists($ticket_id, $tickets)) {
                $errores['items.' . $indice . '.support_ticket_id'] = ['El ticket que mandaste no existe.'];
            }

            $followup_id = $this->entero($item, 'implementation_followup_id');
            if ($followup_id !== null && ! array_key_exists($followup_id, $seguimientos)) {
                $errores['items.' . $indice . '.implementation_followup_id'] = ['El seguimiento que mandaste no existe.'];
            }
        }

        return $errores;
    }

    /**
     * Guarda la tanda. Los repetidos (ya guardados antes, o repetidos dentro de la misma tanda) no se vuelven a
     * guardar y no son un error.
     *
     * @param array<int, array<string, mixed>> $items Los items ya validados (reglas + `errores_de_actividad`).
     *
     * @return array{guardados: int, repetidos: int}
     */
    public function registrar(array $items)
    {
        list($tickets, $seguimientos) = $this->clientes_de($items);
        $ahora = now()->format('Y-m-d H:i:s');
        $filas = [];

        foreach ($items as $item) {
            $ticket_id   = $this->entero($item, 'support_ticket_id');
            $followup_id = $this->entero($item, 'implementation_followup_id');
            /* Con los dos, manda el ticket: es lo que muestra el panel. */
            $client_id = $ticket_id !== null ? $tickets[$ticket_id] : $seguimientos[$followup_id];

            $cuando  = Carbon::parse((string) $item['occurred_at'])->setTimezone(config('app.timezone'));
            $run_id  = (string) $item['run_id'];
            $command = (string) $item['command'];
            $summary = (string) $item['summary'];
            $hash    = self::hash_de($run_id, $command, $cuando, $summary);

            if (isset($filas[$hash])) {
                continue;
            }

            $filas[$hash] = [
                'support_ticket_id'          => $ticket_id,
                'implementation_followup_id' => $followup_id,
                'client_id'                  => $client_id,
                'run_id'                     => $run_id,
                'kind'                       => (string) $item['kind'],
                'command'                    => $command,
                'summary'                    => $summary,
                'detail'                     => isset($item['detail']) ? json_encode($item['detail'], JSON_UNESCAPED_UNICODE) : null,
                'ok'                         => (bool) filter_var($item['ok'], FILTER_VALIDATE_BOOLEAN),
                'occurred_at'                => $cuando->format('Y-m-d H:i:s'),
                'dedupe_hash'                => $hash,
                'created_at'                 => $ahora,
                'updated_at'                 => $ahora,
            ];
        }

        $guardados = $filas === [] ? 0 : (int) DB::table('support_codex_activities')->insertOrIgnore(array_values($filas));

        return ['guardados' => $guardados, 'repetidos' => count($items) - $guardados];
    }

    /**
     * La clave de idempotencia de una acción.
     *
     * @param string $run_id  La ejecución.
     * @param string $command El subcomando.
     * @param Carbon $cuando  Cuándo ocurrió.
     * @param string $summary El resumen.
     *
     * @return string
     */
    public static function hash_de($run_id, $command, Carbon $cuando, $summary)
    {
        return hash('sha256', implode("\n", [$run_id, $command, $cuando->copy()->utc()->format('Y-m-d\TH:i:s.u'), $summary]));
    }

    /**
     * Lo que el plan necesita y las reglas no ven: que el ticket o el seguimiento existan.
     *
     * @param array<string, mixed> $datos El body ya validado.
     *
     * @return array<string, array<int, string>>
     */
    public function errores_de_plan(array $datos)
    {
        $errores = [];

        $ticket_id = $this->entero($datos, 'support_ticket_id');
        if ($ticket_id !== null && ! SupportTicket::query()->whereKey($ticket_id)->exists()) {
            $errores['support_ticket_id'] = ['El ticket que mandaste no existe.'];
        }

        $followup_id = $this->entero($datos, 'implementation_followup_id');
        if ($followup_id !== null && ! ImplementationFollowup::query()->whereKey($followup_id)->exists()) {
            $errores['implementation_followup_id'] = ['El seguimiento que mandaste no existe.'];
        }

        return $errores;
    }

    /**
     * Reemplaza el plan de la ejecución (upsert por `run_id`).
     *
     * @param string               $run_id La ejecución.
     * @param array<string, mixed> $datos  El body ya validado.
     *
     * @return SupportCodexPlan
     */
    public function guardar_plan($run_id, array $datos)
    {
        $pasos = [];
        foreach ((array) $datos['pasos'] as $paso) {
            $pasos[] = ['texto' => (string) $paso['texto'], 'estado' => (string) $paso['estado']];
        }

        $objetivo = isset($datos['objetivo']) ? trim((string) $datos['objetivo']) : '';
        $valores  = [
            'support_ticket_id'          => $this->entero($datos, 'support_ticket_id'),
            'implementation_followup_id' => $this->entero($datos, 'implementation_followup_id'),
            'objetivo'                   => $objetivo === '' ? null : $objetivo,
            'pasos'                      => $pasos,
        ];

        try {
            return SupportCodexPlan::updateOrCreate(['run_id' => $run_id], $valores);
        } catch (QueryException $e) {
            /* Dos PUT del mismo run_id a la vez: el segundo choca con `scp_run_unique` en el alta. Gana el último. */
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            $plan = SupportCodexPlan::where('run_id', $run_id)->firstOrFail();
            $plan->fill($valores)->save();

            return $plan;
        }
    }

    /**
     * Lo que lee el panel de un ticket: una atención por `run_id` (la más nueva primero), cada una con su plan, sus
     * acciones en orden de ocurrencia y su resumen.
     *
     * `resumen`: `errores` cuenta toda acción con `ok: false` y las de `kind: error`; las demás claves cuentan las
     * acciones que salieron bien de su tipo (una escritura rechazada cuenta como error, no como escritura). Las de
     * `kind: plan` no entran en el resumen, pero sí en `total_acciones`.
     *
     * @param int $ticket_id El ticket.
     *
     * @return array{atenciones: array<int, array<string, mixed>>, total_acciones: int}
     */
    public function del_ticket($ticket_id)
    {
        $acciones = SupportCodexActivity::query()
            ->where('support_ticket_id', $ticket_id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'run_id', 'kind', 'command', 'summary', 'detail', 'ok', 'occurred_at']);

        $run_ids = $acciones->pluck('run_id')->unique()->values()->all();
        $planes  = SupportCodexPlan::query()
            ->where(function ($query) use ($ticket_id, $run_ids) {
                $query->where('support_ticket_id', $ticket_id);
                if ($run_ids !== []) {
                    $query->orWhereIn('run_id', $run_ids);
                }
            })
            ->get()
            ->keyBy('run_id');

        $atenciones = [];
        foreach ($acciones as $accion) {
            $run_id = (string) $accion->run_id;
            if (! isset($atenciones[$run_id])) {
                $atenciones[$run_id] = $this->atencion_vacia($run_id);
            }

            $cuando = $accion->occurred_at->toIso8601String();
            if ($atenciones[$run_id]['desde'] === null) {
                $atenciones[$run_id]['desde'] = $cuando;
            }
            $atenciones[$run_id]['hasta']      = $cuando;
            $atenciones[$run_id]['acciones'][] = [
                'kind'        => (string) $accion->kind,
                'command'     => (string) $accion->command,
                'summary'     => (string) $accion->summary,
                'detail'      => $accion->detail,
                'ok'          => (bool) $accion->ok,
                'occurred_at' => $cuando,
            ];
            $this->sumar_al_resumen($atenciones[$run_id]['resumen'], (string) $accion->kind, (bool) $accion->ok);
        }

        foreach ($planes as $run_id => $plan) {
            $run_id = (string) $run_id;
            if (! isset($atenciones[$run_id])) {
                /* Un plan sin acciones registradas (el monitor mandó el plan y la actividad no llegó todavía): la
                   atención se ubica por las fechas del plan. */
                $atenciones[$run_id]          = $this->atencion_vacia($run_id);
                $atenciones[$run_id]['desde'] = $plan->created_at->toIso8601String();
                $atenciones[$run_id]['hasta'] = $plan->updated_at->toIso8601String();
            }

            $atenciones[$run_id]['objetivo'] = $plan->objetivo;
            $atenciones[$run_id]['pasos']    = array_values((array) $plan->pasos);
        }

        $atenciones = array_values($atenciones);
        usort($atenciones, function ($a, $b) {
            $por_fecha = strcmp((string) $b['desde'], (string) $a['desde']);

            return $por_fecha !== 0 ? $por_fecha : strcmp($b['run_id'], $a['run_id']);
        });

        return ['atenciones' => $atenciones, 'total_acciones' => $acciones->count()];
    }

    /**
     * Suma a una consulta de tickets los dos contadores del puntito de la bandeja, como subconsultas correlacionadas
     * que resuelve el índice `sca_ticket_kind_ok_idx` sin leer la tabla (nada de N+1):
     * - `martin_activity_count`: todas las acciones registradas en el ticket (= `total_acciones` del panel).
     * - `martin_writes_count`: las escrituras que salieron bien (= `resumen.escrituras` sumado de todas las atenciones).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query La consulta de `SupportTicket`.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function sumar_contadores($query)
    {
        /* Sin esto el addSelect PISA el select (el mismo recaudo de SupportTicket::scopeWithIndicadoresDeInicio). */
        if ($query->getQuery()->columns === null) {
            $query->select('support_tickets.*');
        }

        $total = SupportCodexActivity::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('support_codex_activities.support_ticket_id', 'support_tickets.id');

        $escrituras = SupportCodexActivity::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('support_codex_activities.support_ticket_id', 'support_tickets.id')
            ->where('support_codex_activities.kind', SupportCodexActivity::KIND_ESCRITURA)
            ->where('support_codex_activities.ok', true);

        return $query
            ->addSelect(['martin_activity_count' => $total, 'martin_writes_count' => $escrituras])
            ->withCasts(['martin_activity_count' => 'integer', 'martin_writes_count' => 'integer']);
    }

    /**
     * @param string $run_id La ejecución.
     *
     * @return array<string, mixed>
     */
    private function atencion_vacia($run_id)
    {
        return [
            'run_id'   => $run_id,
            'desde'    => null,
            'hasta'    => null,
            'objetivo' => null,
            'pasos'    => [],
            'acciones' => [],
            'resumen'  => ['lecturas' => 0, 'escrituras' => 0, 'envios' => 0, 'consultas' => 0, 'errores' => 0],
        ];
    }

    /**
     * @param array<string, int> $resumen El resumen de la atención.
     * @param string             $kind    El tipo.
     * @param bool               $ok      Si salió bien.
     *
     * @return void
     */
    private function sumar_al_resumen(array &$resumen, $kind, $ok)
    {
        if (! $ok || $kind === SupportCodexActivity::KIND_ERROR) {
            $resumen['errores']++;

            return;
        }

        $claves = [
            SupportCodexActivity::KIND_LECTURA   => 'lecturas',
            SupportCodexActivity::KIND_ESCRITURA => 'escrituras',
            SupportCodexActivity::KIND_ENVIO     => 'envios',
            SupportCodexActivity::KIND_CONSULTA  => 'consultas',
        ];
        if (isset($claves[$kind])) {
            $resumen[$claves[$kind]]++;
        }
    }

    /**
     * Los clientes de los tickets y seguimientos que nombra la tanda, en dos consultas: `[id => client_id]`.
     *
     * @param array<int, array<string, mixed>> $items Los items.
     *
     * @return array{0: array<int, int|null>, 1: array<int, int|null>}
     */
    private function clientes_de(array $items)
    {
        $ticket_ids   = [];
        $followup_ids = [];
        foreach ($items as $item) {
            $ticket_id = $this->entero($item, 'support_ticket_id');
            if ($ticket_id !== null) {
                $ticket_ids[$ticket_id] = true;
            }
            $followup_id = $this->entero($item, 'implementation_followup_id');
            if ($followup_id !== null) {
                $followup_ids[$followup_id] = true;
            }
        }

        $tickets = [];
        if ($ticket_ids !== []) {
            foreach (SupportTicket::query()->whereIn('id', array_keys($ticket_ids))->get(['id', 'client_id']) as $ticket) {
                $tickets[(int) $ticket->id] = $ticket->client_id === null ? null : (int) $ticket->client_id;
            }
        }

        $seguimientos = [];
        if ($followup_ids !== []) {
            foreach (ImplementationFollowup::query()->whereIn('id', array_keys($followup_ids))->get(['id', 'client_id']) as $followup) {
                $seguimientos[(int) $followup->id] = $followup->client_id === null ? null : (int) $followup->client_id;
            }
        }

        return [$tickets, $seguimientos];
    }

    /**
     * @param array<string, mixed> $datos Los datos.
     * @param string               $clave La clave.
     *
     * @return int|null
     */
    private function entero(array $datos, $clave)
    {
        if (! isset($datos[$clave]) || $datos[$clave] === '' || ! is_numeric($datos[$clave])) {
            return null;
        }

        return (int) $datos[$clave];
    }
}

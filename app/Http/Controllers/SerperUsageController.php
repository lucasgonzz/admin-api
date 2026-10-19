<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientSerperUsage;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SerperUsageController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'events' => 'required|array|min:1|max:100',
            'events.*' => 'array:event_uuid,occurred_at,auth_user_id,auth_user_name,process,endpoint,query,ok,http_status,credits,duration_ms',
            'events.*.event_uuid' => 'required|uuid',
            'events.*.occurred_at' => 'required|date',
            'events.*.auth_user_id' => 'nullable|integer|min:1',
            'events.*.auth_user_name' => 'nullable|string|max:190',
            'events.*.process' => 'required|string|max:100',
            'events.*.endpoint' => 'required|in:search,images',
            'events.*.query' => 'nullable|string|max:500',
            'events.*.ok' => 'required|boolean',
            'events.*.http_status' => 'nullable|integer|min:100|max:599',
            'events.*.credits' => 'nullable|numeric|min:0|max:1000000',
            'events.*.duration_ms' => 'nullable|integer|min:0|max:4294967295',
        ]);
        $clientId = (int) $request->attributes->get('sync_client')->id;
        $rows = [];
        foreach ($data['events'] as $event) {
            $rows[] = array_merge(array_fill_keys([
                'auth_user_id', 'auth_user_name', 'query', 'http_status', 'credits', 'duration_ms',
            ], null), $event, [
                'client_id' => $clientId,
                'event_uuid' => strtolower($event['event_uuid']),
                'occurred_at' => Carbon::parse($event['occurred_at'])->utc()->format('Y-m-d H:i:s'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('client_serper_usages')->insertOrIgnore($rows);
        // accepted significa recibido durablemente: los repetidos ya estaban confirmados. El
        // emisor puede borrar todo el lote del outbox aunque reintente tras perder la respuesta.
        return response()->json(['accepted' => count($rows)]);
    }

    public function index(Request $request)
    {
        return response()->json($this->summary($request));
    }

    public function client(Request $request, $clientId)
    {
        $client = ctype_digit((string) $clientId) ? Client::findOrFail($clientId) : Client::where('uuid', $clientId)->firstOrFail();
        return response()->json($this->summary($request, (int) $client->id));
    }

    private function summary(Request $request, $clientId = null): array
    {
        $filters = $request->validate([
            'desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d',
            'client_id' => 'nullable|integer|min:1', 'auth_user_id' => 'nullable|integer|min:0',
            'process' => 'nullable|string|max:100', 'endpoint' => 'nullable|in:search,images',
            'result' => 'nullable|in:ok,failed,unknown_credits',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $since = Carbon::parse($filters['desde'] ?? now()->subDays(29)->toDateString())->startOfDay();
        $until = Carbon::parse($filters['hasta'] ?? now()->toDateString())->endOfDay();
        if ($until->lt($since) || $since->diffInDays($until) > 365) {
            throw ValidationException::withMessages(['desde' => 'Elegí un rango de hasta 366 días, con las fechas en orden.']);
        }
        $effectiveClient = $clientId ?: ($filters['client_id'] ?? null);
        $base = ClientSerperUsage::query()->whereBetween('occurred_at', [$since->copy()->utc(), $until->copy()->utc()]);
        if ($effectiveClient) {
            $base->where('client_id', $effectiveClient);
        }
        if (isset($filters['auth_user_id'])) {
            if ((int) $filters['auth_user_id'] === 0) {
                $base->whereNull('auth_user_id');
            } else {
                $base->where('auth_user_id', $filters['auth_user_id']);
            }
        }
        foreach (['process', 'endpoint'] as $field) {
            if (!empty($filters[$field])) {
                $base->where($field, $filters[$field]);
            }
        }
        if (($filters['result'] ?? '') === 'unknown_credits') {
            $base->whereNull('credits');
        } elseif (isset($filters['result'])) {
            $base->where('ok', $filters['result'] === 'ok');
        }
        $aggregate = 'COUNT(*) AS attempts, SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) AS successful, '
            . 'SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS failed, SUM(credits) AS credits, '
            . 'COUNT(credits) AS known_credit_events, SUM(CASE WHEN credits IS NULL THEN 1 ELSE 0 END) AS unknown_credit_events';
        $totals = $this->castAggregate((array) (clone $base)->selectRaw($aggregate)->first()->getAttributes());
        $groups = [];
        foreach (['por_cliente' => ['client_id'], 'por_proceso' => ['process', 'endpoint'], 'por_usuario' => ['client_id', 'auth_user_id']] as $key => $columns) {
            $query = (clone $base)->select($columns)->selectRaw($aggregate)->groupBy($columns)->orderByDesc('attempts');
            if ($key === 'por_usuario') {
                $query->selectRaw('MAX(auth_user_name) AS auth_user_name');
            }
            $groups[$key] = $query->get()->map(function ($row) {
                return $this->castAggregate($row->getAttributes());
            })->all();
        }
        // Argentina no usa horario de verano. El offset explícito evita depender de que el
        // servidor MySQL tenga cargadas las tablas de zonas horarias para America/Argentina.
        $localDay = "DATE(CONVERT_TZ(occurred_at, '+00:00', '" . $since->format('P') . "'))";
        $groups['por_dia'] = (clone $base)->selectRaw($localDay . ' AS date, ' . $aggregate)
            ->groupByRaw($localDay)->orderBy('date')->get()->map(function ($row) {
                return $this->castAggregate($row->getAttributes());
            })->all();
        $clientQuery = Client::query()->select(['id', 'name', 'company_name', 'is_active']);
        if ($effectiveClient) {
            $clientQuery->where('id', $effectiveClient);
        }
        $lastReports = ClientSerperUsage::query()->select('client_id')->selectRaw('MAX(created_at) AS received_at')->groupBy('client_id')->pluck('received_at', 'client_id');
        $clients = $clientQuery->get()->map(function ($client) use ($lastReports) {
            $last = $lastReports->get($client->id);
            return ['id' => (int) $client->id, 'name' => $client->company_name ?: $client->name,
                'has_telemetry' => $last !== null, 'last_received_at' => $last];
        })->all();
        $detail = (clone $base)->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50);
        return array_merge([
            'client_id' => $effectiveClient ? (int) $effectiveClient : null,
            'desde' => $since->toDateString(), 'hasta' => $until->toDateString(),
            'totales' => $totals, 'clientes' => $clients,
            'has_telemetry' => collect($clients)->contains('has_telemetry', true),
            'cost_usd' => null,
            'nota' => 'Los créditos son los informados por Serper. Los intentos sin créditos confirmados pueden haber tenido costo. No se informa un importe facturado.',
            'events' => $detail->items(), 'current_page' => $detail->currentPage(),
            'last_page' => $detail->lastPage(), 'total_events' => $detail->total(),
        ], $groups);
    }

    private function castAggregate(array $row): array
    {
        foreach (['attempts', 'successful', 'failed', 'known_credit_events', 'unknown_credit_events'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        $row['credits'] = $row['credits'] === null ? null : (float) $row['credits'];
        if (isset($row['client_id'])) {
            $row['client_id'] = (int) $row['client_id'];
        }
        if (isset($row['auth_user_id'])) {
            $row['auth_user_id'] = (int) $row['auth_user_id'];
        }
        return $row;
    }
}

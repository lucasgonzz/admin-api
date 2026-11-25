<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientUpgradeMark;
use App\Models\SupportCodexMission;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportTicket;
use App\Models\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Marcas «requiere actualización» de los clientes (misión martin-descripcion-y-actualizaciones, 25/11/2026).
 *
 * Lucas: «que en el admin se vayan marcando los clientes que requieren una actualización, porque plantearon un
 * problema y la misión que lo corrigió ya quedó implementada, o porque pidieron una funcionalidad que ya está
 * mergeada». Una marca nace:
 *  - sola, cuando la corrección de una misión de soporte queda verificada con commits de empresa o tienda
 *    ({@see self::from_mission()}, llamada desde `SupportCodexService::register_mission_fix_evidence()`);
 *  - por el panel (`POST api/admin/client-upgrade-marks`), lo que usa `misiones_cc.py publicar-marcas`.
 *
 * Este servicio es el ÚNICO que escribe `clients.requires_upgrade` ({@see self::sync_client_flag()}).
 */
class ClientUpgradeMarkService
{
    /** Largo máximo del título de una marca (columna string 255). */
    const TITLE_MAX = 255;

    /**
     * Alta idempotente por `external_key`: si ya existe una marca con esa clave, se devuelve sin pisarla.
     *
     * @param array<string, mixed> $data client_id, title, kind?, target?, source?, detail?, external_key?, commits?,
     *                                   merged_at?, support_ticket_id?, support_codex_mission_id?
     *
     * @return array{0: ClientUpgradeMark, 1: bool} La marca y si se creó ahora.
     */
    public function create_or_get(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $key = isset($data['external_key']) && $data['external_key'] !== '' ? (string) $data['external_key'] : null;
            if ($key !== null) {
                $existing = ClientUpgradeMark::where('external_key', $key)->lockForUpdate()->first();
                if ($existing !== null) {
                    return [$existing, false];
                }
            }
            $mark = ClientUpgradeMark::create([
                'client_id' => (int) $data['client_id'],
                'kind' => $data['kind'] ?? 'funcionalidad',
                'target' => $data['target'] ?? 'sistema',
                'source' => $data['source'] ?? 'manual',
                'title' => mb_substr(trim((string) $data['title']), 0, self::TITLE_MAX),
                'detail' => $data['detail'] ?? null,
                'external_key' => $key,
                'commits' => empty($data['commits']) ? null : $data['commits'],
                'merged_at' => $data['merged_at'] ?? now(),
                'support_ticket_id' => $data['support_ticket_id'] ?? null,
                'support_codex_mission_id' => $data['support_codex_mission_id'] ?? null,
            ]);
            $this->sync_client_flag((int) $mark->client_id);

            return [$mark, true];
        });
    }

    /**
     * Alta automática desde la corrección verificada de una misión de soporte.
     *
     * Sólo cuenta lo que se instala en el cliente: commits de `empresa-api`/`empresa-spa` (target `sistema`) o de
     * `tienda-api`/`tienda-spa` (target `tienda`). Una misión que sólo tocó el admin o el repo de conocimiento no
     * marca nada. Si trae las dos cosas, se crean dos marcas (`mission:<id>` y `mission:<id>:tienda`).
     *
     * 🔴 Nunca lanza: la evidencia de la misión es el trabajo, la marca es un extra (un error se registra y sigue).
     *
     * @param SupportCodexMission             $mission
     * @param SupportCodexMissionFixEvidence $evidence
     *
     * @return ClientUpgradeMark[] Las marcas creadas o ya existentes.
     */
    public function from_mission(SupportCodexMission $mission, SupportCodexMissionFixEvidence $evidence): array
    {
        try {
            $ticket = SupportTicket::find($mission->support_ticket_id);
            if ($ticket === null || ! $ticket->client_id) {
                return [];
            }
            $commits = is_array($evidence->payload['commits'] ?? null) ? $evidence->payload['commits'] : [];
            $marks = [];
            foreach (ClientUpgradeMark::REPOS_POR_TARGET as $target => $repos) {
                $own = array_intersect_key($commits, array_flip($repos));
                if ($own === []) {
                    continue;
                }
                ksort($own);
                // La de sistema conserva la clave del contrato; la de tienda sólo existe si la misión tocó las dos.
                $key = 'mission:' . $mission->id . ($target === 'tienda' && $marks !== [] ? ':tienda' : '');
                list($mark) = $this->create_or_get([
                    'client_id' => (int) $ticket->client_id,
                    'kind' => $mission->mission_type === 'funcionalidad' ? 'funcionalidad' : 'bug',
                    'target' => $target,
                    'source' => 'soporte',
                    'title' => $this->title_for($ticket, $mission),
                    'external_key' => $key,
                    'commits' => $own,
                    'merged_at' => $mission->completed_at ?: now(),
                    'support_ticket_id' => (int) $ticket->id,
                    'support_codex_mission_id' => (int) $mission->id,
                ]);
                $marks[] = $mark;
            }

            return $marks;
        } catch (\Throwable $error) {
            Log::warning('No se pudo crear la marca de actualización de la misión.', ['mission_id' => $mission->id, 'error' => $error->getMessage()]);

            return [];
        }
    }

    /**
     * Crea las marcas que faltan para las misiones con corrección verificada (`completed_at`) que todavía no se
     * instalaron (`installed_at` null). Idempotente: correrlo dos veces deja la misma cantidad de marcas.
     *
     * @return int Cuántas misiones se revisaron.
     */
    public function backfill(): int
    {
        $count = 0;
        SupportCodexMission::whereNotNull('completed_at')->whereNull('installed_at')->orderBy('id')
            ->chunkById(200, function ($missions) use (&$count) {
                foreach ($missions as $mission) {
                    $evidence = SupportCodexMissionFixEvidence::where('support_ticket_id', $mission->support_ticket_id)
                        ->where('mission_thread_id', $mission->mission_thread_id)
                        ->where('mission_hash', $mission->mission_hash)->first();
                    if ($evidence !== null) {
                        $this->from_mission($mission, $evidence);
                    }
                    ++$count;
                }
            });

        return $count;
    }

    /**
     * Resuelve una marca a mano (`manual` o `descartada`). Si ya estaba resuelta no cambia nada.
     *
     * @param ClientUpgradeMark $mark
     * @param string            $reason manual | descartada
     * @param string|null       $note   Hasta 300 caracteres.
     *
     * @return ClientUpgradeMark
     */
    public function resolve(ClientUpgradeMark $mark, string $reason, ?string $note = null): ClientUpgradeMark
    {
        if (! $mark->is_open()) {
            return $mark;
        }
        $mark->update(['resolved_at' => now(), 'resolved_reason' => $reason,
            'resolution_note' => $note === null ? null : mb_substr(trim($note), 0, 300)]);
        $this->sync_client_flag((int) $mark->client_id);

        return $mark->fresh();
    }

    /**
     * Deja `clients.requires_upgrade` en true si el cliente tiene alguna marca abierta, y en false si no.
     * Es un update directo (sin eventos del modelo `Client`): es una copia para listar, no un cambio del cliente.
     *
     * @param int $client_id
     *
     * @return void
     */
    public function sync_client_flag(int $client_id): void
    {
        $open = ClientUpgradeMark::where('client_id', $client_id)->whereNull('resolved_at')->exists();
        Client::where('id', $client_id)->update(['requires_upgrade' => $open]);
    }

    /**
     * Listado del panel agrupado por cliente (contrato C6).
     *
     * @param string   $estado    abiertas | resueltas | todas.
     * @param int|null $client_id Filtra un cliente.
     *
     * @return array{clients: array<int, array<string, mixed>>, totales: array<string, int>}
     */
    public function listing(string $estado = 'abiertas', ?int $client_id = null): array
    {
        $query = ClientUpgradeMark::query()->withAll()->orderBy('client_id')->orderBy('id');
        if ($estado === 'abiertas') {
            $query->whereNull('resolved_at');
        } elseif ($estado === 'resueltas') {
            $query->whereNotNull('resolved_at');
        }
        if ($client_id !== null) {
            $query->where('client_id', $client_id);
        }
        $marks = $query->get();
        $clients = Client::with('current_version')->whereIn('id', $marks->pluck('client_id')->unique()->all())->get()->keyBy('id');
        $latest = $this->latest_published_version();
        $totales = ['clientes' => 0, 'marcas' => 0, ClientUpgradeMark::ESTADO_LISTA => 0,
            ClientUpgradeMark::ESTADO_FALTA_RELEASE => 0, ClientUpgradeMark::ESTADO_TIENDA_MANUAL => 0];
        $rows = [];
        foreach ($marks->groupBy('client_id') as $id => $group) {
            $client = $clients->get($id);
            $items = [];
            $estados = [];
            foreach ($group as $mark) {
                $item = $mark->to_api_array();
                $items[] = $item;
                if ($item['estado'] !== ClientUpgradeMark::ESTADO_RESUELTA) {
                    $estados[] = $item['estado'];
                    ++$totales[$item['estado']];
                }
                ++$totales['marcas'];
            }
            ++$totales['clientes'];
            $lista = in_array(ClientUpgradeMark::ESTADO_LISTA, $estados, true);
            $rows[] = [
                'client_id' => (int) $id,
                'name' => $client === null ? null : $client->name,
                'company_name' => $client === null ? null : $client->company_name,
                'current_version' => $client === null || $client->current_version === null ? null : $client->current_version->version,
                'recomendacion' => $this->recommendation($estados),
                'version_objetivo' => $lista && $latest !== null ? $latest->version : null,
                'marks' => $items,
            ];
        }

        return ['clients' => $rows, 'totales' => $totales];
    }

    /**
     * Recomendación para el cliente según los estados de sus marcas abiertas.
     *
     * @param string[] $estados
     *
     * @return string|null actualizar | falta_release | tienda_manual | mixta, o null si no tiene marcas abiertas.
     */
    private function recommendation(array $estados): ?string
    {
        $unique = array_values(array_unique($estados));
        if ($unique === []) {
            return null;
        }
        if (count($unique) > 1) {
            return 'mixta';
        }

        return $unique[0] === ClientUpgradeMark::ESTADO_LISTA ? 'actualizar' : $unique[0];
    }

    /**
     * Última versión publicada según el comparador semántico (no por id: los hotfixes se intercalan).
     *
     * @return Version|null
     */
    public function latest_published_version(): ?Version
    {
        $latest = null;
        foreach (Version::where('status', 'published')->get() as $version) {
            if ($latest === null || VersionNumberComparator::compare($version->version, $latest->version) > 0) {
                $latest = $version;
            }
        }

        return $latest;
    }

    /**
     * Título de la marca: el título del ticket o, si no tiene, la primera línea del diagnóstico de la misión.
     *
     * @param SupportTicket       $ticket
     * @param SupportCodexMission $mission
     *
     * @return string
     */
    private function title_for(SupportTicket $ticket, SupportCodexMission $mission): string
    {
        $title = trim((string) $ticket->name);
        if ($title === '') {
            $lines = preg_split('/\R/u', trim((string) $mission->mission));
            $title = trim((string) ($lines[0] ?? ''));
        }
        if ($title === '') {
            $title = 'Corrección del ticket #' . $ticket->id;
        }

        return mb_substr($title, 0, self::TITLE_MAX);
    }
}

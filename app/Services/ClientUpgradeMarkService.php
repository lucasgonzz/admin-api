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
     * Vuelve a evaluar las marcas abiertas de todos los clientes (comando horario `client-upgrade-marks:refresh`).
     *
     * @return int Cuántos clientes se evaluaron.
     */
    public function refresh_all(): int
    {
        $clients = ClientUpgradeMark::whereNull('resolved_at')->distinct()->pluck('client_id')->all();
        foreach ($clients as $client_id) {
            $this->refresh_client((int) $client_id);
        }

        return count($clients);
    }

    /**
     * Evalúa las marcas abiertas de un cliente: calcula desde qué versión publicada se puede actualizar y resuelve
     * las que la versión instalada ya contiene. La llama el job que despacha el cierre de un upgrade (`terminada`)
     * y el comando horario. Al final sincroniza `clients.requires_upgrade`.
     *
     * @param int $client_id
     *
     * @return void
     */
    public function refresh_client(int $client_id): void
    {
        $client = Client::with('current_version')->find($client_id);
        if ($client === null) {
            return;
        }
        $published = $this->published_versions();
        foreach (ClientUpgradeMark::where('client_id', $client_id)->whereNull('resolved_at')->orderBy('id')->get() as $mark) {
            $this->refresh_mark($mark, $client, $published);
        }
        $this->sync_client_flag($client_id);
    }

    /**
     * Evalúa una marca abierta.
     *
     * Criterio medible (plan §2.5): la versión objetivo es la PRIMERA publicada (por comparador semántico) cuyo tag
     * contiene todos los commits de empresa de la marca; sólo se miran las publicadas desde un día antes del merge.
     * Sin commits, o con GitHub caído, se toma la primera publicada DESPUÉS del merge y queda `available_by: fecha`
     * (se vuelve a intentar por tag en cada refresh). Con la versión instalada del cliente ≥ objetivo, la marca se
     * resuelve como `instalada` y, si viene de una misión de soporte, la misión queda instalada sin acuerdo para que
     * Martín verifique la entrega. Las marcas de tienda no se resuelven solas en esta misión.
     *
     * @param ClientUpgradeMark $mark
     * @param Client            $client    Con `current_version` cargada.
     * @param Version[]         $published Versiones publicadas, de la más vieja a la más nueva.
     *
     * @return void
     */
    private function refresh_mark(ClientUpgradeMark $mark, Client $client, array $published): void
    {
        if ($mark->target === 'tienda') {
            return;
        }
        $latest = $published === [] ? null : $published[count($published) - 1];
        // Sin versiones nuevas desde la última mirada y sin objetivo todavía: no hay nada nuevo que preguntarle a GitHub.
        $sin_novedades = $mark->available_version_id === null && $latest !== null
            && (int) $mark->last_checked_version_id === (int) $latest->id;
        if (($mark->available_version_id === null || $mark->available_by === 'fecha') && ! $sin_novedades) {
            list($version, $by) = $this->available_version_for($mark, $published);
            $mark->available_version_id = $version === null ? null : $version->id;
            $mark->available_by = $by;
            $mark->last_checked_version_id = $latest === null ? null : $latest->id;
            $mark->last_checked_at = now();
            $mark->save();
            $mark->load('available_version');
        }
        $target = $mark->available_version;
        $current = $client->current_version;
        if ($target === null || $current === null || VersionNumberComparator::compare($current->version, $target->version) < 0) {
            return;
        }
        $mark->update(['resolved_at' => now(), 'resolved_reason' => 'instalada', 'resolved_version_id' => $current->id]);
        if ($mark->support_codex_mission_id !== null) {
            $mission = SupportCodexMission::find($mark->support_codex_mission_id);
            if ($mission !== null) {
                app(SupportMissionDeliveryService::class)->mark_installed($mission);
            }
        }
    }

    /**
     * Primera versión publicada que contiene la corrección de la marca, y cómo se supo.
     *
     * @param ClientUpgradeMark $mark
     * @param Version[]         $published De la más vieja a la más nueva.
     *
     * @return array{0: Version|null, 1: string|null} [versión, 'tag'|'fecha'] o [null, null].
     */
    private function available_version_for(ClientUpgradeMark $mark, array $published): array
    {
        $merged = $mark->merged_at ?: $mark->created_at;
        $commits = array_intersect_key($mark->commits ?: [], array_flip(ClientUpgradeMark::REPOS_POR_TARGET['sistema']));
        if ($commits !== []) {
            try {
                $github = app(ReleaseArtifactService::class);
                foreach ($published as $version) {
                    // Un día de margen: el merge y el release pueden registrarse con relojes distintos.
                    if ($merged !== null && $version->published_at->lt($merged->copy()->subDay())) {
                        continue;
                    }
                    $contains = true;
                    foreach ($commits as $repo => $sha) {
                        if (! $github->commit_in_tag($repo, $sha, $version->version)) {
                            $contains = false;
                            break;
                        }
                    }
                    if ($contains) {
                        return [$version, 'tag'];
                    }
                }

                return [null, null];
            } catch (\Throwable $error) {
                Log::warning('No se pudo comprobar en GitHub la versión de una marca de actualización; se usa la fecha.',
                    ['mark_id' => $mark->id, 'error' => $error->getMessage()]);
            }
        }
        foreach ($published as $version) {
            if ($merged === null || $version->published_at->gt($merged)) {
                return [$version, 'fecha'];
            }
        }

        return [null, null];
    }

    /**
     * Versiones publicadas (con fecha) ordenadas por el comparador semántico, de la más vieja a la más nueva.
     *
     * @return Version[]
     */
    private function published_versions(): array
    {
        $versions = Version::where('status', 'published')->whereNotNull('published_at')->get()->all();
        usort($versions, function (Version $a, Version $b) {
            return VersionNumberComparator::compare($a->version, $b->version);
        });

        return $versions;
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

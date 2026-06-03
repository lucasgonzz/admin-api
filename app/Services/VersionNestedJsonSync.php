<?php

namespace App\Services;

use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Models\VersionSeeder;
use Illuminate\Http\Request;

/**
 * Sincroniza notificaciones, seeders, comandos y tareas manuales desde el JSON del admin-spa
 * cuando se guarda la versión (un solo POST/PUT). Solo actúa si la clave correspondiente
 * viene presente en el request, para no borrar datos al omitir el array en el payload.
 */
class VersionNestedJsonSync
{
    /**
     * Ejecuta las sincronizaciones indicadas en el cuerpo JSON.
     *
     * @param Version $version Versión ya persistida (con id).
     * @param Request $request Request con posibles claves notifications, seeders, commands, manual_tasks.
     * @return void
     */
    public function sync_from_request(Version $version, Request $request): void
    {
        if ($request->has('notifications')) {
            $this->sync_notifications($version, $request->input('notifications', []));
        }
        if ($request->has('seeders')) {
            $this->sync_seeders($version, $request->input('seeders', []));
        }
        if ($request->has('commands')) {
            $this->sync_commands($version, $request->input('commands', []));
        }
        if ($request->has('manual_tasks')) {
            $this->sync_manual_tasks($version, $request->input('manual_tasks', []));
        }
    }

    /**
     * @param array<int, mixed> $items
     */
    protected function sync_notifications(Version $version, array $items): void
    {
        $items = array_values(array_filter($items, 'is_array'));
        $keep_ids = [];
        foreach ($items as $payload) {
            $id = $this->optional_int_id($payload['id'] ?? null);
            $client_ids = $this->normalize_client_ids($payload['client_ids'] ?? []);
            $row = [
                'title' => (string) ($payload['title'] ?? ''),
                'body' => (string) ($payload['body'] ?? ''),
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
                'is_active' => $this->bool_from_payload($payload['is_active'] ?? true),
            ];
            if ($id !== null) {
                $model = VersionNotification::query()->where('version_id', $version->id)->find($id);
                if ($model) {
                    $model->update($row);
                    $model->syncRestrictedClientIdsFromRequest($client_ids);
                    $keep_ids[] = $model->id;
                }
            } else {
                $model = VersionNotification::create(array_merge($row, [
                    'version_id' => $version->id,
                ]));
                $model->syncRestrictedClientIdsFromRequest($client_ids);
                $keep_ids[] = $model->id;
            }
        }
        $this->delete_version_children_not_in(VersionNotification::query(), $version->id, $keep_ids);
    }

    /**
     * @param array<int, mixed> $items
     */
    protected function sync_seeders(Version $version, array $items): void
    {
        $items = array_values(array_filter($items, 'is_array'));
        $keep_ids = [];
        foreach ($items as $payload) {
            $id = $this->optional_int_id($payload['id'] ?? null);
            $client_ids = $this->normalize_client_ids($payload['client_ids'] ?? []);
            $execution_order = (int) ($payload['execution_order'] ?? 0);
            if ($id === null && $execution_order === 0) {
                $execution_order = (int) (VersionSeeder::query()
                    ->where('version_id', $version->id)
                    ->max('execution_order') ?? -1) + 1;
            }
            $row = [
                'seeder_class' => (string) ($payload['seeder_class'] ?? ''),
                'description' => (string) ($payload['description'] ?? ''),
                'execution_order' => $execution_order,
                'is_required' => $this->bool_from_payload($payload['is_required'] ?? true),
                /* Default per_database: seeders maestros; per_user solo si genera filas con user_id */
                'run_scope' => $this->normalize_run_scope($payload['run_scope'] ?? null, 'per_database'),
            ];
            if ($id !== null) {
                $model = VersionSeeder::query()->where('version_id', $version->id)->find($id);
                if ($model) {
                    $model->update($row);
                    $model->syncRestrictedClientIdsFromRequest($client_ids);
                    $keep_ids[] = $model->id;
                }
            } else {
                $model = VersionSeeder::create(array_merge($row, [
                    'version_id' => $version->id,
                ]));
                $model->syncRestrictedClientIdsFromRequest($client_ids);
                $keep_ids[] = $model->id;
            }
        }
        $this->delete_version_children_not_in(VersionSeeder::query(), $version->id, $keep_ids);
    }

    /**
     * @param array<int, mixed> $items
     */
    protected function sync_commands(Version $version, array $items): void
    {
        $items = array_values(array_filter($items, 'is_array'));
        $keep_ids = [];
        foreach ($items as $payload) {
            $id = $this->optional_int_id($payload['id'] ?? null);
            $client_ids = $this->normalize_client_ids($payload['client_ids'] ?? []);
            $execution_order = (int) ($payload['execution_order'] ?? 0);
            if ($id === null && $execution_order === 0) {
                $execution_order = (int) (VersionCommand::query()
                    ->where('version_id', $version->id)
                    ->max('execution_order') ?? -1) + 1;
            }
            $row = [
                'command' => (string) ($payload['command'] ?? ''),
                'description' => (string) ($payload['description'] ?? ''),
                'execution_order' => $execution_order,
                'is_required' => $this->bool_from_payload($payload['is_required'] ?? true),
                /* Si es true, el deployment SSH lo omite y queda pendiente para ejecución manual */
                'run_manually' => $this->bool_from_payload($payload['run_manually'] ?? false),
                /* Default per_user: comandos por tenant; per_database solo en excepciones maestras */
                'run_scope' => $this->normalize_run_scope($payload['run_scope'] ?? null, 'per_user'),
            ];
            if ($id !== null) {
                $model = VersionCommand::query()->where('version_id', $version->id)->find($id);
                if ($model) {
                    $model->update($row);
                    $model->syncRestrictedClientIdsFromRequest($client_ids);
                    $keep_ids[] = $model->id;
                }
            } else {
                $model = VersionCommand::create(array_merge($row, [
                    'version_id' => $version->id,
                ]));
                $model->syncRestrictedClientIdsFromRequest($client_ids);
                $keep_ids[] = $model->id;
            }
        }
        $this->delete_version_children_not_in(VersionCommand::query(), $version->id, $keep_ids);
    }

    /**
     * @param array<int, mixed> $items
     */
    protected function sync_manual_tasks(Version $version, array $items): void
    {
        $items = array_values(array_filter($items, 'is_array'));
        $keep_ids = [];
        foreach ($items as $payload) {
            $id = $this->optional_int_id($payload['id'] ?? null);
            $client_ids = $this->normalize_client_ids($payload['client_ids'] ?? []);
            $row = [
                'title' => (string) ($payload['title'] ?? ''),
                'description' => (string) ($payload['description'] ?? ''),
                'execution_order' => (int) ($payload['execution_order'] ?? 0),
                'is_required' => $this->bool_from_payload($payload['is_required'] ?? true),
            ];
            if ($id !== null) {
                $model = VersionManualTask::query()->where('version_id', $version->id)->find($id);
                if ($model) {
                    $model->update($row);
                    $model->syncRestrictedClientIdsFromRequest($client_ids);
                    $keep_ids[] = $model->id;
                }
            } else {
                $model = VersionManualTask::create(array_merge($row, [
                    'version_id' => $version->id,
                ]));
                $model->syncRestrictedClientIdsFromRequest($client_ids);
                $keep_ids[] = $model->id;
            }
        }
        $this->delete_version_children_not_in(VersionManualTask::query(), $version->id, $keep_ids);
    }

    /**
     * Elimina filas hijas de la versión que no están en $keep_ids (si $keep_ids está vacío, borra todas).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query del modelo hijo ya filtrable por version_id.
     * @param int $version_id id de la versión.
     * @param array<int, int> $keep_ids ids a conservar.
     * @return void
     */
    protected function delete_version_children_not_in($query, int $version_id, array $keep_ids): void
    {
        $q = $query->where('version_id', $version_id);
        if ($keep_ids !== []) {
            $q->whereNotIn('id', $keep_ids);
        }
        $q->delete();
    }

    /**
     * @param mixed $id
     * @return int|null
     */
    protected function optional_int_id($id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        $n = (int) $id;

        return $n > 0 ? $n : null;
    }

    /**
     * @param mixed $raw
     * @return array<int, int>
     */
    protected function normalize_client_ids($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $raw)));
    }

    /**
     * @param mixed $value
     */
    protected function bool_from_payload($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Normaliza run_scope del payload JSON del admin-spa.
     *
     * @param mixed $value Valor recibido (per_database | per_user).
     * @param string $default Default si viene vacío o inválido.
     * @return string
     */
    protected function normalize_run_scope($value, string $default): string
    {
        $allowed = ['per_database', 'per_user'];
        $normalized = is_string($value) ? trim($value) : '';

        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        return in_array($default, $allowed, true) ? $default : 'per_database';
    }
}

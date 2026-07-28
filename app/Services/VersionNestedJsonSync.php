<?php

namespace App\Services;

use App\Models\Version;
use App\Models\VersionCommand;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Models\VersionSeeder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        /*
         * Momento (opcional) en que el editor de admin-spa cargó los datos de la versión.
         * Lo manda el SPA como `loaded_at` (ISO-8601, UTC). Sirve para el guard de carrera:
         * un guardado del editor solo tiene autoridad para borrar ítems automáticos que YA
         * existían cuando se abrió el modal, no los que se cargaron después mientras estaba
         * abierto. Si viene ausente, vacío o no parsea, queda en null y el comportamiento es
         * exactamente el actual (compatibilidad con la ficha Blade, que no manda este campo).
         */
        $loaded_at = $this->parse_loaded_at($request->input('loaded_at'));

        if ($request->has('notifications')) {
            $this->sync_notifications($version, $request->input('notifications', []), $loaded_at);
        }
        if ($request->has('seeders')) {
            $this->sync_seeders($version, $request->input('seeders', []), $loaded_at);
        }
        if ($request->has('commands')) {
            $this->sync_commands($version, $request->input('commands', []), $loaded_at);
        }
        if ($request->has('manual_tasks')) {
            $this->sync_manual_tasks($version, $request->input('manual_tasks', []), $loaded_at);
        }
    }

    /**
     * Parsea `loaded_at` de forma defensiva y lo normaliza a la zona horaria de la app.
     *
     * El SPA manda el timestamp con `toISOString()` (siempre UTC, sufijo `Z`), pero
     * `config('app.timezone')` es `America/Argentina/Buenos_Aires` (UTC-3) y los `created_at`
     * de Eloquent se guardan en esa zona. Si compararamos el `loaded_at` crudo (UTC) contra
     * `created_at` (UTC-3) sin normalizar, cualquier ítem cargado dentro de las 3 horas
     * siguientes a abrir el editor quedaría con `created_at > loaded_at` en false y el guard
     * no protegería nada, en silencio. `setTimezone()` convierte el instante (no lo
     * reinterpreta), así que funciona igual si el string viene con `Z`, con offset explícito
     * o sin offset.
     *
     * @param mixed $raw valor crudo del request (string ISO-8601 o null/vacío/inválido).
     * @return Carbon|null null si viene ausente, vacío o no parsea (no cambia el comportamiento actual).
     */
    protected function parse_loaded_at($raw): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw)->setTimezone(config('app.timezone'));
        } catch (\Throwable $exception) {
            /* Valor no parseable: se trata como si no hubiera venido, sin romper el guardado. */
            return null;
        }
    }

    /**
     * @param array<int, mixed> $items
     * @param Carbon|null $loaded_at momento en que el editor cargó los datos (null = sin guard, comportamiento actual).
     */
    protected function sync_notifications(Version $version, array $items, ?Carbon $loaded_at = null): void
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
        $this->delete_version_children_not_in(VersionNotification::query(), $version->id, $keep_ids, $loaded_at);
    }

    /**
     * @param array<int, mixed> $items
     * @param Carbon|null $loaded_at momento en que el editor cargó los datos (null = sin guard, comportamiento actual).
     */
    protected function sync_seeders(Version $version, array $items, ?Carbon $loaded_at = null): void
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
        $this->delete_version_children_not_in(VersionSeeder::query(), $version->id, $keep_ids, $loaded_at);
    }

    /**
     * @param array<int, mixed> $items
     * @param Carbon|null $loaded_at momento en que el editor cargó los datos (null = sin guard, comportamiento actual).
     */
    protected function sync_commands(Version $version, array $items, ?Carbon $loaded_at = null): void
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
        $this->delete_version_children_not_in(VersionCommand::query(), $version->id, $keep_ids, $loaded_at);
    }

    /**
     * @param array<int, mixed> $items
     * @param Carbon|null $loaded_at momento en que el editor cargó los datos (null = sin guard, comportamiento actual).
     */
    protected function sync_manual_tasks(Version $version, array $items, ?Carbon $loaded_at = null): void
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
        $this->delete_version_children_not_in(VersionManualTask::query(), $version->id, $keep_ids, $loaded_at);
    }

    /**
     * Elimina filas hijas de la versión que no están en $keep_ids (si $keep_ids está vacío, borra todas).
     *
     * Guard de carrera (grupo 255, prompt 03): si se recibió `$loaded_at`, se excluyen del
     * borrado las filas que cumplan **las dos** condiciones a la vez:
     *   - `source_group_id` no es null (la cargó un proceso automático), y
     *   - `created_at` es posterior a `$loaded_at` (apareció después de que el editor abrió el modal).
     * Una fila cargada a mano (`source_group_id` null) sigue siendo borrable como siempre, y una
     * fila automática que ya existía cuando se abrió el editor también: si Lucas la sacó de la
     * lista y guardó, la sacó a propósito.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query Query del modelo hijo ya filtrable por version_id.
     * @param int $version_id id de la versión.
     * @param array<int, int> $keep_ids ids a conservar.
     * @param Carbon|null $loaded_at momento en que el editor cargó los datos; null desactiva el guard.
     * @return void
     */
    protected function delete_version_children_not_in($query, int $version_id, array $keep_ids, ?Carbon $loaded_at = null): void
    {
        $q = $query->where('version_id', $version_id);
        if ($keep_ids !== []) {
            $q->whereNotIn('id', $keep_ids);
        }

        if ($loaded_at !== null) {
            /* Filas que el borrado alcanzaría pero el guard protege: automáticas y creadas
               después de que el editor cargó los datos (posible carrera con el loop). */
            $protected_rows = (clone $q)
                ->whereNotNull('source_group_id')
                ->where('created_at', '>', $loaded_at)
                ->get(['id', 'source_group_id']);

            if ($protected_rows->isNotEmpty()) {
                $q->where(function ($sub) use ($loaded_at) {
                    $sub->whereNull('source_group_id')
                        ->orWhere('created_at', '<=', $loaded_at);
                });

                /* Única forma de detectar después que el guard se está usando de verdad. */
                Log::channel('daily')->info('VersionNestedJsonSync: guard de carrera preservó ítems automáticos', [
                    'version_id' => $version_id,
                    'model' => get_class($q->getModel()),
                    'preserved_ids' => $protected_rows->pluck('id')->all(),
                    'source_group_ids' => $protected_rows->pluck('source_group_id')->unique()->values()->all(),
                    'loaded_at' => $loaded_at->toIso8601String(),
                ]);
            }
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

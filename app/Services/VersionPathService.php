<?php

namespace App\Services;

use App\Models\Version;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use Illuminate\Support\Collection;

/**
 * Resuelve el conjunto de versiones entre el origen y el destino de un upgrade (excluye origen, incluye destino)
 * y expone agregados para listados y publicación, ordenando siempre por id de versión.
 */
class VersionPathService
{
    /**
     * Factor de escala para mezclar notificaciones de varias versiones en un solo sort_order global.
     * Debe ser mayor que el max sort_order en una sola versión.
     */
    const NOTIFICATION_SORT_ORDER_MULTIPLIER = 1000;

    /**
     * Instancia de Version solo con atributos escalares, sin relaciones hijas cargadas.
     * Evita ciclo infinito al serializar (notification.version.notifications → version → …).
     *
     * @param  Version  $version
     * @return Version
     */
    protected static function versionWithoutChildRelations(Version $version): Version
    {
        $light = $version->newFromBuilder($version->getAttributes());
        $light->exists = true;

        return $light;
    }

    /**
     * Versiones del rango (from, to], con relaciones opcionales eager-loaded.
     *
     * @return Collection<int, Version>
     */
    public static function versionsInRange(?int $fromVersionId, int $toVersionId, array $with = []): Collection
    {
        $q = Version::query()->orderBy('id');
        if (!empty($with)) {
            $q->with($with);
        }
        if ($fromVersionId === null) {
            $q->whereKey($toVersionId);
        } else {
            $q->where('id', '>', $fromVersionId)
                ->where('id', '<=', $toVersionId);
        }

        return $q->get();
    }

    /**
     * Rango con seeders y comandos (opcional: solo los que aplican al client_id, según restricción por cliente).
     *
     * @return Collection<int, Version>
     */
    public static function versionsInRangeWithSeedersAndCommands(?int $fromVersionId, int $toVersionId, ?int $forClientId = null): Collection
    {
        if ($forClientId === null) {
            return static::versionsInRange($fromVersionId, $toVersionId, ['seeders', 'commands']);
        }

        return static::versionsInRange($fromVersionId, $toVersionId, [
            'seeders' => function ($q) use ($forClientId) {
                $q->forClientId($forClientId)->orderBy('execution_order');
            },
            'commands' => function ($q) use ($forClientId) {
                $q->forClientId($forClientId)->orderBy('execution_order');
            },
        ]);
    }

    /**
     * Notificaciones del rango; si $forClientId, excluye ítems restringidos a otros clientes.
     *
     * @return Collection<int, VersionNotification>
     */
    public static function aggregatedNotifications(?int $fromVersionId, int $toVersionId, ?int $forClientId = null): Collection
    {
        if ($forClientId === null) {
            $with = ['notifications'];
        } else {
            $with = [
                'notifications' => function ($q) use ($forClientId) {
                    $q->forClientId($forClientId)->orderBy('sort_order');
                },
            ];
        }
        $col = collect();
        foreach (static::versionsInRange($fromVersionId, $toVersionId, $with) as $version) {
            $version_for_item = static::versionWithoutChildRelations($version);
            foreach ($version->notifications as $n) {
                $n->setRelation('version', $version_for_item);
                $col->push($n);
            }
        }

        return $col;
    }

    /**
     * Tareas manuales agregadas en orden de versión; con $forClientId aplica el filtro de restricción.
     *
     * @return Collection<int, VersionManualTask>
     */
    public static function aggregatedManualTasks(?int $fromVersionId, int $toVersionId, ?int $forClientId = null): Collection
    {
        if ($forClientId === null) {
            $with = ['manual_tasks'];
        } else {
            $with = [
                'manual_tasks' => function ($q) use ($forClientId) {
                    $q->forClientId($forClientId)->orderBy('execution_order');
                },
            ];
        }
        $col = collect();
        foreach (static::versionsInRange($fromVersionId, $toVersionId, $with) as $version) {
            $version_for_item = static::versionWithoutChildRelations($version);
            foreach ($version->manual_tasks as $task) {
                $task->setRelation('version', $version_for_item);
                $col->push($task);
            }
        }

        return $col;
    }

    public static function globalNotificationSortOrder(int $versionId, int $localSortOrder): int
    {
        return (int) ($versionId * self::NOTIFICATION_SORT_ORDER_MULTIPLIER + $localSortOrder);
    }
}

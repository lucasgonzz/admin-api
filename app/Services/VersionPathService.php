<?php

namespace App\Services;

use App\Models\Version;
use App\Models\VersionManualTask;
use App\Models\VersionNotification;
use App\Services\VersionNumberComparator;
use Illuminate\Support\Collection;

/**
 * Resuelve agregados (seeders, comandos, notificaciones, tareas manuales) sobre un
 * conjunto de versiones YA CONFIRMADO por el admin, y ordena ese conjunto por orden
 * semántico del código de versión (`VersionNumberComparator`), no por `id` de tabla.
 *
 * 🔴 Antes este service calculaba el rango él mismo (`versionsInRange()`, por `id`).
 * Eso se borró: el rango se calcula una sola vez con `candidatesBetween()`, el admin
 * confirma un subconjunto en el paso de preview, y de ahí en más todo el mundo (el
 * detalle del upgrade, la publicación) lee ese subconjunto ya persistido en la pivot
 * `client_version_upgrade_versions` — nunca se vuelve a recalcular el rango para decidir
 * qué mostrar.
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
     * Ordena una colección de Version por tupla semántica ascendente (no por `id`).
     *
     * @param  Collection<int, Version>  $versions
     * @return Collection<int, Version>
     */
    public static function sortSemantically(Collection $versions): Collection
    {
        return $versions->sort(function (Version $a, Version $b) {
            return VersionNumberComparator::compare($a->version, $b->version);
        })->values();
    }

    /**
     * Candidatas del rango (from, to] entre versiones PUBLICADAS, en orden semántico.
     *
     * Si `$from` es `null`, el resultado es solo la versión destino — así se preserva
     * la semántica actual de la vieja `versionsInRange()` para clientes sin
     * `current_version_id` (no "todas las publicadas menores o iguales a destino").
     *
     * @param  Version|null  $from  Versión actual del cliente (puede no tener).
     * @param  Version       $to    Versión destino del upgrade.
     * @return Collection<int, Version>
     */
    public static function candidatesBetween(?Version $from, Version $to): Collection
    {
        if ($from === null) {
            return collect([$to]);
        }

        $publicadas = Version::where('status', 'published')->get();

        $candidatas = $publicadas->filter(function (Version $version) use ($from, $to) {
            return VersionNumberComparator::compare($version->version, $from->version) > 0
                && VersionNumberComparator::compare($version->version, $to->version) <= 0;
        });

        return static::sortSemantically($candidatas);
    }

    /**
     * Recarga un conjunto de versiones (por `id`) con las relaciones indicadas, en
     * orden semántico. Punto único de recarga: lo usan `withSeedersAndCommands()`,
     * `aggregatedNotifications()` y `aggregatedManualTasks()`.
     *
     * @param  Collection<int, Version>  $versions  Conjunto ya confirmado (solo se usa su `id`).
     * @param  array                     $with      Relaciones (o closures de relación) a eager-load.
     * @return Collection<int, Version>
     */
    protected static function loadVersions(Collection $versions, array $with): Collection
    {
        $ids = $versions->pluck('id')->all();

        $cargadas = Version::whereIn('id', $ids)->with($with)->get();

        return static::sortSemantically($cargadas);
    }

    /**
     * Recarga un conjunto YA CONFIRMADO de versiones con seeders y comandos (opcional:
     * solo los que aplican al client_id, según restricción por cliente). Reemplaza a
     * `versionsInRangeWithSeedersAndCommands()`.
     *
     * @param  Collection<int, Version>  $versions
     * @return Collection<int, Version>
     */
    public static function withSeedersAndCommands(Collection $versions, ?int $forClientId = null): Collection
    {
        if ($forClientId === null) {
            return static::loadVersions($versions, ['seeders', 'commands']);
        }

        return static::loadVersions($versions, [
            'seeders' => function ($q) use ($forClientId) {
                $q->forClientId($forClientId)->orderBy('execution_order');
            },
            'commands' => function ($q) use ($forClientId) {
                $q->forClientId($forClientId)->orderBy('execution_order');
            },
        ]);
    }

    /**
     * Notificaciones de un conjunto YA CONFIRMADO de versiones, en orden semántico; si
     * $forClientId, excluye ítems restringidos a otros clientes.
     *
     * @param  Collection<int, Version>  $versions
     * @return Collection<int, VersionNotification>
     */
    public static function aggregatedNotifications(Collection $versions, ?int $forClientId = null): Collection
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
        foreach (static::loadVersions($versions, $with) as $version) {
            $version_for_item = static::versionWithoutChildRelations($version);
            foreach ($version->notifications as $n) {
                $n->setRelation('version', $version_for_item);
                $col->push($n);
            }
        }

        return $col;
    }

    /**
     * Tareas manuales de un conjunto YA CONFIRMADO de versiones, en orden semántico;
     * con $forClientId aplica el filtro de restricción.
     *
     * @param  Collection<int, Version>  $versions
     * @return Collection<int, VersionManualTask>
     */
    public static function aggregatedManualTasks(Collection $versions, ?int $forClientId = null): Collection
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
        foreach (static::loadVersions($versions, $with) as $version) {
            $version_for_item = static::versionWithoutChildRelations($version);
            foreach ($version->manual_tasks as $task) {
                $task->setRelation('version', $version_for_item);
                $col->push($task);
            }
        }

        return $col;
    }

    /**
     * sort_order global "histórico": multiplica por el `id` de la versión. Es la fórmula
     * EN USO (`PublishVersionService::buildPayload()`): como los `id` de versión nunca se
     * repiten, el valor es monótono y único a lo largo del tiempo para un mismo cliente,
     * así que dos actualizaciones sucesivas nunca emiten sort_order solapados.
     *
     * Dentro de un solo upgrade puede no coincidir exactamente con el orden semántico (un
     * hotfix cargado después de una minor posterior tiene `id` más alto), pero eso es
     * mucho más tolerable que romper el orden ENTRE actualizaciones ya entregadas.
     */
    public static function globalNotificationSortOrder(int $versionId, int $localSortOrder): int
    {
        return (int) ($versionId * self::NOTIFICATION_SORT_ORDER_MULTIPLIER + $localSortOrder);
    }

    /**
     * sort_order global basado en la POSICIÓN del ítem dentro del conjunto confirmado
     * ya ordenado semánticamente (1-based), no en el `id` de tabla.
     *
     * 🔴 NO SE ACTIVÓ, y queda acá definida sin uso a propósito. La posición se reinicia
     * en 1 en cada `ClientVersionUpgrade`, así que actualizaciones sucesivas del mismo
     * cliente emitirían sort_order solapados entre sí, pisando el orden de las
     * notificaciones que ya viajaron a clientes en actualizaciones anteriores. Eso rompe
     * el contrato hacia empresa-api de forma no compatible hacia atrás, y no se puede
     * confirmar desde este repo. Pendiente de hablarlo con Lucas si algún día se quiere
     * usar; hasta entonces la fórmula viva es `globalNotificationSortOrder()`.
     */
    public static function positionalNotificationSortOrder(int $position, int $localSortOrder): int
    {
        return (int) ($position * self::NOTIFICATION_SORT_ORDER_MULTIPLIER + $localSortOrder);
    }
}

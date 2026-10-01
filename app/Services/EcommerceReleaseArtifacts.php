<?php

namespace App\Services;

/**
 * El CONTRATO de nombres entre el admin y los releases de `tienda-spa` / `tienda-api` (misión
 * cruzada `versiones-tienda`, 1/10/2026, sección 1.1 del plan), en un solo lugar.
 *
 * | Repo          | Tag     | Asset                         |
 * |---------------|---------|-------------------------------|
 * | `tienda-spa`  | `v{V}`  | `tienda-spa-v{V}-dist.zip`    |
 * | `tienda-api`  | `v{V}`  | `tienda-api-v{V}.zip`         |
 *
 * 🔴 Lo usan DOS caminos que tienen que decir exactamente lo mismo: el alta de una versión
 * (`POST claude/ecommerce/versions` y el panel, que verifican que los dos assets existan antes de
 * publicar) y el pipeline (`ArtefactosDeReleaseDeTienda`, que los baja). Si cada uno armara el
 * nombre por su cuenta, el alta podría verificar un archivo y el deploy buscar otro.
 *
 * Los repos salen de `services.deploy_tienda.release_repo_spa` / `release_repo_api` (sin owner: el
 * owner es el de `services.github.releases_owner`, el mismo que usa empresa).
 */
class EcommerceReleaseArtifacts
{
    /** Repo de la SPA si la config viene vacía. */
    const REPO_SPA_POR_DEFECTO = 'tienda-spa';

    /** Repo de la API si la config viene vacía. */
    const REPO_API_POR_DEFECTO = 'tienda-api';

    /**
     * Cliente de GitHub. Se inyecta para que los tests puedan usar `Http::fake()` sin tocar nada.
     *
     * @var ReleaseArtifactService
     */
    private $releases;

    /**
     * @param  ReleaseArtifactService|null  $releases  Cliente de releases; si no viene, uno nuevo.
     */
    public function __construct(ReleaseArtifactService $releases = null)
    {
        $this->releases = $releases !== null ? $releases : new ReleaseArtifactService();
    }

    /**
     * Tag del release de una versión: `v{version}`.
     *
     * @param  string  $version  Código ("1.0.0").
     * @return string
     */
    public static function tag(string $version): string
    {
        return 'v' . trim($version);
    }

    /**
     * Repo de la SPA en GitHub (sin owner).
     *
     * @return string
     */
    public static function spa_repo(): string
    {
        $repo = trim((string) config('services.deploy_tienda.release_repo_spa', self::REPO_SPA_POR_DEFECTO));

        return $repo !== '' ? $repo : self::REPO_SPA_POR_DEFECTO;
    }

    /**
     * Repo de la API en GitHub (sin owner).
     *
     * @return string
     */
    public static function api_repo(): string
    {
        $repo = trim((string) config('services.deploy_tienda.release_repo_api', self::REPO_API_POR_DEFECTO));

        return $repo !== '' ? $repo : self::REPO_API_POR_DEFECTO;
    }

    /**
     * Nombre del asset del dist de la SPA: `tienda-spa-v{version}-dist.zip`.
     *
     * 🔴 El prefijo es el nombre del CONTRATO (`tienda-spa`), no el del repo configurado: si mañana
     * el repo se renombra por config, el workflow sigue publicando el asset con este nombre.
     *
     * @param  string  $version  Código ("1.0.0").
     * @return string
     */
    public static function spa_asset_name(string $version): string
    {
        return 'tienda-spa-' . self::tag($version) . '-dist.zip';
    }

    /**
     * Nombre del asset de la API: `tienda-api-v{version}.zip`.
     *
     * @param  string  $version  Código ("1.0.0").
     * @return string
     */
    public static function api_asset_name(string $version): string
    {
        return 'tienda-api-' . self::tag($version) . '.zip';
    }

    /**
     * Los dos artefactos esperados de una versión, con repo, tag y nombre de cada uno.
     *
     * @param  string  $version  Código ("1.0.0").
     * @return array<string, array{repo: string, tag: string, asset: string}>  Claves `spa` y `api`.
     */
    public static function expected(string $version): array
    {
        return [
            'spa' => [
                'repo'  => self::spa_repo(),
                'tag'   => self::tag($version),
                'asset' => self::spa_asset_name($version),
            ],
            'api' => [
                'repo'  => self::api_repo(),
                'tag'   => self::tag($version),
                'asset' => self::api_asset_name($version),
            ],
        ];
    }

    /**
     * Busca en GitHub los dos artefactos de una versión.
     *
     * @param  string  $version  Código ("1.0.0").
     * @return array<string, array<string, mixed>>  Claves `spa` y `api`; cada una con `repo`, `tag`,
     *                                              `asset` y `found` (lo que devolvió
     *                                              `ReleaseArtifactService::find_asset()`, o null).
     *
     * @throws \RuntimeException  Si GitHub falla con algo distinto de "no hay release / no hay
     *                            asset" (401, 403, 500, sin red). No se disfraza de "falta el
     *                            artefacto": un token vencido no es una versión mal publicada.
     */
    public function find(string $version): array
    {
        $resultado = [];

        foreach (self::expected($version) as $clave => $esperado) {
            $esperado['found'] = $this->releases->find_asset($esperado['repo'], $esperado['tag'], $esperado['asset']);
            $resultado[$clave] = $esperado;
        }

        return $resultado;
    }

    /**
     * Los artefactos que FALTAN de una versión (vacío = están los dos).
     *
     * @param  string  $version  Código ("1.0.0").
     * @return array<int, array{repo: string, tag: string, asset: string}>
     *
     * @throws \RuntimeException  Ver `find()`.
     */
    public function missing(string $version): array
    {
        $faltantes = [];

        foreach ($this->find($version) as $artefacto) {
            if ($artefacto['found'] === null) {
                $faltantes[] = [
                    'repo'  => $artefacto['repo'],
                    'tag'   => $artefacto['tag'],
                    'asset' => $artefacto['asset'],
                ];
            }
        }

        return $faltantes;
    }

    /**
     * Texto legible de una lista de faltantes: "tienda-spa-v1.0.0-dist.zip (tag v1.0.0 de tienda-spa)".
     *
     * @param  array<int, array{repo: string, tag: string, asset: string}>  $faltantes
     * @return string
     */
    public static function describe_missing(array $faltantes): string
    {
        $partes = [];
        foreach ($faltantes as $faltante) {
            $partes[] = $faltante['asset'] . ' (tag ' . $faltante['tag'] . ' de ' . $faltante['repo'] . ')';
        }

        return implode('; ', $partes);
    }
}

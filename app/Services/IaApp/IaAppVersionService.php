<?php

namespace App\Services\IaApp;

use App\Services\ReleaseArtifactService;

/**
 * La regla de las versiones de la app `ia-spa` (misión cruzada `app-ia-y-memoria`, 29/10/2026, plan §4 A.5):
 * **antes de publicar, el release tiene que tener el asset**. Molde: `EcommerceVersionService` +
 * `EcommerceReleaseArtifacts`, con un solo artefacto:
 *
 * | Repo     | Tag    | Asset                    |
 * |----------|--------|--------------------------|
 * | `ia-spa` | `v{V}` | `ia-spa-v{V}-dist.zip`   |
 *
 * El owner es el de `services.github.releases_owner` (el mismo de empresa y tienda); el repo,
 * `ia_app.versiones.release_repo` (default `ia-spa`).
 */
class IaAppVersionService
{
    /** @var ReleaseArtifactService */
    private $releases;

    /**
     * @param ReleaseArtifactService|null $releases Se inyecta en los tests.
     */
    public function __construct(?ReleaseArtifactService $releases = null)
    {
        $this->releases = $releases !== null ? $releases : new ReleaseArtifactService();
    }

    /**
     * @param string $version
     *
     * @return array{repo: string, tag: string, asset: string}
     */
    public static function esperado($version)
    {
        $repo = trim((string) config('ia_app.versiones.release_repo', 'ia-spa'));

        return [
            'repo'  => $repo !== '' ? $repo : 'ia-spa',
            'tag'   => 'v' . trim((string) $version),
            // 🔴 El prefijo es el del contrato (`ia-spa`), no el del repo configurado.
            'asset' => 'ia-spa-v' . trim((string) $version) . '-dist.zip',
        ];
    }

    /**
     * Verifica el asset ANTES de publicar.
     *
     * @param string $version
     *
     * @return array<string, mixed>|null Null si está. Si no: `{status: 422|502, error, faltantes}`.
     */
    public function verificar_antes_de_publicar($version)
    {
        $esperado = self::esperado($version);

        try {
            $encontrado = $this->releases->find_asset($esperado['repo'], $esperado['tag'], $esperado['asset']);
        } catch (\RuntimeException $e) {
            // Token vencido, GitHub caído, sin red: no es "falta el asset", y tampoco se publica a ciegas.
            return [
                'status'    => 502,
                'code'      => 'github_no_responde',
                'error'     => 'No se pudo verificar en GitHub el release de la versión ' . $version . ' ('
                    . mb_substr($e->getMessage(), 0, 300) . '). No se publicó nada: volvé a intentar, o mandá '
                    . 'verify_artifacts=false si ya verificaste el asset a mano.',
                'faltantes' => null,
            ];
        }

        if ($encontrado !== null) {
            return null;
        }

        return [
            'status'    => 422,
            'code'      => 'artefacto_faltante',
            'error'     => 'Falta el artefacto del release de la versión ' . $version . ': ' . $esperado['asset']
                . ' (tag ' . $esperado['tag'] . ' de ' . $esperado['repo'] . '). No se publicó nada: revisá que el '
                . 'workflow de GitHub Actions haya terminado verde y que el código sea el del commit [release:X.Y.Z].',
            'faltantes' => [$esperado],
        ];
    }
}

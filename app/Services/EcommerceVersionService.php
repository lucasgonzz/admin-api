<?php

namespace App\Services;

use App\Models\EcommerceVersion;

/**
 * Las reglas de las versiones de ecommerce que comparten los dos caminos que las tocan: los
 * endpoints `claude/*` y el panel del admin (misión cruzada `versiones-tienda`, 1/10/2026).
 *
 * Son dos reglas y nada más, y viven acá para que no haya dos definiciones de cada una:
 *
 *  1. **Antes de publicar, el release tiene que tener los dos assets.** El código de la versión del
 *     admin y el del commit `[release:X.Y.Z]` no se cruzan solos: escritos distinto, la versión
 *     queda publicada apuntando a un release que no existe, y la primera actualización falla recién
 *     adentro del job. Verificarlo al publicar adelanta ese error a la persona que lo puede arreglar.
 *  2. **Qué versión despliega una corrida nueva:** la pedida (por id o por código, y si vienen las
 *     dos tienen que coincidir), que tiene que estar publicada; o, si no se pidió ninguna, la última
 *     publicada por orden semántico.
 */
class EcommerceVersionService
{
    /**
     * @var EcommerceReleaseArtifacts
     */
    private $artifacts;

    /**
     * @param  EcommerceReleaseArtifacts|null  $artifacts  Se inyecta en los tests; si no, uno nuevo.
     */
    public function __construct(EcommerceReleaseArtifacts $artifacts = null)
    {
        $this->artifacts = $artifacts !== null ? $artifacts : new EcommerceReleaseArtifacts();
    }

    /**
     * Verifica que el release de una versión tenga los dos assets, ANTES de publicarla.
     *
     * @param  string  $version  Código ("1.0.0").
     * @return array<string, mixed>|null  Null si están los dos. Si no, el rechazo listo para devolver:
     *                                    `status` (422 si falta un asset, 502 si GitHub falló de otra
     *                                    manera), `error` (el mensaje) y `faltantes` (repo/tag/asset de
     *                                    cada uno, o null si no se pudo saber).
     */
    public function check_before_publishing(string $version): ?array
    {
        try {
            $faltantes = $this->artifacts->missing($version);
        } catch (\RuntimeException $e) {
            // Token vencido, GitHub caído, sin red: NO es "falta el asset", y tampoco se publica a
            // ciegas. Se dice tal cual qué contestó GitHub.
            return [
                'status'    => 502,
                'error'     => 'No se pudo verificar en GitHub el release de la versión ' . $version . ' ('
                    . $e->getMessage() . '). No se publicó nada: volvé a intentar, o mandá '
                    . 'verify_artifacts=false si ya verificaste los dos assets a mano.',
                'faltantes' => null,
            ];
        }

        if (empty($faltantes)) {
            return null;
        }

        return [
            'status'    => 422,
            'error'     => 'Faltan artefactos del release de la versión ' . $version . ': '
                . EcommerceReleaseArtifacts::describe_missing($faltantes) . '. No se publicó nada: revisá que '
                . 'el workflow de GitHub Actions haya terminado verde en los dos repos y que el código de la '
                . 'versión sea el mismo que el del commit [release:X.Y.Z].',
            'faltantes' => $faltantes,
        ];
    }

    /**
     * Resuelve la versión que va a desplegar una corrida nueva.
     *
     * @param  mixed  $ecommerce_version_id  Id pedido, o null.
     * @param  mixed  $codigo                Código pedido ("1.0.0"), o null.
     * @return array{version: EcommerceVersion|null, explicita: bool, error: string|null}
     *         `version` null sin `error` = no se pidió ninguna y no hay ninguna publicada (la corrida
     *         va a fallar al arrancar, salvo `DEPLOY_PERMITIR_BUILD_EN_VPS=true`).
     */
    public function resolve_for_run($ecommerce_version_id, $codigo): array
    {
        $id     = ($ecommerce_version_id === null || $ecommerce_version_id === '') ? null : (int) $ecommerce_version_id;
        $codigo = ($codigo === null) ? '' : trim((string) $codigo);

        if ($id === null && $codigo === '') {
            return [
                'version'   => EcommerceVersion::latest_published(),
                'explicita' => false,
                'error'     => null,
            ];
        }

        $por_id = null;
        if ($id !== null) {
            $por_id = EcommerceVersion::find($id);
            if ($por_id === null) {
                return $this->rechazo('No existe la versión de ecommerce #' . $id . '.');
            }
        }

        $por_codigo = null;
        if ($codigo !== '') {
            $por_codigo = EcommerceVersion::where('version', $codigo)->first();
            if ($por_codigo === null) {
                return $this->rechazo('No existe la versión de ecommerce «' . $codigo . '».');
            }
        }

        if ($por_id !== null && $por_codigo !== null && (int) $por_id->id !== (int) $por_codigo->id) {
            return $this->rechazo(
                'ecommerce_version_id (#' . $por_id->id . ', versión ' . $por_id->version . ') y version ('
                . $codigo . ') no son la misma versión. Mandá uno solo, o los dos de la misma.'
            );
        }

        $version = $por_id !== null ? $por_id : $por_codigo;

        if (! $version->is_published()) {
            return $this->rechazo(
                'La versión de ecommerce ' . $version->version . ' está en estado «' . $version->status
                . '»: sólo se despliega una versión publicada.'
            );
        }

        return [
            'version'   => $version,
            'explicita' => true,
            'error'     => null,
        ];
    }

    /**
     * Forma del rechazo de `resolve_for_run()`.
     *
     * @param  string  $mensaje
     * @return array{version: null, explicita: bool, error: string}
     */
    private function rechazo(string $mensaje): array
    {
        return [
            'version'   => null,
            'explicita' => true,
            'error'     => $mensaje,
        ];
    }

    /**
     * Aviso para las respuestas que crean una corrida sin versión: no hay ninguna publicada.
     *
     * @return string
     */
    public static function nota_sin_version_publicada(): string
    {
        return '🔴 No hay ninguna versión de ecommerce publicada: la corrida se creó igual, pero va a fallar al '
            . 'arrancar sin tocar ningún servidor (salvo DEPLOY_PERMITIR_BUILD_EN_VPS=true en el admin, que '
            . 'compila la última de master en el VPS de builds). Publicá la versión con POST '
            . 'claude/ecommerce/versions y volvé a pedir la actualización.';
    }
}

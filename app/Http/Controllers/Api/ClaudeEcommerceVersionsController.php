<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\EcommerceVersion;
use App\Services\EcommerceReleaseArtifacts;
use App\Services\EcommerceVersionService;
use App\Services\VersionNumberComparator;
use Illuminate\Http\Request;

/**
 * Versiones de ecommerce desde `claude/*`: listado, alta y edición (misión cruzada
 * `versiones-tienda`, 1/10/2026).
 *
 * Es el hermano de `ClaudeClientOpsController::versions_*_json` (las versiones de empresa), con UNA
 * diferencia que es el motivo de que exista aparte: una versión de ecommerce es el puntero a dos
 * artefactos de GitHub Actions (`tienda-spa-v{V}-dist.zip` y `tienda-api-v{V}.zip` en el release del
 * tag `v{V}`), y el código de la versión del admin con el del commit `[release:X.Y.Z]` no se cruzan
 * solos. Por eso **publicar verifica que estén los dos assets** (`verify_artifacts`, default true):
 * si falta uno, 422 nombrando repo, tag y asset y NO se escribe nada; si GitHub falla de otra
 * manera, 502 y tampoco.
 *
 * 🔴 La verificación corre cada vez que una versión QUEDA publicada —en el alta con
 * `status=published` (el default) y en el PATCH que la pasa a published—, no en un borrador: un
 * borrador se puede cargar antes de que termine el workflow. La invariante que importa es
 * "publicada ⇒ los dos assets existen", y vale por los dos caminos.
 *
 * Sin `dry_run` ni `confirm_*`: es una fila del admin y no toca ningún servidor. Lo que sí toca
 * servidores es desplegarla (`POST claude/ecommerce/updates`), que tiene sus propios frenos.
 */
class ClaudeEcommerceVersionsController extends Controller
{
    /*
     * Los helpers del bloque `claude/*`. Los mensajes de validación del bloque no traen `unique` ni
     * `regex` (contestarían en inglés), así que se suman acá sin tocar el trait compartido.
     */
    use RespuestasParaClaude {
        mensajes_de_validacion as mensajes_de_validacion_del_bloque;
    }

    /** Valores de `status` que acepta el filtro del listado (`all` = todas). */
    const FILTROS_DE_STATUS = ['draft', 'published', 'archived', 'all'];

    /* ==============================================================================================
     | GET claude/ecommerce/versions
     |============================================================================================= */

    /**
     * Versiones de ecommerce, de la más nueva a la más vieja por orden SEMÁNTICO del código.
     *
     * `status` filtra (default `published`, que son las únicas que se despliegan); `all` las trae
     * todas. Suma `ultima_publicada`: la que usa una actualización que no pide versión.
     *
     * @param  Request  $request  Query: status?.
     * @return \Illuminate\Http\JsonResponse  {data: [...], count, ultima_publicada, status_usado}
     */
    public function versions_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'status' => 'nullable|string|in:' . implode(',', self::FILTROS_DE_STATUS),
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $status = $this->texto_con_default($request, 'status', EcommerceVersion::STATUS_PUBLISHED);

        $query = EcommerceVersion::query();
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $data = [];
        foreach (EcommerceVersion::sort_semantically($query->get()) as $version) {
            $data[] = $this->payload($version);
        }

        $ultima = EcommerceVersion::latest_published();

        return response()->json([
            'data'             => $data,
            'count'            => count($data),
            'status_usado'     => $status,
            'ultima_publicada' => $ultima === null ? null : $ultima->compact_payload(),
            'nota'             => 'Solo se despliega una versión published. POST claude/ecommerce/updates sin versión usa '
                . 'ultima_publicada (orden semántico del código, no por id).',
        ], 200);
    }

    /* ==============================================================================================
     | POST claude/ecommerce/versions
     |============================================================================================= */

    /**
     * Da de alta una versión de ecommerce.
     *
     * Orden: validación (código con el regex de `VersionNumberComparator` y único) → si queda
     * `published` y `verify_artifacts` no es false, los dos assets del release → alta. Ningún
     * rechazo escribe nada.
     *
     * @param  Request  $request  Body: version, title?, description?, status? (default published),
     *                            verify_artifacts? (default true).
     * @return \Illuminate\Http\JsonResponse  201 {model, artefactos_verificados}, 422 o 502.
     */
    public function versions_store_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'version'          => [
                'required',
                'string',
                'max:30',
                'regex:' . VersionNumberComparator::VALID_REGEX,
                'unique:ecommerce_versions,version',
            ],
            'title'            => 'nullable|string|max:200',
            'description'      => 'nullable|string|max:5000',
            'status'           => 'nullable|string|in:' . implode(',', EcommerceVersion::STATUSES),
            'verify_artifacts' => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $codigo    = trim((string) $request->input('version'));
        $status    = $this->texto_con_default($request, 'status', EcommerceVersion::STATUS_PUBLISHED);
        $verificar = $this->booleano_o_null($request, 'verify_artifacts');
        if ($verificar === null) {
            $verificar = true;
        }

        $verificados = false;
        if ($status === EcommerceVersion::STATUS_PUBLISHED && $verificar) {
            $rechazo = (new EcommerceVersionService())->check_before_publishing($codigo);
            if ($rechazo !== null) {
                return $this->respuesta_de_rechazo($rechazo, $codigo);
            }
            $verificados = true;
        }

        $version = EcommerceVersion::create([
            'version'      => $codigo,
            'title'        => $this->texto_o_null($request->input('title')),
            'description'  => $this->texto_o_null($request->input('description')),
            'status'       => $status,
            'published_at' => $status === EcommerceVersion::STATUS_PUBLISHED ? now() : null,
        ]);

        return response()->json([
            'model'                  => $this->payload($version),
            'artefactos_verificados' => $verificados,
            'artefactos'             => EcommerceReleaseArtifacts::expected($codigo),
            'nota'                   => $verificados
                ? 'Publicada: los dos assets del release están en GitHub. POST claude/ecommerce/updates ya la despliega.'
                : ($status === EcommerceVersion::STATUS_PUBLISHED
                    ? '⚠️ Publicada SIN verificar los assets (verify_artifacts=false): si falta alguno, la primera '
                        . 'actualización falla al arrancar, sin tocar ningún servidor.'
                    : 'Cargada en «' . $status . '»: no se despliega hasta pasarla a published (y ahí se verifican los assets).'),
        ], 201);
    }

    /* ==============================================================================================
     | PATCH claude/ecommerce/versions/{id}
     |============================================================================================= */

    /**
     * Edita título, descripción y/o estado de una versión.
     *
     * 🔴 El CÓDIGO no se edita: es lo que arma el tag y el nombre de los assets, y las tiendas y las
     * corridas apuntan a la fila. Mandarlo es un 422 explícito y no un parámetro ignorado en silencio
     * (el que lo manda creería haberlo cambiado). Si está mal, se archiva y se carga la correcta.
     *
     * Al pasar a `published` sin fecha previa se estampa `published_at`; y si NO estaba publicada,
     * se verifican los assets antes (salvo `verify_artifacts=false`), igual que en el alta.
     *
     * @param  Request     $request  Body: title?, description?, status?, verify_artifacts?.
     * @param  int|string  $id       Id de la versión.
     * @return \Illuminate\Http\JsonResponse  200 {model}, 404, 422 o 502.
     */
    public function versions_update_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'title'            => 'sometimes|nullable|string|max:200',
            'description'      => 'sometimes|nullable|string|max:5000',
            'status'           => 'sometimes|required|string|in:' . implode(',', EcommerceVersion::STATUSES),
            'verify_artifacts' => 'sometimes|nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        // Primero la fila: un PATCH sobre un id que no existe es 404, no "no mandaste campos".
        $valor   = trim((string) $id);
        $version = ($valor !== '' && ctype_digit($valor)) ? EcommerceVersion::find((int) $valor) : null;
        if ($version === null) {
            return $this->error_404('no existe la versión de ecommerce ' . $id);
        }

        if ($request->has('version')) {
            return $this->error_422(
                'El código de una versión de ecommerce no se edita: es el que arma el tag y el nombre de los '
                    . 'assets del release, y las tiendas y corridas apuntan a esta fila. No se cambió nada.',
                ['ayuda' => 'Si el código está mal, pasala a archived y cargá la correcta con POST claude/ecommerce/versions.']
            );
        }

        $campos = array_values(array_intersect(array_keys($request->all()), ['title', 'description', 'status']));
        if (empty($campos)) {
            return $this->error_422(
                'No mandaste nada para editar: los campos editables son title, description y status. No se cambió nada.'
            );
        }

        if ($request->has('title')) {
            $version->title = $this->texto_o_null($request->input('title'));
        }
        if ($request->has('description')) {
            $version->description = $this->texto_o_null($request->input('description'));
        }

        $verificados = false;
        if ($request->has('status')) {
            $nuevo       = (string) $request->input('status');
            $se_publica  = $nuevo === EcommerceVersion::STATUS_PUBLISHED && ! $version->is_published();
            $verificar   = $this->booleano_o_null($request, 'verify_artifacts');
            if ($verificar === null) {
                $verificar = true;
            }

            if ($se_publica && $verificar) {
                $rechazo = (new EcommerceVersionService())->check_before_publishing((string) $version->version);
                if ($rechazo !== null) {
                    return $this->respuesta_de_rechazo($rechazo, (string) $version->version);
                }
                $verificados = true;
            }

            $version->status = $nuevo;
            if ($nuevo === EcommerceVersion::STATUS_PUBLISHED && ! $version->published_at) {
                $version->published_at = now();
            }
        }

        $version->save();

        return response()->json([
            'model'                  => $this->payload($version->fresh()),
            'campos_editados'        => $campos,
            'artefactos_verificados' => $verificados,
        ], 200);
    }

    /* ==============================================================================================
     | Helpers
     |============================================================================================= */

    /**
     * Proyección de una versión para las respuestas: `{id, version, title, description, status,
     * published_at, created_at}`, con las fechas en ISO 8601 (mismo formato que las versiones de
     * empresa en `ClaudeClientOpsController::version_payload()`).
     *
     * @param  EcommerceVersion  $version
     * @return array<string, mixed>
     */
    private function payload(EcommerceVersion $version): array
    {
        return [
            'id'           => (int) $version->id,
            'version'      => (string) $version->version,
            'title'        => $version->title,
            'description'  => $version->description,
            'status'       => (string) $version->status,
            'published_at' => optional($version->published_at)->toIso8601String(),
            'created_at'   => optional($version->created_at)->toIso8601String(),
        ];
    }

    /**
     * Respuesta de un rechazo de `EcommerceVersionService::check_before_publishing()`.
     *
     * @param  array<string, mixed>  $rechazo
     * @param  string                $codigo
     * @return \Illuminate\Http\JsonResponse
     */
    private function respuesta_de_rechazo(array $rechazo, string $codigo)
    {
        return response()->json([
            'error'      => $rechazo['error'],
            'faltantes'  => $rechazo['faltantes'],
            'esperados'  => EcommerceReleaseArtifacts::expected($codigo),
            'ayuda'      => 'El release de cada repo se crea con un commit vacío "release: version X.Y.Z [release:X.Y.Z]" '
                . 'en master de tienda-spa Y de tienda-api; GitHub Actions compila, crea el tag vX.Y.Z y sube el asset.',
        ], (int) $rechazo['status']);
    }

    /**
     * Mensajes de validación del bloque, más `unique` y `regex`, que el trait no trae y sin los que
     * esos dos errores saldrían en inglés.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return array_merge($this->mensajes_de_validacion_del_bloque(), [
            'unique' => 'Ya existe una versión de ecommerce con ese :attribute.',
            'regex'  => 'El parámetro :attribute no tiene el formato de una versión (al menos tres números separados por puntos: 1.0.0).',
        ]);
    }
}

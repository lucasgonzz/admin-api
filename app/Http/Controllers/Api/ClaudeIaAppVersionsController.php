<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\IaAppVersion;
use App\Services\IaApp\IaAppVersionService;
use App\Services\VersionNumberComparator;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Versiones de la app `ia-spa` desde `claude/*` (misión cruzada `app-ia-y-memoria`, 29/10/2026, plan §4 A.5):
 * `GET/POST api/claude/ia/versions` y `PATCH api/claude/ia/versions/{id}`. Molde:
 * `ClaudeEcommerceVersionsController`.
 *
 * 🔴 La invariante es «publicada ⇒ el asset `ia-spa-v{V}-dist.zip` existe en el release `v{V}`»: se
 * verifica en el alta con `status=published` (el default) y en el PATCH que la pasa a published. Si falta,
 * 422 y no se escribe nada; si GitHub falla de otra manera, 502 y tampoco.
 *
 * `deployed_at` lo estampa `ia-release.py desplegar` cuando la versión quedó servida en el VPS. Solo una
 * versión publicada se puede marcar desplegada.
 *
 * Errores con la forma del bloque (`{error}`) más `code`.
 */
class ClaudeIaAppVersionsController extends Controller
{
    use RespuestasParaClaude {
        mensajes_de_validacion as mensajes_de_validacion_del_bloque;
    }

    /** Valores del filtro `status` del listado. */
    const FILTROS_DE_STATUS = ['draft', 'published', 'all'];

    /**
     * `GET api/claude/ia/versions?status=all|published|draft` (default `all`).
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'status' => 'nullable|string|in:' . implode(',', self::FILTROS_DE_STATUS),
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $status = $this->texto_con_default($request, 'status', 'all');
        $query  = IaAppVersion::query();

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $data = [];
        foreach (IaAppVersion::ordenar_semanticamente($query->get()) as $version) {
            $data[] = $this->payload($version);
        }

        $publicadas = IaAppVersion::ordenar_semanticamente(IaAppVersion::where('status', IaAppVersion::STATUS_PUBLISHED)->get());
        $ultima     = $publicadas->first();
        $desplegada = IaAppVersion::whereNotNull('deployed_at')->orderByDesc('deployed_at')->orderByDesc('id')->first();

        return response()->json([
            'data'              => $data,
            'count'             => count($data),
            'status_usado'      => $status,
            'ultima_publicada'  => $ultima === null ? null : $this->payload($ultima),
            'ultima_desplegada' => $desplegada === null ? null : $this->payload($desplegada),
        ]);
    }

    /**
     * `POST api/claude/ia/versions` `{version, title?, description?, status? (default published),
     * verify_artifacts? (default true)}`.
     *
     * @param Request             $request
     * @param IaAppVersionService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, IaAppVersionService $servicio)
    {
        $invalido = $this->validar_o_422($request, [
            'version'          => ['required', 'string', 'max:30', 'regex:' . VersionNumberComparator::VALID_REGEX, 'unique:ia_app_versions,version'],
            'title'            => 'nullable|string|max:200',
            'description'      => 'nullable|string|max:5000',
            'status'           => 'nullable|string|in:' . implode(',', IaAppVersion::STATUSES),
            'verify_artifacts' => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $codigo    = trim((string) $request->input('version'));
        $status    = $this->texto_con_default($request, 'status', IaAppVersion::STATUS_PUBLISHED);
        $verificar = $this->booleano_o_null($request, 'verify_artifacts');
        $verificar = $verificar === null ? true : $verificar;

        $verificado = false;
        if ($status === IaAppVersion::STATUS_PUBLISHED && $verificar) {
            $rechazo = $servicio->verificar_antes_de_publicar($codigo);
            if ($rechazo !== null) {
                return $this->rechazo($rechazo, $codigo);
            }
            $verificado = true;
        }

        $version = IaAppVersion::create([
            'version'      => $codigo,
            'title'        => $this->texto_o_null($request->input('title')),
            'description'  => $this->texto_o_null($request->input('description')),
            'status'       => $status,
            'published_at' => $status === IaAppVersion::STATUS_PUBLISHED ? now() : null,
        ]);

        return response()->json([
            'model'                  => $this->payload($version),
            'artefactos_verificados' => $verificado,
            'artefacto'              => IaAppVersionService::esperado($codigo),
        ], 201);
    }

    /**
     * `PATCH api/claude/ia/versions/{id}` `{title?, description?, status?, deployed_at?, verify_artifacts?}`.
     *
     * @param Request             $request
     * @param int|string          $id
     * @param IaAppVersionService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id, IaAppVersionService $servicio)
    {
        $invalido = $this->validar_o_422($request, [
            'title'            => 'sometimes|nullable|string|max:200',
            'description'      => 'sometimes|nullable|string|max:5000',
            'status'           => 'sometimes|required|string|in:' . implode(',', IaAppVersion::STATUSES),
            'deployed_at'      => 'sometimes|nullable|date',
            'verify_artifacts' => 'sometimes|nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $valor   = trim((string) $id);
        $version = ($valor !== '' && ctype_digit($valor)) ? IaAppVersion::find((int) $valor) : null;
        if ($version === null) {
            return response()->json(['code' => 'version_inexistente', 'error' => 'No existe la versión de la app ' . $id . '.'], 404);
        }

        if ($request->has('version')) {
            return response()->json([
                'code'  => 'version_no_editable',
                'error' => 'El código de una versión no se edita: arma el tag y el nombre del asset. No se cambió nada.',
            ], 422);
        }

        $campos = array_values(array_intersect(array_keys($request->all()), ['title', 'description', 'status', 'deployed_at']));
        if (empty($campos)) {
            return response()->json([
                'code'  => 'sin_campos',
                'error' => 'No mandaste nada para editar: title, description, status o deployed_at. No se cambió nada.',
            ], 422);
        }

        if ($request->has('title')) {
            $version->title = $this->texto_o_null($request->input('title'));
        }
        if ($request->has('description')) {
            $version->description = $this->texto_o_null($request->input('description'));
        }

        $verificado = false;
        if ($request->has('status')) {
            $nuevo      = (string) $request->input('status');
            $se_publica = $nuevo === IaAppVersion::STATUS_PUBLISHED && ! $version->is_published();
            $verificar  = $this->booleano_o_null($request, 'verify_artifacts');
            $verificar  = $verificar === null ? true : $verificar;

            if ($se_publica && $verificar) {
                $rechazo = $servicio->verificar_antes_de_publicar((string) $version->version);
                if ($rechazo !== null) {
                    return $this->rechazo($rechazo, (string) $version->version);
                }
                $verificado = true;
            }

            $version->status = $nuevo;
            if ($nuevo === IaAppVersion::STATUS_PUBLISHED && ! $version->published_at) {
                $version->published_at = now();
            }
        }

        if ($request->has('deployed_at')) {
            $fecha = $this->texto_o_null($request->input('deployed_at'));

            if ($fecha !== null && ! $version->is_published()) {
                return response()->json([
                    'code'  => 'no_publicada',
                    'error' => 'Solo una versión publicada se puede marcar desplegada. No se cambió nada.',
                ], 422);
            }

            $version->deployed_at = $fecha === null ? null : Carbon::parse($fecha);
        }

        $version->save();

        return response()->json([
            'model'                  => $this->payload($version->fresh()),
            'campos_editados'        => $campos,
            'artefactos_verificados' => $verificado,
        ]);
    }

    /**
     * @param IaAppVersion $version
     *
     * @return array<string, mixed>
     */
    private function payload(IaAppVersion $version)
    {
        return [
            'id'           => (int) $version->id,
            'version'      => (string) $version->version,
            'title'        => $version->title,
            'description'  => $version->description,
            'status'       => (string) $version->status,
            'published_at' => optional($version->published_at)->toIso8601String(),
            'deployed_at'  => optional($version->deployed_at)->toIso8601String(),
            'created_at'   => optional($version->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $rechazo
     * @param string               $codigo
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function rechazo(array $rechazo, $codigo)
    {
        return response()->json([
            'code'      => $rechazo['code'],
            'error'     => $rechazo['error'],
            'faltantes' => $rechazo['faltantes'],
            'esperado'  => IaAppVersionService::esperado($codigo),
            'ayuda'     => 'El release se crea con un commit vacío "release: version X.Y.Z [release:X.Y.Z]" en master de '
                . 'ia-spa; GitHub Actions compila, crea el tag vX.Y.Z y sube el asset.',
        ], (int) $rechazo['status']);
    }

    /**
     * Los mensajes del bloque, más `unique`, `regex` y `date`.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return array_merge($this->mensajes_de_validacion_del_bloque(), [
            'unique' => 'Ya existe una versión de la app con ese :attribute.',
            'regex'  => 'El parámetro :attribute no tiene el formato de una versión (al menos tres números separados por puntos: 1.0.0).',
        ]);
    }
}

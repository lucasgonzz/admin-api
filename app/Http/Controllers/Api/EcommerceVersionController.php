<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\CommonLaravel\BaseController;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\EcommerceVersion;
use App\Services\EcommerceVersionService;
use App\Services\VersionNumberComparator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * CRUD del panel (`api/admin/ecommerce-versions`, sanctum) de las versiones de ecommerce, para el
 * módulo "Versiones de ecommerce" de admin-spa (misión cruzada `versiones-tienda`, 1/10/2026).
 *
 * Mismas reglas que `claude/ecommerce/versions` —y por eso salen del mismo
 * `EcommerceVersionService`—: el código lleva el formato de `VersionNumberComparator` y es único, no
 * se edita después del alta, y publicar verifica que el release tenga los dos assets (salvo
 * `verify_artifacts=false`). Además, el panel puede BORRAR una versión, cosa que Claude no: sólo si
 * ninguna tienda la tiene instalada y ninguna corrida la referencia (la columna no tiene FK, así que
 * esta guarda es la única integridad que hay).
 *
 * Responde con la forma del resto del panel: `{models}` / `{model}` y `{error}` en los 422, que el
 * interceptor de axios de admin-spa muestra como toast.
 */
class EcommerceVersionController extends BaseController
{
    /**
     * Listado completo, de la más nueva a la más vieja por orden SEMÁNTICO. Con `?status=` filtra
     * (el selector de los modales de instalar/actualizar pide `published`). Cada fila suma cuántas
     * tiendas la tienen instalada y cuántas corridas la referencian, que es lo que decide si se
     * puede borrar.
     *
     * @param  Request  $request  Query: status?.
     * @return JsonResponse  {models: EcommerceVersion[], ultima_publicada: {id, version}|null}
     */
    public function index_json(Request $request): JsonResponse
    {
        $query = EcommerceVersion::query()->withCount(['client_ecommerces', 'installations']);

        $status = trim((string) $request->query('status', ''));
        if (in_array($status, EcommerceVersion::STATUSES, true)) {
            $query->where('status', $status);
        }

        $ultima = EcommerceVersion::latest_published();

        return response()->json([
            'models'           => EcommerceVersion::sort_semantically($query->get()),
            'ultima_publicada' => $ultima === null ? null : $ultima->compact_payload(),
        ]);
    }

    /**
     * Una versión.
     *
     * @param  int|string  $id
     * @return JsonResponse  {model} o 404.
     */
    public function show_json($id): JsonResponse
    {
        $version = $this->buscar($id);
        if ($version === null) {
            return response()->json(['error' => 'No existe la versión de ecommerce.'], 404);
        }

        return response()->json(['model' => $version]);
    }

    /**
     * Alta. Si queda publicada (el default), verifica los dos assets del release antes de escribir.
     *
     * @param  Request  $request  Body: version, title?, description?, status?, verify_artifacts?.
     * @return JsonResponse  201 {model}, 422 o 502 {error}.
     */
    public function store_json(Request $request): JsonResponse
    {
        $invalido = $this->validar($request, [
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

        $codigo = trim((string) $request->input('version'));
        $status = trim((string) $request->input('status', ''));
        if ($status === '') {
            $status = EcommerceVersion::STATUS_PUBLISHED;
        }

        if ($status === EcommerceVersion::STATUS_PUBLISHED && $this->verificar($request)) {
            $rechazo = (new EcommerceVersionService())->check_before_publishing($codigo);
            if ($rechazo !== null) {
                return response()->json(['error' => $rechazo['error'], 'faltantes' => $rechazo['faltantes']], $rechazo['status']);
            }
        }

        $version = EcommerceVersion::create([
            'version'      => $codigo,
            'title'        => $this->texto_o_null($request->input('title')),
            'description'  => $this->texto_o_null($request->input('description')),
            'status'       => $status,
            'published_at' => $status === EcommerceVersion::STATUS_PUBLISHED ? now() : null,
        ]);

        return response()->json(['model' => $this->fullModel('ecommerce_version', $version->id)], 201);
    }

    /**
     * Edición de título, descripción y estado.
     *
     * 🔴 El modal genérico del panel manda el borrador ENTERO, incluido `version`: si viene igual al
     * guardado se ignora; si viene distinto es 422 (el código no se edita: arma el tag y el nombre de
     * los assets). `published_at` y `id` se ignoran siempre.
     *
     * @param  Request     $request
     * @param  int|string  $id
     * @return JsonResponse  {model}, 404, 422 o 502.
     */
    public function update_json(Request $request, $id): JsonResponse
    {
        $version = $this->buscar($id);
        if ($version === null) {
            return response()->json(['error' => 'No existe la versión de ecommerce.'], 404);
        }

        $invalido = $this->validar($request, [
            'title'            => 'sometimes|nullable|string|max:200',
            'description'      => 'sometimes|nullable|string|max:5000',
            'status'           => 'sometimes|required|string|in:' . implode(',', EcommerceVersion::STATUSES),
            'verify_artifacts' => 'sometimes|nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        if ($request->has('version') && trim((string) $request->input('version')) !== (string) $version->version) {
            return response()->json([
                'error' => 'El código de una versión de ecommerce no se edita: arma el tag y el nombre de los assets '
                    . 'del release. Si está mal, archivala y cargá la correcta.',
            ], 422);
        }

        if ($request->has('title')) {
            $version->title = $this->texto_o_null($request->input('title'));
        }
        if ($request->has('description')) {
            $version->description = $this->texto_o_null($request->input('description'));
        }

        if ($request->has('status')) {
            $nuevo = (string) $request->input('status');

            if ($nuevo === EcommerceVersion::STATUS_PUBLISHED && ! $version->is_published() && $this->verificar($request)) {
                $rechazo = (new EcommerceVersionService())->check_before_publishing((string) $version->version);
                if ($rechazo !== null) {
                    return response()->json(['error' => $rechazo['error'], 'faltantes' => $rechazo['faltantes']], $rechazo['status']);
                }
            }

            $version->status = $nuevo;
            if ($nuevo === EcommerceVersion::STATUS_PUBLISHED && ! $version->published_at) {
                $version->published_at = now();
            }
        }

        $version->save();

        return response()->json(['model' => $this->fullModel('ecommerce_version', $version->id)]);
    }

    /**
     * Borra una versión, SÓLO si nadie la usa: ninguna tienda la tiene instalada y ninguna corrida
     * la referencia. Si no, 422 diciendo cuántas y que se archive en vez de borrarla.
     *
     * 🔴 Es la única integridad de `ecommerce_version_id`, que no tiene FK en ninguna de las dos
     * tablas: borrar una versión en uso dejaría tiendas apuntando a una fila que no existe.
     *
     * @param  int|string  $id
     * @return JsonResponse  {deleted: true}, 404 o 422.
     */
    public function destroy_json($id): JsonResponse
    {
        $version = $this->buscar($id);
        if ($version === null) {
            return response()->json(['error' => 'No existe la versión de ecommerce.'], 404);
        }

        $tiendas  = ClientEcommerce::where('ecommerce_version_id', $version->id)->count();
        $corridas = ClientEcommerceInstallation::where('ecommerce_version_id', $version->id)->count();

        if ($tiendas > 0 || $corridas > 0) {
            return response()->json([
                'error' => 'La versión ' . $version->version . ' está en uso (' . $tiendas . ' tienda(s) la tienen '
                    . 'instalada y ' . $corridas . ' corrida(s) la referencian): no se borra. Archivala para que no se '
                    . 'despliegue más.',
            ], 422);
        }

        $version->delete();

        return response()->json(['deleted' => true]);
    }

    /* ------------------------------------------------------------------------------------------ */

    /**
     * Busca una versión por id numérico.
     *
     * @param  int|string  $id
     * @return EcommerceVersion|null
     */
    private function buscar($id): ?EcommerceVersion
    {
        $valor = trim((string) $id);
        if ($valor === '' || ! ctype_digit($valor)) {
            return null;
        }

        return EcommerceVersion::find((int) $valor);
    }

    /**
     * Valida con mensajes en español (el proyecto sólo trae traducciones en inglés) y devuelve el
     * 422 con la forma `{error, errors}` que entiende el interceptor de admin-spa.
     *
     * @param  Request               $request
     * @param  array<string, mixed>  $reglas
     * @return JsonResponse|null  Null si validó.
     */
    private function validar(Request $request, array $reglas): ?JsonResponse
    {
        try {
            $request->validate($reglas, [
                'required' => 'El campo :attribute es obligatorio.',
                'string'   => 'El campo :attribute tiene que ser texto.',
                'max'      => 'El campo :attribute es demasiado largo.',
                'in'       => 'El campo :attribute tiene un valor que no está permitido.',
                'boolean'  => 'El campo :attribute tiene que ser verdadero o falso.',
                'unique'   => 'Ya existe una versión de ecommerce con ese código.',
                'regex'    => 'El código de versión debe tener al menos 3 componentes numéricos separados por puntos (ej. 1.0.0).',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'error'  => 'Datos inválidos.',
                'errors' => $e->errors(),
            ], 422);
        }

        return null;
    }

    /**
     * `verify_artifacts` del request: true salvo que venga explícitamente en false.
     *
     * @param  Request  $request
     * @return bool
     */
    private function verificar(Request $request): bool
    {
        $valor = $request->input('verify_artifacts');
        if ($valor === null || $valor === '') {
            return true;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Texto recortado, o null si quedó vacío.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    private function texto_o_null($valor): ?string
    {
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}

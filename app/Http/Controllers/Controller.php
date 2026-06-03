<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * Sincroniza el pivote de clientes restringidos a partir de client_ids[] (vacío = todos).
     */
    protected function syncRestrictedClientsFromRequest(Model $model, Request $request): void
    {
        if (method_exists($model, 'syncRestrictedClientIdsFromRequest')) {
            $model->syncRestrictedClientIdsFromRequest($request->input('client_ids', []));
        }
    }

    /**
     * Carga un registro por id con withAll() si existe el scope.
     *
     * @param string $model_name Nombre corto (p. ej. "version", "client", "update")
     * @param int|string $id
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    protected function fullModel($model_name, $id)
    {
        $class = GeneralHelper::getModelName($model_name);
        $q = $class::query()->where('id', $id);
        if (method_exists($class, 'scopeWithAll')) {
            $q = $q->withAll();
        }

        return $q->first();
    }

    /**
     * Normaliza run_scope de seeders/comandos de versión (per_database | per_user).
     *
     * @param mixed $value Valor recibido desde formulario o request.
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

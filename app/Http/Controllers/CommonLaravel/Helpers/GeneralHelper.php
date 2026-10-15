<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;

/**
 * Resolución de nombres cortos de modelo (SPA/rutas) a clases Eloquent.
 * Basado en el patrón de empresa-api, con mapeo explícito para alias del admin.
 */
class GeneralHelper
{
    /**
     * Modelos que NO se resuelven por nombre desde las rutas genéricas del panel
     * (`search/{model}`, `search-from-modal/{model}`, `mass-update/{model}`, `meta/{model}`).
     *
     * 🔴 Esas rutas resuelven CUALQUIER clase de `App\Models` a partir de lo que viene en la URL: un
     * modelo nuevo queda expuesto (para leer y para escribir en masa) sin que nadie lo agregue a ningún
     * lado. Estos dos guardan el hash de los tokens con los que se entra al centro de recursos
     * (misión centro-de-recursos-clientes, 13/10/2026): leerlos o pisarlos por la vía genérica sería
     * poder entrar al centro como cualquier usuario de cualquier cliente. Se comparan sin distinguir
     * mayúsculas porque PHP resuelve las clases así.
     *
     * @var array<int, string>
     */
    const MODELOS_NO_EXPUESTOS = [
        'App\\Models\\RecursosSesion',
        'App\\Models\\RecursosLink',
    ];

    /**
     * Convierte un identificador de ruta (p. ej. "client_version_upgrade", "version")
     * en FQCN (p. ej. App\Models\ClientVersionUpgrade).
     *
     * @param string $model_name Ruta o nombre en kebab/snake/una palabra
     * @return string FQCN del modelo
     */
    public static function getModelName($model_name)
    {
        /** "update" en el SPA es el recurso client_version_upgrades, no un modelo "Update" */
        $map = [
            'update'     => 'App\\Models\\ClientVersionUpgrade',
            // "admin_user" mapea al modelo Admin para el CRUD de usuarios del equipo.
            'admin_user' => 'App\\Models\\Admin',
        ];
        if (isset($map[$model_name])) {
            return $map[$model_name];
        }

        $model_name = 'App\\Models\\!'.ucfirst($model_name);
        $model_name = str_replace('!', '', $model_name);
        while (strpos($model_name, '_') !== false) {
            $pos = strpos($model_name, '_');
            $sub_str = substr($model_name, $pos + 1);
            $model_name = substr($model_name, 0, $pos).ucfirst($sub_str);
        }
        while (strpos($model_name, '-') !== false) {
            $pos = strpos($model_name, '-');
            $sub_str = substr($model_name, $pos + 1);
            $model_name = substr($model_name, 0, $pos).ucfirst($sub_str);
        }

        /* Un modelo no expuesto se contesta igual que uno que no existe: 404, sin decir que está. */
        $no_expuestos = array_map('strtolower', self::MODELOS_NO_EXPUESTOS);
        if (in_array(strtolower(ltrim($model_name, '\\')), $no_expuestos, true)) {
            abort(404, 'Recurso desconocido.');
        }

        return $model_name;
    }
}

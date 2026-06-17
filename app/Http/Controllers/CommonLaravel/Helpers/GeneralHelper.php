<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;

/**
 * Resolución de nombres cortos de modelo (SPA/rutas) a clases Eloquent.
 * Basado en el patrón de empresa-api, con mapeo explícito para alias del admin.
 */
class GeneralHelper
{
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

        return $model_name;
    }
}

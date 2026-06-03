<?php

namespace App\Http\Controllers\CommonLaravel\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Asigna al modelo Eloquent las claves presentes en el request según
 * la definición estática properties() de cada modelo (excluyendo only_show / exclude_on_update).
 *
 * Filas solo con `group_title` (sin `key`) son separadores de formulario en admin-spa; aquí se omiten al mapear el request.
 *
 * Propiedades con `not_persisted_on_model: true` se omiten (payloads virtuales gestionados por el controlador, p. ej. relaciones hijas).
 *
 * Propiedades con `from_has_many` validan que el FK apunte a un hijo persistido del padre.
 */
class ModelPropertiesHelper
{
    /**
     * Setea atributos permitidos según el esquema declarativo y persiste.
     *
     * @param \Illuminate\Database\Eloquent\Model $model Instancia a actualizar
     * @param \Illuminate\Http\Request $request Entrada HTTP (JSON o form)
     * @param string $model_name_param Nombre corto coherente con GeneralHelper::getModelName
     * @return void
     */
    public static function set_from_request($model, Request $request, $model_name_param)
    {
        $class = GeneralHelper::getModelName($model_name_param);
        if (! method_exists($class, 'properties')) {
            Log::warning('ModelPropertiesHelper: el modelo no define properties(): '.$class);

            return;
        }

        self::validate_from_has_many($model, $request, $model_name_param);

        foreach ($class::properties() as $prop) {
            if (! empty($prop['exclude_on_update'])) {
                continue;
            }
            if (! empty($prop['only_show'])) {
                continue;
            }
            if (! empty($prop['not_persisted_on_model'])) {
                continue;
            }
            if (empty($prop['key'])) {
                continue;
            }
            if (! $request->has($prop['key'])) {
                continue;
            }
            $model->{$prop['key']} = $request->input($prop['key']);
        }
        $model->save();
    }

    /**
     * Arma un array de atributos para create() según properties() y lo presente en el request.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $model_name_param Nombre corto del modelo
     * @return array<string, mixed>
     */
    public static function attributes_for_create(Request $request, $model_name_param)
    {
        $class = GeneralHelper::getModelName($model_name_param);
        $out = [];
        foreach ($class::properties() as $prop) {
            if (! empty($prop['only_show'])) {
                continue;
            }
            if (! empty($prop['not_persisted_on_model'])) {
                continue;
            }
            if (empty($prop['key'])) {
                continue;
            }
            if (! empty($prop['exclude_on_update']) && $prop['key'] === 'id') {
                continue;
            }
            $k = $prop['key'];
            if ($request->has($k)) {
                $out[$k] = $request->input($k);
            } elseif (array_key_exists('value', $prop)) {
                $out[$k] = $prop['value'];
            }
        }

        return $out;
    }

    /**
     * Valida campos FK declarados con `from_has_many`: el id debe existir entre los hijos del padre.
     *
     * @param \Illuminate\Database\Eloquent\Model $model Instancia padre (debe tener id persistido)
     * @param \Illuminate\Http\Request $request Entrada HTTP
     * @param string $model_name_param Nombre corto del modelo padre
     * @return void
     *
     * @throws ValidationException Si el FK no pertenece a la colección has_many del padre
     */
    public static function validate_from_has_many($model, Request $request, $model_name_param)
    {
        if (! $model || ! $model->id) {
            return;
        }

        $class = GeneralHelper::getModelName($model_name_param);
        if (! method_exists($class, 'properties')) {
            return;
        }

        /** Definiciones meta del modelo padre. */
        $properties = $class::properties();
        /** Columna FK en la tabla hija que apunta al padre (ej. client_id). */
        $parent_fk_column = self::parent_fk_column_from_model_name($model_name_param);
        /** Errores acumulados por key de campo. */
        $errors = [];

        foreach ($properties as $prop) {
            if (empty($prop['from_has_many']['collection_key']) || empty($prop['key'])) {
                continue;
            }
            if (! $request->has($prop['key'])) {
                continue;
            }

            /** Valor FK enviado en el request; null/vacío es válido. */
            $value = $request->input($prop['key']);
            if ($value === null || $value === '') {
                continue;
            }

            /** Meta del campo has_many referenciado por collection_key. */
            $has_many_prop = self::find_has_many_prop_by_collection_key(
                $properties,
                $prop['from_has_many']['collection_key']
            );
            if (! $has_many_prop || empty($has_many_prop['has_many']['model_name'])) {
                continue;
            }

            /** Clase Eloquent del modelo hijo. */
            $child_class = GeneralHelper::getModelName($has_many_prop['has_many']['model_name']);
            /** Etiqueta legible para el mensaje de error. */
            $collection_label = $has_many_prop['text'] ?? $prop['from_has_many']['collection_key'];

            /** Verifica que el hijo exista y pertenezca al padre actual. */
            $exists = $child_class::query()
                ->where('id', $value)
                ->where($parent_fk_column, $model->id)
                ->exists();

            if (! $exists) {
                $errors[$prop['key']] = [
                    'El valor seleccionado debe pertenecer a '.$collection_label.' del registro.',
                ];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Busca la propiedad has_many cuya key coincide con collection_key.
     *
     * @param array<int, array<string, mixed>> $properties
     * @param string $collection_key
     * @return array<string, mixed>|null
     */
    protected static function find_has_many_prop_by_collection_key(array $properties, $collection_key)
    {
        foreach ($properties as $prop) {
            if (! empty($prop['key']) && $prop['key'] === $collection_key) {
                if (! empty($prop['has_many']['model_name'])) {
                    return $prop;
                }
            }
        }

        return null;
    }

    /**
     * Resuelve la columna FK del hijo hacia el padre (snake_case singular + _id).
     *
     * @param string $model_name_param Nombre corto del padre (ej. client, client_version_upgrade)
     * @return string
     */
    protected static function parent_fk_column_from_model_name($model_name_param)
    {
        /** Nombre en snake_case del modelo padre. */
        $snake = str_replace('-', '_', $model_name_param);

        return $snake.'_id';
    }
}

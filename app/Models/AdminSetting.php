<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración global clave-valor del panel administrativo.
 */
class AdminSetting extends Model
{
    /**
     * Campos asignables para upsert de configuración.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Obtiene el valor de una clave o el default si no existe.
     *
     * @param string $key     Clave de configuración.
     * @param mixed  $default Valor por defecto si no hay registro.
     *
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $row = self::where('key', $key)->first();
        if ($row === null) {
            return $default;
        }

        return $row->value;
    }

    /**
     * Persiste o actualiza el valor de una clave de configuración.
     *
     * @param string $key   Clave única.
     * @param mixed  $value Valor serializado como string.
     *
     * @return void
     */
    public static function set(string $key, $value): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );
    }
}

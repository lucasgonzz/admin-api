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
     * Memo por request de los valores ya leídos con memo_value().
     *
     * La clave del array es la `key` del setting y el valor es el crudo de la columna `value`
     * (string o null si la fila no existe). Se usa array_key_exists() y no isset() justamente
     * para que el caso "no hay fila" también quede memorizado: si no, una key sin configurar
     * seguiría pagando una consulta por cada lectura, que es el problema que esto viene a
     * resolver.
     *
     * @var array<string, string|null>
     */
    protected static $value_memo = [];

    /**
     * Invalidación y corte del memo: cualquier escritura por Eloquent sobre admin_settings lo
     * limpia, y cada job de la cola arranca con el memo vacío (ver AppServiceProvider::boot()).
     *
     * Por qué los eventos del modelo y no una llamada explícita en set(): así el memo se entera
     * igual si el valor se escribe por updateOrCreate(), por un save() directo, por un seeder o
     * desde tinker. El caso concreto que esto cubre es el request que guarda
     * `implementation_form_url` y en la misma pasada serializa implementaciones: sin esto el
     * accesor form_link devolvería el link viejo.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function () {
            self::flush_memo();
        });

        static::deleted(function () {
            self::flush_memo();
        });
    }

    /**
     * Vacía el memo por request.
     *
     * @return void
     */
    public static function flush_memo(): void
    {
        self::$value_memo = [];
    }

    /**
     * Valor crudo de una key, leído de la base una sola vez por request.
     *
     * 🔴 Es para las lecturas que se REPITEN dentro de una misma serialización — hoy,
     * `implementation_form_url`, que el accesor form_link de Implementation consulta una vez por
     * fila serializada (un listado de N implementaciones eran N consultas a admin_settings).
     * Para una lectura suelta seguí usando get(): no tiene sentido memorizar algo que se lee
     * una vez.
     *
     * Devuelve exactamente lo mismo que `AdminSetting::where('key', $key)->value('value')`:
     * el string guardado, o null si no hay fila.
     *
     * @param string $key Clave de configuración.
     *
     * @return string|null
     */
    public static function memo_value(string $key)
    {
        if (! array_key_exists($key, self::$value_memo)) {
            self::$value_memo[$key] = self::where('key', $key)->value('value');
        }

        return self::$value_memo[$key];
    }

    /**
     * Deja varias keys memorizadas con UNA sola consulta.
     *
     * Para cuando ya se sabe de antemano que se van a leer todas — hoy, el endpoint que devuelve
     * los settings de implementación juntos: sin esto serían nueve SELECT contra la misma tabla.
     * Las keys que no tienen fila quedan memorizadas como null, así que tampoco pagan una consulta
     * después.
     *
     * No pisa lo que ya estaba memorizado: si en este request ya se leyó una key, el valor es el
     * mismo (cualquier escritura vacía el memo entero).
     *
     * @param array<int, string> $keys Claves a precargar.
     *
     * @return void
     */
    public static function prime_memo(array $keys): void
    {
        // Solo las que faltan; si están todas, no se toca la base.
        $faltantes = array_values(array_diff($keys, array_keys(self::$value_memo)));

        if (empty($faltantes)) {
            return;
        }

        $filas = self::whereIn('key', $faltantes)->pluck('value', 'key');

        foreach ($faltantes as $key) {
            self::$value_memo[$key] = $filas->has($key) ? $filas->get($key) : null;
        }
    }

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

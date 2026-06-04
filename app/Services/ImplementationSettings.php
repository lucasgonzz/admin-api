<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Acceso centralizado a la configuración de implementaciones almacenada en admin_settings.
 *
 * Proporciona métodos estáticos para leer cada setting relevante al flujo
 * de implementación, con fallbacks seguros si el registro no existe.
 */
class ImplementationSettings
{
    /**
     * Retorna la cantidad de segundos que el sistema espera antes de procesar
     * los archivos recibidos en la Etapa 4 (debounce de archivos múltiples).
     *
     * El valor se lee desde admin_settings con key 'implementation_file_wait_seconds'.
     * Si no existe el registro, devuelve 15 como valor por defecto.
     *
     * @return int Segundos de espera (mínimo 1).
     */
    public static function get_file_wait_seconds(): int
    {
        // Leer el valor guardado; fallback a 15 si no existe o es 0.
        $value = (int) AdminSetting::where('key', 'implementation_file_wait_seconds')->value('value');

        return $value > 0 ? $value : 15;
    }
}

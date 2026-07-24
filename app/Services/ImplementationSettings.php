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

    /**
     * Retorna la cantidad de segundos de inactividad que el sistema aguarda
     * tras el último mensaje de empleados antes de preguntarle al cliente si
     * terminó la carga (debounce de confirmación de Etapa 1).
     *
     * El valor se lee desde admin_settings con key 'implementation_employees_wait_seconds'.
     * Si no existe el registro, devuelve 30 como valor por defecto.
     *
     * @return int Segundos de espera (mínimo 1).
     */
    public static function get_employees_wait_seconds(): int
    {
        // Leer el valor guardado; fallback a 30 si no existe o es 0.
        $value = (int) AdminSetting::where('key', 'implementation_employees_wait_seconds')->value('value');

        return $value > 0 ? $value : 30;
    }

    /**
     * Retorna la cantidad de segundos de delay entre el envío del formulario de configuración
     * y el primer contacto automático del sistema por WhatsApp (procesamiento del job).
     *
     * El valor se lee desde admin_settings con key 'implementation_form_contact_delay_seconds'.
     * Si no existe el registro, devuelve 60 como valor por defecto (1 minuto).
     *
     * @return int Segundos de delay (mínimo 1).
     */
    public static function get_form_contact_delay_seconds(): int
    {
        // Leer el valor guardado; fallback a 60 si no existe o es 0.
        $value = (int) AdminSetting::where('key', 'implementation_form_contact_delay_seconds')->value('value');

        return $value > 0 ? $value : 60;
    }

    /**
     * Retorna la URL base del formulario de configuración en admin-spa.
     *
     * El link completo que se le envía al cliente es: {form_url}/{form_token}.
     * El valor se lee desde admin_settings con key 'implementation_form_url'.
     * Si no existe el registro, devuelve cadena vacía.
     *
     * @return string URL base del formulario (sin barra final ni token).
     */
    public static function get_form_url(): string
    {
        // Leer la URL guardada; devuelve cadena vacía si no está configurada.
        $value = (string) AdminSetting::where('key', 'implementation_form_url')->value('value');

        return $value ?? '';
    }

    /**
     * Retorna la cuota de Google por defecto que se asigna al usuario real creado
     * por UserSetupHelper (empresa-api) cuando no se configuró nada.
     *
     * El valor se lee desde admin_settings con key 'implementation_google_cuota_default'.
     * Si no existe el registro, devuelve 100 como valor por defecto.
     *
     * @return int Cuota por defecto (mínimo 0).
     */
    public static function get_google_cuota_default(): int
    {
        // Leer el valor guardado; fallback a 100 si no existe o es 0.
        $value = (int) AdminSetting::where('key', 'implementation_google_cuota_default')->value('value');

        return $value > 0 ? $value : 100;
    }

    /**
     * Retorna la API key de Google Custom Search que se le asigna al User de un
     * cliente real (no demo) al correr el user-setup (UserSetupHelper en empresa-api).
     *
     * El valor se lee desde admin_settings con key 'implementation_google_api_key_default'.
     *
     * Fallback intencional: cadena vacía ''. Si en admin todavía no se cargó ninguna key,
     * admin-api no manda el campo 'google_custom_search_api_key' en el payload y
     * empresa-api usa la constante que ya tiene hardcodeada en su propio código. Así el
     * día que se despliega esto no cambia nada de comportamiento hasta que se cargue el valor.
     *
     * @return string API key (o cadena vacía si no está configurada).
     */
    public static function get_google_api_key_default(): string
    {
        // Leer el valor guardado; trim para que un valor con espacios al final no rompa la llamada a Google.
        $value = trim((string) AdminSetting::where('key', 'implementation_google_api_key_default')->value('value'));

        return $value;
    }

    /**
     * Retorna la API key de Google Custom Search que se le asigna al User de una
     * demo al correr el demo-setup (DemoSetupHelper en empresa-api).
     *
     * El valor se lee desde admin_settings con key 'implementation_google_api_key_demo'.
     * Se mantiene separada de get_google_api_key_default() a propósito: la cuota diaria de
     * Custom Search es por key, y mezclar demo con clientes reales haría que las demos
     * consuman la cuota de los clientes que pagan.
     *
     * Fallback intencional: cadena vacía '' (mismo motivo que get_google_api_key_default()).
     *
     * @return string API key (o cadena vacía si no está configurada).
     */
    public static function get_google_api_key_demo(): string
    {
        // Leer el valor guardado; trim para que un valor con espacios al final no rompa la llamada a Google.
        $value = trim((string) AdminSetting::where('key', 'implementation_google_api_key_demo')->value('value'));

        return $value;
    }

    /**
     * Retorna la cuota de Google por defecto que se asigna al usuario creado por
     * DemoSetupHelper (empresa-api) para las demos, cuando no se configuró nada.
     *
     * El valor se lee desde admin_settings con key 'implementation_google_cuota_demo'.
     * Si no existe el registro, devuelve 100 como valor por defecto (mismo fallback
     * que get_google_cuota_default(), ya que hoy DemoSetupHelper tiene ese valor hardcodeado).
     *
     * @return int Cuota por defecto (mínimo 0).
     */
    public static function get_google_cuota_demo(): int
    {
        // Leer el valor guardado; fallback a 100 si no existe o es 0.
        $value = (int) AdminSetting::where('key', 'implementation_google_cuota_demo')->value('value');

        return $value > 0 ? $value : 100;
    }
}

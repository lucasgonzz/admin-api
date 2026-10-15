<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Services\DemoCredentialsProbeService;
use App\Services\ImplementationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configuración del flujo de implementaciones: admin asignado por defecto,
 * tiempo de espera para confirmar lista de empleados (Etapa 1) y tiempo de
 * espera para procesar archivos recibidos (Etapa 4).
 *
 * Expone endpoints para que el panel de Account pueda leer y actualizar
 * los settings de implementación almacenados en admin_settings.
 */
class ImplementationSettingsController extends Controller
{
    /**
     * Keys de admin_settings que toca esta pantalla, para precargarlas de una.
     *
     * Constante de clase y no un enum: admin-api corre PHP 7.4 en producción.
     *
     * @var array<int, string>
     */
    const KEYS_DE_LA_PANTALLA = [
        'implementation_assigned_admin_id',
        'implementation_file_wait_seconds',
        'implementation_employees_wait_seconds',
        'implementation_form_contact_delay_seconds',
        'implementation_form_url',
        'implementation_google_cuota_default',
        'implementation_google_api_key_default',
        'implementation_google_api_key_demo',
        'implementation_google_cuota_demo',
        'implementation_serper_api_key_default',
        'implementation_serper_api_key_demo',
        // Credenciales de prueba de las demos (Zipnova y Mercado Pago): los cuerpos de show_all()
        // las leen del memo, y sin esto serían cinco consultas más.
        'implementation_zipnova_demo_api_token',
        'implementation_zipnova_demo_api_secret',
        'implementation_zipnova_demo_account_id',
        'implementation_mercadopago_demo_access_token',
        'implementation_mercadopago_demo_public_key',
    ];

    /**
     * Formato de una credencial que no se conoce: cualquier cosa sin espacios ni caracteres de
     * control. Es lo que ataja el error de pegado típico (un salto de línea o un espacio en el
     * medio), sin atarse a un largo o un alfabeto que Zipnova puede cambiar.
     *
     * @var string
     */
    const REGEX_CREDENCIAL_OPACA = '/^[^\s\x00-\x1F\x7F]+$/';

    /**
     * Mensaje de validación cuando una credencial de Zipnova trae espacios o saltos de línea.
     *
     * @var string
     */
    const MENSAJE_CREDENCIAL_OPACA = 'La credencial no puede tener espacios ni saltos de línea: revisá que se haya copiado entera y sin nada de más.';

    /**
     * Mensaje de validación cuando en un campo de Serper se pega una API key de Google.
     *
     * admin-spa tiene su propia copia para avisarlo antes de mandar; esta es la que ve cualquier
     * otro cliente de la API (y la que se prueba).
     *
     * @var string
     */
    const MENSAJE_CLAVE_DE_GOOGLE_EN_SERPER = 'Eso parece una clave de Google (empieza con AIza), no de Serper. Las de Google van en el bloque de Google.';

    /**
     * Mensaje de validación cuando la clave de Serper no tiene el formato esperado.
     *
     * @var string
     */
    const MENSAJE_FORMATO_DE_SERPER = 'La clave no tiene el formato de una clave de Serper (entre 32 y 64 letras y números, sin espacios ni símbolos).';

    /**
     * Devuelve los once settings de implementación en una sola respuesta.
     *
     * 🔴 Se AGREGA, no reemplaza: los once GET de a uno siguen existiendo y devolviendo
     * exactamente lo mismo. admin-spa los mantiene como camino de respaldo.
     *
     * El motivo es que la pantalla de configuración hacía 9 GET al montarse, uno por setting,
     * todos contra la misma tabla. Ahora es un request y —gracias al prime_memo— una sola
     * consulta a admin_settings.
     *
     * Cada entrada viene bajo la misma clave que usa su ruta individual y con el MISMO cuerpo que
     * devuelve esa ruta, así el SPA parsea igual en los dos caminos y el respaldo es un cambio de
     * origen, no de forma:
     *
     *   implementation-assigned-admin    => { admin_id: int|null }
     *   implementation-file-wait         => { seconds: int }
     *   implementation-employees-wait    => { seconds: int }
     *   implementation-form-contact-delay=> { seconds: int }
     *   implementation-form-url          => { url: string }
     *   implementation-google-cuota-default   => { cuota: int }
     *   implementation-google-api-key-default => { api_key: string }
     *   implementation-google-api-key-demo    => { api_key: string }
     *   implementation-google-cuota-demo      => { cuota: int }
     *   implementation-serper-api-key-default => { api_key: string }
     *   implementation-serper-api-key-demo    => { api_key: string }  (la guardada, sin caer a la de clientes)
     *   implementation-zipnova-demo           => { cargada, api_token_cola, api_secret_cargado, account_id, ilegible }  (nunca el valor)
     *   implementation-mercadopago-demo       => { cargada, access_token_cola, public_key_cola, public_key_cargada, tipo_access_token, ilegible }  (nunca el valor)
     *
     * Los valores salen de los mismos métodos que usan las rutas de a uno, así que los fallbacks
     * (15, 30, 60, 300, 100, cadena vacía) no están duplicados acá y no pueden desincronizarse.
     *
     * @return JsonResponse
     */
    public function show_all(): JsonResponse
    {
        // Las once keys en una consulta; los getters de abajo las leen del memo.
        AdminSetting::prime_memo(self::KEYS_DE_LA_PANTALLA);

        // Mismo tratamiento que show(): entero o null si no hay admin configurado.
        $raw_admin_id = AdminSetting::memo_value('implementation_assigned_admin_id');
        $admin_id     = ($raw_admin_id !== null && (int) $raw_admin_id > 0) ? (int) $raw_admin_id : null;

        return response()->json([
            'settings' => [
                'implementation-assigned-admin'         => ['admin_id' => $admin_id],
                'implementation-file-wait'              => ['seconds' => ImplementationSettings::get_file_wait_seconds()],
                'implementation-employees-wait'         => ['seconds' => ImplementationSettings::get_employees_wait_seconds()],
                'implementation-form-contact-delay'     => ['seconds' => ImplementationSettings::get_form_contact_delay_seconds()],
                'implementation-form-url'               => ['url' => ImplementationSettings::get_form_url()],
                'implementation-google-cuota-default'   => ['cuota' => ImplementationSettings::get_google_cuota_default()],
                'implementation-google-api-key-default' => ['api_key' => ImplementationSettings::get_google_api_key_default()],
                'implementation-google-api-key-demo'    => ['api_key' => ImplementationSettings::get_google_api_key_demo()],
                'implementation-google-cuota-demo'      => ['cuota' => ImplementationSettings::get_google_cuota_demo()],
                'implementation-serper-api-key-default' => ['api_key' => ImplementationSettings::get_serper_api_key_default()],
                'implementation-serper-api-key-demo'    => ['api_key' => ImplementationSettings::get_serper_api_key_demo_stored()],
                // Credenciales de prueba de las demos: SOLO el estado y la cola, jamás el valor.
                'implementation-zipnova-demo'           => ImplementationSettings::estado_zipnova_demo(),
                'implementation-mercadopago-demo'       => ImplementationSettings::estado_mercadopago_demo(),
            ],
        ], 200);
    }

    /**
     * Retorna el admin actualmente configurado como responsable de implementaciones.
     *
     * Devuelve admin_id (int o null) para pre-seleccionar el valor en el select del frontend.
     *
     * @return JsonResponse
     */
    public function show(): JsonResponse
    {
        // Leer el ID del admin configurado; convertir a entero o null si no existe.
        $raw_value = AdminSetting::get('implementation_assigned_admin_id');
        $admin_id  = ($raw_value !== null && (int) $raw_value > 0) ? (int) $raw_value : null;

        return response()->json(['admin_id' => $admin_id], 200);
    }

    /**
     * Actualiza el admin asignado por defecto a nuevas implementaciones.
     *
     * Valida que el admin_id exista en la tabla admins antes de persistir.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // El admin_id debe existir en la tabla admins.
            'admin_id' => 'required|integer|exists:admins,id',
        ]);

        // Persistir o actualizar el setting con el nuevo ID de admin.
        AdminSetting::set('implementation_assigned_admin_id', (string) $validated['admin_id']);

        return response()->json(['admin_id' => (int) $validated['admin_id']], 200);
    }

    /**
     * Retorna la cantidad de segundos de espera configurada para procesar archivos
     * recibidos en la Etapa 4 (debounce de múltiples archivos).
     *
     * @return JsonResponse
     */
    public function get_file_wait(): JsonResponse
    {
        // Leer el valor actual del setting; ImplementationSettings aplica el fallback a 15.
        $seconds = ImplementationSettings::get_file_wait_seconds();

        return response()->json(['seconds' => $seconds], 200);
    }

    /**
     * Actualiza la cantidad de segundos de espera antes de procesar archivos
     * recibidos en la Etapa 4.
     *
     * Valida que el valor esté entre 1 y 120 segundos para evitar configuraciones extremas.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_file_wait(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Mínimo 1 segundo para que el debounce tenga sentido; máximo 120 para no bloquear demasiado.
            'seconds' => 'required|integer|min:1|max:120',
        ]);

        // Persistir o actualizar el setting con el nuevo valor.
        AdminSetting::set('implementation_file_wait_seconds', (string) $validated['seconds']);

        return response()->json(['seconds' => (int) $validated['seconds']], 200);
    }

    /**
     * Retorna la cantidad de segundos de espera configurada para preguntar al cliente
     * si terminó de pasar empleados en la Etapa 1 (debounce de confirmación).
     *
     * @return JsonResponse
     */
    public function get_employees_wait(): JsonResponse
    {
        // Leer el valor actual del setting; ImplementationSettings aplica el fallback a 30.
        $seconds = ImplementationSettings::get_employees_wait_seconds();

        return response()->json(['seconds' => $seconds], 200);
    }

    /**
     * Actualiza la cantidad de segundos de espera antes de preguntar al cliente
     * si terminó de pasar empleados en la Etapa 1.
     *
     * Valida que el valor esté entre 1 y 120 segundos para evitar configuraciones extremas.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_employees_wait(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Mínimo 1 segundo para que el debounce tenga sentido; máximo 120 para no bloquear demasiado.
            'seconds' => 'required|integer|min:1|max:120',
        ]);

        // Persistir o actualizar el setting con el nuevo valor.
        AdminSetting::set('implementation_employees_wait_seconds', (string) $validated['seconds']);

        return response()->json(['seconds' => (int) $validated['seconds']], 200);
    }

    /**
     * Retorna la cantidad de segundos de delay post-formulario configurada.
     *
     * Es el tiempo entre que el cliente envía el formulario y el primer contacto automático por WhatsApp.
     *
     * @return JsonResponse { seconds: int }
     */
    public function get_form_contact_delay(): JsonResponse
    {
        // Leer el valor actual del setting; ImplementationSettings aplica el fallback a 60.
        $seconds = ImplementationSettings::get_form_contact_delay_seconds();

        return response()->json(['seconds' => $seconds], 200);
    }

    /**
     * Actualiza la cantidad de segundos de delay entre el envío del formulario
     * y el primer contacto automático por WhatsApp.
     *
     * @param Request $request
     *
     * @return JsonResponse { seconds: int }
     */
    public function update_form_contact_delay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Al menos 1 segundo; sin límite superior estricto (puede ser minutos).
            'seconds' => 'required|integer|min:1|max:3600',
        ]);

        // Persistir o actualizar el setting.
        AdminSetting::set('implementation_form_contact_delay_seconds', (string) $validated['seconds']);

        return response()->json(['seconds' => (int) $validated['seconds']], 200);
    }

    /**
     * Retorna la URL base del formulario de configuración configurada.
     *
     * @return JsonResponse { url: string }
     */
    public function get_form_url(): JsonResponse
    {
        // Leer la URL guardada; devuelve cadena vacía si no está configurada.
        $url = ImplementationSettings::get_form_url();

        return response()->json(['url' => $url], 200);
    }

    /**
     * Actualiza la URL base del formulario de configuración en admin-spa.
     *
     * La URL completa para el cliente es: {url}/{form_token}.
     *
     * @param Request $request
     *
     * @return JsonResponse { url: string }
     */
    public function update_form_url(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // La URL puede estar vacía (para limpiar el setting) o ser una URL válida.
            'url' => 'nullable|string|max:500',
        ]);

        // Persistir o actualizar el setting con la nueva URL.
        $url = (string) ($validated['url'] ?? '');
        AdminSetting::set('implementation_form_url', $url);

        return response()->json(['url' => $url], 200);
    }

    /**
     * Retorna la cuota de Google configurada por defecto para nuevos usuarios reales.
     *
     * @return JsonResponse
     */
    public function get_google_cuota_default(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a 100.
        $cuota = ImplementationSettings::get_google_cuota_default();

        return response()->json(['cuota' => $cuota], 200);
    }

    /**
     * Actualiza la cuota de Google por defecto para nuevos usuarios reales.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_google_cuota_default(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Sin límite superior estricto: es un contador de uso, no un booleano ni un rango acotado.
            'cuota' => 'required|integer|min:0',
        ]);

        AdminSetting::set('implementation_google_cuota_default', (string) $validated['cuota']);

        return response()->json(['cuota' => (int) $validated['cuota']], 200);
    }

    /**
     * Retorna la API key de Google Custom Search configurada para clientes reales.
     *
     * No se enmascara el valor: el panel de admin es interno y hace falta poder ver
     * qué key está cargada.
     *
     * @return JsonResponse { api_key: string }
     */
    public function get_google_api_key_default(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a cadena vacía.
        $api_key = ImplementationSettings::get_google_api_key_default();

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Actualiza la API key de Google Custom Search para clientes reales.
     *
     * Las API keys de Google Custom Search tienen forma "AIza" + 35 caracteres alfanuméricos
     * (incluyendo "-" y "_"). La regex está para evitar el error más caro posible: guardar
     * una key mal copiada (cortada, con un espacio pegado, o la key de otro servicio de
     * Google) y enterarse recién cuando un cliente nuevo no puede buscar imágenes.
     *
     * Se acepta null / cadena vacía como forma explícita de borrar la setting y volver
     * al fallback hardcodeado en empresa-api (en ese caso se guarda '').
     *
     * @param Request $request
     *
     * @return JsonResponse { api_key: string }
     */
    public function update_google_api_key_default(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // "present" permite null/'' explícito para borrar; si viene con contenido, debe matchear el formato de Google.
            'api_key' => ['present', 'nullable', 'string', 'max:100', 'regex:/^AIza[0-9A-Za-z\-_]{35}$/'],
        ]);

        // Cadena vacía si vino null (borra la setting y vuelve al fallback de empresa-api).
        $api_key = (string) ($validated['api_key'] ?? '');
        AdminSetting::set('implementation_google_api_key_default', $api_key);

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Retorna la API key de Google Custom Search configurada para demos.
     *
     * Separada de la de clientes reales a propósito: la cuota diaria de Custom Search
     * es por key, y mezclarlas haría que las demos consuman la cuota de los clientes que pagan.
     *
     * @return JsonResponse { api_key: string }
     */
    public function get_google_api_key_demo(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a cadena vacía.
        $api_key = ImplementationSettings::get_google_api_key_demo();

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Actualiza la API key de Google Custom Search para demos.
     *
     * Misma validación y semántica de borrado que update_google_api_key_default().
     *
     * @param Request $request
     *
     * @return JsonResponse { api_key: string }
     */
    public function update_google_api_key_demo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // "present" permite null/'' explícito para borrar; si viene con contenido, debe matchear el formato de Google.
            'api_key' => ['present', 'nullable', 'string', 'max:100', 'regex:/^AIza[0-9A-Za-z\-_]{35}$/'],
        ]);

        // Cadena vacía si vino null (borra la setting y vuelve al fallback de empresa-api).
        $api_key = (string) ($validated['api_key'] ?? '');
        AdminSetting::set('implementation_google_api_key_demo', $api_key);

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Retorna la cuota de Google configurada por defecto para nuevos usuarios de demo.
     *
     * @return JsonResponse
     */
    public function get_google_cuota_demo(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a 100.
        $cuota = ImplementationSettings::get_google_cuota_demo();

        return response()->json(['cuota' => $cuota], 200);
    }

    /**
     * Actualiza la cuota de Google por defecto para nuevos usuarios de demo.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_google_cuota_demo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Sin límite superior estricto: es un contador de uso, no un booleano ni un rango acotado.
            'cuota' => 'required|integer|min:0',
        ]);

        AdminSetting::set('implementation_google_cuota_demo', (string) $validated['cuota']);

        return response()->json(['cuota' => (int) $validated['cuota']], 200);
    }

    /**
     * Retorna la clave de Serper configurada para clientes reales.
     *
     * No se enmascara el valor, con el mismo criterio que las keys de Google: el panel de admin es
     * interno y hace falta poder ver qué clave está cargada.
     *
     * @return JsonResponse { api_key: string }
     */
    public function get_serper_api_key_default(): JsonResponse
    {
        // ImplementationSettings aplica el fallback a cadena vacía.
        $api_key = ImplementationSettings::get_serper_api_key_default();

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Actualiza la clave de Serper para clientes reales.
     *
     * Las claves de Serper son alfanuméricas (hoy, 40 caracteres hexadecimales). La regex acepta de
     * 32 a 64 letras y números para no atarse al largo exacto, y está por el mismo motivo que la de
     * Google: que una clave mal copiada (cortada, con un espacio en el medio, u otra cosa pegada por
     * error) se frene acá y no cuando un cliente nuevo no puede buscar imágenes. Tampoco se acepta
     * una API key de Google (ver validar_clave_de_serper()).
     *
     * Se acepta null / cadena vacía como forma explícita de borrarla (se guarda ''): el user-setup
     * deja de mandar el campo y los clientes nuevos usan la SERPER_API_KEY del .env de su sistema.
     *
     * Los espacios de los costados no llegan a la validación: los saca antes el middleware
     * TrimStrings, así que una clave copiada con un espacio pegado se guarda limpia.
     *
     * @param Request $request
     *
     * @return JsonResponse { api_key: string }
     */
    public function update_serper_api_key_default(Request $request): JsonResponse
    {
        // Cadena vacía si vino null o vacía (borra la setting y los setups nuevos vuelven al .env de empresa-api).
        $api_key = $this->validar_clave_de_serper($request);
        AdminSetting::set('implementation_serper_api_key_default', $api_key);

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Retorna la clave de Serper PROPIA de las demos, la guardada: si no hay una, devuelve cadena
     * vacía aunque las demos estén recibiendo la de clientes.
     *
     * A propósito no devuelve la efectiva (ImplementationSettings::get_serper_api_key_demo()): el
     * campo del panel tiene que verse vacío cuando no hay clave propia, que es lo que dice su
     * etiqueta, y guardarlo sin tocar no puede copiar la de clientes como si fuera de las demos.
     *
     * @return JsonResponse { api_key: string }
     */
    public function get_serper_api_key_demo(): JsonResponse
    {
        // La guardada para demos, sin la caída a la de clientes.
        $api_key = ImplementationSettings::get_serper_api_key_demo_stored();

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Actualiza la clave de Serper propia de las demos.
     *
     * Misma validación y semántica de borrado que update_serper_api_key_default(). Borrarla no deja
     * a las demos sin Serper: pasan a recibir la de clientes (ImplementationSettings::get_serper_api_key_demo()).
     *
     * @param Request $request
     *
     * @return JsonResponse { api_key: string }
     */
    public function update_serper_api_key_demo(Request $request): JsonResponse
    {
        // Cadena vacía si vino null o vacía (borra la setting y las demos vuelven a recibir la de clientes).
        $api_key = $this->validar_clave_de_serper($request);
        AdminSetting::set('implementation_serper_api_key_demo', $api_key);

        return response()->json(['api_key' => $api_key], 200);
    }

    /**
     * Valida el campo api_key de un PUT de clave de Serper y lo devuelve listo para guardar:
     * cadena vacía si vino null o vacío (la forma explícita de borrarla).
     *
     * Las reglas van con "bail": se corta en la primera que falla, así la respuesta trae un solo
     * mensaje, el más útil. En orden:
     *
     *  - "present": el campo tiene que venir. Un PUT sin él es un error, no un borrado.
     *  - Nada que empiece con "AIza", el prefijo de las API keys de Google, que se pegan acá por
     *    error porque viven en el bloque de al lado. 🔴 Va ANTES del formato a propósito: una key
     *    de Google sin "-" ni "_" son 39 letras y números y pasaría el formato de Serper; y una con
     *    guion se rechazaría con un "formato inválido" que no dice qué pasó.
     *  - El formato de Serper: 32 a 64 letras y números. Los espacios de los costados ya los sacó
     *    el middleware TrimStrings, así que una clave copiada con uno pegado pasa limpia.
     *
     * @param Request $request
     *
     * @return string Clave validada, o cadena vacía para borrar.
     */
    private function validar_clave_de_serper(Request $request): string
    {
        $validated = $request->validate([
            'api_key' => ['bail', 'present', 'nullable', 'string', 'max:100', 'not_regex:/^AIza/', 'regex:/^[A-Za-z0-9]{32,64}$/'],
        ], [
            'api_key.not_regex' => self::MENSAJE_CLAVE_DE_GOOGLE_EN_SERPER,
            'api_key.regex'     => self::MENSAJE_FORMATO_DE_SERPER,
        ]);

        return (string) ($validated['api_key'] ?? '');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Credenciales de PRUEBA de Zipnova y de Mercado Pago de las demos (misión cruzada
    // credenciales-demo-zipnova-mp, 14/10/2026).
    //
    // 🔴 A diferencia de las claves de Serper de arriba, acá NINGÚN GET devuelve el valor: solo el
    // estado (cargada o no) y la cola de 4 caracteres. Se guardan cifradas (ver el bloque de
    // ImplementationSettings) y la pantalla nunca se rellena con lo guardado: se escribe una
    // credencial nueva para reemplazarla, o se borra. Es lo que impide que un screenshot, la
    // pestaña Red del navegador o un log del proxy contengan una credencial de pagos.
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Estado de las credenciales de Zipnova de las demos. Jamás devuelve el token ni el secret.
     *
     * @return JsonResponse { cargada, api_token_cola, api_secret_cargado, account_id, ilegible }
     */
    public function get_zipnova_demo(): JsonResponse
    {
        return response()->json(ImplementationSettings::estado_zipnova_demo(), 200);
    }

    /**
     * Guarda, reemplaza o borra las credenciales de Zipnova de las demos.
     *
     * Cuerpo:
     *  - api_token, api_secret: los valores NUEVOS. Ausente o null = se conserva lo guardado.
     *  - account_id: opcional, numérico (el id de la cuenta de Zipnova). Ausente o null = se conserva.
     *  - borrar: true = borra las tres y no mira el resto.
     *
     * Zipnova necesita token Y secret: si después de aplicar lo que llegó no quedan los dos
     * cargados (la primera vez, o si lo guardado era ilegible), se rechaza con 422 en lugar de
     * dejar un par a medias que el demo-setup ignoraría sin avisar. Una vez cargados los dos, se
     * puede cambiar uno solo.
     *
     * Sin credenciales las demos siguen como siempre: el demo-setup no manda los campos y
     * empresa-api usa lo que tenga en su .env (ZIPNOVA_DEMO_*).
     *
     * La respuesta es la misma que la del GET: nunca el valor.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_zipnova_demo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'borrar'     => ['sometimes', 'nullable', 'boolean'],
            'api_token'  => ['bail', 'sometimes', 'nullable', 'string', 'max:255', 'regex:' . self::REGEX_CREDENCIAL_OPACA],
            'api_secret' => ['bail', 'sometimes', 'nullable', 'string', 'max:255', 'regex:' . self::REGEX_CREDENCIAL_OPACA],
            'account_id' => ['bail', 'sometimes', 'nullable', 'regex:/^[0-9]{1,20}$/'],
        ], [
            'api_token.regex'  => self::MENSAJE_CREDENCIAL_OPACA,
            'api_secret.regex' => self::MENSAJE_CREDENCIAL_OPACA,
            'account_id.regex' => 'El id de cuenta de Zipnova es un número.',
        ]);

        // Borrar gana sobre todo lo demás: vacía las tres keys y devuelve el estado (cargada: false).
        if (! empty($datos['borrar'])) {
            ImplementationSettings::borrar_credenciales(ImplementationSettings::KEYS_ZIPNOVA_DEMO);

            return response()->json(ImplementationSettings::estado_zipnova_demo(), 200);
        }

        $api_token  = $datos['api_token'] ?? null;
        $api_secret = $datos['api_secret'] ?? null;
        $account_id = $datos['account_id'] ?? null;

        // Un PUT que no trae nada para guardar es un error, no un borrado (para eso está `borrar`).
        if ($api_token === null && $api_secret === null && $account_id === null) {
            throw ValidationException::withMessages([
                'api_token' => ['No se mandó ningún dato para guardar.'],
            ]);
        }

        // Lo que quedaría cargado después de aplicar el PUT: lo nuevo, o lo que ya estaba.
        $guardadas = ImplementationSettings::get_zipnova_demo_credenciales();
        $token_final  = $api_token ?? $guardadas['api_token'];
        $secret_final = $api_secret ?? $guardadas['api_secret'];

        if ($token_final === '' || $secret_final === '') {
            throw ValidationException::withMessages([
                'api_token' => ['Zipnova necesita el API Token y el API Secret juntos: cargá los dos.'],
            ]);
        }

        DB::transaction(function () use ($api_token, $api_secret, $account_id) {
            if ($api_token !== null) {
                AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_token'], ImplementationSettings::cifrar_credencial($api_token));
            }

            if ($api_secret !== null) {
                AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['api_secret'], ImplementationSettings::cifrar_credencial($api_secret));
            }

            // El id de cuenta no es secreto: va en claro.
            if ($account_id !== null) {
                AdminSetting::set(ImplementationSettings::KEYS_ZIPNOVA_DEMO['account_id'], (string) $account_id);
            }
        });

        return response()->json(ImplementationSettings::estado_zipnova_demo(), 200);
    }

    /**
     * Prueba las credenciales de Zipnova cargadas contra Zipnova (GET /v2/accounts, solo lectura).
     *
     * Siempre responde 200 con el resultado de la prueba: que las credenciales no autentiquen es
     * un RESULTADO, no un error de este endpoint.
     *
     * @param DemoCredentialsProbeService $probe
     *
     * @return JsonResponse { cargada, autentica, cuenta, motivo, aviso }
     */
    public function probar_zipnova_demo(DemoCredentialsProbeService $probe): JsonResponse
    {
        return response()->json($probe->probar_zipnova(), 200);
    }

    /**
     * Estado de las credenciales de Mercado Pago de las demos. Jamás devuelve el access token completo.
     *
     * @return JsonResponse { cargada, access_token_cola, public_key_cola, public_key_cargada, tipo_access_token, ilegible }
     */
    public function get_mercadopago_demo(): JsonResponse
    {
        return response()->json(ImplementationSettings::estado_mercadopago_demo(), 200);
    }

    /**
     * Guarda, reemplaza o borra las credenciales de Mercado Pago de las demos.
     *
     * Cuerpo: access_token y public_key (los valores NUEVOS; ausente o null = se conserva lo
     * guardado) y borrar (true = borra las dos). Mismas reglas de par que Zipnova: tienen que quedar
     * cargadas las dos. Formato: las credenciales de Mercado Pago empiezan con TEST- (de prueba) o
     * APP_USR- (de producción), y siguen con al menos 20 caracteres.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function update_mercadopago_demo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'borrar'       => ['sometimes', 'nullable', 'boolean'],
            'access_token' => ['bail', 'sometimes', 'nullable', 'string', 'max:255', 'regex:/^(TEST|APP_USR)-[A-Za-z0-9_-]{20,}$/'],
            'public_key'   => ['bail', 'sometimes', 'nullable', 'string', 'max:255', 'regex:/^(TEST|APP_USR)-[A-Za-z0-9-]{20,}$/'],
        ], [
            'access_token.regex' => 'El Access Token de Mercado Pago empieza con TEST- (de prueba) o APP_USR- y tiene al menos 20 caracteres más, sin espacios.',
            'public_key.regex'   => 'La Public Key de Mercado Pago empieza con TEST- (de prueba) o APP_USR- y tiene al menos 20 caracteres más, sin espacios.',
        ]);

        if (! empty($datos['borrar'])) {
            ImplementationSettings::borrar_credenciales(ImplementationSettings::KEYS_MERCADOPAGO_DEMO);

            return response()->json(ImplementationSettings::estado_mercadopago_demo(), 200);
        }

        $access_token = $datos['access_token'] ?? null;
        $public_key   = $datos['public_key'] ?? null;

        if ($access_token === null && $public_key === null) {
            throw ValidationException::withMessages([
                'access_token' => ['No se mandó ningún dato para guardar.'],
            ]);
        }

        $guardadas    = ImplementationSettings::get_mercadopago_demo_credenciales();
        $access_final = $access_token ?? $guardadas['access_token'];
        $public_final = $public_key ?? $guardadas['public_key'];

        if ($access_final === '' || $public_final === '') {
            throw ValidationException::withMessages([
                'access_token' => ['Mercado Pago necesita el Access Token y la Public Key juntos: cargá los dos.'],
            ]);
        }

        DB::transaction(function () use ($access_token, $public_key) {
            if ($access_token !== null) {
                AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['access_token'], ImplementationSettings::cifrar_credencial($access_token));
            }

            // La public key es pública por diseño: va en claro.
            if ($public_key !== null) {
                AdminSetting::set(ImplementationSettings::KEYS_MERCADOPAGO_DEMO['public_key'], $public_key);
            }
        });

        return response()->json(ImplementationSettings::estado_mercadopago_demo(), 200);
    }

    /**
     * Prueba el access token de Mercado Pago cargado contra Mercado Pago (GET /users/me, solo lectura).
     *
     * @param DemoCredentialsProbeService $probe
     *
     * @return JsonResponse { cargada, autentica, cuenta, motivo, aviso }
     */
    public function probar_mercadopago_demo(DemoCredentialsProbeService $probe): JsonResponse
    {
        return response()->json($probe->probar_mercadopago(), 200);
    }
}

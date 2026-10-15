<?php

namespace App\Services;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Acceso centralizado a la configuración de implementaciones almacenada en admin_settings.
 *
 * Proporciona métodos estáticos para leer cada setting relevante al flujo
 * de implementación, con fallbacks seguros si el registro no existe.
 *
 * 🔴 Todas leen con AdminSetting::memo_value(): una consulta por key y por request, no una por
 * llamada. Lo pedía get_form_url() —que el $appends de Implementation dispara una vez por fila
 * serializada— y de paso lo aprovechan las demás, que en más de un flujo se leen dos veces
 * (RunDemoSetupService llama a get_google_cuota_demo(), get_google_api_key_demo() y
 * get_serper_api_key_demo() en dos lugares).
 *
 * El memo lo vacía cualquier escritura por Eloquent sobre admin_settings (los eventos saved y
 * deleted de AdminSetting), así que el request que guarda un valor y después lo vuelve a leer ve
 * el nuevo. Y cada job de la cola arranca con el memo limpio (AppServiceProvider), porque ahí el
 * "request" dura lo que dure el worker.
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
        $value = (int) AdminSetting::memo_value('implementation_file_wait_seconds');

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
        $value = (int) AdminSetting::memo_value('implementation_employees_wait_seconds');

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
        $value = (int) AdminSetting::memo_value('implementation_form_contact_delay_seconds');

        return $value > 0 ? $value : 60;
    }

    /**
     * Retorna la URL base del formulario de configuración en admin-spa.
     *
     * El link completo que se le envía al cliente es: {form_url}/{form_token}.
     * El valor se lee desde admin_settings con key 'implementation_form_url'.
     * Si no existe el registro, devuelve cadena vacía.
     *
     * 🔴 Es la lectura que motivó el memo de toda la clase: el accesor form_link de Implementation
     * está en su $appends, así que esto se llamaba UNA VEZ POR FILA SERIALIZADA — N consultas a
     * admin_settings en GET /implementation, y otras tantas en GET /client y GET /lead, que
     * arrastran la implementación como relación.
     *
     * @return string URL base del formulario (sin barra final ni token).
     */
    public static function get_form_url(): string
    {
        // Leer la URL guardada; devuelve cadena vacía si no está configurada.
        return (string) AdminSetting::memo_value('implementation_form_url');
    }

    /**
     * Retorna la cuota de Google por defecto que se asigna al usuario real creado
     * por UserSetupHelper (empresa-api) cuando no se configuró nada.
     *
     * El valor se lee desde admin_settings con key 'implementation_google_cuota_default'.
     * Si no existe el registro, devuelve 300 como valor por defecto.
     *
     * Por qué 300 y no 100: decisión de Lucas del 24/8/2026 — la cuota de imágenes de un
     * cliente real creado por el user setup tiene que nacer en 300 (la demo nace en 100 y
     * eso lo resuelve empresa-api). Este fallback es el camino principal en producción:
     * RunUserSetupService manda SIEMPRE el campo google_cuota, así que el default de
     * empresa-api casi nunca aplica. Si el AdminSetting está cargado, ese valor le gana
     * al fallback, como corresponde.
     *
     * @return int Cuota por defecto (mínimo 0).
     */
    public static function get_google_cuota_default(): int
    {
        // Leer el valor guardado; fallback a 300 si no existe o es 0.
        $value = (int) AdminSetting::memo_value('implementation_google_cuota_default');

        return $value > 0 ? $value : 300;
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
        $value = trim((string) AdminSetting::memo_value('implementation_google_api_key_default'));

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
        $value = trim((string) AdminSetting::memo_value('implementation_google_api_key_demo'));

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
        $value = (int) AdminSetting::memo_value('implementation_google_cuota_demo');

        return $value > 0 ? $value : 100;
    }

    /**
     * Retorna la clave de Serper que se le asigna al User de un cliente real (no demo) al correr
     * el user-setup (UserSetupHelper en empresa-api la guarda en users.serper_api_key).
     *
     * El valor se lee desde admin_settings con key 'implementation_serper_api_key_default'.
     *
     * Fallback intencional: cadena vacía '', con el mismo criterio que get_google_api_key_default().
     * Si en el admin todavía no se cargó ninguna clave, RunUserSetupService no manda el campo
     * 'serper_api_key', empresa-api deja la columna del dueño en null y el sistema sigue usando la
     * SERPER_API_KEY de su .env, como hasta ahora. El día que se despliega esto no cambia nada
     * hasta que se cargue el valor.
     *
     * @return string Clave (o cadena vacía si no está configurada).
     */
    public static function get_serper_api_key_default(): string
    {
        // Leer el valor guardado; trim para que un espacio pegado al copiarla no rompa la llamada a Serper.
        $value = trim((string) AdminSetting::memo_value('implementation_serper_api_key_default'));

        return $value;
    }

    /**
     * Retorna la clave de Serper PROPIA de las demos tal como está guardada, sin caer a la de
     * clientes.
     *
     * El valor se lee desde admin_settings con key 'implementation_serper_api_key_demo'.
     *
     * Es lo que muestra la pantalla de configuración (el GET de a uno y el lote de show_all): el
     * campo de demos tiene que verse vacío cuando no hay una clave propia, que es lo que dice su
     * etiqueta, y guardar el formulario sin tocarlo no puede copiar la de clientes como si fuera de
     * las demos. Para lo que VIAJA a una demo se usa get_serper_api_key_demo(), que sí cae.
     *
     * @return string Clave propia de las demos (o cadena vacía si no hay una).
     */
    public static function get_serper_api_key_demo_stored(): string
    {
        // Leer el valor guardado; trim por el mismo motivo que get_serper_api_key_default().
        $value = trim((string) AdminSetting::memo_value('implementation_serper_api_key_demo'));

        return $value;
    }

    /**
     * Retorna la clave de Serper que se le asigna al User de una demo al correr el demo-setup
     * (DemoSetupHelper en empresa-api): la propia de las demos y, si está vacía, la de clientes.
     *
     * 🔴 La caída a get_serper_api_key_default() es la diferencia con get_google_api_key_demo(), y
     * es a propósito. Con Google la separación protege la cuota diaria de cada key; con Serper hay
     * UNA sola cuenta, así que separar demos de clientes es opcional, y sin la caída cargar una sola
     * clave dejaría a todas las demos sin Serper.
     *
     * Fallback final: cadena vacía '' si no hay ninguna de las dos. En ese caso el demo-setup no
     * manda el campo y la demo usa la SERPER_API_KEY de su .env.
     *
     * @return string Clave para las demos (o cadena vacía si no hay ninguna cargada).
     */
    public static function get_serper_api_key_demo(): string
    {
        // La propia de las demos, si se cargó una.
        $value = self::get_serper_api_key_demo_stored();

        if ($value !== '') {
            return $value;
        }

        // Si no, la de clientes: hay una sola cuenta de Serper.
        return self::get_serper_api_key_default();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Credenciales de PRUEBA de Zipnova y de Mercado Pago para las demos (misión cruzada
    // credenciales-demo-zipnova-mp, 14/10/2026).
    //
    // 🔴 Se parecen a las claves de Serper de arriba en el camino (admin_settings → demo-setup →
    // empresa-api), pero se DIFERENCIAN en cuatro cosas que no hay que "emparejar" de vuelta:
    //
    //  1. El token, el secret y el access token se guardan CIFRADOS (Crypt::encryptString, con la
    //     APP_KEY del admin). La clave de Serper está en texto plano en admin_settings.value; estas
    //     son credenciales de una cuenta de pagos/envíos, y un dump de la tabla no tiene que
    //     bastar para usarlas. El account id y la public key van en claro: el primero es un
    //     número, y la segunda es pública por diseño (viaja en el navegador de cualquier checkout).
    //  2. Ningún GET devuelve el valor: solo el estado y la cola de 4 caracteres.
    //  3. Viajan SOLO en el demo-setup, nunca en el user-setup de un cliente real: son de una
    //     cuenta de demostración.
    //  4. Van de a pares: Zipnova necesita token Y secret, Mercado Pago access token Y public key.
    //     Un par a medias no viaja (el otro lado lo ignoraría, y mandarlo solo ensucia).
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Keys de admin_settings de las credenciales de Zipnova de las demos.
     *
     * @var array<string, string> nombre lógico => key de admin_settings
     */
    const KEYS_ZIPNOVA_DEMO = [
        'api_token'  => 'implementation_zipnova_demo_api_token',
        'api_secret' => 'implementation_zipnova_demo_api_secret',
        'account_id' => 'implementation_zipnova_demo_account_id',
    ];

    /**
     * Keys de admin_settings de las credenciales de Mercado Pago de las demos.
     *
     * @var array<string, string> nombre lógico => key de admin_settings
     */
    const KEYS_MERCADOPAGO_DEMO = [
        'access_token' => 'implementation_mercadopago_demo_access_token',
        'public_key'   => 'implementation_mercadopago_demo_public_key',
    ];

    /**
     * Cuántos caracteres del final se muestran de una credencial ("termina en XXXX").
     *
     * @var int
     */
    const LARGO_DE_LA_COLA = 4;

    /**
     * Largo mínimo de una credencial para mostrar su cola: con una más corta, esos cuatro
     * caracteres serían una parte grande de la credencial. Mismo criterio que el preview de
     * ImplementationActionService.
     *
     * @var int
     */
    const LARGO_MINIMO_PARA_MOSTRAR_LA_COLA = 16;

    /**
     * Texto que reemplaza a una credencial cuando se tapa dentro de un mensaje de error.
     *
     * @var string
     */
    const TEXTO_DE_CREDENCIAL_TAPADA = '[credencial oculta]';

    /**
     * Cifra una credencial para guardarla en admin_settings.
     *
     * Es el único punto de escritura cifrada: el controlador no llama a Crypt por su cuenta, así
     * lector y escritor no pueden desincronizarse (por ejemplo, uno cifrando con encrypt() y el
     * otro leyendo con decryptString()).
     *
     * @param string $valor Credencial en claro.
     *
     * @return string Valor cifrado, listo para AdminSetting::set().
     */
    public static function cifrar_credencial(string $valor): string
    {
        return Crypt::encryptString($valor);
    }

    /**
     * Borra las filas de admin_settings de un grupo de credenciales (Zipnova o Mercado Pago).
     *
     * Se borra por el modelo, fila por fila, y no con un delete() masivo del query builder: el
     * masivo no dispara los eventos del modelo y el memo por request quedaría con el valor viejo
     * (ver AdminSetting::boot()). Lo que no tiene fila no es un error: borrar lo ya borrado es un no-op.
     *
     * @param array<string, string> $keys Una de las constantes KEYS_*_DEMO (nombre lógico => key).
     *
     * @return void
     */
    public static function borrar_credenciales(array $keys): void
    {
        foreach (AdminSetting::whereIn('key', array_values($keys))->get() as $fila) {
            $fila->delete();
        }
    }

    /**
     * Lee una credencial CIFRADA de admin_settings y la devuelve descifrada.
    /**
     * Lee una credencial CIFRADA de admin_settings y la devuelve descifrada.
     *
     * 🔴 Nunca explota ni devuelve el texto cifrado: si el valor guardado no se puede descifrar
     * (la APP_KEY del admin cambió, o alguien escribió texto plano a mano en la fila) se lee como
     * cadena vacía y se avisa con `ilegible`. Explotar acá rompería el armado del payload del
     * demo-setup entero por una credencial opcional; devolver el texto cifrado la mandaría a la
     * demo como si fuera la credencial.
     *
     * No se pasa el mensaje de la excepción a ningún lado: puede incluir fragmentos del valor.
     *
     * @param string $key Key de admin_settings.
     *
     * @return array{valor: string, ilegible: bool}
     */
    private static function leer_credencial_cifrada(string $key): array
    {
        $crudo = trim((string) AdminSetting::memo_value($key));

        // Sin fila o vacía: no hay nada cargado, y eso no es un error.
        if ($crudo === '') {
            return ['valor' => '', 'ilegible' => false];
        }

        try {
            return ['valor' => trim(Crypt::decryptString($crudo)), 'ilegible' => false];
        } catch (\Throwable $e) {
            return ['valor' => '', 'ilegible' => true];
        }
    }

    /**
     * Lee una credencial guardada en claro (account id, public key), recortada.
     *
     * @param string $key Key de admin_settings.
     *
     * @return string
     */
    private static function leer_credencial_en_claro(string $key): string
    {
        return trim((string) AdminSetting::memo_value($key));
    }

    /**
     * Últimos caracteres de una credencial, para que se pueda reconocer cuál está cargada sin
     * exponerla. Null si no hay credencial o si es tan corta que la cola sería una parte grande.
     *
     * @param string $valor Credencial en claro.
     *
     * @return string|null
     */
    private static function cola_de($valor)
    {
        if (strlen($valor) < self::LARGO_MINIMO_PARA_MOSTRAR_LA_COLA) {
            return null;
        }

        return substr($valor, -self::LARGO_DE_LA_COLA);
    }

    /**
     * Credenciales de Zipnova de las demos, descifradas. Cualquier campo ausente (o ilegible) es ''.
     *
     * Es lo que usan el armado del payload del demo-setup y el botón "probar". Nunca lo devuelve
     * una ruta GET: esas usan estado_zipnova_demo().
     *
     * @return array{api_token: string, api_secret: string, account_id: string}
     */
    public static function get_zipnova_demo_credenciales(): array
    {
        return [
            'api_token'  => self::leer_credencial_cifrada(self::KEYS_ZIPNOVA_DEMO['api_token'])['valor'],
            'api_secret' => self::leer_credencial_cifrada(self::KEYS_ZIPNOVA_DEMO['api_secret'])['valor'],
            'account_id' => self::leer_credencial_en_claro(self::KEYS_ZIPNOVA_DEMO['account_id']),
        ];
    }

    /**
     * Credenciales de Mercado Pago de las demos, descifradas. Cualquier campo ausente (o ilegible) es ''.
     *
     * @return array{access_token: string, public_key: string}
     */
    public static function get_mercadopago_demo_credenciales(): array
    {
        return [
            'access_token' => self::leer_credencial_cifrada(self::KEYS_MERCADOPAGO_DEMO['access_token'])['valor'],
            'public_key'   => self::leer_credencial_en_claro(self::KEYS_MERCADOPAGO_DEMO['public_key']),
        ];
    }

    /**
     * Estado de las credenciales de Zipnova de las demos, SIN el valor: es el cuerpo de los GET y
     * de la entrada del lote de show_all.
     *
     *  - cargada: hay token Y secret legibles (el par completo; solo así viajan).
     *  - api_token_cola: los últimos 4 caracteres del token (null si no hay o es muy corto).
     *  - api_secret_cargado: hay un secret legible. Del secret no se muestra ni la cola.
     *  - account_id: el id de la cuenta, si se cargó (no es secreto).
     *  - ilegible: hay algo guardado que no se pudo descifrar (APP_KEY distinta): hay que volver a cargarlo.
     *
     * @return array{cargada: bool, api_token_cola: string|null, api_secret_cargado: bool, account_id: string, ilegible: bool}
     */
    public static function estado_zipnova_demo(): array
    {
        $token  = self::leer_credencial_cifrada(self::KEYS_ZIPNOVA_DEMO['api_token']);
        $secret = self::leer_credencial_cifrada(self::KEYS_ZIPNOVA_DEMO['api_secret']);

        return [
            'cargada'            => $token['valor'] !== '' && $secret['valor'] !== '',
            'api_token_cola'     => self::cola_de($token['valor']),
            'api_secret_cargado' => $secret['valor'] !== '',
            'account_id'         => self::leer_credencial_en_claro(self::KEYS_ZIPNOVA_DEMO['account_id']),
            'ilegible'           => $token['ilegible'] || $secret['ilegible'],
        ];
    }

    /**
     * Estado de las credenciales de Mercado Pago de las demos, SIN el valor completo.
     *
     *  - cargada: hay access token Y public key (el par completo; solo así viajan).
     *  - access_token_cola / public_key_cola: los últimos 4 caracteres (null si no hay o es muy corta).
     *  - public_key_cargada: hay una public key.
     *  - tipo_access_token: 'TEST' o 'APP_USR' según el prefijo, null si no hay. Lo único del access
     *    token que se muestra además de la cola: deja ver de un vistazo si en las demos hay una
     *    credencial de prueba o una de producción.
     *  - ilegible: hay un access token guardado que no se pudo descifrar.
     *
     * @return array{cargada: bool, access_token_cola: string|null, public_key_cola: string|null, public_key_cargada: bool, tipo_access_token: string|null, ilegible: bool}
     */
    public static function estado_mercadopago_demo(): array
    {
        $access     = self::leer_credencial_cifrada(self::KEYS_MERCADOPAGO_DEMO['access_token']);
        $public_key = self::leer_credencial_en_claro(self::KEYS_MERCADOPAGO_DEMO['public_key']);

        // Solo el prefijo, nunca más: TEST- o APP_USR-.
        $tipo = null;
        if (strpos($access['valor'], 'TEST-') === 0) {
            $tipo = 'TEST';
        } elseif (strpos($access['valor'], 'APP_USR-') === 0) {
            $tipo = 'APP_USR';
        }

        return [
            'cargada'            => $access['valor'] !== '' && $public_key !== '',
            'access_token_cola'  => self::cola_de($access['valor']),
            'public_key_cola'    => self::cola_de($public_key),
            'public_key_cargada' => $public_key !== '',
            'tipo_access_token'  => $tipo,
            'ilegible'           => $access['ilegible'],
        ];
    }

    /**
     * Campos de credenciales de prueba que viajan en el payload del demo-setup.
     *
     * 🔴 Es la ÚNICA fuente de esos campos: los dos armados del demo-setup (build_payload con lead
     * y payload_de_defaults sin lead) llaman acá, y nadie más. En particular no lo llama ningún
     * armado de user-setup: son credenciales de demostración y no pueden llegar a un cliente real.
     *
     * Cada integración viaja solo si está COMPLETA: Zipnova con token y secret, Mercado Pago con
     * access token y public key. Un par a medias no manda nada (ni el que sí está): el otro lado
     * también lo ignora, y así el contrato es "o están los dos o no hay ninguno". El
     * `zipnova_account_id` es opcional y solo viaja junto al par de Zipnova.
     *
     * Sin nada cargado devuelve [] y el payload queda IDÉNTICO al de antes de esta misión.
     *
     * @return array<string, string>
     */
    public static function get_credenciales_de_demo_para_el_payload(): array
    {
        $campos = [];

        // Zipnova: el par completo, y el id de cuenta si lo hay.
        $zipnova = self::get_zipnova_demo_credenciales();
        if ($zipnova['api_token'] !== '' && $zipnova['api_secret'] !== '') {
            $campos['zipnova_api_token']  = $zipnova['api_token'];
            $campos['zipnova_api_secret'] = $zipnova['api_secret'];

            if ($zipnova['account_id'] !== '') {
                $campos['zipnova_account_id'] = $zipnova['account_id'];
            }
        }

        // Mercado Pago: el par completo.
        $mercadopago = self::get_mercadopago_demo_credenciales();
        if ($mercadopago['access_token'] !== '' && $mercadopago['public_key'] !== '') {
            $campos['mercadopago_access_token'] = $mercadopago['access_token'];
            $campos['mercadopago_public_key']   = $mercadopago['public_key'];
        }

        return $campos;
    }

    /**
     * Tapa, dentro de un texto, cualquier credencial de demo que esté cargada en el admin.
     *
     * Es la red de seguridad de los lugares donde un texto del otro lado (el cuerpo de un error de
     * empresa-api, el mensaje de una excepción) termina guardado en una columna (`leads.demo_setup_last_error`,
     * el log de una instalación de demo) o en un log: si por algún camino que hoy no existe ese
     * texto trajera el token, el secret, el access token o la public key de las demos, no se
     * persiste. empresa-api ya tapa lo suyo (SetupErrorHelper); esto cubre el borde de este lado
     * sin depender de que el otro esté al día.
     *
     * Si no se puede consultar el admin_settings (por ejemplo, la base caída) el texto NO se
     * devuelve tal cual: se reemplaza por un aviso, porque esta función existe justamente para no
     * dejar pasar lo que no pudo verificar.
     *
     * @param string $texto Texto que se va a guardar o loguear.
     *
     * @return string El mismo texto con las credenciales cargadas reemplazadas.
     */
    public static function tapar_credenciales_de_demo(string $texto): string
    {
        if ($texto === '') {
            return $texto;
        }

        try {
            $zipnova     = self::get_zipnova_demo_credenciales();
            $mercadopago = self::get_mercadopago_demo_credenciales();
        } catch (\Throwable $e) {
            return '[texto omitido: no se pudo verificar que no contuviera credenciales]';
        }

        // Los cuatro valores sensibles. El account id no: es un número, y tapar "1234" dentro de
        // cualquier texto lo arruinaría sin proteger nada.
        $valores = [
            $zipnova['api_token'],
            $zipnova['api_secret'],
            $mercadopago['access_token'],
            $mercadopago['public_key'],
        ];

        foreach ($valores as $valor) {
            // Un valor muy corto no es una credencial real y taparlo desfiguraría el mensaje.
            if (strlen($valor) >= 6) {
                $texto = str_replace($valor, self::TEXTO_DE_CREDENCIAL_TAPADA, $texto);
            }
        }

        return $texto;
    }
}

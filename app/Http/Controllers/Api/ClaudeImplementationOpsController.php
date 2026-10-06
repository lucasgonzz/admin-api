<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ImplementacionMailException;
use App\Helpers\WhatsappNormalizer;
use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Jobs\EjecutarInstalacionDeImplementacionJob;
use App\Jobs\EjecutarUserSetupDeImplementacionJob;
use App\Mail\Helpers\ImplementacionMailHelper;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\ClientSshCredential;
use App\Models\ClientVersionUpgrade;
use App\Models\DeploymentLog;
use App\Models\EnvTemplate;
use App\Models\Implementation;
use App\Models\ImplementationMail;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\ImplementationStageConfig;
use App\Models\Lead;
use App\Models\Version;
use App\Services\ClientEmpresaApiUrlResolver;
use App\Services\HostingProvisioningStructure;
use App\Services\ImplementacionMailService;
use App\Services\ImplementationActionService;
use App\Services\ImplementationBroadcastService;
use App\Services\ImplementationFormMapper;
use App\Services\ImplementationSettings;
use App\Services\ImplementationStartService;
use App\Services\PromoteLeadToClientService;
use App\Services\SubdomainSuggestionService;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Operar las IMPLEMENTACIONES de clientes nuevos desde `claude/*` (misión `implementar-cliente`,
 * 5/10/2026): dar de alta el cliente a partir de un lead, mirar en qué etapa está, avanzarla,
 * registrar lo que se hizo por fuera, instalar el sistema, configurarlo con lo que el cliente cargó
 * en el formulario y mandarle el mail de cada hito.
 *
 * 🔴 POR QUÉ EXISTE. Hasta acá nada de esto tenía puerta `claude/*`: todo vivía bajo `api/admin/*`
 * con `auth:sanctum`, que la clave `X-Claude-Task-Key` no abre, y lo único que Claude podía hacer
 * sobre una implementación era LEERla por `GET claude/query`. Lucas pidió dejar de operarlas a
 * mano desde el panel y llevarlas con una skill (`/implementar`); esta es la mitad del admin de ese
 * pedido.
 *
 * 🔴 LAS REGLAS DEL BLOQUE, APLICADAS ACÁ (son las de siempre de `claude/*`, no hay una nueva):
 *  - Lista blanca CERRADA de parámetros: uno desconocido es 422 y no se escribe nada. Un parámetro
 *    que el endpoint no entiende suele ser alguien esperando que haga algo que no hace.
 *  - `dry_run` es true POR DEFECTO en toda escritura que toca algo importante: la primera llamada
 *    devuelve lo que haría. Para escribir hay que decir `dry_run=false` explícito.
 *  - Confirmación por nombre en lo que toca el sistema de un cliente (`confirm_client_name`, o
 *    `confirm_nombre` en el alta, donde todavía no hay cliente). El error nunca revela el nombre
 *    correcto: es un freno, no un formulario a completar.
 *  - Todo lo largo se ENCOLA con `->onConnection('database')` y se contesta 202. 🔴 Sin la conexión
 *    explícita, `QUEUE_CONNECTION=sync` correría el pipeline entero adentro del request y lo
 *    mataría `max_execution_time` con un fatal que ningún `catch` ve.
 *  - Las reglas de negocio NO se reescriben: se delega en los servicios del panel
 *    (`ImplementationStartService`, `PromoteLeadToClientService`, `InstallationService`,
 *    `ImplementationUserSetupService`...). Dos definiciones de "así se instala un cliente" se
 *    desincronizan sin que nada lo denuncie.
 *
 * 🔴 EL PANEL NO CAMBIA DE COMPORTAMIENTO. Lo único que se tocó de su lado fue extraer el `start`
 * a un servicio y darle un parámetro opcional al user setup. Todo lo demás es aditivo.
 *
 * 🔴 LO QUE ESTE CONTROLADOR NUNCA HACE (cada una está escrita donde corresponde):
 *  - Re-aplicar el user setup. Del otro lado el setup arranca con `migrate:fresh --force` y le
 *    vacía la base al cliente; el panel ofrece "forzar" y esta puerta NO: con el candado lleno es 422
 *    sin vuelta, y solo se aplica en la etapa 2. Lo único que hay es una salida tras un ERROR del
 *    job —que casi nunca prueba que no corrió—: `reintentar` (vuelve a despachar) o `conciliar` (el
 *    dueño ya existe: lo da por aplicado SIN llamar al cliente), excluyentes. Si de verdad hace falta
 *    re-aplicarlo, se hace desde el panel, con una persona mirando.
 *  - Disparar `handle_stage_advance` al avanzar de etapa. En este camino la instalación la crea
 *    `install`, no la entrada a la etapa 2.
 *  - Mandar un WhatsApp. Los mensajes a clientes salen por WhatsApp Web, desde la skill y con el
 *    ok de Lucas; acá solo se REGISTRAN (`actions`).
 */
class ClaudeImplementationOpsController extends Controller
{
    /**
     * Los helpers genéricos del bloque `claude/*`: la validación que siempre contesta JSON, las
     * respuestas 422/404 con la forma única del bloque, el freno del nombre y los lectores de
     * parámetros. 🔴 Se usan del trait y no se copian.
     *
     * El método de los mensajes de validación se reimporta con otro nombre porque este controlador
     * usa reglas que el trait no traduce (`regex`, `email`, `required_without`...) y UNA REGLA QUE
     * NO TIENE MENSAJE EN ESPAÑOL CONTESTA EN INGLÉS, que es silencioso: ver
     * `mensajes_de_validacion()` más abajo.
     */
    use RespuestasParaClaude {
        mensajes_de_validacion as protected mensajes_de_validacion_base;
    }

    /** Conexión de cola de TODOS los dispatch de este controlador. 🔴 Explícita, sin excepción. */
    const CONEXION_DE_COLA = 'database';

    /**
     * Latencia máxima esperable entre el encolado y el arranque real del job, en segundos. El
     * scheduler dispara `queue:work database --stop-when-empty` cada minuto: ese es el peor caso de
     * espera antes de ver movimiento. Mismo número y mismo motivo que en los otros controladores.
     */
    const LATENCIA_MAXIMA_SEGUNDOS = 60;

    /**
     * Las ocho etapas de una implementación y su nombre, por si el catálogo de la base
     * (`implementation_stage_configs`) no las trae. El nombre que manda es el de la base; esto es
     * la red para una base recién creada, sin seeders.
     *
     * @var array<int, string>
     */
    const ETAPAS = [
        1 => 'Información de la empresa',
        2 => 'Instalación del sistema',
        3 => 'Recolección de archivos',
        4 => 'Migración de datos',
        5 => 'Entrega del sistema',
        6 => 'Capacitación',
        7 => 'Vinculación ARCA/AFIP',
        8 => 'Videollamada de capacitación',
    ];

    /**
     * Endpoint de empresa-api que dice qué versión tiene activa un sistema (`default_version`). Se consulta
     * en el dry-run de `install` como señal ADICIONAL de que ya hay un sistema andando en la API del cliente.
     */
    const RUTA_DE_LA_VERSION_ACTIVA = '/api/version-activa';

    /** Techo, en segundos, de esa consulta: un dry-run no se cuelga esperando a una API que no responde. */
    const TIMEOUT_DE_LA_SENAL_DE_VERSION = 5;

    /** Lo que se puede pedir con `include` en la lectura del estado. */
    const INCLUDES_DEL_ESTADO = ['contacto', 'formulario', 'logs'];

    /**
     * Lista blanca del alta (`POST claude/implementations`). Cualquier otra clave es 422 y no se
     * escribe nada: un parámetro que el endpoint no entiende (un `force`, un `automation_mode`, un
     * `phone`) suele ser alguien esperando que haga algo que no hace.
     */
    const PARAMETROS_DEL_ALTA = ['lead_id', 'client_id', 'subdominio', 'dry_run', 'confirm_nombre'];

    /**
     * El subdominio de las URLs de un cliente nuevo: de 1 a 20 caracteres de `[a-z0-9-]`, empezando por
     * letra o número. El mismo patrón y el mismo tope de 20 que `SubdomainSuggestionService` y que la
     * guarda 3 de `HostingProvisioningStructure`: si éste dejara pasar algo que aquella rechaza, la
     * instalación fallaría a los quince minutos y no ahora.
     */
    const PATRON_DE_SUBDOMINIO = '/^[a-z0-9][a-z0-9-]{0,19}$/';

    /**
     * Subdominios que no puede tener un cliente porque ya existen en `comerciocity.com` o los usa la
     * plataforma: `admin` es el panel (admin.comerciocity.com), `api` es la API pública que sirve el
     * isotipo de los mails, `tienda` es la del ecommerce, y el resto son nombres que Hostinger o los
     * clientes de correo (`imap`, `pop`, `pop3`, `smtp`, `autodiscover`) y de hosting (`cpanel`, `ftp`,
     * `localhost`) reservan. Un cliente llamado "admin" no chocaría con ninguna `client_api` —por eso no
     * lo ve el chequeo de unicidad— y en cambio pisaría el panel de ComercioCity en la zona DNS.
     *
     * @var array<int, string>
     */
    const SUBDOMINIOS_RESERVADOS = [
        'admin', 'api', 'www', 'mail', 'smtp', 'ftp', 'webmail', 'demo', 'app', 'soporte',
        'tienda', 'imap', 'pop', 'pop3', 'cpanel', 'autodiscover', 'localhost',
    ];

    /**
     * Los reservados que se NUMERAN: `demo` o `ns` seguidos de dígitos (`demo2`, `ns3`). Las demos de la
     * plataforma (`demo`, `demo2`, `demo3`...) y los servidores de nombres (`ns1`, `ns2`...) crecen en
     * cantidad, y una lista fija se queda corta el día que aparece la siguiente.
     */
    const PATRON_DE_RESERVADOS_NUMERADOS = '/^(demo|ns)[0-9]+$/';

    /**
     * Largo máximo de un nombre de base de datos en MySQL: la base de un cliente se llama
     * `<prefijo de la cuenta>_<subdominio>`.
     */
    const TOPE_NOMBRE_DE_BASE = 32;

    /**
     * Segundos que se espera el lock global de altas antes de rendirse, y segundos que vive ese lock.
     * El lock serializa las PROMOCIONES (no las implementaciones): el bloque de `user_id` que se le
     * asigna a cada cliente nuevo se calcula y se reserva sin lock en `UserIdBlockAllocatorService`, así
     * que dos promociones simultáneas podrían pedir el mismo bloque.
     */
    const LOCK_DE_ALTAS_ESPERA = 10;
    const LOCK_DE_ALTAS_TTL    = 120;

    /**
     * Lista blanca de `POST claude/implementations/{id}/advance`. Cualquier otra clave es 422: un
     * `forzar`, un `a_etapa` o un `handle_stage_advance` suelen ser alguien esperando que este endpoint
     * salte etapas o dispare lo que el panel dispara, y no lo hace.
     */
    const PARAMETROS_DEL_AVANCE = ['etapa_actual', 'saltar', 'nota', 'dry_run'];

    /**
     * Lista blanca de `POST claude/implementations/{id}/actions`. Cualquier otra clave es 422: un
     * `content`, un `enviar` o un `force` suelen ser alguien esperando que este endpoint MANDE el
     * mensaje, y no lo manda: solo registra que ya salió.
     */
    const PARAMETROS_DE_LA_ACCION = ['accion', 'canal', 'texto', 'telefono', 'etapa'];

    /**
     * Las acciones que se pueden registrar. Las cinco primeras son las acciones con mensaje del panel
     * (`presentacion`, `form_link`, `progreso`, `pedir_archivos` y `entrega`; las que lee el checklist de
     * la SPA); `acceso`, `imagenes`, `categorias`, `descripciones` y `listo` son los hitos de la
     * migración y la entrega que el panel no tiene; `nota` es una anotación libre. `user_setup` y
     * `crear_instalacion` NO están a propósito: las escriben sus propios endpoints.
     *
     * @var array<int, string>
     */
    const ACCIONES = ['presentacion', 'form_link', 'progreso', 'pedir_archivos', 'entrega', 'acceso', 'imagenes', 'categorias', 'descripciones', 'listo', 'nota'];

    /**
     * Por dónde se hizo lo que se registra. `whatsapp_web` es el único que además crea el saliente del
     * hilo.
     *
     * @var array<int, string>
     */
    const CANALES = ['whatsapp_web', 'mail', 'llamada', 'otro'];

    /**
     * Ventana de idempotencia de `actions`, en minutos: la misma acción por el mismo canal con el mismo
     * texto, dentro de este tiempo, no se registra dos veces.
     */
    const MINUTOS_DE_IDEMPOTENCIA = 10;

    /**
     * Lista blanca de `POST claude/implementations/{id}/install`. Cualquier otra clave es 422: un `version`,
     * un `provision_hosting_type`, un `kind` o un `force` suelen ser alguien esperando elegir lo que acá
     * está fijado a propósito (la última versión publicada, el hosting compartido, el par real + esqueleto).
     */
    const PARAMETROS_DE_LA_INSTALACION = ['dry_run', 'confirm_client_name', 'marcar_colgadas'];

    /**
     * Minutos sin actividad después de los cuales una instalación que dice `instalando` se da por colgada.
     *
     * "Actividad" es lo último de: que haya arrancado (`started_at`), que alguien haya escrito la fila
     * (`updated_at`) o el último renglón de log de la instalación (`deployment_logs`). Una instalación sana
     * tarda ~15 minutos (la peor medida, ~28) y el pipeline escribe logs a cada paso; el `$timeout` del job
     * son 38 minutos. A los 60 sin ninguna de las tres cosas, el job no está corriendo: el worker murió con la
     * fila `instalando` (un `kill -9`, un reinicio, un `$timeout` sin `pcntl`) y nada la va a terminar.
     */
    const MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION = 60;

    /**
     * Lista blanca de `POST claude/implementations/{id}/user-setup`. 🔴 Sin `force` ni `forzar` A
     * PROPÓSITO: un parámetro de más es 422 y no se aplica nada. Re-aplicar el user setup le vacía la base
     * al cliente.
     */
    const PARAMETROS_DEL_USER_SETUP = ['dry_run', 'confirm_client_name', 'include', 'reintentar', 'conciliar'];

    /**
     * Lo que se puede pedir con `include` en el dry-run del user setup: `contacto` muestra enteros el mail, el
     * documento y el teléfono del dueño que lleva el payload (por defecto salen enmascarados).
     */
    const INCLUDES_DEL_USER_SETUP = ['contacto'];

    /**
     * La nota que deja una conciliación del user setup (`conciliar: true`) en el registro de la etapa 2.
     * Es textual a propósito: es lo que la skill y el panel leen para saber que el candado se llenó SIN
     * que este camino llamara al cliente.
     */
    const NOTA_DE_CONCILIACION = 'conciliado: el dueño ya existía en el sistema del cliente';

    /**
     * Valores estándar de las variables de conexión del `.env` en el hosting compartido, para cuando la
     * plantilla de variables no trae un valor: el MySQL local de la cuenta. Son las tres que el panel exige
     * cargar a mano y que no dependen del cliente (`DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` las genera
     * el aprovisionamiento).
     *
     * @var array<string, string>
     */
    const VALORES_ESTANDAR_DE_CONEXION = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306'];

    /**
     * Lista blanca de `POST claude/implementations/{id}/mail`. Cualquier otra clave es 422: un `para`, un
     * `asunto`, un `html` o un `from` suelen ser alguien esperando armar el mail a mano, y el mail de
     * cada hito lo arma el servicio (el texto, la casilla y el remitente no se eligen desde afuera).
     */
    const PARAMETROS_DEL_MAIL = ['hito', 'datos', 'email', 'reenviar', 'dry_run', 'confirm_client_name'];

    /** Lista blanca de la lectura del estado por id. */
    const PARAMETROS_DE_LA_LECTURA = ['include'];

    /** Lista blanca de la lectura del estado por cliente o por lead. */
    const PARAMETROS_DE_LA_BUSQUEDA = ['client_id', 'lead_id', 'include'];

    /** Cuántas líneas de log de cada instalación se devuelven con `include=logs`. */
    const LINEAS_DE_LOG = 40;

    /** Recorte de cada línea de log y del motivo de fallo de una instalación, en caracteres. */
    const CARACTERES_POR_LINEA = 500;

    /**
     * Minutos después de los cuales un user setup que dice `en_curso` se da por colgado.
     *
     * 🔴 Existe porque `en_curso` es un estado que escribe el endpoint ANTES de despachar el job y
     * que el job reemplaza por `ok` o `error` al terminar. Si el worker muere sin pasar ni por `handle()` ni por `failed()`
     * (un `kill -9`, un reinicio del servidor) el estado se queda en `en_curso` PARA SIEMPRE, y como
     * `user-setup` frena con 409 mientras haya uno en curso, la implementación quedaría trabada sin
     * que ninguna llamada pueda destrabarla. 45 minutos son casi el doble del techo del job (1500 s) y
     * quedan por encima del `retry_after` de la cola (2400 s): para entonces el worker ya tuvo ocasión
     * de recuperar el job y descartarlo. Pasado ese tiempo el estado se REPORTA igual (con
     * `colgado: true`) y el endpoint deja volver a intentar.
     *
     * ⚠️ "Colgado" es "no hubo señal en 45 minutos", NO "el job no pudo seguir vivo": no se sabe qué pasó
     * con el proceso, y afirmarlo sería mentir. Lo que hace seguro reintentar es otra cosa: cada intento
     * lleva un token (su `iniciado_at`) y un job viejo que arranque tarde se descarta solo, sin llamar al
     * cliente, porque el registro ya es de otro intento. Y del otro lado empresa-api toma un candado y
     * contesta 409 si hay otro corriendo, y ese 409 vuelve como error, sin reintento.
     */
    const MINUTOS_PARA_DAR_POR_COLGADO = 45;

    /**
     * Mensajes de validación en español: los del trait MÁS las reglas que este controlador usa y el
     * trait no traduce.
     *
     * 🔴 No sacar. Sin esto, una regla como `regex` o `required_without` que falle contesta en inglés
     * ("The subdominio format is invalid."), y es silencioso: nadie se entera hasta que un error le
     * llega a Lucas en otro idioma. Es la misma trampa que el trait documenta para `exists` y `array`.
     *
     * El mensaje de `boolean` se REEMPLAZA (el del trait dice "booleano (1, 0, true o false)"): la regla de
     * Laravel acepta `true`, `false`, `1` y `0` pero NO el texto `"false"` o `"true"` entre comillas, que da 422,
     * y un mensaje que lista "true o false" manda al que lo recibe a probar con lo que acaba de mandar. Acá se
     * dice que van sin comillas y que el texto no se acepta. No se aceptan los textos a propósito: en una
     * escritura no se adivina qué quiso decir quien mandó un texto donde va un booleano (`"dry_run": "false"`
     * leído de más es un real que nadie pidió). El trait y los demás controladores del bloque no se tocan.
     *
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return array_merge($this->mensajes_de_validacion_base(), [
            'boolean'          => 'El parámetro :attribute tiene que ser un booleano de JSON (true o false, sin comillas) o 1 o 0: el texto "true" o "false" '
                . 'entre comillas no se acepta.',
            'required_without' => 'El parámetro :attribute es obligatorio si no mandás :values.',
            'required_if'      => 'El parámetro :attribute es obligatorio cuando :other es :value.',
            'regex'            => 'El parámetro :attribute no tiene un formato válido.',
            'email'            => 'El parámetro :attribute tiene que ser una casilla de mail válida.',
            'between'          => 'El parámetro :attribute tiene que estar entre :min y :max.',
            'url'              => 'El parámetro :attribute tiene que ser una URL válida.',
        ]);
    }

    /* ==============================================================================================
     | 1) GET claude/implementations/{id} y GET claude/implementations?client_id=|lead_id= — ESTADO
     |============================================================================================= */

    /**
     * El estado completo de UNA implementación, por su id.
     *
     * 🔴 Es lo que la skill mira en cada paso, y por eso trae todo junto (una llamada grande en vez de
     * seis chicas: el limitador de tasa agrupa por IP y `claude/*` nunca tiene usuario): la etapa en
     * la que está, las instalaciones y cómo van, si el user setup corrió, los mails que ya salieron y
     * si el cliente escribió algo que nadie contestó.
     *
     * Los datos de contacto y el resumen del formulario NO viajan por defecto: se piden con
     * `include=contacto` y `include=formulario`. El formulario trae datos personales (el DNI y el
     * teléfono de los empleados, el mail y el CUIT del dueño) y esta lectura la hace una sesión de
     * Claude: lo que no hace falta para decidir el paso no tiene por qué pasar por ahí.
     *
     * @param Request $request Query: include (csv de contacto, formulario, logs).
     * @param int|string $id   Id de la implementación (segmento de la URL).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show_json(Request $request, $id)
    {
        $rechazo = $this->rechazar_parametros_de_mas($request, self::PARAMETROS_DE_LA_LECTURA, 'GET claude/implementations/{id}');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $includes = $this->resolver_includes($request, self::INCLUDES_DEL_ESTADO);
        if (! is_array($includes)) {
            return $includes;
        }

        $implementation = Implementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación ' . (int) $id);
        }

        return response()->json($this->payload_de_estado($implementation, $includes), 200);
    }

    /**
     * El estado de la implementación de un cliente o de un lead, sin conocer el id de la
     * implementación.
     *
     * 🔴 Existe porque la skill arranca con el nombre de un negocio o un lead, no con un id de
     * implementación, y porque un lead que ya fue promovido pero todavía no tiene implementación es un
     * caso que tiene que poder preguntar sin que le salga un 500.
     *
     * Con `lead_id` se resuelve por el cliente al que se promovió ese lead. Exactamente UNO de los dos
     * parámetros: mandar los dos es ambiguo (podrían apuntar a negocios distintos) y no mandar ninguno
     * no dice qué buscar.
     *
     * @param Request $request Query: client_id o lead_id (uno solo), include.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        $rechazo = $this->rechazar_parametros_de_mas($request, self::PARAMETROS_DE_LA_BUSQUEDA, 'GET claude/implementations');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $invalido = $this->validar_o_422($request, [
            'client_id' => 'nullable|integer|min:1',
            'lead_id'   => 'nullable|integer|min:1',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $includes = $this->resolver_includes($request, self::INCLUDES_DEL_ESTADO);
        if (! is_array($includes)) {
            return $includes;
        }

        $client_id = $this->entero_o_null($request->input('client_id'));
        $lead_id   = $this->entero_o_null($request->input('lead_id'));

        if (($client_id === null) === ($lead_id === null)) {
            return $this->error_422(
                'Mandá exactamente uno de client_id o lead_id. Los dos juntos son ambiguos (pueden apuntar a '
                    . 'negocios distintos) y ninguno no dice qué buscar.',
                ['parametros_aceptados' => self::PARAMETROS_DE_LA_BUSQUEDA]
            );
        }

        if ($lead_id !== null) {
            $lead = Lead::find($lead_id);
            if ($lead === null) {
                return $this->error_404('no existe el lead ' . $lead_id);
            }

            if (empty($lead->promoted_client_id)) {
                return $this->error_404(
                    'el lead ' . $lead_id . ' todavía no se promovió a cliente, así que no tiene implementación. '
                        . 'POST claude/implementations lo promueve y la inicia.'
                );
            }

            $client_id = (int) $lead->promoted_client_id;
        }

        $client = Client::find($client_id);
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . $client_id);
        }

        $implementation = Implementation::where('client_id', $client->id)->orderByDesc('id')->first();
        if ($implementation === null) {
            return $this->error_404(
                'el cliente ' . $client_id . ' no tiene implementación. POST claude/implementations la inicia.'
            );
        }

        return response()->json($this->payload_de_estado($implementation, $includes), 200);
    }

    /**
     * Arma el estado completo de una implementación.
     *
     * 🔴 SE ARMA CAMPO POR CAMPO Y NO SERIALIZANDO LOS MODELOS. `Client` no oculta `api_key` ni
     * `inbound_api_key` y `Implementation` expone el `form_token`: un `toArray()` de cualquiera de los
     * dos sacaría credenciales a una respuesta que una sesión de Claude después pega en una
     * conversación. Lo que no está en esta lista no sale.
     *
     * @param Implementation     $implementation La implementación.
     * @param array<int, string> $includes       contacto | formulario | logs.
     *
     * @return array<string, mixed>
     */
    protected function payload_de_estado(Implementation $implementation, array $includes)
    {
        $implementation->loadMissing(['client', 'stages']);

        $client         = $implementation->client;
        $con_contacto   = in_array('contacto', $includes, true);
        $con_formulario = in_array('formulario', $includes, true);
        $con_logs       = in_array('logs', $includes, true);

        $respuesta = [
            'implementation' => $this->bloque_de_la_implementacion($implementation),
            'cliente'        => $client === null ? null : $this->bloque_del_cliente($client, $implementation, $con_contacto),
            'etapas'         => $this->bloque_de_etapas($implementation),
        ];

        /* El resumen del formulario va solo si lo pidieron, y sin datos personales salvo que también
           pidan el contacto: ver el docblock de show_json(). */
        if ($con_formulario) {
            $respuesta['formulario'] = $this->bloque_del_formulario($implementation, $con_contacto);
        }

        $respuesta['user_setup']    = $this->estado_del_user_setup($implementation);
        $respuesta['instalaciones'] = $client === null ? [] : $this->bloque_de_instalaciones($client, $con_logs);
        $respuesta['mails']         = $this->bloque_de_mails($implementation);
        $respuesta['entrantes']     = $this->bloque_de_entrantes($implementation);

        return $respuesta;
    }

    /**
     * El bloque `implementation` del estado: la fila, sin el token pelado.
     *
     * El `form_link` SÍ viaja (es lo que la skill le manda al cliente) y es la única forma en que
     * sale el token. Es null si todavía no hay token o si la URL base del formulario no está cargada
     * en los settings; en ese caso las respuestas que lo necesitan lo avisan.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<string, mixed>
     */
    protected function bloque_de_la_implementacion(Implementation $implementation)
    {
        return [
            'id'                     => (int) $implementation->id,
            'client_id'              => (int) $implementation->client_id,
            'current_stage'          => (int) $implementation->current_stage,
            'status'                 => (string) $implementation->status,
            'automation_mode'        => (string) $implementation->automation_mode,
            'started_at'             => $this->instante($implementation->started_at),
            'completed_at'           => $this->instante($implementation->completed_at),
            'form_submitted_at'      => $this->instante($implementation->form_submitted_at),
            'user_setup_executed_at' => $this->instante($implementation->user_setup_executed_at),
            'form_link'              => $implementation->form_link,
        ];
    }

    /**
     * El bloque `cliente`: quién es y dónde vive su sistema.
     *
     * Las URLs salen de `ClientEmpresaApiUrlResolver`, la misma que usa todo el admin para hablarle al
     * sistema del cliente: normaliza (agrega `/public` en el hosting compartido, por ejemplo). No se
     * lee la columna cruda de `client_apis` porque ahí la URL se guarda sin normalizar y quien la
     * usara tal cual se caería.
     *
     * @param Client         $client         El cliente.
     * @param Implementation $implementation Su implementación (para el teléfono del responsable).
     * @param bool           $con_contacto   true = incluir los datos de contacto.
     *
     * @return array<string, mixed>
     */
    protected function bloque_del_cliente(Client $client, Implementation $implementation, $con_contacto)
    {
        $client->loadMissing('active_client_api');
        $api       = $client->active_client_api;
        $resolutor = new ClientEmpresaApiUrlResolver();

        $bloque = [
            'id'           => (int) $client->id,
            'name'         => $client->name,
            'company_name' => $client->company_name,
            'slug'         => $client->slug,
            'user_id'      => $client->user_id === null ? null : (int) $client->user_id,
            'is_active'    => (bool) $client->is_active,
            'sistema'      => [
                'spa_url'              => $this->vacio_a_null($resolutor->resolve_spa_url($client)),
                'api_url'              => $this->vacio_a_null($resolutor->resolve_base_url($client)),
                'path'                 => $api === null ? null : $api->path,
                'hosting_type'         => $api === null ? null : $api->hosting_type,
                'active_client_api_id' => $client->active_client_api_id === null ? null : (int) $client->active_client_api_id,
            ],
        ];

        if ($con_contacto) {
            $datos_del_formulario = is_array($client->setup_data) ? $client->setup_data : [];

            $bloque['contacto'] = [
                'phone'                   => $client->phone,
                'email'                   => $client->email,
                'email_formulario'        => isset($datos_del_formulario['email']) ? (string) $datos_del_formulario['email'] : null,
                'migration_contact_phone' => $implementation->migration_contact_phone,
            ];
        }

        return $bloque;
    }

    /**
     * El bloque `etapas`: las ocho, con su estado y lo que se registró en cada una.
     *
     * `acciones` junta lo que hizo el panel (`{action, stage, at}`) y lo que registró Claude por
     * `actions` (`{action, stage, at, canal, origen}`): el `origen` dice de cuál es cada una. Las del
     * panel no traen canal; salen con `canal: null`.
     *
     * @param Implementation $implementation La implementación, con sus etapas cargadas.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function bloque_de_etapas(Implementation $implementation)
    {
        $nombres   = $this->nombres_de_etapas();
        $por_etapa = [];

        foreach ($implementation->stages as $etapa) {
            $por_etapa[(int) $etapa->stage_number] = $etapa;
        }

        $bloque = [];

        for ($numero = 1; $numero <= 8; $numero++) {
            $etapa = isset($por_etapa[$numero]) ? $por_etapa[$numero] : null;

            $acciones = [];
            $datos    = $etapa !== null && is_array($etapa->data) ? $etapa->data : [];
            $entradas = isset($datos['actions']) && is_array($datos['actions']) ? $datos['actions'] : [];

            foreach ($entradas as $entrada) {
                if (! is_array($entrada)) {
                    continue;
                }

                $acciones[] = [
                    'accion' => isset($entrada['action']) ? (string) $entrada['action'] : null,
                    'at'     => isset($entrada['at']) ? (string) $entrada['at'] : null,
                    'canal'  => isset($entrada['canal']) ? (string) $entrada['canal'] : null,
                    'origen' => isset($entrada['origen']) ? (string) $entrada['origen'] : 'panel',
                ];
            }

            $bloque[] = [
                'numero'       => $numero,
                'nombre'       => $nombres[$numero],
                'estado'       => $etapa === null ? 'pending' : (string) $etapa->status,
                'started_at'   => $etapa === null ? null : $this->instante($etapa->started_at),
                'completed_at' => $etapa === null ? null : $this->instante($etapa->completed_at),
                'acciones'     => $acciones,
            ];
        }

        return $bloque;
    }

    /**
     * El bloque `formulario`: cuándo se envió y el resumen de lo que el cliente cargó.
     *
     * 🔴 El resumen sale de `ImplementationFormMapper::build_summary()`, el mismo que muestra el
     * panel, y esa función incluye el DNI y el teléfono de cada empleado en la sección "Equipo" y la
     * dirección y las redes del negocio en "Empresa". Sin `include=contacto` se les saca esos datos
     * ANTES de armar el resumen (y no se parchea el texto de salida): de los empleados queda solo el
     * nombre, y del negocio el nombre (sin dirección ni redes). Lo demás del resumen —precios, stock,
     * ventas— no es un dato personal. El mail y el CUIT/documento del dueño no salen en ningún caso:
     * `build_summary()` no los incluye.
     *
     * @param Implementation $implementation La implementación.
     * @param bool           $con_contacto   true = el resumen completo, con los datos personales.
     *
     * @return array<string, mixed>
     */
    protected function bloque_del_formulario(Implementation $implementation, $con_contacto)
    {
        $mapper     = new ImplementationFormMapper();
        $respuestas = $mapper->read_form_responses($implementation);

        if (! $con_contacto && isset($respuestas['employees']) && is_array($respuestas['employees'])) {
            $solo_nombres = [];

            foreach ($respuestas['employees'] as $fila) {
                if (is_array($fila)) {
                    $solo_nombres[] = ['name' => isset($fila['name']) ? $fila['name'] : ''];
                }
            }

            $respuestas['employees'] = $solo_nombres;
        }

        /* 🔴 Y sin el contacto tampoco salen la DIRECCIÓN ni las REDES del negocio: son datos de contacto del
           negocio, y quien lee esto es una sesión que los pega en una conversación. Se sacan ANTES de armar el
           resumen, igual que los datos de los empleados (no se parchea el texto de salida). El nombre del negocio
           queda: no es un dato de contacto y es con lo que se confirma la operación. */
        if (! $con_contacto) {
            foreach (['address_company', 'facebook', 'instagram', 'social_networks'] as $campo_de_contacto) {
                unset($respuestas[$campo_de_contacto]);
            }
        }

        return [
            'enviado_at' => $this->instante($implementation->form_submitted_at),
            'resumen'    => $mapper->build_summary($respuestas),
        ];
    }

    /**
     * El estado del user setup: `sin_correr`, `en_curso`, `ok` o `error`.
     *
     * La verdad de "ya se aplicó" es `implementations.user_setup_executed_at` (la misma columna que
     * mira el panel para su candado): si está, es `ok` aunque el registro de la etapa 2 diga otra
     * cosa. El registro (`stage 2 data.user_setup`) lo escribe el job de `user-setup` y agrega lo que
     * la columna no puede decir: cuándo arrancó, cuándo terminó y el motivo del error.
     *
     * @param Implementation $implementation La implementación, con sus etapas cargadas.
     *
     * @return array<string, mixed>
     */
    protected function estado_del_user_setup(Implementation $implementation)
    {
        $registro = $this->registro_del_user_setup($implementation);
        $estado   = isset($registro['estado']) ? (string) $registro['estado'] : '';
        $colgado  = false;

        if ($implementation->user_setup_executed_at !== null) {
            $estado = 'ok';
        } elseif ($estado === 'en_curso') {
            $colgado = $this->esta_colgado($registro);
        } elseif ($estado !== 'error') {
            $estado = 'sin_correr';
        }

        $respuesta = [
            'estado'       => $estado,
            'ejecutado_at' => $this->instante($implementation->user_setup_executed_at),
            'iniciado_at'  => isset($registro['iniciado_at']) ? (string) $registro['iniciado_at'] : null,
            'terminado_at' => isset($registro['terminado_at']) ? (string) $registro['terminado_at'] : null,
            'error'        => isset($registro['error']) ? (string) $registro['error'] : null,
        ];

        /* En un error: ¿pudo haber corrido del otro lado? (null cuando no hay error, o en un registro que
           no lo dice). Es lo que decide si corresponde conciliar o reintentar. */
        if ($estado === 'error') {
            $respuesta['puede_haber_corrido'] = isset($registro['puede_haber_corrido']) ? (bool) $registro['puede_haber_corrido'] : null;
        }

        /* La nota que dejó quien concilió ("conciliado: el dueño ya existía en el sistema del cliente"). */
        if ($estado === 'ok' && isset($registro['nota']) && trim((string) $registro['nota']) !== '') {
            $respuesta['nota'] = (string) $registro['nota'];
        }

        if ($colgado) {
            $llamo = $this->llamo_antes_de_colgarse($registro);

            $respuesta['colgado']             = true;
            $respuesta['llamada_iniciada_at'] = $llamo ? (string) $registro['llamada_iniciada_at'] : null;
            $respuesta['puede_haber_corrido'] = $llamo;
            $respuesta['nota']                = 'No hubo señal en ' . self::MINUTOS_PARA_DAR_POR_COLGADO . ' minutos: el registro sigue en en_curso sin resultado. '
                . ($llamo
                    ? '🔴 El job SÍ llegó a llamar al sistema del cliente (llamada_iniciada_at) y se cortó sin dejar el resultado (un deploy del admin, una caída, '
                        . 'el worker muerto): el setup PUDO HABER CORRIDO del otro lado. Mirá si el dueño existe en el sistema del cliente (con sus listas de precios y sus depósitos) y mandá EXACTAMENTE UNO de '
                        . '`conciliar: true` (existe: no llama a nadie) o `reintentar: true` (no existe o quedó a medias: vuelve a correr migrate:fresh). La llamada '
                        . 'normal, sin ninguno de los dos, es 422.'
                    : 'El job NUNCA llegó a llamar al cliente (no hay llamada_iniciada_at: la cola estaba parada o atrasada), así que no salió nada. POST '
                        . 'claude/implementations/{id}/user-setup con la llamada normal vuelve a intentar, y es seguro: cada intento lleva su token y el job viejo, '
                        . 'si llega a arrancar, se descarta solo sin llamar al cliente.');
        }

        return $respuesta;
    }

    /**
     * ¿Un user setup que quedó `en_curso` llegó a llamar al sistema del cliente antes de colgarse?
     *
     * 🔴 Lo dice `llamada_iniciada_at`, que el job escribe bajo lock ANTES de llamar (ver `tomar_el_turno()`). Sin la
     * marca el job nunca arrancó (cola parada o atrasada) y no salió nada; con ella, la llamada pudo estar en vuelo o
     * haber terminado cuando se cortó la ejecución, y el setup PUDO HABER CORRIDO del otro lado.
     *
     * @param array<string, mixed> $registro El registro de la etapa 2.
     *
     * @return bool
     */
    protected function llamo_antes_de_colgarse(array $registro)
    {
        return isset($registro['llamada_iniciada_at']) && trim((string) $registro['llamada_iniciada_at']) !== '';
    }

    /**
     * ¿Hay que DECIDIR (`conciliar` o `reintentar`) antes de volver a aplicar el user setup?
     *
     * Sí cuando el último intento terminó en `error` y cuando quedó colgado DESPUÉS de llamar al cliente: los dos
     * pudieron haber corrido del otro lado, y repetir la llamada tal cual es otro `migrate:fresh`. No cuando no hubo
     * intento, ni cuando el job nunca llegó a llamar (colgado sin la marca).
     *
     * @param array<string, mixed> $estado El bloque `user_setup` de `estado_del_user_setup()`.
     *
     * @return bool
     */
    protected function pide_decision_el_intento_anterior(array $estado)
    {
        if ($estado['estado'] === 'error') {
            return true;
        }

        return $estado['estado'] === 'en_curso'
            && isset($estado['colgado']) && $estado['colgado'] === true
            && isset($estado['puede_haber_corrido']) && $estado['puede_haber_corrido'] === true;
    }

    /**
     * El registro del user setup guardado en la etapa 2 (`data.user_setup`), o un array vacío.
     *
     * @param Implementation $implementation La implementación, con sus etapas cargadas.
     *
     * @return array<string, mixed>
     */
    protected function registro_del_user_setup(Implementation $implementation)
    {
        $implementation->loadMissing('stages');

        $etapa_2 = $implementation->stages->firstWhere('stage_number', 2);
        if ($etapa_2 === null || ! is_array($etapa_2->data)) {
            return [];
        }

        return isset($etapa_2->data['user_setup']) && is_array($etapa_2->data['user_setup'])
            ? $etapa_2->data['user_setup']
            : [];
    }

    /**
     * ¿Hace más de `MINUTOS_PARA_DAR_POR_COLGADO` que corre el user setup que dice `en_curso`?
     *
     * 🔴 Los minutos se cuentan desde que el job LLAMÓ al cliente (`llamada_iniciada_at`) y no desde que se encoló
     * (`iniciado_at`): con la cola atrasada el job puede tardar en arrancar, y darlo por colgado con la llamada recién salida
     * mandaría a reintentar un setup que está corriendo. Sin la marca (el job todavía no llamó, o nunca llamó) se cuenta desde
     * que se encoló. Una marca ilegible no cuenta: se cae al encolado.
     *
     * Un `en_curso` sin ninguna de las dos fechas (no debería pasar: las escribe el endpoint y el job) se da por colgado: sin
     * fecha no hay forma de saber desde cuándo corre y dejarlo trabado para siempre es peor que dejar reintentar.
     *
     * @param array<string, mixed> $registro El registro de la etapa 2.
     *
     * @return bool
     */
    protected function esta_colgado(array $registro)
    {
        $desde = $this->parsear_o_null(isset($registro['llamada_iniciada_at']) ? $registro['llamada_iniciada_at'] : null);
        if ($desde === null) {
            $desde = $this->parsear_o_null(isset($registro['iniciado_at']) ? $registro['iniciado_at'] : null);
        }
        if ($desde === null) {
            return true;
        }

        return $desde->lt(now()->subMinutes(self::MINUTOS_PARA_DAR_POR_COLGADO));
    }

    /**
     * El bloque `instalaciones`: las del cliente, la última primero.
     *
     * @param Client $client   El cliente.
     * @param bool   $con_logs true = sumar las últimas líneas del log de cada una.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function bloque_de_instalaciones(Client $client, $con_logs)
    {
        $filas = ClientInstallation::where('client_id', $client->id)->with('version')->orderByDesc('id')->get();

        $bloque = [];

        foreach ($filas as $fila) {
            /* Solo una `instalando` puede estar colgada (y solo ahí vale la pena la consulta de los logs). */
            $actividad = $fila->status === 'instalando' ? $this->ultima_actividad_de_la_instalacion($fila) : null;
            $colgada   = $fila->status === 'instalando' && $this->esta_colgada_la_instalacion($fila, $actividad);

            $item = [
                'id'                     => (int) $fila->id,
                'uuid'                   => (string) $fila->uuid,
                'group_uuid'             => $fila->group_uuid,
                'kind'                   => (string) $fila->kind,
                'status'                 => (string) $fila->status,
                'colgada'                => $colgada,
                'ultima_actividad_at'    => $this->instante($actividad),
                'client_api_id'          => $fila->client_api_id === null ? null : (int) $fila->client_api_id,
                'version'                => $fila->version === null ? null : [
                    'id'      => (int) $fila->version->id,
                    'version' => (string) $fila->version->version,
                ],
                'provision_hosting_type' => $fila->provision_hosting_type,
                'started_at'             => $this->instante($fila->started_at),
                'finished_at'            => $this->instante($fila->finished_at),
                'failure_reason'         => $this->recortar($fila->failure_reason),
            ];

            if ($colgada) {
                $item['nota'] = 'Sin actividad hace más de ' . self::MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION . ' minutos (ni logs ni escrituras de la fila): '
                    . 'el job no está corriendo. POST claude/implementations/{id}/install con marcar_colgadas=true la pasa a fallida y deja volver a instalar '
                    . '(solo si TODAS las instalando del cliente están colgadas).';
            }

            if ($con_logs) {
                $item['logs'] = $this->ultimas_lineas_de_log($fila);
            }

            $bloque[] = $item;
        }

        return $bloque;
    }

    /**
     * El último momento en que se supo algo de una instalación: lo más reciente entre que arrancó
     * (`started_at`), que alguien escribió la fila (`updated_at`) y su último renglón de log.
     *
     * @param ClientInstallation $fila La instalación.
     *
     * @return Carbon|null Null si no hay ningún dato de tiempo.
     */
    protected function ultima_actividad_de_la_instalacion(ClientInstallation $fila)
    {
        $candidatas = [$fila->started_at, $fila->updated_at, DeploymentLog::where('client_installation_id', $fila->id)->max('created_at')];
        $mayor      = null;

        foreach ($candidatas as $candidata) {
            $momento = $candidata instanceof Carbon ? $candidata : $this->parsear_o_null($candidata);

            if ($momento !== null && ($mayor === null || $momento->gt($mayor))) {
                $mayor = $momento;
            }
        }

        return $mayor;
    }

    /**
     * ¿Está colgada esta instalación? Solo una `instalando` puede estarlo: la que lleva más de
     * `MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION` sin actividad. Sin ningún dato de tiempo no hay con qué
     * probar que está viva, y se da por colgada (igual que un user setup `en_curso` sin fecha).
     *
     * @param ClientInstallation $fila   La instalación.
     * @param Carbon|null        $ultima Su última actividad (`ultima_actividad_de_la_instalacion()`).
     *
     * @return bool
     */
    protected function esta_colgada_la_instalacion(ClientInstallation $fila, $ultima)
    {
        if ($fila->status !== 'instalando') {
            return false;
        }

        return $ultima === null || $ultima->lt(now()->subMinutes(self::MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION));
    }

    /**
     * Las últimas líneas del log de una instalación, en orden cronológico.
     *
     * Las líneas ya vienen REDACTADAS de origen: el runner de comandos remotos tapa los secretos
     * antes de que la línea llegue a `deployment_logs` (ver `RemoteCommandRunner`), así que acá no hay
     * contraseñas en claro. Se recortan por largo porque una línea puede traer la salida cruda de un
     * comando.
     *
     * @param ClientInstallation $instalacion La instalación.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function ultimas_lineas_de_log(ClientInstallation $instalacion)
    {
        $lineas = DeploymentLog::where('client_installation_id', $instalacion->id)
            ->orderByDesc('id')
            ->limit(self::LINEAS_DE_LOG)
            ->get(['step', 'level', 'line', 'created_at'])
            ->reverse()
            ->values();

        $bloque = [];

        foreach ($lineas as $linea) {
            $bloque[] = [
                'at'    => $this->instante($linea->created_at),
                'step'  => (string) $linea->step,
                'level' => (string) $linea->level,
                'line'  => $this->recortar($linea->line),
            ];
        }

        return $bloque;
    }

    /**
     * El bloque `mails`: lo que ya salió de cada hito, de la tabla `implementation_mails`.
     *
     * 🔴 La tabla la crea otra migración de la misma misión. Si todavía no está en la base (un slot
     * que no la corrió, un entorno de tests viejo) se devuelve lista vacía en vez de romper: un estado
     * que contesta 500 porque falta una tabla de mails dejaría a la skill sin poder ver nada, y esto es
     * lo único que depende de ella.
     *
     * La casilla sale enmascarada ("l***@gmail.com"), con el mismo formato que `para_enmascarado` de las
     * respuestas del mail: este listado no es el lugar donde mostrarla entera.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function bloque_de_mails(Implementation $implementation)
    {
        if (! $this->existe_la_tabla_de_mails()) {
            return [];
        }

        $filas = ImplementationMail::where('implementation_id', $implementation->id)->orderBy('id')->get();

        $bloque = [];

        foreach ($filas as $fila) {
            $bloque[] = [
                'hito'             => (string) $fila->hito,
                'para_enmascarado' => ImplementacionMailHelper::enmascarar((string) $fila->email),
                'estado'           => (string) $fila->estado,
                'enviado_at'       => $this->instante($fila->enviado_at),
                'reenvios'         => (int) $fila->reenvios,
                'error'            => $this->recortar($fila->error),
            ];
        }

        return $bloque;
    }

    /**
     * ¿Existe en la base la tabla `implementation_mails`?
     *
     * Es un método y no un `Schema::hasTable()` suelto dentro de `bloque_de_mails()` por una razón de
     * tests: la facade `Schema` no se puede espiar en esta versión de Laravel (su accesor es un objeto,
     * no un nombre), y sin una costura no habría forma de probar el camino "la tabla todavía no está"
     * en una base que ya la tiene. El test lo reemplaza con una subclase anónima.
     *
     * @return bool
     */
    protected function existe_la_tabla_de_mails()
    {
        return Schema::hasTable('implementation_mails');
    }

    /**
     * El bloque `entrantes`: si el cliente escribió algo que nadie contestó.
     *
     * 🔴 Es la razón de ser de este bloque: mientras la implementación está abierta, TODO lo que el
     * cliente le escribe al número de WhatsApp del sistema cae al hilo de la implementación (el webhook
     * lo manda ahí y no a soporte), y en modo manual ese hilo no contesta nada y nadie lo mira. Un
     * cliente puede estar semanas escribiendo al vacío. La skill mira esto en cada paso.
     *
     * "Sin responder" son los mensajes entrantes POSTERIORES al último saliente QUE EFECTIVAMENTE SALIÓ:
     * un saliente sin `whatsapp_message_id` es un envío fallido (la tabla no tiene columna de estado, y
     * `send_outbound` lo persiste igual), así que no cuenta como una respuesta. Los salientes que
     * registra Claude por `actions` llevan siempre un id (`waweb-<uuid>`) justamente para no leerse como
     * fallidos.
     *
     * @param Implementation $implementation La implementación.
     *
     * @return array<string, mixed>
     */
    protected function bloque_de_entrantes(Implementation $implementation)
    {
        $ultimo_saliente = ImplementationMessage::where('implementation_id', $implementation->id)
            ->where('direction', 'outbound')
            ->whereNotNull('whatsapp_message_id')
            ->max('sent_at');

        $entrantes = ImplementationMessage::where('implementation_id', $implementation->id)
            ->where('direction', 'inbound');

        $ultimo_entrante = (clone $entrantes)->max('sent_at');

        if ($ultimo_saliente !== null) {
            $entrantes->where('sent_at', '>', $ultimo_saliente);
        }

        return [
            'sin_responder' => (int) $entrantes->count(),
            'ultimo_at'     => $this->instante($ultimo_entrante),
        ];
    }

    /* ==============================================================================================
     | 2) POST claude/implementations — ALTA: lead → cliente → implementación
     |============================================================================================= */

    /**
     * Da de alta una implementación: promueve el lead a cliente (si todavía no lo es) y arranca la
     * implementación con sus ocho etapas, el formulario y la etapa 1 en curso.
     *
     * Es "tal lead compró": lo único que hay que decidir con Lucas es el SUBDOMINIO, porque de él
     * salen las cuatro URLs del cliente (`<sub>`, `<sub>2`, `api-<sub>`, `api-<sub>2`) y no se puede
     * cambiar sin rehacer el hosting.
     *
     * Dos caminos, según qué se pase:
     *  - `lead_id`: si el lead ya tiene cliente promovido, se usa ese (no se promueve otra vez y el
     *    `subdominio` se ignora, con un aviso); si no, se promueve con `PromoteLeadToClientService`,
     *    el mismo servicio que el botón "Promover a cliente" del panel.
     *  - `client_id`: un cliente que ya existe y todavía no tiene implementación.
     *
     * Frenos, todos ANTES de escribir:
     *   1. Lista blanca de parámetros.
     *   2. Tipos, y exactamente uno de `lead_id` o `client_id`.
     *   3. El lead o el cliente existen.
     *   4. El cliente no tiene ya una implementación (409, con el id de la que tiene).
     *   5. Si hay que promover: un creador resoluble (las tareas de la promoción llevan un Admin como
     *      creador y acá no hay sesión) y un subdominio válido y libre.
     *   6. `dry_run` (default TRUE): devuelve exactamente lo que haría, incluidas las cuatro URLs, y
     *      nombra todo lo que bloquearía el alta real en `bloqueos`.
     *   7. `confirm_nombre`: el nombre del negocio (`company_name`, o el del contacto si está vacío) del
     *      lead o del cliente. No revela el correcto.
     *
     * Con `dry_run=false` el alta entera (promoción + implementación) corre en UNA transacción, con la
     * fila del lead o del cliente bloqueada: dos POST simultáneos no crean dos implementaciones (la
     * tabla no tiene índice único por cliente) y un fallo a la mitad no deja un cliente sin tareas ni
     * ClientApis, que es como el panel puede dejarlo (la promoción del panel no es transaccional).
     * Además toma un lock global mientras promueve, porque el bloque de `user_id` del cliente nuevo se
     * calcula sin lock.
     *
     * 🔴 La implementación nace SIEMPRE en modo `manual` y sin mandar la plantilla de bienvenida, diga
     * lo que diga el setting global (`ImplementationStartService::start($client, true)`): cada mensaje
     * al cliente lo manda la skill por WhatsApp Web con el ok de Lucas.
     *
     * @param Request $request Body: lead_id | client_id, subdominio?, dry_run?, confirm_nombre.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        /* --- Freno 1: lista blanca. --- */
        $rechazo = $this->rechazar_parametros_de_mas($request, self::PARAMETROS_DEL_ALTA, 'POST claude/implementations');
        if ($rechazo !== null) {
            return $rechazo;
        }

        /* --- Freno 2: tipos y quién. --- */
        $invalido = $this->validar_o_422($request, [
            'lead_id'        => 'required_without:client_id|nullable|integer|min:1',
            'client_id'      => 'required_without:lead_id|nullable|integer|min:1',
            'subdominio'     => 'nullable|string|regex:' . self::PATRON_DE_SUBDOMINIO,
            'dry_run'        => 'nullable|boolean',
            'confirm_nombre' => 'required_if:dry_run,false|nullable|string|max:190',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $lead_id   = $this->entero_o_null($request->input('lead_id'));
        $client_id = $this->entero_o_null($request->input('client_id'));

        if ($lead_id !== null && $client_id !== null) {
            return $this->error_422(
                'Mandá lead_id o client_id, no los dos: pueden apuntar a negocios distintos y no se sabe cuál es el '
                    . 'que compró. No se hizo nada.',
                ['parametros_aceptados' => self::PARAMETROS_DEL_ALTA]
            );
        }

        /* --- Freno 3: el lead o el cliente. --- */
        $lead   = null;
        $client = null;

        if ($lead_id !== null) {
            $lead = Lead::find($lead_id);
            if ($lead === null) {
                return $this->error_404('no existe el lead ' . $lead_id);
            }

            /* Un lead ya promovido se usa con su cliente. `leads.promoted_client_id` tiene una foreign key a
               `clients` con ON DELETE SET NULL, así que no puede apuntar a un cliente que no existe: si el
               cliente se borra, el lead vuelve a quedar sin promover. Por eso acá no hay una rama para el
               "dato roto". */
            if (! empty($lead->promoted_client_id)) {
                $client = Client::find((int) $lead->promoted_client_id);
            }
        } else {
            $client = Client::find($client_id);
            if ($client === null) {
                return $this->error_404('no existe el cliente ' . $client_id);
            }
        }

        $promover = $client === null;

        /* --- Freno 4: el cliente ya tiene implementación. --- */
        if ($client !== null) {
            $existente = Implementation::where('client_id', $client->id)->orderByDesc('id')->first();

            if ($existente !== null) {
                return $this->conflicto_de_implementacion_existente($client, $existente);
            }
        }

        $dry_run           = $this->resolver_dry_run($request);
        $subdominio_pedido = $this->texto_o_null($request->input('subdominio'));
        $avisos            = [];
        $bloqueos          = [];

        if ($lead !== null && ! $promover && $subdominio_pedido !== null) {
            $avisos[] = 'El lead ya estaba promovido al cliente ' . (int) $client->id . ': no se promueve otra vez y el '
                . 'subdominio que mandaste se ignora (las URLs de ese cliente ya están definidas).';
        }

        /* --- Freno 5: lo que hace falta para promover. --- */
        $creador    = null;
        $subdominio = ['pedido' => $subdominio_pedido, 'sugerido' => null, 'valido' => null, 'motivo' => 'No aplica: el cliente ya existe y sus URLs ya están definidas.'];
        $efectivo   = null;

        if ($promover) {
            $creador = $this->resolver_creador();
            if ($creador === null) {
                $bloqueos[] = 'No hay ningún admin que figure como creador de las tareas de la promoción '
                    . '(CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID, el admin por defecto de tareas o el primer admin).';
            }

            $subdominio = $this->evaluar_el_subdominio($subdominio_pedido, $lead);
            $efectivo   = $subdominio['valido'] === true ? ($subdominio_pedido !== null ? $subdominio_pedido : $subdominio['sugerido']) : null;

            if ($subdominio_pedido === null) {
                $bloqueos[] = 'Falta confirmar el subdominio: mandalo en `subdominio` (el sugerido es "'
                    . (string) $subdominio['sugerido'] . '"). Lo definen Lucas y la skill juntos: de él salen las cuatro URLs.';
            } elseif ($subdominio['valido'] !== true) {
                $bloqueos[] = 'El subdominio "' . $subdominio_pedido . '" no sirve: ' . $subdominio['motivo'];
            }

            $avisos[] = 'El subdominio se chequeó contra las ClientApi del admin, NO contra Hostinger: la instalación lo vuelve a '
                . 'verificar al arrancar (provision_check), y conviene mirar la cuenta antes con '
                . 'hostinger-cliente.php ver <subdominio>.';

            if (trim((string) $lead->phone) === '') {
                $avisos[] = 'El lead no tiene teléfono: el cliente nacería sin teléfono y no se le va a poder escribir.';
            }
        } else {
            /* 🔴 No se arranca una implementación sobre un negocio que ya opera: un cliente con un sistema vivo
               (actualizaciones registradas) ya pasó por esto, y volver a "implementarlo" lo llevaría a instalar,
               configurar y cerrar etapas sobre datos de producción. Vale para `client_id` y para un lead ya
               promovido a ese cliente. */
            $vivo = $this->sistema_vivo($client);
            if ($vivo['vivo']) {
                $bloqueos[] = 'El cliente ya tiene un sistema en producción (' . implode(' ', $vivo['motivos']) . '): no se arranca una implementación '
                    . 'sobre un negocio que opera.';
            }

            if (trim((string) $client->phone) === '') {
                $avisos[] = 'El cliente no tiene teléfono cargado: no se le va a poder escribir por WhatsApp.';
            }

            if (! ClientApi::where('client_id', $client->id)->exists()) {
                $avisos[] = 'El cliente no tiene ninguna ClientApi: la instalación no va a poder correr hasta que tenga las dos '
                    . '(<sub> y <sub>2).';
            }
        }

        $url_del_formulario = ImplementationSettings::get_form_url();
        if ($url_del_formulario === '') {
            $avisos[] = 'La URL base del formulario (setting implementation_form_url) está vacía: la implementación se crea pero '
                . 'form_link va a ser null y no hay link para mandarle al cliente. Cargala en Cuenta → Implementación.';
        }

        if ((string) AdminSetting::get('implementation_automation_mode', 'manual') === 'auto') {
            $avisos[] = 'El setting global implementation_automation_mode está en "auto": no importa, la implementación nace en '
                . '"manual" y sin plantilla de bienvenida igual.';
        }

        $version = Version::where('status', 'published')->orderByDesc('id')->first();
        if ($version === null) {
            $avisos[] = 'No hay ninguna versión publicada: el alta puede hacerse pero la instalación no va a poder correr.';
        }

        /* --- Freno 6: simulación por defecto. --- */
        if ($dry_run) {
            return response()->json([
                'dry_run'              => true,
                'accion'               => $promover ? 'promover_y_empezar' : 'empezar',
                'listo'                => count($bloqueos) === 0,
                'lead'                 => $lead === null ? null : [
                    'id'                 => (int) $lead->id,
                    'contact_name'       => $lead->contact_name,
                    'company_name'       => $lead->company_name,
                    'status'             => $lead->status,
                    'promoted_client_id' => empty($lead->promoted_client_id) ? null : (int) $lead->promoted_client_id,
                ],
                'cliente'              => $promover ? [
                    'existente'    => false,
                    'name'         => trim((string) $lead->contact_name) !== '' ? trim((string) $lead->contact_name) : 'Cliente',
                    'company_name' => trim((string) $lead->company_name) !== '' ? trim((string) $lead->company_name) : null,
                    'nota'         => 'Se crea con la promoción: su user_id (bloque de ComercioCity), su slug y sus claves las asigna el sistema.',
                ] : [
                    'existente'            => true,
                    'id'                   => (int) $client->id,
                    'name'                 => $client->name,
                    'company_name'         => $client->company_name,
                    'slug'                 => $client->slug,
                    'user_id'              => $client->user_id === null ? null : (int) $client->user_id,
                    'active_client_api_id' => $client->active_client_api_id === null ? null : (int) $client->active_client_api_id,
                ],
                'subdominio'           => $subdominio,
                'urls'                 => $promover ? $this->urls_del_subdominio($efectivo) : $this->urls_del_cliente($client),
                'version_a_instalar'   => $version === null ? null : ['id' => (int) $version->id, 'version' => (string) $version->version],
                'form_url_configurada' => $url_del_formulario !== '',
                'bloqueos'             => $bloqueos,
                'avisos'               => $avisos,
                'nota'                 => 'Simulacro: no se creó nada. Repetí con dry_run=false y confirm_nombre (el nombre del negocio) para dar el alta.',
            ], 200);
        }

        /* --- Freno 7: confirmación por nombre. --- */
        $nombre_esperado = $promover
            ? $this->nombre_del_negocio($lead->company_name, $lead->contact_name)
            : $this->nombre_del_negocio($client->company_name, $client->name);

        $rechazo = $this->rechazar_si_el_nombre_no_confirma($request, $nombre_esperado, $promover ? 'lead' : 'cliente', $promover ? (int) $lead->id : (int) $client->id);
        if ($rechazo !== null) {
            return $rechazo;
        }

        if (count($bloqueos) > 0) {
            return $this->error_422(
                'No se puede dar el alta: ' . implode(' | ', $bloqueos) . ' No se creó nada.',
                ['bloqueos' => $bloqueos, 'subdominio' => $subdominio]
            );
        }

        $resultado = $this->ejecutar_el_alta($lead, $client, $promover, $efectivo, $creador);

        if ($resultado === 'lock') {
            return $this->error_422(
                'No se pudo tomar el lock de altas en ' . $this->segundos_de_espera_del_lock_de_altas() . ' segundos: hay otra promoción en curso. '
                    . 'No se creó nada. Es transitorio: reintentá la misma llamada.',
                ['reintentable' => true]
            );
        }

        if (isset($resultado['subdominio_ocupado'])) {
            return $this->error_422(
                'No se puede dar el alta: El subdominio "' . (string) $efectivo . '" no sirve: ' . $resultado['subdominio_ocupado']
                    . ' (se volvió a chequear con el lock tomado: otra promoción lo ocupó mientras tanto). No se creó nada.',
                [
                    'bloqueos'   => ['El subdominio "' . (string) $efectivo . '" no sirve: ' . $resultado['subdominio_ocupado']],
                    'subdominio' => ['pedido' => $subdominio_pedido, 'sugerido' => $subdominio['sugerido'], 'valido' => false, 'motivo' => $resultado['subdominio_ocupado']],
                ]
            );
        }

        if (isset($resultado['conflicto'])) {
            return $this->conflicto_de_implementacion_existente($resultado['client'], $resultado['conflicto']);
        }

        $client         = $resultado['client'];
        $implementation = $resultado['implementation'];

        if ($implementation->form_link === null) {
            $avisos[] = 'form_link es null: la URL base del formulario (implementation_form_url) está vacía. Cargala en Cuenta → '
                . 'Implementación y volvé a leer el estado: ahí va a salir el link.';
        }

        return response()->json([
            'dry_run'        => false,
            'accion'         => $promover ? 'promover_y_empezar' : 'empezar',
            'promovido'      => (bool) $resultado['promovido'],
            'implementation' => [
                'id'            => (int) $implementation->id,
                'current_stage' => (int) $implementation->current_stage,
                'status'        => (string) $implementation->status,
                'form_link'     => $implementation->form_link,
            ],
            'cliente'        => [
                'id'                   => (int) $client->id,
                'name'                 => $client->name,
                'company_name'         => $client->company_name,
                'slug'                 => $client->slug,
                'user_id'              => $client->user_id === null ? null : (int) $client->user_id,
                'active_client_api_id' => $client->active_client_api_id === null ? null : (int) $client->active_client_api_id,
            ],
            'client_apis'    => $this->client_apis_de($client),
            'avisos'         => $avisos,
        ], 201);
    }

    /**
     * Corre el alta de verdad: promueve (si hay que hacerlo) e inicia la implementación, todo en UNA
     * transacción y con las filas bloqueadas.
     *
     * 🔴 POR QUÉ UNA TRANSACCIÓN. `PromoteLeadToClientService::run()` no lo es (lo dice su propio
     * comentario): si algo tira a mitad queda un Client sin tareas ni ClientApis, un cliente a medias
     * que nadie nota hasta que falla la instalación. Acá, o queda todo o no queda nada.
     *
     * 🔴 POR QUÉ LOS LOCKS. Dos POST simultáneos del mismo alta pasarían los dos el chequeo de "no
     * tiene implementación" y crearían dos (`implementations.client_id` no tiene índice único): la
     * fila del cliente se bloquea con `lockForUpdate()` y recién ahí se vuelve a mirar. Y mientras se
     * promueve se toma un lock global porque el bloque de `user_id` del cliente nuevo se calcula y se
     * reserva sin lock (`UserIdBlockAllocatorService`): dos promociones a la vez podrían pedir el mismo.
     *
     * @param Lead|null   $lead       El lead (solo si se promueve).
     * @param Client|null $client     El cliente existente (null = hay que promover).
     * @param bool        $promover   true = promover el lead.
     * @param string|null $subdominio El subdominio confirmado (solo si se promueve).
     * @param Admin|null  $creador    Admin creador de las tareas de la promoción.
     *
     * @return array<string, mixed>|string `lock` si no se pudo tomar el lock global; si no, un array
     *                                     con `client`, `implementation` y `promovido`, con `client` y
     *                                     `conflicto` si entre medio alguien creó la implementación, o con
     *                                     `subdominio_ocupado` (el motivo) si con el lock tomado el
     *                                     subdominio ya no servía.
     */
    protected function ejecutar_el_alta($lead, $client, $promover, $subdominio, $creador)
    {
        $lock = null;

        if ($promover) {
            $lock = Cache::lock('claude_implementations_alta', self::LOCK_DE_ALTAS_TTL);

            try {
                $lock->block($this->segundos_de_espera_del_lock_de_altas());
            } catch (LockTimeoutException $e) {
                return 'lock';
            }
        }

        try {
            /* 🔴 El chequeo del subdominio se REPITE acá, con el lock tomado. El de antes del lock mira un estado que
               otra promoción puede estar cambiando: dos altas con el mismo subdominio para dos leads distintos
               pasarían las dos ese chequeo, y la segunda crearía las ClientApi de un subdominio que la primera
               acaba de ocupar. Con el lock nadie más promueve, así que lo que se ve acá es lo que hay. (Sin
               promoción —un cliente que ya existe— no hay subdominio que chequear.) */
            if ($promover) {
                $motivo = $this->motivo_por_el_que_no_sirve_el_subdominio((string) $subdominio);

                if ($motivo !== null) {
                    return ['subdominio_ocupado' => $motivo];
                }
            }

            return DB::transaction(function () use ($lead, $client, $promover, $subdominio, $creador) {
                $promovido = false;

                if ($promover) {
                    $lead_bloqueado = Lead::query()->whereKey($lead->id)->lockForUpdate()->first();

                    if (empty($lead_bloqueado->promoted_client_id)) {
                        app(PromoteLeadToClientService::class)->run($lead_bloqueado, $creador, (string) $subdominio);
                        $lead_bloqueado->refresh();
                        $promovido = true;
                    }

                    $client_id = (int) $lead_bloqueado->promoted_client_id;
                } else {
                    $client_id = (int) $client->id;
                }

                $cliente = Client::query()->whereKey($client_id)->lockForUpdate()->first();

                $existente = Implementation::where('client_id', $cliente->id)->orderByDesc('id')->first();
                if ($existente !== null) {
                    return ['client' => $cliente, 'conflicto' => $existente];
                }

                return [
                    'client'         => $cliente,
                    'implementation' => app(ImplementationStartService::class)->start($cliente, true),
                    'promovido'      => $promovido,
                ];
            });
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    /**
     * Cuántos segundos espera el alta el lock global de promociones antes de rendirse.
     *
     * Es un método y no la constante pelada por una razón de tests: probar el camino "hay otra promoción
     * en curso" con la constante esperaría los diez segundos enteros. El test lo reemplaza con una
     * subclase anónima que devuelve cero.
     *
     * @return int
     */
    protected function segundos_de_espera_del_lock_de_altas()
    {
        return self::LOCK_DE_ALTAS_ESPERA;
    }

    /**
     * El 409 de "este cliente ya tiene implementación", con el id de la que tiene para que la skill
     * pueda seguir con esa.
     *
     * @param Client         $client    El cliente.
     * @param Implementation $existente Su implementación.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function conflicto_de_implementacion_existente(Client $client, Implementation $existente)
    {
        return response()->json([
            'error'             => 'El cliente ' . (int) $client->id . ' ya tiene una implementación (' . (int) $existente->id . '): no se crea otra. '
                . 'No se hizo nada.',
            'client_id'         => (int) $client->id,
            'implementation_id' => (int) $existente->id,
            'current_stage'     => (int) $existente->current_stage,
            'status'            => (string) $existente->status,
            'ayuda'             => 'GET claude/implementations/' . (int) $existente->id . ' dice en qué etapa está.',
        ], 409);
    }

    /**
     * El admin que figura como creador de las tareas de la promoción.
     *
     * 🔴 Réplica DELIBERADA de `ClaudeTaskIngestController::resolve_creator_admin_id()`, que es
     * `protected` y vive en otro controlador: acá no hay sesión de Sanctum y `PromoteLeadToClientService`
     * necesita un `Admin` para registrar quién creó las tres tareas de `lead_a_cliente`. Mismo orden de
     * prioridad: el admin de `CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID`, el admin marcado como destinatario
     * por defecto de tareas, el primer admin de la tabla. Quedó anotado para el que unifique las dos.
     *
     * @return Admin|null Null si no hay ningún admin en la base.
     */
    protected function resolver_creador()
    {
        $configurado = config('services.claude_task_ingest.default_creator_admin_id');

        if (! empty($configurado)) {
            $admin = Admin::find((int) $configurado);

            if ($admin !== null) {
                return $admin;
            }
        }

        $por_defecto = Admin::where('is_default_task_assignee', true)->first();
        if ($por_defecto !== null) {
            return $por_defecto;
        }

        return Admin::orderBy('id')->first();
    }

    /**
     * Evalúa el subdominio del alta: el que mandaron o, si no mandaron ninguno, el que sugiere el
     * sistema.
     *
     * Devuelve `valido` sobre el que está en juego (el pedido si hay uno, si no el sugerido) y el
     * `motivo` cuando no sirve. NO ajusta nada solo: un subdominio ocupado no se arregla agregándole un
     * número, porque lo decide Lucas con el cliente y es el nombre que va a ver para siempre.
     *
     * @param string|null $pedido El subdominio que mandaron, ya recortado.
     * @param Lead|null   $lead   El lead (de su razón social sale la sugerencia).
     *
     * @return array{pedido: string|null, sugerido: string|null, valido: bool, motivo: string|null}
     */
    protected function evaluar_el_subdominio($pedido, $lead)
    {
        $sugerido = null;

        if ($pedido === null) {
            $nombre   = $this->nombre_del_negocio($lead->company_name, $lead->contact_name);
            $sugerido = (new SubdomainSuggestionService())->suggest($nombre);
        }

        $en_juego = $pedido !== null ? $pedido : $sugerido;
        $motivo   = $this->motivo_por_el_que_no_sirve_el_subdominio((string) $en_juego);

        return [
            'pedido'   => $pedido,
            'sugerido' => $sugerido,
            'valido'   => $motivo === null,
            'motivo'   => $motivo,
        ];
    }

    /**
     * Por qué un subdominio NO sirve para un cliente nuevo, o null si sirve.
     *
     * Chequea, en este orden: el formato (el mismo que la guarda 3 de la instalación), que no termine
     * en guion, que no sea un nombre reservado, que `<prefijo de la cuenta><subdominio>` entre en los
     * 32 caracteres de MySQL (el nombre de la base), y que ninguna ClientApi del admin use ya `<sub>`
     * ni `<sub>2` —en la url, en el spa_url ni en el path—.
     *
     * 🔴 Chequear `<sub>` y también `<sub>2` no es de más: un cliente nuevo ocupa los DOS. Si ya existe
     * un cliente `ferre` con su `ferre2`, un nuevo `ferre2` chocaría con la segunda API de aquél aunque
     * `ferre2` "parezca" libre.
     *
     * @param string $subdominio El subdominio a evaluar.
     *
     * @return string|null
     */
    protected function motivo_por_el_que_no_sirve_el_subdominio($subdominio)
    {
        if (preg_match(self::PATRON_DE_SUBDOMINIO, $subdominio) !== 1) {
            return 'no tiene el formato de un subdominio: entre 1 y 20 caracteres de a-z, 0-9 y guion, empezando por una letra o un número.';
        }

        if (substr($subdominio, -1) === '-') {
            return 'no puede terminar en guion.';
        }

        if (in_array($subdominio, self::SUBDOMINIOS_RESERVADOS, true) || preg_match(self::PATRON_DE_RESERVADOS_NUMERADOS, $subdominio) === 1) {
            return 'es un nombre reservado de la plataforma (' . implode(', ', self::SUBDOMINIOS_RESERVADOS) . ', y demo o ns seguidos de números).';
        }

        $prefijo = (string) config('services.hostinger.database_prefix', 'u767360347_');
        if (strlen($prefijo . $subdominio) > self::TOPE_NOMBRE_DE_BASE) {
            return 'la base de datos se llamaría "' . $prefijo . $subdominio . '" y MySQL admite hasta ' . self::TOPE_NOMBRE_DE_BASE . ' caracteres.';
        }

        $ocupado_por = $this->cliente_que_usa_el_subdominio($subdominio);
        if ($ocupado_por !== null) {
            return 'ya lo usa el cliente ' . $ocupado_por . ' (en una de sus ClientApi: <sub> o <sub>2).';
        }

        return null;
    }

    /**
     * El id del cliente cuyas ClientApi ya usan `<sub>` o `<sub>2`, o null si ninguna.
     *
     * La consulta SQL solo acota (un `LIKE` por el texto del subdominio, que para un nombre corto trae
     * de más) y la decisión se toma en PHP, comparando el host exacto: `hb` aparece adentro de
     * `https://api-hbo.comerciocity.com` y no por eso está ocupado.
     *
     * @param string $subdominio El subdominio.
     *
     * @return int|null
     */
    protected function cliente_que_usa_el_subdominio($subdominio)
    {
        $propios = [$subdominio, $subdominio . '2'];
        $con_api = ['api-' . $subdominio, 'api-' . $subdominio . '2'];

        /* 🔴 Los cuatro hosts candidatos se comparan contra el host SPA y el host API de cada ClientApi, sin importar el rol:
           pedir `api-x` cuando existe el cliente `x` (su API es `api-x`) choca aunque `api-x` no sea un SPA suyo, y pedir `x` cuando
           existe `api-x` también. Los dos terminarían como subdominios repetidos en Hostinger. */
        $todos = array_merge($propios, $con_api);

        $candidatas = ClientApi::query()
            ->where(function ($consulta) use ($subdominio) {
                $consulta->where('url', 'like', '%' . $subdominio . '%')
                    ->orWhere('spa_url', 'like', '%' . $subdominio . '%')
                    ->orWhere('path', 'like', $subdominio . '%');
            })
            ->get(['client_id', 'url', 'spa_url', 'path']);

        foreach ($candidatas as $api) {
            $host_spa = HostingProvisioningStructure::label_de_url((string) $api->spa_url);
            $host_api = HostingProvisioningStructure::label_de_url((string) $api->url);

            $partes    = explode('/', trim((string) $api->path, '/'));
            $path_raiz = $partes[0];

            if (in_array($host_spa, $todos, true) || in_array($host_api, $todos, true) || in_array($path_raiz, $propios, true)) {
                return (int) $api->client_id;
            }
        }

        return null;
    }

    /**
     * Las cuatro URLs que tendría un cliente con ese subdominio, en el orden en que se crean.
     *
     * Salen del dominio de config y no de un valor de afuera (guarda G5 del aprovisionamiento).
     *
     * @param string|null $subdominio El subdominio ya validado, o null si todavía no hay uno que sirva.
     *
     * @return array<int, array<string, string>>
     */
    protected function urls_del_subdominio($subdominio)
    {
        if ($subdominio === null) {
            return [];
        }

        $dominio = HostingProvisioningStructure::dominio();

        return [
            ['rol' => 'spa',   'url' => 'https://' . $subdominio . '.' . $dominio],
            ['rol' => 'api',   'url' => 'https://api-' . $subdominio . '.' . $dominio],
            ['rol' => 'spa_2', 'url' => 'https://' . $subdominio . '2.' . $dominio],
            ['rol' => 'api_2', 'url' => 'https://api-' . $subdominio . '2.' . $dominio],
        ];
    }

    /**
     * Las URLs que ya tiene un cliente existente, desde sus ClientApi.
     *
     * @param Client $client El cliente.
     *
     * @return array<int, array<string, string>>
     */
    protected function urls_del_cliente(Client $client)
    {
        $urls = [];

        foreach (ClientApi::where('client_id', $client->id)->orderBy('id')->get(['id', 'url', 'spa_url']) as $api) {
            $cual = $client->active_client_api_id !== null && (int) $api->id === (int) $client->active_client_api_id ? 'activa' : 'otra';

            if (trim((string) $api->spa_url) !== '') {
                $urls[] = ['rol' => 'spa_' . $cual, 'url' => (string) $api->spa_url];
            }

            if (trim((string) $api->url) !== '') {
                $urls[] = ['rol' => 'api_' . $cual, 'url' => (string) $api->url];
            }
        }

        return $urls;
    }

    /**
     * Las ClientApi de un cliente, en la forma corta que devuelve el alta.
     *
     * @param Client $client El cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function client_apis_de(Client $client)
    {
        $apis = [];

        foreach (ClientApi::where('client_id', $client->id)->orderBy('id')->get(['id', 'url', 'spa_url', 'path', 'hosting_type']) as $api) {
            $apis[] = [
                'id'           => (int) $api->id,
                'url'          => $api->url,
                'spa_url'      => $api->spa_url,
                'path'         => $api->path,
                'hosting_type' => $api->hosting_type,
            ];
        }

        return $apis;
    }

    /**
     * El nombre del negocio con el que se confirma el alta: la razón social y, si está vacía, el
     * nombre de la persona.
     *
     * @param mixed $razon_social `company_name`.
     * @param mixed $contacto     `contact_name` (lead) o `name` (cliente).
     *
     * @return string
     */
    protected function nombre_del_negocio($razon_social, $contacto)
    {
        $razon_social = trim((string) $razon_social);

        return $razon_social !== '' ? $razon_social : trim((string) $contacto);
    }

    /**
     * Freno de la confirmación del alta: `confirm_nombre` tiene que coincidir con el nombre del
     * negocio, comparado con trim + minúsculas en las dos puntas.
     *
     * Es la versión del freno `rechazar_si_el_nombre_del_cliente_no_confirma()` del trait para el caso
     * en que todavía no hay un `Client` (el alta de un lead) y el parámetro se llama distinto. Mismas
     * dos reglas que aquél, por el mismo motivo: el error NO revela el nombre correcto (es un freno, no
     * un formulario a completar) y un nombre vacío no se puede confirmar con nada, así que se dice la
     * causa de verdad en vez de un "no coincide" que mentiría.
     *
     * @param Request $request  La request (de ahí sale `confirm_nombre`).
     * @param string  $esperado El nombre del negocio.
     * @param string  $de_quien `lead` | `cliente`, para el mensaje.
     * @param int     $id       Id del lead o del cliente, para el mensaje.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si confirma bien.
     */
    protected function rechazar_si_el_nombre_no_confirma(Request $request, $esperado, $de_quien, $id)
    {
        $recibido = $this->normalizar_nombre($request->input('confirm_nombre'));
        $real     = $this->normalizar_nombre($esperado);
        $clave_id = $de_quien === 'lead' ? 'lead_id' : 'client_id';

        if ($real === '') {
            return $this->error_422(
                'El ' . $de_quien . ' ' . $id . ' NO tiene ni razón social ni nombre cargado: no hay con qué confirmar y el alta no se puede '
                    . 'hacer. No se creó nada.',
                [
                    $clave_id => $id,
                    'ayuda'   => 'Cargale el nombre en el admin y volvé a intentar. Este freno no se saltea.',
                ]
            );
        }

        if ($recibido !== '' && $recibido === $real) {
            return null;
        }

        return $this->error_422(
            'confirm_nombre no coincide con el nombre del negocio de este ' . $de_quien . '. No se creó nada.',
            [
                $clave_id => $id,
                'ayuda'   => 'Es la razón social (company_name) o, si está vacía, el nombre del contacto. La respuesta de este error no '
                    . 'dice cuál es a propósito: es un freno, no un formulario a completar.',
            ]
        );
    }

    /**
     * `dry_run`: true salvo que venga un `false` explícito.
     *
     * 🔴 El default es TRUE en toda escritura de este bloque: la primera llamada devuelve lo que haría y
     * para escribir hay que pedirlo. Ya viene validado como booleano.
     *
     * @param Request $request La request.
     *
     * @return bool
     */
    protected function resolver_dry_run(Request $request)
    {
        $dry_run = $this->booleano_o_null($request, 'dry_run');

        return $dry_run === null ? true : $dry_run;
    }

    /* ==============================================================================================
     | 3) POST claude/implementations/{id}/advance — AVANZAR (o cerrar) la etapa actual
     |============================================================================================= */

    /**
     * Avanza la implementación a la etapa siguiente: cierra la actual (`completed`, o `skipped` si no
     * aplica) y deja en curso la que sigue. Desde la etapa 8 cierra la implementación entera.
     *
     * Hace lo que hace "Avanzar etapa" del panel (`ImplementationController::advance_stage`), con DOS
     * diferencias deliberadas:
     *
     * 🔴 NO dispara `handle_stage_advance`. En el panel, entrar a la etapa 2 crea una `ClientInstallation`
     * en `pendiente` (y en modo `auto` además manda mensajes y encola jobs). En este camino la
     * instalación la crea `install`, con la versión y el aprovisionamiento correctos; si avanzar también
     * la creara, `install` se encontraría con una fila que armó otro con la versión que quedó fijada al
     * promover, que puede ser vieja.
     *
     * 🔴 Desde la etapa 8 CIERRA y deja `current_stage` en 8. El panel la deja en 9, y además oculta el
     * botón en la 8 ("la última etapa cierra automáticamente"), con lo que una implementación no se
     * podía cerrar desde la interfaz. Importa: mientras esté `in_progress`, TODO lo que el cliente le
     * escribe al número de WhatsApp del sistema cae al hilo de la implementación y no llega a soporte ni
     * al asistente, así que una implementación que se queda abierta le deja mudo el WhatsApp.
     *
     * Frenos:
     *   1. Lista blanca de parámetros y tipos.
     *   2. `etapa_actual` tiene que ser la real (409 con la verdadera): es lo que impide avanzar dos veces
     *      por un reintento o por dos sesiones que miraron el mismo estado.
     *   3. Una implementación ya `completed` no avanza (409).
     *   4. Cerrar la etapa 2 con un user setup EN CURSO (no colgado) es 409: se espera a que termine (ver `conflicto_de_avance`).
     *   5. `dry_run` por defecto TRUE.
     * Con `dry_run=false` el cierre y la apertura van en una transacción con la fila de la implementación
     * bloqueada, y se vuelve a mirar la etapa adentro: dos POST simultáneos no avanzan dos etapas.
     *
     * ⚠️ NO valida que la etapa esté "lista" (el panel tampoco): la skill decide cuándo. Lo que sí hace es
     * AVISAR, sin frenar, lo que detecta que falta (formulario sin enviar, sistema sin instalar o sin
     * configurar).
     *
     * @param Request    $request Body: etapa_actual, saltar?, nota?, dry_run?.
     * @param int|string $id      Id de la implementación (segmento de la URL).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function advance_json(Request $request, $id)
    {
        $rechazo = $this->rechazar_parametros_de_mas($request, self::PARAMETROS_DEL_AVANCE, 'POST claude/implementations/{id}/advance');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $invalido = $this->validar_o_422($request, [
            'etapa_actual' => 'required|integer|between:1,8',
            'saltar'       => 'nullable|boolean',
            'nota'         => 'nullable|string|max:500',
            'dry_run'      => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $implementation = Implementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación ' . (int) $id);
        }

        $etapa_pedida = (int) $request->input('etapa_actual');
        $saltar       = $this->booleano_o_null($request, 'saltar') === true;
        $nota         = $this->texto_o_null($request->input('nota'));
        $dry_run      = $this->resolver_dry_run($request);

        /* Frenos 2 y 3, con lo que se ve ahora. Se repiten adentro del lock para el caso real. */
        $conflicto = $this->conflicto_de_avance($implementation, $etapa_pedida);
        if ($conflicto !== null) {
            return $conflicto;
        }

        $nombres = $this->nombres_de_etapas();
        $cierra  = $etapa_pedida === 8;
        $avisos  = $this->avisos_del_avance($implementation, $etapa_pedida, $saltar);

        if ($dry_run) {
            return response()->json([
                'dry_run'                  => true,
                'implementation_id'        => (int) $implementation->id,
                'de'                       => ['numero' => $etapa_pedida, 'nombre' => $nombres[$etapa_pedida]],
                'marca_la_etapa_como'      => $saltar ? 'skipped' : 'completed',
                'a'                        => $cierra ? null : ['numero' => $etapa_pedida + 1, 'nombre' => $nombres[$etapa_pedida + 1]],
                'cierra_la_implementacion' => $cierra,
                'guarda_nota'              => $nota !== null,
                'avisos'                   => $avisos,
                'nota'                     => 'Simulacro: no se avanzó nada. Repetí con dry_run=false para avanzar. Avanzar por acá NO crea la '
                    . 'instalación ni manda ningún mensaje: la instalación es POST claude/implementations/{id}/install.',
            ], 200);
        }

        $resultado = DB::transaction(function () use ($implementation, $etapa_pedida, $saltar, $nota) {
            $bloqueada = Implementation::query()->whereKey($implementation->id)->lockForUpdate()->first();

            $conflicto = $this->conflicto_de_avance($bloqueada, $etapa_pedida);
            if ($conflicto !== null) {
                return $conflicto;
            }

            return $this->aplicar_el_avance($bloqueada, $etapa_pedida, $saltar, $nota);
        });

        if ($resultado instanceof \Illuminate\Http\JsonResponse) {
            return $resultado;
        }

        $cerrada   = $resultado['cerrada'];
        $siguiente = $resultado['siguiente'];

        return response()->json([
            'dry_run'                  => false,
            'implementation'           => [
                'id'            => (int) $resultado['implementation']->id,
                'current_stage' => (int) $resultado['implementation']->current_stage,
                'status'        => (string) $resultado['implementation']->status,
                'completed_at'  => $this->instante($resultado['implementation']->completed_at),
            ],
            'etapa_cerrada'            => ['numero' => $etapa_pedida, 'nombre' => $nombres[$etapa_pedida], 'estado' => $saltar ? 'skipped' : 'completed'],
            'etapa_actual'             => $siguiente === null ? null : ['numero' => (int) $siguiente->stage_number, 'nombre' => $nombres[(int) $siguiente->stage_number], 'estado' => (string) $siguiente->status],
            'cierra_la_implementacion' => $cerrada,
            'nota_guardada'            => $nota !== null,
            'avisos'                   => $avisos,
        ], 200);
    }

    /**
     * ¿Se puede avanzar con esta etapa pedida? Si no, el 409 que corresponde.
     *
     * Tres casos, todos 409 y no 422: no es que el pedido esté mal armado, es que el estado de la
     * implementación ya no es el que el que llama miró.
     *   - Ya está completada: no hay etapa a la que avanzar.
     *   - `etapa_actual` no es la real: se devuelve la real para que la skill relea y decida, en vez de
     *     avanzar dos veces por un reintento.
     *   - Se pide cerrar la etapa 2 con un user setup EN CURSO (no colgado): se espera a que termine.
     *
     * @param Implementation $implementation La implementación (bloqueada, si se llama adentro del lock).
     * @param int            $etapa_pedida   La etapa que dice el que llama.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si se puede avanzar.
     */
    protected function conflicto_de_avance(Implementation $implementation, $etapa_pedida)
    {
        if ($implementation->status === 'completed') {
            return response()->json([
                'error'             => 'La implementación ' . (int) $implementation->id . ' ya está completada: no hay etapa a la que avanzar. No se hizo nada.',
                'implementation_id' => (int) $implementation->id,
                'status'            => 'completed',
                'current_stage'     => (int) $implementation->current_stage,
            ], 409);
        }

        if ((int) $implementation->current_stage !== (int) $etapa_pedida) {
            return response()->json([
                'error'             => 'La implementación ' . (int) $implementation->id . ' no está en la etapa ' . (int) $etapa_pedida . ': está en la '
                    . (int) $implementation->current_stage . '. No se hizo nada (es lo que evita avanzar dos veces). Releé el estado y decidí de nuevo.',
                'implementation_id' => (int) $implementation->id,
                'status'            => (string) $implementation->status,
                'current_stage'     => (int) $implementation->current_stage,
                'etapa_pedida'      => (int) $etapa_pedida,
            ], 409);
        }

        /* 🔴 De la etapa 2 no se avanza con un user setup EN CURSO. El job se despacha en la etapa 2 y recién después
           llama al cliente; si se avanza a la 3 mientras corre (o espera en la cola), el negocio pasa a una etapa donde ya se
           le cargan datos mientras su sistema se está vaciando y re-sembrando (migrate:fresh). Uno colgado (más de 45
           minutos sin señal) no cuenta: ahí hay que resolverlo (conciliar o reintentar), no esperarlo. */
        if ((int) $etapa_pedida === 2 && $implementation->user_setup_executed_at === null) {
            $registro = $this->registro_del_user_setup($implementation);

            if ((isset($registro['estado']) ? $registro['estado'] : '') === 'en_curso' && ! $this->esta_colgado($registro)) {
                return response()->json([
                    'error'             => 'Hay un user setup en curso (arrancó ' . (isset($registro['iniciado_at']) ? (string) $registro['iniciado_at'] : 'sin fecha')
                        . '): esperá a que termine antes de avanzar de la etapa 2. No se hizo nada.',
                    'implementation_id' => (int) $implementation->id,
                    'user_setup'        => $this->estado_del_user_setup($implementation),
                    'ayuda'             => 'GET claude/implementations/' . (int) $implementation->id . ' dice cómo va (user_setup.estado: en_curso → ok | error).',
                ], 409);
            }
        }

        return null;
    }

    /**
     * Lo que se avisa al avanzar, sin frenar: lo que se detecta que probablemente falta.
     *
     * 🔴 Son AVISOS y no frenos a propósito: el panel no valida nada al avanzar y hay casos legítimos de
     * avanzar sin eso (un cliente que cargó el formulario a mano, una instalación hecha por afuera). Lo
     * que no se puede es avanzar sin enterarse.
     *
     * @param Implementation $implementation La implementación.
     * @param int            $etapa          La etapa que se cierra.
     * @param bool           $saltar         true = se marca skipped en vez de completed.
     *
     * @return array<int, string>
     */
    protected function avisos_del_avance(Implementation $implementation, $etapa, $saltar)
    {
        $avisos = [];

        if ($etapa === 1 && $implementation->form_submitted_at === null && ! $saltar) {
            $avisos[] = 'El cliente todavía no envió el formulario: se cierra la etapa 1 sin sus respuestas.';
        }

        if ($etapa === 2 && ! $saltar) {
            $instalada = ClientInstallation::where('client_id', $implementation->client_id)
                ->where('kind', ClientInstallation::KIND_COMPLETA)
                ->where('status', 'completada')
                ->exists();

            if (! $instalada) {
                $avisos[] = 'El sistema del cliente todavía no figura instalado (ninguna instalación completa en estado completada): se cierra la etapa 2 igual.';
            }

            if ($implementation->user_setup_executed_at === null) {
                $avisos[] = 'El user setup todavía no se aplicó (user_setup_executed_at vacío): el sistema del cliente no tiene la configuración del formulario.';
            }
        }

        if ($saltar) {
            $avisos[] = 'La etapa ' . (int) $etapa . ' queda marcada como skipped (no aplica) y no como completed.';
        }

        if ($etapa === 8) {
            $avisos[] = 'Es la etapa 8: avanzar CIERRA la implementación (status completed, current_stage queda en 8). Desde ese momento lo que el '
                . 'cliente le escriba al número de WhatsApp del sistema vuelve a soporte y al asistente, en vez de caer al hilo de la implementación.';
        }

        return $avisos;
    }

    /**
     * Aplica el avance sobre una implementación YA bloqueada y verificada: cierra la etapa, abre la
     * siguiente (o cierra la implementación) y guarda la nota.
     *
     * 🔴 NO llama a `handle_stage_advance` y no hay que agregárselo: ver el docblock de advance_json().
     *
     * @param Implementation $implementation La implementación, bloqueada.
     * @param int            $etapa          La etapa que se cierra (1 a 8), ya verificada contra la real.
     * @param bool           $saltar         true = la etapa queda skipped.
     * @param string|null    $nota           Nota a guardar en `data.notas` de la etapa que se cierra.
     *
     * @return array<string, mixed> `implementation`, `cerrada` (bool) y `siguiente` (la etapa que quedó en curso, o null).
     */
    protected function aplicar_el_avance(Implementation $implementation, $etapa, $saltar, $nota)
    {
        $actual = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', $etapa)
            ->lockForUpdate()
            ->first();

        if ($actual !== null) {
            $actual->status       = $saltar ? 'skipped' : 'completed';
            $actual->completed_at = now();

            if ($nota !== null) {
                $datos           = is_array($actual->data) ? $actual->data : [];
                $datos['notas']  = isset($datos['notas']) && is_array($datos['notas']) ? $datos['notas'] : [];
                $datos['notas'][] = ['texto' => $nota, 'at' => now()->toISOString(), 'origen' => 'claude'];
                $actual->data    = $datos;
            }

            $actual->save();
        }

        if ($etapa >= 8) {
            $implementation->status       = 'completed';
            $implementation->completed_at = now();
            $implementation->save();

            return ['implementation' => $implementation, 'cerrada' => true, 'siguiente' => null];
        }

        $implementation->current_stage = $etapa + 1;
        $implementation->save();

        $siguiente = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', $etapa + 1)
            ->lockForUpdate()
            ->first();

        if ($siguiente !== null) {
            $siguiente->status     = 'in_progress';
            $siguiente->started_at = now();
            $siguiente->save();
        }

        return ['implementation' => $implementation, 'cerrada' => false, 'siguiente' => $siguiente];
    }

    /* ==============================================================================================
     | 4) POST claude/implementations/{id}/actions — REGISTRAR lo que se hizo por fuera
     |============================================================================================= */

    /**
     * Registra una acción que se hizo por fuera del admin —un WhatsApp mandado por WhatsApp Web, un mail,
     * una llamada— para que el panel y el estado la vean.
     *
     * 🔴 ESTO SOLO REGISTRA. No manda nada, ni WhatsApp ni mail ni nada: el mensaje ya salió, desde la
     * skill, por WhatsApp Web y con el ok de Lucas. Lo que hace este endpoint es dejar la huella.
     *
     * Dos efectos:
     *  1. Agrega `{action, stage, at, canal, origen: "claude"}` a `data.actions[]` de la etapa
     *     (`etapa`, por defecto la actual). Es el mismo registro que escriben las acciones del panel, así
     *     que el checklist del panel se tilda (`presentacion`, `form_link`, `pedir_archivos` y `entrega`
     *     son las claves que lee la SPA) y `GET claude/implementations/{id}` la muestra con su origen.
     *  2. Con `canal=whatsapp_web`, además crea el `implementation_messages` SALIENTE con el texto: así
     *     el hilo del panel muestra lo que se le mandó al cliente y `entrantes` sabe que ya se le
     *     respondió.
     *
     * 🔴 EL `whatsapp_message_id` DEL SALIENTE NUNCA ES NULL: es `waweb-<uuid>`. Un saliente con id nulo
     * se lee como un envío FALLIDO (la tabla no tiene columna de estado; `send_outbound` persiste igual
     * lo que no salió y el único rastro es el id vacío), y no cuenta como respuesta en `entrantes`.
     * Lo que se mandó por WhatsApp Web sí salió, así que tiene que llevar id.
     *
     * 🔴 ES IDEMPOTENTE: la misma acción, por el mismo canal, para la misma etapa y con el mismo texto,
     * en los últimos 10 minutos, devuelve 200 `{ya_registrada: true}` sin duplicar nada. Es lo que hace
     * seguro reintentar una llamada que cortó el timeout: sin esto, el reintento duplicaba la fila del
     * hilo y dejaba dos entradas en el registro. Pasados los 10 minutos es otro envío (el mismo texto
     * puede mandarse de nuevo a propósito, p. ej. un recordatorio). La comprobación y el alta van en una
     * transacción con la fila de la etapa bloqueada: dos llamadas simultáneas tampoco duplican.
     *
     * No tiene `dry_run`: lo único que escribe es la huella de algo que ya pasó, y no hay nada que
     * simular.
     *
     * @param Request    $request Body: accion, canal, texto?, telefono?, etapa?.
     * @param int|string $id      Id de la implementación (segmento de la URL).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function actions_json(Request $request, $id)
    {
        $rechazo = $this->rechazar_parametros_de_mas($request, self::PARAMETROS_DE_LA_ACCION, 'POST claude/implementations/{id}/actions');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $invalido = $this->validar_o_422($request, [
            'accion'   => 'required|string|in:' . implode(',', self::ACCIONES),
            'canal'    => 'required|string|in:' . implode(',', self::CANALES),
            'texto'    => 'required_if:canal,whatsapp_web|nullable|string|max:4000',
            'telefono' => 'nullable|string|max:30',
            'etapa'    => 'nullable|integer|between:1,8',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $implementation = Implementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación ' . (int) $id);
        }

        $accion = (string) $request->input('accion');
        $canal  = (string) $request->input('canal');
        $texto  = $this->texto_o_null($request->input('texto'));
        $etapa  = $this->entero_o_null($request->input('etapa'));
        $etapa  = $etapa === null ? (int) $implementation->current_stage : $etapa;

        /* El teléfono del mensaje, resuelto ANTES de abrir la transacción: sin teléfono no hay forma de
           que el hilo muestre a quién se le mandó, y es mejor decirlo ahora que escribir la mitad. */
        $telefono = null;
        if ($canal === 'whatsapp_web') {
            $telefono = $this->telefono_del_mensaje($implementation, $accion, $this->texto_o_null($request->input('telefono')));

            if ($telefono === null) {
                return $this->error_422(
                    'No hay un teléfono al que se le mandó el mensaje: el cliente no tiene teléfono cargado y no mandaste `telefono`. No se registró nada.',
                    ['ayuda' => 'Pasá `telefono` (se normaliza a E.164) o cargale el teléfono al cliente.']
                );
            }
        }

        $resultado = DB::transaction(function () use ($implementation, $accion, $canal, $texto, $etapa, $telefono) {
            return $this->registrar_la_accion($implementation, $accion, $canal, $texto, $etapa, $telefono);
        });

        if ($resultado === 'sin_etapa') {
            return $this->error_422(
                'La implementación ' . (int) $implementation->id . ' no tiene la etapa ' . $etapa . ' cargada. No se registró nada.',
                ['implementation_id' => (int) $implementation->id, 'etapa' => $etapa]
            );
        }

        if (isset($resultado['ya_registrada'])) {
            return response()->json([
                'registrada'    => false,
                'ya_registrada' => true,
                'accion'        => $accion,
                'canal'         => $canal,
                'etapa'         => $etapa,
                'at'            => isset($resultado['ya_registrada']['at']) ? (string) $resultado['ya_registrada']['at'] : null,
                'mensaje'       => null,
                'nota'          => 'Es la misma acción por el mismo canal, con el mismo texto, registrada hace menos de '
                    . self::MINUTOS_DE_IDEMPOTENCIA . ' minutos: no se duplicó nada.',
            ], 200);
        }

        $mensaje = $resultado['mensaje'];

        /* El panel se entera del saliente recién creado (el evento Pusher del hilo), DESPUÉS del commit. */
        if ($mensaje !== null) {
            $this->avisar_al_hilo($implementation, $mensaje);
        }

        return response()->json([
            'registrada'    => true,
            'ya_registrada' => false,
            'accion'        => $accion,
            'canal'         => $canal,
            'etapa'         => $etapa,
            'at'            => (string) $resultado['entrada']['at'],
            'mensaje'       => $mensaje === null ? null : [
                'id'                  => (int) $mensaje->id,
                'whatsapp_message_id' => (string) $mensaje->whatsapp_message_id,
                /* Enmascarado: lo lee una sesión que lo pega en una conversación, y para saber a quién salió
                   alcanza con el final. Entero queda en la base (lo necesita el hilo). */
                'telefono'            => $mensaje->phone === null ? null : $this->enmascarar_un_numero((string) $mensaje->phone),
            ],
        ], 201);
    }

    /**
     * Avisa al panel que el hilo de la implementación tiene un mensaje nuevo (el evento Pusher del hilo, el
     * mismo que emite el panel cuando manda uno), para que lo agregue al hilo abierto sin recargar.
     *
     * 🔴 Nunca rompe el registro: lo que se registra es algo que YA pasó (el WhatsApp ya salió) y no se puede
     * deshacer porque falle un aviso. Un Pusher caído o sin credencial se loguea y se sigue.
     *
     * @param Implementation        $implementation La implementación.
     * @param ImplementationMessage $mensaje        El saliente recién creado.
     *
     * @return void
     */
    protected function avisar_al_hilo(Implementation $implementation, ImplementationMessage $mensaje)
    {
        try {
            ImplementationBroadcastService::emit_message_received((int) $implementation->id, (int) $mensaje->id);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('ClaudeImplementationOpsController: no se pudo avisar al panel del mensaje registrado.', [
                'implementation_id' => (int) $implementation->id,
                'mensaje_id'        => (int) $mensaje->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Escribe la huella de la acción en la etapa (y, con WhatsApp Web, el saliente del hilo), salvo que ya
     * esté registrada. Se llama ADENTRO de una transacción.
     *
     * Bloquea la fila de la etapa con `lockForUpdate()` y recién ahí mira si ya existe la misma entrada
     * reciente: el chequeo de idempotencia y el alta tienen que ser una sola operación, o dos llamadas
     * simultáneas pasarían las dos el chequeo y duplicarían.
     *
     * La igualdad de "la misma acción" es: mismo `action`, mismo `canal`, misma `stage`, mismo texto (por
     * su `sha1`, que se guarda en la entrada como `texto_hash` para no repetir hasta 4000 caracteres por
     * cada registro), MISMO DESTINATARIO (el `sha1` del teléfono ya normalizado, en `telefono_hash`) y
     * `origen = claude`. Las entradas del panel no cuentan: las escribe otro camino.
     *
     * 🔴 El destinatario es parte de la clave: el mismo texto a DOS teléfonos distintos (el dueño y el
     * responsable de migración, por ejemplo) son dos mensajes, y el segundo no puede descartarse como
     * repetición del primero —no se registraría ni su huella ni su saliente, y nadie se enteraría—. Se guarda
     * el hash y no el teléfono para no dejar el número suelto en el JSON de la etapa.
     *
     * @param Implementation $implementation La implementación.
     * @param string         $accion         Una de ACCIONES.
     * @param string         $canal          Uno de CANALES.
     * @param string|null    $texto          Texto del mensaje (o de la nota).
     * @param int            $etapa          Etapa en la que se registra (1 a 8).
     * @param string|null    $telefono       Teléfono E.164 del mensaje (solo con whatsapp_web).
     *
     * @return array<string, mixed>|string `sin_etapa` si la etapa no existe; si no, un array con
     *                                     `ya_registrada` (la entrada que ya estaba) o con `entrada` y
     *                                     `mensaje` (el saliente creado, o null).
     */
    protected function registrar_la_accion(Implementation $implementation, $accion, $canal, $texto, $etapa, $telefono)
    {
        $registro = ImplementationStage::where('implementation_id', $implementation->id)
            ->where('stage_number', $etapa)
            ->lockForUpdate()
            ->first();

        if ($registro === null) {
            return 'sin_etapa';
        }

        $datos    = is_array($registro->data) ? $registro->data : [];
        $entradas = isset($datos['actions']) && is_array($datos['actions']) ? $datos['actions'] : [];
        $hash     = sha1($texto === null ? '' : $texto);
        $hash_tel = $telefono === null ? null : sha1($telefono);
        $limite   = now()->subMinutes(self::MINUTOS_DE_IDEMPOTENCIA);

        foreach ($entradas as $existente) {
            if (! is_array($existente)
                || (isset($existente['origen']) ? $existente['origen'] : null) !== 'claude'
                || (isset($existente['action']) ? $existente['action'] : null) !== $accion
                || (isset($existente['canal']) ? $existente['canal'] : null) !== $canal
                || (int) (isset($existente['stage']) ? $existente['stage'] : 0) !== (int) $etapa
                || (isset($existente['texto_hash']) ? $existente['texto_hash'] : null) !== $hash
                || (isset($existente['telefono_hash']) ? $existente['telefono_hash'] : null) !== $hash_tel) {
                continue;
            }

            $cuando = $this->parsear_o_null(isset($existente['at']) ? $existente['at'] : null);

            if ($cuando !== null && $cuando->gte($limite)) {
                return ['ya_registrada' => $existente];
            }
        }

        $entrada = [
            'action'     => $accion,
            'stage'      => (int) $etapa,
            'at'         => now()->toISOString(),
            'canal'      => $canal,
            'origen'     => 'claude',
            'texto_hash' => $hash,
        ];

        if ($hash_tel !== null) {
            $entrada['telefono_hash'] = $hash_tel;
        }

        /* El texto de una nota es la nota: no hay otro lugar donde guardarlo. El de un WhatsApp vive en el
           mensaje del hilo, y el de un mail o una llamada no hace falta repetirlo. */
        if ($accion === 'nota' && $texto !== null) {
            $entrada['texto'] = $texto;
        }

        $entradas[]      = $entrada;
        $datos['actions'] = $entradas;
        $registro->data  = $datos;
        $registro->save();

        $mensaje = null;

        if ($canal === 'whatsapp_web') {
            $mensaje = ImplementationMessage::create([
                'implementation_id'   => $implementation->id,
                'stage_number'        => (int) $etapa,
                'direction'           => 'outbound',
                'phone'               => $telefono,
                'body'                => (string) $texto,
                'whatsapp_message_id' => 'waweb-' . Str::uuid()->toString(),
                'sent_at'             => now(),
            ]);
        }

        return ['entrada' => $entrada, 'mensaje' => $mensaje];
    }

    /**
     * El teléfono (E.164) al que se le mandó un mensaje de WhatsApp Web: el que se pasó o, si no, el del
     * destinatario que corresponde.
     *
     * Mismo criterio que `ImplementationActionService::resolve_recipient_phone()`: el pedido de archivos
     * va al RESPONSABLE DE MIGRACIÓN (y si no lo hay, al dueño) y todo lo demás al dueño.
     *
     * 🔴 Se normaliza con `WhatsappNormalizer`, el MISMO con el que el webhook (`WhatsappWebhookController`)
     * normaliza el `from` de lo que ENTRA antes de guardarlo en el hilo: el hilo compara teléfonos como texto
     * exacto (la ventana de 24 h, por ejemplo), y un saliente guardado con otro formato que el entrante de la
     * misma persona los trata como dos personas. Esta ruta usaba `ArgentinePhoneNormalizer`, que el webhook
     * NO usa (lo usan el mapeo del formulario y la conversación automática): no saca el 0 de marcado local y
     * trata distinto el 15.
     *
     * @param Implementation $implementation La implementación.
     * @param string         $accion         La acción que se registra.
     * @param string|null    $pedido         `telefono` tal como llegó.
     *
     * @return string|null El teléfono, o null si no hay ninguno.
     */
    protected function telefono_del_mensaje(Implementation $implementation, $accion, $pedido)
    {
        $crudo = $pedido;

        if ($crudo === null) {
            $implementation->loadMissing('client');

            $del_dueno = $implementation->client === null ? '' : trim((string) $implementation->client->phone);
            $del_resp  = trim((string) $implementation->migration_contact_phone);

            $crudo = $accion === 'pedir_archivos' && $del_resp !== '' ? $del_resp : $del_dueno;
        }

        $crudo = trim((string) $crudo);

        if ($crudo === '') {
            return null;
        }

        $normalizado = WhatsappNormalizer::normalize($crudo);

        return $normalizado === '' ? null : $normalizado;
    }

    /* ==============================================================================================
     | 5) POST claude/implementations/{id}/install — INSTALAR el sistema (el admin aprovisiona)
     |============================================================================================= */

    /**
     * Instala el sistema del cliente: crea (o reutiliza) el par de instalaciones —la real en la API
     * activa y el esqueleto en la otra— con el aprovisionamiento del hosting compartido y las encola en la
     * cola `database`.
     *
     * 🔴 ESTO ESCRIBE EN EL HOSTING DE UN NEGOCIO. El pipeline crea los cuatro subdominios, la base y el
     * cron en Hostinger, sube el SPA y el código de la API por SFTP y escribe el `.env`. Es el equivalente
     * de apretar "Nueva instalación" + "Iniciar" en el módulo de Instalaciones del panel, hecho de una vez
     * y con más frenos, porque desde acá no hay una persona mirando la pantalla.
     *
     * 🔴 SIEMPRE con la ÚLTIMA versión publicada y SOLO con el token de Hostinger del admin. La versión que
     * `ensure_client_installation()` fija al avanzar a la etapa 2 sale de `clients.current_version_id`, que
     * se grabó al PROMOVER y puede tener semanas; el botón del panel sí usa la última publicada, y esto
     * también. Sin token de Hostinger el pipeline no puede crear los subdominios y moriría en
     * `provision_check`: acá se frena antes, con un mensaje que manda a `/instalar-cliente` (que los crea
     * desde la máquina de Lucas).
     *
     * Frenos, en este orden, todos ANTES de escribir:
     *   1. Lista blanca de parámetros y tipos.
     *   2. `confirm_client_name` exacto (`clients.name`), sin revelar el correcto.
     *   3. Nueve chequeos (`chequeos` del dry-run): la etapa y el formulario, la estructura del hosting (las
     *      cinco guardas de `HostingProvisioningStructure` más la coherencia del hosting), las URLs en
     *      https, una versión publicada, la credencial SSH del compartido, el token de Hostinger, las
     *      instalaciones previas, que el cliente no tenga ya un sistema vivo (`sin_sistema_vivo`: filas en
     *      `client_version_upgrades`) y las variables manuales del `.env`. Con uno en false, 422.
     *   4. Las instalaciones previas: una `instalando` es 409 (no se pisa un pipeline vivo) y una
     *      `completada` es 422 (ya está instalado: reinstalar le pisa el `.env` a un negocio que ya
     *      anda).
     *   5. `dry_run` (default TRUE): devuelve los chequeos y lo que crearía.
     *
     * 🔴 UNA INSTALACIÓN `instalando` QUE NADIE VA A TERMINAR. Si el worker muere sin pasar por `failed()` (un
     * `kill -9`, un reinicio, un `$timeout` sin `pcntl`) la fila queda `instalando` para siempre, y como el
     * 409 frena mientras haya una, ese cliente no podría volver a instalar. El estado (GET) marca `colgada`
     * a la que lleva más de 60 minutos sin actividad (ni logs ni escrituras de la fila), y `marcar_colgadas`
     * la destraba: si TODAS las `instalando` del cliente están colgadas las pasa a `fallida` con "colgada: sin
     * actividad desde <fecha>" y sigue con un par nuevo; si UNA tiene actividad reciente, 409 como siempre (no
     * se pisa un pipeline vivo). No salta ningún otro chequeo, y con la instalación real ya completada no
     * reinstala.
     *
     * Con `dry_run=false` el re-chequeo y el alta van en UNA transacción con la implementación bloqueada
     * (dos POST simultáneos no crean dos pares), las filas pasan a `instalando` ahí mismo —igual que
     * `start()` del panel— y el job se despacha DESPUÉS del commit: un worker real puede levantar el job
     * antes de que la transacción cierre y encontrarse las filas todavía en `pendiente`.
     *
     * Responde 202 y nunca espera: se mira con `GET claude/implementations/{id}` (`instalaciones[]`, con
     * `include=logs`) cada 30 o 60 segundos.
     *
     * @param Request    $request Body: dry_run?, confirm_client_name, marcar_colgadas?.
     * @param int|string $id      Id de la implementación (segmento de la URL).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function instalar_json(Request $request, $id)
    {
        /* --- Freno 1: lista blanca y tipos. --- */
        $rechazo = $this->rechazar_parametros_de_mas($request, self::PARAMETROS_DE_LA_INSTALACION, 'POST claude/implementations/{id}/install');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $invalido = $this->validar_o_422($request, [
            'dry_run'             => 'nullable|boolean',
            'confirm_client_name' => 'required_if:dry_run,false|nullable|string|max:190',
            'marcar_colgadas'     => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $marcar_colgadas = $this->booleano_o_null($request, 'marcar_colgadas') === true;

        $implementation = Implementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación ' . (int) $id);
        }

        $client = Client::find((int) $implementation->client_id);
        if ($client === null) {
            return $this->error_404('la implementación ' . (int) $id . ' apunta a un cliente que no existe');
        }

        $dry_run = $this->resolver_dry_run($request);
        $plan    = $this->plan_de_la_instalacion($implementation, $client, $marcar_colgadas);

        if ($dry_run) {
            return response()->json($this->respuesta_del_dry_run_de_la_instalacion($implementation, $plan, $client), 200);
        }

        /* --- Freno 2: confirmación por nombre. --- */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se instaló nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        /* --- Frenos 3 y 4, con lo que se ve ahora. Se repiten adentro del lock. --- */
        $impedimento = $this->impedimento_de_la_instalacion($implementation, $plan);
        if ($impedimento !== null) {
            return $impedimento;
        }

        $resultado = DB::transaction(function () use ($implementation, $client, $marcar_colgadas) {
            $bloqueada = Implementation::query()->whereKey($implementation->id)->lockForUpdate()->first();

            /* Todo lo que depende de un estado que puede haber cambiado (la etapa, las instalaciones) se
               vuelve a leer con la implementación bloqueada. */
            $plan = $this->plan_de_la_instalacion($bloqueada, $client, $marcar_colgadas);

            $impedimento = $this->impedimento_de_la_instalacion($bloqueada, $plan);
            if ($impedimento !== null) {
                return $impedimento;
            }

            return [
                'filas'    => $this->preparar_el_par_de_instalaciones($client, $plan),
                'colgadas' => $plan['estado_previo'] === 'colgadas' ? $plan['ids_colgadas'] : [],
            ];
        });

        if ($resultado instanceof \Illuminate\Http\JsonResponse) {
            return $resultado;
        }

        $colgadas_marcadas = $resultado['colgadas'];
        $resultado         = $resultado['filas'];

        $uuids = [];
        foreach ($resultado as $fila) {
            $uuids[] = (string) $fila->uuid;
        }

        /* 🔴 onConnection explícito y DESPUÉS del commit. Con la conexión por defecto (`sync`) el pipeline
           correría entero adentro de este request y lo mataría `max_execution_time`.

           🔴 Y con red: las filas YA están en `instalando` (commiteadas), así que si encolar falla (la tabla
           `jobs`, la base, lo que sea) quedarían así para siempre sin ningún job que las corra, y como `install`
           frena con 409 mientras haya una instalando, ese cliente no podría reintentar. Se vuelven a
           `pendiente` —que es lo que eran: nunca arrancaron— y se dice. */
        try {
            EjecutarInstalacionDeImplementacionJob::dispatch($uuids)->onConnection(self::CONEXION_DE_COLA);
        } catch (\Throwable $e) {
            ClientInstallation::query()
                ->whereIn('uuid', $uuids)
                ->where('status', 'instalando')
                ->update(['status' => 'pendiente', 'started_at' => null, 'finished_at' => null]);

            Log::channel('daily')->error('ClaudeImplementationOpsController: no se pudo encolar la instalación.', [
                'implementation_id' => (int) $implementation->id,
                'error'             => $e->getMessage(),
            ]);

            return response()->json([
                'error'             => 'No se pudo encolar la instalación: ' . $e->getMessage() . '. Las instalaciones volvieron a pendiente (nunca '
                    . 'arrancaron): se puede reintentar la misma llamada.',
                'implementation_id' => (int) $implementation->id,
                'reintentable'      => true,
            ], 500);
        }

        return response()->json([
            'dry_run'                  => false,
            'implementation_id'        => (int) $implementation->id,
            'group_uuid'               => (string) $resultado[0]->group_uuid,
            'instalaciones'            => array_map(function ($fila) {
                return $this->instalacion_en_corto($fila);
            }, $resultado),
            'version'                  => $this->version_en_corto($resultado[0]->version_id),
            'colgadas_marcadas_como_fallidas' => $colgadas_marcadas,
            'conexion_de_cola'         => self::CONEXION_DE_COLA,
            'latencia_maxima_segundos' => self::LATENCIA_MAXIMA_SEGUNDOS,
            'nota'                     => 'Se encoló la instalación (la real y el esqueleto del subdominio hermano, en ese orden). 🔴 Tarda ~15 '
                . 'minutos: poleá cada 30 o 60 segundos con GET claude/implementations/' . (int) $implementation->id . '?include=logs '
                . '(instalaciones[].status: instalando → completada | fallida), no cada 2 (rate limit por IP). Cuando la real esté completada, '
                . 'sigue POST claude/implementations/' . (int) $implementation->id . '/user-setup.',
        ], 202);
    }

    /**
     * Los nueve chequeos de la instalación y todo lo que hace falta para crear el par.
     *
     * Lee, no escribe. Se llama dos veces en el camino real —antes de la transacción, para contestar
     * rápido, y adentro con la implementación bloqueada— y una en el dry-run.
     *
     * Con `$marcar_colgadas` (el parámetro del pedido), si TODAS las instalaciones `instalando` del cliente
     * están colgadas (ver `MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION`) el plan las da por liberables:
     * `estado_previo` es `colgadas` y el chequeo `instalaciones_previas` pasa. Con una sola viva, o sin el
     * parámetro, sigue siendo `instalando` (409): nunca se pisa un pipeline vivo.
     *
     * @param Implementation $implementation   La implementación.
     * @param Client         $client           Su cliente.
     * @param bool           $marcar_colgadas  `marcar_colgadas` del pedido.
     *
     * @return array<string, mixed> `chequeos`, `estado_previo` (instalando | colgadas | completada |
     *                              pendientes | nueva), `ids_instalando`, `ids_colgadas`, `todas_colgadas`,
     *                              `ultimas_actividades` (id → Carbon), `apis` (`activa` y `otra`), `version`,
     *                              `variables` (`se_completan`, `exentas`, `faltan`) y `existentes` (los valores
     *                              manuales que las filas pendientes ya traen).
     */
    protected function plan_de_la_instalacion(Implementation $implementation, Client $client, $marcar_colgadas = false)
    {
        $chequeos = [];

        /* 1. La etapa y el formulario. */
        $formulario = $this->formulario_enviado($implementation, $client);

        $en_la_etapa_2 = (int) $implementation->current_stage === 2;
        $faltantes     = [];
        if (! $en_la_etapa_2) {
            $faltantes[] = 'la implementación está en la etapa ' . (int) $implementation->current_stage . ' y tiene que estar en la 2 (avanzá con POST claude/implementations/{id}/advance)';
        }
        if (! $formulario) {
            $faltantes[] = 'el cliente todavía no envió el formulario';
        }

        $chequeos[] = $this->chequeo(
            'etapa_y_formulario',
            count($faltantes) === 0,
            count($faltantes) === 0 ? 'Está en la etapa 2 y el formulario ya se envió.' : ucfirst(implode('; ', $faltantes)) . '.'
        );

        /* 2. La estructura del hosting: las cinco guardas, la coherencia del hosting y cuál es cuál. */
        $activa = $client->active_client_api_id === null
            ? null
            : ClientApi::where('id', (int) $client->active_client_api_id)->where('client_id', $client->id)->first();
        $otra   = null;

        if ($activa === null) {
            $chequeos[] = $this->chequeo('estructura_del_hosting', false, 'El cliente no tiene una API activa (clients.active_client_api_id): no se sabe en cuál instalar.');
        } else {
            try {
                $estructura = new HostingProvisioningStructure($activa, (string) config('services.hostinger.database_prefix', ''));
                $slug       = $estructura->slug();
                $estructura->assert_hosting_type_coherente(ClientInstallation::PROVISION_SHARED_HOSTING);

                foreach ($estructura->apis() as $api) {
                    if ((int) $api->id !== (int) $activa->id) {
                        $otra = $api;
                    }
                }

                if ($otra === null) {
                    $chequeos[] = $this->chequeo('estructura_del_hosting', false, 'La API activa del cliente no es una de las dos del par <slug> / <slug>2.');
                } else {
                    $chequeos[] = $this->chequeo(
                        'estructura_del_hosting',
                        true,
                        'Par estándar de "' . $slug . '": la real va en ' . $activa->url . ' y el esqueleto en ' . $otra->url . '. Hosting compartido.'
                    );
                }
            } catch (\RuntimeException $e) {
                $chequeos[] = $this->chequeo('estructura_del_hosting', false, $e->getMessage());
            }
        }

        /* 3. Las URLs en https. */
        $sin_https = [];
        foreach (array_filter([$activa, $otra]) as $api) {
            foreach (['url' => $api->url, 'spa_url' => $api->spa_url] as $campo => $valor) {
                if (stripos(trim((string) $valor), 'https://') !== 0) {
                    $sin_https[] = 'ClientApi ' . (int) $api->id . ' (' . $campo . ': "' . (string) $valor . '")';
                }
            }
        }

        $chequeos[] = $this->chequeo(
            'urls_https',
            $activa !== null && $otra !== null && count($sin_https) === 0,
            $activa === null || $otra === null
                ? 'No se pudieron revisar las URLs porque falta resolver el par de APIs.'
                : (count($sin_https) === 0
                    ? 'Las URLs de las dos APIs empiezan con https://.'
                    : 'Tienen que ser https:// (con http el servidor redirige y la redirección convierte el PUT final en GET): ' . implode(', ', $sin_https) . '.')
        );

        /* 4. La última versión publicada (no la que quedó fijada al promover). */
        $version = Version::where('status', 'published')->orderByDesc('id')->first();

        $chequeos[] = $this->chequeo(
            'version_publicada',
            $version !== null,
            $version === null
                ? 'No hay ninguna versión publicada: no hay nada que instalar.'
                : 'Se instala la última publicada: ' . $version->version . ' (id ' . (int) $version->id . ').'
        );

        /* 5. La credencial SSH del hosting compartido. */
        $credencial = ClientSshCredential::where('type', ClientInstallation::PROVISION_SHARED_HOSTING)->exists();

        $chequeos[] = $this->chequeo(
            'credencial_ssh_shared',
            $credencial,
            $credencial ? 'Hay credencial SSH del hosting compartido.' : 'Falta la credencial SSH de tipo shared_hosting (client_ssh_credentials).'
        );

        /* 6. El token de Hostinger: sin él el pipeline no puede crear los subdominios. */
        $token = trim((string) config('services.hostinger.api_token')) !== '';

        $chequeos[] = $this->chequeo(
            'token_de_hostinger',
            $token,
            $token
                ? 'El admin tiene el token de Hostinger: el pipeline crea los cuatro subdominios, la base y el cron.'
                : 'sin token de Hostinger en el admin: instalar con /instalar-cliente (crea los subdominios y la base desde la máquina de Lucas).'
        );

        /* 7. Las instalaciones previas del cliente. */
        $previas       = ClientInstallation::where('client_id', $client->id)->orderBy('id')->get();
        $ids_instalando = $previas->where('status', 'instalando')->pluck('id')->map(function ($valor) {
            return (int) $valor;
        })->all();
        $completada    = $previas->where('kind', ClientInstallation::KIND_COMPLETA)->where('status', 'completada')->first();
        $pendientes    = $previas->where('status', 'pendiente');

        /* 🔴 Cuáles de las instalando están colgadas: sin actividad hace más de 60 minutos. */
        $ultimas_actividades = [];
        $ids_colgadas        = [];
        foreach ($previas->where('status', 'instalando') as $en_curso) {
            $ultima = $this->ultima_actividad_de_la_instalacion($en_curso);
            $ultimas_actividades[(int) $en_curso->id] = $ultima;

            if ($this->esta_colgada_la_instalacion($en_curso, $ultima)) {
                $ids_colgadas[] = (int) $en_curso->id;
            }
        }
        $todas_colgadas = count($ids_instalando) > 0 && count($ids_colgadas) === count($ids_instalando);

        if (count($ids_instalando) > 0 && $marcar_colgadas && $todas_colgadas && $completada === null) {
            $estado_previo = 'colgadas';
            $detalle       = 'Hay ' . count($ids_colgadas) . ' instalación(es) en instalando SIN ACTIVIDAD hace más de ' . self::MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION
                . ' minutos (id ' . implode(', ', $ids_colgadas) . '): están colgadas. Con marcar_colgadas=true se pasan a fallida (con el motivo) y se crea un par nuevo.';
        } elseif (count($ids_instalando) > 0) {
            $estado_previo = 'instalando';
            $detalle       = 'Hay una instalación en curso (id ' . implode(', ', $ids_instalando) . '): no se pisa un pipeline vivo. '
                . ($todas_colgadas
                    ? 'Todas llevan más de ' . self::MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION . ' minutos sin actividad: están colgadas. Con marcar_colgadas=true se pasan a fallida y se puede volver a instalar.'
                    : (count($ids_colgadas) > 0
                        ? 'Alguna (id ' . implode(', ', $ids_colgadas) . ') lleva más de ' . self::MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION . ' minutos sin actividad, pero no todas: marcar_colgadas=true no se aplica con una viva. Esperá a que termine o falle.'
                        : 'Esperá a que termine o falle.'))
                . ($completada !== null
                    ? ' Y la instalación real ya está completada (id ' . (int) $completada->id . '): el sistema ya está instalado, y marcar_colgadas no abre la puerta a reinstalarlo.'
                    : '');
        } elseif ($completada !== null) {
            $estado_previo     = 'completada';
            $esqueleto_fallido = $previas->where('kind', ClientInstallation::KIND_ESQUELETO)->where('status', 'fallida')->first();
            $detalle           = 'El sistema ya está instalado (instalación ' . (int) $completada->id . ' completada): reinstalar le pisaría el .env a un negocio que ya anda.'
                . ($esqueleto_fallido !== null
                    ? ' El esqueleto del subdominio hermano (instalación ' . (int) $esqueleto_fallido->id . ') quedó fallido: el cliente opera sin él y por acá no se '
                        . 'puede reintentar solo ese; si hace falta, se hace desde el panel (Instalaciones).'
                    : '');
        } elseif ($pendientes->count() > 0) {
            $estado_previo = 'pendientes';
            $detalle       = 'Hay ' . $pendientes->count() . ' instalación(es) pendiente(s) sin arrancar (id ' . $pendientes->pluck('id')->implode(', ')
                . '): se reutilizan, con la última versión publicada, y se crea la hermana que falte.';
        } else {
            $estado_previo = 'nueva';
            $detalle       = $previas->count() === 0
                ? 'No hay instalaciones previas: se crea el par.'
                : 'Las instalaciones previas fallaron: se crea un par nuevo (las fallidas quedan como historial).';
        }

        $chequeos[] = $this->chequeo('instalaciones_previas', in_array($estado_previo, ['pendientes', 'nueva', 'colgadas'], true), $detalle);

        /* 8. 🔴 Que el cliente no tenga ya un sistema vivo. `instalaciones_previas` solo ve las instalaciones que
           hizo ESTE camino; un cliente instalado a mano, por /instalar-cliente o por el panel no tiene ninguna, y
           reinstalar sobre un negocio que opera le pisa el .env y la base. */
        $vivo = $this->sistema_vivo($client);

        $chequeos[] = $this->chequeo(
            'sin_sistema_vivo',
            ! $vivo['vivo'],
            $vivo['vivo']
                ? 'El cliente ya tiene un sistema en producción: ' . implode(' ', $vivo['motivos']) . ' Instalar le pisaría el .env y la base. Si de verdad es un cliente '
                    . 'nuevo y es un dato viejo, resolvelo desde el panel; este camino no instala sobre un negocio que opera.'
                : 'El cliente no tiene actualizaciones registradas (client_version_upgrades): no hay señal de un sistema ya instalado.'
        );

        /* 9. Las variables manuales del .env. */
        $reutilizable = $pendientes->where('kind', ClientInstallation::KIND_COMPLETA)->sortByDesc('id')->first();
        $existentes   = $reutilizable !== null && is_array($reutilizable->env_manual_values) ? $reutilizable->env_manual_values : [];
        $variables    = $this->variables_manuales_de_la_instalacion($existentes);

        $chequeos[] = $this->chequeo(
            'variables_manuales',
            count($variables['faltan']) === 0,
            count($variables['faltan']) === 0
                ? 'Las variables manuales del .env están resueltas (' . count($variables['exentas']) . ' las genera el aprovisionamiento, '
                    . count($variables['se_completan']) . ' se completan con el valor estándar del hosting compartido).'
                : 'Faltan valores para estas variables manuales del .env, que el aprovisionamiento no genera: ' . implode(', ', $variables['faltan'])
                    . '. Cargalas en el panel (Instalaciones) o en la plantilla de variables.'
        );

        return [
            'chequeos'            => $chequeos,
            'estado_previo'       => $estado_previo,
            'ids_instalando'      => $ids_instalando,
            'ids_colgadas'        => $ids_colgadas,
            'todas_colgadas'      => $todas_colgadas,
            'ultimas_actividades' => $ultimas_actividades,
            'apis'                => ['activa' => $activa, 'otra' => $otra],
            'version'        => $version,
            'variables'      => $variables,
            'existentes'     => $existentes,
            'pendientes'     => $pendientes->pluck('id')->map(function ($valor) {
                return (int) $valor;
            })->all(),
        ];
    }

    /**
     * ¿Tiene el cliente un sistema vivo (ya instalado, ya en producción)?
     *
     * 🔴 La señal es su historial de actualizaciones (`client_version_upgrades`): un sistema al que se le
     * actualizó la versión es un sistema que ya estuvo en producción, y CUALQUIER fila cuenta, en el estado que
     * sea —una pendiente o una fallida también son un cliente al que el admin ya le despliega versiones—. Es lo
     * que deja un cliente instalado por afuera de este camino (a mano, por `/instalar-cliente`, por el panel),
     * que `instalaciones_previas` no ve porque solo mira las instalaciones que hizo éste.
     *
     * ⚠️ El dato "este sistema ya está configurado" vive en `client_version_upgrades.sistema_configurado_at`,
     * NO en `clients`: la tabla `clients` no tiene esa columna. Se cuenta por las filas de actualizaciones, que
     * incluyen a las que ya llegaron a configurar el sistema.
     *
     * @param Client $client El cliente.
     *
     * @return array{vivo: bool, motivos: array<int, string>}
     */
    protected function sistema_vivo(Client $client)
    {
        $actualizaciones = ClientVersionUpgrade::where('client_id', $client->id)->orderByDesc('id')->get(['id', 'status', 'sistema_configurado_at']);
        $cantidad        = $actualizaciones->count();

        if ($cantidad === 0) {
            return ['vivo' => false, 'motivos' => []];
        }

        $ultima  = $actualizaciones->first();
        $motivos = [
            'Tiene ' . $cantidad . ' ' . ($cantidad === 1 ? 'actualización registrada' : 'actualizaciones registradas') . ' en client_version_upgrades (la última: id '
                . (int) $ultima->id . ', estado "' . (string) $ultima->status . '").',
        ];

        if ($actualizaciones->whereNotNull('sistema_configurado_at')->count() > 0) {
            $motivos[] = 'Alguna figura con el sistema configurado (sistema_configurado_at).';
        }

        return ['vivo' => true, 'motivos' => $motivos];
    }

    /**
     * El estado del user setup que dejó el camino de LEADS (`RunUserSetupService`) en el lead del que salió el
     * cliente: `pendiente`, `ejecutandose`, `exitoso`, `fallido` o `sin_confirmar`. Null si el cliente no salió de un
     * lead (se creó directo) o el lead no tiene estado.
     *
     * Lo mira el chequeo `lead_sin_user_setup` del user setup: ese camino llama al MISMO endpoint remoto
     * (`admin-sync/user-setup`, que hace `migrate:fresh`) y no escribe el candado de la implementación, así que sin
     * este dato el candado no se entera de que el sistema ya se configuró.
     *
     * @param Client $client El cliente.
     *
     * @return string|null
     */
    protected function estado_del_user_setup_del_lead(Client $client)
    {
        $lead = Lead::where('promoted_client_id', $client->id)->orderByDesc('id')->first();

        if ($lead === null || $lead->user_setup_status === null || trim((string) $lead->user_setup_status) === '') {
            return null;
        }

        return (string) $lead->user_setup_status;
    }

    /**
     * La señal HTTP de que ya hay un sistema andando: `GET <api del cliente>/api/version-activa`.
     *
     * 🔴 ES INFORMATIVA Y SOLO DEL DRY-RUN. No decide nada: una API que no responde es lo normal en un cliente
     * sin instalar (el subdominio ni existe todavía), y una que responde puede ser cualquier cosa. Lo que sí
     * dice, cuando contesta 200 con una `default_version`, es que hay un sistema andando ahí, y eso se avisa en
     * `avisos` del dry-run para que quien lo lee no instale encima de un negocio sin darse cuenta. Con 5
     * segundos de techo y sin dejar escapar ninguna excepción: un dry-run no se cuelga ni se rompe por la API
     * del cliente. La URL sale de `ClientEmpresaApiUrlResolver`, la misma con la que el admin le habla a la
     * API de un cliente en todo lo demás.
     *
     * @param Client $client El cliente.
     *
     * @return array<string, mixed> `consultado`, `url`, `status`, `default_version`, `responde_con_version` y,
     *                              si la llamada falló, `error`.
     */
    protected function senal_de_la_version_activa(Client $client)
    {
        $base = (new ClientEmpresaApiUrlResolver())->resolve_base_url($client);

        if ($base === '') {
            return [
                'consultado'           => false,
                'url'                  => null,
                'status'               => null,
                'default_version'      => null,
                'responde_con_version' => false,
                'nota'                 => 'El cliente no tiene una URL de API válida: no se consultó.',
            ];
        }

        $url = $base . self::RUTA_DE_LA_VERSION_ACTIVA;

        try {
            $respuesta = Http::timeout(self::TIMEOUT_DE_LA_SENAL_DE_VERSION)->acceptJson()->get($url);
            $json      = $respuesta->json();
            $version   = is_array($json) && isset($json['default_version']) && ! is_array($json['default_version']) && trim((string) $json['default_version']) !== ''
                ? (string) $json['default_version']
                : null;

            return [
                'consultado'           => true,
                'url'                  => $url,
                'status'               => $respuesta->status(),
                'default_version'      => $version,
                'responde_con_version' => $respuesta->status() === 200 && $version !== null,
            ];
        } catch (\Throwable $e) {
            return [
                'consultado'           => true,
                'url'                  => $url,
                'status'               => null,
                'default_version'      => null,
                'responde_con_version' => false,
                'error'                => 'no respondió: ' . (string) $this->recortar($e->getMessage()),
            ];
        }
    }

    /**
     * Qué variables manuales del `.env` hay que resolver y con qué.
     *
     * Son las plantillas con `is_manual_on_create`. Con aprovisionamiento quedan EXENTAS las tres que el
     * pipeline genera (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`): las escribe `provision_db`, y un valor
     * viejo no puede pisar la base recién creada. Las de conexión (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`)
     * se completan con el valor de la plantilla y, si la plantilla no lo trae, con el estándar del hosting
     * compartido —el mismo MySQL local de siempre—. Cualquier OTRA variable manual sin valor es un faltante
     * y frena: es exactamente lo que el botón "Iniciar" del panel exige antes de despachar.
     *
     * @param array<string, mixed> $existentes Los valores que la fila pendiente a reutilizar ya trae.
     *
     * @return array<string, array> `se_completan` (clave → valor), `exentas` (claves) y `faltan` (claves).
     */
    protected function variables_manuales_de_la_instalacion(array $existentes)
    {
        $se_completan = [];
        $exentas      = [];
        $faltan       = [];

        foreach (EnvTemplate::where('is_manual_on_create', true)->orderBy('id')->get() as $plantilla) {
            $clave = (string) $plantilla->key;

            if (in_array($clave, ClientInstallation::CLAVES_ENV_APROVISIONADAS, true)) {
                $exentas[] = $clave;
                continue;
            }

            if (trim((string) (isset($existentes[$clave]) ? $existentes[$clave] : '')) !== '') {
                continue;
            }

            if (array_key_exists($clave, self::VALORES_ESTANDAR_DE_CONEXION)) {
                $de_la_plantilla = trim((string) $plantilla->value);
                $se_completan[$clave] = $de_la_plantilla !== '' ? $de_la_plantilla : self::VALORES_ESTANDAR_DE_CONEXION[$clave];
                continue;
            }

            $faltan[] = $clave;
        }

        return ['se_completan' => $se_completan, 'exentas' => $exentas, 'faltan' => $faltan];
    }

    /**
     * ¿Algo impide instalar ahora? La respuesta de error que corresponde, o null si se puede.
     *
     * Dos clases, y no es lo mismo: una instalación EN CURSO es 409 (el estado cambió o se pidió dos veces:
     * no es un error de armado, es que ya hay un pipeline vivo y hay que esperarlo), y cualquier otro
     * chequeo en false es 422, con la lista completa de chequeos para que se vea de una cuál es.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $plan           El plan de `plan_de_la_instalacion()`.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function impedimento_de_la_instalacion(Implementation $implementation, array $plan)
    {
        if ($plan['estado_previo'] === 'instalando') {
            return response()->json([
                'error'             => 'Hay una instalación en curso para este cliente: no se pisa un pipeline vivo. No se instaló nada.',
                'implementation_id' => (int) $implementation->id,
                'ids_instalando'    => $plan['ids_instalando'],
                'colgadas'          => $plan['ids_colgadas'],
                'todas_colgadas'    => $plan['todas_colgadas'],
                'chequeos'          => $plan['chequeos'],
                'ayuda'             => 'GET claude/implementations/' . (int) $implementation->id . '?include=logs dice cómo va (instalaciones[].colgada).'
                    . ($plan['todas_colgadas']
                        ? ' Todas llevan más de ' . self::MINUTOS_PARA_DAR_POR_COLGADA_LA_INSTALACION . ' minutos sin actividad: con marcar_colgadas=true se pasan a fallida y se puede volver a instalar.'
                        : ''),
            ], 409);
        }

        $fallidos = [];
        foreach ($plan['chequeos'] as $chequeo) {
            if (! $chequeo['ok']) {
                $fallidos[] = $chequeo['chequeo'];
            }
        }

        if (count($fallidos) === 0) {
            return null;
        }

        return $this->error_422(
            'No se puede instalar: ' . implode(', ', $fallidos) . '. No se instaló nada.',
            [
                'implementation_id' => (int) $implementation->id,
                'chequeos'          => $plan['chequeos'],
                'ayuda'             => 'El dry_run (que es el default) muestra los nueve chequeos con su detalle.',
            ]
        );
    }

    /**
     * La respuesta del dry-run de la instalación.
     *
     * Además de los chequeos trae `senales`: lo que se ve consultando la API del cliente, que es INFORMATIVO y
     * no entra en `listo` (ver `senal_de_la_version_activa()`), y `avisos` con lo que esa consulta encontró.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $plan           El plan de `plan_de_la_instalacion()`.
     * @param Client               $client         Su cliente.
     *
     * @return array<string, mixed>
     */
    protected function respuesta_del_dry_run_de_la_instalacion(Implementation $implementation, array $plan, Client $client)
    {
        $listo = true;
        foreach ($plan['chequeos'] as $chequeo) {
            if (! $chequeo['ok']) {
                $listo = false;
            }
        }

        $activa  = $plan['apis']['activa'];
        $otra    = $plan['apis']['otra'];
        $version = $plan['version'];

        $se_crearia = null;
        if ($activa !== null && $otra !== null) {
            $se_crearia = [
                'modo'                   => $plan['estado_previo'] === 'pendientes'
                    ? 'reutiliza_las_pendientes_y_completa_el_par'
                    : ($plan['estado_previo'] === 'colgadas' ? 'marca_las_colgadas_como_fallidas_y_crea_el_par' : 'crea_el_par'),
                'colgadas_que_marcaria_como_fallidas' => $plan['estado_previo'] === 'colgadas' ? $plan['ids_colgadas'] : [],
                'instalaciones'          => [
                    ['kind' => ClientInstallation::KIND_COMPLETA, 'client_api_id' => (int) $activa->id, 'url' => $activa->url, 'spa_url' => $activa->spa_url],
                    ['kind' => ClientInstallation::KIND_ESQUELETO, 'client_api_id' => (int) $otra->id, 'url' => $otra->url, 'spa_url' => $otra->spa_url],
                ],
                'provision_hosting_type' => ClientInstallation::PROVISION_SHARED_HOSTING,
                'version'                => $version === null ? null : ['id' => (int) $version->id, 'version' => (string) $version->version],
                'instalaciones_pendientes_que_se_reutilizan' => $plan['estado_previo'] === 'pendientes' ? $plan['pendientes'] : [],
            ];
        }

        /* La señal de la API del cliente: solo en el dry-run, y solo informa (no entra en `listo`). */
        $senal  = $this->senal_de_la_version_activa($client);
        $avisos = [];

        if ($senal['responde_con_version']) {
            $avisos[] = 'OJO: ' . $senal['url'] . ' respondió 200 con default_version=' . $senal['default_version'] . ': ya hay un sistema andando en esa API. '
                . 'Instalar le pisaría el .env y la base. Si es un negocio que opera, no sigas.';
        }

        return [
            'dry_run'           => true,
            'implementation_id' => (int) $implementation->id,
            'listo'             => $listo,
            'chequeos'          => $plan['chequeos'],
            'se_crearia'        => $se_crearia,
            'variables_env'     => [
                'exentas_por_el_aprovisionamiento' => $plan['variables']['exentas'],
                'se_completan_con'                 => $plan['variables']['se_completan'],
                'faltan'                           => $plan['variables']['faltan'],
            ],
            'colgadas'          => $plan['ids_colgadas'],
            'todas_colgadas'    => $plan['todas_colgadas'],
            'senales'           => ['version_activa' => $senal],
            'avisos'            => $avisos,
            'nota'              => 'Simulacro: no se creó ni se encoló nada. 🔴 Instalar crea los cuatro subdominios, la base y el cron en Hostinger, '
                . 'sube el SPA y la API por SFTP y escribe el .env del cliente. Repetí con dry_run=false y confirm_client_name para instalar.',
        ];
    }

    /**
     * Crea o reutiliza el par de instalaciones, las deja en `instalando` y devuelve las dos filas (la
     * real primero). Se llama ADENTRO de la transacción, con la implementación bloqueada.
     *
     * 🔴 Por cada una de las dos filas se REUTILIZA la que esté `pendiente` del mismo tipo y la misma API
     * (la que dejó el panel al avanzar a la etapa 2, por ejemplo) y recién si no hay se crea. Una
     * reutilizada se actualiza a la última versión publicada —la que trajo el panel puede ser vieja— y al
     * aprovisionamiento del compartido. Las `fallida` no se tocan: quedan de historial y se crea un par
     * nuevo, que es lo que manda el flujo de reintento del panel (borrar la fallida y crear otra).
     *
     * Las dos comparten `group_uuid` y los mismos valores manuales del `.env` (los dos subdominios sirven la
     * MISMA base del cliente, así que `DB_*` son idénticas por definición): se completan solo las que
     * faltan, sin pisar una que la fila ya traiga.
     *
     * @param Client               $client El cliente.
     * @param array<string, mixed> $plan   El plan de `plan_de_la_instalacion()`, ya sin impedimentos.
     *
     * @return array<int, ClientInstallation> La real y el esqueleto.
     */
    protected function preparar_el_par_de_instalaciones(Client $client, array $plan)
    {
        /* 🔴 Las colgadas (solo con `marcar_colgadas` y solo si TODAS las instalando lo están: ver
           `plan_de_la_instalacion()`) pasan a `fallida` ANTES de armar el par, con el motivo y la fecha de su última
           actividad. Quedan de historial como cualquier fallida, y el par nuevo no las reutiliza. */
        if ($plan['estado_previo'] === 'colgadas') {
            foreach (ClientInstallation::whereIn('id', $plan['ids_colgadas'])->where('status', 'instalando')->lockForUpdate()->get() as $colgada) {
                $desde = isset($plan['ultimas_actividades'][(int) $colgada->id]) ? $plan['ultimas_actividades'][(int) $colgada->id] : null;

                $colgada->status         = 'fallida';
                $colgada->finished_at    = now();
                $colgada->failure_reason = 'colgada: sin actividad desde ' . ($desde === null ? 'una fecha desconocida' : $desde->format('d/m/Y H:i'));
                $colgada->save();
            }
        }

        $filas       = [];
        $grupos      = [];
        $reutilizadas = 0;

        foreach ([[ClientInstallation::KIND_COMPLETA, $plan['apis']['activa']], [ClientInstallation::KIND_ESQUELETO, $plan['apis']['otra']]] as $destino) {
            list($kind, $api) = $destino;

            $fila = ClientInstallation::where('client_id', $client->id)
                ->where('kind', $kind)
                ->where('client_api_id', $api->id)
                ->where('status', 'pendiente')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($fila !== null) {
                $reutilizadas++;
                $grupos[] = $fila->group_uuid;
            } else {
                $fila = new ClientInstallation();
            }

            $fila->client_id     = $client->id;
            $fila->client_api_id = $api->id;
            $fila->kind          = $kind;

            $filas[] = $fila;
        }

        /* Un grupo ya armado se respeta solo si las dos filas pendientes son del mismo; en cualquier otro
           caso se arma uno nuevo para las dos. */
        $mismo_grupo = $reutilizadas === 2 && $grupos[0] !== null && $grupos[0] === $grupos[1];
        $group_uuid  = $mismo_grupo ? $grupos[0] : (string) Str::uuid();

        $valores = array_merge($plan['variables']['se_completan'], $plan['existentes']);

        foreach ($filas as $fila) {
            $propios = is_array($fila->env_manual_values) ? $fila->env_manual_values : [];

            /* Lo que la fila ya traía gana sobre lo que se completa, salvo que esté vacío. */
            $env = $valores;
            foreach ($propios as $clave => $valor) {
                if (trim((string) $valor) !== '') {
                    $env[$clave] = $valor;
                }
            }

            $fila->version_id             = $plan['version']->id;
            $fila->group_uuid             = $group_uuid;
            $fila->provision_hosting_type = ClientInstallation::PROVISION_SHARED_HOSTING;
            $fila->env_manual_values      = $env;
            $fila->status                 = 'instalando';
            $fila->failure_reason         = null;
            $fila->started_at             = null;
            $fila->finished_at            = null;
            $fila->save();
        }

        return $filas;
    }

    /**
     * Una instalación en la forma corta que devuelve `install`.
     *
     * @param ClientInstallation $fila La instalación.
     *
     * @return array<string, mixed>
     */
    protected function instalacion_en_corto(ClientInstallation $fila)
    {
        return [
            'id'                     => (int) $fila->id,
            'uuid'                   => (string) $fila->uuid,
            'kind'                   => (string) $fila->kind,
            'status'                 => (string) $fila->status,
            'client_api_id'          => (int) $fila->client_api_id,
            'version_id'             => $fila->version_id === null ? null : (int) $fila->version_id,
            'provision_hosting_type' => $fila->provision_hosting_type,
        ];
    }

    /**
     * La versión de una instalación en la forma corta, leída de la fila (la que se creó o reutilizó).
     *
     * Se lee de la fila y no del plan de antes del lock: entre uno y otro pudo publicarse otra versión, y
     * la respuesta tiene que decir lo que de verdad se instala.
     *
     * @param int|null $version_id `client_installations.version_id`.
     *
     * @return array<string, mixed>|null
     */
    protected function version_en_corto($version_id)
    {
        $version = $version_id === null ? null : Version::find((int) $version_id);

        return $version === null ? null : ['id' => (int) $version->id, 'version' => (string) $version->version];
    }

    /**
     * ¿El cliente ya cargó el formulario de la implementación?
     *
     * 🔴 ES EL GATE DEL PANEL (`ImplementationActionService::user_setup_gate()`), CON UNA PRECISIÓN. El
     * panel da el formulario por enviado si `form_submitted_at` está lleno O SI LA ETAPA 1 ESTÁ
     * `completed`. Esa segunda mitad existe porque la etapa 1 se completa sola al enviarse el formulario,
     * pero también se completa cuando alguien aprieta "Avanzar etapa" —y ahora también `advance` de
     * Claude— SIN que el cliente haya cargado nada. En ese caso el user setup correría con un
     * `setup_data` vacío: `migrate:fresh` sobre el sistema del cliente y todos los valores por defecto,
     * que es justo lo que el formulario venía a evitar.
     *
     * Por eso acá la etapa 1 completada cuenta SOLO si además hay datos del formulario ya mapeados en
     * `clients.setup_data` (que es lo que consume el user setup): el caso legítimo es el de Lucas
     * cargando las respuestas desde el panel ("Editar" datos recolectados), que mapea `setup_data` pero no
     * llena `form_submitted_at`.
     *
     * @param Implementation $implementation La implementación.
     * @param Client         $client         Su cliente.
     *
     * @return bool
     */
    protected function formulario_enviado(Implementation $implementation, Client $client)
    {
        if ($implementation->form_submitted_at !== null) {
            return true;
        }

        $primera = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 1)->first();

        return $primera !== null
            && $primera->status === 'completed'
            && is_array($client->setup_data)
            && count($client->setup_data) > 0;
    }

    /**
     * Un chequeo de los que devuelven `install` y `user-setup`.
     *
     * @param string $nombre  Qué se chequeó.
     * @param bool   $ok      Si está bien.
     * @param string $detalle Lo que se vio, en castellano.
     *
     * @return array<string, mixed>
     */
    protected function chequeo($nombre, $ok, $detalle)
    {
        return ['chequeo' => $nombre, 'ok' => (bool) $ok, 'detalle' => (string) $detalle];
    }

    /* ==============================================================================================
     | 6) POST claude/implementations/{id}/user-setup — CONFIGURAR el sistema con el formulario
     |============================================================================================= */

    /**
     * Aplica en el sistema del cliente la configuración que cargó en el formulario (listas de precios,
     * sucursales, IVA, el usuario dueño con su documento, la tienda...): el "user setup".
     *
     * 🔴🔴 ESTO LE VACÍA LA BASE AL CLIENTE. Del otro lado (`admin-sync/user-setup` de empresa-api) el setup
     * arranca con `migrate:fresh --force` y después siembra todo: "solo debe correrse sobre instancias
     * recién instaladas". Por eso este camino NO TIENE "FORZAR", a diferencia del botón del panel, y NO
     * hay que agregarle uno: una vez aplicado (`user_setup_executed_at` lleno), re-aplicarlo borraría todo
     * lo que se cargó después —artículos, clientes, ventas—. Si de verdad hace falta, se hace desde el
     * panel, con una persona mirando. Y el panel tiene además un hint que empuja a re-aplicar cada vez que
     * se edita una respuesta del formulario: no es una razón para hacerlo.
     *
     * Hace lo que el botón "Configuración del sistema (UserSetup)" del panel
     * (`ImplementationActionService::execute()`), con dos diferencias: corre en la cola `database` y no
     * dentro del request, y la llamada HTTP va con 1200 segundos de techo y no los 15 de
     * `services.client_api.timeout` (con 15 el panel corta la espera mientras el setup sigue corriendo
     * del otro lado). Lo hace `EjecutarUserSetupDeImplementacionJob`.
     *
     * Chequeos (todos en el dry-run, como `chequeos`; con uno en false el real es 422, salvo el que se
     * dice):
     *   1. El formulario se envió.
     *   2. 🔴 La implementación está EXACTAMENTE en la etapa 2 (como `install`): antes no hay sistema
     *      instalado, y después el negocio puede estar operando —`migrate:fresh` le borraría lo que cargó—.
     *   3. La última instalación `completa` de la API activa está `completada` (la API responde).
     *   4. La API activa tiene URL.
     *   4b. 🔴 La instalación completada es POSTERIOR al arranque de la implementación (`instalacion_de_esta_implementacion`):
     *      una anterior es un sistema que ya existía y puede estar operando.
     *   4c. 🔴 El cliente no tiene un sistema vivo (`sin_sistema_vivo`: actualizaciones registradas en
     *      `client_version_upgrades`), el mismo chequeo que el alta y `install`.
     *   4d. 🔴 El user setup no se aplicó ya por el camino de LEADS (`lead_sin_user_setup`: `leads.user_setup_status`
     *      del lead promovido en ejecutandose, exitoso o sin_confirmar). Ese camino llama al mismo endpoint remoto.
     *   5. `user_setup_executed_at` está vacío: si no, 422 sin vuelta, con la fecha.
     *   6. No hay otro user setup `en_curso` (409). Uno que dice `en_curso` hace más de 45 minutos se da
     *      por colgado. Si el job NUNCA llegó a llamar al cliente (sin `llamada_iniciada_at`: la cola estaba parada o
     *      atrasada) se puede volver a intentar con la misma llamada; si SÍ llegó a llamar (con la marca) pudo haber
     *      corrido del otro lado y pide `reintentar` o `conciliar`, igual que un error. El job viejo, si arranca, se
     *      descarta solo (cada intento lleva su token), y el cliente igual frena un setup doble con su propio 409.
     *
     * 🔴 TRAS UN ERROR NO SE REPITE LA LLAMADA SIN DECIR QUÉ SE HACE. Un error del job casi nunca prueba que el
     * setup no corrió (un 502, un timeout, un worker muerto: el origen sigue), y repetir la llamada era
     * despachar OTRO `migrate:fresh`. Si el último estado es `error`, el real responde 422 explicando eso y
     * solo sigue con UNO de dos parámetros, excluyentes y con `confirm_client_name`:
     *   - `reintentar: true` vuelve a despachar el job (con un token nuevo);
     *   - `conciliar: true` NO llama al cliente: llena el candado, deja el estado en `ok` con la nota
     *     "conciliado: el dueño ya existía en el sistema del cliente" y registra la acción `user_setup`
     *     (canal `claude`). Es para cuando se verificó que el dueño YA existe en el sistema del cliente.
     * Sin un error previo los dos son 422. El dry-run dice cuál corresponde (`corresponde`, `opciones`).
     *
     * El dry-run devuelve el payload REAL que se va a mandar, con las claves de servicios pagos tapadas (el
     * mismo preview del panel). Con `dry_run=false`: `confirm_client_name`, el registro `en_curso` se
     * escribe bajo lock ANTES de despachar (así dos llamadas no despachan dos jobs), y responde 202. El
     * resultado se lee en `user_setup` de `GET claude/implementations/{id}`.
     *
     * @param Request    $request Body: dry_run?, confirm_client_name, include?, reintentar?, conciliar?.
     * @param int|string $id      Id de la implementación (segmento de la URL).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function user_setup_json(Request $request, $id)
    {
        /* --- Freno 1: lista blanca y tipos. --- */
        $rechazo = $this->rechazar_parametros_de_mas($request, self::PARAMETROS_DEL_USER_SETUP, 'POST claude/implementations/{id}/user-setup');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $invalido = $this->validar_o_422($request, [
            'dry_run'             => 'nullable|boolean',
            'confirm_client_name' => 'required_if:dry_run,false|nullable|string|max:190',
            'reintentar'          => 'nullable|boolean',
            'conciliar'           => 'nullable|boolean',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $includes = $this->resolver_includes($request, self::INCLUDES_DEL_USER_SETUP);
        if (! is_array($includes)) {
            return $includes;
        }

        $con_contacto = in_array('contacto', $includes, true);

        $reintentar = $this->booleano_o_null($request, 'reintentar') === true;
        $conciliar  = $this->booleano_o_null($request, 'conciliar') === true;

        /* Excluyentes: reintentar vuelve a correr el setup y conciliar da por hecho que ya corrió. Pedir las dos
           cosas a la vez no tiene una lectura segura, y no se simula en el dry-run lo que el real rechaza. */
        if ($reintentar && $conciliar) {
            return $this->error_422(
                '`reintentar` y `conciliar` son excluyentes: reintentar vuelve a correr el setup del otro lado y conciliar da por hecho que ya '
                    . 'corrió. Mandá uno solo. No se aplicó nada.',
                ['parametros_aceptados' => self::PARAMETROS_DEL_USER_SETUP]
            );
        }

        $implementation = Implementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación ' . (int) $id);
        }

        $client = Client::find((int) $implementation->client_id);
        if ($client === null) {
            return $this->error_404('la implementación ' . (int) $id . ' apunta a un cliente que no existe');
        }

        $dry_run = $this->resolver_dry_run($request);
        $plan    = $this->plan_del_user_setup($implementation, $client);

        if ($dry_run) {
            return response()->json($this->respuesta_del_dry_run_del_user_setup($implementation, $plan, $con_contacto), 200);
        }

        /* --- Freno 2: confirmación por nombre. --- */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se aplicó nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        /* --- Frenos 3 a 7, con lo que se ve ahora. Se repiten adentro del lock. --- */
        $impedimento = $this->impedimento_del_user_setup($implementation, $plan, $reintentar, $conciliar);
        if ($impedimento !== null) {
            return $impedimento;
        }

        $resultado = DB::transaction(function () use ($implementation, $client, $reintentar, $conciliar) {
            $bloqueada = Implementation::query()->whereKey($implementation->id)->lockForUpdate()->first();

            $plan = $this->plan_del_user_setup($bloqueada, $client);

            $impedimento = $this->impedimento_del_user_setup($bloqueada, $plan, $reintentar, $conciliar);
            if ($impedimento !== null) {
                return $impedimento;
            }

            $etapa = ImplementationStage::where('implementation_id', $bloqueada->id)->where('stage_number', 2)->lockForUpdate()->first();
            if ($etapa === null) {
                return 'sin_etapa';
            }

            /* 🔴 Conciliar NO llama al cliente ni despacha nada: solo deja el registro y el candado como si el
               setup hubiera terminado bien. Va adentro de la misma transacción y con los mismos chequeos. */
            if ($conciliar) {
                return $this->conciliar_el_user_setup($bloqueada, $etapa);
            }

            $datos    = is_array($etapa->data) ? $etapa->data : [];
            $anterior = isset($datos['user_setup']['iniciado_at']) ? (string) $datos['user_setup']['iniciado_at'] : null;

            /* 🔴 El `iniciado_at` es el TOKEN del job (ver EjecutarUserSetupDeImplementacionJob): lo que lo
               distingue del intento anterior. Con microsegundos no se repite, salvo con el reloj congelado
               de un test; por eso, si coincide con el del intento anterior, se corre un microsegundo: dos
               intentos con el mismo token no se podrían distinguir y el viejo pisaría al nuevo. */
            $iniciado_at = now()->toISOString();
            if ($iniciado_at === $anterior) {
                $iniciado_at = now()->addMicroseconds(1)->toISOString();
            }

            $datos['user_setup'] = ['estado' => 'en_curso', 'iniciado_at' => $iniciado_at, 'terminado_at' => null, 'error' => null];
            $etapa->data         = $datos;
            $etapa->save();

            return ['iniciado_at' => $iniciado_at];
        });

        if ($resultado instanceof \Illuminate\Http\JsonResponse) {
            return $resultado;
        }

        if ($resultado === 'sin_etapa') {
            return $this->error_422(
                'La implementación ' . (int) $implementation->id . ' no tiene la etapa 2 cargada, que es donde queda el registro del user setup. No se aplicó nada.',
                ['implementation_id' => (int) $implementation->id]
            );
        }

        if (isset($resultado['conciliado'])) {
            return response()->json([
                'dry_run'           => false,
                'implementation_id' => (int) $implementation->id,
                'conciliado'        => true,
                'user_setup'        => ['estado' => 'ok', 'ejecutado_at' => $resultado['ejecutado_at'], 'nota' => self::NOTA_DE_CONCILIACION],
                'nota'              => 'NO se llamó al sistema del cliente: el user setup quedó como aplicado (user_setup_executed_at) con la nota "'
                    . self::NOTA_DE_CONCILIACION . '" y la acción user_setup registrada. Desde acá no se vuelve a aplicar. Siguiente paso: avanzar la etapa.',
            ], 200);
        }

        /* 🔴 onConnection explícito y DESPUÉS del commit: el job tiene que encontrar el `en_curso` ya escrito.

           🔴 Y con red: si encolar falla, el registro ya dice `en_curso` y nadie lo va a terminar (esperaría
           45 minutos a darse por colgado). Se deja como `error`, que no bloquea reintentar. */
        try {
            EjecutarUserSetupDeImplementacionJob::dispatch((int) $implementation->id, $resultado['iniciado_at'])->onConnection(self::CONEXION_DE_COLA);
        } catch (\Throwable $e) {
            $this->dejar_el_user_setup_en_error($implementation, 'No se pudo encolar el job: ' . $e->getMessage());

            Log::channel('daily')->error('ClaudeImplementationOpsController: no se pudo encolar el user setup.', [
                'implementation_id' => (int) $implementation->id,
                'error'             => $e->getMessage(),
            ]);

            return response()->json([
                'error'             => 'No se pudo encolar la configuración: ' . $e->getMessage() . '. No se aplicó nada: el registro quedó en error '
                    . '(puede_haber_corrido=false: la llamada ni salió) y se puede reintentar con `reintentar: true`.',
                'implementation_id' => (int) $implementation->id,
                'reintentable'      => true,
            ], 500);
        }

        return response()->json([
            'dry_run'                  => false,
            'implementation_id'        => (int) $implementation->id,
            'user_setup'               => ['estado' => 'en_curso', 'iniciado_at' => $resultado['iniciado_at']],
            'conexion_de_cola'         => self::CONEXION_DE_COLA,
            'latencia_maxima_segundos' => self::LATENCIA_MAXIMA_SEGUNDOS,
            'nota'                     => 'Se encoló la configuración del sistema. 🔴 Tarda varios minutos (migrate:fresh + seeders, ~10): poleá cada 30 o '
                . '60 segundos con GET claude/implementations/' . (int) $implementation->id . ' (user_setup.estado: en_curso → ok | error), '
                . 'no cada 2 (rate limit por IP). Si termina en error por una conexión cortada, NO reintentes sin mirar el sistema del cliente: '
                . 'pudo haber seguido corriendo, y un reintento le vuelve a vaciar la base.',
        ], 202);
    }

    /**
     * Deja el registro del user setup en `error` con ese motivo (la etapa 2, `data.user_setup`).
     *
     * Lo usa `user_setup_json()` cuando el job no se pudo encolar: el registro ya decía `en_curso` y no hay
     * nadie que lo termine. No toca el candado (`user_setup_executed_at`): nada se aplicó.
     *
     * @param Implementation $implementation La implementación.
     * @param string         $motivo         Qué pasó.
     *
     * @return void
     */
    protected function dejar_el_user_setup_en_error(Implementation $implementation, $motivo)
    {
        $etapa = ImplementationStage::where('implementation_id', $implementation->id)->where('stage_number', 2)->first();

        if ($etapa === null) {
            return;
        }

        $datos    = is_array($etapa->data) ? $etapa->data : [];
        $registro = isset($datos['user_setup']) && is_array($datos['user_setup']) ? $datos['user_setup'] : [];

        $registro['estado']              = 'error';
        $registro['terminado_at']        = now()->toISOString();
        $registro['error']               = $motivo;
        $registro['puede_haber_corrido'] = false;

        $datos['user_setup'] = $registro;
        $etapa->data         = $datos;
        $etapa->save();
    }

    /**
     * Concilia el user setup: lo deja como aplicado SIN llamar al cliente.
     *
     * Es para cuando el último intento terminó en error pero el dueño YA existe en el sistema del cliente
     * (el setup corrió del otro lado aunque acá se cortó la espera). Hace lo mismo que el job cuando termina
     * bien —llena el candado `user_setup_executed_at`, deja el registro en `ok` y registra la acción
     * `user_setup` con `canal: claude`, que es la huella que lee el checklist del panel—, más la nota que dice
     * que fue una conciliación y no una corrida.
     *
     * Se llama ADENTRO de la transacción, con la implementación y la etapa 2 bloqueadas.
     *
     * @param Implementation  $implementation La implementación, bloqueada.
     * @param ImplementationStage $etapa      La etapa 2, bloqueada.
     *
     * @return array<string, mixed> `conciliado` (true) y `ejecutado_at` (ISO 8601).
     */
    protected function conciliar_el_user_setup(Implementation $implementation, ImplementationStage $etapa)
    {
        $datos    = is_array($etapa->data) ? $etapa->data : [];
        $registro = isset($datos['user_setup']) && is_array($datos['user_setup']) ? $datos['user_setup'] : [];
        $ahora    = now();

        $registro['estado']       = 'ok';
        $registro['terminado_at'] = $ahora->toISOString();
        $registro['error']        = null;
        $registro['nota']         = self::NOTA_DE_CONCILIACION;
        $registro['conciliado']   = true;
        unset($registro['puede_haber_corrido']);

        $implementation->user_setup_executed_at = $ahora;
        $implementation->save();

        $acciones         = isset($datos['actions']) && is_array($datos['actions']) ? $datos['actions'] : [];
        $acciones[]       = ['action' => 'user_setup', 'stage' => 2, 'at' => $ahora->toISOString(), 'canal' => 'claude', 'origen' => 'claude'];
        $datos['actions'] = $acciones;

        $datos['user_setup'] = $registro;
        $etapa->data         = $datos;
        $etapa->save();

        Log::channel('daily')->info('ClaudeImplementationOpsController: user setup conciliado (sin llamar al cliente).', [
            'implementation_id' => (int) $implementation->id,
        ]);

        return ['conciliado' => true, 'ejecutado_at' => $this->instante($ahora)];
    }

    /**
     * Los nueve chequeos del user setup.
     *
     * Lee, no escribe. Los tres primeros son los del `user_setup_gate()` del panel, con dos precisiones: el
     * segundo exige la etapa 2 EXACTA (el panel acepta cualquiera desde la 2, y re-aplicar en la 3 o después le
     * borra al cliente lo que ya cargó) y el tercero mira la última instalación REAL (`completa`) de la API
     * activa y no "la última del cliente", porque con el par de filas (real + esqueleto) la última por id es el
     * esqueleto, que termina después y no tiene nada que ver con el sistema al que se le va a pegar.
     *
     * @param Implementation $implementation La implementación.
     * @param Client         $client         Su cliente.
     *
     * @return array<string, mixed> `chequeos`, `aplicado` (bool: el candado está lleno), `en_curso` (bool:
     *                              hay uno corriendo y no está colgado), `endpoint` (string|null).
     */
    protected function plan_del_user_setup(Implementation $implementation, Client $client)
    {
        $chequeos = [];

        /* 1. El formulario. */
        $formulario = $this->formulario_enviado($implementation, $client);

        $chequeos[] = $this->chequeo(
            'formulario_enviado',
            $formulario,
            $formulario ? 'El cliente envió el formulario.' : 'Todavía no se completó el formulario (etapa 1): el payload no tendría datos reales.'
        );

        /* 2. La etapa: EXACTAMENTE la 2, como `install`. Antes no hay sistema instalado; después el negocio puede
           estar operando y `migrate:fresh` le borraría lo que cargó. */
        $etapa_ok = (int) $implementation->current_stage === 2;

        $chequeos[] = $this->chequeo(
            'etapa_2',
            $etapa_ok,
            $etapa_ok
                ? 'La implementación está en la etapa 2.'
                : 'La implementación está en la etapa ' . (int) $implementation->current_stage . ': el user setup solo se aplica en la etapa 2 (antes no hay sistema '
                    . 'instalado; después el negocio puede estar operando y migrate:fresh le borraría lo que cargó).'
        );

        /* 3 y 4. La instalación real de la API activa y la URL a la que se le pega. */
        $activa = $client->active_client_api_id === null
            ? null
            : ClientApi::where('id', (int) $client->active_client_api_id)->where('client_id', $client->id)->first();

        $instalacion = $activa === null
            ? null
            : ClientInstallation::where('client_id', $client->id)
                ->where('kind', ClientInstallation::KIND_COMPLETA)
                ->where('client_api_id', $activa->id)
                ->orderByDesc('id')
                ->first();

        $instalada = $instalacion !== null && $instalacion->status === 'completada';

        $chequeos[] = $this->chequeo(
            'instalacion_completada',
            $instalada,
            $instalacion === null
                ? 'No hay ninguna instalación completa sobre la API activa: instalá primero (POST claude/implementations/{id}/install).'
                : ($instalada
                    ? 'La instalación ' . (int) $instalacion->id . ' de la API activa está completada.'
                    : 'La última instalación completa de la API activa (' . (int) $instalacion->id . ') está en "' . $instalacion->status . '", no en completada.')
        );

        /* 🔴 La misma URL que va a usar `trigger_user_setup()`: normalizada (con `/public` en hosting compartido, sin él en
           VPS). Con la URL cruda de un cliente nuevo de shared el POST daba 404; el dry-run tiene que mostrar el destino REAL. */
        $url = $activa === null ? '' : (new ClientEmpresaApiUrlResolver())->normalize_api_base_url($activa->url, $activa->hosting_type);

        $chequeos[] = $this->chequeo(
            'client_api_activa',
            $url !== '',
            $url !== '' ? 'La API activa del cliente es ' . $url . '.' : 'El cliente no tiene una API activa con URL (clients.active_client_api_id).'
        );

        /* 4b. 🔴 La instalación es de ESTA implementación. Una instalación completada ANTERIOR al arranque de la
           implementación es un sistema que ya existía (instalado por afuera de este camino, por /instalar-cliente, a mano o
           por otra implementación): puede estar operando, y migrate:fresh le borraría todo. Sin instalación completada
           todavía no aplica (el chequeo de arriba ya está en false). */
        $posterior = ! $instalada
            || $implementation->started_at === null
            || $instalacion->created_at === null
            || $instalacion->created_at->gte($implementation->started_at);

        $chequeos[] = $this->chequeo(
            'instalacion_de_esta_implementacion',
            $posterior,
            ! $instalada
                ? 'No aplica todavía: no hay una instalación completada.'
                : ($posterior
                    ? 'La instalación ' . (int) $instalacion->id . ' es posterior al arranque de la implementación: la hizo este camino.'
                    : 'La instalación completada (' . (int) $instalacion->id . ', del ' . $instalacion->created_at->format('d/m/Y H:i') . ') es ANTERIOR al arranque de la '
                        . 'implementación (' . $implementation->started_at->format('d/m/Y H:i') . '): es un sistema que ya existía y puede estar operando. 🔴 migrate:fresh '
                        . 'le borraría todo. Si de verdad hace falta, se hace desde el panel, con una persona mirando.')
        );

        /* 4c. 🔴 Que no tenga ya un sistema vivo (el mismo chequeo que el alta y `install`: un cliente al que el admin ya le
           desplegó versiones). El user setup es la acción que VACÍA la base, y es la que más lo necesita. */
        $vivo = $this->sistema_vivo($client);

        $chequeos[] = $this->chequeo(
            'sin_sistema_vivo',
            ! $vivo['vivo'],
            $vivo['vivo']
                ? 'El cliente ya tiene un sistema vivo: ' . implode(' ', $vivo['motivos']) . ' 🔴 migrate:fresh le borraría lo que tiene. Si de verdad hace '
                    . 'falta, se hace desde el panel, con una persona mirando.'
                : 'Sin señales de un sistema ya instalado (el cliente no tiene actualizaciones registradas).'
        );

        /* 4d. 🔴 Que el user setup no se haya aplicado ya por el camino de LEADS (`RunUserSetupService`: el que usa
           /instalar-cliente para crear al dueño). Ese camino no escribe el candado de la implementación, así que sin
           esto el candado de abajo no se entera. `sin_confirmar` es "la llamada salió y no se sabe cómo terminó". */
        $estado_del_lead = $this->estado_del_user_setup_del_lead($client);
        $lead_aplicado   = in_array($estado_del_lead, ['ejecutandose', 'exitoso', \App\Services\RunDemoSetupService::ESTADO_SIN_CONFIRMAR], true);

        $chequeos[] = $this->chequeo(
            'lead_sin_user_setup',
            ! $lead_aplicado,
            $lead_aplicado
                ? 'El lead del que salió este cliente tiene el user setup en estado "' . $estado_del_lead . '": el sistema ya se configuró (o se está configurando) '
                    . 'por el camino de leads. 🔴 Aplicarlo de nuevo VACÍA la base del cliente (migrate:fresh). Verificá el sistema del cliente (`motor <cliente> '
                    . 'metricas`: ¿existe el dueño?) y seguí con la verificación; si de verdad hace falta re-aplicar, se hace desde el panel, con una persona mirando.'
                : 'El user setup no se aplicó por el camino de leads.'
        );

        /* 5. El candado: ya aplicado = nunca más por acá. */
        $aplicado = $implementation->user_setup_executed_at !== null;

        $chequeos[] = $this->chequeo(
            'sin_aplicar_antes',
            ! $aplicado,
            $aplicado
                ? 'El user setup ya se aplicó el ' . $implementation->user_setup_executed_at->format('d/m/Y H:i') . '. 🔴 Re-aplicarlo VACÍA la base del cliente '
                    . '(migrate:fresh): este camino no tiene forzar. Si de verdad hace falta, se hace desde el panel, con una persona mirando.'
                : 'Todavía no se aplicó.'
        );

        /* 6. Otro en curso. Uno colgado (más de 45 minutos) no cuenta: ver MINUTOS_PARA_DAR_POR_COLGADO. */
        $registro = $this->registro_del_user_setup($implementation);
        $en_curso = ! $aplicado && (isset($registro['estado']) ? $registro['estado'] : '') === 'en_curso' && ! $this->esta_colgado($registro);

        $chequeos[] = $this->chequeo(
            'sin_setup_en_curso',
            ! $en_curso,
            $en_curso
                ? 'Ya hay un user setup en curso (arrancó ' . (isset($registro['iniciado_at']) ? (string) $registro['iniciado_at'] : 'sin fecha') . '): esperá a que termine.'
                : (! $aplicado && (isset($registro['estado']) ? $registro['estado'] : '') === 'en_curso'
                    ? 'No hubo señal en ' . self::MINUTOS_PARA_DAR_POR_COLGADO . ' minutos (el registro sigue en en_curso sin resultado): se da por colgado. '
                        . ($this->llamo_antes_de_colgarse($registro)
                            ? 'El job SÍ llegó a llamar al cliente (llamada_iniciada_at): pudo haber corrido, así que hay que elegir `conciliar` o `reintentar`.'
                            : 'El job nunca llegó a llamar al cliente: se puede volver a intentar con la llamada normal; el job viejo, si arranca, se descarta solo.')
                    : 'No hay ninguno en curso.')
        );

        return [
            'chequeos' => $chequeos,
            'aplicado' => $aplicado,
            'en_curso' => $en_curso,
            'endpoint' => $url === '' ? null : rtrim($url, '/') . '/api/admin-sync/user-setup',
        ];
    }

    /**
     * ¿Algo impide aplicar el user setup ahora? La respuesta de error que corresponde, o null si se puede.
     *
     * Cuatro clases, en este orden:
     *   - ya aplicado: 422 con el motivo largo y SIN vuelta (no hay forzar);
     *   - otro en curso: 409 (no es un error de armado: hay que esperarlo);
     *   - cualquier otro chequeo en false (la etapa incluida): 422 con la lista de chequeos;
     *   - el intento anterior (`impedimento_por_el_intento_anterior()`): tras un error hace falta `reintentar` o
     *     `conciliar`, y sin un error esos dos parámetros no se aceptan.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $plan           El plan de `plan_del_user_setup()`.
     * @param bool                 $reintentar     `reintentar` del pedido.
     * @param bool                 $conciliar      `conciliar` del pedido.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function impedimento_del_user_setup(Implementation $implementation, array $plan, $reintentar = false, $conciliar = false)
    {
        if ($plan['aplicado']) {
            return $this->error_422(
                'El user setup ya se aplicó el ' . $implementation->user_setup_executed_at->format('d/m/Y H:i') . '. Re-aplicarlo VACÍA la base del cliente '
                    . '(migrate:fresh): este camino no tiene "forzar". Si de verdad hace falta, se hace desde el panel, con una persona mirando. '
                    . 'No se aplicó nada.',
                [
                    'implementation_id'      => (int) $implementation->id,
                    'user_setup_executed_at' => $this->instante($implementation->user_setup_executed_at),
                    'chequeos'               => $plan['chequeos'],
                ]
            );
        }

        if ($plan['en_curso']) {
            return response()->json([
                'error'             => 'Ya hay un user setup en curso para esta implementación: esperá a que termine. No se aplicó nada.',
                'implementation_id' => (int) $implementation->id,
                'user_setup'        => $this->estado_del_user_setup($implementation),
                'chequeos'          => $plan['chequeos'],
                'ayuda'             => 'GET claude/implementations/' . (int) $implementation->id . ' dice cómo va (user_setup.estado).',
            ], 409);
        }

        $fallidos = [];
        foreach ($plan['chequeos'] as $chequeo) {
            if (! $chequeo['ok']) {
                $fallidos[] = $chequeo['chequeo'];
            }
        }

        if (count($fallidos) > 0) {
            return $this->error_422(
                'No se puede aplicar el user setup: ' . implode(', ', $fallidos) . '. No se aplicó nada.',
                [
                    'implementation_id' => (int) $implementation->id,
                    'chequeos'          => $plan['chequeos'],
                    'ayuda'             => 'El dry_run (que es el default) muestra los chequeos con su detalle.',
                ]
            );
        }

        return $this->impedimento_por_el_intento_anterior($implementation, $reintentar, $conciliar);
    }

    /**
     * ¿Qué dice el intento anterior sobre lo que se puede hacer ahora? La respuesta de error, o null.
     *
     * 🔴 Tras un `error` NO se repite la llamada tal cual: el intento anterior pudo haber corrido del otro
     * lado (un 502, un timeout o un worker muerto no prueban lo contrario) y repetirlo es otro `migrate:fresh`.
     * Se pide `reintentar: true` (el dueño NO existe en el sistema del cliente: se vuelve a despachar) o
     * `conciliar: true` (el dueño YA existe: se da por aplicado sin llamar a nadie), y hay que elegir.
     *
     * Y al revés: sin un error previo esos dos parámetros no tienen sentido —`conciliar` marcaría como
     * aplicado algo que nunca se intentó— y se rechazan, en vez de ignorarlos en silencio.
     *
     * @param Implementation $implementation La implementación.
     * @param bool           $reintentar     `reintentar` del pedido.
     * @param bool           $conciliar      `conciliar` del pedido.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function impedimento_por_el_intento_anterior(Implementation $implementation, $reintentar, $conciliar)
    {
        $estado   = $this->estado_del_user_setup($implementation);
        $en_error = $estado['estado'] === 'error';

        /* Pide decisión un `error` y un `en_curso` colgado DESPUÉS de llamar al cliente (con `llamada_iniciada_at`):
           los dos pudieron haber corrido del otro lado. Un colgado en el que el job nunca arrancó, no. */
        if (! $this->pide_decision_el_intento_anterior($estado)) {
            if (! $reintentar && ! $conciliar) {
                return null;
            }

            return $this->error_422(
                '`' . ($conciliar ? 'conciliar' : 'reintentar') . '` solo se acepta cuando el último intento del user setup terminó en error o quedó colgado '
                    . 'DESPUÉS de llamar al sistema del cliente, y este no (estado: ' . $estado['estado'] . '). Sin ese parámetro la llamada normal aplica. '
                    . 'No se aplicó nada.',
                [
                    'implementation_id' => (int) $implementation->id,
                    'user_setup'        => $estado,
                ]
            );
        }

        if ($reintentar || $conciliar) {
            return null;
        }

        $opciones = $this->opciones_tras_un_error($estado);
        $puede    = $estado['puede_haber_corrido'] !== false;

        return $this->error_422(
            'El último intento del user setup ' . ($en_error
                ? 'terminó en error' . ($puede ? ' y PUDO HABER CORRIDO del otro lado' : '') . ' (' . (string) $this->recortar($estado['error']) . ')'
                : 'quedó COLGADO después de llamar al sistema del cliente (sin resultado hace más de ' . self::MINUTOS_PARA_DAR_POR_COLGADO . ' minutos) y PUDO HABER CORRIDO del otro lado')
                . '. Repetir la llamada tal cual no alcanza: reintentar le vuelve a vaciar la base al cliente '
                . '(migrate:fresh) y conciliar da por aplicado lo que ya corrió. Mirá si el dueño existe en el sistema del cliente (con sus listas de precios y sus depósitos) y mandá EXACTAMENTE UNO de '
                . 'estos dos parámetros, con dry_run=false y confirm_client_name: `conciliar: true` (el dueño ya existe: no llama al cliente) o '
                . '`reintentar: true` (no existe o quedó a medias: vuelve a despachar). No se aplicó nada.',
            [
                'implementation_id'   => (int) $implementation->id,
                'user_setup'          => $estado,
                'puede_haber_corrido' => $estado['puede_haber_corrido'],
                'sugerida'            => $opciones['sugerida'],
                'opciones'            => $opciones['opciones'],
                'ayuda'               => 'El dry_run (que es el default) dice cuál corresponde (`corresponde`) y qué hace cada una (`opciones`).',
            ]
        );
    }

    /**
     * Las dos salidas que hay tras un error del user setup y cuál se sugiere.
     *
     * Se sugiere `conciliar` cuando el error PUDO HABER CORRIDO (o el registro no lo dice: un registro viejo
     * se lee como "pudo") y `reintentar` cuando se sabe que no corrió. Es una sugerencia, no una decisión: la
     * que vale es la de quien mira el sistema del cliente.
     *
     * @param array<string, mixed> $estado El bloque `user_setup` de `estado_del_user_setup()`.
     *
     * @return array{sugerida: string, opciones: array<int, array<string, mixed>>}
     */
    protected function opciones_tras_un_error(array $estado)
    {
        $puede = ! isset($estado['puede_haber_corrido']) || $estado['puede_haber_corrido'] !== false;

        return [
            'sugerida' => $puede ? 'conciliar' : 'reintentar',
            'opciones' => [
                [
                    'parametro' => 'conciliar',
                    'cuando'    => 'El dueño YA existe en el sistema del cliente, con sus listas de precios y sus depósitos (`motor <cliente> metricas`): el intento '
                        . 'anterior llegó a correr (un 502, un timeout o un worker muerto no prueban lo contrario). Si existe pero sin sus listas o sus '
                        . 'depósitos, se cortó a medias: no se concilia ni se reintenta por cuenta propia.',
                    'que_hace'  => 'NO llama al sistema del cliente: llena user_setup_executed_at, deja el estado en ok con la nota "'
                        . self::NOTA_DE_CONCILIACION . '" y registra la acción user_setup (canal claude).',
                ],
                [
                    'parametro' => 'reintentar',
                    'cuando'    => 'El dueño NO existe en el sistema del cliente, o quedó a medias: el intento anterior no llegó a terminar.',
                    'que_hace'  => 'Vuelve a despachar el job con un token nuevo: del otro lado corre migrate:fresh otra vez y VACÍA la base.',
                ],
            ],
        ];
    }

    /**
     * Qué corresponde hacer con el user setup según cómo está, para el dry-run.
     *
     * `ninguna` (ya aplicado: no se vuelve a aplicar por acá), `esperar` (hay uno en curso), `conciliar` o
     * `reintentar` (el último intento terminó en error: ver `opciones_tras_un_error()`) o `aplicar` (nada
     * antes, o uno colgado que se puede volver a intentar).
     *
     * @param array<string, mixed> $plan   El plan de `plan_del_user_setup()`.
     * @param array<string, mixed> $estado El bloque `user_setup` de `estado_del_user_setup()`.
     *
     * @return string
     */
    protected function que_corresponde_hacer(array $plan, array $estado)
    {
        if ($plan['aplicado']) {
            return 'ninguna';
        }

        if ($plan['en_curso']) {
            return 'esperar';
        }

        if ($this->pide_decision_el_intento_anterior($estado)) {
            $opciones = $this->opciones_tras_un_error($estado);

            return $opciones['sugerida'];
        }

        return 'aplicar';
    }

    /**
     * La respuesta del dry-run del user setup: los chequeos y el payload REAL con las claves tapadas.
     *
     * El payload sale de `ImplementationActionService::preview()` —el mismo preview del panel, que arma el
     * mismo payload que `trigger_user_setup()` y le tapa las claves de servicios pagos—, así que lo que se
     * ve acá es lo que va a viajar.
     *
     * 🔴 Lleva el mail, el documento (el usuario con el que entra el dueño) y el teléfono del dueño, y quien
     * lo lee es una sesión de Claude que después lo pega en una conversación. Para decidir si se aplica no
     * hace falta verlos enteros: por defecto salen ENMASCARADOS (`p***@dominio`, `***4567`) y enteros solo con
     * `include=contacto`, igual que el contacto de `GET claude/implementations/{id}`.
     *
     * @param Implementation       $implementation La implementación.
     * @param array<string, mixed> $plan           El plan de `plan_del_user_setup()`.
     * @param bool                 $con_contacto   true = el mail, el documento y el teléfono enteros.
     *
     * @return array<string, mixed>
     */
    protected function respuesta_del_dry_run_del_user_setup(Implementation $implementation, array $plan, $con_contacto = false)
    {
        $listo = true;
        foreach ($plan['chequeos'] as $chequeo) {
            if (! $chequeo['ok']) {
                $listo = false;
            }
        }

        $preview = (new ImplementationActionService())->preview($implementation, 'user_setup');
        $payload = json_decode((string) $preview['body'], true);
        $payload = is_array($payload) ? $payload : [];

        if (! $con_contacto) {
            $payload = $this->enmascarar_los_datos_personales_del_payload($payload);
        }

        /* Lo que pasó con el intento anterior y qué corresponde hacer ahora. */
        $estado      = $this->estado_del_user_setup($implementation);
        $corresponde = $this->que_corresponde_hacer($plan, $estado);
        $en_error    = $this->pide_decision_el_intento_anterior($estado) && ! $plan['aplicado'];

        $respuesta = [
            'dry_run'                 => true,
            'implementation_id'       => (int) $implementation->id,
            'listo'                   => $listo,
            'chequeos'                => $plan['chequeos'],
            'ultimo_intento'          => $estado['estado'] === 'sin_correr' ? null : [
                'estado'              => $estado['estado'],
                'iniciado_at'         => $estado['iniciado_at'],
                'terminado_at'        => $estado['terminado_at'],
                'error'               => $estado['error'],
                'puede_haber_corrido' => isset($estado['puede_haber_corrido']) ? $estado['puede_haber_corrido'] : null,
            ],
            'corresponde'             => $corresponde,
            'parametros_para_aplicar' => $en_error ? [$corresponde => true] : [],
            'destino'                 => [
                'endpoint'         => $plan['endpoint'],
                'timeout_segundos' => EjecutarUserSetupDeImplementacionJob::TIMEOUT_DE_LA_LLAMADA,
            ],
            'payload'                 => $payload,
            'aviso_destructivo'       => 'Del otro lado el setup arranca con migrate:fresh --force: VACÍA la base del sistema del cliente. Solo se aplica sobre una '
                . 'instalación recién hecha y una sola vez; este camino no tiene forzar.',
        ];

        if ($en_error) {
            $opciones = $this->opciones_tras_un_error($estado);

            $respuesta['sugerida'] = $opciones['sugerida'];
            $respuesta['opciones'] = $opciones['opciones'];
        }

        $respuesta['nota'] = 'Simulacro: no se encoló ni se escribió nada. Repetí con dry_run=false y confirm_client_name para aplicar la configuración.'
            . ($en_error
                ? ' 🔴 Como el último intento ' . ($estado['estado'] === 'error' ? 'terminó en error' : 'quedó colgado después de llamar al cliente')
                    . ($estado['puede_haber_corrido'] === false ? '' : ' y pudo haber corrido del otro lado')
                    . ', mirá si el dueño existe en el sistema del cliente y mandá además `' . $corresponde . ': true` (o el otro: ver `opciones`): sin uno de los dos, el real es 422.'
                : '');

        return $respuesta;
    }

    /**
     * Enmascara en el payload del user setup los tres datos personales del dueño: `email`, `doc_number` y
     * `phone`, y tapa enteros la dirección del negocio y sus redes (`address_company`, `facebook`, `instagram`).
     *
     * El mail conserva la primera letra y el dominio (`p***@ejemplo.test`, el mismo formato que la casilla de
     * los mails de hito); el documento y el teléfono, solo los últimos cuatro dígitos (`***4567`). Un dato
     * vacío o ausente se deja como está, y uno con menos de cuatro dígitos sale tapado entero: no se muestra
     * a medias lo que no alcanza para taparse.
     *
     * @param array<string, mixed> $payload El payload del user setup.
     *
     * @return array<string, mixed>
     */
    protected function enmascarar_los_datos_personales_del_payload(array $payload)
    {
        foreach (['email', 'doc_number', 'phone'] as $campo) {
            if (! isset($payload[$campo]) || is_array($payload[$campo]) || trim((string) $payload[$campo]) === '') {
                continue;
            }

            $payload[$campo] = $campo === 'email'
                ? ImplementacionMailHelper::enmascarar((string) $payload[$campo])
                : $this->enmascarar_un_numero((string) $payload[$campo]);
        }

        /* La dirección del negocio y sus redes también salen solo con `include=contacto` en `GET ?include=formulario`: acá
           igual (se tapan enteras: no hay una parte que sirva para decidir si se aplica). */
        foreach (['address_company', 'facebook', 'instagram'] as $campo) {
            if (isset($payload[$campo]) && ! is_array($payload[$campo]) && trim((string) $payload[$campo]) !== '') {
                $payload[$campo] = '***';
            }
        }

        return $payload;
    }

    /**
     * Tapa un número (documento, teléfono) dejando los últimos cuatro dígitos: `20-30405060-7` → `***0607`.
     *
     * Se enmascara por los dígitos y no por el texto: un documento con puntos o guiones y un teléfono con
     * `+`, espacios o paréntesis muestran lo mismo.
     *
     * @param string $valor El número tal como está.
     *
     * @return string
     */
    protected function enmascarar_un_numero($valor)
    {
        $digitos = preg_replace('/\D+/', '', $valor);

        return strlen((string) $digitos) < 4 ? '***' : '***' . substr($digitos, -4);
    }

    /* ==============================================================================================
     | 7) POST claude/implementations/{id}/mail — el MAIL de cada hito
     |============================================================================================= */

    /**
     * Manda (o simula) el mail de un hito de la implementación al dueño del negocio: bienvenida, sistema
     * instalado, acceso, fotos, categorías o sistema listo. Cada uno lleva la línea de progreso con el
     * estado real de las ocho etapas.
     *
     * 🔴 ESTE ENDPOINT SOLO ARMA LA RESPUESTA Y PONE LOS FRENOS. El armado del mail, el envío, la casilla
     * y el registro viven en `ImplementacionMailService`: acá no hay ni una regla de qué dice cada mail ni
     * de a quién le llega. Es la misma separación que el resto del bloque: el controlador frena y contesta,
     * el servicio hace.
     *
     * Qué hace cada llamada:
     *   - `dry_run` (default TRUE): `previa()` — el mail armado, con su asunto, su HTML, la casilla
     *     enmascarada y lo que le FALTA para poder mandarse (`faltan`: `email`, `form_link`, `url_sistema`),
     *     tal cual lo devuelve el servicio. No manda ni escribe NADA. Un hito que ya salió se avisa en
     *     `ya_enviado` (el real pediría `reenviar=true`).
     *   - `dry_run=false`: `enviar()`, con `confirm_client_name`. Síncrono, por el mailer `admin`
     *     (`admin@comerciocity.com`), y registrado en `implementation_mails`.
     *
     * Frenos: lista blanca de parámetros y tipos; los datos del hito se validan por hito ANTES de armar
     * nada (`validar_datos()`); `confirm_client_name` exacto; y los del servicio —un solo envío por
     * (implementación, hito) salvo `reenviar`, un lock por hito contra el doble envío, y la casilla—.
     *
     * 🔴 Las excepciones de negocio del servicio (`ImplementacionMailException`) se contestan con SU
     * status —422, o 409 si hay otro envío del mismo hito en vuelo— y su `motivo` corto y estable
     * (`sin_mail`, `ya_enviado`, `faltan_datos`, `hito_invalido`, `envio_en_curso`), para decidir sin
     * parsear el texto. Un envío que FALLA (SMTP caído, sin credencial en el `.env`) NO tira: vuelve 200
     * con `estado: error` y el motivo, porque el intento quedó registrado y es lo que hay que mirar.
     *
     * 🔴 Una casilla pasada en `email` pisa a las demás y, si el mail sale, se guarda en `clients.email`:
     * así el aviso de actualización tampoco queda en `sin_mail`. Es varchar(150): por eso el tope.
     *
     * @param Request    $request Body: hito, datos?, email?, reenviar?, dry_run?, confirm_client_name.
     * @param int|string $id      Id de la implementación (segmento de la URL).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function mail_json(Request $request, $id)
    {
        /* --- Freno 1: lista blanca y tipos. --- */
        $rechazo = $this->rechazar_parametros_de_mas($request, self::PARAMETROS_DEL_MAIL, 'POST claude/implementations/{id}/mail');
        if ($rechazo !== null) {
            return $rechazo;
        }

        $invalido = $this->validar_o_422($request, [
            'hito'                => 'required|string|in:' . implode(',', ImplementacionMailService::HITOS),
            'datos'               => 'nullable|array',
            'email'               => 'nullable|email:rfc|max:' . ImplementacionMailService::MAX_CASILLA,
            'reenviar'            => 'nullable|boolean',
            'dry_run'             => 'nullable|boolean',
            'confirm_client_name' => 'required_if:dry_run,false|nullable|string|max:190',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $implementation = Implementation::find((int) $id);
        if ($implementation === null) {
            return $this->error_404('no existe la implementación ' . (int) $id);
        }

        $client = Client::find((int) $implementation->client_id);
        if ($client === null) {
            return $this->error_404('la implementación ' . (int) $id . ' apunta a un cliente que no existe');
        }

        $hito     = (string) $request->input('hito');
        $datos    = is_array($request->input('datos')) ? $request->input('datos') : [];
        $email    = $this->texto_o_null($request->input('email'));
        $reenviar = $this->booleano_o_null($request, 'reenviar') === true;
        $dry_run  = $this->resolver_dry_run($request);

        /* --- Freno 2: los datos del hito, por hito, antes de armar nada. --- */
        $errores = ImplementacionMailService::validar_datos($hito, $datos);
        if (count($errores) > 0) {
            return $this->respuesta_del_error_del_mail(ImplementacionMailException::faltan_datos(array_keys($errores), $errores));
        }

        if ($dry_run) {
            try {
                $previa = ImplementacionMailService::previa($implementation, $hito, $datos, $email);
            } catch (ImplementacionMailException $e) {
                return $this->respuesta_del_error_del_mail($e);
            }

            $previo = $this->mail_previo($implementation, $hito);
            $ya_salio = $previo !== null && $previo['estado'] === ImplementationMail::ESTADO_ENVIADO;

            /* 🔴 Sin credencial en el mailer `admin` el mail NO puede salir: el real daría `estado: error`. El dry-run no tiene
               que decir `listo` para algo que va a fallar. */
            $falta_del_mailer = ImplementacionMailService::que_le_falta_al_mailer();

            return response()->json([
                'dry_run'          => true,
                'hito'             => $hito,
                'listo'            => count($previa['faltan']) === 0 && (! $ya_salio || $reenviar) && $falta_del_mailer === null,
                'asunto'           => $previa['asunto'],
                'para_enmascarado' => $previa['para_enmascarado'],
                'html'             => $previa['html'],
                'faltan'           => $previa['faltan'],
                'mailer'           => ['listo' => $falta_del_mailer === null, 'falta' => $falta_del_mailer],
                'ya_enviado'       => $previo,
                'nota'             => 'Simulacro: no se mandó ni se escribió nada. '
                    . ($falta_del_mailer !== null ? 'El mailer admin no tiene con qué mandar: ' . $falta_del_mailer . ' ' : '')
                    . ($ya_salio && ! $reenviar ? 'Este hito YA salió: el real pide reenviar=true. ' : '')
                    . 'Repetí con dry_run=false y confirm_client_name para mandarlo.',
            ], 200);
        }

        /* --- Freno 3: confirmación por nombre. --- */
        $rechazo = $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se mandó nada.');
        if ($rechazo !== null) {
            return $rechazo;
        }

        try {
            $resultado = ImplementacionMailService::enviar($implementation, $hito, $datos, $email, $reenviar);
        } catch (ImplementacionMailException $e) {
            return $this->respuesta_del_error_del_mail($e);
        }

        $respuesta = [
            'dry_run'          => false,
            'hito'             => $hito,
            'enviado'          => $resultado['estado'] === 'enviado',
            'estado'           => $resultado['estado'],
            'para_enmascarado' => $resultado['para_enmascarado'],
            'enviado_at'       => $resultado['enviado_at'],
            'reenvios'         => (int) $resultado['reenvios'],
            'error'            => $resultado['error'],
        ];

        /* El mail SALIÓ pero no se pudo anotar en implementation_mails: se avisa para que nadie lo reenvíe. */
        if (isset($resultado['aviso'])) {
            $respuesta['aviso'] = $resultado['aviso'];
        }

        return response()->json($respuesta, 200);
    }

    /**
     * La respuesta de una `ImplementacionMailException`: su status (422, o 409 si hay otro envío en
     * vuelo) y el cuerpo con el `motivo` corto y estable.
     *
     * Lleva el texto dos veces —`error` y `message`— a propósito: `error` es la forma que promete todo el
     * bloque `claude/*` y `message` es la que ya devuelve la propia excepción cuando nadie la atrapa
     * (`render()`), y la skill lee una u otra según de dónde venga la respuesta. Son idénticas.
     *
     * @param ImplementacionMailException $e La excepción.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_del_error_del_mail(ImplementacionMailException $e)
    {
        return response()->json([
            'error'   => $e->getMessage(),
            'message' => $e->getMessage(),
            'motivo'  => $e->getMotivo(),
            'errores' => $e->errores,
            'faltan'  => $e->faltan,
        ], (int) $e->getCode());
    }

    /**
     * Lo que ya pasó con el mail de un hito: la fila de `implementation_mails`, o null si nunca se
     * intentó (o si la tabla todavía no existe).
     *
     * La casilla sale enmascarada, con el mismo formato que `para_enmascarado`.
     *
     * @param Implementation $implementation La implementación.
     * @param string         $hito           El hito.
     *
     * @return array<string, mixed>|null
     */
    protected function mail_previo(Implementation $implementation, $hito)
    {
        if (! $this->existe_la_tabla_de_mails()) {
            return null;
        }

        $fila = ImplementationMail::where('implementation_id', $implementation->id)->where('hito', $hito)->first();

        if ($fila === null) {
            return null;
        }

        return [
            'estado'           => (string) $fila->estado,
            'enviado_at'       => $this->instante($fila->enviado_at),
            'reenvios'         => (int) $fila->reenvios,
            'para_enmascarado' => ImplementacionMailHelper::enmascarar((string) $fila->email),
            'error'            => $this->recortar($fila->error),
        ];
    }

    /* ==============================================================================================
     | Helpers
     |============================================================================================= */

    /**
     * Freno 1 de todo endpoint de este controlador: la lista blanca de parámetros.
     *
     * Cualquier clave de más (en el cuerpo o en la query) es 422 y no se escribe ni se lee nada. Un
     * parámetro que el endpoint no entiende suele ser alguien esperando que haga algo que no hace —un
     * `force`, un `mode`, un `forzar`—, y ignorarlo en silencio dejaría creer que se aplicó.
     *
     * @param Request            $request   La request.
     * @param array<int, string> $aceptados Los parámetros que el endpoint sí acepta.
     * @param string             $endpoint  "MÉTODO claude/..." para el mensaje.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si no hay nada de más.
     */
    protected function rechazar_parametros_de_mas(Request $request, array $aceptados, $endpoint)
    {
        $de_mas = array_values(array_diff(array_keys($request->all()), $aceptados));

        if (count($de_mas) === 0) {
            return null;
        }

        return $this->error_422(
            'Parámetros que ' . $endpoint . ' no acepta: ' . implode(', ', $de_mas) . '. No se hizo nada.',
            [
                'parametros_aceptados' => $aceptados,
                'ayuda'                => 'GET claude/catalog describe cada parámetro de cada endpoint de implementaciones.',
            ]
        );
    }

    /**
     * Los nombres de las ocho etapas: los del catálogo de la base y, donde falte alguno, los de
     * `ETAPAS`.
     *
     * @return array<int, string>
     */
    protected function nombres_de_etapas()
    {
        $nombres = self::ETAPAS;

        foreach (ImplementationStageConfig::query()->get(['stage_number', 'name']) as $config) {
            $numero = (int) $config->stage_number;

            if ($numero >= 1 && $numero <= 8 && trim((string) $config->name) !== '') {
                $nombres[$numero] = (string) $config->name;
            }
        }

        return $nombres;
    }

    /**
     * Un instante como ISO 8601 con el huso, o null.
     *
     * Con huso y no `toDateTimeString()`: la skill compara estos instantes entre sí y con la hora de
     * su máquina (que es UTC), y una hora sin huso es ambigua.
     *
     * @param mixed $valor Carbon, string de la base o null.
     *
     * @return string|null
     */
    protected function instante($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor->toIso8601String();
        }

        $parseado = $this->parsear_o_null($valor);

        return $parseado === null ? (string) $valor : $parseado->toIso8601String();
    }

    /**
     * Un texto recortado a `CARACTERES_POR_LINEA`, o null si está vacío.
     *
     * @param mixed $texto Texto crudo.
     *
     * @return string|null
     */
    protected function recortar($texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return null;
        }

        return mb_strlen($texto) > self::CARACTERES_POR_LINEA
            ? mb_substr($texto, 0, self::CARACTERES_POR_LINEA) . '…'
            : $texto;
    }

    /**
     * Cadena vacía a null, el resto igual.
     *
     * @param mixed $valor Valor crudo.
     *
     * @return mixed
     */
    protected function vacio_a_null($valor)
    {
        return $valor === '' ? null : $valor;
    }
}

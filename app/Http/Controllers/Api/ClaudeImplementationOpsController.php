<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminSetting;
use App\Models\Client;
use App\Models\ClientApi;
use App\Models\ClientInstallation;
use App\Models\DeploymentLog;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\ImplementationStageConfig;
use App\Models\Lead;
use App\Models\Version;
use App\Services\ClientEmpresaApiUrlResolver;
use App\Services\HostingProvisioningStructure;
use App\Services\ImplementationFormMapper;
use App\Services\ImplementationSettings;
use App\Services\ImplementationStartService;
use App\Services\PromoteLeadToClientService;
use App\Services\SubdomainSuggestionService;
use App\Mail\Helpers\ImplementacionMailHelper;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 *    vacía la base al cliente; el panel ofrece "forzar" y esta puerta NO. Si de verdad hace falta
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
     * isotipo de los mails, y el resto son nombres que Hostinger o los correos reservan. Un cliente
     * llamado "admin" no chocaría con ninguna `client_api` —por eso no lo ve el chequeo de unicidad— y
     * en cambio pisaría el panel de ComercioCity en la zona DNS.
     *
     * @var array<int, string>
     */
    const SUBDOMINIOS_RESERVADOS = ['admin', 'api', 'www', 'mail', 'smtp', 'ftp', 'webmail', 'demo', 'app', 'soporte', 'ns1', 'ns2'];

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

    /** Lista blanca de la lectura del estado por id. */
    const PARAMETROS_DE_LA_LECTURA = ['include'];

    /** Lista blanca de la lectura del estado por cliente o por lead. */
    const PARAMETROS_DE_LA_BUSQUEDA = ['client_id', 'lead_id', 'include'];

    /** Cuántas líneas de log de cada instalación se devuelven con `include=logs`. */
    const LINEAS_DE_LOG = 40;

    /** Recorte de cada línea de log y del motivo de fallo de una instalación, en caracteres. */
    const CARACTERES_POR_LINEA = 500;

    /**
     * Estados de la implementación que se leen de `implementation_stages.status`, tal cual.
     * `skipped` existe en el enum aunque el panel no lo escribe nunca: lo escribe `advance` con
     * `saltar=true`.
     */
    const ESTADOS_DE_ETAPA = ['pending', 'in_progress', 'completed', 'skipped'];

    /**
     * Minutos después de los cuales un user setup que dice `en_curso` se da por colgado.
     *
     * 🔴 Existe porque `en_curso` es un estado que escribe el endpoint ANTES de despachar el job y
     * que borra el job al terminar. Si el worker muere sin pasar ni por `handle()` ni por `failed()`
     * (un `kill -9`, un reinicio del servidor) el estado se queda en `en_curso` PARA SIEMPRE, y como
     * `user-setup` frena con 409 mientras haya uno en curso, la implementación quedaría trabada sin
     * que ninguna llamada pueda destrabarla. 45 minutos son tres veces el techo del job (900 s) y
     * cubren de sobra el peor setup medido (~565 s). Pasado ese tiempo el estado se REPORTA igual
     * (con `colgado: true`) y el endpoint deja volver a intentar.
     *
     * ⚠️ Darlo por colgado NO es riesgo de pisar un setup vivo: del otro lado empresa-api toma un
     * candado y contesta 409 si hay otro corriendo, y ese 409 vuelve como error, sin reintento.
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
     * @return array<string, string>
     */
    protected function mensajes_de_validacion()
    {
        return array_merge($this->mensajes_de_validacion_base(), [
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

        $client        = $implementation->client;
        $con_contacto  = in_array('contacto', $includes, true);
        $con_formulario = in_array('formulario', $includes, true);
        $con_logs      = in_array('logs', $includes, true);

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
        $nombres  = $this->nombres_de_etapas();
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
     * panel, y esa función incluye el DNI y el teléfono de cada empleado en la sección "Equipo". Sin
     * `include=contacto` se les saca esos dos datos ANTES de armar el resumen (y no se parchea el
     * texto de salida): queda solo el nombre. Lo demás del resumen —precios, stock, ventas, nombre y
     * dirección del negocio— no es un dato personal. El mail y el CUIT/documento del dueño no salen en
     * ningún caso: `build_summary()` no los incluye.
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
            'estado'      => $estado,
            'ejecutado_at' => $this->instante($implementation->user_setup_executed_at),
            'iniciado_at' => isset($registro['iniciado_at']) ? (string) $registro['iniciado_at'] : null,
            'terminado_at' => isset($registro['terminado_at']) ? (string) $registro['terminado_at'] : null,
            'error'       => isset($registro['error']) ? (string) $registro['error'] : null,
        ];

        if ($colgado) {
            $respuesta['colgado'] = true;
            $respuesta['nota']    = 'Dice en_curso desde hace más de ' . self::MINUTOS_PARA_DAR_POR_COLGADO
                . ' minutos: el job no pudo haber seguido vivo. POST claude/implementations/{id}/user-setup deja volver '
                . 'a intentar (si el setup en realidad seguía corriendo, el cliente contesta 409 y vuelve como error).';
        }

        return $respuesta;
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
     * ¿Hace más de `MINUTOS_PARA_DAR_POR_COLGADO` que arrancó el user setup que dice `en_curso`?
     *
     * Un `en_curso` sin fecha de arranque (no debería pasar: lo escribe el endpoint junto con ella) se
     * da por colgado: sin fecha no hay forma de saber desde cuándo corre y dejarlo trabado para
     * siempre es peor que dejar reintentar.
     *
     * @param array<string, mixed> $registro El registro de la etapa 2.
     *
     * @return bool
     */
    protected function esta_colgado(array $registro)
    {
        $iniciado = $this->parsear_o_null(isset($registro['iniciado_at']) ? $registro['iniciado_at'] : null);
        if ($iniciado === null) {
            return true;
        }

        return $iniciado->lt(now()->subMinutes(self::MINUTOS_PARA_DAR_POR_COLGADO));
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
            $item = [
                'id'                     => (int) $fila->id,
                'uuid'                   => (string) $fila->uuid,
                'group_uuid'             => $fila->group_uuid,
                'kind'                   => (string) $fila->kind,
                'status'                 => (string) $fila->status,
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

            if ($con_logs) {
                $item['logs'] = $this->ultimas_lineas_de_log($fila);
            }

            $bloque[] = $item;
        }

        return $bloque;
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
     * lo único que depende de ella. Se lee con el query builder y no con el modelo por lo mismo: esta
     * lectura no tiene por qué cargar una clase para saber si hay algo que mostrar.
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

        $filas = DB::table('implementation_mails')
            ->where('implementation_id', $implementation->id)
            ->orderBy('id')
            ->get(['hito', 'email', 'estado', 'enviado_at', 'reenvios', 'error']);

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
     *                                     con `client`, `implementation` y `promovido`, o con `client` y
     *                                     `conflicto` si entre medio alguien creó la implementación.
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

        if (in_array($subdominio, self::SUBDOMINIOS_RESERVADOS, true)) {
            return 'es un nombre reservado de la plataforma (' . implode(', ', self::SUBDOMINIOS_RESERVADOS) . ').';
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

            if (in_array($host_spa, $propios, true) || in_array($host_api, $con_api, true) || in_array($path_raiz, $propios, true)) {
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

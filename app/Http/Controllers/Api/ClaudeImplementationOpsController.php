<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientInstallation;
use App\Models\DeploymentLog;
use App\Models\Implementation;
use App\Models\ImplementationMessage;
use App\Models\ImplementationStage;
use App\Models\ImplementationStageConfig;
use App\Models\Lead;
use App\Services\ClientEmpresaApiUrlResolver;
use App\Services\ImplementationFormMapper;
use App\Services\ImplementationSettings;
use App\Mail\Helpers\ImplementacionMailHelper;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

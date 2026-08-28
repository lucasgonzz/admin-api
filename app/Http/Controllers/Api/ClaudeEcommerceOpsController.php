<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Jobs\RunEcommerceInstallationJob;
use App\Models\Client;
use App\Models\ClientEcommerce;
use App\Models\ClientEcommerceInstallation;
use App\Models\ClientSshCredential;
use App\Services\ClaudeQueryService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lectura y ACTUALIZACIÓN del ecommerce (tienda) de un cliente desde `claude/*`.
 *
 * 🔴 ESTO ARRANCA PIPELINES SSH REALES CONTRA EL SERVIDOR DE UN NEGOCIO. Una corrida de
 * `EcommerceDeploymentService` clona y compila `tienda-spa` en el VPS de builds, sube la SPA y el
 * código de la API por SFTP al hosting compartido del cliente y corre `composer install` allá.
 * Entre esto y la tienda que le vende al público no hay ningún entorno intermedio.
 *
 * 🔴 LO QUE ESTE CONTROLADOR NO HACE, Y ES UNA DECISIÓN, NO UN OLVIDO: **no existe ninguna ruta que
 * cree una `ClientEcommerceInstallation` con `mode = 'install'`.** La instalación inicial de una
 * tienda escribe el `.env` de `tienda-api` (base de datos, APP_KEY, claves del cliente), decide
 * paths de hosting y es irreversible desde afuera; la actualización sólo recompila y sobrescribe
 * código ya instalado. Son dos operaciones de riesgo distinto y acá sólo entra la segunda. La regla
 * está verificada mecánicamente en
 * `ActualizacionDelEcommercePorClaudeTest::test_ninguna_ruta_claude_crea_una_instalacion_inicial()`,
 * que además lee el fuente de esta clase y exige que haya UN solo `ClientEcommerceInstallation::create()`
 * y que su modo sea `self::MODO_ACTUALIZACION`, y que ninguna ruta `claude/*` apunte al controlador
 * del panel.
 *
 * 🔴 LOS DOS `dispatch()` DE ESTE CONTROLADOR VAN CON `->onConnection(self::CONEXION_DE_COLA)`,
 * SIN EXCEPCIÓN. `QUEUE_CONNECTION` es `sync` en este proyecto: un `dispatch()` pelado corre el
 * pipeline SSH ENTERO (`npm ci`, `npm run build`, uploads, `composer install`) adentro del request
 * HTTP, y lo mata `max_execution_time` con un fatal que no captura ni el `catch` del job. Por eso
 * estos endpoints devuelven **202** de inmediato y nunca esperan a que el pipeline termine.
 *
 * ⚠️ Y ACÁ ESTÁ LA CONVIVENCIA INCÓMODA, ESCRITA PARA QUE NADIE LA DESCUBRA DE NUEVO: el PANEL del
 * admin despacha el mismo job PELADO (`EcommerceInstallationController` líneas 95, 154 y 212:
 * `RunEcommerceInstallationJob::dispatch($installation->uuid)` sin `onConnection`), así que sus tres
 * botones corren el pipeline dentro del request. 🔴 Esta misión NO lo arregla a propósito: cambiar
 * el despacho del panel cambia el comportamiento del botón que Lucas usa todos los días, y eso es
 * una decisión suya, no un efecto colateral de agregarle endpoints a Claude. Consecuencia práctica:
 * mientras haya una corrida de Claude en curso, no se toca el botón del panel (y viceversa) — las
 * dos competirían por el lock del clone de `tienda-spa` en el VPS de builds.
 *
 * ⚠️ NADIE DESTRABA UNA CORRIDA COLGADA. Para los deployments de empresa existe
 * `deployments:vencer-colgados`; para `client_ecommerce_installations` **no existe el equivalente**.
 * Una corrida que quedó en `instalando` bloquea a esa tienda para siempre vía la precondición "ya
 * hay una corrida en curso", y hoy se saca a mano. Este controlador lo MIDE y lo DECLARA en
 * `salud.corrida_stale` + `salud.nota`; construir el vencedor es otra misión (ver la nota).
 *
 * ⚠️ Las tres precondiciones de arranque se REPLICAN, no se llaman: los `assert_*` de
 * `EcommerceInstallationController` son `protected` y devuelven `{"error": ...}` con otra forma que
 * la del bloque `claude/*`. Cada réplica lleva escrito de qué método original se espeja.
 */
class ClaudeEcommerceOpsController extends Controller
{
    /**
     * Los helpers genéricos del bloque `claude/*`: paginación por cursor, normalización de
     * parámetros y las respuestas 422/404 con la forma única del bloque. 🔴 Se usan del trait y no
     * se copian: seis copias de `validar_o_422` es la clase de error que este repo ya tiene
     * documentada.
     */
    use RespuestasParaClaude;

    /** Conexión de cola de los dos dispatch. 🔴 Explícita, siempre. Ver el docblock de la clase. */
    const CONEXION_DE_COLA = 'database';

    /**
     * Latencia máxima esperable entre el encolado y el arranque real del pipeline, en segundos.
     * El scheduler dispara `queue:work database --stop-when-empty` cada minuto: ese es el peor caso
     * de espera antes de ver movimiento. Mismo número y mismo motivo que
     * `ClaudeUpgradeOpsController::LATENCIA_MAXIMA_SEGUNDOS`.
     */
    const LATENCIA_MAXIMA_SEGUNDOS = 60;

    /**
     * Tope duro de tiendas por llamada al lote.
     *
     * 🔴 CINCO, Y EL NÚMERO ESTÁ DERIVADO DE LA INFRAESTRUCTURA, NO ELEGIDO A OJO. El scheduler
     * corre `queue:work` cada minuto **sin `withoutOverlapping()`** (decisión deliberada, comentada
     * en `Kernel.php`, para que un deployment de 30 minutos no deje sin worker a los demo setups),
     * así que varias corridas de ecommerce arrancan a la vez y compiten por el lock del clone de
     * `tienda-spa` en el VPS de builds. `EcommerceInstallationService::acquire_build_lock()`
     * reintenta cada 15 segundos hasta `services.deploy_tienda.build_lock_timeout` (1800 s) y
     * después tira `RuntimeException`. Con ~6 minutos de lock por corrida, la sexta supera los 30
     * minutos de espera y **muere sola**. Cinco es el último que entra.
     *
     * ⚠️ La alternativa obvia —subir `DEPLOY_TIENDA_BUILD_LOCK_TIMEOUT`— está descartada: es la
     * misma config que gobierna el panel, así que tocarla para agrandar un lote de Claude le
     * cambiaría el comportamiento a los botones de Lucas.
     */
    const MAX_LOTE_ECOMMERCE = 5;

    /**
     * Horas de enfriamiento del LOTE por tienda.
     *
     * 6 y no las 24 de `ClaudeLeadsOutboundController::COOLDOWN_HORAS`, porque lo que se evita es
     * distinto: una actualización de tienda es idempotente (siempre la última de `master`), así que
     * repetirla no le manda nada a nadie ni duplica ningún dato. Lo que el cooldown evita es el
     * DOBLE DISPARO del mismo lote —dos llamadas seguidas mientras la primera todavía no arrancó—,
     * que sí llenaría la cola de corridas peleándose por el lock del build.
     *
     * ⚠️ Sólo aplica al LOTE, no al endpoint de a uno. La asimetría es deliberada y es la inversa
     * de la de leads: allá el cooldown está en los dos lados porque cada envío es un WhatsApp
     * irreversible a una persona; acá el freno del individual es `confirm_client_name`, que el lote
     * no puede tener (§1.3 del plan), y una actualización repetida a mano es idempotente.
     */
    const COOLDOWN_HORAS_ECOMMERCE = 6;

    /**
     * 🔴 El único `mode` que este controlador escribe. Ver el docblock de la clase: ninguna ruta de
     * Claude crea una instalación inicial, y hay un test que lee este fuente para verificarlo.
     */
    const MODO_ACTUALIZACION = 'update';

    /** Estado con el que nace una corrida, antes de que el worker la tome. */
    const ESTADO_INICIAL = 'pendiente';

    /** Estado que significa "hay un pipeline SSH corriendo sobre esta tienda ahora mismo". */
    const ESTADO_EN_CURSO = 'instalando';

    /**
     * Estados en los que una corrida OCUPA la tienda y no se puede arrancar otra.
     *
     * 🔴 INCLUYE `pendiente` A PROPÓSITO, Y ACÁ SE ENDURECE RESPECTO DEL PANEL.
     * `EcommerceInstallationController::assert_no_running_installation()` mira sólo `instalando`, y
     * para el botón del panel alcanza: lo aprieta un humano que acaba de ver la pantalla. Del lado
     * de Claude no.
     *
     * El caso concreto que lo originó: `POST claude/ecommerce/updates` para el cliente X, el HTTP da
     * timeout del lado del que llama, y se reintenta dentro del minuto. La corrida nace en
     * `pendiente` y el worker tarda hasta `LATENCIA_MAXIMA_SEGUNDOS` (60) en levantarla, así que
     * mirando sólo `instalando` el freno no ve NADA y se crean una segunda corrida y un segundo job
     * sobre la misma tienda. Las dos pelean por el lock del clone de `tienda-spa` en el VPS de
     * builds — que es exactamente la contención de la que `MAX_LOTE_ECOMMERCE = 5` deriva su número:
     * el lock espera hasta 1800 s y después tira `RuntimeException`.
     *
     * ⚠️ El panel NO se toca: cambiarlo cambiaría un botón que Lucas usa, y esa asimetría es
     * deliberada. Está declarada en `limitaciones_conocidas` del catálogo.
     */
    const ESTADOS_QUE_OCUPAN_LA_TIENDA = [self::ESTADO_INICIAL, self::ESTADO_EN_CURSO];

    /** Valores válidos de `client_ecommerce_installations.mode`, para los filtros de lectura. */
    const MODES = ['install', 'update'];

    /** Valores válidos de `client_ecommerce_installations.status`, para los filtros de lectura. */
    const STATUSES = ['pendiente', 'instalando', 'completada', 'fallida'];

    /** Paginación del listado de tiendas. */
    const LIMIT_STORES_DEFAULT = 200;
    const LIMIT_STORES_MAX     = 500;

    /** Paginación del listado de corridas. */
    const LIMIT_INSTALLATIONS_DEFAULT = 100;
    const LIMIT_INSTALLATIONS_MAX     = 300;

    /**
     * Recorte de `failure_reason` en los listados. Espeja `ClaudeClientOpsController::ULTIMO_ERROR_CHARS`:
     * el motivo de fallo de un pipeline puede traer la salida cruda de `npm run build`, y una página
     * de 100 corridas con eso adentro no entra en ninguna ventana de contexto. La ficha de una
     * corrida sola sí lo devuelve entero.
     */
    const FAILURE_REASON_CHARS = 500;

    /**
     * 🔴 Los ÚNICOS parámetros que acepta el lote. Cualquier otra clave en el body o en la query
     * string lo rechaza entero.
     *
     * No es tiquismiquis: es la regla que `send_template_batch_json` publica como "no hay lenguaje
     * de filtros del lado de escritura, así un filtro mal escrito no se puede convertir en un envío
     * masivo", aplicada con más fuerza todavía porque acá el efecto no es un mensaje sino un
     * pipeline SSH sobre el servidor de un negocio. La lista blanca positiva es lo que hace que la
     * regla sea mecánica y no una promesa: un `?status=active` de más no se ignora en silencio (que
     * sería lo peligroso, porque el llamador creería haber filtrado), se rechaza con 422.
     */
    const PARAMETROS_DEL_LOTE = ['client_ids', 'dry_run', 'confirm_client_count', 'confirm_token'];

    /* ==============================================================================================
     | 1) GET claude/ecommerce/stores — qué tiendas hay y cuáles se pueden actualizar
     |============================================================================================= */

    /**
     * Tiendas configuradas, con su última corrida y si se pueden actualizar ahora mismo.
     *
     * 🔴 `puede_actualizarse` NO es una opinión de este endpoint: sale del MISMO método que usan los
     * dos endpoints de escritura para decidir (`motivo_por_el_que_no_se_puede_actualizar()`). Si
     * fueran dos cálculos distintos, este listado diría "sí" y el POST contestaría 422, que es
     * exactamente la clase de error que un endpoint de lectura viene a evitar.
     *
     * ⚠️ `en_cooldown_del_lote` va aparte de `puede_actualizarse` porque el cooldown SÓLO gobierna
     * al lote: una tienda en cooldown se actualiza igual de a uno, que lleva `confirm_client_name`.
     *
     * Las tres consultas auxiliares (última corrida, corridas en curso, cooldown) son AGREGADAS
     * para toda la página: nada de una consulta por fila.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function stores_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'client_id' => 'nullable|integer|min:1',
            'status'    => 'nullable|string|in:pending,installing,active',
            'after_id'  => 'nullable|integer|min:1',
            'limit'     => 'nullable|integer',
            'order'     => 'nullable|string|in:asc,desc',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $direction = $this->texto_con_default($request, 'order', 'asc');
        $limit     = $this->resolver_limite($request->input('limit'), self::LIMIT_STORES_DEFAULT, self::LIMIT_STORES_MAX);
        $after_id  = $this->entero_o_null($request->input('after_id'));

        $query = ClientEcommerce::query()->with('client');

        $client_id = $this->entero_o_null($request->input('client_id'));
        if ($client_id !== null) {
            $query->where('client_id', $client_id);
        }

        $status = $this->texto_o_null($request->input('status'));
        if ($status !== null) {
            $query->where('status', $status);
        }

        $this->aplicar_cursor($query, 'id', $after_id, $direction);
        $query->orderBy('id', $direction);

        $pagina   = $this->traer_pagina($query, $limit);
        $tiendas  = $pagina['rows'];
        $ids      = [];
        foreach ($tiendas as $tienda) {
            $ids[] = (int) $tienda->id;
        }

        $contexto = $this->contexto_de_precondiciones($ids);
        $ultimas  = $this->ultima_corrida_por_tienda($ids);

        $data = [];
        foreach ($tiendas as $tienda) {
            $id     = (int) $tienda->id;
            $motivo = $this->motivo_por_el_que_no_se_puede_actualizar($tienda, $contexto);

            $data[] = [
                'client_ecommerce_id'  => $id,
                'client_id'            => $tienda->client_id === null ? null : (int) $tienda->client_id,
                'client_name'          => $tienda->client === null ? null : $tienda->client->name,
                'domain'               => $tienda->resolve_domain(),
                'spa_url'              => $tienda->spa_url,
                'api_url'              => $tienda->api_url,
                'status'               => $tienda->status,
                'ultima_corrida'       => isset($ultimas[$id]) ? $ultimas[$id] : null,
                'puede_actualizarse'   => $motivo === null,
                'motivo'               => $motivo,
                'en_cooldown_del_lote' => in_array($id, $contexto['en_cooldown'], true),
            ];
        }

        $count = count($data);

        return response()->json([
            'data'                   => $data,
            'count'                  => $count,
            'has_more'               => $pagina['has_more'],
            'next_after_id'          => ($pagina['has_more'] && $count > 0) ? (int) $data[$count - 1]['client_ecommerce_id'] : null,
            'credenciales_ssh'       => [
                'vps'                => $contexto['ssh_vps'],
                'hosting_compartido' => $contexto['ssh_hosting'],
            ],
            'cooldown_horas_del_lote' => self::COOLDOWN_HORAS_ECOMMERCE,
            'nota'                    => 'Las credenciales SSH son GLOBALES (una fila por tipo en client_ssh_credentials), '
                . 'no por cliente: si faltan, no se puede actualizar ninguna tienda. `en_cooldown_del_lote` sólo afecta a '
                . 'POST claude/ecommerce/updates/batch; el endpoint de a uno no tiene cooldown porque su freno es '
                . 'confirm_client_name.',
        ], 200);
    }

    /* ==============================================================================================
     | 2) GET claude/ecommerce/installations — corridas, filtrables
     |============================================================================================= */

    /**
     * Corridas del pipeline de ecommerce, paginadas por cursor.
     *
     * `failure_reason` viene recortado acá (ver `FAILURE_REASON_CHARS`) y entero en la ficha de una
     * corrida sola.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function installations_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'client_id'           => 'nullable|integer|min:1',
            'client_ecommerce_id' => 'nullable|integer|min:1',
            'mode'                => 'nullable|string|in:' . implode(',', self::MODES),
            'status'              => 'nullable|string|in:' . implode(',', self::STATUSES),
            'created_via'         => 'nullable|string|max:30',
            'desde'               => 'nullable|string|max:40',
            'hasta'               => 'nullable|string|max:40',
            'after_id'            => 'nullable|integer|min:1',
            'limit'               => 'nullable|integer',
            'order'               => 'nullable|string|in:asc,desc',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $direction = $this->texto_con_default($request, 'order', 'desc');
        $limit     = $this->resolver_limite($request->input('limit'), self::LIMIT_INSTALLATIONS_DEFAULT, self::LIMIT_INSTALLATIONS_MAX);
        $after_id  = $this->entero_o_null($request->input('after_id'));

        /* Un solo JOIN para traer el cliente de cada corrida: sin esto, resolver el nombre sería una
           consulta por fila, que es la regla 1 del bloque `claude/*`. */
        $query = DB::table('client_ecommerce_installations as cei')
            ->leftJoin('client_ecommerces as ce', 'ce.id', '=', 'cei.client_ecommerce_id')
            ->leftJoin('clients as c', 'c.id', '=', 'ce.client_id')
            ->select([
                'cei.id', 'cei.uuid', 'cei.client_ecommerce_id', 'cei.mode', 'cei.status',
                'cei.created_via', 'cei.failure_reason', 'cei.started_at', 'cei.finished_at',
                'cei.created_at', 'cei.updated_at',
                'ce.client_id', 'ce.domain', 'c.name as client_name',
            ]);

        $client_id = $this->entero_o_null($request->input('client_id'));
        if ($client_id !== null) {
            $query->where('ce.client_id', $client_id);
        }

        $client_ecommerce_id = $this->entero_o_null($request->input('client_ecommerce_id'));
        if ($client_ecommerce_id !== null) {
            $query->where('cei.client_ecommerce_id', $client_ecommerce_id);
        }

        $mode = $this->texto_o_null($request->input('mode'));
        if ($mode !== null) {
            $query->where('cei.mode', $mode);
        }

        $status = $this->texto_o_null($request->input('status'));
        if ($status !== null) {
            $query->where('cei.status', $status);
        }

        $created_via = $this->texto_o_null($request->input('created_via'));
        if ($created_via !== null) {
            $query->where('cei.created_via', $created_via);
        }

        /* Una fecha que no se pudo parsear se rechaza en vez de ignorarse: un filtro silenciosamente
           descartado devuelve MÁS filas de las pedidas, que es el error caro acá.

           🔴 `ClaudeQueryService::fecha_estricta()` Y NO `parsear_o_null()`, y no es una preferencia
           de estilo: el helper del trait delega en `Carbon::parse()`, que para `'x'` NO lanza nada y
           devuelve AHORA. Con él, este `if` nunca se cumplía, el 422 que promete el comentario no
           existía, y la consulta salía filtrada por `created_at >= <ahora>` — cero filas, siempre, y
           en silencio. Es exactamente el mismo defecto que ya se arregló en el filtro `fecha_desde`
           de `GET claude/query`, y por eso se usa LA MISMA función y no una copia: dos definiciones
           de "qué es una fecha válida" se desincronizan y arreglar una deja la otra rota. */
        $desde_crudo = $this->texto_o_null($request->input('desde'));
        if ($desde_crudo !== null) {
            $desde = ClaudeQueryService::fecha_estricta($desde_crudo);
            if ($desde === null) {
                return $this->error_422(
                    'El parámetro desde no es una fecha válida: "' . $desde_crudo . '".',
                    $this->ayuda_de_fecha()
                );
            }
            $query->where('cei.created_at', '>=', $desde);
        }

        $hasta_crudo = $this->texto_o_null($request->input('hasta'));
        if ($hasta_crudo !== null) {
            $hasta = ClaudeQueryService::fecha_estricta($hasta_crudo);
            if ($hasta === null) {
                return $this->error_422(
                    'El parámetro hasta no es una fecha válida: "' . $hasta_crudo . '".',
                    $this->ayuda_de_fecha()
                );
            }
            $query->where('cei.created_at', '<=', $hasta);
        }

        $this->aplicar_cursor($query, 'cei.id', $after_id, $direction);
        $query->orderBy('cei.id', $direction);

        $pagina = $this->traer_pagina($query, $limit);

        $data = [];
        foreach ($pagina['rows'] as $row) {
            $data[] = $this->proyectar_corrida($row, true);
        }

        $count = count($data);

        return response()->json([
            'data'          => $data,
            'count'         => $count,
            'has_more'      => $pagina['has_more'],
            'next_after_id' => ($pagina['has_more'] && $count > 0) ? (int) $data[$count - 1]['id'] : null,
            'nota'          => 'failure_reason viene recortado a ' . self::FAILURE_REASON_CHARS . ' caracteres en el '
                . 'listado (trae la salida cruda del pipeline). Entero, en GET claude/ecommerce/installations/{id}.',
        ], 200);
    }

    /**
     * Los extras del 422 de una fecha mal escrita: los formatos que sí se aceptan.
     *
     * Salen de `ClaudeQueryService::EJEMPLOS_DE_FECHA`, que es la misma lista que publica el 422 de
     * `GET claude/query`. Un solo criterio de fecha y un solo mensaje de ayuda.
     *
     * @return array<string, mixed>
     */
    private function ayuda_de_fecha()
    {
        return [
            'formatos_validos' => ClaudeQueryService::EJEMPLOS_DE_FECHA,
            'ayuda'            => 'Se acepta sólo una fecha absoluta en alguno de esos formatos. Nada de expresiones '
                . 'relativas ("ayer", "next monday"): parsean a algo que no es lo que se pidió y el filtro saldría '
                . 'aplicado y mal.',
        ];
    }

    /* ==============================================================================================
     | 3) GET claude/ecommerce/installations/{id} — ficha + salud
     |============================================================================================= */

    /**
     * Ficha de una corrida, con la salud calculada (no persistida).
     *
     * Acepta id numérico o uuid, igual que los endpoints de upgrades.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid de la corrida.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function installation_json(Request $request, $id)
    {
        $corrida = $this->buscar_corrida($id);
        if ($corrida === null) {
            return $this->error_404('no existe la corrida de ecommerce ' . $id);
        }

        $fila = $this->proyectar_corrida($corrida, false);

        return response()->json([
            'corrida'   => $fila,
            'salud'     => $this->salud_de_la_corrida($corrida),
            'endpoints' => $this->endpoints_de((int) $corrida->id),
        ], 200);
    }

    /* ==============================================================================================
     | 4) GET claude/ecommerce/installations/{id}/logs
     |============================================================================================= */

    /**
     * Logs de una corrida, paginados por cursor y con las líneas truncadas.
     *
     * 🔴 Es el MISMO contrato que `ClaudeClientOpsController::upgrade_logs_json()` —mismos
     * parámetros, mismos defaults, misma forma de respuesta— y los defaults se leen de las
     * constantes de ESE controlador en vez de repetirse acá. Copiar los números habría dado dos
     * definiciones de "cuántos logs entran en una página", que se desincronizan el día que alguien
     * ajuste una sola. Quien aprendió a leer los logs de un deployment ya sabe leer estos.
     *
     * 🔴 `max_line_chars` tiene default y no null por el mismo motivo que allá: los pasos
     * `ensure_spa_cloned` y `compile_spa` traen la salida cruda de `npm ci` y `npm run build`. Sin
     * truncar, una página no entra en la ventana de contexto.
     *
     * ⚠️ Diferencia real con los logs de empresa, y va escrita en la respuesta: acá NADIE borra los
     * logs. `POST claude/upgrades/{id}/deploy/start` borra los del intento anterior; una corrida de
     * ecommerce es una fila nueva con sus propias líneas, así que el historial se conserva entero.
     *
     * @param Request    $request Request entrante.
     * @param int|string $id      Id numérico o uuid de la corrida.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function installation_logs_json(Request $request, $id)
    {
        $invalido = $this->validar_o_422($request, [
            'step'           => 'nullable|string|max:60',
            'level'          => 'nullable|string|in:' . implode(',', ClaudeClientOpsController::LOG_LEVELS),
            'after_id'       => 'nullable|integer|min:1',
            'limit'          => 'nullable|integer',
            'order'          => 'nullable|string|in:asc,desc',
            'max_line_chars' => 'nullable|integer|min:1',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $corrida = $this->buscar_corrida($id);
        if ($corrida === null) {
            return $this->error_404('no existe la corrida de ecommerce ' . $id);
        }

        $direction = $this->texto_con_default($request, 'order', 'asc');
        $limit     = $this->resolver_limite(
            $request->input('limit'),
            ClaudeClientOpsController::LIMIT_LOGS_DEFAULT,
            ClaudeClientOpsController::LIMIT_LOGS_MAX
        );

        $max_line_chars = $this->entero_o_null($request->input('max_line_chars'));
        if ($max_line_chars === null) {
            $max_line_chars = ClaudeClientOpsController::MAX_LINE_CHARS_DEFAULT;
        }

        $after_id = $this->entero_o_null($request->input('after_id'));

        $query = DB::table('ecommerce_deployment_logs')
            ->where('client_ecommerce_installation_id', (int) $corrida->id);

        $step = $this->texto_o_null($request->input('step'));
        if ($step !== null) {
            $query->where('step', $step);
        }

        $level = $this->texto_o_null($request->input('level'));
        if ($level !== null) {
            $query->where('level', $level);
        }

        $this->aplicar_cursor($query, 'id', $after_id, $direction);
        $query->orderBy('id', $direction);
        $query->select(['id', 'step', 'level', 'line', 'created_at']);

        $pagina = $this->traer_pagina($query, $limit);

        $data = [];
        foreach ($pagina['rows'] as $row) {
            $log       = (array) $row;
            $log['id'] = (int) $log['id'];

            $linea = (string) $log['line'];
            $largo = mb_strlen($linea);

            if ($largo > $max_line_chars) {
                $log['line']           = mb_substr($linea, 0, $max_line_chars);
                $log['truncada']       = true;
                $log['largo_original'] = $largo;
            } else {
                $log['truncada'] = false;
            }

            $data[] = $log;
        }

        $count = count($data);

        return response()->json([
            'installation_id' => (int) $corrida->id,
            'status'          => $corrida->status,
            'data'            => $data,
            'count'           => $count,
            'has_more'        => $pagina['has_more'],
            'next_after_id'   => ($pagina['has_more'] && $count > 0) ? (int) $data[$count - 1]['id'] : null,
            'max_line_chars'  => $max_line_chars,
            'nota'            => 'A diferencia de los logs de un upgrade de empresa, acá NADIE los borra: cada corrida '
                . 'es una fila nueva con sus propias líneas y el historial queda entero.',
        ], 200);
    }

    /* ==============================================================================================
     | 5) POST claude/ecommerce/updates — actualización de UNA tienda
     |============================================================================================= */

    /**
     * Dispara la actualización de la tienda de un cliente.
     *
     * Frenos, en este orden, y ninguno escribe nada cuando rechaza:
     *   1. `confirm_client_name` — espeja `ClaudeUpgradeOpsController::rechazar_si_el_nombre_no_confirma()`,
     *      incluido el no revelar el nombre correcto y el caso "cliente sin nombre cargado".
     *   2. Las tres precondiciones del panel, replicadas con el contrato de error del bloque:
     *      tienda configurada, credenciales SSH, y ninguna corrida en curso.
     *
     * 🔴 NO hay `dry_run` acá, igual que en `send_template_json`: el freno del individual es el
     * nombre. Un `dry_run` en un endpoint que ya exige tipear el nombre del negocio no agrega
     * protección, agrega un paso que se saltea. La simulación es del LOTE, que es donde no hay un
     * nombre que confirmar.
     *
     * ⚠️ Tampoco hay cooldown acá: ver el docblock de `COOLDOWN_HORAS_ECOMMERCE`.
     *
     * @param Request $request Body: client_id, confirm_client_name.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'client_id'           => 'required|integer|min:1',
            'confirm_client_name' => 'required|string|max:190',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $client = Client::find((int) $request->input('client_id'));
        if ($client === null) {
            return $this->error_404('no existe el cliente ' . (int) $request->input('client_id'));
        }

        /* Freno 1: el nombre, antes de mirar nada más. */
        $rechazo = $this->rechazar_si_el_nombre_no_confirma($request, $client);
        if ($rechazo !== null) {
            return $rechazo;
        }

        $tienda = $client->client_ecommerce;
        if ($tienda === null) {
            return $this->error_422(
                'El cliente no tiene una tienda (ecommerce) configurada. No se encoló nada.',
                [
                    'client_id' => (int) $client->id,
                    'ayuda'     => 'La tienda se crea en la sección "Tienda online (ecommerce)" del perfil del cliente '
                        . 'en el admin. 🔴 Claude no la crea: ninguna ruta claude/* hace la instalación inicial.',
                ]
            );
        }

        /* Freno 2: las tres precondiciones, con el MISMO método que usa el lote y que publica
           GET claude/ecommerce/stores. Una sola definición de "se puede actualizar". */
        $contexto = $this->contexto_de_precondiciones([(int) $tienda->id]);
        $motivo   = $this->motivo_por_el_que_no_se_puede_actualizar($tienda, $contexto);
        if ($motivo !== null) {
            return $this->error_422(
                'No se puede actualizar la tienda de este cliente: ' . $motivo . '. No se encoló nada.',
                [
                    'client_id'           => (int) $client->id,
                    'client_ecommerce_id' => (int) $tienda->id,
                    'ayuda'               => 'Mirá GET claude/ecommerce/stores?client_id=' . (int) $client->id
                        . ' para ver el estado de la tienda y qué falta.',
                ]
            );
        }

        $corrida = $this->crear_corrida($tienda);

        /* 🔴 onConnection explícito: sin esto el pipeline SSH entero correría adentro de este
           request. Ver el docblock de la clase. */
        RunEcommerceInstallationJob::dispatch($corrida->uuid)->onConnection(self::CONEXION_DE_COLA);

        return response()->json($this->respuesta_de_encolado($client, $tienda, $corrida), 202);
    }

    /* ==============================================================================================
     | 6) POST claude/ecommerce/updates/batch — actualización en LOTE
     |============================================================================================= */

    /**
     * Dispara la actualización de hasta `MAX_LOTE_ECOMMERCE` tiendas nombradas una por una.
     *
     * 🔴 SÓLO `client_ids[]` EXPLÍCITOS. No hay ningún filtro ni selector de este lado (ver
     * `PARAMETROS_DEL_LOTE`): la selección se hace con `GET claude/ecommerce/stores`, se revisa, y
     * recién ahí se nombra a cada tienda. Es la misma regla que `send_template_batch_json`, pero acá
     * pesa más: un filtro mal escrito no mandaría un mensaje de más, arrancaría pipelines SSH sobre
     * servidores de negocios que nadie eligió.
     *
     * ⚠️ Asimetría deliberada con el lote de upgrades de empresa, que SÍ acepta el selector
     * `from_version_id`: aquél no toca ningún servidor, sólo escribe filas. Éste arranca corridas
     * reales. Está declarado en la propia respuesta para que la diferencia no parezca un olvido.
     *
     * Orden de los frenos, todos ANTES de crear la primera fila:
     *   1. Cualquier parámetro fuera de la lista blanca → 422, cero corridas.
     *   2. Más de `MAX_LOTE_ECOMMERCE` ids → 422, cero corridas.
     *   3. Resolución de candidatas: todo lo que no califica sale como `omitidos`.
     *   4. `dry_run` (default true) → devuelve qué tiendas se actualizarían y el `confirm_token`.
     *   5. `confirm_client_count` exacto.
     *   6. `confirm_token` con `hash_equals`.
     *
     * 🔴 Y recién ahí se escribe: las N filas van en UNA transacción y los N `dispatch()` van
     * DESPUÉS del commit, todos juntos. Si algo falla a mitad del alta, no queda ninguna corrida sin
     * job ni ningún job apuntando a una corrida que no existe — que es el estado del que nadie sabe
     * salir, porque el job marcaría `fallida` una fila fantasma.
     *
     * @param Request $request Body: client_ids[], dry_run, confirm_client_count, confirm_token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update_batch_json(Request $request)
    {
        /* --- Freno 1: lista blanca de parámetros. Va PRIMERO, antes incluso de validar los tipos:
           un `?status=active` mal puesto tiene que rebotar por lo que es —un filtro— y no colarse
           como parámetro ignorado. --- */
        $sobrantes = array_values(array_diff(array_keys($request->all()), self::PARAMETROS_DEL_LOTE));
        if (! empty($sobrantes)) {
            return $this->error_422(
                'El lote de ecommerce NO acepta filtros: sólo client_ids[] explícitos. Llegaron parámetros que no '
                    . 'existen en este endpoint (' . implode(', ', $sobrantes) . '). No se creó ninguna corrida.',
                [
                    'parametros_validos' => self::PARAMETROS_DEL_LOTE,
                    'ayuda'              => 'Elegí las tiendas con GET claude/ecommerce/stores, revisá la lista, y '
                        . 'nombrá a cada una en client_ids[]. La asimetría con POST claude/upgrades/batch (que sí acepta '
                        . 'un selector por versión) es deliberada: aquél sólo escribe filas y éste arranca pipelines SSH.',
                ]
            );
        }

        $invalido = $this->validar_o_422($request, [
            'client_ids'           => 'required|array|min:1',
            'client_ids.*'         => 'required|integer|min:1',
            'dry_run'              => 'nullable|boolean',
            'confirm_client_count' => 'nullable|integer|min:0',
            'confirm_token'        => 'nullable|string|max:64',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        /* --- Freno 2: tope duro, antes de tocar la base. --- */
        $ids_crudos = $request->input('client_ids', []);
        if (count($ids_crudos) > self::MAX_LOTE_ECOMMERCE) {
            return $this->error_422(
                'El lote no puede superar las ' . self::MAX_LOTE_ECOMMERCE . ' tiendas por llamada y llegaron '
                    . count($ids_crudos) . '. No se creó ninguna corrida: partilo en tandas y esperá a que cada una '
                    . 'termine.',
                [
                    'max_lote'  => self::MAX_LOTE_ECOMMERCE,
                    'recibidos' => count($ids_crudos),
                    'ayuda'     => 'El tope sale del lock del clone de tienda-spa en el VPS de builds, que espera hasta '
                        . '1800 s y después tira RuntimeException: con más de ' . self::MAX_LOTE_ECOMMERCE
                        . ' corridas simultáneas, las últimas mueren solas.',
                ]
            );
        }

        /* --- Freno 3: resolución de candidatas. Todo lo que no califica sale como omitido. --- */
        $omitidos   = [];
        $client_ids = [];
        foreach ($ids_crudos as $valor) {
            $id = (int) $valor;
            if (in_array($id, $client_ids, true)) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'id repetido en client_ids (se actualiza una sola vez)'];
                continue;
            }
            $client_ids[] = $id;
        }

        $clientes = Client::query()->whereIn('id', $client_ids)->with('client_ecommerce')->get()->keyBy('id');

        $ecommerce_ids = [];
        foreach ($clientes as $cliente) {
            if ($cliente->client_ecommerce !== null) {
                $ecommerce_ids[] = (int) $cliente->client_ecommerce->id;
            }
        }

        $contexto = $this->contexto_de_precondiciones($ecommerce_ids);

        $candidatas = [];
        foreach ($client_ids as $id) {
            if (! isset($clientes[$id])) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente no existe'];
                continue;
            }

            $cliente = $clientes[$id];

            /*
             * 🔴 Cliente sin nombre cargado: NO está en la lista de omisiones del plan y se agregó a
             * propósito. El `confirm_token` del lote reemplaza a `confirm_client_name` incorporando
             * el nombre normalizado de cada cliente, y el endpoint de a uno se niega en redondo a
             * operar sobre un cliente sin nombre ("este freno no se saltea"). Si el lote lo aceptara,
             * sería el camino para saltear ese freno: mando el cliente sin nombre en un lote de uno
             * y listo. Un freno que tiene una puerta de al lado no es un freno.
             */
            if ($this->normalizar_nombre($cliente->name) === '') {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente no tiene nombre cargado'];
                continue;
            }

            $tienda = $cliente->client_ecommerce;
            if ($tienda === null) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente no tiene tienda configurada'];
                continue;
            }

            $motivo = $this->motivo_por_el_que_no_se_puede_actualizar($tienda, $contexto);
            if ($motivo !== null) {
                $omitidos[] = ['client_id' => $id, 'motivo' => $motivo];
                continue;
            }

            /* El cooldown es del LOTE solamente, así que se evalúa acá y no adentro del método
               compartido: si estuviera ahí, el endpoint de a uno lo heredaría y dejaría de poder
               reintentar a mano una tienda que acaba de fallar. */
            if (in_array((int) $tienda->id, $contexto['en_cooldown'], true)) {
                $omitidos[] = [
                    'client_id' => $id,
                    'motivo'    => 'se actualizó por Claude hace menos de ' . self::COOLDOWN_HORAS_ECOMMERCE . ' hs',
                ];
                continue;
            }

            $candidatas[] = [
                'client_id'           => $id,
                'client_name'         => $cliente->name,
                'client_ecommerce_id' => (int) $tienda->id,
                'domain'              => $tienda->resolve_domain(),
                'spa_url'             => $tienda->spa_url,
                'api_url'             => $tienda->api_url,
                'tienda'              => $tienda,
            ];
        }

        $actualizarian  = count($candidatas);
        $confirm_token  = $this->calcular_confirm_token($candidatas);
        $tiendas_para_ver = [];
        foreach ($candidatas as $candidata) {
            unset($candidata['tienda']);
            $tiendas_para_ver[] = $candidata;
        }

        /* --- Freno 4: simulación. Es el default y no escribe absolutamente nada. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'        => true,
                'actualizarian'  => $actualizarian,
                'omitidos'       => $omitidos,
                'tiendas'        => $tiendas_para_ver,
                'confirm_token'  => $confirm_token,
                'max_lote'       => self::MAX_LOTE_ECOMMERCE,
                'nota'           => 'Simulación: no se creó ninguna corrida ni se encoló ningún job. REVISÁ la lista de '
                    . 'tiendas antes de seguir: cada una arranca un pipeline SSH contra el hosting de ese negocio. Para '
                    . 'actualizar de verdad, repetí la misma llamada con dry_run=false, confirm_client_count='
                    . $actualizarian . ' y confirm_token=' . $confirm_token . '.',
                'nota_asimetria' => '🔴 Este lote NO acepta filtros, sólo client_ids[]. POST claude/upgrades/batch sí '
                    . 'acepta un selector por versión, y la diferencia es deliberada: aquél sólo escribe filas y éste '
                    . 'arranca corridas reales sobre servidores de clientes.',
            ], 200);
        }

        /* --- Freno 5: confirmación del número exacto. --- */
        if (! $request->filled('confirm_client_count')) {
            return $this->error_422(
                'confirm_client_count es obligatorio cuando dry_run es false. No se creó ninguna corrida.',
                ['actualizarian' => $actualizarian, 'omitidos' => $omitidos]
            );
        }

        $confirm_client_count = (int) $request->input('confirm_client_count');
        if ($confirm_client_count !== $actualizarian) {
            return $this->error_422(
                'confirm_client_count no coincide con la cantidad real de tiendas a actualizar (' . $actualizarian
                    . '). No se creó ninguna corrida: revisá los omitidos y volvé con el número real.',
                [
                    'confirm_client_count_recibido' => $confirm_client_count,
                    'actualizarian'                 => $actualizarian,
                    'omitidos'                      => $omitidos,
                ]
            );
        }

        /*
         * --- Freno 6: el token ata la confirmación al CONJUNTO, no sólo a la cantidad. ---
         *
         * 🔴 Es el equivalente de `confirm_client_name` en un lote, donde no hay UN nombre que
         * confirmar (§1.3 del plan): el token incorpora el id Y el nombre normalizado de cada
         * cliente, así que un lote que cambió de clientes entre la simulación y la confirmación no
         * pasa. Sin esto, `confirm_client_count` se satisface con cualquier lote del mismo tamaño:
         * simular con las tiendas A y B y después actualizar C y D pasaba sin una advertencia.
         */
        $token_recibido = $this->texto_o_null($request->input('confirm_token'));
        if ($token_recibido === null) {
            return $this->error_422(
                'confirm_token es obligatorio cuando dry_run es false. Corré primero la simulación, revisá las tiendas '
                    . 'y volvé con el token que te devolvió. No se creó ninguna corrida.',
                ['actualizarian' => $actualizarian, 'confirm_token' => $confirm_token]
            );
        }

        if (! hash_equals($confirm_token, $token_recibido)) {
            return $this->error_422(
                'confirm_token no corresponde a este conjunto de tiendas: la lista de clientes o el nombre de alguno '
                    . 'cambiaron respecto de la simulación que generó ese token. No se creó ninguna corrida. Volvé a '
                    . 'simular y usá el token nuevo.',
                ['actualizarian' => $actualizarian, 'confirm_token_esperado' => $confirm_token]
            );
        }

        /*
         * --- Escritura real. ---
         *
         * 🔴 Las N filas en UNA transacción y los N dispatch DESPUÉS, todos juntos. Lo que ESTE
         * orden garantiza, exacto: **ningún job apunta a una fila que no existe**. Encolando adentro
         * de la transacción, un rollback dejaría jobs apuntando a filas revertidas y el job muere
         * con `firstOrFail`.
         *
         * ⚠️ LO QUE NO GARANTIZA, Y NO SE PROMETE: que no quede ninguna corrida sin job. Un
         * `dispatch()` es un INSERT en `jobs` y puede fallar; si falla el k-ésimo, las filas k..N ya
         * están commiteadas y se quedan en `pendiente` sin nadie que las corra. El daño es acotado
         * —una fila `pendiente` no bloquea la tienda para el panel, y `ESTADOS_QUE_OCUPAN_LA_TIENDA`
         * la va a frenar en el próximo intento de claude/*, que es visible y se destraba borrándola—
         * y la ventana es angosta, pero existe. Cerrarla del todo pide una outbox (encolar como
         * parte de la misma transacción, con un despachador aparte que la vacía), que es otra misión
         * y no una línea. Mientras tanto: el 202 devuelve las N corridas con su id, y
         * `GET claude/ecommerce/installations?created_via=claude` muestra cuáles arrancaron.
         */
        $corridas = DB::transaction(function () use ($candidatas) {
            $creadas = [];
            foreach ($candidatas as $candidata) {
                $creadas[] = [
                    'candidata' => $candidata,
                    'corrida'   => $this->crear_corrida($candidata['tienda']),
                ];
            }

            return $creadas;
        });

        $resultados = [];
        foreach ($corridas as $par) {
            RunEcommerceInstallationJob::dispatch($par['corrida']->uuid)->onConnection(self::CONEXION_DE_COLA);

            $id_corrida   = (int) $par['corrida']->id;
            $resultados[] = [
                'client_id'           => $par['candidata']['client_id'],
                'client_name'         => $par['candidata']['client_name'],
                'client_ecommerce_id' => $par['candidata']['client_ecommerce_id'],
                'installation_id'     => $id_corrida,
                'uuid'                => (string) $par['corrida']->uuid,
                'mode'                => self::MODO_ACTUALIZACION,
                'status'              => self::ESTADO_INICIAL,
                'endpoints'           => $this->endpoints_de($id_corrida),
            ];
        }

        return response()->json([
            'dry_run'                  => false,
            'creadas'                  => count($resultados),
            'omitidos'                 => $omitidos,
            'corridas'                 => $resultados,
            'conexion_de_cola'         => self::CONEXION_DE_COLA,
            'latencia_maxima_segundos' => self::LATENCIA_MAXIMA_SEGUNDOS,
            'created_via'              => ClientEcommerceInstallation::CREATED_VIA_CLAUDE,
            'nota_precondicion'        => $this->nota_de_precondicion(),
            'nota'                     => 'Las ' . count($resultados) . ' corridas se encolaron juntas y el worker las va '
                . 'a tomar de a una o de a varias según cuántos workers levante el scheduler. 🔴 Compiten por el lock del '
                . 'clone de tienda-spa en el VPS de builds: la primera compila y las demás esperan. Poleá cada 30 o 60 '
                . 'segundos con GET claude/ecommerce/installations?created_via=claude, no cada 2 (rate limit por IP).',
        ], 202);
    }

    /* ==============================================================================================
     | Frenos y precondiciones
     |============================================================================================= */

    /**
     * Freno del nombre: `confirm_client_name` tiene que coincidir con `clients.name`, comparado con
     * trim + mb_strtolower en las dos puntas.
     *
     * ✅ Ya NO es una réplica. El cuerpo se unificó en
     * `RespuestasParaClaude::rechazar_si_el_nombre_del_cliente_no_confirma()`: las dos diferencias
     * que habían frenado la unificación —el cierre del mensaje y el `upgrade_id` del otro
     * controlador— resultaron ser datos del que llama, no reglas distintas, así que entran por
     * parámetro sin cambiarle la respuesta a nadie. Esto que queda es el cierre propio de este
     * controlador, que encola en vez de escribir.
     *
     * 🔴 El error NO revela el nombre correcto, igual que el original. Si lo revelara dejaría de ser
     * un freno y sería un formulario a completar: quien se equivocó de cliente leería el nombre real,
     * lo copiaría, y actualizaría la tienda del negocio equivocado.
     *
     * @param Request $request Request entrante.
     * @param Client  $client  Cliente involucrado.
     *
     * @return \Illuminate\Http\JsonResponse|null Null si confirma bien.
     */
    private function rechazar_si_el_nombre_no_confirma(Request $request, Client $client)
    {
        return $this->rechazar_si_el_nombre_del_cliente_no_confirma($request, $client, 'No se encoló nada.');
    }

    /**
     * Junta, en cuatro consultas fijas, todo lo que hace falta para decidir si una tienda se puede
     * actualizar: credenciales SSH globales, tiendas con corrida en curso y tiendas en cooldown.
     *
     * 🔴 Agregado a propósito. La versión ingenua —preguntar por cada tienda adentro del loop— es
     * una consulta por fila en el listado y N consultas en el lote, que es la regla 1 del bloque
     * `claude/*`. Acá el costo es fijo: dos `exists()` y dos `pluck()` con `whereIn`.
     *
     * @param array<int, int> $ecommerce_ids Ids de las tiendas en juego.
     *
     * @return array<string, mixed>
     */
    private function contexto_de_precondiciones(array $ecommerce_ids)
    {
        /* Las credenciales SSH son GLOBALES (una fila por tipo), no por cliente: se preguntan una
           vez para todo el lote. Espeja `EcommerceInstallationController::assert_deploy_prerequisites()`,
           que hace exactamente estos dos `exists()`. */
        $contexto = [
            'ssh_vps'     => ClientSshCredential::where('type', 'vps')->exists(),
            'ssh_hosting' => ClientSshCredential::where('type', 'shared_hosting')->exists(),
            'corriendo'   => [],
            'en_cooldown' => [],
        ];

        if (empty($ecommerce_ids)) {
            return $contexto;
        }

        /* Espeja `EcommerceInstallationController::assert_no_running_installation()`, pero MÁS DURO:
           el panel mira sólo `instalando` y acá cuentan también las `pendiente`, porque una corrida
           recién encolada todavía no llegó al worker y un reintento del POST crearía una segunda
           sobre la misma tienda. El motivo completo está en ESTADOS_QUE_OCUPAN_LA_TIENDA. */
        $contexto['corriendo'] = DB::table('client_ecommerce_installations')
            ->whereIn('client_ecommerce_id', $ecommerce_ids)
            ->whereIn('status', self::ESTADOS_QUE_OCUPAN_LA_TIENDA)
            ->distinct()
            ->pluck('client_ecommerce_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        /* Cooldown: sólo cuenta lo que disparó CLAUDE. Sin `created_via` esta consulta no se podría
           escribir y el cooldown estaría frenando también al botón del panel, que no es asunto de
           Claude. Ver el docblock de la migración 2026_08_28_120000. */
        $contexto['en_cooldown'] = DB::table('client_ecommerce_installations')
            ->whereIn('client_ecommerce_id', $ecommerce_ids)
            ->where('created_via', ClientEcommerceInstallation::CREATED_VIA_CLAUDE)
            ->where('created_at', '>=', Carbon::now()->subHours(self::COOLDOWN_HORAS_ECOMMERCE))
            ->distinct()
            ->pluck('client_ecommerce_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        return $contexto;
    }

    /**
     * LA definición de "esta tienda no se puede actualizar ahora", en un solo lugar.
     *
     * La usan los tres caminos: `GET claude/ecommerce/stores` (para publicar `puede_actualizarse`),
     * el POST de a uno (para el 422) y el lote (para los `omitidos`). 🔴 Si cada uno tuviera su
     * propia versión, el listado diría "sí" y el POST contestaría 422 — la clase de error que este
     * repo tiene documentada como "dos definiciones de lo mismo se desincronizan".
     *
     * ⚠️ Réplica de las precondiciones del panel, no llamada a ellas: `assert_ecommerce_is_configured()`
     * y `assert_deploy_prerequisites()` de `EcommerceInstallationController` son `protected` y
     * devuelven un `JsonResponse` con otra forma de error. Se replica la REGLA (los mismos campos,
     * el mismo `resolve_domain()`, los mismos dos tipos de credencial), no el cuerpo de la respuesta.
     *
     * ⚠️ NO se replican los dos chequeos que el panel hace sólo para `mode = 'install'` (plantilla de
     * `.env` de tienda y API de empresa activa del cliente): el pipeline de `update` no reescribe el
     * `.env`, así que exigirlos acá rechazaría actualizaciones perfectamente válidas.
     *
     * 🔴 Y hay UNA divergencia deliberada en la otra dirección: "ya hay una corrida en curso" cuenta
     * también las `pendiente`, que el panel no cuenta. Es lo que evita que un reintento del POST
     * dentro del minuto —el HTTP dio timeout, la corrida todavía no la levantó el worker— cree una
     * segunda corrida sobre la misma tienda. Ver ESTADOS_QUE_OCUPAN_LA_TIENDA.
     *
     * ⚠️ El cooldown NO está acá a propósito: es del lote solamente. Ver `COOLDOWN_HORAS_ECOMMERCE`.
     *
     * @param ClientEcommerce      $tienda   Tienda a evaluar.
     * @param array<string, mixed> $contexto Salida de `contexto_de_precondiciones()`.
     *
     * @return string|null Motivo por el que NO se puede, o null si se puede.
     */
    private function motivo_por_el_que_no_se_puede_actualizar(ClientEcommerce $tienda, array $contexto)
    {
        /* Configuración mínima de la tienda. Se usa `resolve_domain()` y no la columna cruda
           `domain` por lo mismo que el panel: el dominio puede estar derivado del host de `spa_url`. */
        $faltantes = [];
        if (empty($tienda->spa_url)) {
            $faltantes[] = 'spa_url';
        }
        if (empty($tienda->api_url)) {
            $faltantes[] = 'api_url';
        }
        if ($tienda->resolve_domain() === '') {
            $faltantes[] = 'dominio';
        }

        if (! empty($faltantes)) {
            return 'falta configuración de la tienda (' . implode(' / ', $faltantes) . ')';
        }

        if (! $contexto['ssh_vps']) {
            return 'faltan las credenciales SSH del VPS de builds';
        }

        if (! $contexto['ssh_hosting']) {
            return 'faltan las credenciales SSH del hosting compartido';
        }

        if (in_array((int) $tienda->id, $contexto['corriendo'], true)) {
            return 'ya hay una corrida en curso para esta tienda';
        }

        return null;
    }

    /**
     * Huella determinista del lote simulado: ata la confirmación al conjunto exacto de tiendas.
     *
     * 🔴 Entra el id del cliente Y el hash de su nombre normalizado, no sólo el id. Ese es el punto
     * (§1.3 del plan): en un lote no hay UN nombre que confirmar, así que el equivalente de
     * `confirm_client_name` es un token que incorpora los nombres de todos. Si entre la simulación y
     * la confirmación cambia un cliente —o le renombran el negocio—, el token deja de coincidir.
     *
     * Determinista y sin estado: se recalcula del mismo input, no hace falta ninguna tabla. No es un
     * secreto ni pretende serlo: no defiende de alguien que quiere burlarlo (ya tiene la clave de la
     * API), defiende del error de armar la segunda llamada con una lista distinta de la que se revisó.
     *
     * ⚠️ Lo que NO tapa: una lista mal armada desde el principio. El token protege de que la lista
     * CAMBIE, no de que estuviera mal. Para eso hay que leer la simulación.
     *
     * @param array<int, array<string, mixed>> $candidatas Tiendas ya resueltas.
     *
     * @return string
     */
    private function calcular_confirm_token(array $candidatas)
    {
        $partes = [];
        foreach ($candidatas as $candidata) {
            $partes[] = (int) $candidata['client_id']
                . ':' . md5($this->normalizar_nombre($candidata['client_name']))
                . ':' . (int) $candidata['client_ecommerce_id'];
        }
        sort($partes);

        return substr(hash('sha256', 'ecommerce-updates-batch|' . implode('|', $partes)), 0, 32);
    }

    /* ==============================================================================================
     | Escritura y armado de respuestas
     |============================================================================================= */

    /**
     * Crea la fila de la corrida. 🔴 Siempre `mode = update` y siempre marcada como de Claude.
     *
     * @param ClientEcommerce $tienda Tienda a actualizar.
     *
     * @return ClientEcommerceInstallation
     */
    private function crear_corrida(ClientEcommerce $tienda)
    {
        return ClientEcommerceInstallation::create([
            'client_ecommerce_id' => $tienda->id,
            'mode'                => self::MODO_ACTUALIZACION,
            'status'              => self::ESTADO_INICIAL,
            'created_via'         => ClientEcommerceInstallation::CREATED_VIA_CLAUDE,
        ]);
    }

    /**
     * Cuerpo de la respuesta 202 del endpoint de a uno.
     *
     * 🔴 Declara la conexión usada y la latencia esperable, y dice explícitamente que el endpoint no
     * espera a que el pipeline termine. Mismo criterio que
     * `ClaudeUpgradeOpsController::respuesta_de_encolado()`.
     *
     * @param Client                      $client  Cliente dueño.
     * @param ClientEcommerce             $tienda  Tienda actualizada.
     * @param ClientEcommerceInstallation $corrida Corrida creada.
     *
     * @return array<string, mixed>
     */
    private function respuesta_de_encolado(Client $client, ClientEcommerce $tienda, ClientEcommerceInstallation $corrida)
    {
        $id = (int) $corrida->id;

        return [
            'encolado'                 => true,
            'installation_id'          => $id,
            'uuid'                     => (string) $corrida->uuid,
            'client_id'                => (int) $client->id,
            'client_name'              => $client->name,
            'client_ecommerce_id'      => (int) $tienda->id,
            'domain'                   => $tienda->resolve_domain(),
            'mode'                     => self::MODO_ACTUALIZACION,
            'status'                   => self::ESTADO_INICIAL,
            'created_via'              => ClientEcommerceInstallation::CREATED_VIA_CLAUDE,
            'conexion_de_cola'         => self::CONEXION_DE_COLA,
            'latencia_maxima_segundos' => self::LATENCIA_MAXIMA_SEGUNDOS,
            'endpoints'                => $this->endpoints_de($id),
            'nota_precondicion'        => $this->nota_de_precondicion(),
            'nota'                     => 'La actualización siempre trae la última de `master`: no se elige versión ni '
                . 'tag, igual que el botón del panel. Este endpoint NO espera a que el pipeline termine.',
        ];
    }

    /**
     * Precondición de infraestructura, declarada en toda respuesta que encola.
     *
     * @return string
     */
    private function nota_de_precondicion()
    {
        return 'El pipeline lo corre el worker `queue:work database --stop-when-empty` que el scheduler dispara cada '
            . 'minuto. Sin ese cron, esto no hace NADA visible: la corrida queda en `pendiente` y el job dormido en la '
            . 'tabla `jobs`. Mirá `salud.jobs_en_cola` en GET claude/ecommerce/installations/{id}.';
    }

    /**
     * Los dos endpoints con los que se sigue una corrida.
     *
     * @param int $id Id de la corrida.
     *
     * @return array<string, string>
     */
    private function endpoints_de($id)
    {
        return [
            'estado' => 'GET claude/ecommerce/installations/' . (int) $id,
            'logs'   => 'GET claude/ecommerce/installations/' . (int) $id . '/logs',
        ];
    }

    /* ==============================================================================================
     | Lectura: proyecciones y salud
     |============================================================================================= */

    /**
     * Resuelve una corrida por id numérico o por uuid.
     *
     * @param int|string $route_id Valor crudo del segmento de la ruta.
     *
     * @return ClientEcommerceInstallation|null
     */
    private function buscar_corrida($route_id)
    {
        $valor = trim((string) $route_id);

        if ($valor !== '' && ctype_digit($valor)) {
            $corrida = ClientEcommerceInstallation::find((int) $valor);
            if ($corrida !== null) {
                return $corrida;
            }
        }

        return ClientEcommerceInstallation::where('uuid', $valor)->first();
    }

    /**
     * Normaliza una corrida a la proyección de la respuesta.
     *
     * @param object $row      Fila (query builder o modelo).
     * @param bool   $recortar Si hay que recortar `failure_reason` (listados sí, ficha no).
     *
     * @return array<string, mixed>
     */
    private function proyectar_corrida($row, $recortar)
    {
        $fila = [
            'id'                  => (int) $row->id,
            'uuid'                => (string) $row->uuid,
            'client_ecommerce_id' => (int) $row->client_ecommerce_id,
            'client_id'           => isset($row->client_id) && $row->client_id !== null ? (int) $row->client_id : null,
            'client_name'         => isset($row->client_name) ? $row->client_name : null,
            'domain'              => isset($row->domain) ? $row->domain : null,
            'mode'                => $row->mode,
            'status'              => $row->status,
            'created_via'         => isset($row->created_via) ? $row->created_via : null,
            'started_at'          => $this->instante($row->started_at),
            'finished_at'         => $this->instante($row->finished_at),
            'created_at'          => $this->instante($row->created_at),
            'updated_at'          => $this->instante($row->updated_at),
        ];

        $motivo = $row->failure_reason === null ? null : (string) $row->failure_reason;

        if ($motivo !== null && $recortar && mb_strlen($motivo) > self::FAILURE_REASON_CHARS) {
            $fila['failure_reason']          = mb_substr($motivo, 0, self::FAILURE_REASON_CHARS);
            $fila['failure_reason_truncada'] = true;
        } else {
            $fila['failure_reason']          = $motivo;
            $fila['failure_reason_truncada'] = false;
        }

        /* La ficha viene sin cliente cargado (el modelo no trae el join): se completa desde la
           relación, que es UNA consulta porque es una sola corrida. */
        if ($fila['client_id'] === null && $row instanceof ClientEcommerceInstallation) {
            $tienda = $row->client_ecommerce;
            if ($tienda !== null) {
                $fila['client_id'] = $tienda->client_id === null ? null : (int) $tienda->client_id;
                $fila['domain']    = $tienda->resolve_domain();
                $fila['client_name'] = $tienda->client === null ? null : $tienda->client->name;
            }
        }

        return $fila;
    }

    /**
     * Señales de si el worker está avanzando sobre esta corrida, calculadas y NO persistidas.
     *
     * 🔴 REUSA `ClaudeClientOpsController::STALE_MINUTOS` en vez de inventar un número nuevo: la
     * noción de "hace rato que no reporta" ya existe en este bloque y tener dos umbrales con el
     * mismo significado es garantía de que uno de los dos quede viejo.
     *
     * 🔴 Y ACÁ ESTÁ LA VERDAD INCÓMODA, QUE VA ESCRITA EN LA RESPUESTA Y NO SÓLO EN UN DOCBLOCK: para
     * los deployments de empresa, `deployment_stale` es un aviso porque existe
     * `deployments:vencer-colgados` que efectivamente los destraba. Para las corridas de ecommerce
     * NO EXISTE ese proceso. Una corrida colgada en `instalando` bloquea a esa tienda para siempre
     * vía la precondición "ya hay una corrida en curso", y hoy se saca a mano. Esta misión lo mide y
     * lo declara; construir el vencedor —clonado del de deployments, anclado en `started_at` + la
     * última línea de `ecommerce_deployment_logs`, con el piso derivado del timeout del job— es otra
     * misión.
     *
     * @param ClientEcommerceInstallation $corrida Corrida a evaluar.
     *
     * @return array<string, mixed>
     */
    private function salud_de_la_corrida(ClientEcommerceInstallation $corrida)
    {
        $stale_minutos = ClaudeClientOpsController::STALE_MINUTOS;
        $limite        = Carbon::now()->subMinutes($stale_minutos);

        $ultimo_log = DB::table('ecommerce_deployment_logs')
            ->where('client_ecommerce_installation_id', (int) $corrida->id)
            ->orderByDesc('id')
            ->first(['created_at']);

        $ultimo_log_at = $ultimo_log === null ? null : $ultimo_log->created_at;
        $ultimo        = $this->parsear_o_null($ultimo_log_at);

        /* El ancla es `started_at`, y si el worker todavía no la estampó, `created_at`: una corrida
           que quedó en `pendiente` porque el scheduler no corre también está colgada, y sin este
           fallback no se mediría. */
        $arranco = $this->parsear_o_null($corrida->started_at);
        if ($arranco === null) {
            $arranco = $this->parsear_o_null($corrida->created_at);
        }

        $en_curso = in_array($corrida->status, [self::ESTADO_INICIAL, self::ESTADO_EN_CURSO], true);

        $stale = false;
        if ($en_curso) {
            $sin_logs_recientes = $ultimo === null || $ultimo->lessThan($limite);
            $arranco_hace_rato  = $arranco !== null && $arranco->lessThan($limite);
            $stale              = $sin_logs_recientes && $arranco_hace_rato;
        }

        /* El timeout del job se LEE del job y no se repite acá: dos definiciones del mismo número se
           desincronizan, y este es justo el número del que saldría el piso de un vencedor futuro. */
        $timeout_job = (new RunEcommerceInstallationJob(''))->timeout;

        return [
            'status'                    => $corrida->status,
            'corrida_stale'             => $stale,
            'stale_minutos'             => $stale_minutos,
            'minutos_en_curso'          => ($en_curso && $arranco !== null) ? Carbon::now()->diffInMinutes($arranco) : null,
            'ultimo_log_at'             => $ultimo_log_at,
            'segundos_desde_ultimo_log' => $ultimo === null ? null : Carbon::now()->diffInSeconds($ultimo),
            'total_logs'                => (int) DB::table('ecommerce_deployment_logs')
                ->where('client_ecommerce_installation_id', (int) $corrida->id)
                ->count(),
            'jobs_en_cola'              => (int) DB::table('jobs')->count(),
            'timeout_del_job_minutos'   => (int) ceil($timeout_job / 60),
            'nota'                      => '🔴 `corrida_stale` REPORTA, no arregla. Y a diferencia de los deployments de '
                . 'empresa, acá NO hay ningún proceso que destrabe: no existe el equivalente de '
                . '`deployments:vencer-colgados` para client_ecommerce_installations. Una corrida colgada en '
                . '`instalando` bloquea a esa tienda para siempre (la precondición "ya hay una corrida en curso" la '
                . 'frena) y hoy se saca A MANO, cambiándole el status en la base. Pasados '
                . (int) ceil($timeout_job / 60) . ' minutos (el timeout del job) la corrida está muerta con certeza.',
        ];
    }

    /**
     * Última corrida de cada tienda, en dos consultas para toda la página.
     *
     * @param array<int, int> $ecommerce_ids Ids de las tiendas.
     *
     * @return array<int, array<string, mixed>> Indexado por client_ecommerce_id.
     */
    private function ultima_corrida_por_tienda(array $ecommerce_ids)
    {
        if (empty($ecommerce_ids)) {
            return [];
        }

        $ultimos_ids = [];
        $maximos     = DB::table('client_ecommerce_installations')
            ->whereIn('client_ecommerce_id', $ecommerce_ids)
            ->groupBy('client_ecommerce_id')
            ->select(DB::raw('MAX(id) as id'))
            ->get();
        foreach ($maximos as $maximo) {
            $ultimos_ids[] = (int) $maximo->id;
        }

        if (empty($ultimos_ids)) {
            return [];
        }

        $filas = DB::table('client_ecommerce_installations')
            ->whereIn('id', $ultimos_ids)
            ->select(['id', 'uuid', 'client_ecommerce_id', 'mode', 'status', 'created_via', 'started_at', 'finished_at', 'created_at'])
            ->get();

        $por_tienda = [];
        foreach ($filas as $fila) {
            $por_tienda[(int) $fila->client_ecommerce_id] = [
                'id'          => (int) $fila->id,
                'uuid'        => (string) $fila->uuid,
                'mode'        => $fila->mode,
                'status'      => $fila->status,
                'created_via' => $fila->created_via,
                'started_at'  => $this->instante($fila->started_at),
                'finished_at' => $this->instante($fila->finished_at),
                'created_at'  => $this->instante($fila->created_at),
            ];
        }

        return $por_tienda;
    }

    /**
     * Normaliza un instante a string, venga como Carbon (modelo) o como string (query builder).
     *
     * @param mixed $valor Valor crudo.
     *
     * @return string|null
     */
    private function instante($valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor->toDateTimeString();
        }

        return (string) $valor;
    }
}

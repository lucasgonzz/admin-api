<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Http\Controllers\UpdateController;
use App\Models\Client;
use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Services\ClientVersionUpgradeCreationService;
use App\Services\VersionPathService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `POST claude/upgrades/batch` — alta de actualizaciones EN LOTE para varios clientes a la vez.
 *
 * 🔴 ESTE ENDPOINT SÓLO CREA ACTUALIZACIONES. NUNCA ARRANCA UN DEPLOYMENT, Y ESO NO ES PRUDENCIA
 * GENÉRICA: ES QUE NO SE PUEDE.
 *
 * Crear un `ClientVersionUpgrade` escribe filas en la base del admin. Arrancar un deployment abre
 * SSH contra el servidor de un negocio. Son dos operaciones de riesgo distinto, y la segunda no
 * admite lote por una razón concreta: **los dos frenos que la gobiernan son por cliente**.
 *
 *  - El gate de horario del post-cierre pregunta si la jornada de HOY de ESE negocio ya terminó.
 *    Veinte clientes tienen veinte jornadas distintas: un "arrancá el post-cierre de los veinte" o
 *    rechaza a diecinueve, o —peor— los saltea a todos con un `force` global que dejaría de ser un
 *    freno.
 *  - `allow_deploy_to_active_api` se evalúa contra la `ClientApi` activa de *cada* cliente, y el
 *    `start` además BORRA los logs del intento anterior de *cada* upgrade. Un flag global ahí
 *    significaría "desplegá sobre producción de todos los que tengan una sola API", que es
 *    exactamente lo que ese freno existe para impedir.
 *
 * **Consecuencia práctica, y va escrita en la respuesta:** después del lote hay que llamar
 * `deploy/start` una vez por cliente. La respuesta devuelve la lista exacta de endpoints a llamar.
 *
 * 🔴 `confirm_client_name` NO SE PUEDE ESPEJAR EN UN LOTE, Y SU EQUIVALENTE ES `confirm_token`. En
 * el alta de a uno el freno es el nombre del cliente; en un lote de veinte no hay *un* nombre. El
 * token incorpora, por cliente, el id **y el nombre normalizado** **y el conjunto de versiones que
 * le tocó**: así, un lote que cambió de clientes —o de versiones por cliente— entre la simulación y
 * la confirmación no puede pasar, que es exactamente de lo que `confirm_client_name` protege de a
 * uno. ⚠️ Lo que el token NO tapa es una lista mal armada desde el principio: para eso hay que leer
 * la simulación.
 *
 * ⚠️ Asimetría deliberada con `POST claude/ecommerce/updates/batch`, que NO acepta filtros: éste sí
 * acepta el selector `from_version_id` / `from_version` porque no toca ningún servidor, sólo escribe
 * filas que después alguien tiene que arrancar de a una. Aquél arranca pipelines SSH, y ahí un
 * filtro mal escrito se convierte en corridas reales sobre negocios que nadie eligió.
 */
class ClaudeUpgradeBatchController extends Controller
{
    /**
     * Los helpers genéricos del bloque `claude/*`: normalización de parámetros y las respuestas
     * 422/404 con la forma única del bloque. 🔴 Salen del trait, no se copian.
     */
    use RespuestasParaClaude;

    /**
     * Tope duro de clientes por llamada.
     *
     * 🔴 25 y no los 50 de `ClaudeLeadsOutboundController::MAX_BATCH`, y la diferencia es el costo
     * de cada ítem: un envío de plantilla es un HTTP; un alta de upgrade es
     * `candidatesBetween()` + `withSeedersAndCommands()` + N inserts de `UpdateSeeder` y
     * `UpdateCommand` + `SharedDatabaseAutoSkipService` recorriendo los clientes hermanos de la
     * misma base. Y el blast radius también es otro: 25 upgrades mal creados son 25 clientes con una
     * actualización pendiente que alguien tiene que borrar a mano.
     */
    const MAX_LOTE_CLIENTES = 25;

    /**
     * Presupuesto de tiempo del loop de creación, en segundos. Mismo número y mismo motivo que
     * `ClaudeLeadsOutboundController::PRESUPUESTO_SEGUNDOS`: preferimos una respuesta honesta e
     * incompleta antes que un request colgado que muere sin contarle a nadie dónde quedó.
     */
    const PRESUPUESTO_SEGUNDOS = 50;

    /**
     * Reserva que se le guarda al PRÓXIMO cliente antes de arrancarlo, en segundos.
     *
     * 🔴 Sin reserva el presupuesto no acota nada: mirar sólo el tiempo transcurrido deja que un
     * alta que arranca en el segundo 49 corra igual sus segundos y se pase. Cinco y no los 35 del
     * lote de leads porque acá no hay ningún HTTP de por medio: el peor caso de un alta es un puñado
     * de inserts contra la base local.
     */
    const RESERVA_POR_CLIENTE_SEGUNDOS = 5;

    /**
     * Horas de enfriamiento por (cliente, versión destino). Espeja `COOLDOWN_HORAS` del lote de
     * leads: lo que evita es el doble disparo del mismo lote, no que alguien decida crear otro
     * upgrade a propósito (para eso está el alta de a uno, que no tiene cooldown).
     */
    const COOLDOWN_HORAS = 24;

    /** Política que replica la sugerencia del panel: troncal sí, hotfix no, destino siempre. */
    const POLITICA_SUGERIDAS = 'sugeridas_del_panel';

    /** Política que confirma TODAS las candidatas del rango, hotfix incluidos. */
    const POLITICA_TODAS = 'todas_las_candidatas';

    /** Las dos políticas aceptadas, para la validación y para el mensaje de error. */
    const POLITICAS = [self::POLITICA_SUGERIDAS, self::POLITICA_TODAS];

    /**
     * Los dos estados TERMINALES de `client_version_upgrades.status`.
     *
     * 🔴 Se nombran éstos y no los abiertos porque los abiertos se derivan restándolos de
     * `UpdateController::STATUS_LABELS`, que es la enumeración real de la columna (`pendiente`,
     * `listo_para_actualizar`, `actualizandose`, `terminada`, `fallida`). Escribir la lista de
     * abiertos a mano dejaría dos definiciones de "qué estados existen", y el día que se agregue uno
     * nuevo la que quedaría vieja sería ésta: un estado nuevo se leería como "no abierto" y el lote
     * crearía un segundo upgrade encima de uno que sigue vivo.
     *
     * ⚠️ El plan de esta misión decía `status = 'pending'`. Esa cadena NO existe en el proyecto: los
     * valores reales están arriba y son en español.
     */
    const ESTADOS_TERMINALES = ['terminada', 'fallida'];

    /**
     * Estados de deployment con los que NO se crea otro upgrade para ese cliente. Misma lista que
     * `ClaudeUpgradeOpsController::ACTIVE_DEPLOYMENT_STATUSES` y que
     * `DeploymentController::$active_deployment_statuses`.
     */
    const ACTIVE_DEPLOYMENT_STATUSES = ClaudeUpgradeOpsController::ACTIVE_DEPLOYMENT_STATUSES;

    /* ==============================================================================================
     | POST claude/upgrades/batch
     |============================================================================================= */

    /**
     * Crea una actualización a la misma versión destino para un conjunto de clientes.
     *
     * Orden de los frenos, todos ANTES de crear la primera fila:
     *   1. Selección: `client_ids[]` explícitos **o** el selector por versión actual, nunca los dos
     *      y nunca ninguno.
     *   2. Versión destino publicada.
     *   3. Tope duro de `MAX_LOTE_CLIENTES`.
     *   4. Resolución de candidatos: todo lo que no califica sale como `omitidos[]`, con el motivo.
     *   5. `dry_run` (default true) → devuelve qué se crearía, por cliente, y el `confirm_token`.
     *   6. `confirm_client_count` exacto.
     *   7. `confirm_token` con `hash_equals`.
     *
     * 🔴 Cómo se resuelve que `confirm_version_count` cambie por cliente: **no se resuelve pidiéndolo
     * por cliente**. Se saca del contrato y se reemplaza por la política + el token. La simulación
     * devuelve, por cliente, `versiones_confirmadas` con ids, números y `cantidad` —o sea, la misma
     * información que `confirm_version_count` obliga a mirar en el alta de a uno—, y el token
     * incorpora ese conjunto: confirmar el token es confirmar los N conjuntos, cada uno con su
     * cantidad, sin tener que tipear veinte números.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_batch_json(Request $request)
    {
        $invalido = $this->validar_o_422($request, [
            'to_version_id'          => 'required|exists:versions,id',
            'client_ids'             => 'nullable|array|min:1',
            'client_ids.*'           => 'required|integer|min:1',
            'from_version_id'        => 'nullable|integer|min:1',
            'from_version'           => 'nullable|string|max:30',
            'politica_de_versiones'  => 'nullable|string|in:' . implode(',', self::POLITICAS),
            'include_inactivos'      => 'nullable|boolean',
            'dry_run'                => 'nullable|boolean',
            'confirm_client_count'   => 'nullable|integer|min:0',
            'confirm_token'          => 'nullable|string|max:64',
            'notes'                  => 'nullable|string',
            'scheduled_date'         => 'nullable|date_format:Y-m-d',
        ]);
        if ($invalido !== null) {
            return $invalido;
        }

        $to = Version::find((int) $request->input('to_version_id'));

        /* ⚠️ Réplica deliberada de `ClaudeUpgradeOpsController::rechazar_si_no_esta_publicada()`,
           que es `private`. Mismo texto y misma regla que `UpdateController`: una versión sin
           publicar no se le instala a nadie, y menos a veinticinco de una. */
        if ($to === null || $to->status !== 'published') {
            return $this->error_422('La versión destino tiene que estar publicada.', [
                'status_actual' => $to === null ? null : $to->status,
                'ayuda'         => 'Consultá GET claude/versions para ver el catálogo de lo que se puede pedir.',
            ]);
        }

        /* --- Freno 1: la selección. --- */
        $seleccion = $this->resolver_seleccion($request);
        if ($seleccion instanceof \Illuminate\Http\JsonResponse) {
            return $seleccion;
        }

        /* --- Freno 3: tope duro, antes de tocar nada. --- */
        if (count($seleccion['client_ids']) > self::MAX_LOTE_CLIENTES) {
            return $this->error_422(
                'El lote no puede superar los ' . self::MAX_LOTE_CLIENTES . ' clientes por llamada y quedaron '
                    . count($seleccion['client_ids']) . '. No se creó nada: partilo en tandas.',
                [
                    'max_lote'  => self::MAX_LOTE_CLIENTES,
                    'recibidos' => count($seleccion['client_ids']),
                    'origen'    => $seleccion['origen'],
                    'ayuda'     => $seleccion['origen'] === 'selector'
                        ? 'El selector por versión resolvió más clientes que el tope. Mirálos con '
                            . 'GET claude/clients?current_version_id=… y mandá client_ids[] en tandas.'
                        : 'Mandá menos ids por llamada.',
                ]
            );
        }

        /* --- Freno 4: candidatos y omisiones. --- */
        $politica  = $this->texto_con_default($request, 'politica_de_versiones', self::POLITICA_SUGERIDAS);
        $resuelto  = $this->resolver_candidatos($seleccion['client_ids'], $to, $politica, $request);
        $candidatos = $resuelto['candidatos'];
        $omitidos   = $resuelto['omitidos'];

        $crearian      = count($candidatos);
        $confirm_token = $this->calcular_confirm_token($candidatos, (int) $to->id);

        /* --- Freno 5: simulación. Es el default y no escribe absolutamente nada. --- */
        $dry_run = $request->filled('dry_run') ? $request->boolean('dry_run') : true;
        if ($dry_run) {
            return response()->json([
                'dry_run'                => true,
                'to_version'             => ['id' => (int) $to->id, 'version' => $to->version, 'title' => $to->title],
                'politica_de_versiones'  => $politica,
                'crearian'               => $crearian,
                'omitidos'               => $omitidos,
                'clientes'               => $this->clientes_para_ver($candidatos),
                'confirm_token'          => $confirm_token,
                'max_lote'               => self::MAX_LOTE_CLIENTES,
                'advertencias'           => $this->advertencias($candidatos),
                'nota'                   => 'No se creó NADA. REVISÁ la lista de clientes y las versiones que le tocan a '
                    . 'cada uno antes de seguir. Para crear de verdad, repetí la misma llamada con dry_run=false, '
                    . 'confirm_client_count=' . $crearian . ' y confirm_token=' . $confirm_token . '.',
                'nota_deployment'        => '🔴 Este endpoint NO arranca ningún deployment: sólo crea las '
                    . 'actualizaciones. El gate de horario y allow_deploy_to_active_api son POR CLIENTE, así que después '
                    . 'hay que llamar POST claude/upgrades/{id}/deploy/start uno por uno. La respuesta real devuelve la '
                    . 'lista exacta de endpoints, en orden.',
            ], 200);
        }

        /* --- Freno 6: confirmación del número exacto. --- */
        if (! $request->filled('confirm_client_count')) {
            return $this->error_422(
                'confirm_client_count es obligatorio cuando dry_run es false. No se creó nada.',
                ['crearian' => $crearian, 'omitidos' => $omitidos]
            );
        }

        $confirm_client_count = (int) $request->input('confirm_client_count');
        if ($confirm_client_count !== $crearian) {
            return $this->error_422(
                'confirm_client_count no coincide con la cantidad real de clientes a los que se les crearía la '
                    . 'actualización (' . $crearian . '). No se creó nada: revisá los omitidos y volvé con el número real.',
                [
                    'confirm_client_count_recibido' => $confirm_client_count,
                    'crearian'                      => $crearian,
                    'omitidos'                      => $omitidos,
                ]
            );
        }

        /*
         * --- Freno 7: el token ata la confirmación al CONJUNTO, no sólo a la cantidad. ---
         *
         * 🔴 Es el equivalente de `confirm_client_name` en un lote (ver el docblock de la clase).
         * Sin esto, `confirm_client_count` se satisface con cualquier lote del mismo tamaño: simular
         * con los clientes A y B y después crear sobre C y D pasaba sin una sola advertencia. Y el
         * token incluye el conjunto de versiones de cada uno, así que tampoco pasa un lote donde los
         * clientes son los mismos pero a alguno le cambió el camino de versiones entre medio.
         */
        $token_recibido = $this->texto_o_null($request->input('confirm_token'));
        if ($token_recibido === null) {
            return $this->error_422(
                'confirm_token es obligatorio cuando dry_run es false. Corré primero la simulación, revisá los clientes '
                    . 'y las versiones de cada uno, y volvé con el token que te devolvió. No se creó nada.',
                ['crearian' => $crearian, 'confirm_token' => $confirm_token]
            );
        }

        if (! hash_equals($confirm_token, $token_recibido)) {
            return $this->error_422(
                'confirm_token no corresponde a este conjunto: la lista de clientes, el nombre de alguno o las versiones '
                    . 'que le tocan cambiaron respecto de la simulación que generó ese token. No se creó nada. Volvé a '
                    . 'simular y usá el token nuevo.',
                ['crearian' => $crearian, 'confirm_token_esperado' => $confirm_token]
            );
        }

        return $this->crear_en_lote($request, $to, $candidatos, $omitidos, $politica);
    }

    /* ==============================================================================================
     | La creación real
     |============================================================================================= */

    /**
     * Crea los upgrades, uno por uno, cada uno en su propia transacción y su propio try/catch.
     *
     * 🔴 Una transacción POR CLIENTE y no una sola para el lote entero, al revés que el lote de
     * ecommerce: allá las N filas son el mismo trámite y una a medias deja jobs huérfanos; acá cada
     * upgrade es independiente y ya sirve solo. Si el cliente 14 explota, los 13 anteriores son
     * actualizaciones perfectamente válidas que alguien puede arrancar, y tirarlas abajo sería
     * castigar a doce clientes por un problema del decimotercero. Lo que sí es atómico es cada alta:
     * un upgrade con la mitad de sus `UpdateSeeder` no se puede arrancar ni borrar sin mirar.
     *
     * 🔴 Y no se encola NADA. Ver el docblock de la clase.
     *
     * @param Request                          $request    Request entrante (notes, scheduled_date).
     * @param Version                          $to         Versión destino.
     * @param array<int, array<string, mixed>> $candidatos Clientes ya resueltos.
     * @param array<int, array<string, mixed>> $omitidos   Omitidos, para devolverlos igual.
     * @param string                           $politica   Política aplicada.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function crear_en_lote(Request $request, Version $to, array $candidatos, array $omitidos, $politica)
    {
        $service = app(ClientVersionUpgradeCreationService::class);

        $opciones_base = [
            'notes'          => $request->input('notes'),
            'scheduled_date' => $request->input('scheduled_date'),
            /* 🔴 `created_by_admin_id` queda en null (no hay sesión), que es honesto: no lo creó
               ningún admin. `created_via` dice quién sí, y es lo que mira el cooldown. */
            'created_via'    => ClientVersionUpgrade::CREATED_VIA_CLAUDE,
        ];

        $resultados     = [];
        $fallidos       = [];
        $no_procesados  = [];
        $creados        = 0;
        $abortado       = false;
        $motivo_corte   = null;
        $arranque       = microtime(true);
        $indice         = 0;

        foreach ($candidatos as $candidato) {
            /* Presupuesto con reserva: no se arranca un alta que no entra completa. Cortamos limpio
               y devolvemos los `no_procesados`, que se pueden reintentar sin duplicar nada porque el
               cooldown de 24 hs ya cubre a los que sí se crearon. */
            $transcurrido = microtime(true) - $arranque;
            if ($indice > 0 && ($transcurrido + self::RESERVA_POR_CLIENTE_SEGUNDOS) >= self::PRESUPUESTO_SEGUNDOS) {
                $abortado     = true;
                $motivo_corte = 'se agotó el presupuesto de ' . self::PRESUPUESTO_SEGUNDOS . ' s del request ('
                    . round($transcurrido, 1) . ' s usados, y no entra otra alta sin pasarse); a los clientes no '
                    . 'procesados NO se les creó nada y se pueden reintentar en otra llamada';
            }

            if ($abortado) {
                $no_procesados[] = $candidato['client_id'];
                $indice++;
                continue;
            }

            try {
                $cliente = $candidato['cliente'];
                $ids     = $candidato['version_ids'];

                $upgrade = DB::transaction(function () use ($service, $cliente, $to, $ids, $opciones_base) {
                    return $service->create($cliente, $to, $ids, $opciones_base);
                });

                $creados++;
                $id_upgrade   = (int) $upgrade->id;
                $resultados[] = [
                    'client_id'             => $candidato['client_id'],
                    'client_name'           => $candidato['client_name'],
                    'upgrade_id'            => $id_upgrade,
                    'upgrade_uuid'          => (string) $upgrade->uuid,
                    'cantidad_de_versiones' => count($ids),
                    'siguiente_accion'      => 'POST claude/upgrades/' . $id_upgrade . '/deploy/start',
                ];
            } catch (\Throwable $e) {
                /* Un cliente que explota no se lleva puesto el lote. El texto de la excepción va al
                   log y NO a la respuesta: una excepción de PDO trae el INSERT completo con sus
                   valores atados. */
                Log::channel('daily')->error('ClaudeUpgradeBatchController: excepción creando el upgrade de un cliente.', [
                    'client_id'     => $candidato['client_id'],
                    'to_version_id' => (int) $to->id,
                    'error'         => $e->getMessage(),
                ]);

                $fallidos[] = [
                    'client_id' => $candidato['client_id'],
                    'motivo'    => 'la creación falló y se revirtió entera; el motivo quedó en el log del sistema',
                ];
            }

            $indice++;
        }

        return response()->json([
            'dry_run'               => false,
            'to_version'            => ['id' => (int) $to->id, 'version' => $to->version, 'title' => $to->title],
            'politica_de_versiones' => $politica,
            'creados'               => $creados,
            'fallidos'              => $fallidos,
            'omitidos'              => $omitidos,
            'no_procesados'         => $no_procesados,
            'abortado'              => $abortado,
            'motivo_corte'          => $motivo_corte,
            'created_via'           => ClientVersionUpgrade::CREATED_VIA_CLAUDE,
            'resultados'            => $resultados,
            /* Las mismas advertencias que la simulación: el que confirmó también las necesita
               DESPUÉS, porque son sobre lo que va a pasar recién en el deploy/start de cada uno. */
            'advertencias'          => $this->advertencias($candidatos),
            'nota_deployment'       => '🔴 NO se arrancó ningún deployment y NO se encoló ningún job: este endpoint sólo '
                . 'creó las actualizaciones. El gate de horario y allow_deploy_to_active_api son POR CLIENTE, así que el '
                . 'arranque va uno por uno con el `siguiente_accion` de cada resultado, mirando el horario de ese '
                . 'negocio en GET claude/clients/{id}/schedule.',
        ], 201);
    }

    /* ==============================================================================================
     | Selección y candidatos
     |============================================================================================= */

    /**
     * Freno 1: resuelve el conjunto de clientes a evaluar, desde `client_ids[]` o desde el selector.
     *
     * 🔴 Los dos a la vez son 422, y ninguno también. No es puritanismo: con los dos aceptados
     * habría que decidir en silencio si el selector suma o filtra, y quien escribió la llamada
     * creería la otra. Un lote de veinticinco clientes no puede depender de adivinar eso.
     *
     * @param Request $request Request entrante.
     *
     * @return array{client_ids: array<int, int>, origen: string}|\Illuminate\Http\JsonResponse
     */
    private function resolver_seleccion(Request $request)
    {
        $ids_pedidos = $this->normalizar_lista_enteros($request->input('client_ids'));
        $por_id      = $request->filled('from_version_id');
        $por_numero  = $this->texto_o_null($request->input('from_version')) !== null;
        $hay_selector = $por_id || $por_numero;

        if (! empty($ids_pedidos) && $hay_selector) {
            return $this->error_422(
                'Mandaste client_ids[] Y el selector por versión actual (from_version_id / from_version) a la vez. Es '
                    . 'uno o el otro: no se creó nada.',
                [
                    'ayuda' => 'Con client_ids[] elegís vos la lista. Con el selector la arma el endpoint a partir de la '
                        . 'versión actual de cada cliente. Aceptar los dos obligaría a decidir en silencio si el selector '
                        . 'suma o filtra, y quien escribió la llamada creería lo otro.',
                ]
            );
        }

        if (! empty($ids_pedidos)) {
            return ['client_ids' => $ids_pedidos, 'origen' => 'client_ids'];
        }

        if (! $hay_selector) {
            return $this->error_422(
                'No mandaste ni client_ids[] ni el selector por versión actual (from_version_id / from_version). No hay '
                    . 'a quién crearle nada.',
                [
                    'ayuda' => 'Elegí los clientes con GET claude/clients (podés filtrar por current_version_id) y '
                        . 'mandalos en client_ids[], o usá from_version_id / from_version para que los arme el selector.',
                ]
            );
        }

        $from = $por_id
            ? Version::find((int) $request->input('from_version_id'))
            : Version::where('version', $this->texto_o_null($request->input('from_version')))->first();

        if ($from === null) {
            return $this->error_422('La versión del selector (from_version_id / from_version) no existe. No se creó nada.', [
                'ayuda' => 'Consultá GET claude/versions para ver el catálogo.',
            ]);
        }

        /* El selector arma la lista mirando la versión ACTUAL de cada cliente. Los inactivos entran
           igual y se omiten después, con su motivo escrito: sacarlos acá los volvería invisibles, y
           "no apareció" es peor que "apareció omitido porque está inactivo". */
        $ids = Client::query()
            ->where('current_version_id', (int) $from->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return [
            'client_ids' => array_map('intval', $ids),
            'origen'     => 'selector',
        ];
    }

    /**
     * Freno 4: separa candidatos de omitidos, con el motivo de cada omisión.
     *
     * 🔴 Todas las precondiciones se resuelven con consultas AGREGADAS —una por precondición para
     * todo el lote— y no una por cliente adentro del loop. Con veinticinco clientes, un N+1 acá son
     * cien consultas para decidir algo que se puede saber en cuatro.
     *
     * @param array<int, int> $client_ids Ids a evaluar.
     * @param Version         $to         Versión destino.
     * @param string          $politica   Política de versiones.
     * @param Request         $request    Request entrante (include_inactivos).
     *
     * @return array{candidatos: array<int, array<string, mixed>>, omitidos: array<int, array<string, mixed>>}
     */
    private function resolver_candidatos(array $client_ids, Version $to, $politica, Request $request)
    {
        $include_inactivos = $request->filled('include_inactivos') && $request->boolean('include_inactivos');

        $clientes = Client::query()
            ->whereIn('id', $client_ids)
            ->with('current_version', 'client_apis')
            ->get()
            ->keyBy('id');

        $contexto = $this->contexto_de_precondiciones($client_ids, (int) $to->id);
        $service  = app(ClientVersionUpgradeCreationService::class);

        /* Memo del rango por versión de origen: `candidatesBetween()` lee TODAS las versiones
           publicadas en cada llamada, y veinticinco clientes que vienen de la misma versión tienen
           el mismo rango. Se calcula una vez por versión de origen distinta, no una por cliente. */
        $rangos = [];

        $candidatos = [];
        $omitidos   = [];

        foreach ($client_ids as $id) {
            if (! isset($clientes[$id])) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente no existe'];
                continue;
            }

            $cliente = $clientes[$id];

            if (! (bool) $cliente->is_active && ! $include_inactivos) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente está inactivo'];
                continue;
            }

            /* 🔴 Sin nombre no hay con qué confirmar: el `confirm_token` incorpora el nombre
               normalizado de cada cliente, igual que `confirm_client_name` en el alta de a uno, que
               se niega en redondo a operar sobre un cliente sin nombre. Si el lote lo aceptara sería
               la puerta de al lado de ese freno. */
            if (trim((string) $cliente->name) === '') {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente no tiene nombre cargado'];
                continue;
            }

            $clave_del_rango = $cliente->current_version_id === null ? 'sin_version' : (int) $cliente->current_version_id;
            if (! array_key_exists($clave_del_rango, $rangos)) {
                $rangos[$clave_del_rango] = VersionPathService::candidatesBetween($cliente->current_version, $to);
            }

            $candidatas = $rangos[$clave_del_rango];

            if ($candidatas->isEmpty()) {
                $omitidos[] = [
                    'client_id' => $id,
                    'motivo'    => 'el cliente ya está en la versión destino o más adelante',
                ];
                continue;
            }

            if (in_array($id, $contexto['con_deployment_en_curso'], true)) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente tiene un deployment en curso'];
                continue;
            }

            if (in_array($id, $contexto['con_upgrade_abierto'], true)) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente ya tiene un upgrade abierto a esta versión'];
                continue;
            }

            if (in_array($id, $contexto['en_cooldown'], true)) {
                $omitidos[] = [
                    'client_id' => $id,
                    'motivo'    => 'ya se le creó un upgrade a esta versión en las últimas '
                        . self::COOLDOWN_HORAS . ' hs',
                ];
                continue;
            }

            /* Sin ninguna ClientApi el upgrade nacería sin API destino y `deploy/start` lo rechazaría
               para siempre: crear una fila que no se puede arrancar es peor que omitirla con motivo. */
            if ($cliente->client_apis->isEmpty()) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'el cliente no tiene ninguna ClientApi'];
                continue;
            }

            $version_ids = $this->versiones_segun_politica($candidatas, $to, $politica);

            /*
             * 🔴 El `abort(422)` de `resolve_confirmed_version_ids()` se envuelve: un cliente raro
             * —uno cuyo rango cambió entre que se calculó y que se validó, por ejemplo— sale como
             * omitido y NO voltea el lote entero. Un 422 global acá dejaría a los otros veinticuatro
             * sin actualización por culpa de uno.
             */
            try {
                $confirmados = $service->resolve_confirmed_version_ids($cliente, $to, $version_ids);
            } catch (\Throwable $e) {
                $omitidos[] = ['client_id' => $id, 'motivo' => 'pidió versiones fuera del rango'];
                continue;
            }

            $target_id = $service->resolve_default_target_client_api_id($cliente);

            $candidatos[] = [
                'client_id'            => $id,
                'client_name'          => $cliente->name,
                'from_version'         => $cliente->current_version === null ? null : $cliente->current_version->version,
                'version_ids'          => $confirmados,
                'versiones'            => $this->detalle_de_versiones($candidatas, $confirmados),
                'target_client_api_id' => $target_id,
                'target_es_la_api_activa' => $target_id !== null
                    && $cliente->active_client_api_id !== null
                    && $target_id === (int) $cliente->active_client_api_id,
                'cliente'              => $cliente,
            ];
        }

        return ['candidatos' => $candidatos, 'omitidos' => $omitidos];
    }

    /**
     * Las tres precondiciones que dependen de otras filas, resueltas en tres consultas para todo el
     * lote.
     *
     * @param array<int, int> $client_ids    Ids del lote.
     * @param int             $to_version_id Versión destino.
     *
     * @return array{con_deployment_en_curso: array<int, int>, con_upgrade_abierto: array<int, int>,
     *               en_cooldown: array<int, int>}
     */
    private function contexto_de_precondiciones(array $client_ids, $to_version_id)
    {
        if (empty($client_ids)) {
            return ['con_deployment_en_curso' => [], 'con_upgrade_abierto' => [], 'en_cooldown' => []];
        }

        $abiertos = array_values(array_diff(array_keys(UpdateController::STATUS_LABELS), self::ESTADOS_TERMINALES));

        $con_deployment = DB::table('client_version_upgrades')
            ->whereIn('client_id', $client_ids)
            ->whereIn('deployment_status', self::ACTIVE_DEPLOYMENT_STATUSES)
            ->distinct()
            ->pluck('client_id')
            ->all();

        $con_upgrade_abierto = DB::table('client_version_upgrades')
            ->whereIn('client_id', $client_ids)
            ->where('to_version_id', $to_version_id)
            ->whereIn('status', $abiertos)
            ->distinct()
            ->pluck('client_id')
            ->all();

        $en_cooldown = DB::table('client_version_upgrades')
            ->whereIn('client_id', $client_ids)
            ->where('to_version_id', $to_version_id)
            ->where('created_via', ClientVersionUpgrade::CREATED_VIA_CLAUDE)
            ->where('created_at', '>=', now()->subHours(self::COOLDOWN_HORAS))
            ->distinct()
            ->pluck('client_id')
            ->all();

        return [
            'con_deployment_en_curso' => array_map('intval', $con_deployment),
            'con_upgrade_abierto'     => array_map('intval', $con_upgrade_abierto),
            'en_cooldown'             => array_map('intval', $en_cooldown),
        ];
    }

    /**
     * Aplica la política de versiones al rango de un cliente.
     *
     * 🔴 `sugeridas_del_panel` NO tiene su propia copia de la regla: llama a
     * `ClientVersionUpgradeCreationService::es_sugerida_por_defecto()`, que es la MISMA que
     * `POST claude/upgrades/preview` publica como `default_checked`. Con dos copias, el lote crearía
     * upgrades con un conjunto distinto del que el preview muestra tildado y nadie se enteraría
     * hasta que un cliente quede con un hotfix de más o de menos.
     *
     * @param \Illuminate\Support\Collection $candidatas Rango del cliente.
     * @param Version                        $to         Versión destino.
     * @param string                         $politica   Política pedida.
     *
     * @return array<int, int>
     */
    private function versiones_segun_politica($candidatas, Version $to, $politica)
    {
        $ids = [];

        foreach ($candidatas as $candidata) {
            if ($politica === self::POLITICA_TODAS
                || ClientVersionUpgradeCreationService::es_sugerida_por_defecto($candidata, $to)) {
                $ids[] = (int) $candidata->id;
            }
        }

        return $ids;
    }

    /**
     * Detalle legible de las versiones que se le confirmarían a un cliente.
     *
     * @param \Illuminate\Support\Collection $candidatas  Rango completo del cliente.
     * @param array<int, int>                $confirmados Ids que quedaron confirmados.
     *
     * @return array<int, array<string, mixed>>
     */
    private function detalle_de_versiones($candidatas, array $confirmados)
    {
        $por_id = [];
        foreach ($candidatas as $candidata) {
            $por_id[(int) $candidata->id] = $candidata;
        }

        $detalle = [];
        foreach ($confirmados as $id) {
            if (! isset($por_id[$id])) {
                /* La versión destino se agrega SIEMPRE en resolve_confirmed_version_ids(), aunque no
                   figure entre las candidatas. No se la esconde: se la nombra con lo que se sabe. */
                $detalle[] = ['id' => (int) $id, 'version' => null, 'is_hotfix' => null];
                continue;
            }

            $detalle[] = [
                'id'        => (int) $id,
                'version'   => $por_id[$id]->version,
                'is_hotfix' => (bool) $por_id[$id]->is_hotfix,
            ];
        }

        return $detalle;
    }

    /* ==============================================================================================
     | Armado de respuestas
     |============================================================================================= */

    /**
     * Los candidatos sin el modelo Eloquent adentro, para que viajen en el JSON.
     *
     * @param array<int, array<string, mixed>> $candidatos Candidatos resueltos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function clientes_para_ver(array $candidatos)
    {
        $lista = [];

        foreach ($candidatos as $candidato) {
            $lista[] = [
                'client_id'               => $candidato['client_id'],
                'client_name'             => $candidato['client_name'],
                'from_version'            => $candidato['from_version'],
                'versiones_confirmadas'   => $candidato['versiones'],
                'cantidad'                => count($candidato['version_ids']),
                'target_client_api_id'    => $candidato['target_client_api_id'],
                'target_es_la_api_activa' => $candidato['target_es_la_api_activa'],
            ];
        }

        return $lista;
    }

    /**
     * Advertencias que la simulación tiene que decir en voz alta antes de que alguien confirme.
     *
     * @param array<int, array<string, mixed>> $candidatos Candidatos resueltos.
     *
     * @return array<int, string>
     */
    private function advertencias(array $candidatos)
    {
        $advertencias = [];

        $sobre_la_activa = 0;
        $sin_api_destino = 0;
        foreach ($candidatos as $candidato) {
            if ($candidato['target_es_la_api_activa']) {
                $sobre_la_activa++;
            }
            if ($candidato['target_client_api_id'] === null) {
                $sin_api_destino++;
            }
        }

        if ($sobre_la_activa > 0) {
            $advertencias[] = $sobre_la_activa . ' cliente(s) tienen como API destino por defecto la API ACTIVA en '
                . 'producción: el freno allow_deploy_to_active_api los va a frenar en el deploy/start. Pasa cuando el '
                . 'cliente tiene una sola ClientApi.';
        }

        if ($sin_api_destino > 0) {
            $advertencias[] = $sin_api_destino . ' cliente(s) quedarían sin target_client_api_id resuelto: el '
                . 'deploy/start los rechaza hasta que se les configure la API destino.';
        }

        return $advertencias;
    }

    /**
     * Huella del conjunto exacto que se simuló: id + nombre normalizado + versiones, por cliente.
     *
     * 🔴 Es el equivalente de `confirm_client_name` en un lote (§ docblock de la clase). Lleva el
     * NOMBRE porque el id no tiene ninguna redundancia y el nombre sí, y lleva las VERSIONES porque
     * un cliente puede seguir estando en la lista con otro camino de versiones —alguien publicó un
     * hotfix entre la simulación y la confirmación— y ése es exactamente el caso que
     * `confirm_version_count` frena en el alta de a uno.
     *
     * Determinista y sin estado: se recalcula del mismo input, no hace falta ninguna tabla. No es un
     * secreto ni pretende serlo: no defiende de alguien que quiere burlarlo (ya tiene la clave de la
     * API), defiende del error de armar la segunda llamada con una lista distinta de la que se leyó.
     *
     * @param array<int, array<string, mixed>> $candidatos    Candidatos resueltos.
     * @param int                              $to_version_id Versión destino.
     *
     * @return string
     */
    private function calcular_confirm_token(array $candidatos, $to_version_id)
    {
        $partes = [];

        foreach ($candidatos as $candidato) {
            $ids = $candidato['version_ids'];
            sort($ids);

            $partes[] = (int) $candidato['client_id']
                . ':' . md5(mb_strtolower(trim((string) $candidato['client_name'])))
                . ':' . md5(implode(',', $ids));
        }

        sort($partes);

        return substr(hash('sha256', 'upgrades-batch|' . (int) $to_version_id . '|' . implode('|', $partes)), 0, 32);
    }
}

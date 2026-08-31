<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespuestasParaClaude;
use App\Http\Controllers\Controller;
use App\Models\ClientSupportContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Carga y lectura de las fichas de contexto por cliente que el agente de soporte usa en cada
 * consulta.
 *
 * El circuito es este: Lucas y Claude escriben la ficha de un cliente en la conversación, y Claude
 * la sube acá con la clave del header. No hay pantalla de alta — el que escribe es un proceso
 * externo, y por eso la validación es explícita campo por campo, a diferencia del resto del
 * backend, que valida en el frontend porque el que llama es el SPA.
 *
 * 🔴 LOS DOS CAMPOS NO SON EQUIVALENTES, Y ESA ES LA RAZÓN DE SER DE TODO ESTO:
 *
 *   - `ficha_operativa` se inyecta en el prompt del agente en CADA consulta sobre ese cliente.
 *   - `notas_internas` NO se inyecta nunca. Es para el operador humano: juicios sobre la persona,
 *     temas comerciales, todo lo que no tiene que condicionar el tono de una respuesta que se le
 *     manda a ese mismo cliente.
 *
 * La separación es del esquema, no de la disciplina de quien escribe: son dos columnas, y el
 * camino que llega al prompt (ClientSupportContext::ficha_operativa_de_cliente()) hace un SELECT
 * de una sola columna, así que la nota no está ni en memoria cuando se arma el prompt.
 *
 * ⚠️ ESTE `GET` SÍ DEVUELVE `notas_internas`, Y NO ES UNA CONTRADICCIÓN. El consumidor prohibido
 * es el prompt del agente, no la sesión de Claude que carga las fichas: para no pisar una nota que
 * ya estaba hay que poder leerla antes. Son dos consumidores distintos con permisos distintos.
 *
 * 🔴 NADA CALCULABLE SE ACEPTA ACÁ. Tickets abiertos, antigüedad, versión que corre, cantidad de
 * mensajes y veces que se escaló no son campos de este endpoint: los arma
 * SupportClientContextService leyendo la base al momento de construir el prompt. Guardarlos sería
 * garantizar que queden viejos sin que nada lo denuncie. Si una ficha llega con un encabezado que
 * los repite, ese encabezado hay que sacarlo antes de subirla.
 */
class ClaudeClientContextController extends Controller
{
    use RespuestasParaClaude;

    /**
     * Tope de fichas por lote.
     *
     * No es un freno de peligrosidad —esto no toca el sistema de ningún cliente— sino de tamaño:
     * el lote entero va en una transacción con un lock por fila, y una corrida de mil fichas la
     * sostiene abierta más de lo que conviene. Con cuarenta clientes, cien es holgado.
     *
     * @var int
     */
    const MAX_ENTRIES = 100;

    /**
     * Devuelve las fichas cargadas, para poder leer antes de pisar.
     *
     * @param Request $request Request entrante. Filtro opcional `client_id`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index_json(Request $request)
    {
        $error = $this->validar_o_422($request, [
            'client_id' => 'nullable|integer',
        ]);
        if ($error !== null) {
            return $error;
        }

        $query = ClientSupportContext::query()
            ->leftJoin('clients', 'clients.id', '=', 'client_support_contexts.client_id')
            ->orderBy('client_support_contexts.client_id')
            ->select([
                'client_support_contexts.id',
                'client_support_contexts.client_id',
                'client_support_contexts.ficha_operativa',
                'client_support_contexts.notas_internas',
                'client_support_contexts.created_via',
                'client_support_contexts.created_at',
                'client_support_contexts.updated_at',
                'clients.name as client_name',
            ]);

        $client_id = $this->entero_o_null($request->input('client_id'));
        if ($client_id !== null) {
            $query->where('client_support_contexts.client_id', $client_id);
        }

        $fichas = $query->get();

        return response()->json([
            'fichas' => $fichas,
            'total'  => $fichas->count(),
            'nota'   => 'Sólo `ficha_operativa` llega al prompt del agente. `notas_internas` no se '
                . 'inyecta nunca: es para el operador humano.',
        ], 200);
    }

    /**
     * Alta idempotente de un lote de fichas de contexto.
     *
     * Reenviar el mismo `client_id` actualiza la fila; nunca crea una segunda. Lo que no vino en
     * el payload se queda donde está.
     *
     * @param Request $request Request entrante.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request)
    {
        $error = $this->validar_o_422($request, [
            'entries'                   => 'required|array|min:1|max:' . self::MAX_ENTRIES,
            'entries.*.client_id'       => 'required|integer|exists:clients,id',
            'entries.*.ficha_operativa' => 'nullable|string',
            'entries.*.notas_internas'  => 'nullable|string',
        ]);
        if ($error !== null) {
            return $error;
        }

        $filas = array_values(array_filter((array) $request->input('entries', []), 'is_array'));

        /* Un client_id repetido dentro del MISMO lote no rompe (la segunda vuelta encuentra la fila
           que creó la primera y la actualiza), pero el resultado depende del orden y eso es casi
           siempre un payload mal armado. Se rechaza antes de escribir nada. */
        $vistos = [];
        foreach ($filas as $fila) {
            $id = (int) (isset($fila['client_id']) ? $fila['client_id'] : 0);
            if (in_array($id, $vistos, true)) {
                return $this->error_422(
                    'El client_id ' . $id . ' viene más de una vez en el mismo lote: el resultado dependería '
                        . 'del orden de las entradas. No se escribió nada.',
                    ['client_id' => $id]
                );
            }
            $vistos[] = $id;
        }

        /* 🔴 SON DOS RECHAZOS DISTINTOS Y HUBO QUE SEPARARLOS. El primero que se escribió era uno
           solo —"si no trae texto, 422"— y hacía IMPOSIBLE borrar una nota: mandar
           `notas_internas: null` sobre una ficha que ya existe es la única forma de vaciar el
           campo, y quedaba rechazada junto con las entradas que no dicen nada. Lo agarró el test
           del null explícito. Son dos cosas distintas:
             a) una entrada que no trae NINGUNA de las dos claves no dice nada, nunca;
             b) una entrada que sí las trae pero todas en null sólo es un problema cuando la ficha
                NO existe todavía, porque ahí crea una fila vacía: ruido con forma de dato, que el
                GET devuelve y hace parecer que ese cliente tiene ficha. Sobre una ficha que ya
                existe, exactamente el mismo payload es un borrado legítimo. */
        foreach ($filas as $fila) {
            if (! $this->trae_alguna_clave($fila)) {
                return $this->error_422(
                    'La entrada del client_id ' . (int) $fila['client_id'] . ' no trae ni ficha_operativa ni '
                        . 'notas_internas: no dice nada. No se escribió nada.',
                    ['client_id' => (int) $fila['client_id']]
                );
            }
        }

        /* Qué client_id ya tienen ficha, en una sola consulta. La respuesta al rechazo (b) depende
           de eso, y se resuelve acá afuera para que el bucle de escritura quede plano.
           ⚠️ Entre esta lectura y la transacción hay una ventana teórica: si en el medio alguien
           creara la ficha, una entrada de puros null se rechazaría siendo un borrado válido. El
           daño es un 422 de más en un endpoint que llama una sola sesión cargando su lote, y la
           alternativa (decidirlo adentro de la transacción) obliga a abortarla con una excepción
           para poder contestar 422. No se paga esa complejidad por esta ventana. */
        $ya_existen = ClientSupportContext::query()
            ->whereIn('client_id', $vistos)
            ->pluck('client_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();

        foreach ($filas as $fila) {
            $client_id = (int) $fila['client_id'];

            if (in_array($client_id, $ya_existen, true)) {
                continue;
            }

            if (! $this->trae_algun_texto($fila)) {
                return $this->error_422(
                    'La entrada del client_id ' . $client_id . ' viene con ficha_operativa y notas_internas '
                        . 'vacías, y ese cliente todavía no tiene ficha: crearía una ficha vacía. No se '
                        . 'escribió nada.',
                    ['client_id' => $client_id]
                );
            }
        }

        $resultados = ['creadas' => 0, 'actualizadas' => 0];
        $ids_tocados = [];

        /* El lote entra o no entra: si una fila explota a mitad, no puede quedar medio lote cargado
           y la mitad afuera, porque del otro lado no hay forma de saber dónde se cortó. */
        DB::transaction(function () use ($filas, &$resultados, &$ids_tocados) {
            foreach ($filas as $fila) {
                $client_id = (int) $fila['client_id'];

                /* 🔴 El lock es lo que hace que la idempotencia aguante dos corridas encimadas.
                   Sin él esto es un SELECT y después un INSERT: dos requests con el mismo
                   client_id nuevo pueden pasar los dos por el SELECT antes de que cualquiera
                   inserte, y el índice único de la tabla hace que el segundo tire una
                   QueryException. Como el lote entero va en una transacción, esa excepción voltea
                   las treinta y una fichas de esa corrida y no sólo la que chocó. No es
                   hipotético: acá se reenvía el lote completo cada vez que se corrige una ficha, y
                   un timeout con reintento alcanza para encimar dos. Misma lección que
                   ClaudeClientTemplatesController. */
                $existente = ClientSupportContext::query()
                    ->where('client_id', $client_id)
                    ->lockForUpdate()
                    ->first();

                $datos = $this->fila_de_datos($fila, $client_id, $existente);

                if ($existente !== null) {
                    $existente->update($datos);
                    $resultados['actualizadas']++;
                } else {
                    ClientSupportContext::create($datos);
                    $resultados['creadas']++;
                }

                $ids_tocados[] = $client_id;
            }
        });

        $fichas = ClientSupportContext::query()
            ->whereIn('client_id', $ids_tocados)
            ->orderBy('client_id')
            ->get();

        /* 🔴 Auditoría SIN el texto. Se loguea qué clientes se tocaron y cuántos, nunca el
           contenido: la ficha y sobre todo las notas internas son apreciaciones sobre personas
           concretas, y el canal `daily` no es lugar para eso. Tampoco se loguea nunca la clave del
           header, ni la recibida ni la configurada. */
        Log::channel('daily')->info('ClaudeClientContextController: carga de fichas de contexto de cliente.', [
            'resultados' => $resultados,
            'client_ids' => $ids_tocados,
        ]);

        return response()->json([
            'resultados' => $resultados,
            'fichas'     => $fichas,
            'nota'       => 'Sólo `ficha_operativa` llega al prompt del agente. `notas_internas` no se '
                . 'inyecta nunca: es para el operador humano.',
        ], 200);
    }

    /**
     * Dice si una entrada nombra al menos uno de los dos campos, con el valor que sea.
     *
     * Nombrar el campo con `null` ES decir algo (borralo). No nombrarlo no dice nada.
     *
     * @param array $fila Entrada del payload, ya validada.
     *
     * @return bool
     */
    protected function trae_alguna_clave(array $fila)
    {
        return array_key_exists('ficha_operativa', $fila) || array_key_exists('notas_internas', $fila);
    }

    /**
     * Dice si una entrada trae al menos uno de los dos textos con contenido real.
     *
     * @param array $fila Entrada del payload, ya validada.
     *
     * @return bool
     */
    protected function trae_algun_texto(array $fila)
    {
        foreach (['ficha_operativa', 'notas_internas'] as $campo) {
            if (! array_key_exists($campo, $fila)) {
                continue;
            }

            if ($fila[$campo] !== null && trim((string) $fila[$campo]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Arma las columnas a guardar a partir de una entrada del payload.
     *
     * 🔴 En una actualización, un campo ausente NO borra lo que ya estaba: se puede corregir la
     * ficha operativa de un cliente sin tener que repetir sus notas internas, y perderlas en
     * silencio sería peor que ignorar el campo. Mandar `null` explícito SÍ lo borra — es la única
     * forma de vaciar un campo, y es deliberada.
     *
     * @param array                     $fila      Entrada del payload, ya validada.
     * @param int                       $client_id Id del cliente.
     * @param ClientSupportContext|null $existente Fila que ya estaba, si la había.
     *
     * @return array<string, mixed>
     */
    protected function fila_de_datos(array $fila, $client_id, $existente)
    {
        $datos = ['client_id' => $client_id];

        foreach (['ficha_operativa', 'notas_internas'] as $campo) {
            if (! array_key_exists($campo, $fila)) {
                continue;
            }

            if ($fila[$campo] === null) {
                $datos[$campo] = null;
                continue;
            }

            $texto = trim((string) $fila[$campo]);
            $datos[$campo] = $texto === '' ? null : $texto;
        }

        /* 🔴 `created_via` se estampa SÓLO en el alta. Una actualización no le cambia el origen a
           una fila: si mañana el admin gana una pantalla para editar fichas a mano, que Claude
           corrija una no la convierte en suya. */
        if ($existente === null) {
            $datos['created_via'] = ClientSupportContext::CREATED_VIA_CLAUDE;
        }

        return $datos;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Services\DemoHitosService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Canal de entrada de lo que pasa adentro de una instancia de demo (misión 48, pieza 4).
 *
 * Hasta esta misión no había ningún registro de lo que el lead hacía adentro de la demo: lo único
 * que se guardaba era el `LeadMessage` de sistema que escribe `DemoExperienciaController`. Acá
 * entran los eventos que emite la instancia (misión 50) y de acá salen los hitos marcados.
 *
 * El lead NO viene en el body: lo resuelve {@see \App\Http\Middleware\DemoEventosKey} desde el
 * header, que es la única fuente.
 */
class DemoEventosController extends Controller
{
    /** Tamaño máximo del json de `datos` de un evento, en bytes del serializado. */
    const MAX_BYTES_DATOS = 4096;

    /**
     * Evento con el que la instancia avisa que terminó de armarse el demo setup (misión 61).
     *
     * No es un hito del roadmap: es estado del ciclo de vida de la INSTANCIA. Ver
     * {@see self::marcar_setup_exitoso()}.
     */
    const EVENTO_SETUP_COMPLETADO = 'demo.setup.completado';

    /**
     * POST /api/demo-eventos
     *
     * Recibe un lote de eventos (siempre array, aunque venga uno solo), los persiste y los aplica
     * a los hitos del roadmap del lead.
     *
     * @param Request $request Body `{ "eventos": [ { uuid, nombre, clip_id, ocurrido_at, datos } ] }`.
     *
     * @return JsonResponse Resumen del lote: recibidos, guardados, duplicados, hitos movidos.
     */
    public function store_json(Request $request): JsonResponse
    {
        // El lead lo puso el middleware desde el token del header. Nunca del body.
        $lead = $request->attributes->get('demo_eventos_lead');

        if (! $lead instanceof Lead) {
            return response()->json(['error' => 'no autorizado'], 401);
        }

        /* Tres cosas que la validación tiene que cerrar, y las tres se pagan caro si faltan:
         *
         *  - `max` en el lote. Sin techo, un POST con 100.000 eventos son cientos de miles de
         *    queries en una sola request contra la base que corre el panel comercial entero. 200
         *    es holgado para el caso real (una demo dura una hora y emite decenas de eventos).
         *  - `date` en `ocurrido_at`, y no `string`. La columna es un `timestamp` con cast
         *    `datetime`: un valor ilegible reventaba en el `create()` con un 500 no manejado, y
         *    como el emisor reintenta ante cualquier respuesta no exitosa, UN evento mal formado
         *    dejaba el canal en loop infinito fallando siempre en el mismo lugar. Con `date` la
         *    respuesta es 422, que es definitiva y le dice al emisor qué arreglar.
         *  - Techo en `datos`. Es un json libre que entra sin mirar: sin techo, un solo evento
         *    puede traer megabytes. Ojo con esto: el `max:50` de la regla cuenta ELEMENTOS del
         *    array, no bytes — con él solo, un `datos` de una clave y 2 MB de texto pasa igual
         *    (medido). El límite de tamaño real se chequea abajo, sobre el json serializado.
         *
         * Misión 48. */
        $validated = $request->validate([
            'eventos'               => 'required|array|min:1|max:200',
            'eventos.*.uuid'        => 'required|string|max:64',
            'eventos.*.nombre'      => 'required|string|max:60',
            'eventos.*.clip_id'     => 'nullable|string|max:10',
            // El rango va acotado además de validado: `date` acepta "2999-12-31", que parsea bien
            // en Carbon y se sale del rango de un TIMESTAMP de MySQL (1970-2038) — o sea, otro
            // 500 por el mismo camino que el valor ilegible.
            'eventos.*.ocurrido_at' => 'nullable|date|after:2020-01-01|before:2038-01-01',
            'eventos.*.datos'       => 'nullable|array|max:50',
        ]);

        // Techo de tamaño de `datos`, en bytes del json serializado — que es lo que termina en la
        // columna. 4 KB es holgado para lo que un evento de demo necesita cargar y corta de raíz
        // el evento de megabytes que la regla `max:50` deja pasar.
        foreach ($validated['eventos'] as $indice => $evento) {
            if (! isset($evento['datos'])) {
                continue;
            }

            if (strlen((string) json_encode($evento['datos'])) > self::MAX_BYTES_DATOS) {
                return response()->json([
                    'message' => 'El campo datos supera el tamaño máximo permitido.',
                    'errors'  => ['eventos.' . $indice . '.datos' => ['Máximo ' . self::MAX_BYTES_DATOS . ' bytes.']],
                ], 422);
            }
        }

        $guardados  = 0;
        $duplicados = 0;
        $movidos    = 0;

        foreach ($validated['eventos'] as $evento) {
            /* Idempotencia del canal: el uuid es unique en la tabla, así que un evento ya visto se
             * ignora en silencio y cuenta como aceptado. Si se devolviera un error, el emisor —que
             * reintenta ante cualquier respuesta no exitosa— reintentaría para siempre. Y el
             * chequeo va ANTES de aplicar los hitos, no sólo antes de insertar: aplicar dos veces
             * el mismo evento no rompe nada hoy (los estados no retroceden y las marcas se
             * escriben una sola vez), pero sí falsearía el conteo de hitos movidos. Misión 48. */
            $ya_recibido = DemoEventoRecibido::where('lead_id', $lead->id)
                ->where('uuid', $evento['uuid'])
                ->exists();
            if ($ya_recibido) {
                $duplicados++;

                continue;
            }

            /* El `exists()` de arriba resuelve el caso normal, pero NO es atómico con el insert:
             * dos requests del mismo emisor pueden leer las dos que el evento no existe y la
             * segunda choca contra el unique (lead_id, uuid). Sin este catch eso sale como HTTP
             * 500, y como el emisor reintenta ante cualquier respuesta no exitosa, el lote entra
             * en loop. Un choque de unicidad significa exactamente lo mismo que el `exists()`:
             * el evento ya está. Se cuenta como duplicado y se sigue. Misión 48. */
            try {
                DemoEventoRecibido::create([
                    'lead_id'     => $lead->id,
                    'uuid'        => $evento['uuid'],
                    'nombre'      => $evento['nombre'],
                    'clip_id'     => isset($evento['clip_id']) ? $evento['clip_id'] : null,
                    'ocurrido_at' => isset($evento['ocurrido_at']) ? $evento['ocurrido_at'] : null,
                    'datos'       => isset($evento['datos']) ? $evento['datos'] : null,
                ]);
            } catch (QueryException $e) {
                // 23000 es la clase SQLSTATE de violación de integridad (incluye el 1062 de
                // MySQL). Cualquier otro error de base sí tiene que subir.
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }

                $duplicados++;

                continue;
            }

            $guardados++;

            /* 🔴 `demo.setup.completado` se persiste como cualquier otro evento, pero NO pasa por
             * DemoHitosService y no mueve ningún hito. No es un descuido ni una optimización: el
             * roadmap es el recorrido del LEAD adentro de la demo (entró, vio el tutorial, creó el
             * artículo), y que la instancia se haya terminado de armar no es algo que el lead
             * hizo. Mezclarlos le mostraría al lead un logro que no es suyo, y encima descontaría
             * el conteo de `hitos` del resumen del lote, que es lo que mira el emisor. Misión 61. */
            if ($evento['nombre'] === self::EVENTO_SETUP_COMPLETADO) {
                $this->marcar_setup_exitoso($lead);

                continue;
            }

            // Un evento que no le corresponde a ningún hito del plan queda guardado igual y no
            // rompe nada: el crudo alimenta el brief del closer aunque hoy no lo sepamos leer.
            $movidos += DemoHitosService::aplicar($lead, $evento);
        }

        Log::info('DemoEventosController: lote de eventos de demo recibido.', [
            'lead_id'    => $lead->id,
            'recibidos'  => count($validated['eventos']),
            'guardados'  => $guardados,
            'duplicados' => $duplicados,
            'movidos'    => $movidos,
        ]);

        return response()->json([
            'recibidos'  => count($validated['eventos']),
            'guardados'  => $guardados,
            'duplicados' => $duplicados,
            'hitos'      => $movidos,
        ], 200);
    }

    /**
     * Deja el demo setup del lead en `exitoso` porque la propia instancia avisó que terminó de
     * armarse (misión 61).
     *
     * @param Lead $lead Lead dueño del canal, ya resuelto por el middleware.
     */
    protected function marcar_setup_exitoso(Lead $lead): void
    {
        /* Misma guardia que las cuatro de DemoHitosService, y por el mismo motivo: hoy el
         * middleware del canal ya rechaza con 401 a un lead 'actual', pero una defensa que depende
         * de que otra no falle no es una defensa. Un lead de la dinámica actual no tiene instancia
         * que reporte nada. */
        if (! $lead->usa_experiencia_demo_nueva()) {
            return;
        }

        /* 🔴 Este evento mueve el estado a `exitoso` desde `pendiente`, desde `ejecutandose` y
         * TAMBIÉN desde `fallido`. Lo último es deliberado y no viola la regla de que ningún
         * estado retrocede: `exitoso` es el estado más alto de los cuatro, así que desde cualquiera
         * de los otros tres esto siempre es avanzar.
         *
         * Y por qué desde `fallido` en particular, que es lo que alguien va a querer "corregir"
         * acá con un `where('demo_setup_status', '!=', 'fallido')`: hasta la misión 61 el admin se
         * enteraba del resultado del setup SOLO por la respuesta HTTP del POST a
         * `/api/admin-sync/demo-setup`, que se espera hasta 300 segundos adentro del
         * RunDemoSetupJob. Si esa conexión se corta con el setup terminando bien del otro lado,
         * `RunDemoSetupService::mark_failed()` deja al lead en `fallido` con la instancia
         * perfectamente armada, y `evaluar_ingreso()` no le habilita nunca el botón de comenzar la
         * demo. Este evento lo emite la instancia recién DESPUÉS de terminar de armarse: es prueba
         * directa de que la demo existe, y es información más nueva y más confiable que un POST que
         * se cortó. Ponerle esa condición vuelve a romper exactamente el caso que esta misión vino
         * a arreglar. */
        $lead->update([
            'demo_setup_status'     => 'exitoso',
            'demo_setup_last_error' => null,
        ]);
    }
}

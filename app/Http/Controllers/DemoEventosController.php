<?php

namespace App\Http\Controllers;

use App\Models\DemoEventoRecibido;
use App\Models\Lead;
use App\Services\DemoHitosService;
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
         *  - `max` en `datos`. Es un json libre que entra sin mirar: sin techo, un solo evento
         *    puede traer megabytes.
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

            DemoEventoRecibido::create([
                'lead_id'     => $lead->id,
                'uuid'        => $evento['uuid'],
                'nombre'      => $evento['nombre'],
                'clip_id'     => isset($evento['clip_id']) ? $evento['clip_id'] : null,
                'ocurrido_at' => isset($evento['ocurrido_at']) ? $evento['ocurrido_at'] : null,
                'datos'       => isset($evento['datos']) ? $evento['datos'] : null,
            ]);
            $guardados++;

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
}

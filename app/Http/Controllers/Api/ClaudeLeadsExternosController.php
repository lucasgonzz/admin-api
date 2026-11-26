<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadCompromiso;
use App\Services\LeadAltaExternaService;
use App\Services\LeadCompromisoService;
use Illuminate\Http\Request;

/**
 * Alta de leads de otros medios desde los endpoints `claude/*` (misión leads-referidos-compromisos, etapa E1).
 *
 * Es la puerta que usan las skills `whatsapp-personal` y `chequeo-de-leads` cuando Lucas pasa un contacto por su
 * WhatsApp personal. Mismo contrato de seguridad que el resto de `claude/*` (la clave `X-Claude-Task-Key` la exige
 * el middleware del grupo de rutas):
 *
 *  - `POST claude/leads`: los mismos campos que el alta del panel, sueltos en el cuerpo. 🔴 `dry_run` por defecto
 *    TRUE: devuelve lo que haría y no escribe nada. Con `dry_run=false`: 201 si crea, 200 si completa uno existente
 *    (`completar_existente=true`), 409 si el teléfono ya tiene lead.
 *  - `POST claude/leads/{id}/contexto-previo`: `{texto, fuente, dry_run}` AGREGA texto al contexto previo con
 *    encabezado (nunca reemplaza) hasta 60.000 caracteres en total.
 *
 * Y los compromisos de contacto (etapa E2), para lo que Lucas pacta en su WhatsApp personal («escribile el martes»):
 *
 *  - `GET claude/leads/{id}/compromisos`: 200 `{compromisos}` (el abierto primero).
 *  - `POST claude/leads/{id}/compromisos`: `{vence_at, accion, resumen, cita_del_lead, dry_run}`. 🔴 `dry_run` por defecto TRUE
 *    (200 con lo que haría, cero escrituras); con `dry_run=false` crea (201, `origen = agente`) y reemplaza al vigente.
 *  - `POST claude/lead-compromisos/{id}/cancelar`: `{motivo, dry_run}`, con el mismo freno.
 *
 * Sin lógica de negocio (vive en {@see LeadAltaExternaService} y {@see LeadCompromisoService}); sin `$request->validate()`, el
 * servicio responde el 422.
 */
class ClaudeLeadsExternosController extends Controller
{
    /**
     * Alta de un lead de otro medio (simulada por defecto).
     *
     * @param Request                $request
     * @param LeadAltaExternaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store_json(Request $request, LeadAltaExternaService $servicio)
    {
        $simular = $this->es_simulacion($request);

        $resultado = $servicio->crear($request->all(), null, $simular);

        if ($resultado['conflicto']) {
            return response()->json([
                'message' => 'Ya hay un lead con ese teléfono. Mandá completar_existente=true para completarlo.',
                'code'    => 'lead_existente',
                'lead_id' => (int) $resultado['lead_id'],
                'dry_run' => $simular,
            ], 409);
        }

        if ($simular) {
            return response()->json([
                'dry_run'         => true,
                'message'         => 'Simulación: no se escribió nada. Mandá dry_run=false para dar de alta.',
                'lead_id'         => $resultado['lead_id'],
                'que_haria'       => $resultado['que_haria'],
                'primer_contacto' => $resultado['primer_contacto'],
            ], 200);
        }

        $lead = $resultado['lead'];

        return response()->json([
            'dry_run'         => false,
            'model'           => $this->fullModel('lead', (int) $lead->id),
            'primer_contacto' => $resultado['primer_contacto'],
        ], $resultado['creado'] ? 201 : 200);
    }

    /**
     * Agrega texto al contexto previo de un lead (simulado por defecto).
     *
     * @param Request                $request Body: texto (req), fuente, dry_run.
     * @param int|string             $id      Lead.
     * @param LeadAltaExternaService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function contexto_previo_json(Request $request, $id, LeadAltaExternaService $servicio)
    {
        $lead = Lead::query()->find((int) $id);
        if ($lead === null) {
            return response()->json(['message' => 'El lead no existe.'], 404);
        }

        $simular = $this->es_simulacion($request);

        $resultado = $servicio->agregar_contexto(
            $lead,
            (string) $request->input('texto', ''),
            (string) $request->input('fuente', ''),
            $simular
        );

        return response()->json([
            'dry_run'     => $simular,
            'lead_id'     => (int) $lead->id,
            'largo_total' => $resultado['largo_total'],
            'message'     => $simular ? 'Simulación: no se escribió nada.' : 'Contexto agregado.',
        ], 200);
    }

    /**
     * ¿El pedido es una simulación? `dry_run` vale TRUE salvo que venga explícitamente en false.
     *
     * @param Request $request
     *
     * @return bool
     */
    private function es_simulacion(Request $request): bool
    {
        if (! $request->has('dry_run')) {
            return true;
        }

        return filter_var($request->input('dry_run'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Los compromisos del lead.
     *
     * @param int|string            $id
     * @param LeadCompromisoService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function compromisos_index_json($id, LeadCompromisoService $servicio)
    {
        $lead = Lead::query()->find((int) $id);
        if ($lead === null) {
            return response()->json(['message' => 'El lead no existe.'], 404);
        }

        $modelos = [];
        foreach ($servicio->listar($lead) as $compromiso) {
            $modelos[] = $servicio->a_json($compromiso);
        }

        return response()->json(['compromisos' => $modelos], 200);
    }

    /**
     * Crea un compromiso para el lead (simulado por defecto).
     *
     * @param Request               $request  vence_at, accion, resumen, cita_del_lead, dry_run.
     * @param int|string            $id
     * @param LeadCompromisoService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function compromisos_store_json(Request $request, $id, LeadCompromisoService $servicio)
    {
        $lead = Lead::query()->find((int) $id);
        if ($lead === null) {
            return response()->json(['message' => 'El lead no existe.'], 404);
        }

        if (! LeadCompromisoService::activo()) {
            return response()->json([
                'message' => 'El motor de compromisos está apagado: no se guardó nada. Se prende desde Cuenta.',
                'code'    => 'motor_apagado',
                'dry_run' => $this->es_simulacion($request),
            ], 409);
        }

        /* La misma validación que el alta real, sin escribir: un dry_run que dice «va a andar» y después falla no sirve. */
        $limpios = $servicio->limpiar_datos($request->all());

        if ($this->es_simulacion($request)) {
            return response()->json([
                'dry_run'   => true,
                'message'   => 'Simulación: no se escribió nada. Mandá dry_run=false para crear el compromiso.',
                'que_haria' => [
                    'accion'              => $limpios['accion'],
                    'vence_at'            => $limpios['vence_at']->format('Y-m-d H:i:s'),
                    'resumen'             => $limpios['resumen'],
                    'reemplaza_al_vigente' => $servicio->abierto_de($lead) !== null,
                ],
            ], 200);
        }

        $compromiso = $servicio->crear($lead, array_merge($request->all(), ['origen' => LeadCompromiso::ORIGEN_AGENTE]), null);

        return response()->json(['dry_run' => false, 'model' => $servicio->a_json($compromiso)], 201);
    }

    /**
     * Cancela un compromiso (simulado por defecto).
     *
     * @param Request               $request  motivo, dry_run.
     * @param int|string            $id       Id del compromiso.
     * @param LeadCompromisoService $servicio
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function compromiso_cancelar_json(Request $request, $id, LeadCompromisoService $servicio)
    {
        $compromiso = LeadCompromiso::query()->find((int) $id);
        if ($compromiso === null) {
            return response()->json(['message' => 'El compromiso no existe.'], 404);
        }

        if ($this->es_simulacion($request)) {
            return response()->json([
                'dry_run'   => true,
                'message'   => 'Simulación: no se canceló nada. Mandá dry_run=false para cancelarlo.',
                'que_haria' => ['compromiso_id' => (int) $compromiso->id, 'status_actual' => (string) $compromiso->status, 'pasaria_a' => LeadCompromiso::STATUS_CANCELADO],
            ], 200);
        }

        $compromiso = $servicio->cancelar($compromiso, $request->input('motivo'), null);

        return response()->json(['dry_run' => false, 'model' => $servicio->a_json($compromiso)], 200);
    }
}

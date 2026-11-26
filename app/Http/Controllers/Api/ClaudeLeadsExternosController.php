<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\LeadAltaExternaService;
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
 * Sin lógica de negocio (vive en {@see LeadAltaExternaService}); sin `$request->validate()`, el servicio responde el 422.
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
}

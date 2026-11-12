<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\SupportCodexService;
use App\Services\SupportDynamicTourService;
use App\Services\SupportTourService;
use App\Services\WhatsappSessionWindowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SupportTourController extends Controller
{
    public function ofrecer(Request $request, $id, SupportCodexService $soporte, SupportTourService $tours)
    {
        $data = $request->validate(['expected_message_id' => 'required|integer|min:1']);
        $guion = $tours::guion($request->all());
        if ($guion === null || $guion['tema'] === SupportTourService::TEMA_DINAMICO) {
            return response()->json(['ok' => false, 'code' => 'tour_invalid', 'message' => 'El recorrido o sus textos son inválidos.'], 422);
        }

        $resultado = DB::transaction(function () use ($request, $id, $data, $guion, $soporte, $tours) {
            $ticket = SupportTicket::lockForUpdate()->findOrFail($id);
            $soporte->assert_current($ticket, [
                'lease_token' => (string) $request->header('X-Support-Lease-Token'),
                'expected_message_id' => $data['expected_message_id'],
                'action' => 'responder',
            ]);
            if ($ticket->requiere_verificacion_mensajes) {
                return ['ok' => false, 'status' => 409, 'code' => 'verification_required', 'message' => 'El ticket exige aprobación humana.'];
            }
            if ($ticket->source === 'whatsapp' && ! app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone)['open']) {
                return ['ok' => false, 'status' => 422, 'code' => 'window_expired', 'message' => 'Venció la ventana de WhatsApp.'];
            }

            $entrante = SupportMessage::findOrFail($data['expected_message_id']);
            return $tours->ofrecer($ticket, $entrante, $guion);
        });

        return $this->respuesta($resultado);
    }

    public function preparar(Request $request, $id, SupportCodexService $soporte, SupportDynamicTourService $tours)
    {
        $data = $request->validate(['expected_message_id' => 'required|integer|min:1']);
        try {
            $resultado = $tours->preparar((int) $id, (int) $data['expected_message_id'], function (SupportTicket $ticket) use ($request, $data, $soporte) {
                $soporte->assert_current($ticket, [
                    'lease_token' => (string) $request->header('X-Support-Lease-Token'),
                    'expected_message_id' => $data['expected_message_id'],
                    'action' => 'responder',
                ]);
                abort_if($ticket->source === 'whatsapp'
                    && ! app(WhatsappSessionWindowService::class)->window_state((string) $ticket->whatsapp_phone)['open'],
                    422, 'window_expired: Venció la ventana de WhatsApp.');
            });
        } catch (HttpExceptionInterface $corte) {
            // El estado del ticket cortó antes de planificar (mensaje viejo, revisión humana, misión, ventana vencida):
            // la respuesta sigue igual, pero el motivo queda en el log y en el contexto del ticket.
            SupportDynamicTourService::registrar_fallo((int) $id, (int) $data['expected_message_id'],
                'estado_del_ticket', mb_substr($corte->getMessage(), 0, 200));
            throw $corte;
        }

        return $this->respuesta($resultado);
    }

    public function seguimiento($id, SupportTourService $tours)
    {
        return $this->respuesta($tours->seguimiento(SupportTicket::findOrFail($id)));
    }

    private function respuesta(array $resultado)
    {
        $status = $resultado['status'] ?? 200;
        unset($resultado['status']);
        return response()->json($resultado, $status);
    }
}

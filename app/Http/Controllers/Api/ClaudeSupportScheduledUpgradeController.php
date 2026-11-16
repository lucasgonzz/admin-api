<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportCodexMissionFixEvidence;
use App\Models\SupportCodexScheduledUpgrade;
use App\Models\SupportTicket;
use App\Services\SupportScheduledUpgradeService;
use Illuminate\Http\Request;

class ClaudeSupportScheduledUpgradeController extends Controller
{
    public function show($id)
    {
        SupportTicket::findOrFail($id);
        $row = SupportCodexScheduledUpgrade::where('support_ticket_id', $id)->latest('id')->first();
        return response()->json(['scheduled_upgrade' => $row === null ? null : $this->present($row)]);
    }

    public function store(Request $request, $id, SupportScheduledUpgradeService $service)
    {
        $input = $request->validate(['agreement_id' => 'required|integer|min:1', 'version_id' => 'required|integer|min:1', 'target' => 'sometimes|in:sistema,tienda']);
        $agreement = \App\Models\SupportCodexUpgradeAgreement::where('support_ticket_id', $id)->findOrFail($input['agreement_id']);
        abort_unless(($input['target'] ?? 'sistema') === ($agreement->target ?: 'sistema'), 409, 'El destino no coincide con el acuerdo.');
        $row = $service->schedule((int) $id, (int) $input['agreement_id'], (int) $input['version_id']);
        return response()->json(['scheduled_upgrade' => $this->present($row)], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function verify(Request $request, $id, SupportScheduledUpgradeService $service)
    {
        $input = $request->validate([
            'agreement_id' => 'required|integer|min:1',
            'upgrade_id' => 'required|integer|min:1',
            'mission_thread_id' => 'required|string|max:150',
            'mission_hash' => 'required|string|regex:/^[a-f0-9]{64}$/D',
            'resultado' => 'required|in:verificado,inconcluso',
            'evidencia' => 'required|string|max:3000',
            'motivo' => 'sometimes|in:verificacion_sin_resultado',
        ]);
        abort_if(isset($input['motivo']) && $input['resultado'] !== 'inconcluso', 422, 'El motivo corresponde a una comprobación inconclusa.');
        $row = $service->verify((int) $id, $input);
        return response()->json(['scheduled_upgrade' => $this->present($row)]);
    }

    private function present(SupportCodexScheduledUpgrade $row): array
    {
        $evidence = SupportCodexMissionFixEvidence::find(optional($row->agreement)->mission_fix_evidence_id);
        return [
            'id' => (int) $row->id, 'target' => $row->target ?: 'sistema',
            'client_ecommerce_id' => $row->client_ecommerce_id === null ? null : (int) $row->client_ecommerce_id,
            'maintenance_mode' => optional($row->agreement)->maintenance_mode ?: 'closed',
            'force' => optional($row->agreement)->maintenance_mode === 'coordinated',
            'agreement_id' => (int) $row->agreement_id,
            'version_id' => (int) $row->version_id,
            'version' => $row->version,
            'upgrade_id' => $row->target === 'tienda'
                ? ($row->ecommerce_installation_id === null ? null : (int) $row->ecommerce_installation_id)
                : ($row->upgrade_id === null ? null : (int) $row->upgrade_id),
            'status' => $row->status,
            'reason' => $row->reason,
            'starts_at_utc' => $row->starts_at_utc,
            'ends_at_utc' => $row->ends_at_utc,
            'mission_thread_id' => optional($evidence)->mission_thread_id,
            'mission_hash' => optional($evidence)->mission_hash,
        ];
    }
}

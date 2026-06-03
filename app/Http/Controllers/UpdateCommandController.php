<?php

namespace App\Http\Controllers;

use App\Models\ClientVersionUpgrade;
use App\Models\UpdateCommand;
use Illuminate\Http\Request;

class UpdateCommandController extends Controller
{
    function mark_json(Request $request, $update_id, $id)
    {
        $upgrade = ClientVersionUpgrade::findOrFail($update_id);
        $command = UpdateCommand::where('client_version_upgrade_id', $update_id)->findOrFail($id);

        $status = $request->input('status');
        if (! in_array($status, ['pendiente', 'exitoso', 'fallido'])) {
            return response()->json(['message' => 'Estado de comando no válido.'], 422);
        }

        $command->update([
            'status'        => $status,
            'executed_at'   => in_array($status, ['exitoso', 'fallido']) ? ($command->executed_at ?? now()) : null,
            'failure_notes' => $status === 'fallido' ? $request->input('failure_notes') : null,
        ]);

        $upgrade->recalculate_status();

        return response()->json([
            'model' => ClientVersionUpgrade::withAll()->find($update_id),
        ], 200);
    }

    function mark(Request $request, $update_id, $id) {
        $upgrade = ClientVersionUpgrade::findOrFail($update_id);
        $command = UpdateCommand::where('client_version_upgrade_id', $update_id)->findOrFail($id);

        $status = $request->input('status');

        if (!in_array($status, ['pendiente', 'exitoso', 'fallido'])) {
            return redirect()->route('updates.show', $update_id)
                             ->with('error', 'Estado de comando no válido.');
        }

        $command->update([
            'status'        => $status,
            'executed_at'   => in_array($status, ['exitoso', 'fallido']) ? ($command->executed_at ?? now()) : null,
            'failure_notes' => $status === 'fallido' ? $request->input('failure_notes') : null,
        ]);

        $upgrade->recalculate_status();

        return redirect()->route('updates.show', $update_id)
                         ->with('success', 'Estado del comando actualizado.');
    }
}

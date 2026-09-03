<?php

namespace App\Http\Controllers;

use App\Models\Version;
use App\Models\VersionCommand;
use App\Services\VersionItemSanitizer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VersionCommandController extends Controller
{
    function store(Request $request, $versionId) {
        $version = Version::findOrFail($versionId);
        /* Mismo saneamiento que la ingesta de Claude: la regla vive en un solo lugar. */
        $comando = $this->sanear_comando_o_fallar($request->input('command'));
        $nextOrder = (int) (VersionCommand::query()
            ->where('version_id', $version->id)
            ->max('execution_order') ?? -1) + 1;
        $command = VersionCommand::create([
            'version_id' => $version->id,
            'command' => $comando,
            'description' => $request->input('description'),
            'execution_order' => $nextOrder,
            'is_required' => $request->boolean('is_required', true),
            'run_manually' => $request->boolean('run_manually', false),
            /* Default per_user si no se envía run_scope desde el formulario Blade */
            'run_scope' => $this->normalize_run_scope($request->input('run_scope'), 'per_user'),
        ]);
        $this->syncRestrictedClientsFromRequest($command, $request);
        return redirect(route('versions.show', $version->id) . '#tab-commands')->with('success', 'Comando agregado.');
    }

    function update(Request $request, $versionId, $id) {
        $command = VersionCommand::where('version_id', $versionId)->findOrFail($id);
        $comando = $this->sanear_comando_o_fallar($request->input('command'));
        $command->update([
            'command' => $comando,
            'description' => $request->input('description'),
            'execution_order' => (int) $request->input('execution_order', 0),
            'is_required' => $request->boolean('is_required', false),
            'run_manually' => $request->boolean('run_manually', false),
            'run_scope' => $this->normalize_run_scope($request->input('run_scope'), 'per_user'),
        ]);
        $this->syncRestrictedClientsFromRequest($command, $request);
        return redirect(route('versions.show', $versionId) . '#tab-commands')->with('success', 'Comando actualizado.');
    }

    /**
     * Completa el comando con --force si le hace falta, o corta con 422 si es destructivo.
     *
     * Un artisan confirmable sin --force cuelga el deployment 30 minutos esperando un "yes" por
     * stdin: es lo que volteo el upgrade 76 de masquito el 3/9/2026.
     *
     * @param  string|null  $valor
     * @return string
     */
    private function sanear_comando_o_fallar($valor): string
    {
        $motivo = VersionItemSanitizer::motivo_de_rechazo_de_comando($valor);
        if ($motivo !== null) {
            /*
             * 🔴 ValidationException y NO abort(422): estas son rutas WEB del panel, que responden
             * con redirect. Un abort(422) cae en la pagina de error generica de Laravel —admin-api
             * no tiene vista errors::422— asi que el operador NO ve el motivo, que es justamente
             * lo unico que esta guarda tiene para darle, y ademas pierde lo que habia cargado en
             * el formulario. Con esto vuelve al form, con el mensaje y con sus datos.
             * Lo levanto la verificacion independiente de esta mision.
             */
            throw ValidationException::withMessages(['command' => $motivo]);
        }

        return VersionItemSanitizer::sanear_comando($valor);
    }

    function destroy($versionId, $id) {
        $command = VersionCommand::where('version_id', $versionId)->findOrFail($id);
        $command->delete();
        return redirect(route('versions.show', $versionId) . '#tab-commands')->with('success', 'Comando eliminado.');
    }
}

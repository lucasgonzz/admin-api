<?php

namespace App\Http\Controllers;

use App\Mail\Helpers\LeadDemoMailHelper;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Landing pública de la demo por lead (prompt 213).
 *
 * Todo lo que hoy vive únicamente en el Mail 1 (videos tutoriales, datos de
 * acceso, tienda demo, día y horario del turno) también tiene que estar
 * disponible en una URL propia por lead, pública y sin login, para poder
 * mandarla por WhatsApp si el mail no llega o cae en spam.
 *
 * El token de la URL es el `uuid` que ya tiene la tabla `leads` (no es
 * enumerable). Ojo: `HasUuid::getRouteKeyName()` devuelve 'id', así que acá
 * se busca a mano por `uuid` en vez de depender del route model binding.
 */
class DemoLandingController extends Controller
{
    /**
     * Muestra la landing pública de la demo de un lead a partir de su uuid.
     *
     * Reusa `LeadDemoMailHelper::build_view_data()` (misma fuente que el Mail 1) para
     * que el mail y la landing nunca se desincronicen. Si el lead no tiene demo
     * asignada o no tiene documento cargado, se muestra igual la página con
     * `acceso_listo = false` en vez de abortar: los videos siguen siendo útiles
     * aunque el acceso todavía se esté preparando.
     *
     * @param string $uuid Token público del lead (columna `uuid`, no enumerable).
     *
     * @return \Illuminate\View\View
     */
    public function show($uuid)
    {
        // Búsqueda manual por uuid: el route model binding implícito no aplica acá
        // porque HasUuid::getRouteKeyName() devuelve 'id', no 'uuid'.
        $lead = Lead::where('uuid', $uuid)->first();
        if (! $lead) {
            abort(404);
        }

        // Datos del acceso listos: demo asignada y documento cargado (usado como
        // usuario/contraseña). Sin esto no hay credenciales reales que mostrar.
        $acceso_listo = ! empty($lead->demo_id) && ! empty($lead->doc_number);

        // Misma fuente de datos que el Mail 1, para que ambos canales muestren
        // siempre la misma información (nombre, día, horario, videos, accesos).
        $data = LeadDemoMailHelper::build_view_data($lead);
        $data['acceso_listo'] = $acceso_listo;

        // Log de visita: solo lead_id, sin datos sensibles del lead.
        Log::info('DemoLandingController@show: visita a landing de demo.', [
            'lead_id' => $lead->id,
        ]);

        return view('demo.landing', $data);
    }
}

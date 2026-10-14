<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Landing pública de la demo por lead (prompt 213).
 *
 * 🔴 RETIRADA el 14/10/2026 (misión demo-solo-experiencia-nueva): hoy solo redirige a la página de
 * experiencia del lead. El texto de abajo describe lo que hacía antes y se deja como historia.
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

        // 🔴 Landing RETIRADA (misión demo-solo-experiencia-nueva, 14/10/2026). Esta página
        // mostraba el link del sistema, el usuario y la contraseña de la demo (doc_number) a
        // cualquiera que tuviera el uuid, SIN pasar por la página de experiencia ni por su
        // formulario: justo lo que Lucas pidió que no pueda pasar. Los uuid ya viajaron en el
        // Mail 1 y por WhatsApp de la dinámica vieja, así que la ruta no se borra: esos links
        // viejos siguen resolviendo y llevan al lead a su página de experiencia, que es la única
        // puerta de entrada a la demo.
        $url_experiencia = $lead->demo_experiencia_url;
        if (empty($url_experiencia)) {
            // Sin URL de experiencia armable (ADMIN_SPA_URL sin configurar): antes de mostrar
            // credenciales se prefiere un 404.
            abort(404);
        }

        Log::info('DemoLandingController@show: link viejo de landing redirigido a la experiencia.', [
            'lead_id' => $lead->id,
        ]);

        return redirect()->away($url_experiencia);
    }
}

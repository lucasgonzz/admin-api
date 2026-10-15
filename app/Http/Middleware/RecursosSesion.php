<?php

namespace App\Http\Middleware;

use App\Models\RecursosSesion as RecursosSesionModelo;
use App\Models\RecursosUsuario;
use Closure;
use Illuminate\Http\Request;

/**
 * La sesión del centro de recursos (`recursos.sesion`): exige un token del centro válido en
 * `Authorization: Bearer <token>` y deja al usuario en `$request->attributes->get('recursos_usuario')`.
 * Sin token válido, 401 `{error: 'sin_sesion'}` (contrato C2 de la misión centro-de-recursos-clientes).
 *
 * 🔴 POR QUÉ NO ES SANCTUM, y por qué no hay que "simplificarlo" a `auth:sanctum`:
 * el `auth:sanctum` del admin resuelve tokens de `personal_access_tokens`, que es POLIMÓRFICA. Si un
 * `RecursosUsuario` tuviera tokens de Sanctum (`HasApiTokens` + `createToken()`), ese token pasaría
 * el `auth:sanctum` de TODAS las rutas del panel (`admin/*`): `$request->user()` dejaría de ser un
 * `Admin` y cualquier usuario de cualquier cliente entraría a leads, clientes, deploys y claves. Por
 * eso la sesión del centro vive en su propia tabla (`recursos_sesiones`), con su propio middleware, y
 * el usuario NO se pone en `$request->user()` sino en un atributo del request: nada que lea
 * `$request->user()` (el panel, los limitadores, las policies) puede confundirlo con un admin. Hay un
 * test que lo cuida (`SesionDelCentroTest::test_un_token_del_centro_no_entra_al_panel`).
 *
 * El hermano `RecursosSesionOpcional` (`recursos.sesion.opcional`) hace lo mismo pero, sin token o
 * con uno que no sirve, deja pasar al pedido como invitado.
 */
class RecursosSesion
{
    /**
     * Atributo del request donde queda el usuario del centro.
     */
    const ATRIBUTO_USUARIO = 'recursos_usuario';

    /**
     * Atributo del request donde queda la sesión (la usa `recursos/salir`).
     */
    const ATRIBUTO_SESION = 'recursos_sesion';

    /**
     * Cada cuánto, como mucho, se escribe `last_used_at` (en segundos). La página pega cada ~10 s
     * mientras se reproduce un video: escribir la sesión en cada pedido sería una escritura más por
     * cada avance, para un dato que solo se mira de a días.
     */
    const REFRESCO_DE_USO = 300;

    /**
     * @param Request $request
     * @param Closure $next
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $this->resolver($request)) {
            return response()->json(['error' => 'sin_sesion'], 401);
        }

        return $next($request);
    }

    /**
     * Busca la sesión del token del pedido y, si sirve, deja usuario y sesión en el request.
     *
     * @param Request $request
     *
     * @return bool true si había una sesión válida.
     */
    protected function resolver(Request $request): bool
    {
        $token = self::token_del_pedido($request);

        if ($token === '') {
            return false;
        }

        $sesion = RecursosSesionModelo::where('token_hash', hash('sha256', $token))->first();

        if (! $sesion instanceof RecursosSesionModelo) {
            return false;
        }

        if ($sesion->expires_at === null || $sesion->expires_at->isPast()) {
            return false;
        }

        $usuario = RecursosUsuario::with('client')->find($sesion->recursos_usuario_id);

        if (! $usuario instanceof RecursosUsuario) {
            return false;
        }

        if ($sesion->last_used_at === null || $sesion->last_used_at->lt(now()->subSeconds(self::REFRESCO_DE_USO))) {
            // Por el query builder: no hay por qué disparar eventos ni tocar `updated_at` por esto.
            RecursosSesionModelo::where('id', $sesion->id)->update(['last_used_at' => now()]);
        }

        $request->attributes->set(self::ATRIBUTO_USUARIO, $usuario);
        $request->attributes->set(self::ATRIBUTO_SESION, $sesion);

        return true;
    }

    /**
     * El token del centro que trae el pedido (`Authorization: Bearer ...`), o vacío.
     *
     * Público y estático porque lo usa también el limitador (`RecursosThrottle`) para armar la
     * cubeta por token.
     *
     * @param Request $request
     *
     * @return string
     */
    public static function token_del_pedido(Request $request): string
    {
        $token = trim((string) $request->bearerToken());

        // Un token del centro son 64 caracteres; algo muy largo no se busca ni se hashea.
        if ($token === '' || strlen($token) > 200) {
            return '';
        }

        return $token;
    }
}

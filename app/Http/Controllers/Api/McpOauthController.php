<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Mcp\McpOauthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Servidor de autorización OAuth 2.1 del conector MCP (misión dots-openclaw, 25/11/2026): registro dinámico,
 * pantalla de consentimiento (con login de admin) y endpoint de token.
 *
 * Delgado a propósito: la validación, los códigos y los tokens están en {@see McpOauthService}. Acá sólo se
 * resuelve lo HTTP y el límite de intentos del login del consentimiento. Con `mcp.enabled = false` todo da 404.
 */
class McpOauthController extends Controller
{
    /** Hash bcrypt (costo 10) de una clave descartada: relleno del login cuando el email no existe. */
    const HASH_DE_RELLENO = '$2y$10$sPCFs.MRIypc2hu2bcCG3.ufxNwwWRBRvsu1jL/ycZNG7uhUO6JGu';

    /** @var McpOauthService */
    private $oauth;

    /**
     * @param McpOauthService $oauth Lógica OAuth.
     */
    public function __construct(McpOauthService $oauth)
    {
        $this->oauth = $oauth;
    }

    /**
     * `POST /api/mcp/oauth/register`: registro dinámico de un cliente (DCR).
     *
     * @param Request $request Cuerpo JSON `{redirect_uris, client_name?, …}`.
     *
     * @return JsonResponse 201 con el cliente, o 400 `{error, error_description}`.
     */
    public function registrar_json(Request $request): JsonResponse
    {
        if (! $this->oauth->habilitado()) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        $entrada   = $request->isJson() ? $request->json()->all() : $request->request->all();
        $resultado = $this->oauth->registrar_cliente(is_array($entrada) ? $entrada : []);

        if (! $resultado['ok']) {
            return response()->json(['error' => $resultado['error'], 'error_description' => $resultado['descripcion']], 400);
        }

        return response()->json($resultado['cliente'], 201, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * `POST /api/mcp/oauth/token`: canje del código o del refresh token por tokens.
     *
     * @param Request $request Cuerpo form-urlencoded (o JSON). Nunca se leen parámetros de la query.
     *
     * @return JsonResponse
     */
    public function token_json(Request $request): JsonResponse
    {
        if (! $this->oauth->habilitado()) {
            return response()->json(['message' => 'No encontrado.'], 404);
        }

        $entrada   = $request->isJson() ? $request->json()->all() : $request->request->all();
        $resultado = $this->oauth->atender_token(is_array($entrada) ? $entrada : []);

        return response()->json($resultado['body'], $resultado['status'], ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * `GET /mcp/oauth/authorize`: valida el pedido y muestra la pantalla de consentimiento.
     *
     * @param Request $request Pedido de autorización (query).
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function pantalla(Request $request)
    {
        if (! $this->oauth->habilitado()) {
            abort(404);
        }

        $pedido = $this->oauth->validar_pedido_autorizacion($request->query());
        $previa = $this->respuesta_de_error_del_pedido($pedido);
        if ($previa !== null) {
            return $previa;
        }

        return $this->vista($pedido, 200, null);
    }

    /**
     * `POST /mcp/oauth/authorize`: el formulario de la pantalla (login + Permitir / No permitir).
     *
     * Se revalida el pedido entero (nada del formulario se da por bueno). "No permitir" vuelve al cliente con
     * `access_denied`. "Permitir" exige email y contraseña de un admin que pase la política; con credenciales
     * malas vuelve a mostrar la pantalla (422) y los intentos fallidos se limitan por email + IP.
     *
     * @param Request $request Formulario.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function decidir(Request $request)
    {
        if (! $this->oauth->habilitado()) {
            abort(404);
        }

        $pedido = $this->oauth->validar_pedido_autorizacion($request->request->all());
        $previa = $this->respuesta_de_error_del_pedido($pedido);
        if ($previa !== null) {
            return $previa;
        }
        $parametros = $pedido['parametros'];

        // "No permitir": no hace falta login para negarse.
        if ($this->campo($request, 'decision') !== 'allow') {
            return $this->volver($parametros, ['error' => 'access_denied', 'error_description' => 'El usuario no permitió el acceso.']);
        }

        // Límite de intentos fallidos por email + IP (además del throttle de la ruta).
        // El usuario se busca tal cual (sin pasarlo a minúsculas), igual que el login del admin: hay admins cuyo
        // "email" es un nombre de usuario ("Lucas"). El límite de intentos sí usa minúsculas para no duplicar claves.
        $email = trim($this->campo($request, 'email'));
        $clave = 'mcp-login:' . sha1(strtolower($email) . '|' . $request->ip());
        $maximo = max(1, (int) config('mcp.login_max_intentos', 5));
        if (RateLimiter::tooManyAttempts($clave, $maximo)) {
            return $this->vista($pedido, 429, 'Demasiados intentos. Esperá unos minutos y probá de nuevo.', $email);
        }

        $admin = $email === '' ? null : Admin::where('email', $email)->first();
        $clave_ingresada = $this->campo($request, 'password');
        // Siempre exactamente un Hash::check, con el hash del admin o con uno de relleno del mismo costo (si el email
        // no existe, o si la clave viene vacía), para que el tiempo no revele si el email existe ni si hubo clave.
        $hash = $admin !== null ? (string) $admin->password : self::HASH_DE_RELLENO;
        $verificada = Hash::check($clave_ingresada, $hash);
        $coincide = $clave_ingresada !== '' && $verificada;

        if ($admin === null || ! $coincide) {
            RateLimiter::hit($clave, max(1, (int) config('mcp.login_bloqueo_min', 15)) * 60);

            return $this->vista($pedido, 422, 'Email o contraseña incorrectos.', $email);
        }
        RateLimiter::clear($clave);

        if (! $this->oauth->admin_autorizado($admin)) {
            return $this->vista($pedido, 403, 'Tu usuario no puede conectar el conector.', $email);
        }

        $codigo   = $this->oauth->emitir_codigo($pedido['cliente'], $admin, $parametros);
        $retorno = ['code' => $codigo];

        return $this->volver($parametros, $retorno);
    }

    /**
     * Un campo del formulario (sólo del cuerpo, nunca de la query) como texto; vacío si falta o no es escalar.
     *
     * @param Request $request Pedido.
     * @param string  $nombre  Nombre del campo.
     *
     * @return string
     */
    private function campo(Request $request, $nombre)
    {
        $valor = $request->request->get($nombre);

        return is_string($valor) ? $valor : '';
    }

    /**
     * La respuesta que corresponde a un pedido con error, o null si el pedido es válido.
     *
     * - Error fatal (cliente, redirect o response_type malos): página 400, NUNCA redirige.
     * - Error con redirect válido: 302 al redirect con `error`, `state` e `iss`.
     *
     * @param array<string, mixed> $pedido Resultado de `validar_pedido_autorizacion()`.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse|null
     */
    private function respuesta_de_error_del_pedido(array $pedido)
    {
        if ($pedido['fatal'] !== null) {
            return $this->cabeceras_de_pantalla(response()->view('mcp.authorize', [
                'fatal' => $pedido['fatal'], 'cliente' => null, 'parametros' => [], 'host' => '', 'error' => null, 'email' => '',
            ], 400));
        }
        if ($pedido['error'] !== null) {
            return $this->volver($pedido['parametros'], [
                'error' => $pedido['error']['error'], 'error_description' => $pedido['error']['descripcion'],
            ]);
        }

        return null;
    }

    /**
     * Redirige de vuelta al cliente con los parámetros dados, más `state` (si vino) e `iss`.
     *
     * @param array<string, string> $parametros Parámetros del pedido ya validado.
     * @param array<string, string> $resultado  `code` o `error` (+ descripción).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    private function volver(array $parametros, array $resultado)
    {
        if ($parametros['state'] !== '') {
            $resultado['state'] = $parametros['state'];
        }

        return $this->cabeceras_de_pantalla(redirect()->away($this->oauth->url_de_retorno($parametros['redirect_uri'], $resultado)));
    }

    /**
     * Muestra la pantalla de consentimiento.
     *
     * @param array<string, mixed> $pedido Pedido ya validado.
     * @param int                  $status Estado HTTP.
     * @param string|null          $error  Mensaje para mostrar arriba del formulario.
     * @param string               $email  Email a precargar.
     *
     * @return \Illuminate\Http\Response
     */
    private function vista(array $pedido, $status, $error, $email = '')
    {
        $host = (string) parse_url($pedido['parametros']['redirect_uri'], PHP_URL_HOST);

        return $this->cabeceras_de_pantalla(response()->view('mcp.authorize', [
            'fatal'      => null,
            'cliente'    => $pedido['cliente'],
            'parametros' => $pedido['parametros'],
            'host'       => $host,
            'error'      => $error,
            'email'      => $email,
        ], $status));
    }

    /**
     * Cabeceras de una pantalla o redirección del flujo: sin cache, sin referrer y sin poder embeberse en un
     * marco (clickjacking sobre el botón "Permitir").
     *
     * @param \Symfony\Component\HttpFoundation\Response $respuesta Respuesta a completar.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    private function cabeceras_de_pantalla($respuesta)
    {
        $respuesta->headers->set('Cache-Control', 'no-store');
        $respuesta->headers->set('Pragma', 'no-cache');
        $respuesta->headers->set('Referrer-Policy', 'no-referrer');
        $respuesta->headers->set('X-Frame-Options', 'DENY');
        $respuesta->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $respuesta;
    }
}

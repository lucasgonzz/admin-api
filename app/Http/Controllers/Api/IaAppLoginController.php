<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IaApp\CredencialDeLogin;
use App\Services\IaApp\IaDirectorioService;
use App\Services\IaApp\IaLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * `POST api/ia/login`: el login PÚBLICO de la app `ia.comerciocity.com` (contrato C4.1 de la misión cruzada
 * `app-ia-y-memoria`, 29/10/2026). La lógica vive en `IaLoginService`; acá van la validación, los topes y la
 * forma de la respuesta (`{code, message}` en todo error).
 *
 * Topes (contrato C4.1, paso 4): 10 por minuto por IP y 5 por minuto por usuario normalizado. Se cuentan
 * TODOS los intentos, buenos y malos: la persona que entra bien no llega nunca a cinco por minuto, y el que
 * prueba contraseñas sí. La cubeta del usuario se arma con el hash del directorio, no con el usuario en claro.
 * Limitador propio y no `throttle:` porque los limitadores con nombre viven en `RouteServiceProvider` y uno
 * definido en las rutas no sobrevive a `route:cache` (mismo motivo que `RecursosThrottle`).
 *
 * 🔴 La contraseña no se guarda, no se loguea y no vuelve en ninguna respuesta: la validación de Laravel no
 * repite valores, y `password` está en `$dontFlash` del Handler.
 */
class IaAppLoginController extends Controller
{
    /**
     * @param Request        $request
     * @param IaLoginService $login
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request, IaLoginService $login)
    {
        /* 🔴 Sin argumentos en las trazas mientras dura el login: una excepción en el medio copiaría en su traza
           los argumentos de cada función (PHP 7.4 muestra tal cual los strings de hasta 15 caracteres) y la
           traza termina en laravel.log. Se vuelve al valor de antes al salir. */
        $ignorar_antes = ini_get('zend.exception_ignore_args');
        @ini_set('zend.exception_ignore_args', '1');

        try {
            return $this->login_sin_argumentos_en_las_trazas($request, $login);
        } finally {
            if ($ignorar_antes !== false) {
                @ini_set('zend.exception_ignore_args', (string) $ignorar_antes);
            }
        }
    }

    /**
     * @param Request        $request
     * @param IaLoginService $login
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function login_sin_argumentos_en_las_trazas(Request $request, IaLoginService $login)
    {
        $cubeta_ip = 'ia-login|ip|' . sha1((string) $request->ip());

        if ($this->agotada($cubeta_ip, (int) config('ia_app.login.por_ip_por_minuto', 10))) {
            return $this->demasiados_intentos($cubeta_ip);
        }

        $validador = Validator::make($request->all(), [
            'usuario'     => 'required|string|max:64',
            'password'    => 'required|string|max:255',
            'negocio'     => 'nullable|string|max:255',
            'dispositivo' => 'nullable|string|max:100',
        ], [
            'required' => 'Falta :attribute.',
            'string'   => ':attribute tiene que ser texto.',
            'max'      => ':attribute es demasiado largo.',
        ]);

        if ($validador->fails()) {
            return response()->json([
                'code'    => 'validacion',
                'message' => 'Revisá el usuario y la contraseña.',
                'errores' => $validador->errors()->toArray(),
            ], 422);
        }

        $usuario = trim((string) $request->input('usuario'));
        $hash    = IaDirectorioService::hash_usuario($usuario);

        if ($hash === null) {
            return response()->json(['code' => 'validacion', 'message' => 'Revisá el usuario y la contraseña.'], 422);
        }

        $cubeta_usuario = 'ia-login|usuario|' . $hash;

        if ($this->agotada($cubeta_usuario, (int) config('ia_app.login.por_usuario_por_minuto', 5))) {
            return $this->demasiados_intentos($cubeta_usuario);
        }

        // La contraseña viaja envuelta desde acá (ver CredencialDeLogin).
        $credencial = new CredencialDeLogin((string) $request->input('password'));

        try {
            $resultado = $login->resolver(
                $usuario,
                $credencial,
                $request->input('negocio'),
                $this->dispositivo($request)
            );
        } catch (\Throwable $e) {
            /* 🔴 Un error inesperado (la base caída, una tabla que falta) NO sigue al Handler: se anota acá, sin
               traza y con la contraseña tapada por si el mensaje la trajera, y la persona ve el 502 de siempre. */
            Log::error('IaAppLoginController: el login se cortó por un error inesperado.', [
                'clase'   => get_class($e),
                'mensaje' => mb_substr(str_replace($credencial->valor() === '' ? [] : [$credencial->valor()], CredencialDeLogin::TAPADA, (string) $e->getMessage()), 0, 500),
                'donde'   => basename((string) $e->getFile()) . ':' . (int) $e->getLine(),
            ]);

            $resultado = $login->sistema_no_disponible();
        }

        return response()->json($resultado['cuerpo'], $resultado['http']);
    }

    /**
     * Suma el intento a la cubeta y dice si ya estaba agotada.
     *
     * @param string $cubeta
     * @param int    $maximo
     *
     * @return bool
     */
    protected function agotada($cubeta, $maximo)
    {
        $maximo = max(1, $maximo);

        if (RateLimiter::tooManyAttempts($cubeta, $maximo)) {
            return true;
        }

        RateLimiter::hit($cubeta, 60);

        return false;
    }

    /**
     * @param string $cubeta
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function demasiados_intentos($cubeta)
    {
        $segundos = max(1, (int) RateLimiter::availableIn($cubeta));

        return response()->json([
            'code'          => 'demasiados_intentos',
            'message'       => 'Hiciste muchos intentos seguidos. Esperá un minuto y probá de nuevo.',
            'reintentar_en' => $segundos,
        ], 429)->header('Retry-After', (string) $segundos);
    }

    /**
     * El nombre del dispositivo para el token (`ia-app:<dispositivo>`): el que mandó la app, o uno grueso
     * sacado del User-Agent. Nunca el User-Agent entero.
     *
     * @param Request $request
     *
     * @return string
     */
    protected function dispositivo(Request $request)
    {
        $pedido = trim((string) $request->input('dispositivo'));

        if ($pedido !== '') {
            return mb_substr($pedido, 0, 100);
        }

        $agente = strtolower((string) $request->userAgent());

        foreach (['iphone' => 'iPhone', 'ipad' => 'iPad', 'android' => 'Android', 'windows' => 'Windows', 'mac os' => 'Mac', 'linux' => 'Linux'] as $marca => $nombre) {
            if (strpos($agente, $marca) !== false) {
                return $nombre;
            }
        }

        return 'navegador';
    }
}

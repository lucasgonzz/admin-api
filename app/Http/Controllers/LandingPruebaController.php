<?php

namespace App\Http\Controllers;

use App\Helpers\WhatsappNormalizer;
use App\Services\DemoIndustriaCatalogo;
use App\Services\LeadAtribucionService;
use App\Services\Prueba\AltaDePruebaService;
use App\Services\Prueba\PruebaEstadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Los dos endpoints públicos de la prueba gratuita desde la landing (contrato C6 del programa «Prueba
 * gratuita de 7 días», misión `prueba-alta-instancia`, 3/11/2026). Sin credenciales, desde
 * comerciocity.com, con throttle propio por IP (`RouteServiceProvider`).
 *
 *  - `POST /api/landing/prueba/alta` `{ nombre, telefono, email, empresa, password, rubro?, utm_*?,
 *    atribucion?, fbp?, fbc?, event_source_url?, sitio_web, abierto_hace_ms }` → 201 `{ uuid, meta_eventos }`;
 *    409 `{ code, mensaje }` con `code` en `ya_es_cliente | ya_tiene_prueba | telefono_invalido | sin_capacidad`;
 *    422 `{ mensaje, errores: {campo: [texto]} }`.
 *  - `GET /api/landing/prueba/{uuid}/estado` → 200 `{ estado, etapa, progreso, url_ingreso, video_url,
 *    whatsapp_soporte, mensaje }`; 404 si el uuid no es de una prueba.
 *
 * Anti-spam sin captcha, calcado de `LandingAgendaController` (D13 de aquella misión): honeypot
 * `sitio_web` y `abierto_hace_ms` con 422 GENÉRICO, validación estricta con mensajes en castellano. La
 * diferencia con la agenda, pedida por M5 (ESTADO.md, 3/11): las claves que el admin NO conoce se
 * IGNORAN, no dan 422 (la landing manda `utm_*` sueltos y lo que el píxel tenga a mano).
 *
 * `fbp`, `fbc` y `event_source_url` son datos de medición: fuera de formato se descartan en silencio.
 */
class LandingPruebaController extends Controller
{
    /** Formato de las cookies `_fbp` / `_fbc` del píxel. */
    const REGEX_COOKIE_FB = '/^fb\.\d\.\d+\.[A-Za-z0-9_\-\.]+$/';

    /** Claves de atribución que la landing manda sueltas y acá se juntan en `atribucion`. */
    const CLAVES_UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid'];

    /**
     * `POST /api/landing/prueba/alta`.
     */
    public function alta(Request $request, AltaDePruebaService $service): JsonResponse
    {
        $cuerpo = $request->all();

        if (array_key_exists('sitio_web', $cuerpo) && $cuerpo['sitio_web'] !== null
            && (! is_string($cuerpo['sitio_web']) || trim($cuerpo['sitio_web']) !== '')) {
            return $this->rechazo_generico('honeypot');
        }
        $abierto = isset($cuerpo['abierto_hace_ms']) && is_scalar($cuerpo['abierto_hace_ms'])
            ? filter_var($cuerpo['abierto_hace_ms'], FILTER_VALIDATE_INT)
            : false;
        if ($abierto === false || $abierto < (int) config('landing_agenda.abierto_minimo_ms', 2500)) {
            return $this->rechazo_generico('abierto_hace_ms');
        }

        $reglas_atribucion = LeadAtribucionService::reglas_de_los_datos();
        $atribucion        = isset($cuerpo['atribucion']) && is_array($cuerpo['atribucion']) ? $cuerpo['atribucion'] : [];
        foreach (self::CLAVES_UTM as $clave) {
            if (isset($cuerpo[$clave]) && is_scalar($cuerpo[$clave]) && ! isset($atribucion[$clave]) && array_key_exists($clave, $reglas_atribucion)) {
                $atribucion[$clave] = (string) $cuerpo[$clave];
            }
        }
        /* Una clave de atribución que el admin no conoce se descarta (no 422). */
        $atribucion = array_intersect_key($atribucion, $reglas_atribucion);

        $datos_a_validar = [
            'nombre'     => isset($cuerpo['nombre']) ? $cuerpo['nombre'] : null,
            'telefono'   => isset($cuerpo['telefono']) ? $cuerpo['telefono'] : null,
            'email'      => isset($cuerpo['email']) ? $cuerpo['email'] : null,
            'empresa'    => isset($cuerpo['empresa']) ? $cuerpo['empresa'] : null,
            'password'   => isset($cuerpo['password']) ? $cuerpo['password'] : null,
            'rubro'      => isset($cuerpo['rubro']) && $cuerpo['rubro'] !== '' ? $cuerpo['rubro'] : null,
            'atribucion' => $atribucion,
        ];

        $reglas = [
            'nombre'     => ['required', 'string', 'min:2', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/'],
            'telefono'   => ['required', 'string', 'max:30'],
            'email'      => ['required', 'string', 'email:filter', 'max:120'],
            'empresa'    => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[\x00-\x1F\x7F]/'],
            'password'   => ['required', 'string', 'min:8', 'max:72'],
            'rubro'      => ['nullable', 'string', 'in:' . implode(',', array_keys(DemoIndustriaCatalogo::OPCIONES))],
            'atribucion' => ['nullable', 'array'],
        ];
        foreach ($reglas_atribucion as $campo => $regla) {
            $reglas['atribucion.' . $campo] = $regla;
        }

        $validador = Validator::make($datos_a_validar, $reglas, self::mensajes(), [
            'nombre'   => 'tu nombre',
            'telefono' => 'tu WhatsApp',
            'email'    => 'tu correo',
            'empresa'  => 'el nombre de tu negocio',
            'password' => 'la contraseña',
            'rubro'    => 'el rubro',
        ]);

        $telefono_invalido = false;
        $validador->after(function ($v) use ($datos_a_validar, &$telefono_invalido) {
            if (isset($datos_a_validar['telefono']) && is_string($datos_a_validar['telefono'])
                && ! preg_match('/^\+549\d{10}$/', WhatsappNormalizer::normalize($datos_a_validar['telefono']))) {
                $telefono_invalido = true;
                $v->errors()->add('telefono', 'Revisá el WhatsApp: tiene que ser un celular de Argentina con código de área.');
            }
        });

        if ($validador->fails()) {
            /* El teléfono que no es un celular argentino es un 409 del contrato (`telefono_invalido`) cuando es
               lo ÚNICO que falla; con otros errores encima, 422 con todos juntos. */
            if ($telefono_invalido && count($validador->errors()->keys()) === 1) {
                return response()->json([
                    'code'    => 'telefono_invalido',
                    'codigo'  => 'telefono_invalido',
                    'mensaje' => 'Revisá el WhatsApp: tiene que ser un celular de Argentina con código de área.',
                    'errores' => $validador->errors()->toArray(),
                ], 409);
            }

            return response()->json([
                'mensaje' => 'Revisá los datos marcados.',
                'errores' => $validador->errors()->toArray(),
            ], 422);
        }

        $datos               = $validador->validated();
        $datos['nombre']     = trim((string) preg_replace('/\s+/u', ' ', (string) $datos['nombre']));
        $datos['empresa']    = trim((string) preg_replace('/\s+/u', ' ', (string) $datos['empresa']));
        $datos['atribucion'] = LeadAtribucionService::sin_vacios(isset($datos['atribucion']) && is_array($datos['atribucion']) ? $datos['atribucion'] : []);

        $contexto_web = [
            'client_ip_address' => (string) $request->ip(),
            'client_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'fbp'               => self::cookie_fb_valida(isset($cuerpo['fbp']) ? $cuerpo['fbp'] : null),
            'fbc'               => self::cookie_fb_valida(isset($cuerpo['fbc']) ? $cuerpo['fbc'] : null),
            'event_source_url'  => self::url_de_origen_valida(isset($cuerpo['event_source_url']) ? $cuerpo['event_source_url'] : null),
        ];

        try {
            $resultado = $service->alta($datos, $contexto_web);
        } catch (\Throwable $e) {
            Log::error('LandingPrueba: el alta falló.', ['error' => $e->getMessage()]);

            return response()->json([
                'mensaje' => 'No pudimos crear tu prueba en este momento. Escribinos por WhatsApp y la armamos juntos.',
            ], 503);
        }

        return response()->json($resultado['cuerpo'], $resultado['http']);
    }

    /**
     * `GET /api/landing/prueba/{uuid}/estado`.
     */
    public function estado(string $uuid, PruebaEstadoService $service): JsonResponse
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) !== 1) {
            return response()->json(['mensaje' => 'No encontramos esa prueba.'], 404);
        }

        $estado = $service->estado($uuid);
        if ($estado === null) {
            return response()->json(['mensaje' => 'No encontramos esa prueba.'], 404);
        }

        return response()->json($estado, 200);
    }

    private static function cookie_fb_valida($valor): string
    {
        if (! is_string($valor)) {
            return '';
        }
        $valor = trim($valor);

        return $valor !== '' && strlen($valor) <= 255 && preg_match(self::REGEX_COOKIE_FB, $valor) ? $valor : '';
    }

    private static function url_de_origen_valida($valor): string
    {
        if (! is_string($valor)) {
            return '';
        }
        $valor = trim($valor);
        if ($valor === '' || strlen($valor) > 500 || filter_var($valor, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $esquema = strtolower((string) parse_url($valor, PHP_URL_SCHEME));
        $host    = strtolower((string) parse_url($valor, PHP_URL_HOST));
        $hosts   = array_map('strtolower', (array) config('landing_agenda.hosts_de_origen', []));

        return in_array($esquema, ['http', 'https'], true) && in_array($host, $hosts, true) ? $valor : '';
    }

    private function rechazo_generico(string $motivo): JsonResponse
    {
        Log::channel('daily')->info('LandingPrueba: alta rechazada por el filtro anti-bot.', ['motivo' => $motivo]);

        return response()->json([
            'mensaje' => 'No pudimos tomar el pedido. Probá de nuevo en unos segundos.',
            'errores' => new \stdClass(),
        ], 422);
    }

    private static function mensajes(): array
    {
        return [
            'required'  => 'Completá :attribute.',
            'string'    => 'Revisá :attribute.',
            'min'       => 'Revisá :attribute: es demasiado corto.',
            'max'       => 'Revisá :attribute: es demasiado largo.',
            'email'     => 'Revisá el correo: no parece una dirección válida.',
            'in'        => 'Elegí un rubro de la lista.',
            'regex'     => 'Revisá :attribute.',
            'not_regex' => 'Revisá :attribute.',
            'array'     => 'Revisá :attribute.',
        ];
    }
}

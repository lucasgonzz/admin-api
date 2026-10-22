<?php

namespace App\Http\Controllers;

use App\Helpers\WhatsappNormalizer;
use App\Services\DemoIndustriaCatalogo;
use App\Services\LandingAgendaService;
use App\Services\LeadAtribucionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Los dos endpoints públicos de la demo autogestionada desde la landing (misión
 * demo-autogestionada-landing, 22/10/2026). Sin credenciales, desde comerciocity.com, con throttle
 * propio por IP (`RouteServiceProvider`).
 *
 * El contrato con la landing está en el plan de la misión (§3) y NO se cambia de este lado sin
 * cambiar la landing: nombres de campos, códigos del 409 y formas de respuesta.
 *
 * 🔴 Anti-spam sin captcha (D13), en este orden, todo antes de tocar la base:
 *   1. Lista blanca estricta de claves, arriba y adentro de `atribucion`: cualquier otra → 422. Un
 *      formulario público que acepta claves de más es una puerta para escribir columnas que nadie
 *      pensó exponer.
 *   2. Honeypot `sitio_web` (un campo que una persona no ve y un bot llena) y `abierto_hace_ms` (un
 *      formulario completo en menos de 2,5 s no lo llenó una persona): 422 GENÉRICO, sin decir qué
 *      falló, para no enseñarle al bot qué corregir.
 *   3. Validación estricta de cada campo, con los mensajes en castellano para el visitante.
 */
class LandingAgendaController extends Controller
{
    /** Claves admitidas en el cuerpo de la reserva (contrato con la landing). */
    const CLAVES_ADMITIDAS = [
        'nombre', 'whatsapp', 'email', 'rubro', 'fecha', 'hora', 'atribucion',
        'fbp', 'fbc', 'event_source_url', 'sitio_web', 'abierto_hace_ms',
    ];

    /** Formato de las cookies `_fbp` / `_fbc` del píxel. */
    const REGEX_COOKIE_FB = '/^fb\.\d\.\d+\.[A-Za-z0-9_\-\.]+$/';

    /**
     * `GET /api/landing/agenda/turnos`.
     *
     * @param LandingAgendaService $service
     *
     * @return JsonResponse
     */
    public function turnos(LandingAgendaService $service): JsonResponse
    {
        try {
            return response()->json($service->turnos(), 200);
        } catch (\Throwable $e) {
            Log::error('LandingAgenda: no se pudieron calcular los turnos.', ['error' => $e->getMessage()]);

            return response()->json([
                'mensaje' => 'No pudimos cargar los turnos en este momento. Escribinos por WhatsApp y la coordinamos.',
            ], 503);
        }
    }

    /**
     * `POST /api/landing/agenda/reservas`.
     *
     * @param Request              $request
     * @param LandingAgendaService $service
     *
     * @return JsonResponse
     */
    public function reservar(Request $request, LandingAgendaService $service): JsonResponse
    {
        $cuerpo = $request->all();

        if (array_diff(array_keys($cuerpo), self::CLAVES_ADMITIDAS)) {
            return $this->campos_no_admitidos();
        }
        $reglas_atribucion = LeadAtribucionService::reglas_de_los_datos();
        if (isset($cuerpo['atribucion']) && is_array($cuerpo['atribucion'])
            && array_diff(array_keys($cuerpo['atribucion']), array_keys($reglas_atribucion))) {
            return $this->campos_no_admitidos();
        }

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

        $reglas = [
            /* Sin caracteres de control (como la atribución en `emitir`): el nombre va como `{{1}}` de la
               plantilla al lead y del aviso a los admins, y Meta rechaza un parámetro con salto de línea o
               tabulación (no saldría ninguno de los dos). */
            'nombre'           => ['required', 'string', 'min:2', 'max:80', 'not_regex:/[\x00-\x1F\x7F]/'],
            'whatsapp'         => ['required', 'string', 'max:30'],
            'email'            => ['required', 'string', 'email:filter', 'max:120'],
            'rubro'            => ['required', 'string', 'in:' . implode(',', array_keys(DemoIndustriaCatalogo::OPCIONES))],
            'fecha'            => ['required', 'date_format:Y-m-d'],
            'hora'             => ['required', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'atribucion'       => ['nullable', 'array'],
            'fbp'              => ['nullable', 'string', 'max:255', 'regex:' . self::REGEX_COOKIE_FB],
            'fbc'              => ['nullable', 'string', 'max:255', 'regex:' . self::REGEX_COOKIE_FB],
            'event_source_url' => ['nullable', 'string', 'url', 'max:500'],
        ];
        foreach ($reglas_atribucion as $campo => $regla) {
            $reglas['atribucion.' . $campo] = $regla;
        }

        $validador = Validator::make($cuerpo, $reglas, self::mensajes(), [
            'nombre'           => 'tu nombre',
            'whatsapp'         => 'tu WhatsApp',
            'email'            => 'tu correo',
            'rubro'            => 'el rubro',
            'fecha'            => 'la fecha',
            'hora'             => 'la hora',
            'atribucion'       => 'la atribución',
            'event_source_url' => 'la página',
        ]);

        $validador->after(function ($v) use ($cuerpo) {
            /* Móvil argentino: +549 y diez dígitos después de normalizar (el mismo normalizador con el que
               se guarda y se busca el teléfono). Un fijo o un número extranjero no recibe WhatsApp. */
            if (isset($cuerpo['whatsapp']) && is_string($cuerpo['whatsapp'])
                && ! preg_match('/^\+549\d{10}$/', WhatsappNormalizer::normalize($cuerpo['whatsapp']))) {
                $v->errors()->add('whatsapp', 'Revisá el WhatsApp: tiene que ser un celular de Argentina con código de área.');
            }
            if (isset($cuerpo['event_source_url']) && is_string($cuerpo['event_source_url']) && $cuerpo['event_source_url'] !== '') {
                $host = strtolower((string) parse_url($cuerpo['event_source_url'], PHP_URL_HOST));
                if (! in_array($host, array_map('strtolower', (array) config('landing_agenda.hosts_de_origen', [])), true)) {
                    $v->errors()->add('event_source_url', 'La página de origen no es válida.');
                }
            }
        });

        if ($validador->fails()) {
            return response()->json([
                'mensaje' => 'Revisá los datos marcados.',
                'errores' => $validador->errors()->toArray(),
            ], 422);
        }

        $datos = $validador->validated();
        /* Los espacios de más adentro del nombre, colapsados (los de los bordes ya los saca TrimStrings):
           Meta también rechaza un parámetro con más de cuatro espacios seguidos. */
        $datos['nombre'] = trim((string) preg_replace('/\s+/u', ' ', (string) $datos['nombre']));
        $datos['atribucion'] = LeadAtribucionService::sin_vacios(isset($datos['atribucion']) && is_array($datos['atribucion']) ? $datos['atribucion'] : []);

        $contexto_web = [
            'client_ip_address' => (string) $request->ip(),
            'client_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'fbp'               => isset($datos['fbp']) ? (string) $datos['fbp'] : '',
            'fbc'               => isset($datos['fbc']) ? (string) $datos['fbc'] : '',
            'event_source_url'  => isset($datos['event_source_url']) ? (string) $datos['event_source_url'] : '',
        ];

        /* D9: la carta, el WhatsApp, el aviso y Meta van después de responder: el servicio despacha ese
           trabajo apenas la reserva queda escrita (ver `LandingAgendaService::escribir_reserva`). */
        $resultado = $service->reservar($datos, $contexto_web);

        return response()->json($resultado['cuerpo'], $resultado['http']);
    }

    /**
     * @return JsonResponse
     */
    private function campos_no_admitidos(): JsonResponse
    {
        return response()->json(['mensaje' => 'Hay campos no admitidos.', 'errores' => new \stdClass()], 422);
    }

    /**
     * El 422 de los filtros anti-bot: no dice qué filtro saltó (queda en el log).
     *
     * @param string $motivo
     *
     * @return JsonResponse
     */
    private function rechazo_generico(string $motivo): JsonResponse
    {
        Log::channel('daily')->info('LandingAgenda: reserva rechazada por el filtro anti-bot.', ['motivo' => $motivo]);

        return response()->json([
            'mensaje' => 'No pudimos tomar la reserva. Probá de nuevo en unos segundos.',
            'errores' => new \stdClass(),
        ], 422);
    }

    /**
     * Mensajes de validación para el visitante (el idioma de la app es `en`).
     *
     * @return array<string, string>
     */
    private static function mensajes(): array
    {
        return [
            'required'    => 'Completá :attribute.',
            'string'      => 'Revisá :attribute.',
            'min'         => 'Revisá :attribute: es demasiado corto.',
            'max'         => 'Revisá :attribute: es demasiado largo.',
            'email'       => 'Revisá el correo: no parece una dirección válida.',
            'in'          => 'Elegí un rubro de la lista.',
            'date_format' => 'Revisá la fecha del turno.',
            'regex'       => 'Revisá :attribute.',
            'not_regex'   => 'Revisá :attribute.',
            'array'       => 'Revisá :attribute.',
            'url'         => 'La página de origen no es válida.',
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Helpers\WhatsappNormalizer;
use App\Models\WhatsappAdReferral;
use App\Models\WhatsappConfig;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook CRUDO de Meta (modalidad `kind: meta` de Kapso), solo para atribución Click-to-WhatsApp.
 *
 * ¿Por qué existe un segundo webhook si ya hay uno andando? Porque el payload procesado de Kapso
 * tiene campos fijos y NO trae el bloque `referral` ni el `ctwa_clid`. Ese bloque solo viaja en el
 * formato crudo de Meta Cloud API, que Kapso reenvía como una modalidad aparte y que CONVIVE con
 * el webhook que ya está funcionando: los dos reciben el mismo mensaje, cada uno en su formato.
 *
 * 🔴 ESTE ENDPOINT NO PROCESA MENSAJES. No crea leads, no crea tickets, no manda nada, no dispara
 * eventos ni broadcasts. Persiste una fila de atribución y contesta. No lo "unifiques" con
 * {@see WhatsappWebhookController}: son dos formatos distintos del mismo mensaje, y si este camino
 * empezara a crear leads o mensajes cada conversación que entra por un anuncio quedaría duplicada
 * —una vez por cada webhook— sin que nada lo denuncie hasta que el lead conteste dos veces.
 *
 * 🔴 Y NO COMPARTE LA AUTENTICACIÓN CON EL OTRO WEBHOOK. Un webhook `kind: meta` no manda NINGUNA
 * cabecera de firma: Kapso reenvía el payload exacto que recibió de Meta, sin modificar, y agrega
 * solo `Content-Type` y `X-Idempotency-Key`. El `secret_key` con el que se firman los webhooks
 * `kind: kapso` no participa. La primera versión de este controlador verificaba HMAC como el otro,
 * y por eso habría contestado 401 al 100% de las entregas reales dejando la tabla vacía para
 * siempre, sin una sola señal (detectado el 27/8/2026 contra la doc de Kapso). Tampoco sirve el
 * `X-Idempotency-Key` como credencial: es el SHA256 del propio payload, lo calcula cualquiera que
 * pueda armar el body. La credencial es un token secreto en el path de la URL cargada en Kapso.
 */
class MetaRawWebhookController extends Controller
{
    /**
     * Cabecera alternativa para el mismo token, por si se prefiere no ponerlo en la URL.
     */
    private const HEADER_TOKEN = 'X-CC-Webhook-Token';

    /**
     * Recibe el webhook crudo de Meta y guarda la atribución de los mensajes que traen `referral`.
     *
     * Contesta 200 ante cualquier payload AUTENTICADO que no reconozca: un 4xx o un 5xx hace que
     * Kapso reintente el mismo evento en loop, y acá no hay nada que reintentar — si el payload no
     * trae referral, no lo va a traer en el reintento tampoco. El único 4xx es el de autenticación,
     * que sí tiene que ser un rechazo duro.
     *
     * @param Request     $request
     * @param string|null $token   Token secreto que viaja en el path de la URL.
     *
     * @return JsonResponse
     */
    public function receive(Request $request, ?string $token = null): JsonResponse
    {
        // getActive() ya filtra por is_active: sin configuración activa no hay token contra el cual
        // autenticar, así que es el mismo 401 y no un 503. Un 503 haría reintentar a Kapso por algo
        // que ningún reintento va a resolver.
        $config = WhatsappConfig::getActive();
        if ($config === null || ! $this->token_valido($request, $config, $token)) {
            Log::channel('daily')->warning('Webhook Meta crudo: token inválido o ausente.', [
                'ip'                => $request->ip(),
                'token_configurado' => $config !== null && trim((string) $config->meta_webhook_token) !== '',
            ]);

            return response()->json(['message' => 'Invalid token.'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            Log::channel('daily')->warning('Webhook Meta crudo: el body no es un JSON de objeto.');

            return response()->json(['ok' => true, 'referrals' => 0], 200);
        }

        $recorrido = $this->walk_payload($payload);

        $guardados = 0;
        foreach ($recorrido['referrals'] as $referral_row) {
            if ($this->store_referral($referral_row)) {
                $guardados++;
            }
        }

        $this->avisar_si_no_se_reconocio_nada($payload, $recorrido);

        return response()->json(['ok' => true, 'referrals' => $guardados], 200);
    }

    /**
     * Valida el token secreto contra el guardado en la configuración activa.
     *
     * 🔴 FALLA CERRADO. Si la columna está vacía, NINGÚN token entra. Sin esta guarda un secreto
     * sin configurar dejaría el endpoint abierto de par en par — que es exactamente el agujero que
     * tenía la verificación HMAC anterior, donde `hash_hmac(..., '')` lo calcula cualquiera.
     *
     * Se aceptan dos vías y cualquiera de las dos válida alcanza: el token en el path de la URL
     * —la forma que se carga en el panel de Kapso, que no deja mandar cabeceras propias— o la
     * cabecera `X-CC-Webhook-Token`. Siempre con `hash_equals()` y nunca con `===`: una comparación
     * común filtra el token de a un carácter por el tiempo que tarda en cortar.
     *
     * @param Request        $request
     * @param WhatsappConfig $config
     * @param string|null    $token_del_path
     *
     * @return bool
     */
    private function token_valido(Request $request, WhatsappConfig $config, ?string $token_del_path): bool
    {
        $esperado = trim((string) $config->meta_webhook_token);
        if ($esperado === '') {
            return false;
        }

        $candidatos = [
            $token_del_path === null ? '' : trim($token_del_path),
            trim((string) $request->header(self::HEADER_TOKEN)),
        ];

        foreach ($candidatos as $candidato) {
            if ($candidato !== '' && hash_equals($esperado, $candidato)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recorre `entry[].changes[].value` juntando los referrals y contando qué estructuras conocidas
     * apareció el payload.
     *
     * Cada nivel se valida con `is_array()` antes de bajar: el payload lo manda Meta y cambia de
     * forma sin aviso. Una forma inesperada tiene que devolver cero filas, nunca romper.
     *
     * Los contadores no son decorativos: son la única forma de distinguir "vino un mensaje normal
     * sin anuncio" (esperable, silencioso) de "no entendimos el sobre" (grave, se avisa).
     *
     * @param array<string, mixed> $payload Body JSON del webhook crudo.
     *
     * @return array{referrals: array<int, array<string, mixed>>, mensajes: int, statuses: int}
     */
    private function walk_payload(array $payload): array
    {
        $referrals = [];
        $mensajes = 0;
        $statuses = 0;

        $entries = isset($payload['entry']) && is_array($payload['entry']) ? $payload['entry'] : [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : [];
            foreach ($changes as $change) {
                if (! is_array($change) || ! isset($change['value']) || ! is_array($change['value'])) {
                    continue;
                }

                $value = $change['value'];

                // `statuses[]` son los acuses de entrega de los mensajes salientes. Este endpoint no
                // los procesa (de eso ya se ocupa el webhook de Kapso), pero contarlos sirve para
                // saber que el sobre se sigue entendiendo.
                if (isset($value['statuses']) && is_array($value['statuses'])) {
                    $statuses += count($value['statuses']);
                }

                $messages = isset($value['messages']) && is_array($value['messages']) ? $value['messages'] : [];
                foreach ($messages as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $mensajes++;

                    if (! isset($message['referral']) || ! is_array($message['referral'])) {
                        continue;
                    }

                    $fila = $this->build_referral_row($message, $value);
                    if ($fila !== null) {
                        $referrals[] = $fila;
                    }
                }
            }
        }

        return ['referrals' => $referrals, 'mensajes' => $mensajes, 'statuses' => $statuses];
    }

    /**
     * Avisa cuando llegó un POST autenticado del que no se reconoció NINGUNA estructura conocida.
     *
     * 🔴 La condición es "no entendimos el sobre", no "no había referral". Kapso manda acá todos los
     * mensajes y la enorme mayoría no viene de un anuncio: avisar por cada mensaje sin `referral`
     * sería ruido puro y en dos días nadie miraría más el log. Lo que sí tiene que gritar es que no
     * hayamos encontrado ni un `messages[]` ni un `statuses[]` en todo el payload: eso significa que
     * Meta o Kapso cambiaron la forma del envoltorio y la atribución dejó de entrar. Sin este aviso
     * ese cambio se descubre meses después, cuando alguien mira la tabla y está vacía.
     *
     * @param array<string, mixed>                                             $payload   Body recibido.
     * @param array{referrals: array<int, mixed>, mensajes: int, statuses: int} $recorrido Resultado del walk.
     *
     * @return void
     */
    private function avisar_si_no_se_reconocio_nada(array $payload, array $recorrido): void
    {
        if ($recorrido['mensajes'] > 0 || $recorrido['statuses'] > 0) {
            return;
        }

        // Solo las claves de primer nivel y los tamaños: el payload trae teléfonos y textos de
        // conversaciones, y esto va a un log que se lee entero.
        Log::channel('daily')->warning('Webhook Meta crudo: payload autenticado sin ninguna estructura reconocible.', [
            'claves_raiz' => array_keys($payload),
            'entradas'    => isset($payload['entry']) && is_array($payload['entry']) ? count($payload['entry']) : 0,
            'bytes'       => strlen((string) json_encode($payload)),
        ]);
    }

    /**
     * Arma la fila de atribución de un mensaje con `referral`.
     *
     * @param array<string, mixed> $message Mensaje crudo de Meta con su bloque `referral`.
     * @param array<string, mixed> $value   Bloque `value` que lo contiene (trae `contacts[]`).
     *
     * @return array<string, mixed>|null Fila lista para persistir, o null si falta lo mínimo.
     */
    private function build_referral_row(array $message, array $value): ?array
    {
        $wamid = isset($message['id']) ? trim((string) $message['id']) : '';
        if ($wamid === '') {
            // Sin wamid no hay clave de idempotencia: un reintento de Kapso duplicaría la fila.
            return null;
        }

        // El teléfono sale de messages[].from; contacts[].wa_id es el respaldo, igual que en el
        // webhook de Kapso. Se normaliza con el MISMO helper que usa `leads.phone`: si cada punta
        // normalizara distinto, la relación por teléfono del modelo Lead no engancharía nunca.
        $telefono_crudo = isset($message['from']) ? trim((string) $message['from']) : '';
        if ($telefono_crudo === '') {
            $telefono_crudo = $this->first_contact_wa_id($value);
        }

        $phone = WhatsappNormalizer::normalize($telefono_crudo);
        if ($phone === '') {
            return null;
        }

        $referral = $message['referral'];

        return [
            'phone'         => $phone,
            'wamid'         => $wamid,
            'ctwa_clid'     => $this->texto($referral, ['ctwa_clid']),
            'source_id'     => $this->texto($referral, ['source_id']),
            'source_type'   => $this->texto($referral, ['source_type']),
            'source_url'    => $this->texto($referral, ['source_url']),
            'headline'      => $this->texto($referral, ['headline']),
            'body'          => $this->texto($referral, ['body']),
            'media_type'    => $this->texto($referral, ['media_type']),
            // Meta nombra la miniatura distinto según el tipo de creatividad; se aceptan las
            // variantes conocidas en vez de quedarse solo con `thumbnail_url` y perder el dato.
            'thumbnail_url' => $this->texto($referral, ['thumbnail_url', 'image_url', 'video_url', 'media_url']),
            'received_at'   => $this->resolve_received_at($message),
            'raw'           => $referral,
        ];
    }

    /**
     * Primer `wa_id` de `contacts[]`, usado como respaldo del remitente.
     *
     * @param array<string, mixed> $value Bloque `value` del cambio.
     *
     * @return string Teléfono sin normalizar, o cadena vacía.
     */
    private function first_contact_wa_id(array $value): string
    {
        $contacts = isset($value['contacts']) && is_array($value['contacts']) ? $value['contacts'] : [];
        foreach ($contacts as $contact) {
            if (! is_array($contact) || ! isset($contact['wa_id'])) {
                continue;
            }

            $wa_id = trim((string) $contact['wa_id']);
            if ($wa_id !== '') {
                return $wa_id;
            }
        }

        return '';
    }

    /**
     * Primer valor textual no vacío entre varias claves posibles del bloque `referral`.
     *
     * @param array<string, mixed> $referral Bloque `referral` crudo.
     * @param array<int, string>   $claves   Claves a probar, en orden de preferencia.
     *
     * @return string|null Texto limpio, o null si ninguna clave trae algo utilizable.
     */
    private function texto(array $referral, array $claves): ?string
    {
        foreach ($claves as $clave) {
            if (! isset($referral[$clave]) || is_array($referral[$clave]) || is_object($referral[$clave])) {
                continue;
            }

            $valor = trim((string) $referral[$clave]);
            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    /**
     * Momento del mensaje según Meta (`messages[].timestamp`, en segundos epoch).
     *
     * @param array<string, mixed> $message Mensaje crudo.
     *
     * @return Carbon|null Fecha del mensaje, o null si el payload no la trae o es inválida.
     */
    private function resolve_received_at(array $message): ?Carbon
    {
        if (! isset($message['timestamp']) || is_array($message['timestamp'])) {
            return null;
        }

        $timestamp = trim((string) $message['timestamp']);
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return null;
        }

        try {
            return Carbon::createFromTimestamp((int) $timestamp);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Persiste una fila de atribución, sin repetir la que ya está.
     *
     * La idempotencia real la da el índice único de `wamid`, no el chequeo previo: Kapso puede
     * reenviar el mismo evento mientras el primero todavía está escribiendo. El chequeo evita el
     * insert de más en el caso normal; el catch cubre la carrera.
     *
     * @param array<string, mixed> $fila Fila armada por build_referral_row().
     *
     * @return bool true si se creó una fila nueva.
     */
    private function store_referral(array $fila): bool
    {
        try {
            if (WhatsappAdReferral::query()->where('wamid', $fila['wamid'])->exists()) {
                return false;
            }

            WhatsappAdReferral::create($fila);

            return true;
        } catch (QueryException $exception) {
            // 🔴 Se chequea el código del error, no se asume que sea un duplicado. `strict` está en
            // true en config/database.php, así que un valor que no entra en una columna TIRA en vez
            // de truncar y llega acá igual que un duplicado. Tratar todo como duplicado perdía la
            // fila entera —incluido el `raw`, que está justamente para no perder nada— y encima
            // dejaba un log a nivel info diciendo lo contrario de lo que había pasado.
            if ($this->es_error_de_duplicado($exception)) {
                Log::channel('daily')->info('Webhook Meta crudo: referral ya registrado.', [
                    'wamid' => $fila['wamid'],
                ]);

                return false;
            }

            Log::channel('daily')->error('Webhook Meta crudo: la base rechazó la atribución.', [
                'wamid' => $fila['wamid'],
                'error' => $exception->getMessage(),
            ]);

            return false;
        } catch (\Throwable $exception) {
            // Nada de lo que pase acá puede volverse un 500: un 5xx haría que Kapso reintente el
            // mismo evento en loop. Se loguea y se sigue con el resto del payload.
            Log::channel('daily')->error('Webhook Meta crudo: error al guardar la atribución.', [
                'wamid' => $fila['wamid'],
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ¿La excepción es una violación de clave única —el duplicado esperable— y no otra cosa?
     *
     * SQLSTATE 23000 cubre toda la familia de violaciones de integridad (un NOT NULL roto también
     * es 23000), así que no alcanza por sí solo: hace falta el código del driver. 1062 es el
     * "Duplicate entry" de MySQL; 19 es el constraint violation de SQLite, por si algún día la
     * suite corre sobre memoria.
     *
     * @param QueryException $exception
     *
     * @return bool
     */
    private function es_error_de_duplicado(QueryException $exception): bool
    {
        $error_info = $exception->errorInfo;
        $codigo_driver = is_array($error_info) && isset($error_info[1]) ? (int) $error_info[1] : 0;

        return (string) $exception->getCode() === '23000' && in_array($codigo_driver, [1062, 19], true);
    }
}

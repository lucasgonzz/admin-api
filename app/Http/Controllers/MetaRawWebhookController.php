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
 * Por el mismo motivo este archivo no importa nada de WhatsappWebhookController: comparte el
 * secreto de {@see WhatsappConfig} y el esquema de firma, y nada más.
 */
class MetaRawWebhookController extends Controller
{
    /**
     * Recibe el webhook crudo de Meta y guarda la atribución de los mensajes que traen `referral`.
     *
     * Contesta 200 ante cualquier payload que no reconozca: un 4xx o un 5xx hace que Kapso
     * reintente el mismo evento en loop, y acá no hay nada que reintentar — si el payload no trae
     * referral, no lo va a traer en el reintento tampoco.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function receive(Request $request): JsonResponse
    {
        $config = WhatsappConfig::getActive();
        if (! $config || ! $config->is_active) {
            return response()->json(['message' => 'WhatsApp integration unavailable.'], 503);
        }

        // Falla cerrado: sin firma válida no se escribe NADA. Es un endpoint público que persiste
        // datos de atribución; sin esta guarda cualquiera puede ensuciar la medición de campañas.
        if (! $this->verify_signature($request, $config)) {
            Log::channel('daily')->warning('Webhook Meta crudo: firma inválida.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['ok' => true], 200);
        }

        $guardados = 0;
        foreach ($this->extract_referrals($payload) as $referral_row) {
            if ($this->store_referral($referral_row)) {
                $guardados++;
            }
        }

        return response()->json(['ok' => true, 'referrals' => $guardados], 200);
    }

    /**
     * Verifica la firma HMAC-SHA256 del body crudo contra el secreto configurado.
     *
     * 🔴 Es una copia deliberada de WhatsappWebhookController::verify_signature() y no una llamada
     * a él. Los dos webhooks son la MISMA integración de Kapso con el MISMO secreto, así que el
     * esquema tiene que ser idéntico; pero importar el controlador de mensajes desde acá ataría
     * este camino aislado al que procesa mensajes, que es justo lo que no queremos. Si algún día
     * cambia el esquema de firma de Kapso, se cambia en los dos lugares.
     *
     * @param Request        $request
     * @param WhatsappConfig $config
     *
     * @return bool
     */
    private function verify_signature(Request $request, WhatsappConfig $config): bool
    {
        // Kapso documenta X-Webhook-Signature; se acepta también X-Kapso-Signature.
        $signature = (string) ($request->header('X-Kapso-Signature') ?: $request->header('X-Webhook-Signature'));
        if ($signature === '') {
            return false;
        }

        $signature = str_replace('sha256=', '', $signature);
        $expected = hash_hmac('sha256', $request->getContent(), (string) $config->webhook_secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Recorre `entry[].changes[].value.messages[]` y devuelve una fila por mensaje con `referral`.
     *
     * Cada nivel se valida con `is_array()` antes de bajar: el payload lo manda Meta y cambia de
     * forma sin aviso. Una forma inesperada tiene que devolver cero filas, nunca romper.
     *
     * @param array<string, mixed> $payload Body JSON del webhook crudo.
     *
     * @return array<int, array<string, mixed>> Filas listas para persistir.
     */
    private function extract_referrals(array $payload): array
    {
        $filas = [];

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
                $messages = isset($value['messages']) && is_array($value['messages']) ? $value['messages'] : [];
                foreach ($messages as $message) {
                    if (! is_array($message) || ! isset($message['referral']) || ! is_array($message['referral'])) {
                        continue;
                    }

                    $fila = $this->build_referral_row($message, $value);
                    if ($fila !== null) {
                        $filas[] = $fila;
                    }
                }
            }
        }

        return $filas;
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
            // Violación del único de wamid: el evento ya estaba guardado. No es un error.
            Log::channel('daily')->info('Webhook Meta crudo: referral ya registrado.', [
                'wamid' => $fila['wamid'],
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
}

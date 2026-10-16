<?php

namespace App\Services;

/**
 * Proveedor de IA del agente de WhatsApp de los leads (`LeadAiService`): Claude o DeepSeek.
 *
 * Misión leads-agente-deepseek (16/10/2026, pedido de Lucas). Es el ÚNICO lugar del admin que sabe
 * que hay dos proveedores; `LeadAiService` le pregunta a esta clase la URL, la clave, el modelo y
 * el cuerpo de cada llamada y no se entera de cuál está corriendo.
 *
 * 🔴 POR QUÉ NO HAY UN TRADUCTOR DE FORMATOS. DeepSeek publica un endpoint COMPATIBLE CON ANTHROPIC
 * (`{base_url}/v1/messages`, header `x-api-key`; api-docs.deepseek.com/guides/anthropic_api) que
 * acepta el mismo payload que se le manda a Anthropic —system en bloques, tools con `input_schema`,
 * bloques `tool_use` / `tool_result`— e ignora `cache_control`, `anthropic-version` y
 * `anthropic-beta` sin romper. Medido contra la API real en `empresa-api` el 22/9/2026. Por eso el
 * loop de tool use de `LeadAiService::run_with_tools()` es UNO solo y lo único que cambia entre los
 * dos proveedores es lo que sale de acá.
 *
 * 🔴 LA ÚNICA DIFERENCIA DE PAYLOAD ES `thinking`. DeepSeek trae el razonamiento prendido por
 * defecto y hay que decirle explícitamente que no; Anthropic NO recibe ninguna clave `thinking`,
 * exactamente como hasta hoy. Por eso `cuerpo()` suma la clave solo con DeepSeek: con Claude el
 * cuerpo sale idéntico al de antes de esta misión.
 *
 * 🔴 SIN CLAVE DE DEEPSEEK, SE QUEDA EN CLAUDE. `es_deepseek()` exige las dos cosas: que el
 * proveedor configurado sea 'deepseek' (el default) Y que `DEEPSEEK_API_KEY` no esté vacía. Mergear
 * esto no cambia lo que contesta ningún lead: lo prende cargar la clave en el `.env` del admin.
 * No es un detalle: es lo que deja desplegar el código y cargar la clave en dos momentos distintos.
 */
class LeadAiProveedor
{
    /** Proveedor histórico del agente: Claude. */
    const ANTHROPIC = 'anthropic';

    /** El pedido de Lucas del 16/10/2026: DeepSeek por su endpoint compatible con Anthropic. */
    const DEEPSEEK = 'deepseek';

    /** Endpoint de mensajes de Anthropic, tal como estaba escrito a mano en `LeadAiService`. */
    const URL_ANTHROPIC = 'https://api.anthropic.com/v1/messages';

    /** Ruta del endpoint de mensajes de DeepSeek, colgada de `services.deepseek.base_url`. */
    const RUTA_MESSAGES = '/v1/messages';

    /** Modelo de Claude si `ANTHROPIC_MODEL` no está definido (el mismo default de siempre). */
    const MODELO_ANTHROPIC_POR_DEFECTO = 'claude-sonnet-4-20250514';

    /** Modelo de DeepSeek si `DEEPSEEK_MODEL_LEADS` viene vacío. */
    const MODELO_DEEPSEEK_POR_DEFECTO = 'deepseek-flash';

    /**
     * ¿El agente de leads corre hoy contra DeepSeek?
     *
     * Verdadero solo si el proveedor configurado es 'deepseek' Y hay clave cargada (ver el bloque de
     * arriba: sin clave se queda en Claude).
     *
     * @return bool
     */
    public static function es_deepseek(): bool
    {
        $configurado = strtolower(trim((string) config('services.lead_ai.proveedor', self::DEEPSEEK)));

        if ($configurado !== self::DEEPSEEK) {
            return false;
        }

        return trim((string) config('services.deepseek.api_key')) !== '';
    }

    /**
     * Nombre del proveedor que está corriendo: 'deepseek' o 'anthropic'.
     *
     * @return string
     */
    public static function nombre(): string
    {
        return self::es_deepseek() ? self::DEEPSEEK : self::ANTHROPIC;
    }

    /**
     * Nombre legible para mensajes de error y logs ("Error DeepSeek HTTP 402...").
     *
     * @return string
     */
    public static function etiqueta(): string
    {
        return self::es_deepseek() ? 'DeepSeek' : 'Anthropic';
    }

    /**
     * Clave de API del proveedor que corre. Vacía si ese proveedor no la tiene (solo puede pasar con
     * Claude: con DeepSeek `es_deepseek()` ya exigió que no esté vacía).
     *
     * @return string
     */
    public static function api_key(): string
    {
        if (self::es_deepseek()) {
            return trim((string) config('services.deepseek.api_key'));
        }

        return (string) config('services.anthropic.api_key');
    }

    /**
     * Id del modelo que se manda en cada llamada.
     *
     * @return string
     */
    public static function modelo(): string
    {
        if (self::es_deepseek()) {
            $modelo = trim((string) config('services.deepseek.model_leads'));

            return $modelo !== '' ? $modelo : self::MODELO_DEEPSEEK_POR_DEFECTO;
        }

        return (string) config('services.anthropic.model', self::MODELO_ANTHROPIC_POR_DEFECTO);
    }

    /**
     * URL completa del endpoint de mensajes del proveedor que corre.
     *
     * @return string
     */
    public static function url_messages(): string
    {
        if (self::es_deepseek()) {
            return rtrim((string) config('services.deepseek.base_url'), '/') . self::RUTA_MESSAGES;
        }

        return self::URL_ANTHROPIC;
    }

    /**
     * Arma el cuerpo de una llamada a partir del que ya armaba `LeadAiService`.
     *
     * Con Claude lo devuelve TAL CUAL (ni una clave de más). Con DeepSeek le suma `thinking`, que
     * ahí viene prendido por defecto: sin esta clave cada respuesta del agente razonaría de más,
     * tardaría más contra el timeout de 90 s y gastaría tokens que el guion no necesita.
     *
     * @param  array<string, mixed>  $payload  Cuerpo de la llamada (model, max_tokens, system, messages, tools?).
     *
     * @return array<string, mixed>
     */
    public static function cuerpo(array $payload): array
    {
        if (! self::es_deepseek()) {
            return $payload;
        }

        $tipo = strtolower(trim((string) config('services.deepseek.thinking_leads')));

        if ($tipo === '') {
            $tipo = 'disabled';
        }

        $payload['thinking'] = ['type' => $tipo];

        return $payload;
    }

    /**
     * ¿Se valida el certificado TLS del proveedor que corre? Falso solo en desarrollo.
     *
     * @return bool
     */
    public static function verify_ssl(): bool
    {
        $clave = self::es_deepseek() ? 'services.deepseek.verify_ssl' : 'services.anthropic.verify_ssl';

        return (bool) config($clave, true);
    }

    /**
     * Ruta al cacert.pem del proveedor que corre (WAMP/Windows), o null si no hay.
     *
     * @return string|null
     */
    public static function ca_bundle()
    {
        $clave = self::es_deepseek() ? 'services.deepseek.ca_bundle' : 'services.anthropic.ca_bundle';
        $ruta  = config($clave);

        return is_string($ruta) && $ruta !== '' ? $ruta : null;
    }
}

<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Flujo de entrada nuevo de Martín (misión embudo-whatsapp-precios, etapa E2, 21/11/2026).
 *
 * Qué resuelve: a todo lead nuevo de WhatsApp (del anuncio o de la web) se le manda, en orden, una presentación
 * corta, la pregunta por su rubro, las dos formas de arrancar (por su cuenta, gratis, o que se lo carguemos
 * nosotros con un pago único), la página y, sólo al lead de la web, el link de la prueba. Si el lead PREGUNTA algo,
 * Martín contesta eso primero y el sistema completa, sin repetir, lo que todavía falta del flujo.
 *
 * 🔴 Interruptor apagado por defecto ({@see KEY_ACTIVO}): con él apagado ninguna función de acá cambia el
 * comportamiento de hoy (los «puntos debidos» son `[]`, el bloque del agente es '', la transición al link no corre).
 * Se vuelve atrás apagándolo: no hay datos que migrar.
 *
 * Los PUNTOS ({@see PUNTOS}) son lo obligatorio del flujo. El registro de cuáles ya se entregaron NO se guarda en
 * ninguna columna: sale del hilo ({@see puntos_entregados()}), así que no se desincroniza si un setter edita,
 * rechaza o descarta una sugerencia.
 *
 * Todo es estático y sin estado, igual que {@see LeadOfertaDePruebaService}, para que lo usen el webhook, los jobs,
 * el agente y los tests sin armar objetos.
 */
class LeadFlujoEntradaService
{
    /** Clave del interruptor del flujo: '1' prende, '0' (default) deja todo como hoy. */
    public const KEY_ACTIVO = 'lead_whatsapp_flujo_entrada_activo';

    /**
     * Clave del sello de encendido: fecha y hora (ISO 8601) en que el interruptor pasó de apagado a prendido. Sólo están «en el
     * flujo» los leads creados desde ese momento: prender el flujo no le cambia la conversación a quien ya estaba hablando con
     * Martín (DECISIONES-E2 punto 10). Apagar no lo borra; volver a prender lo renueva.
     */
    public const KEY_ACTIVADO_EN = 'lead_whatsapp_flujo_entrada_activado_en';

    /** Clave opcional que pisa el texto del mensaje 1 de la llegada (presentación). */
    public const KEY_LLEGADA_1 = 'lead_whatsapp_flujo_llegada_1';

    /** Clave opcional que pisa el texto del mensaje 2 de la llegada (rubro y cantidad de productos). */
    public const KEY_LLEGADA_2 = 'lead_whatsapp_flujo_llegada_2';

    /** Clave opcional que pisa el texto de las dos formas de arrancar. */
    public const KEY_RESPUESTA_1 = 'lead_whatsapp_flujo_respuesta_1';

    /** Clave opcional que pisa el texto de la página. */
    public const KEY_RESPUESTA_2 = 'lead_whatsapp_flujo_respuesta_2';

    /** Clave que dice qué implementación se nombra en `{precio_implementacion}` (default `gestion`). */
    public const KEY_CLAVE_IMPLEMENTACION = 'lead_whatsapp_flujo_clave_implementacion';

    /** Clave con los textos precargados de los anuncios (uno por línea): no cuentan como pregunta. */
    public const KEY_TEXTOS_PRECARGADOS = 'lead_whatsapp_flujo_textos_precargados';

    /** `system_message_kind` del segundo mensaje de la llegada (rubro y cantidad de productos). */
    public const KIND_ENTRADA_2 = 'whatsapp_entrada_2';

    /** `system_message_kind` de las dos formas de arrancar. */
    public const KIND_DOS_FORMAS = 'whatsapp_dos_formas';

    /** `system_message_kind` de la página. */
    public const KIND_PAGINA = 'whatsapp_pagina';

    /**
     * `system_message_kind` del mensaje que sale de un paso de seguimiento del embudo (E3). Es automático del sistema: cuenta
     * para `puntos_entregados` (por ancla, como cualquier mensaje del sistema) pero NO como respuesta a una pregunta del lead
     * ({@see kinds_automaticos()}).
     */
    public const KIND_EMBUDO_PASO = 'whatsapp_embudo_paso';

    /** Clave de implementación que se nombra por defecto en las dos formas. */
    public const CLAVE_IMPLEMENTACION_DEFAULT = 'gestion';

    /** Estados en los que el lead puede deber puntos del flujo. */
    public const ESTADOS_CON_PUNTOS = ['nuevo', 'contactado', 'calificado'];

    /** Estados en los que el agente recibe el bloque del flujo (los de arriba más el del link ya enviado). */
    public const ESTADOS_CON_EL_BLOQUE = ['nuevo', 'contactado', 'calificado', 'link_enviado'];

    /** Estados desde los que enviar el link de la prueba mueve al lead a `link_enviado`. */
    public const ESTADOS_QUE_PASAN_A_LINK_ENVIADO = ['nuevo', 'contactado', 'calificado'];

    /**
     * Estados en los que el lead del flujo todavía NO pidió la demo: ahí el camino es la prueba y el agente no recibe
     * el guion de la demo (su página `/experiencia/`, la calificación mínima, la nota de la demo directa).
     * Pedir la demo con sus palabras lo mueve a `solicita_disponibilidad` y ahí vuelve todo (misión
     * embudo-prueba-martin-precios, ensayo 707).
     */
    public const ESTADOS_SIN_DEMO = ['nuevo', 'contactado', 'calificado', 'link_enviado', 'implementacion_pedida'];

    /**
     * Los puntos obligatorios, en el orden en que salen. `paso` es 1 (llegada) o 2 (respuesta del lead); `clave` es
     * la del texto literal; `kind` es el `system_message_kind` con el que sale por el camino literal.
     *
     * 🔴 `presentacion` usa el kind de la bienvenida de siempre (`whatsapp_welcome`): así no se tocan
     * `has_welcome_been_sent`, el `contactado` determinístico del webhook ni la idempotencia del onboarding.
     * `link_prueba` usa el kind del link de la oferta de hoy (`whatsapp_prueba_link`).
     */
    public const PUNTOS = [
        'presentacion'   => ['paso' => 1, 'clave' => 'llegada_1', 'kind' => LeadWhatsappOnboardingService::KIND_WELCOME],
        'prueba_y_rubro' => ['paso' => 1, 'clave' => 'llegada_2', 'kind' => self::KIND_ENTRADA_2],
        'dos_formas'     => ['paso' => 2, 'clave' => 'respuesta_1', 'kind' => self::KIND_DOS_FORMAS],
        'pagina'         => ['paso' => 2, 'clave' => 'respuesta_2', 'kind' => self::KIND_PAGINA],
        'link_prueba'    => ['paso' => 2, 'clave' => 'link_prueba', 'kind' => LeadWhatsappOnboardingService::KIND_PRUEBA_LINK],
    ];

    /**
     * Expresiones que, escritas, hacen que un texto cuente como pregunta aunque no lleve «?». Ya normalizadas
     * (minúsculas, sin tildes); se buscan como palabras completas. `sale` queda afuera a propósito: «sale mucho»
     * daría falsos positivos.
     */
    public const EXPRESIONES_DE_PREGUNTA = [
        'como', 'cuanto', 'cual', 'donde', 'cuando', 'por que', 'que es', 'que son', 'que incluye', 'que hace',
        'que tiene', 'en que consiste', 'precio', 'costo', 'cuesta', 'vale', 'sirve', 'funciona', 'se puede',
    ];

    /** Prefijo de la marca de caché «ya hay un literal del paso 2 en camino para este lead». */
    private const CACHE_LITERAL_ENCOLADO = 'lead_flujo_entrada_literal_encolado:';

    /** Cuánto dura esa marca (segundos): lo que tarda el job (3 s) con holgura para una cola lenta. */
    private const CACHE_LITERAL_ENCOLADO_SEGUNDOS = 180;

    /** Texto por defecto del mensaje 1 de la llegada (§9 del informe de Lucas). */
    public const DEFAULT_LLEGADA_1 = '¡Hola! Soy Martín, de ComercioCity, una plataforma de gestión con sistema, tienda online y agentes de IA para comercios.';

    /** Texto por defecto del mensaje 2 de la llegada; `{dias_prueba}` sale de `PruebaSettings::dias_default()`. */
    public const DEFAULT_LLEGADA_2 = 'Lo podés probar gratis {dias_prueba} días con tu propio negocio. Comentame, ¿qué rubro trabajás y más o menos cuántos productos manejás?';

    /** Texto por defecto de las dos formas de arrancar; `{precio_implementacion}` sale de PRECIOS VIGENTES. */
    public const DEFAULT_RESPUESTA_1 = "Tenés dos formas de arrancar:\n"
        . "\n"
        . "1️⃣ Por tu cuenta, gratis: creás tu prueba en 2 minutos y subís tu Excel con un video que te guía.\n"
        . "\n"
        . '2️⃣ Te lo dejamos armado nosotros: nos pasás tu Excel, las listas, páginas web o los PDF de tus proveedores y te cargamos productos, precios, fotos y descripciones. También contás con desarrollos a medida de por vida. Todo esto con un pago único de {precio_implementacion}; podés arrancar con la mitad para probar completamente la plataforma con toda tu información ya cargada.';

    /** Texto por defecto de la página (no el link de la prueba: la página). */
    public const DEFAULT_RESPUESTA_2 = 'También te dejamos nuestra página para que veas más acerca del servicio y testimonios de nuestros clientes: comerciocity.com';

    // ------------------------------------------------------------------------------------------
    // Interruptor y textos
    // ------------------------------------------------------------------------------------------

    /**
     * ¿El flujo nuevo está prendido? Default no.
     *
     * @return bool
     */
    public static function activo(): bool
    {
        return (string) AdminSetting::get(self::KEY_ACTIVO, '0') === '1';
    }

    /**
     * Sella el encendido del flujo (ahora). Lo llama el guardado de Cuenta cuando el interruptor pasa de apagado a prendido.
     *
     * @return void
     */
    public static function sellar_encendido(): void
    {
        AdminSetting::set(self::KEY_ACTIVADO_EN, Carbon::now()->toIso8601String());
    }

    /**
     * Desde cuándo está prendido el flujo: el sello guardado; si el interruptor se prendió sin pasar por el guardado de Cuenta
     * (a mano en la base, un seeder), la fecha de última escritura de la clave del interruptor. Sin interruptor prendido, o sin
     * ninguna de las dos, null: nadie está en el flujo.
     *
     * @return Carbon|null
     */
    public static function activado_en(): ?Carbon
    {
        if (! self::activo()) {
            return null;
        }

        $sello = trim((string) AdminSetting::get(self::KEY_ACTIVADO_EN, ''));
        if ($sello === '') {
            $sello = (string) AdminSetting::query()->where('key', self::KEY_ACTIVO)->value('updated_at');
        }

        if ($sello === '') {
            return null;
        }

        try {
            return Carbon::parse($sello);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ¿El lead está en el flujo nuevo? Sí si el interruptor está prendido, el lead se creó desde el encendido
     * ({@see activado_en()}) y no tiene demo asignada (la demo sigue su flujo de siempre).
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function en_el_flujo(Lead $lead): bool
    {
        if ($lead->demo_id) {
            return false;
        }

        $desde = self::activado_en();

        return $desde !== null && $lead->created_at !== null && Carbon::parse($lead->created_at)->greaterThanOrEqualTo($desde);
    }

    /**
     * ¿Hay que ocultarle al agente el guion de la demo a este lead? Sí si está en el flujo de entrada
     * ({@see en_el_flujo()}: interruptor prendido, creado desde el encendido, sin demo asignada) y en un estado
     * donde todavía no pidió la demo ({@see ESTADOS_SIN_DEMO}).
     *
     * Por qué existe: el bloque DEMO DIRECTA le dicta al agente pasar la página del lead y calificarlo, y el flujo de
     * entrada manda al lead a la prueba; con los dos bloques en el prompt, el de la demo (más concreto) le ganaba al
     * del flujo (ensayo 707, 27/11/2026). No tocar este criterio sin revisar `LeadAiService::generate_suggestion()`
     * y `leads:check-pagina-sin-demo`, que lo comparten.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function oculta_la_demo(Lead $lead): bool
    {
        return self::en_el_flujo($lead) && in_array((string) $lead->status, self::ESTADOS_SIN_DEMO, true);
    }

    /**
     * El bloque corto que reemplaza a la demo directa para el lead que {@see oculta_la_demo()}: dice qué hacer si
     * pide la demo con sus palabras, sin ningún link de su página ni calificación.
     *
     * El lead que pide la demo pasa por `solicita_disponibilidad` (con el mail primero) y recién en el turno
     * siguiente el sistema le da el bloque completo de la demo. Excepción: el lead en `implementacion_pedida` NO puede
     * pasar a `solicita_disponibilidad` (la guarda de «no retroceder de tramo» de `LeadAiService` compara `sort_order`
     * y ese estado va después), así que el sistema nunca le da el bloque de la demo: a ese lead no se le promete nada
     * automático, la demo se la muestra Juan en la llamada de la implementación.
     *
     * @param string $estado_del_lead Estado actual del lead ('' si no se conoce).
     *
     * @return string Bloque listo para concatenar al contexto de usuario.
     */
    public static function bloque_de_la_demo_a_pedido(string $estado_del_lead = ''): string
    {
        $encabezado = "\n\nDEMO SÓLO A PEDIDO (el camino de este lead es la prueba gratis; no hay demo asignada ni se la ofrezcas):\n"
            . "  - NO le pases la página de su demo ni ningún link de demo, y no lo califiques con preguntas de la demo.\n";

        /* Lead que ya pidió la implementación: la demo no se agenda por acá, se la muestra Juan en la llamada. */
        if ($estado_del_lead === 'implementacion_pedida') {
            return $encabezado
                . "  - Este lead ya pidió la implementación: si pide una DEMO con sus palabras, decile que Juan se la muestra\n"
                . "    en la llamada de la implementación. NO la agendes, NO pidas mail ni horario para una demo, NO prometas una demo automática ni un bloque de\n"
                . "    demo en el turno siguiente, y NO devuelvas estado_sugerido \"solicita_disponibilidad\".\n";
        }

        return $encabezado
            . "  - Si el lead pide una DEMO con sus palabras (un mensaje suyo, no el texto de un botón): confirmale que sí. Si todavía no\n"
            . "    tenemos su mail, pedíselo con una sola pregunta; si ya lo tenemos, preguntale si la hace ahora o cuándo.\n"
            . "    Devolvé estado_sugerido: \"solicita_disponibilidad\", SIN agendar_demo y SIN links en este turno: en el turno\n"
            . "    siguiente el sistema te da el bloque de la demo.\n";
    }

    /**
     * El texto literal de un punto, ya con el precio, los días y el link.
     *
     * @param string $punto Clave de {@see PUNTOS}.
     *
     * @return string '' si el punto no existe.
     */
    public static function texto_del_punto(string $punto): string
    {
        if (! isset(self::PUNTOS[$punto])) {
            return '';
        }

        switch (self::PUNTOS[$punto]['clave']) {
            case 'llegada_1':
                return self::texto_guardado_o_por_defecto(self::KEY_LLEGADA_1, self::DEFAULT_LLEGADA_1);
            case 'llegada_2':
                return self::texto_guardado_o_por_defecto(self::KEY_LLEGADA_2, self::DEFAULT_LLEGADA_2);
            case 'respuesta_1':
                return self::texto_guardado_o_por_defecto(self::KEY_RESPUESTA_1, self::DEFAULT_RESPUESTA_1);
            case 'respuesta_2':
                return self::texto_guardado_o_por_defecto(self::KEY_RESPUESTA_2, self::DEFAULT_RESPUESTA_2);
            default:
                /* El link de la prueba: el mismo segundo mensaje de la oferta de hoy, que lleva el link textual. */
                $mensajes = LeadOfertaDePruebaService::mensajes();

                return $mensajes[1];
        }
    }

    /**
     * El `system_message_kind` con el que sale un punto por el camino literal.
     *
     * @param string $punto Clave de {@see PUNTOS}.
     *
     * @return string|null null si el punto no existe.
     */
    public static function kind_del_punto(string $punto): ?string
    {
        return isset(self::PUNTOS[$punto]) ? self::PUNTOS[$punto]['kind'] : null;
    }

    /**
     * El precio de la implementación que se nombra en las dos formas, formateado ($628.400 con los defaults).
     *
     * @return string
     */
    public static function precio_de_implementacion(): string
    {
        $clave = trim((string) AdminSetting::get(self::KEY_CLAVE_IMPLEMENTACION, ''));
        if ($clave === '') {
            $clave = self::CLAVE_IMPLEMENTACION_DEFAULT;
        }

        $item = PreciosVigentesSettings::implementacion($clave);
        if ($item === null) {
            $item = PreciosVigentesSettings::implementacion(self::CLAVE_IMPLEMENTACION_DEFAULT);
        }

        return $item === null ? '' : PreciosVigentesSettings::formato_pesos((float) $item['precio']);
    }

    // ------------------------------------------------------------------------------------------
    // ¿Preguntó algo?
    // ------------------------------------------------------------------------------------------

    /**
     * Los textos precargados que NO cuentan como pregunta, ya normalizados: la frase de la web y los que Lucas
     * cargó en {@see KEY_TEXTOS_PRECARGADOS} (uno por línea; los de los anuncios, que a veces llevan «?»).
     *
     * @return array<int, string>
     */
    public static function textos_precargados(): array
    {
        $normalizados = [LeadOrigenService::normalizar(LeadOrigenService::FRASE_LANDING_PRUEBA)];

        $guardado = (string) AdminSetting::get(self::KEY_TEXTOS_PRECARGADOS, '');
        foreach (preg_split('/\R/u', $guardado) ?: [] as $linea) {
            $linea = LeadOrigenService::normalizar($linea);
            if ($linea !== '') {
                $normalizados[] = $linea;
            }
        }

        return $normalizados;
    }

    /**
     * ¿Lo que escribió el lead es una pregunta?
     *
     * Devuelve false si el texto es uno de los precargados ({@see textos_precargados()}) o ES la frase de un
     * botón (con a lo sumo un saludo adelante y nada atrás: «Hola, quiero probar ComercioCity, ¿cuánto sale?» ya es
     * una pregunta propia). Si no, true cuando lleva «?» o «¿», o una de las
     * {@see EXPRESIONES_DE_PREGUNTA} como palabra completa.
     *
     * @param string $texto Lo que escribió el lead.
     *
     * @return bool
     */
    public static function tiene_pregunta(string $texto): bool
    {
        $normalizado = LeadOrigenService::normalizar($texto);
        if ($normalizado === '') {
            return false;
        }

        /* La frase del botón de la web (con o sin un saludo delante) y los precargados de los anuncios. */
        if (LeadWhatsappOnboardingService::primer_mensaje_es_frase_de_boton($texto)) {
            return false;
        }
        if (in_array($normalizado, self::textos_precargados(), true)) {
            return false;
        }

        if (strpos($texto, '?') !== false || strpos($texto, '¿') !== false) {
            return true;
        }

        $envuelto = ' ' . $normalizado . ' ';
        foreach (self::EXPRESIONES_DE_PREGUNTA as $expresion) {
            if (strpos($envuelto, ' ' . $expresion . ' ') !== false) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------------------------------
    // Puntos entregados y debidos (salen del hilo)
    // ------------------------------------------------------------------------------------------

    /**
     * ¿El texto cumple el ancla de un punto?
     *
     * Las anclas se buscan sobre el texto normalizado (minúsculas, sin tildes):
     *  - `presentacion`: contiene `comerciocity`;
     *  - `prueba_y_rubro`: contiene `gratis` y `rubro`;
     *  - `dos_formas`: contiene el precio de implementación formateado y `gratis`;
     *  - `pagina`: contiene `comerciocity.com` que NO sea el comienzo de una ruta (la página, no el link de la
     *    prueba);
     *  - `link_prueba`: contiene el link de la prueba.
     *
     * 🔴 Un ancla se puede cumplir sin cubrir el punto (nombrar ComercioCity sin presentarse). Se acepta: endurecerlas
     * haría que se repitan textos, que es lo que Lucas no quiere (PLAN §2.2.2).
     *
     * @param string $punto Clave de {@see PUNTOS}.
     * @param string $texto Texto a revisar.
     *
     * @return bool
     */
    public static function cumple_el_ancla(string $punto, string $texto): bool
    {
        $normalizado = LeadOrigenService::normalizar($texto);
        $envuelto    = ' ' . $normalizado . ' ';

        switch ($punto) {
            case 'presentacion':
                return strpos($normalizado, 'comerciocity') !== false;
            case 'prueba_y_rubro':
                return strpos($envuelto, ' gratis ') !== false && strpos($normalizado, 'rubro') !== false;
            case 'dos_formas':
                $precio = LeadOrigenService::normalizar(self::precio_de_implementacion());

                return $precio !== '' && strpos($normalizado, $precio) !== false && strpos($envuelto, ' gratis ') !== false;
            case 'pagina':
                return preg_match('/comerciocity\.com(?!\/)/i', $texto) === 1;
            case 'link_prueba':
                return strpos($texto, LeadOfertaDePruebaService::link()) !== false;
            default:
                return false;
        }
    }

    /**
     * Qué puntos ya se le entregaron al lead y en qué mensaje: el id del ÚLTIMO mensaje del hilo que cubre cada
     * punto. Cuenta un mensaje del sistema o del agente que fue EFECTIVAMENTE enviado (`status = enviado`, no
     * evento de estado) y que tiene el kind del punto o cuyo texto cumple el ancla. Una sugerencia pendiente,
     * rechazada o descartada no cuenta.
     *
     * @param Lead $lead
     *
     * @return array<string, int> Punto => id del último mensaje que lo cubre.
     */
    public static function mensajes_de_puntos_entregados(Lead $lead): array
    {
        $entregados = [];

        $mensajes = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'sistema')
            ->where('status', 'enviado')
            ->where('is_status_event', false)
            ->orderBy('id')
            ->get();

        foreach ($mensajes as $mensaje) {
            /* Lo que salió es el texto editado por el setter, si lo hubo. */
            $texto = trim((string) ($mensaje->edited_content !== null && trim((string) $mensaje->edited_content) !== ''
                ? $mensaje->edited_content
                : $mensaje->content));

            foreach (array_keys(self::PUNTOS) as $punto) {
                $por_kind = (string) $mensaje->system_message_kind === self::PUNTOS[$punto]['kind'];
                if ($por_kind || ($texto !== '' && self::cumple_el_ancla($punto, $texto))) {
                    $entregados[$punto] = (int) $mensaje->id;
                }
            }
        }

        return $entregados;
    }

    /**
     * Los puntos que ya se le entregaron al lead (ver {@see mensajes_de_puntos_entregados()}).
     *
     * @param Lead $lead
     *
     * @return array<int, string>
     */
    public static function puntos_entregados(Lead $lead): array
    {
        return array_keys(self::mensajes_de_puntos_entregados($lead));
    }

    /**
     * Los puntos que se le deben al lead ahora, en el orden de {@see PUNTOS}.
     *
     * Devuelve `[]` si el interruptor está apagado, si el lead tiene su prueba o si no está en `nuevo`,
     * `contactado` o `calificado`. Si no: los del paso 1 que no se entregaron; y los del paso 2 que no se
     * entregaron, SÓLO si los del paso 1 ya están todos entregados y el lead escribió algo después del último
     * envío del paso 1 (o sea, ya contestó).
     *
     * @param Lead $lead
     *
     * @return array<int, string>
     */
    public static function puntos_debidos(Lead $lead): array
    {
        if (! self::en_el_flujo($lead)) {
            return [];
        }

        if (! in_array((string) $lead->status, self::ESTADOS_CON_PUNTOS, true) || LeadOfertaDePruebaService::tiene_prueba($lead)) {
            return [];
        }

        $entregados = self::mensajes_de_puntos_entregados($lead);

        $debidos = [];
        $ultimo_del_paso_1 = 0;
        foreach (self::PUNTOS as $punto => $datos) {
            if ($datos['paso'] !== 1) {
                continue;
            }
            /* A quien llegó por otro medio (referido, WhatsApp personal) no se le vuelve a presentar ComercioCity: ya
               sabe qué es y el recurso `referidos` lo prohíbe. El resto del flujo sigue igual. */
            if ($punto === 'presentacion' && LeadOrigenService::es_externo($lead)) {
                continue;
            }
            if (isset($entregados[$punto])) {
                $ultimo_del_paso_1 = max($ultimo_del_paso_1, $entregados[$punto]);
            } else {
                $debidos[] = $punto;
            }
        }

        /* Mientras falte algo del paso 1, el paso 2 no se debe. */
        if (! empty($debidos)) {
            return $debidos;
        }

        /* El paso 2 se debe cuando el lead contestó: hay un mensaje suyo posterior al último envío del paso 1. */
        $contesto = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'lead')
            ->where('id', '>', $ultimo_del_paso_1)
            ->exists();
        if (! $contesto) {
            return [];
        }

        /* Quién merece el link de la prueba: el lead de la web o el que, desde su primer mensaje, dice que quiere probar. */
        $quiere_probar = self::es_lead_de_la_web($lead) || self::quiere_probar_desde_el_primer_mensaje($lead);
        foreach (self::PUNTOS as $punto => $datos) {
            if ($datos['paso'] !== 2 || isset($entregados[$punto])) {
                continue;
            }
            /* El link sólo se le debe al lead de la web o al que dijo «quiero probar» en su primer mensaje (los textos
               precargados de los anuncios), con el link prendido y lugar en el pool de pruebas. */
            if ($punto === 'link_prueba' && ! ($quiere_probar && LeadOfertaDePruebaService::activa() && self::hay_lugar_en_el_pool())) {
                continue;
            }
            $debidos[] = $punto;
        }

        return $debidos;
    }

    /**
     * ¿El lead llegó con la frase de un botón de la web? Se mira su PRIMER mensaje (P2 de Lucas, 21/11/2026).
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function es_lead_de_la_web(Lead $lead): bool
    {
        $primero = self::primer_mensaje_del_lead($lead);

        return $primero !== null && LeadOrigenService::origen_por_frase($primero) === LeadOrigenService::ORIGEN_LANDING;
    }

    /**
     * ¿El primer mensaje del lead dice que quiere probar? Es el caso del lead del anuncio, cuyo texto precargado
     * («Hola, vi el video de los presupuestos y quiero probar ComercioCity») no contiene la frase contigua de la web
     * y por eso no cuenta como lead de la web ({@see es_lead_de_la_web()}), aunque pida exactamente lo mismo.
     *
     * Sí si el primer mensaje, normalizado, contiene «quiero probar» como palabras enteras. No se lee del origen (atribución de Meta) a propósito: acá
     * se mide la intención del lead.
     *
     * @param Lead $lead
     *
     * @return bool false si todavía no escribió.
     */
    public static function quiere_probar_desde_el_primer_mensaje(Lead $lead): bool
    {
        $primero = self::primer_mensaje_del_lead($lead);
        if ($primero === null) {
            return false;
        }

        /* Sólo la intención explícita. Ser igual a un texto precargado NO alcanza: esa perilla guarda lo que «no cuenta
           como pregunta» (p. ej. «Hola, vi el video» o «Quiero más información»), que no pide probar nada. */
        return LeadOrigenService::contiene_frase($primero, 'quiero probar');
    }

    /**
     * El texto del primer mensaje que escribió el lead.
     *
     * @param Lead $lead
     *
     * @return string|null null si todavía no escribió.
     */
    public static function primer_mensaje_del_lead(Lead $lead): ?string
    {
        $mensaje = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'lead')
            ->orderBy('id')
            ->first();

        return $mensaje === null ? null : (string) $mensaje->content;
    }

    /**
     * ¿El pool de pruebas tiene lugar? Mismo criterio que {@see LeadOfertaDePruebaService::aplica_al_primer_mensaje()}:
     * lugar bajo el tope de activas y alguna instancia libre o armándose.
     *
     * @return bool false también si no se pudo medir.
     */
    public static function hay_lugar_en_el_pool(): bool
    {
        try {
            return PruebaPoolService::hay_capacidad() && PruebaPoolService::libres_o_creando() >= 1;
        } catch (\Throwable $e) {
            Log::channel('daily')->error('LeadFlujoEntrada: no se pudo medir la capacidad del pool de pruebas.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ------------------------------------------------------------------------------------------
    // Qué sale en cada inbound
    // ------------------------------------------------------------------------------------------

    /**
     * ¿Este inbound lo contesta el camino literal del paso 2 (el job de las dos formas)?
     *
     * Sí cuando el flujo está prendido, se le deben puntos del paso 2 y lo que escribió no es una pregunta. Con
     * pregunta, o con puntos del paso 1 todavía debidos, sigue el camino de siempre (la IA, que recibe el bloque
     * de puntos obligatorios).
     *
     * @param Lead   $lead
     * @param string $texto Lo que escribió el lead en este inbound.
     *
     * @return bool
     */
    public static function corresponde_el_literal_del_paso_2(Lead $lead, string $texto): bool
    {
        /* Ya hay un literal en camino (el job tarda 3 s): este inbound es el SIGUIENTE y lo sigue la IA, que recibe los
           puntos que falten. Sin la marca, dos mensajes seguidos encolarían dos veces lo mismo. */
        if (self::literal_encolado($lead)) {
            return false;
        }

        $debidos = self::puntos_debidos($lead);

        /* Literal o IA, nunca los dos: si la IA ya dejó una sugerencia sin enviar (retenida, pendiente de aprobación o
           programada) que cubre TODO lo que se debe, mandar el literal haría que el lead lo reciba dos veces cuando se
           apruebe. Sigue el camino normal: el scheduler la descarta y la IA regenera completando lo que falte
           (DECISIONES-E2 punto 11). Si cubre sólo una parte, sale el literal y el webhook descarta
           la vieja con las defensas del scheduler (`preparar_para_el_literal`). */
        if (! empty($debidos) && self::las_sugerencias_sin_enviar_cubren($lead, $debidos)) {
            return false;
        }

        if (empty($debidos) || self::tiene_pregunta($texto)) {
            return false;
        }

        /* No se toma el literal si hay una pregunta anterior todavía sin contestar (tomarlo cancelaría la generación que la iba
           a contestar) ni si hay un pase a una persona pendiente (se perdería). Sigue el camino normal, que agenda la IA
           (DECISIONES-E2 punto 14). */
        if (self::hay_pregunta_sin_contestar($lead) || self::hay_sugerencia_que_pide_intervencion($lead)) {
            return false;
        }

        /* Tampoco mientras la IA tenga una generación agendada o en curso con el token vigente: tomar el literal renovaría el
           token y la descartaría, y lo que el lead dijo antes quedaría sin respuesta (DECISIONES-E2 punto 16). */
        if ((new LeadAiSuggestionScheduler())->hay_generacion_agendada((int) $lead->id)) {
            return false;
        }

        foreach ($debidos as $punto) {
            if (self::PUNTOS[$punto]['paso'] !== 2) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿Hay una pregunta del lead que todavía no tuvo ninguna respuesta generada? Mira los mensajes del lead posteriores al último
     * saliente enviado: si alguno es una pregunta y después de ella no hay ningún mensaje del sistema (ni enviado, ni sugerido ni
     * rechazado; los seguimientos no cuentan), la IA todavía tiene que contestarla (su generación está agendada o en curso).
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function hay_pregunta_sin_contestar(Lead $lead): bool
    {
        $ultimo_saliente = (int) LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'sistema')
            ->where('status', 'enviado')
            ->where('is_status_event', false)
            ->where(function ($query) {
                $query->whereNull('system_message_kind')->orWhereNotIn('system_message_kind', self::kinds_automaticos());
            })
            ->max('id');

        $ultima_pregunta = 0;
        $mensajes = LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'lead')
            ->where('id', '>', $ultimo_saliente)
            ->get();

        foreach ($mensajes as $mensaje) {
            if (self::tiene_pregunta((string) $mensaje->content)) {
                $ultima_pregunta = max($ultima_pregunta, (int) $mensaje->id);
            }
        }

        if ($ultima_pregunta === 0) {
            return false;
        }

        return ! LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'sistema')
            ->where('is_status_event', false)
            ->where('is_followup', false)
            ->where(function ($query) {
                $query->whereNull('system_message_kind')->orWhereNotIn('system_message_kind', self::kinds_automaticos());
            })
            ->where('id', '>', $ultima_pregunta)
            ->exists();
    }

    /**
     * ¿Hay una sugerencia sin enviar que pide intervención humana (el mensaje de espera del gate, por ejemplo)?
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function hay_sugerencia_que_pide_intervencion(Lead $lead): bool
    {
        foreach (self::sugerencias_sin_enviar($lead) as $mensaje) {
            $acciones = $mensaje->pending_actions;
            if (is_array($acciones) && ! empty($acciones['requiere_intervencion_humana'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los `system_message_kind` de los mensajes automáticos del flujo (los literales de {@see PUNTOS}, que incluyen la
     * bienvenida, y el auto de siempre). No son una respuesta a lo que el lead preguntó.
     *
     * @return array<int, string>
     */
    public static function kinds_automaticos(): array
    {
        $kinds = array_column(self::PUNTOS, 'kind');
        $kinds[] = LeadWhatsappOnboardingService::KIND_AUTO;
        $kinds[] = self::KIND_EMBUDO_PASO;

        return array_values(array_unique($kinds));
    }

    /**
     * Las sugerencias de la IA todavía sin enviar del lead (estado `sugerido`: pendientes de aprobación, retenidas o
     * programadas).
     *
     * @param Lead $lead
     *
     * @return \Illuminate\Support\Collection
     */
    private static function sugerencias_sin_enviar(Lead $lead)
    {
        return LeadMessage::query()
            ->where('lead_id', (int) $lead->id)
            ->where('sender', 'sistema')
            ->where('status', 'sugerido')
            ->where('is_followup', false)
            ->where('is_status_event', false)
            ->get();
    }

    /**
     * ¿El lead tiene alguna sugerencia de la IA sin enviar (pendiente de aprobación, retenida o programada)? Lo usa el
     * despacho de los pasos del embudo (E3): con una sugerencia en camino, el paso espera.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function hay_sugerencia_sin_enviar(Lead $lead): bool
    {
        return self::sugerencias_sin_enviar($lead)->isNotEmpty();
    }

    /**
     * ¿Las sugerencias sin enviar del lead cubren, juntas, TODOS los puntos dados (por ancla)?
     *
     * @param Lead                $lead
     * @param array<int, string>  $puntos Puntos debidos.
     *
     * @return bool false si no hay ninguna sugerencia sin enviar.
     */
    public static function las_sugerencias_sin_enviar_cubren(Lead $lead, array $puntos): bool
    {
        $pendientes = self::sugerencias_sin_enviar($lead);
        if ($pendientes->isEmpty() || empty($puntos)) {
            return false;
        }

        $texto = $pendientes->map(function ($mensaje) {
            return (string) $mensaje->content;
        })->implode("\n");

        foreach ($puntos as $punto) {
            if (! self::cumple_el_ancla($punto, $texto)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deja marcado que hay un literal del paso 2 en camino para el lead. Lo llama el webhook al encolar el job.
     *
     * @param Lead $lead
     *
     * @return void
     */
    public static function marcar_literal_encolado(Lead $lead): void
    {
        Cache::put(self::CACHE_LITERAL_ENCOLADO . (int) $lead->id, 1, self::CACHE_LITERAL_ENCOLADO_SEGUNDOS);
    }

    /**
     * ¿Hay un literal del paso 2 en camino para el lead?
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public static function literal_encolado(Lead $lead): bool
    {
        return Cache::has(self::CACHE_LITERAL_ENCOLADO . (int) $lead->id);
    }

    /**
     * Saca la marca de «literal en camino». Lo llama el job de las dos formas al terminar.
     *
     * @param Lead $lead
     *
     * @return void
     */
    public static function limpiar_literal_encolado(Lead $lead): void
    {
        Cache::forget(self::CACHE_LITERAL_ENCOLADO . (int) $lead->id);
    }

    /**
     * ¿Al lead se le contesta con IA ya en su PRIMER mensaje? Sí si el flujo está prendido, el lead sigue en
     * `nuevo`, su primer mensaje es una pregunta y se le deben puntos (el gate «la IA espera al segundo mensaje»
     * existe porque al primero lo contesta el onboarding, y a este no le sale ningún literal).
     *
     * @param int $lead_id
     *
     * @return bool
     */
    public static function ia_contesta_el_primer_mensaje(int $lead_id): bool
    {
        $lead = Lead::query()->find($lead_id);
        if ($lead === null || ! self::en_el_flujo($lead) || (string) $lead->status !== 'nuevo') {
            return false;
        }

        $primero = self::primer_mensaje_del_lead($lead);
        if ($primero === null || ! self::tiene_pregunta($primero)) {
            return false;
        }

        return ! empty(self::puntos_debidos($lead));
    }

    // ------------------------------------------------------------------------------------------
    // Bloque para el agente
    // ------------------------------------------------------------------------------------------

    /**
     * El bloque que el agente de leads lee en cada turno con un lead dentro del flujo. Va AL FINAL del contexto,
     * en el mismo lugar que el de la oferta de la web, y lo REEMPLAZA.
     *
     * @param Lead $lead
     *
     * @return string '' si el flujo está apagado, el lead pidió o tiene demo, tiene su prueba o está en otro
     *                estado (ahí valen el bloque «YA CREO SU PRUEBA» o el flujo de la demo de hoy).
     */
    public static function bloque_para_el_agente(Lead $lead): string
    {
        if (! self::en_el_flujo($lead)) {
            return '';
        }

        if (! in_array((string) $lead->status, self::ESTADOS_CON_EL_BLOQUE, true) || LeadOfertaDePruebaService::tiene_prueba($lead)) {
            return '';
        }

        $hay_lugar     = self::hay_lugar_en_el_pool();
        $entregados    = self::puntos_entregados($lead);
        $debidos       = self::puntos_debidos($lead);
        $precio        = self::precio_de_implementacion();
        $link          = LeadOfertaDePruebaService::link();

        $txt = '';

        /* Lo que se le debe en ESTE turno: va primero y con el texto literal de referencia de cada punto. */
        if (! empty($debidos)) {
            $txt .= "\n\nPUNTOS OBLIGATORIOS DE ESTE TURNO (el flujo de entrada de ComercioCity; lo que sigue es obligatorio):\n"
                . "  1. Respondé PRIMERO lo que el lead preguntó, con información correcta.\n"
                . "  2. Después cubrí cada punto pendiente de abajo partiendo de su texto de referencia, adaptado para que NO repita lo que\n"
                . "     ya dijiste en tu respuesta ni lo que está en el hilo. Si tu respuesta ya cubre un punto (por ejemplo, explicaste qué es\n"
                . "     ComercioCity), no lo repitas.\n"
                . "  3. Separá los mensajes con una línea que diga solamente ---\n"
                . "  4. Las cifras salen de PRECIOS VIGENTES y se citan en fuentes_kb (\"" . PreciosVigentesSettings::FUENTE_PARA_EL_GATE . "\").\n"
                . "  Puntos pendientes:\n";

            foreach ($debidos as $punto) {
                $txt .= '  - ' . $punto . ': ' . self::frase_del_ancla($punto) . "\n"
                    . '    Texto de referencia: «' . str_replace("\n", "\n      ", self::texto_del_punto($punto)) . "»\n";
            }
        }

        $txt .= "\n\nFLUJO DE ENTRADA (esto PISA lo que digan los bloques de arriba y los recursos sobre calificar, pasar su página u ofrecer\n"
            . "y agendar la demo):\n";

        /* Qué formas de arrancar ya recibió, con el precio vigente. */
        if (in_array('dos_formas', $entregados, true)) {
            $txt .= "  - Ya se le dieron las dos formas de arrancar: por su cuenta, gratis; o que se lo carguemos nosotros con un pago único de "
                . $precio . ". No se las repitas enteras: contestá lo que pregunte.\n";
        } else {
            $txt .= "  - Todavía no se le dieron las dos formas de arrancar (por su cuenta, gratis; o que se lo carguemos nosotros con un pago\n"
                . '    único de ' . $precio . "): las manda el flujo cuando conteste.\n";
        }

        $txt .= "  - Tu objetivo es que elija cómo arrancar.\n";

        if ($hay_lugar) {
            $txt .= "  - Si dice que quiere probar, empezar o hacerlo por su cuenta: mandale el link de la prueba textual, en un mensaje corto,\n"
                . "    con estado_sugerido: \"link_enviado\". Link (copiar textual, sin cambiarle nada): " . $link . "\n";
        } else {
            $txt .= "  - Hoy NO hay lugar para crear pruebas nuevas: si quiere probar, NO le pases ningún link.\n"
                . "    Decile: Juan te escribe para darte el acceso. Y devolvé requiere_intervencion_humana: true con el motivo.\n";
        }

        /* «Cómo arranco» (ensayo del 26/11/2026): Martín le mandó comerciocity.com a un lead que preguntó cómo arrancar,
           en vez del link de la prueba. Es un punto aparte del de arriba (que habla de «probar») para que las frases
           concretas del lead queden nombradas. Sin lugar en el pool sigue la regla vigente: ningún link. */
        if ($hay_lugar) {
            $txt .= "  - Si dice «cómo arranco», «cómo empiezo», «me interesa», «quiero empezar» o «cómo hago» (o algo parecido): mandale el link de\n"
                . "    la prueba textual, en un mensaje corto, con estado_sugerido: \"link_enviado\". NO le mandes comerciocity.com como respuesta a\n"
                . "    eso: la página no es el camino para arrancar. Link (copiar textual, sin cambiarle nada): " . $link . "\n";
        } else {
            $txt .= "  - Si dice «cómo arranco», «cómo empiezo», «me interesa», «quiero empezar» o «cómo hago» (o algo parecido): hoy NO hay lugar,\n"
                . "    NO le pases ningún link. Decile: Juan te escribe para darte el acceso. Y devolvé requiere_intervencion_humana: true con el\n"
                . "    motivo (es lo único que le avisa a Juan). NO le mandes comerciocity.com como respuesta a eso.\n";
        }

        /* La carga (ensayo del 26/11/2026): Martín le ofreció «te doy una mano para subirlo» a quien pidió la carga gratis.
           Con el flujo nuevo la carga la hace el lead solo (video dentro de la prueba) o la hacemos nosotros como
           implementación paga: nunca una ayuda informal por WhatsApp. La prohibición va con o sin lugar en el pool. */
        $txt .= "  - NO le ofrezcas ayuda con la carga: nada de «te doy una mano para subirlo», «pasame tu Excel», «te lo subo» ni «te lo cargo».\n"
            . "    NO le pidas ni recibas su Excel, listas ni PDF por WhatsApp: la carga hecha por nosotros es la implementación paga, y la\n"
            . "    coordina Juan, no vos.\n";

        if ($hay_lugar) {
            $txt .= "  - Si pide que se lo carguen GRATIS (o si le podés cargar vos los productos): mandale el link de la prueba textual y decile que\n"
                . "    adentro de la prueba hay un video corto que lo guía para subir su Excel él mismo. Aclará que si prefiere que lo carguemos\n"
                . "    nosotros, eso es la implementación paga (pago único de " . $precio . ") y la coordina Juan.\n"
                . "    Link (copiar textual, sin cambiarle nada): " . $link . "\n";
        } else {
            /* Sin lugar no hay prueba ni video a donde mandarlo: la prohibición de ayudar con la carga sigue y, como se le
               promete que Juan escribe, se pide la intervención humana (la regla vigente de arriba para «probar»). */
            $txt .= "  - Si pide que se lo carguen GRATIS (o si le podés cargar vos los productos): hoy NO hay lugar, NO le pases ningún link ni\n"
                . "    le ofrezcas ayuda con la carga. Decile: Juan te escribe para darte el acceso; si prefiere que lo carguemos nosotros, eso es la\n"
                . "    implementación paga (pago único de " . $precio . "). Y devolvé requiere_intervencion_humana: true con el motivo.\n";
        }

        $txt .= "  - Si quiere que se lo carguen nosotros o pide la implementación: decile que lo llama Juan, del equipo, hoy, para contarle cómo es\n"
            . "    la implementación (la llamada no tiene costo; la implementación es un pago único de PRECIOS VIGENTES), sin pedirle hora, y\n"
            . "    devolvé estado_sugerido: \"implementacion_pedida\".\n"
            . "  - No le pidas hora. No le pidas datos para calificarlo más allá de lo que ya pide el flujo.\n"
            . "  - No pidas el nombre, ni cuántos trabajan, ni qué sistema usa: con el rubro alcanza.\n"
            . "  - Si pregunta precios: contestá con PRECIOS VIGENTES (citá \"" . PreciosVigentesSettings::FUENTE_PARA_EL_GATE . "\" en fuentes_kb)"
            . ($hay_lugar
                ? " y cerrá con el link de la prueba textual (estado_sugerido link_enviado).\n"
                : ".\n")
            . "  - NO le ofrezcas la demo ni una llamada, NO devuelvas agendar_demo y NO le pidas disponibilidad.\n"
            . "    SALVO que el lead pida EXPLICITAMENTE una DEMO con sus palabras (un mensaje suyo, no el texto de un botón): ahí seguí el\n"
            . "    flujo de la demo de arriba.\n"
            . "  - La prueba se crea sólo desde el link: no la crees por WhatsApp ni le pidas la contraseña.\n";

        return $txt;
    }

    /**
     * La frase que tiene que aparecer en el mensaje para que el punto cuente como cubierto (el ancla, en castellano).
     *
     * @param string $punto Clave de {@see PUNTOS}.
     *
     * @return string
     */
    private static function frase_del_ancla(string $punto): string
    {
        switch ($punto) {
            case 'presentacion':
                return 'tiene que aparecer el nombre ComercioCity (presentarte como Martín, de ComercioCity).';
            case 'prueba_y_rubro':
                return 'tiene que decir que lo puede probar gratis y preguntar por su rubro.';
            case 'dos_formas':
                return 'tiene que nombrar la forma gratis y el pago único de ' . self::precio_de_implementacion() . '.';
            case 'pagina':
                return 'tiene que pasar la página comerciocity.com (sin ninguna ruta detrás).';
            default:
                return 'tiene que llevar el link de la prueba, textual: ' . LeadOfertaDePruebaService::link();
        }
    }

    // ------------------------------------------------------------------------------------------
    // Chequeo determinista sobre el paquete del agente
    // ------------------------------------------------------------------------------------------

    /**
     * Separa la pregunta final de un segmento que termina en «?» de lo que la precede.
     *
     * La pregunta final es la ORACIÓN que contiene el último «¿»: empieza después del último límite de oración
     * anterior a ese «¿» (un `.`, `!`, `…` o `?` seguido de espacio o salto, o un salto de línea) o al principio del
     * segmento si no hay ninguno. Así un giro antes del signo («Contame, ¿a qué se dedica tu negocio?») viaja con su
     * pregunta. `respuesta` es todo lo anterior a esa oración (sin espacios finales); si queda vacía, el segmento
     * entero es la pregunta. Sin «¿» en el segmento no se separa nada.
     *
     * @param string $segmento Último segmento de la IA, ya recortado y terminado en «?».
     *
     * @return array{respuesta: string, pregunta: string} `respuesta` queda '' cuando no hay nada antes de la oración
     *                                                    (y `pregunta` es entonces el segmento completo).
     */
    private static function separar_la_pregunta_final(string $segmento): array
    {
        $sin_separar = ['respuesta' => '', 'pregunta' => $segmento];

        /* Posición (en caracteres) del último «¿»: la pregunta final es la oración que lo contiene. */
        $signo = mb_strrpos($segmento, '¿');
        if ($signo === false) {
            return $sin_separar;
        }

        /* Lo anterior al «¿»; el último límite de oración que contiene marca dónde empieza la oración de la pregunta. */
        $antes  = mb_substr($segmento, 0, $signo);
        $inicio = 0;
        if (preg_match_all('/[.!…?](?=\s)|\R/u', $antes, $limites, PREG_OFFSET_CAPTURE) > 0) {
            $ultimo = end($limites[0]);
            /* El offset es en bytes: se pasa a caracteres y se avanza hasta después del límite. */
            $inicio = mb_strlen(substr($antes, 0, $ultimo[1])) + mb_strlen($ultimo[0]);
        }

        $respuesta = rtrim(mb_substr($segmento, 0, $inicio));
        if ($respuesta === '') {
            return $sin_separar;
        }

        return ['respuesta' => $respuesta, 'pregunta' => trim(mb_substr($segmento, $inicio))];
    }

    /**
     * Completa el paquete del agente con lo que todavía se le debe al lead del flujo de entrada.
     *
     * Se llama DESPUÉS del gate de respaldo documental, y no lo destraba nunca: si el gate frenó
     * (`$gate_rechazo`), si el paquete pide intervención humana o si no hay mensaje, devuelve el paquete intacto
     * (los puntos siguen debidos y decide la persona). Si no, por cada punto debido cuya ancla no aparece en
     * `mensaje_sugerido` agrega `"\n---\n"` y el texto literal, en el orden de {@see PUNTOS}, y deja asentado en
     * `razonamiento` «Flujo de entrada: se agregaron <puntos>».
     *
     * @param Lead                 $lead
     * @param array<string, mixed> $parsed       Paquete del agente.
     * @param bool                 $gate_rechazo true si el gate de respaldo frenó el paquete.
     *
     * @return array<string, mixed> El paquete, completado o intacto.
     */
    public static function completar_paquete(Lead $lead, array $parsed, bool $gate_rechazo): array
    {
        /* Un estado del embudo no retrocede (DECISIONES-E2 punto 9): vale también si el gate frenó el paquete. */
        if (isset($parsed['estado_sugerido'])) {
            $parsed['estado_sugerido'] = self::estado_sin_retroceso($lead, (string) $parsed['estado_sugerido']);
        }

        if ($gate_rechazo || ! empty($parsed['requiere_intervencion_humana'])) {
            return $parsed;
        }

        $mensaje = isset($parsed['mensaje_sugerido']) ? trim((string) $parsed['mensaje_sugerido']) : '';
        if ($mensaje === '') {
            return $parsed;
        }

        /* Si el último mensaje (segmento separado por `---`) de la IA termina en pregunta, esa pregunta tiene que seguir
           siendo lo último que lee el lead: los puntos que agrega el flujo van ANTES de ella y no detrás («pregunta →
           dos formas → página», ensayo 707). Sin pregunta final, los puntos van al final como siempre. */
        $segmentos      = preg_split('/\R---\R/u', $mensaje) ?: [$mensaje];
        $pregunta_final = trim((string) end($segmentos));
        $texto_base     = $mensaje;
        if (substr($pregunta_final, -1) === '?') {
            array_pop($segmentos);

            /* Si la pregunta viene pegada al final de una respuesta del mismo segmento («…todo en un solo lugar. ¿A qué se
               dedica tu negocio?»), se separa: la respuesta se queda donde estaba y sólo la pregunta pasa detrás de los
               puntos. Si no se puede aislar con seguridad, el segmento entero sigue siendo «la pregunta» (orden de antes). */
            $partes_de_la_pregunta = self::separar_la_pregunta_final($pregunta_final);
            if ($partes_de_la_pregunta['respuesta'] !== '') {
                $segmentos[]    = $partes_de_la_pregunta['respuesta'];
                $pregunta_final = $partes_de_la_pregunta['pregunta'];
            }
            $texto_base = trim(implode("\n---\n", array_map('trim', $segmentos)));
        } else {
            $pregunta_final = '';
        }

        /* Los textos de los puntos que faltan, cada uno con su separador por delante. */
        $agregados = [];
        $nuevos    = '';
        foreach (self::puntos_debidos($lead) as $punto) {
            if (self::cumple_el_ancla($punto, $mensaje)) {
                continue;
            }
            $nuevos     .= "\n---\n" . self::texto_del_punto($punto);
            $agregados[] = $punto;
        }

        if (empty($agregados)) {
            return $parsed;
        }

        /* Sin texto antes de la pregunta (la IA sólo preguntó) se saca el primer separador para no empezar con `---`. */
        $mensaje = $texto_base . $nuevos;
        if ($texto_base === '') {
            $mensaje = ltrim($nuevos, "\n-");
        }
        if ($pregunta_final !== '') {
            $mensaje .= "\n---\n" . $pregunta_final;
        }

        $parsed['mensaje_sugerido'] = $mensaje;

        $nota = 'Flujo de entrada: se agregaron ' . implode(', ', $agregados);
        $parsed['razonamiento'] = trim((string) ($parsed['razonamiento'] ?? '')) === ''
            ? $nota
            : trim((string) $parsed['razonamiento']) . ' ' . $nota;

        return $parsed;
    }

    // ------------------------------------------------------------------------------------------
    // Transiciones de estado del embudo
    // ------------------------------------------------------------------------------------------

    /**
     * Estados de ANTES, por estado del embudo, sobre los que un `estado_sugerido` no se aplica: `link_enviado` e
     * `implementacion_pedida` no retroceden. Avanzar (demo, prueba, cierre) sí se aplica.
     */
    public const ESTADOS_QUE_NO_RETROCEDEN = [
        'link_enviado'          => ['nuevo', 'contactado', 'calificado'],
        'implementacion_pedida' => ['nuevo', 'contactado', 'calificado', 'link_enviado'],
    ];

    /**
     * El estado que corresponde aplicar: el sugerido, salvo que baje a un lead de `link_enviado` o `implementacion_pedida` a un
     * estado de antes (el bloque de la demo directa le hace pedir `calificado` al agente en el turno siguiente al link). En ese
     * caso, el estado actual: ni se aplica ni queda el chip de cambio en el mensaje.
     *
     * @param Lead   $lead
     * @param string $estado_sugerido Slug que sugirió el agente.
     *
     * @return string
     */
    public static function estado_sin_retroceso(Lead $lead, string $estado_sugerido): string
    {
        $actual = (string) $lead->status;

        if (isset(self::ESTADOS_QUE_NO_RETROCEDEN[$actual])
            && in_array(trim($estado_sugerido), self::ESTADOS_QUE_NO_RETROCEDEN[$actual], true)) {
            return $actual;
        }

        return $estado_sugerido;
    }

    /**
     * Si el texto que se envió lleva el link de la prueba, pasa al lead de `nuevo`, `contactado` o `calificado` a
     * `link_enviado` y deja el evento de estado en el hilo. Sólo con el flujo prendido (el lead que no está en el
     * flujo no cambia de estado por esto). Cubre al agente y al setter que edita la sugerencia: lo que cuenta es
     * lo que salió.
     *
     * @param Lead   $lead
     * @param string $texto Texto efectivamente enviado.
     *
     * @return bool true si el lead cambió de estado.
     */
    public static function marcar_link_enviado(Lead $lead, string $texto): bool
    {
        if (! self::en_el_flujo($lead) || strpos($texto, LeadOfertaDePruebaService::link()) === false) {
            return false;
        }

        $anterior = (string) $lead->status;
        if (! in_array($anterior, self::ESTADOS_QUE_PASAN_A_LINK_ENVIADO, true)) {
            return false;
        }

        $nuevo = LeadPipelineStatus::ensure_exists('link_enviado');

        $lead->status = $nuevo->slug;
        $lead->save();

        LeadMessage::create([
            'lead_id'               => $lead->id,
            'sender'                => 'sistema',
            'content'               => 'Estado cambiado de "' . LeadPipelineStatus::label_for($anterior)
                . '" a "' . LeadPipelineStatus::label_for($nuevo->slug) . '": se le envió el link de la prueba.',
            'status'                => 'enviado',
            'is_followup'           => false,
            'is_status_event'       => true,
            'requiere_verificacion' => false,
        ]);

        LeadBroadcastService::emit_conversation_updated((int) $lead->id);

        /* E3: el lead entra en `link_enviado`: se cancelan los pasos de los estados anteriores y se programan los del link
           (2 h, 22 h y, con plantilla, día 3), anclados en el momento en que salió. Sale por el camino que sea (la IA, el
           setter o el literal del paso 2). */
        LeadEmbudoSeguimientosService::programar_para_estado($lead, Carbon::now());

        return true;
    }

    /**
     * El agente sugirió `implementacion_pedida`: el lead pasa a ese estado YA (aunque el mensaje quede pendiente de
     * aprobación) y se crea la tarea del embudo para el vendedor. Mismo patrón que `solicita_disponibilidad`.
     *
     * No pisa a un lead que ya está en la demo o más adelante (el flujo de la demo no se toca): sólo mueve desde
     * `nuevo`, `contactado`, `calificado` y `link_enviado`.
     *
     * @param Lead   $lead
     * @param string $estado_sugerido Slug que sugirió el agente.
     *
     * @return bool true si el lead quedó en `implementacion_pedida` por esta llamada.
     */
    public static function aplicar_implementacion_pedida(Lead $lead, string $estado_sugerido): bool
    {
        if ($estado_sugerido !== 'implementacion_pedida') {
            return false;
        }

        $anterior = (string) $lead->status;
        if ($anterior === 'implementacion_pedida') {
            return false;
        }
        if (! in_array($anterior, self::ESTADOS_CON_EL_BLOQUE, true)) {
            return false;
        }

        LeadPipelineStatus::ensure_exists('implementacion_pedida');
        $lead->status = 'implementacion_pedida';
        $lead->save();

        LeadTareaVendedorService::crear($lead, 'implementacion_pedida');

        return true;
    }

    // ------------------------------------------------------------------------------------------
    // Apoyo
    // ------------------------------------------------------------------------------------------

    /**
     * El texto guardado en `admin_settings` si hay uno, si no el por defecto, con los placeholders resueltos.
     *
     * @param string $clave Clave de `admin_settings`.
     * @param string $por_defecto Texto por defecto.
     *
     * @return string
     */
    private static function texto_guardado_o_por_defecto(string $clave, string $por_defecto): string
    {
        $guardado = trim((string) AdminSetting::get($clave, ''));
        $plantilla = $guardado !== '' ? $guardado : $por_defecto;

        return trim(strtr($plantilla, [
            '{dias_prueba}'          => (string) PruebaSettings::dias_default(),
            '{precio_implementacion}' => self::precio_de_implementacion(),
            '{link_prueba}'          => LeadOfertaDePruebaService::link(),
        ]));
    }
}

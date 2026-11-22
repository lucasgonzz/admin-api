<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadPipelineStatus;
use App\Services\Prueba\PruebaPoolService;
use App\Services\Prueba\PruebaSettings;
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

    /** Clave de implementación que se nombra por defecto en las dos formas. */
    public const CLAVE_IMPLEMENTACION_DEFAULT = 'gestion';

    /** Estados en los que el lead puede deber puntos del flujo. */
    public const ESTADOS_CON_PUNTOS = ['nuevo', 'contactado', 'calificado'];

    /** Estados en los que el agente recibe el bloque del flujo (los de arriba más el del link ya enviado). */
    public const ESTADOS_CON_EL_BLOQUE = ['nuevo', 'contactado', 'calificado', 'link_enviado'];

    /** Estados desde los que enviar el link de la prueba mueve al lead a `link_enviado`. */
    public const ESTADOS_QUE_PASAN_A_LINK_ENVIADO = ['nuevo', 'contactado', 'calificado'];

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
        if (! self::activo()) {
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

        $es_de_la_web = self::es_lead_de_la_web($lead);
        foreach (self::PUNTOS as $punto => $datos) {
            if ($datos['paso'] !== 2 || isset($entregados[$punto])) {
                continue;
            }
            /* El link sólo se le debe al lead de la web, con el link prendido y lugar en el pool de pruebas. */
            if ($punto === 'link_prueba' && ! ($es_de_la_web && LeadOfertaDePruebaService::activa() && self::hay_lugar_en_el_pool())) {
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
        if (empty($debidos) || self::tiene_pregunta($texto)) {
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
        if (! self::activo()) {
            return false;
        }

        $lead = Lead::query()->find($lead_id);
        if ($lead === null || (string) $lead->status !== 'nuevo') {
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
        if (! self::activo()) {
            return '';
        }

        if (! in_array((string) $lead->status, self::ESTADOS_CON_EL_BLOQUE, true) || $lead->demo_id || LeadOfertaDePruebaService::tiene_prueba($lead)) {
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
            $txt .= "  - Hoy NO hay lugar para crear pruebas nuevas: si quiere probar, NO le pases ningún link. Decile «Juan te escribe para darte el\n"
                . "    acceso» y devolvé requiere_intervencion_humana: true con el motivo.\n";
        }

        $txt .= "  - Si quiere que se lo carguen nosotros o pide la implementación: decile que lo llama Juan, del equipo, hoy, sin pedirle hora y\n"
            . "    sin cobrar, y devolvé estado_sugerido: \"implementacion_pedida\".\n"
            . "  - No le pidas hora. No le pidas datos para calificarlo más allá de lo que ya pide el flujo.\n"
            . "  - Si pregunta precios, dale los de PRECIOS VIGENTES (citá \"" . PreciosVigentesSettings::FUENTE_PARA_EL_GATE . "\" en fuentes_kb).\n"
            . "  - NO le ofrezcas la demo ni una llamada, NO devuelvas agendar_demo y NO le pidas disponibilidad, SALVO que el lead pida\n"
            . "    EXPLICITAMENTE una DEMO con sus palabras (un mensaje suyo, no el texto de un botón): ahí seguí el flujo de la demo de arriba.\n"
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
        if ($gate_rechazo || ! empty($parsed['requiere_intervencion_humana'])) {
            return $parsed;
        }

        $mensaje = isset($parsed['mensaje_sugerido']) ? trim((string) $parsed['mensaje_sugerido']) : '';
        if ($mensaje === '') {
            return $parsed;
        }

        $agregados = [];
        foreach (self::puntos_debidos($lead) as $punto) {
            if (self::cumple_el_ancla($punto, $mensaje)) {
                continue;
            }
            $mensaje    .= "\n---\n" . self::texto_del_punto($punto);
            $agregados[] = $punto;
        }

        if (empty($agregados)) {
            return $parsed;
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
        if (! self::activo() || strpos($texto, LeadOfertaDePruebaService::link()) === false) {
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

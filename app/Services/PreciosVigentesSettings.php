<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Fuente única de los precios vigentes de ComercioCity (misión embudo-whatsapp-precios, etapa E1).
 *
 * Antes los precios vivían en constantes de `ContractPreciosService` y escritos a mano en el recurso
 * `precios.md` del agente de leads. Ahora hay UNA sola clave de `admin_settings` (`precios_vigentes`)
 * con un JSON que sigue el contrato de §2.6 del PLAN. De ahí leen:
 *  - la pantalla de Cuenta → «Precios vigentes» del admin-spa (GET / PUT autenticados);
 *  - la landing, por el endpoint público de sólo lectura (`to_array_publico()`);
 *  - Martín (agente de leads), que recibe en cada turno el bloque de {@see bloque_para_el_agente()};
 *  - el «desde» de la oferta de la prueba ({@see LeadOfertaDePruebaService::precio_desde()}).
 *
 * Mismo patrón que `CotizadorSettings` (servicio estático con `to_array()` y `persist_from_request()`).
 *
 * 🔴 Las claves de planes, implementaciones y tarifas de Meta son FIJAS (la landing y el SPA las usan de
 * id); lo editable son los valores. Si la clave no existe, el JSON está roto o trae un valor inválido
 * (editado a mano en la base), se usan los DEFAULTS y se loguea un warning: un precio equivocado sale
 * sin intermediarios por WhatsApp a los leads y en la landing, así que la fuente nunca devuelve basura.
 * Las claves que falten en un JSON viejo se completan con el default.
 *
 * 🔴 Esta etapa NO toca `ContractPreciosService` (contratos y cobranza siguen con sus constantes hasta E4).
 */
class PreciosVigentesSettings
{
    /** Clave de `admin_settings` donde vive el JSON completo del contrato de §2.6. */
    public const KEY = 'precios_vigentes';

    /** Fuente que Martín cita en `fuentes_kb` para respaldar un precio ante el gate (ver LeadAiService). */
    public const FUENTE_PARA_EL_GATE = 'precios_vigentes';

    /** Tope de un monto en pesos: la guarda contra el dedo pegado (un cero de más llega a un lead). */
    public const MAX_MONTO = 99999999;

    /** Tope de meses del descuento de bienvenida. */
    public const MAX_MESES_DESCUENTO = 24;

    /** Fecha de los valores por defecto (los del pedido de Lucas), para el encabezado del bloque de Martín. */
    private const FECHA_DE_LOS_DEFAULTS = '21/11/2026';

    /**
     * Valores del pedido de Lucas (21/11/2026). Es el contrato de §2.6 sin los sellos `actualizado_*`.
     *
     * @var array<string, mixed>
     */
    public const DEFAULTS = [
        /* Versión 2 (21/11/2026): oferta simple. Sin compatibilidad hacia atrás con la forma 1 (lite/full, seis items). */
        'version' => 2,
        'moneda'  => 'ARS',
        'gestion' => [
            'planes' => [
                ['clave' => 'inicial', 'nombre' => 'Inicial', 'desde' => 0, 'hasta' => 200, 'usuarios' => 1, 'abono' => 55900, 'excedente' => 760],
                ['clave' => 'intermedio', 'nombre' => 'Intermedio', 'desde' => 201, 'hasta' => 600, 'usuarios' => 2, 'abono' => 85400, 'excedente' => 570],
                ['clave' => 'empresa', 'nombre' => 'Empresa', 'desde' => 601, 'hasta' => null, 'usuarios' => 3, 'abono' => 263000, 'excedente' => null],
            ],
            'usuario_extra'        => 30400,
            'descuento_bienvenida' => ['porcentaje' => 50, 'meses' => 3],
        ],
        'tienda'  => ['mensualidad' => 44900],
        /* Agentes: UN solo paquete (incluye el agente de WhatsApp). La mensualidad depende del paquete de tokens:
           ganancia fija + los USD de tokens cotizados al dólar blue de venta. 🔴 `mensualidad` es de SÓLO LECTURA:
           la calcula la API en `recalcular()`; lo que valga acá es sólo el punto de partida. */
        'agentes' => [
            'incluye'                  => 'Agente de WhatsApp con la migración o configuración inicial del número',
            'uso_de_tokens'            => 'Hacerle preguntas y pedirle cosas a los agentes, y generar imágenes para el negocio',
            'cotizacion_usd'           => ['valor' => 1675, 'referencia' => 'Dólar blue, venta', 'fecha' => '2026-11-20'],
            'ganancia_por_paquete'     => 30000,
            'aviso_consumo_porcentaje' => 80,
            'paquetes'                 => [
                ['clave' => 'inicial', 'nombre' => 'Inicial', 'tokens_usd' => 10, 'mensualidad' => 46750],
                ['clave' => 'pro', 'nombre' => 'Pro', 'tokens_usd' => 30, 'mensualidad' => 80250],
                ['clave' => 'max', 'nombre' => 'Max', 'tokens_usd' => 70, 'mensualidad' => 147250],
            ],
        ],
        /* Implementación: tres productos al mismo precio; los tres juntos con descuento. No hay combos de dos.
           🔴 `precio_lista` y `precio` de `tres_juntos` son de SÓLO LECTURA (los calcula `recalcular()`). */
        'implementaciones' => [
            'anticipo_porcentaje' => 50,
            'reembolsable'        => false,
            'items'               => [
                ['clave' => 'gestion', 'nombre' => 'Sistema de gestión', 'precio' => 628400],
                ['clave' => 'tienda', 'nombre' => 'Tienda online', 'precio' => 628400],
                ['clave' => 'agentes', 'nombre' => 'Agentes', 'precio' => 628400],
            ],
            'tres_juntos'         => ['descuento_porcentaje' => 30, 'precio_lista' => 1885200, 'precio' => 1319640],
        ],
        'costos_meta' => [
            'vigente_desde'                  => '2026-10-01',
            'mensajes_libres_gratis_por_mes' => 1000,
            'tarifas'                        => [
                ['clave' => 'marketing', 'nombre' => 'Plantilla de marketing', 'usd' => 0.0618, 'ars' => 89.56],
                ['clave' => 'utilidad', 'nombre' => 'Plantilla de utilidad', 'usd' => 0.026, 'ars' => 37.68],
                ['clave' => 'autenticacion', 'nombre' => 'Plantilla de autenticación', 'usd' => 0.026, 'ars' => 37.68],
                ['clave' => 'mensaje_libre', 'nombre' => 'Mensaje libre dentro de las 24 h', 'usd' => 0.026, 'ars' => 37.68],
            ],
        ],
    ];

    /* ==================================================================================
     * Lectura
     * ================================================================================== */

    /**
     * El contrato de §2.6 completo, con `actualizado_en` y `actualizado_por` (null si nunca se guardó).
     *
     * @return array<string, mixed>
     */
    public static function to_array(): array
    {
        return self::cargar();
    }

    /**
     * El mismo contrato SIN `actualizado_por`: el nombre de quien editó no sale a internet.
     *
     * @return array<string, mixed>
     */
    public static function to_array_publico(): array
    {
        $contrato = self::to_array();
        unset($contrato['actualizado_por']);

        return $contrato;
    }

    /**
     * Un plan de gestión por su clave fija (`inicial`, `intermedio`, `empresa`).
     *
     * @param string $clave Clave del plan.
     *
     * @return array<string, mixed>|null null si la clave no existe.
     */
    public static function plan(string $clave): ?array
    {
        foreach (self::cargar()['gestion']['planes'] as $plan) {
            if ($plan['clave'] === $clave) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * El abono con el descuento de bienvenida aplicado (el de los primeros meses), redondeado a 2 decimales.
     * No mira si hay promo vigente: eso lo decide {@see hay_descuento_de_bienvenida()}.
     *
     * @param float $abono Abono mensual lleno.
     *
     * @return float
     */
    public static function abono_con_descuento(float $abono): float
    {
        $porcentaje = (float) self::cargar()['gestion']['descuento_bienvenida']['porcentaje'];

        return round($abono * (100 - $porcentaje) / 100, 2);
    }

    /**
     * ¿Hay descuento de bienvenida vigente? Sí cuando el porcentaje y los meses son mayores a cero.
     *
     * @return bool
     */
    public static function hay_descuento_de_bienvenida(): bool
    {
        $descuento = self::cargar()['gestion']['descuento_bienvenida'];

        return (float) $descuento['porcentaje'] > 0 && (int) $descuento['meses'] > 0;
    }

    /**
     * Un ítem de implementación por su clave fija.
     *
     * @param string $clave Clave del ítem (`gestion`, `tienda`, `agentes`).
     *
     * @return array<string, mixed>|null null si la clave no existe.
     */
    public static function implementacion(string $clave): ?array
    {
        foreach (self::cargar()['implementaciones']['items'] as $item) {
            if ($item['clave'] === $clave) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Lo que se paga para arrancar una implementación: el precio por el porcentaje de anticipo, a 2 decimales.
     *
     * @param float $precio Precio total de la implementación.
     *
     * @return float
     */
    public static function anticipo_de(float $precio): float
    {
        return round($precio * (float) self::cargar()['implementaciones']['anticipo_porcentaje'] / 100, 2);
    }

    /* ==================================================================================
     * Escritura
     * ================================================================================== */

    /**
     * Valida y guarda el contrato que llega del SPA, y sella `actualizado_en` y `actualizado_por`.
     *
     * 🔴 Las claves que no vengan en el request conservan el valor guardado (un SPA viejo que no manda
     * un campo nuevo no lo pisa). Si algo es inválido NO se guarda nada y se tira un 422 con un mensaje
     * legible y los errores por campo (formato Laravel).
     *
     * @param array<string, mixed> $datos    Cuerpo del PUT (el contrato de §2.6, sin `actualizado_*`).
     * @param int|null             $admin_id Admin que edita (para `actualizado_por`).
     *
     * @return array<string, mixed> El contrato guardado, igual que `to_array()`.
     *
     * @throws ValidationException Si algún valor es inválido.
     */
    public static function persist_from_request(array $datos, ?int $admin_id = null): array
    {
        $errores = [];

        /* Sobre lo vigente: lo que el request no menciona queda como está. Las claves desconocidas se descartan. */
        $candidato = self::fusionar(self::cargar(), $datos, 'contrato', $errores);

        self::validar($candidato, $errores);

        if (! empty($errores)) {
            /* Un campo por clave y un único texto por campo, como el formato de errores de Laravel. */
            throw ValidationException::withMessages(
                array_map(function ($texto) {
                    return [$texto];
                }, $errores)
            );
        }

        $candidato = self::normalizar($candidato);

        $candidato['actualizado_en']  = now()->toIso8601String();
        $candidato['actualizado_por'] = self::nombre_del_admin($admin_id);

        AdminSetting::set(self::KEY, json_encode($candidato, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::to_array();
    }

    /**
     * Mensaje legible de un 422 de esta fuente (lo arma el controlador a partir de los errores).
     *
     * @param array<string, array<int, string>> $errores Errores por campo, como los entrega ValidationException.
     *
     * @return string
     */
    public static function mensaje_de_errores(array $errores): string
    {
        $planos = [];

        foreach ($errores as $lista) {
            foreach ((array) $lista as $texto) {
                $planos[] = (string) $texto;
            }
        }

        if (empty($planos)) {
            return 'Los precios no se guardaron: hay valores inválidos.';
        }

        return 'Los precios no se guardaron: ' . $planos[0]
            . (count($planos) > 1 ? ' (y ' . (count($planos) - 1) . ' error(es) más)' : '');
    }

    /* ==================================================================================
     * Formato
     * ================================================================================== */

    /**
     * Un monto en pesos: «$628.400» (sin decimales si es entero) o «$89,56».
     *
     * @param float $monto Monto en pesos.
     *
     * @return string
     */
    public static function formato_pesos(float $monto): string
    {
        $monto = round($monto, 2);

        if (floor($monto) === $monto) {
            return '$' . number_format($monto, 0, ',', '.');
        }

        return '$' . number_format($monto, 2, ',', '.');
    }

    /**
     * Un monto en dólares con los decimales que tenga (hasta 4) y coma decimal: «0,0618», «0,026».
     *
     * @param float $monto Monto en USD.
     *
     * @return string
     */
    private static function formato_usd(float $monto): string
    {
        $texto = number_format($monto, 4, ',', '');
        $texto = rtrim($texto, '0');

        return rtrim($texto, ',');
    }

    /* ==================================================================================
     * Bloque para Martín
     * ================================================================================== */

    /**
     * El bloque «PRECIOS VIGENTES» que Martín recibe en cada llamada (§2.1.1 del PLAN). Sale de la fuente,
     * así que un cambio en la pantalla del admin cambia lo que Martín dice en el turno siguiente. Las frases
     * fijas son las del plan; los montos y fechas, de la fuente.
     *
     * Cuenta como respaldo del gate de `fuentes_kb` bajo el nombre {@see FUENTE_PARA_EL_GATE}.
     *
     * @return string Texto sin saltos de línea al principio ni al final.
     */
    public static function bloque_para_el_agente(): string
    {
        $contrato = self::cargar();
        $gestion  = $contrato['gestion'];
        $hay_promo = self::hay_descuento_de_bienvenida();
        $promo     = $gestion['descuento_bienvenida'];

        /* Fecha del encabezado: la de la última edición, o la de los valores por defecto. */
        $fecha = self::FECHA_DE_LOS_DEFAULTS;
        if (! empty($contrato['actualizado_en'])) {
            try {
                $fecha = \Carbon\Carbon::parse($contrato['actualizado_en'])->format('d/m/Y');
            } catch (\Throwable $e) {
                $fecha = self::FECHA_DE_LOS_DEFAULTS;
            }
        }

        $lineas   = [];
        $lineas[] = 'PRECIOS VIGENTES (fuente: admin de ComercioCity, actualizados el ' . $fecha . '). PISAN cualquier cifra de los';
        $lineas[] = 'recursos (precios, posicionamiento, reglas) y del manual. Si afirmás un precio, citá "' . self::FUENTE_PARA_EL_GATE . '" en';
        $lineas[] = 'fuentes_kb. Plus y A medida ya no se ofrecen.';

        $encabezado_gestion = '- Sistema de gestión, abono mensual final (no se suma IVA)';
        if ($hay_promo) {
            $encabezado_gestion .= ', ' . self::numero_sin_ceros($promo['porcentaje']) . ' % de descuento los primeros ' . (int) $promo['meses'] . ' meses';
        }
        $lineas[] = $encabezado_gestion . ':';

        foreach ($gestion['planes'] as $plan) {
            $rango = self::describir_rango($plan);
            $usuarios = (int) $plan['usuarios'] . ' ' . ((int) $plan['usuarios'] === 1 ? 'usuario' : 'usuarios');

            $linea = '  - ' . $plan['nombre'] . ': ' . $rango . ', ' . $usuarios . ': ' . self::formato_pesos((float) $plan['abono']);

            if ($hay_promo) {
                $linea .= ' (' . self::formato_pesos(self::abono_con_descuento((float) $plan['abono']))
                    . ' los primeros ' . (int) $promo['meses'] . ' meses)';
            }

            $linea .= '.';

            if ($plan['excedente'] !== null) {
                $linea .= ' Operación excedente: ' . self::formato_pesos((float) $plan['excedente']) . '.';
            }

            $lineas[] = $linea;
        }

        $lineas[] = '  - Usuario extra: ' . self::formato_pesos((float) $gestion['usuario_extra']) . ' por mes, en cualquier plan.';
        $lineas[] = '- Tienda online: ' . self::formato_pesos((float) $contrato['tienda']['mensualidad']) . ' por mes.';

        /* Agentes: un solo paquete. 🔴 Martín NUNCA dice los USD de tokens ni la ganancia: sólo la mensualidad en
           pesos y cuántas veces los tokens del primer paquete tiene cada uno (calculado sobre `tokens_usd`, que no
           se muestra). */
        $agentes  = $contrato['agentes'];
        $paquetes = $agentes['paquetes'];
        $base_tokens = (float) $paquetes[0]['tokens_usd'];

        $lineas[] = '- Agentes de IA: un solo paquete, incluye: ' . self::minuscula_inicial((string) $agentes['incluye'])
            . '. La mensualidad depende del paquete de tokens. Los tokens sirven para ' . self::minuscula_inicial((string) $agentes['uso_de_tokens']) . ':';

        foreach ($paquetes as $indice => $paquete) {
            $linea_paquete = '  - ' . $paquete['nombre'] . ': ' . self::formato_pesos((float) $paquete['mensualidad']) . ' por mes';

            /* El paréntesis compara contra el primer paquete; si ese tuviera 0 tokens no hay con qué comparar. */
            if ($indice > 0 && $base_tokens > 0) {
                $linea_paquete .= ' (' . self::numero_sin_ceros((float) $paquete['tokens_usd'] / $base_tokens)
                    . ' veces los tokens de ' . $paquetes[0]['nombre'] . ')';
            }

            $lineas[] = $linea_paquete . '.';
        }

        $lineas[] = '  - Al llegar al ' . self::numero_sin_ceros($agentes['aviso_consumo_porcentaje']) . ' % de los tokens del mes se le avisa'
            . ' y se le propone pasar al paquete siguiente, con su OK; el agente no se corta.';

        $implementaciones = $contrato['implementaciones'];

        /* Anticipo guardado: toda cifra de la implementación sale de acá. Con 0 o 100 no hay «arrancar con una parte». */
        $anticipo = (float) $implementaciones['anticipo_porcentaje'];
        $hay_anticipo_parcial = $anticipo > 0 && $anticipo < 100;
        $anticipo_texto = self::formato_porcentaje($anticipo);

        if ($hay_anticipo_parcial) {
            $detalle_anticipo = 'se arranca con el ' . $anticipo_texto . ' %, que es el valor entre paréntesis';
        } elseif ($anticipo >= 100) {
            $detalle_anticipo = 'se paga completo';
        } else {
            $detalle_anticipo = 'sin anticipo';
        }

        $lineas[] = '- Implementación (pago único, en pesos; ' . $detalle_anticipo . '; '
            . ($implementaciones['reembolsable'] ? 'reembolsable' : 'no reembolsable') . '):';

        foreach ($implementaciones['items'] as $item) {
            $linea_item = '  - ' . $item['nombre'] . ': ' . self::formato_pesos((float) $item['precio']);

            /* El valor entre paréntesis sólo existe cuando el anticipo es una parte del precio. */
            if ($hay_anticipo_parcial) {
                $linea_item .= ' (' . self::formato_pesos(self::anticipo_de((float) $item['precio'])) . ')';
            }

            $lineas[] = $linea_item . '.';
        }

        /* Los tres juntos: con descuento > 0 se dice el precio y el de lista; sin descuento no hay línea de promo.
           No hay combos de dos: dos productos se suman. */
        $tres_juntos = $implementaciones['tres_juntos'];

        if ((float) $tres_juntos['descuento_porcentaje'] > 0) {
            $linea_tres = '  - Los tres juntos: ' . self::numero_sin_ceros($tres_juntos['descuento_porcentaje']) . ' % de descuento, '
                . self::formato_pesos((float) $tres_juntos['precio']) . ' en lugar de ' . self::formato_pesos((float) $tres_juntos['precio_lista']);

            if ($hay_anticipo_parcial) {
                $linea_tres .= ' (' . self::formato_pesos(self::anticipo_de((float) $tres_juntos['precio'])) . ')';
            }

            $lineas[] = $linea_tres . '. No hay combos de dos: dos productos se suman sin descuento.';
        } else {
            $lineas[] = '  - No hay combos: cada producto se suma.';
        }

        /* Frase final sobre el anticipo: con 50 dice «la mitad (50 %)»; con otro parcial, su porcentaje; con 0 o 100, nada. */
        $frase_anticipo = '';
        if ($hay_anticipo_parcial) {
            $frase_anticipo = $anticipo === 50.0
                ? ' Se puede arrancar con la mitad (' . $anticipo_texto . ' %).'
                : ' Se puede arrancar pagando el ' . $anticipo_texto . ' %.';
        }

        /* Frase fija: la implementación asistida NO es obligatoria (la oferta de la prueba dice que se puede arrancar solo). */
        $lineas[] = '  - La implementación asistida es OPCIONAL: el lead puede arrancar por su cuenta gratis con la prueba (crea su prueba y sube su'
            . ' Excel con un video que lo guía). Quien paga la implementación recibe la carga de productos, precios, fotos y descripciones hecha'
            . ' por ComercioCity y desarrollos a medida de por vida.' . $frase_anticipo;

        $meta = $contrato['costos_meta'];
        $desde = \DateTime::createFromFormat('Y-m-d', (string) $meta['vigente_desde']);
        $lineas[] = '- Costos de Meta por WhatsApp (los paga el negocio aparte, en su cuenta de WhatsApp), vigentes desde el '
            . ($desde ? $desde->format('d/m/Y') : (string) $meta['vigente_desde']) . ':';
        $lineas[] = '  - ' . number_format((int) $meta['mensajes_libres_gratis_por_mes'], 0, ',', '.')
            . ' mensajes libres gratis por mes por número; no se acumulan.';

        foreach ($meta['tarifas'] as $tarifa) {
            $lineas[] = '  - ' . $tarifa['nombre'] . ': USD ' . self::formato_usd((float) $tarifa['usd'])
                . ' (unos ' . self::formato_pesos((float) $tarifa['ars']) . ') por mensaje.';
        }

        return implode("\n", $lineas);
    }

    /**
     * Pone en minúscula la primera letra de un texto (multibyte): «Hacerle preguntas…» → «hacerle preguntas…».
     * Se usa para insertar los textos editables de Agentes en medio de una oración del bloque de Martín.
     *
     * @param string $texto Texto original.
     *
     * @return string
     */
    private static function minuscula_inicial(string $texto): string
    {
        $texto = trim($texto);

        return mb_strtolower(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
    }

    /**
     * Describe el rango de operaciones de un plan: «hasta 200 operaciones por mes», «de 201 a 600 operaciones
     * por mes» u «operaciones ilimitadas».
     *
     * @param array<string, mixed> $plan Plan del contrato.
     *
     * @return string
     */
    private static function describir_rango(array $plan): string
    {
        if ($plan['hasta'] === null) {
            return 'operaciones ilimitadas';
        }

        if ((int) $plan['desde'] === 0) {
            return 'hasta ' . (int) $plan['hasta'] . ' operaciones por mes';
        }

        return 'de ' . (int) $plan['desde'] . ' a ' . (int) $plan['hasta'] . ' operaciones por mes';
    }

    /**
     * Un porcentaje tal cual está guardado, sin ceros de más: 50 → «50», 12.5 → «12,5». Es la única forma de
     * mostrarlo, tanto en el bloque de Martín como en la oferta de la prueba.
     *
     * @param mixed $valor Porcentaje.
     *
     * @return string
     */
    public static function formato_porcentaje($valor): string
    {
        return self::numero_sin_ceros($valor);
    }

    /**
     * Un número sin ceros de más: 50 → «50», 12.5 → «12,5».
     *
     * @param mixed $valor Número.
     *
     * @return string
     */
    private static function numero_sin_ceros($valor): string
    {
        $texto = rtrim(rtrim(number_format((float) $valor, 2, ',', ''), '0'), ',');

        return $texto === '' ? '0' : $texto;
    }

    /* ==================================================================================
     * Carga, fusión y normalización
     * ================================================================================== */

    /**
     * Lee la fuente: lo guardado completado con los defaults. Si no hay nada, el JSON está roto o trae un
     * valor inválido, devuelve los defaults y loguea un warning.
     *
     * @return array<string, mixed> Contrato de §2.6 más `actualizado_en` / `actualizado_por` (null si no hay).
     */
    private static function cargar(): array
    {
        try {
            return self::cargar_sin_red();
        } catch (\Throwable $e) {
            /* Red de seguridad: ningún contenido de la base (ni una falla al leerla) puede tirar abajo a Martín
               ni al endpoint público. Se registra y se sirven los precios por defecto. */
            try {
                Log::warning('PreciosVigentesSettings: no se pudo leer la fuente guardada; se usan los precios por defecto.', [
                    'key'       => self::KEY,
                    'excepcion' => get_class($e),
                    'mensaje'   => $e->getMessage(),
                ]);
            } catch (\Throwable $ignorada) {
                /* Si ni el log responde, igual se devuelven los defaults. */
            }

            return self::defaults_completos();
        }
    }

    /**
     * Los valores por defecto ya normalizados y con los sellos `actualizado_*` en null.
     *
     * @return array<string, mixed>
     */
    private static function defaults_completos(): array
    {
        $defaults = self::normalizar(self::DEFAULTS);
        $defaults['actualizado_en']  = null;
        $defaults['actualizado_por'] = null;

        return $defaults;
    }

    /**
     * Lectura de la fuente sin la red de seguridad de {@see cargar()} (que la envuelve).
     *
     * @return array<string, mixed>
     */
    private static function cargar_sin_red(): array
    {
        $defaults = self::defaults_completos();

        $crudo = AdminSetting::get(self::KEY);

        if ($crudo === null || trim((string) $crudo) === '') {
            return $defaults;
        }

        $guardado = json_decode((string) $crudo, true);

        if (! is_array($guardado)) {
            Log::warning('PreciosVigentesSettings: el JSON guardado en admin_settings está roto; se usan los precios por defecto.', [
                'key' => self::KEY,
            ]);

            return $defaults;
        }

        /* JSON de la forma 1 (Agentes Lite/Full, seis items): lo que no encaja en la forma nueva se descarta y se
           completa con los defaults (lo hace `fusionar`, que sólo toma las claves que conoce). Queda registrado. */
        if (self::es_forma_vieja($guardado)) {
            Log::warning('PreciosVigentesSettings: el JSON guardado tiene la forma vieja (version 1: Agentes Lite/Full, seis implementaciones); se normaliza a la oferta simple y lo que no encaja se completa con los precios por defecto.', [
                'key'     => self::KEY,
                'version' => $guardado['version'] ?? null,
            ]);
        }

        $errores = [];

        $contrato = self::fusionar($defaults, $guardado, 'contrato', $errores);

        self::validar($contrato, $errores);

        if (! empty($errores)) {
            Log::warning('PreciosVigentesSettings: la fuente guardada trae valores inválidos; se usan los precios por defecto.', [
                'key'     => self::KEY,
                'errores' => $errores,
            ]);

            return $defaults;
        }

        $contrato = self::normalizar($contrato);

        $contrato['actualizado_en']  = isset($guardado['actualizado_en']) && is_string($guardado['actualizado_en']) ? $guardado['actualizado_en'] : null;
        $contrato['actualizado_por'] = isset($guardado['actualizado_por']) && is_string($guardado['actualizado_por']) ? $guardado['actualizado_por'] : null;

        return $contrato;
    }

    /**
     * ¿El JSON guardado es de la forma 1 (antes de la oferta simple)? Sí si trae `agentes.lite` / `agentes.full`,
     * items de implementación que ya no existen, o una versión distinta de la actual.
     *
     * @param array<string, mixed> $guardado JSON decodificado de `admin_settings`.
     *
     * @return bool
     */
    private static function es_forma_vieja(array $guardado): bool
    {
        if (isset($guardado['version']) && (int) $guardado['version'] !== (int) self::DEFAULTS['version']) {
            return true;
        }

        if (isset($guardado['agentes']) && is_array($guardado['agentes'])
            && (array_key_exists('lite', $guardado['agentes']) || array_key_exists('full', $guardado['agentes']))) {
            return true;
        }

        $claves_vigentes = array_column(self::DEFAULTS['implementaciones']['items'], 'clave');

        if (isset($guardado['implementaciones']['items']) && is_array($guardado['implementaciones']['items'])) {
            foreach ($guardado['implementaciones']['items'] as $item) {
                if (is_array($item) && isset($item['clave']) && is_string($item['clave']) && ! in_array($item['clave'], $claves_vigentes, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Completa `$base` con los valores de `$nuevo`, respetando la forma de `$base`: sólo se toman las
     * claves que `$base` conoce, las listas (planes, ítems, tarifas) se emparejan por `clave`, y una
     * estructura que no coincide con la esperada se anota como error en vez de ignorarse.
     *
     * @param array<string, mixed>  $base    Forma y valores de partida.
     * @param mixed                 $nuevo   Lo que llega (request o JSON guardado).
     * @param string                $ruta    Ruta del campo, para los errores.
     * @param array<string, string> $errores Errores acumulados por ruta (por referencia).
     *
     * @return array<string, mixed>
     */
    private static function fusionar(array $base, $nuevo, string $ruta, array &$errores): array
    {
        if (! is_array($nuevo)) {
            $errores[$ruta] = 'El bloque «' . $ruta . '» tiene que ser un objeto.';

            return $base;
        }

        $resultado = $base;

        foreach ($base as $clave => $valor_base) {
            /* Los sellos los pone el servidor y `version` / `moneda` son fijos: nunca se toman del request. */
            if (in_array($clave, ['actualizado_en', 'actualizado_por', 'version', 'moneda'], true)) {
                continue;
            }

            if (! array_key_exists($clave, $nuevo)) {
                continue;
            }

            $valor_nuevo = $nuevo[$clave];
            $subruta     = $ruta . '.' . $clave;

            if (is_array($valor_base) && self::es_lista_de_claves($valor_base)) {
                $resultado[$clave] = self::fusionar_lista($valor_base, $valor_nuevo, $subruta, $errores);
            } elseif (is_array($valor_base)) {
                $resultado[$clave] = self::fusionar($valor_base, $valor_nuevo, $subruta, $errores);
            } else {
                $resultado[$clave] = $valor_nuevo;
            }
        }

        return $resultado;
    }

    /**
     * Fusiona una lista de objetos con `clave` fija: cada elemento de la base toma los valores del
     * elemento de `$nuevo` que tenga su misma `clave`. Los elementos que no vengan quedan como estaban.
     *
     * @param array<int, array<string, mixed>> $base    Lista de partida.
     * @param mixed                            $nuevo   Lista que llega.
     * @param string                           $ruta    Ruta del campo, para los errores.
     * @param array<string, string>            $errores Errores acumulados (por referencia).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fusionar_lista(array $base, $nuevo, string $ruta, array &$errores): array
    {
        if (! is_array($nuevo)) {
            $errores[$ruta] = 'La lista «' . $ruta . '» tiene que ser un arreglo.';

            return $base;
        }

        /* Una lista tiene que llegar como arreglo de objetos: un objeto suelto (`{clave: ...}`) o elementos que
           no son objetos no se pueden emparejar y antes se ignoraban en silencio con un 200. */
        if (! empty($nuevo) && array_keys($nuevo) !== range(0, count($nuevo) - 1)) {
            $errores[$ruta] = 'La lista «' . $ruta . '» tiene que ser un arreglo de objetos, no un objeto suelto.';

            return $base;
        }

        foreach ($nuevo as $elemento_nuevo) {
            if (! is_array($elemento_nuevo)) {
                $errores[$ruta] = 'La lista «' . $ruta . '» tiene que ser un arreglo de objetos.';

                return $base;
            }
        }

        $resultado = [];

        foreach ($base as $elemento_base) {
            $encontrado = null;

            foreach ($nuevo as $elemento_nuevo) {
                if (is_array($elemento_nuevo) && isset($elemento_nuevo['clave']) && $elemento_nuevo['clave'] === $elemento_base['clave']) {
                    $encontrado = $elemento_nuevo;
                    break;
                }
            }

            $resultado[] = $encontrado === null
                ? $elemento_base
                : self::fusionar($elemento_base, $encontrado, $ruta . '.' . $elemento_base['clave'], $errores);
        }

        return $resultado;
    }

    /**
     * ¿Es una lista de objetos identificados por `clave` (planes, ítems, tarifas)?
     *
     * @param array<mixed> $valor Valor a inspeccionar.
     *
     * @return bool
     */
    private static function es_lista_de_claves(array $valor): bool
    {
        return isset($valor[0]) && is_array($valor[0]) && isset($valor[0]['clave']);
    }

    /**
     * Pasa los números a su tipo natural (entero si no tiene decimales, flotante si sí) para que el JSON
     * salga como en el contrato de §2.6: 55900 y no 55900.0 ni "55900". Se aplica a un contrato ya validado.
     *
     * @param array<string, mixed> $contrato Contrato validado.
     *
     * @return array<string, mixed>
     */
    private static function normalizar(array $contrato): array
    {
        /* 🔴 Primero lo calculado: mensualidades de Agentes y tres juntos salen SIEMPRE de la API, ignorando
           lo que haya llegado en el request o estuviera guardado. */
        $contrato = self::recalcular($contrato);

        $numero = function ($valor) {
            if ($valor === null) {
                return null;
            }

            $flotante = round((float) $valor, 4);

            return floor($flotante) === $flotante ? (int) $flotante : $flotante;
        };

        foreach ($contrato['gestion']['planes'] as $i => $plan) {
            foreach (['desde', 'hasta', 'usuarios', 'abono', 'excedente'] as $campo) {
                $contrato['gestion']['planes'][$i][$campo] = $numero($plan[$campo]);
            }
        }

        $contrato['gestion']['usuario_extra'] = $numero($contrato['gestion']['usuario_extra']);
        $contrato['gestion']['descuento_bienvenida']['porcentaje'] = $numero($contrato['gestion']['descuento_bienvenida']['porcentaje']);
        $contrato['gestion']['descuento_bienvenida']['meses']      = $numero($contrato['gestion']['descuento_bienvenida']['meses']);

        $contrato['tienda']['mensualidad'] = $numero($contrato['tienda']['mensualidad']);

        $contrato['agentes']['cotizacion_usd']['valor']  = $numero($contrato['agentes']['cotizacion_usd']['valor']);
        $contrato['agentes']['ganancia_por_paquete']     = $numero($contrato['agentes']['ganancia_por_paquete']);
        $contrato['agentes']['aviso_consumo_porcentaje'] = $numero($contrato['agentes']['aviso_consumo_porcentaje']);

        foreach ($contrato['agentes']['paquetes'] as $i => $paquete) {
            $contrato['agentes']['paquetes'][$i]['tokens_usd']  = $numero($paquete['tokens_usd']);
            $contrato['agentes']['paquetes'][$i]['mensualidad'] = $numero($paquete['mensualidad']);
        }

        $contrato['implementaciones']['anticipo_porcentaje'] = $numero($contrato['implementaciones']['anticipo_porcentaje']);
        $contrato['implementaciones']['reembolsable']        = (bool) $contrato['implementaciones']['reembolsable'];

        foreach ($contrato['implementaciones']['items'] as $i => $item) {
            $contrato['implementaciones']['items'][$i]['precio'] = $numero($item['precio']);
        }

        foreach (['descuento_porcentaje', 'precio_lista', 'precio'] as $campo) {
            $contrato['implementaciones']['tres_juntos'][$campo] = $numero($contrato['implementaciones']['tres_juntos'][$campo]);
        }

        $contrato['costos_meta']['mensajes_libres_gratis_por_mes'] = $numero($contrato['costos_meta']['mensajes_libres_gratis_por_mes']);

        foreach ($contrato['costos_meta']['tarifas'] as $i => $tarifa) {
            $contrato['costos_meta']['tarifas'][$i]['usd'] = $numero($tarifa['usd']);
            $contrato['costos_meta']['tarifas'][$i]['ars'] = $numero($tarifa['ars']);
        }

        return $contrato;
    }

    /**
     * Calcula lo que la API decide (y las puntas sólo muestran): la mensualidad de cada paquete de Agentes y el
     * precio de los tres juntos. A pesos enteros.
     *
     *  - `paquete.mensualidad = round(ganancia_por_paquete + tokens_usd * cotizacion_usd.valor)`
     *  - `tres_juntos.precio_lista = suma de los precios de los tres items` (a 2 decimales, sin redondear a enteros)
     *  - `tres_juntos.precio = round(precio_lista * (100 - descuento_porcentaje) / 100)`
     *
     * Se aplica a un contrato ya validado (los campos de origen son números). Es la única fórmula: la usan la
     * lectura, el PUT y los defaults, así que un valor calculado que llegue en un PUT se pisa siempre.
     *
     * @param array<string, mixed> $contrato Contrato validado.
     *
     * @return array<string, mixed> El mismo contrato con los campos calculados al día.
     */
    private static function recalcular(array $contrato): array
    {
        $cotizacion = (float) $contrato['agentes']['cotizacion_usd']['valor'];
        $ganancia   = (float) $contrato['agentes']['ganancia_por_paquete'];

        foreach ($contrato['agentes']['paquetes'] as $i => $paquete) {
            $contrato['agentes']['paquetes'][$i]['mensualidad'] = (int) round($ganancia + (float) $paquete['tokens_usd'] * $cotizacion);
        }

        $lista = 0.0;
        foreach ($contrato['implementaciones']['items'] as $item) {
            $lista += (float) $item['precio'];
        }

        /* La lista es la suma exacta de los tres items (a 2 decimales, como la vista previa del SPA): redondearla a
           pesos enteros desviaba el descuento. Sólo el precio con descuento va a pesos enteros. */
        $lista     = round($lista, 2);
        $descuento = (float) $contrato['implementaciones']['tres_juntos']['descuento_porcentaje'];

        $contrato['implementaciones']['tres_juntos']['precio_lista'] = $lista;
        $contrato['implementaciones']['tres_juntos']['precio']       = (int) round($lista * (100 - $descuento) / 100);

        return $contrato;
    }

    /**
     * Nombre con el que se sella `actualizado_por`: el del admin, su email si no tiene nombre, o «Admin #id».
     *
     * @param int|null $admin_id Admin que edita.
     *
     * @return string Nunca vacío.
     */
    private static function nombre_del_admin(?int $admin_id): string
    {
        if ($admin_id !== null) {
            $admin = Admin::query()->find($admin_id);

            if ($admin !== null) {
                $nombre = trim((string) $admin->name);

                if ($nombre !== '') {
                    return $nombre;
                }

                if (trim((string) $admin->email) !== '') {
                    return trim((string) $admin->email);
                }
            }

            return 'Admin #' . $admin_id;
        }

        return 'Admin';
    }

    /* ==================================================================================
     * Validación
     * ================================================================================== */

    /**
     * Valida el contrato fusionado. Motivo de que la validación viva en el servidor y no sólo en el SPA: la
     * fuente sale sin intermediarios por WhatsApp a los leads y en la landing, y un dedo pegado no puede llegar
     * a un lead. Los errores quedan por ruta de campo, con un texto legible.
     *
     * @param array<string, mixed>  $c       Contrato fusionado.
     * @param array<string, string> $errores Errores acumulados por ruta (por referencia).
     *
     * @return void
     */
    private static function validar(array $c, array &$errores): void
    {
        /* Planes de gestión: usuarios, abono, excedente y rangos encadenados. */
        $planes = [];
        foreach ($c['gestion']['planes'] as $plan) {
            $planes[$plan['clave']] = $plan;
            $ruta   = 'gestion.planes.' . $plan['clave'];
            /* El nombre puede venir con cualquier tipo (JSON editado a mano): sólo se concatena si es texto. */
            $nombre = 'Plan ' . self::texto_para_mensaje($plan['nombre'], $plan['clave']);

            self::validar_texto($errores, $ruta . '.nombre', $plan['nombre'], 'El nombre del plan ' . $plan['clave']);
            self::validar_entero($errores, $ruta . '.usuarios', $plan['usuarios'], 1, 1000, $nombre . ': los usuarios incluidos');
            self::validar_monto($errores, $ruta . '.abono', $plan['abono'], $nombre . ': el abono mensual');
            self::validar_monto($errores, $ruta . '.excedente', $plan['excedente'], $nombre . ': el precio de la operación excedente', true);
            self::validar_entero($errores, $ruta . '.desde', $plan['desde'], 0, 100000000, $nombre . ': el inicio del rango de operaciones');
            self::validar_entero($errores, $ruta . '.hasta', $plan['hasta'], 0, 100000000, $nombre . ': el fin del rango de operaciones', true);
        }

        if (isset($planes['inicial'], $planes['intermedio'], $planes['empresa'])) {
            if ($planes['inicial']['desde'] !== null && self::es_entero($planes['inicial']['desde']) && (int) $planes['inicial']['desde'] !== 0) {
                $errores['gestion.planes.inicial.desde'] = 'El plan Inicial tiene que empezar en 0 operaciones.';
            }

            if (self::es_entero($planes['inicial']['hasta']) && self::es_entero($planes['intermedio']['desde'])
                && (int) $planes['intermedio']['desde'] !== (int) $planes['inicial']['hasta'] + 1) {
                $errores['gestion.planes.intermedio.desde'] = 'Los rangos de operaciones tienen que ser continuos: el plan Intermedio tiene que empezar en '
                    . ((int) $planes['inicial']['hasta'] + 1) . ' (el fin de Inicial más 1), no en ' . (int) $planes['intermedio']['desde'] . '.';
            }

            if (self::es_entero($planes['intermedio']['hasta']) && self::es_entero($planes['empresa']['desde'])
                && (int) $planes['empresa']['desde'] !== (int) $planes['intermedio']['hasta'] + 1) {
                $errores['gestion.planes.empresa.desde'] = 'Los rangos de operaciones tienen que ser continuos: el plan Empresa tiene que empezar en '
                    . ((int) $planes['intermedio']['hasta'] + 1) . ' (el fin de Intermedio más 1), no en ' . (int) $planes['empresa']['desde'] . '.';
            }

            if ($planes['empresa']['hasta'] !== null) {
                $errores['gestion.planes.empresa.hasta'] = 'El plan Empresa es ilimitado: su fin de rango tiene que quedar vacío.';
            }

            foreach (['inicial', 'intermedio'] as $clave) {
                /* Nombre del plan para los mensajes, ya a salvo de tipos raros. */
                $nombre_plan = self::texto_para_mensaje($planes[$clave]['nombre'], $clave);

                if ($planes[$clave]['hasta'] === null) {
                    $errores['gestion.planes.' . $clave . '.hasta'] = 'El plan ' . $nombre_plan . ' necesita un fin de rango de operaciones.';
                } elseif (self::es_entero($planes[$clave]['desde']) && self::es_entero($planes[$clave]['hasta'])
                    && (int) $planes[$clave]['hasta'] < (int) $planes[$clave]['desde']) {
                    $errores['gestion.planes.' . $clave . '.hasta'] = 'El plan ' . $nombre_plan . ': el fin del rango no puede ser menor al inicio.';
                }

                if ($planes[$clave]['excedente'] === null) {
                    $errores['gestion.planes.' . $clave . '.excedente'] = 'El plan ' . $nombre_plan . ' necesita el precio de la operación excedente.';
                }
            }
        }

        self::validar_monto($errores, 'gestion.usuario_extra', $c['gestion']['usuario_extra'], 'El usuario extra');

        $descuento = $c['gestion']['descuento_bienvenida'];
        self::validar_numero($errores, 'gestion.descuento_bienvenida.porcentaje', $descuento['porcentaje'], 0, 100, 2, 'El porcentaje del descuento de bienvenida');
        self::validar_entero($errores, 'gestion.descuento_bienvenida.meses', $descuento['meses'], 0, self::MAX_MESES_DESCUENTO, 'Los meses del descuento de bienvenida');

        self::validar_monto($errores, 'tienda.mensualidad', $c['tienda']['mensualidad'], 'La mensualidad de la tienda online');
        $agentes = $c['agentes'];

        self::validar_texto($errores, 'agentes.incluye', $agentes['incluye'], 'Lo que incluye el paquete de Agentes', 300);
        self::validar_texto($errores, 'agentes.uso_de_tokens', $agentes['uso_de_tokens'], 'Para qué sirven los tokens', 300);

        $cotizacion = $agentes['cotizacion_usd'];
        self::validar_positivo($errores, 'agentes.cotizacion_usd.valor', $cotizacion['valor'], 2, 'La cotización del dólar');
        self::validar_texto($errores, 'agentes.cotizacion_usd.referencia', $cotizacion['referencia'], 'La referencia de la cotización', 60);

        $fecha_cotizacion = is_string($cotizacion['fecha']) ? \DateTime::createFromFormat('Y-m-d', $cotizacion['fecha']) : false;
        if (! $fecha_cotizacion || $fecha_cotizacion->format('Y-m-d') !== $cotizacion['fecha']) {
            $errores['agentes.cotizacion_usd.fecha'] = 'La fecha de la cotización tiene que tener el formato AAAA-MM-DD.';
        }

        self::validar_monto($errores, 'agentes.ganancia_por_paquete', $agentes['ganancia_por_paquete'], 'La ganancia por paquete');
        self::validar_entero($errores, 'agentes.aviso_consumo_porcentaje', $agentes['aviso_consumo_porcentaje'], 1, 100, 'El porcentaje de consumo que dispara el aviso');

        foreach ($agentes['paquetes'] as $paquete) {
            $ruta_paquete = 'agentes.paquetes.' . $paquete['clave'];

            self::validar_texto($errores, $ruta_paquete . '.nombre', $paquete['nombre'], 'El nombre del paquete ' . $paquete['clave'], 40);
            self::validar_positivo($errores, $ruta_paquete . '.tokens_usd', $paquete['tokens_usd'], 4, 'Los USD de tokens del paquete ' . $paquete['clave']);
        }

        $impl = $c['implementaciones'];
        self::validar_numero($errores, 'implementaciones.anticipo_porcentaje', $impl['anticipo_porcentaje'], 0, 100, 2, 'El porcentaje de anticipo de la implementación');

        if (! is_bool($impl['reembolsable'])) {
            $errores['implementaciones.reembolsable'] = 'El dato «reembolsable» de la implementación tiene que ser verdadero o falso.';
        }

        self::validar_numero($errores, 'implementaciones.tres_juntos.descuento_porcentaje', $impl['tres_juntos']['descuento_porcentaje'], 0, 100, 2, 'El descuento de los tres juntos');

        foreach ($impl['items'] as $item) {
            self::validar_texto($errores, 'implementaciones.items.' . $item['clave'] . '.nombre', $item['nombre'], 'El nombre de la implementación ' . $item['clave']);
            self::validar_monto($errores, 'implementaciones.items.' . $item['clave'] . '.precio', $item['precio'], 'El precio de la implementación «' . self::texto_para_mensaje($item['nombre'], $item['clave']) . '»');
        }

        $meta = $c['costos_meta'];
        $fecha = is_string($meta['vigente_desde']) ? \DateTime::createFromFormat('Y-m-d', $meta['vigente_desde']) : false;
        if (! $fecha || $fecha->format('Y-m-d') !== $meta['vigente_desde']) {
            $errores['costos_meta.vigente_desde'] = 'La fecha de vigencia de los costos de Meta tiene que tener el formato AAAA-MM-DD.';
        }

        self::validar_entero($errores, 'costos_meta.mensajes_libres_gratis_por_mes', $meta['mensajes_libres_gratis_por_mes'], 0, 100000000, 'Los mensajes libres gratis por mes');

        foreach ($meta['tarifas'] as $tarifa) {
            $ruta = 'costos_meta.tarifas.' . $tarifa['clave'];
            $nombre_tarifa = self::texto_para_mensaje($tarifa['nombre'], $tarifa['clave']);

            self::validar_texto($errores, $ruta . '.nombre', $tarifa['nombre'], 'El nombre de la tarifa ' . $tarifa['clave']);
            self::validar_numero($errores, $ruta . '.usd', $tarifa['usd'], 0, self::MAX_MONTO, 4, 'La tarifa «' . $nombre_tarifa . '» en USD');
            self::validar_monto($errores, $ruta . '.ars', $tarifa['ars'], 'La tarifa «' . $nombre_tarifa . '» en pesos');
        }
    }

    /**
     * Texto seguro para meter en un mensaje de error: el valor si es un texto, o la alternativa si no.
     * Motivo: un nombre que llega como arreglo u objeto (JSON editado a mano en la base, o un PUT mal
     * armado) rompe la concatenación con «Array to string conversion», que Laravel eleva a excepción.
     *
     * @param mixed  $valor        Valor que se quiere mostrar.
     * @param string $alternativa  Texto a usar si el valor no es un texto (la clave fija del ítem).
     *
     * @return string
     */
    private static function texto_para_mensaje($valor, string $alternativa): string
    {
        return is_string($valor) && trim($valor) !== '' ? $valor : $alternativa;
    }

    /**
     * ¿El valor es un entero (o un número sin decimales, o un texto numérico entero)?
     *
     * @param mixed $valor Valor a mirar.
     *
     * @return bool
     */
    private static function es_entero($valor): bool
    {
        return is_numeric($valor) && (float) $valor === floor((float) $valor);
    }

    /**
     * Valida un número con rango y decimales máximos.
     *
     * @param array<string, string> $errores    Errores acumulados (por referencia).
     * @param string                $ruta       Ruta del campo.
     * @param mixed                 $valor      Valor a validar.
     * @param float|int             $minimo     Mínimo permitido.
     * @param float|int             $maximo     Máximo permitido.
     * @param int                   $decimales  Decimales máximos.
     * @param string                $etiqueta   Cómo se nombra el campo en el mensaje.
     * @param bool                  $permite_null Si null es válido.
     *
     * @return void
     */
    private static function validar_numero(array &$errores, string $ruta, $valor, $minimo, $maximo, int $decimales, string $etiqueta, bool $permite_null = false): void
    {
        if ($valor === null && $permite_null) {
            return;
        }

        if (is_bool($valor) || ! is_numeric($valor) || ! is_finite((float) $valor)) {
            $errores[$ruta] = $etiqueta . ' tiene que ser un número.';

            return;
        }

        $numero = (float) $valor;

        if ($numero < $minimo || $numero > $maximo) {
            $errores[$ruta] = $etiqueta . ' tiene que estar entre ' . number_format($minimo, 0, ',', '.') . ' y ' . number_format($maximo, 0, ',', '.') . '.';

            return;
        }

        $factor = pow(10, $decimales);
        if (abs($numero * $factor - round($numero * $factor)) > 0.0001) {
            $errores[$ruta] = $etiqueta . ' admite hasta ' . $decimales . ' decimales.';
        }
    }

    /**
     * Valida un monto en pesos: de 0 a MAX_MONTO con hasta 2 decimales.
     *
     * @param array<string, string> $errores      Errores acumulados (por referencia).
     * @param string                $ruta         Ruta del campo.
     * @param mixed                 $valor        Valor a validar.
     * @param string                $etiqueta     Cómo se nombra el campo en el mensaje.
     * @param bool                  $permite_null Si null es válido.
     *
     * @return void
     */
    private static function validar_monto(array &$errores, string $ruta, $valor, string $etiqueta, bool $permite_null = false): void
    {
        self::validar_numero($errores, $ruta, $valor, 0, self::MAX_MONTO, 2, $etiqueta, $permite_null);
    }

    /**
     * Valida un entero dentro de un rango.
     *
     * @param array<string, string> $errores      Errores acumulados (por referencia).
     * @param string                $ruta         Ruta del campo.
     * @param mixed                 $valor        Valor a validar.
     * @param int                   $minimo       Mínimo permitido.
     * @param int                   $maximo       Máximo permitido.
     * @param string                $etiqueta     Cómo se nombra el campo en el mensaje.
     * @param bool                  $permite_null Si null es válido.
     *
     * @return void
     */
    private static function validar_entero(array &$errores, string $ruta, $valor, int $minimo, int $maximo, string $etiqueta, bool $permite_null = false): void
    {
        if ($valor === null && $permite_null) {
            return;
        }

        if (! self::es_entero($valor) || is_bool($valor)) {
            $errores[$ruta] = $etiqueta . ' tiene que ser un número entero.';

            return;
        }

        if ((float) $valor < $minimo || (float) $valor > $maximo) {
            $errores[$ruta] = $etiqueta . ' tiene que estar entre ' . number_format($minimo, 0, ',', '.') . ' y ' . number_format($maximo, 0, ',', '.') . '.';
        }
    }

    /**
     * Valida un texto corto no vacío (nombres editables).
     *
     * @param array<string, string> $errores  Errores acumulados (por referencia).
     * @param string                $ruta     Ruta del campo.
     * @param mixed                 $valor    Valor a validar.
     * @param string                $etiqueta Cómo se nombra el campo en el mensaje.
     * @param int                   $maximo   Largo máximo en caracteres (80 por defecto).
     *
     * @return void
     */
    private static function validar_texto(array &$errores, string $ruta, $valor, string $etiqueta, int $maximo = 80): void
    {
        if (! is_string($valor) || trim($valor) === '' || mb_strlen($valor) > $maximo) {
            $errores[$ruta] = $etiqueta . ' tiene que ser un texto de 1 a ' . $maximo . ' caracteres.';
        }
    }

    /**
     * Valida un número ESTRICTAMENTE mayor a cero (cotización, USD de tokens), hasta MAX_MONTO y con
     * decimales máximos.
     *
     * @param array<string, string> $errores   Errores acumulados (por referencia).
     * @param string                $ruta      Ruta del campo.
     * @param mixed                 $valor     Valor a validar.
     * @param int                   $decimales Decimales máximos.
     * @param string                $etiqueta  Cómo se nombra el campo en el mensaje.
     *
     * @return void
     */
    private static function validar_positivo(array &$errores, string $ruta, $valor, int $decimales, string $etiqueta): void
    {
        if (! is_bool($valor) && is_numeric($valor) && is_finite((float) $valor) && (float) $valor <= 0) {
            $errores[$ruta] = $etiqueta . ' tiene que ser mayor a cero.';

            return;
        }

        self::validar_numero($errores, $ruta, $valor, 0, self::MAX_MONTO, $decimales, $etiqueta);
    }
}

<?php

namespace App\Services;

use App\Models\AdminSetting;

/**
 * Parámetros configurables del cotizador del sistema (solapa Contrato de un lead).
 *
 * Persistencia en `admin_settings`, mismo patrón clave-valor que LeadDemoSettings: los precios
 * por defecto de cada sistema, el descuento por transferencia y los días que vive el link de pago.
 *
 * 🔴 Estos son DEFAULTS, no el precio que se cobra. Al cotizar, quien vende manda los tres precios
 * en el request y puede pisar cualquiera de ellos: el pedido de Lucas fue inputs y no etiquetas,
 * porque hace falta poder hacer un precio especial para un lead puntual sin cambiar el default de
 * todos. Lo que el servidor sí hace, sin excepción, es recalcular el total desde los valores
 * recibidos y validarles el rango — ver CotizadorLeadService y LeadCotizacionController.
 */
class CotizadorSettings
{
    /** Clave: precio por defecto de ComercioCity Gestión, en dólares. */
    public const KEY_PRECIO_GESTION = 'cotizador_precio_gestion';

    /** Clave: precio por defecto de ComercioCity E-Commerce, en dólares. */
    public const KEY_PRECIO_ECOMMERCE = 'cotizador_precio_ecommerce';

    /** Clave: precio por defecto de ComercioCity Agentes, en dólares. */
    public const KEY_PRECIO_AGENTES = 'cotizador_precio_agentes';

    /** Clave: descuento, en porcentaje, de pagar por transferencia directa en vez de por Mercado Pago. */
    public const KEY_DESCUENTO_TRANSFERENCIA = 'cotizador_descuento_transferencia';

    /** Clave: días que el link de pago sigue siendo válido desde que se genera. */
    public const KEY_LINK_VENCE_DIAS = 'cotizador_link_vence_dias';

    /**
     * Catálogo de sistemas cotizables: key interna → etiqueta que ve el lead.
     *
     * 🔴 Única fuente de los nombres. La etiqueta viaja al front (para pintar las tres filas), se
     * guarda en la foto de la cotización del lead y va como título de cada item de la preferencia
     * de Mercado Pago — o sea que es lo que el lead lee en el checkout. Si se escribiera en cada
     * uno de esos tres lugares, el día que cambie un nombre comercial quedarían dos versiones
     * distintas conviviendo y una de ellas sería la que ve el que paga.
     *
     * Las keys son el contrato con el front y con lo ya guardado en `contract_cotizacion_items`:
     * agregar un sistema es sumar una entrada acá; renombrar una key rompe las cotizaciones viejas.
     *
     * @var array<string, string>
     */
    public const SISTEMAS = [
        'gestion'   => 'ComercioCity Gestión',
        'ecommerce' => 'ComercioCity E-Commerce',
        'agentes'   => 'ComercioCity Agentes',
    ];

    /** Valor por defecto: precio de ComercioCity Gestión en dólares (pago único, licencia + implementación). */
    private const DEFAULT_PRECIO_GESTION = 1500.0;

    /** Valor por defecto: precio de ComercioCity E-Commerce en dólares. */
    private const DEFAULT_PRECIO_ECOMMERCE = 600.0;

    /** Valor por defecto: precio de ComercioCity Agentes en dólares. */
    private const DEFAULT_PRECIO_AGENTES = 600.0;

    /** Valor por defecto: descuento por transferencia directa, en porcentaje. */
    private const DEFAULT_DESCUENTO_TRANSFERENCIA = 10.0;

    /** Valor por defecto: días de vigencia del link de pago. */
    private const DEFAULT_LINK_VENCE_DIAS = 7;

    /** Precio mínimo aceptado, en dólares. Cero no es un precio: sería regalar un sistema sin decirlo. */
    public const MIN_PRECIO = 0.01;

    /**
     * Precio máximo aceptado, en dólares.
     *
     * Techo contra el dedo pegado: con el dólar arriba de 1.000, un cero de más en el precio arma
     * un link de cobro por una cifra absurda y el lead lo recibe por WhatsApp antes de que nadie
     * lo mire. 100.000 deja lugar de sobra para cualquier precio real de esta plataforma.
     */
    public const MAX_PRECIO = 100000.0;

    /** Cotización mínima del dólar aceptada. Igual que el precio: cero no es una cotización. */
    public const MIN_DOLAR = 0.01;

    /** Cotización máxima del dólar aceptada. Mismo criterio de techo que MAX_PRECIO. */
    public const MAX_DOLAR = 1000000.0;

    /** Descuento mínimo por transferencia, en porcentaje. 0 = sin descuento, es válido. */
    public const MIN_DESCUENTO = 0;

    /** Descuento máximo por transferencia, en porcentaje. 100 sería regalarlo; el tope real lo pone quien vende. */
    public const MAX_DESCUENTO = 100;

    /** Mínimo de días de vigencia del link. 1 y no 0: un link que nace vencido no sirve para nada. */
    public const MIN_LINK_VENCE_DIAS = 1;

    /** Máximo de días de vigencia del link. 365 es el techo duro; la razón de que venza está en el docblock de la clase. */
    public const MAX_LINK_VENCE_DIAS = 365;

    /**
     * Devuelve la configuración completa para el panel y para el modal del cotizador.
     *
     * Incluye `sistemas` ya resuelto (key + etiqueta + precio por defecto) para que el front no
     * tenga que cruzar el catálogo con los precios, y `mercado_pago_configurado`, que es lo que
     * usa para deshabilitar el botón con un motivo en vez de dejar que el usuario descubra el
     * error recién al apretarlo.
     *
     * 🔴 El access token NO sale de acá ni de ningún lado: lo único que viaja es el booleano.
     *
     * @return array<string, mixed>
     */
    public static function to_array(): array
    {
        $precios = [
            'gestion'   => self::get_precio_gestion(),
            'ecommerce' => self::get_precio_ecommerce(),
            'agentes'   => self::get_precio_agentes(),
        ];

        $sistemas = [];
        foreach (self::SISTEMAS as $key => $label) {
            $sistemas[] = [
                'key'        => $key,
                'label'      => $label,
                'precio_usd' => $precios[$key],
            ];
        }

        return [
            'precio_gestion'          => $precios['gestion'],
            'precio_ecommerce'        => $precios['ecommerce'],
            'precio_agentes'          => $precios['agentes'],
            'descuento_transferencia' => self::get_descuento_transferencia(),
            'link_vence_dias'         => self::get_link_vence_dias(),
            'cuotas'                  => self::get_cuotas(),
            'mercado_pago_configurado' => self::mercado_pago_configurado(),
            'sistemas'                => $sistemas,
        ];
    }

    /**
     * Persiste la configuración validada desde admin-spa.
     *
     * Todas las claves son opcionales (`isset`), igual que los campos nuevos de LeadDemoSettings:
     * el despliegue no es atómico y un front viejo que no mande una clave no tiene que borrar el
     * valor guardado.
     *
     * @param array<string, mixed> $data Campos del formulario ya validados por el controller.
     *
     * @return void
     */
    public static function persist_from_request(array $data): void
    {
        if (isset($data['precio_gestion'])) {
            AdminSetting::set(self::KEY_PRECIO_GESTION, (string) self::clamp_precio((float) $data['precio_gestion']));
        }

        if (isset($data['precio_ecommerce'])) {
            AdminSetting::set(self::KEY_PRECIO_ECOMMERCE, (string) self::clamp_precio((float) $data['precio_ecommerce']));
        }

        if (isset($data['precio_agentes'])) {
            AdminSetting::set(self::KEY_PRECIO_AGENTES, (string) self::clamp_precio((float) $data['precio_agentes']));
        }

        if (isset($data['descuento_transferencia'])) {
            AdminSetting::set(self::KEY_DESCUENTO_TRANSFERENCIA, (string) self::clamp_descuento((float) $data['descuento_transferencia']));
        }

        if (isset($data['link_vence_dias'])) {
            AdminSetting::set(self::KEY_LINK_VENCE_DIAS, (string) self::clamp_link_vence_dias((int) $data['link_vence_dias']));
        }
    }

    /**
     * Precio por defecto de ComercioCity Gestión, en dólares.
     *
     * @return float
     */
    public static function get_precio_gestion(): float
    {
        return self::clamp_precio((float) AdminSetting::get(self::KEY_PRECIO_GESTION, (string) self::DEFAULT_PRECIO_GESTION));
    }

    /**
     * Precio por defecto de ComercioCity E-Commerce, en dólares.
     *
     * @return float
     */
    public static function get_precio_ecommerce(): float
    {
        return self::clamp_precio((float) AdminSetting::get(self::KEY_PRECIO_ECOMMERCE, (string) self::DEFAULT_PRECIO_ECOMMERCE));
    }

    /**
     * Precio por defecto de ComercioCity Agentes, en dólares.
     *
     * @return float
     */
    public static function get_precio_agentes(): float
    {
        return self::clamp_precio((float) AdminSetting::get(self::KEY_PRECIO_AGENTES, (string) self::DEFAULT_PRECIO_AGENTES));
    }

    /**
     * Descuento por pagar con transferencia directa, en porcentaje.
     *
     * @return float
     */
    public static function get_descuento_transferencia(): float
    {
        return self::clamp_descuento((float) AdminSetting::get(self::KEY_DESCUENTO_TRANSFERENCIA, (string) self::DEFAULT_DESCUENTO_TRANSFERENCIA));
    }

    /**
     * Días que el link de pago sigue siendo válido desde que se genera.
     *
     * El default sale de config y no de una constante propia para que el `.env` de producción
     * pueda moverlo sin que nadie haya tocado nunca la pantalla de configuración.
     *
     * @return int
     */
    public static function get_link_vence_dias(): int
    {
        $default = (int) config('services.mercadopago.link_vence_dias', self::DEFAULT_LINK_VENCE_DIAS);
        if ($default <= 0) {
            $default = self::DEFAULT_LINK_VENCE_DIAS;
        }

        return self::clamp_link_vence_dias((int) AdminSetting::get(self::KEY_LINK_VENCE_DIAS, (string) $default));
    }

    /**
     * Cantidad de cuotas sin interés de la preferencia de Mercado Pago.
     *
     * No es configurable desde el panel a propósito: que salgan SIN interés depende de lo que la
     * cuenta de Mercado Pago tenga habilitado, no de este número. Moverlo desde una pantalla, sin
     * tocar la cuenta, sería ofrecer cuotas que después se cobran con interés.
     *
     * @return int
     */
    public static function get_cuotas(): int
    {
        $cuotas = (int) config('services.mercadopago.cuotas', 3);

        return $cuotas > 0 ? $cuotas : 3;
    }

    /**
     * ¿Hay access token de Mercado Pago cargado?
     *
     * 🔴 Devuelve un booleano y nada más. Es lo único del token que puede salir del backend: el
     * front lo usa para deshabilitar el botón con un motivo, sin ver ni un carácter de la
     * credencial.
     *
     * @return bool
     */
    public static function mercado_pago_configurado(): bool
    {
        return trim((string) config('services.mercadopago.admin_access_token', '')) !== '';
    }

    /**
     * Acota un precio en dólares al rango permitido [MIN_PRECIO, MAX_PRECIO].
     *
     * @param float $value
     *
     * @return float
     */
    private static function clamp_precio(float $value): float
    {
        if ($value < self::MIN_PRECIO) {
            return self::MIN_PRECIO;
        }
        if ($value > self::MAX_PRECIO) {
            return self::MAX_PRECIO;
        }

        return round($value, 2);
    }

    /**
     * Acota un descuento al rango permitido [MIN_DESCUENTO, MAX_DESCUENTO].
     *
     * Clamp propio y no el de precios: es un porcentaje, así que un 500 tiene que dar 100 y no
     * pasar por válido sólo porque está abajo del techo de los precios.
     *
     * @param float $value Valor en porcentaje.
     *
     * @return float
     */
    private static function clamp_descuento(float $value): float
    {
        if ($value < self::MIN_DESCUENTO) {
            return (float) self::MIN_DESCUENTO;
        }
        if ($value > self::MAX_DESCUENTO) {
            return (float) self::MAX_DESCUENTO;
        }

        return round($value, 2);
    }

    /**
     * Acota los días de vigencia del link al rango permitido.
     *
     * @param int $value
     *
     * @return int
     */
    private static function clamp_link_vence_dias(int $value): int
    {
        if ($value < self::MIN_LINK_VENCE_DIAS) {
            return self::MIN_LINK_VENCE_DIAS;
        }
        if ($value > self::MAX_LINK_VENCE_DIAS) {
            return self::MAX_LINK_VENCE_DIAS;
        }

        return $value;
    }
}

<?php

namespace App\Services;

/**
 * El cálculo de la cotización del sistema: puro, sin red y sin base de datos.
 *
 * Dados los sistemas elegidos con su precio en dólares y la cotización del dólar, devuelve el
 * detalle por sistema, los totales en las dos monedas, el valor de la cuota y cuánto sale pagando
 * por transferencia directa (con descuento) en dólares y en pesos.
 *
 * 🔴 POR QUÉ EL REDONDEO ESTÁ FIJADO ACÁ, PASO POR PASO
 * ------------------------------------------------------
 * El front hace esta misma cuenta mientras el usuario tipea, para mostrar el total en vivo; el
 * servidor la rehace al generar el link, y el número que se cobra es SIEMPRE el del servidor. Si
 * las dos cuentas no dan exactamente igual, quien vende ve un número y el lead paga otro — y se
 * entera recién en el checkout de Mercado Pago, que es el peor momento posible.
 *
 * Las seis fórmulas, y son estas y no otras:
 *
 *   precio_ars(item)  = round(precio_usd * dolar, 2)
 *   total_usd         = round(suma de precio_usd, 2)
 *   total_ars         = round(suma de precio_ars, 2)   ← suma de los YA redondeados
 *   cuota_ars         = round(total_ars / cuotas, 2)
 *   transferencia_usd = round(total_usd * (1 - descuento/100), 2)
 *   transferencia_ars = round(total_ars * (1 - descuento/100), 2)
 *
 * `total_ars` se suma a partir de los `precio_ars` ya redondeados y NO se calcula como
 * `total_usd * dolar`: así el detalle por sistema que ve el usuario cierra exactamente con el
 * total, que es justo lo que va a mirar si algo no le cuadra. Las dos formas difieren en centavos,
 * y un centavo que no cierra en una pantalla de precios es una llamada.
 *
 * 🔴 Y el total NUNCA se toma del request. El navegador manda los precios y el dólar (eso es la
 * funcionalidad: quien vende decide el precio de cada lead), pero el total lo rehace esta clase.
 * Un `total` que venga en el body se ignora sin avisar.
 */
class CotizadorLeadService
{
    /**
     * Arma la cotización completa a partir de los sistemas elegidos.
     *
     * Los precios y el dólar tienen que venir ya validados de rango por quien llama
     * (LeadCotizacionController lo hace con `validate()`); acá se asume que son números sanos.
     *
     * @param array<int, array<string, mixed>> $sistemas_elegidos Lista de [{key, precio_usd}] en el
     *                                                            orden en que los eligió el usuario.
     * @param float                            $dolar             Cotización del dólar, cargada a mano.
     * @param float                            $descuento         Descuento por transferencia, en porcentaje.
     * @param int                              $cuotas            Cuotas sin interés de Mercado Pago.
     *
     * @return array<string, mixed> Detalle por sistema y totales, con las claves del contrato de la API.
     */
    public static function cotizar(array $sistemas_elegidos, float $dolar, float $descuento, int $cuotas): array
    {
        $items     = [];
        $total_usd = 0.0;
        $total_ars = 0.0;

        foreach ($sistemas_elegidos as $elegido) {
            $key = isset($elegido['key']) ? (string) $elegido['key'] : '';

            // La etiqueta sale SIEMPRE del catálogo y nunca del request: es lo que el lead va a
            // leer en el checkout de Mercado Pago, así que no puede depender de lo que mande el
            // navegador. Una key desconocida se descarta acá (el controller ya la rechaza antes).
            if (! isset(CotizadorSettings::SISTEMAS[$key])) {
                continue;
            }

            $precio_usd = round((float) $elegido['precio_usd'], 2);
            $precio_ars = round($precio_usd * $dolar, 2);

            $items[] = [
                'key'        => $key,
                'label'      => CotizadorSettings::SISTEMAS[$key],
                'precio_usd' => $precio_usd,
                'precio_ars' => $precio_ars,
            ];

            $total_usd += $precio_usd;
            $total_ars += $precio_ars;
        }

        $total_usd = round($total_usd, 2);
        $total_ars = round($total_ars, 2);

        // Guarda contra la división por cero: si alguien deja `cuotas` en 0 en el .env, la cuota
        // pasa a ser el total (que es lo que efectivamente se paga en un pago) en vez de reventar.
        $cuota_ars = $cuotas > 0 ? round($total_ars / $cuotas, 2) : $total_ars;

        // Factor del descuento por transferencia, una sola vez, para que las dos monedas usen
        // exactamente el mismo número y no puedan divergir por un tipeo.
        $factor = 1 - ($descuento / 100);

        return [
            'dolar'                   => round($dolar, 2),
            'items'                   => $items,
            'total_usd'               => $total_usd,
            'total_ars'               => $total_ars,
            'cuotas'                  => $cuotas,
            'cuota_ars'               => $cuota_ars,
            'descuento_transferencia' => round($descuento, 2),
            'transferencia_usd'       => round($total_usd * $factor, 2),
            'transferencia_ars'       => round($total_ars * $factor, 2),
        ];
    }
}

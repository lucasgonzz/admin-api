<?php

namespace App\Services;

use App\Models\Client;

/**
 * Calcula y persiste la mensualidad de un Client, replicando exactamente la
 * fórmula de `UserPaymentExpiredAtController::update` en empresa-api, pero de
 * forma autónoma: admin no llama a la empresa-api del cliente para esto (esa
 * sincronización opcional, con conteos vivos, va aparte en el prompt 335).
 *
 * Los inputs (precio_plan, precio_por_cuenta, cantidad_empleados, flags de
 * módulos y datos fiscales) se cargan a mano en admin (prompt 328/334) y este
 * servicio los usa para calcular y guardar `total_mensualidad`.
 */
class ClientMensualidadService
{
    /**
     * Calcula el total de mensualidad a partir de los inputs, con la misma
     * fórmula que hoy usa empresa-api: plan base + (precio_por_cuenta *
     * cantidad_empleados) + un cargo de 1 cuenta por cada módulo activo
     * (ecommerce / mercado libre / tienda nube), usando su precio específico
     * si existe o cayendo a precio_por_cuenta como fallback.
     *
     * @param  array $inputs Puede incluir: precio_plan, precio_por_cuenta,
     *                       cantidad_empleados, tiene_ecommerce,
     *                       tiene_mercado_libre, tiene_tienda_nube,
     *                       precio_ecommerce, precio_mercado_libre,
     *                       precio_tienda_nube.
     * @return float Total de mensualidad redondeado a 2 decimales.
     */
    public function calcular_total(array $inputs)
    {
        // Monto fijo base del plan (el dueño se cobra dentro de este monto).
        $precio_plan = (float) ($inputs['precio_plan'] ?? 0);

        // Precio general por cada cuenta empleado; también es el fallback de los módulos.
        $precio_por_cuenta = (float) ($inputs['precio_por_cuenta'] ?? 0);

        // Cantidad de cuentas empleado (sin contar al dueño, que va en precio_plan).
        $cantidad_empleados = (int) ($inputs['cantidad_empleados'] ?? 0);

        // Flags de módulos activos para este cliente.
        $tiene_ecommerce = ! empty($inputs['tiene_ecommerce']);
        $tiene_mercado_libre = ! empty($inputs['tiene_mercado_libre']);
        $tiene_tienda_nube = ! empty($inputs['tiene_tienda_nube']);

        // Precios específicos por módulo (opcionales); si no vienen o son null, quedan en null
        // para que el cálculo use precio_por_cuenta como fallback (igual que en empresa-api).
        $precio_ecommerce = isset($inputs['precio_ecommerce']) && $inputs['precio_ecommerce'] !== null
            ? (float) $inputs['precio_ecommerce']
            : null;
        $precio_mercado_libre = isset($inputs['precio_mercado_libre']) && $inputs['precio_mercado_libre'] !== null
            ? (float) $inputs['precio_mercado_libre']
            : null;
        $precio_tienda_nube = isset($inputs['precio_tienda_nube']) && $inputs['precio_tienda_nube'] !== null
            ? (float) $inputs['precio_tienda_nube']
            : null;

        // Para cada servicio: si tiene precio individual lo usa, sino usa precio_por_cuenta.
        $precio_ecommerce_efectivo = $precio_ecommerce ?? $precio_por_cuenta;
        $precio_mercado_libre_efectivo = $precio_mercado_libre ?? $precio_por_cuenta;
        $precio_tienda_nube_efectivo = $precio_tienda_nube ?? $precio_por_cuenta;

        // Total mensualidad: plan base (dueño) + empleados + módulos ecommerce/ML/TN
        // (cada módulo activo suma 1 cuenta a su precio efectivo, igual que empresa-api).
        $total_mensualidad = $precio_plan
            + ($precio_por_cuenta * $cantidad_empleados)
            + ($precio_ecommerce_efectivo * ($tiene_ecommerce ? 1 : 0))
            + ($precio_mercado_libre_efectivo * ($tiene_mercado_libre ? 1 : 0))
            + ($precio_tienda_nube_efectivo * ($tiene_tienda_nube ? 1 : 0));

        return round($total_mensualidad, 2);
    }

    /**
     * Asigna los inputs de mensualidad recibidos al Client, recalcula el
     * total con `calcular_total` y guarda también la fecha de próximo pago
     * y los datos fiscales del receptor cuando vienen en el payload.
     *
     * @param  Client $client  Cliente a actualizar.
     * @param  array  $payload Inputs validados por el controller (ver update_json).
     * @return Client Cliente actualizado y persistido.
     */
    public function guardar(Client $client, array $payload)
    {
        // Inputs de mensualidad: se asignan tal cual vienen validados.
        $client->precio_plan = $payload['precio_plan'];
        $client->precio_por_cuenta = $payload['precio_por_cuenta'];
        $client->cantidad_empleados = $payload['cantidad_empleados'];
        $client->tiene_ecommerce = ! empty($payload['tiene_ecommerce']);
        $client->tiene_mercado_libre = ! empty($payload['tiene_mercado_libre']);
        $client->tiene_tienda_nube = ! empty($payload['tiene_tienda_nube']);
        // Precios específicos por módulo: si no vienen en el payload, se guardan como null
        // (el cálculo cae a precio_por_cuenta como fallback).
        $client->precio_ecommerce = array_key_exists('precio_ecommerce', $payload) ? $payload['precio_ecommerce'] : null;
        $client->precio_mercado_libre = array_key_exists('precio_mercado_libre', $payload) ? $payload['precio_mercado_libre'] : null;
        $client->precio_tienda_nube = array_key_exists('precio_tienda_nube', $payload) ? $payload['precio_tienda_nube'] : null;

        // Recalculamos el total con los mismos inputs recién asignados.
        $client->total_mensualidad = $this->calcular_total([
            'precio_plan' => $client->precio_plan,
            'precio_por_cuenta' => $client->precio_por_cuenta,
            'cantidad_empleados' => $client->cantidad_empleados,
            'tiene_ecommerce' => $client->tiene_ecommerce,
            'tiene_mercado_libre' => $client->tiene_mercado_libre,
            'tiene_tienda_nube' => $client->tiene_tienda_nube,
            'precio_ecommerce' => $client->precio_ecommerce,
            'precio_mercado_libre' => $client->precio_mercado_libre,
            'precio_tienda_nube' => $client->precio_tienda_nube,
        ]);

        // Fecha de próximo pago: referencia manual en admin (o empujada a futuro por el sync del 335).
        if (array_key_exists('payment_expired_at', $payload) && $payload['payment_expired_at'] !== null) {
            $client->payment_expired_at = $payload['payment_expired_at'];
        }

        // Datos fiscales del receptor, solo si vinieron en el payload (para no pisarlos en updates parciales).
        if (array_key_exists('afip_cuit', $payload)) {
            $client->afip_cuit = $payload['afip_cuit'];
        }
        if (array_key_exists('afip_razon_social', $payload)) {
            $client->afip_razon_social = $payload['afip_razon_social'];
        }
        if (array_key_exists('afip_condicion_iva', $payload)) {
            $client->afip_condicion_iva = $payload['afip_condicion_iva'];
        }
        if (array_key_exists('afip_domicilio', $payload)) {
            $client->afip_domicilio = $payload['afip_domicilio'];
        }

        $client->save();

        return $client;
    }

    /**
     * Devuelve el snapshot de mensualidad de un Client para el front: los
     * inputs guardados, el total ya calculado, los datos fiscales y un
     * desglose por línea (plan, empleados, ecommerce, ML, TN) para que el
     * panel pueda previsualizar sin tener que reimplementar la fórmula.
     *
     * @param  Client $client
     * @return array
     */
    public function estado(Client $client)
    {
        // Precio por cuenta general, usado como fallback en los subtotales de cada módulo.
        $precio_por_cuenta = (float) ($client->precio_por_cuenta ?? 0);
        $precio_plan = (float) ($client->precio_plan ?? 0);
        $cantidad_empleados = (int) ($client->cantidad_empleados ?? 0);

        // Precio efectivo de cada módulo: el propio si está seteado, sino precio_por_cuenta.
        $precio_ecommerce_efectivo = $client->precio_ecommerce !== null
            ? (float) $client->precio_ecommerce
            : $precio_por_cuenta;
        $precio_mercado_libre_efectivo = $client->precio_mercado_libre !== null
            ? (float) $client->precio_mercado_libre
            : $precio_por_cuenta;
        $precio_tienda_nube_efectivo = $client->precio_tienda_nube !== null
            ? (float) $client->precio_tienda_nube
            : $precio_por_cuenta;

        // Desglose por línea: solo suma el subtotal del módulo si está activo.
        $desglose = [
            'plan' => round($precio_plan, 2),
            'empleados' => round($precio_por_cuenta * $cantidad_empleados, 2),
            'ecommerce' => $client->tiene_ecommerce ? round($precio_ecommerce_efectivo, 2) : 0.0,
            'mercado_libre' => $client->tiene_mercado_libre ? round($precio_mercado_libre_efectivo, 2) : 0.0,
            'tienda_nube' => $client->tiene_tienda_nube ? round($precio_tienda_nube_efectivo, 2) : 0.0,
        ];

        return [
            'precio_plan' => $client->precio_plan,
            'precio_por_cuenta' => $client->precio_por_cuenta,
            'cantidad_empleados' => $client->cantidad_empleados,
            'tiene_ecommerce' => (bool) $client->tiene_ecommerce,
            'tiene_mercado_libre' => (bool) $client->tiene_mercado_libre,
            'tiene_tienda_nube' => (bool) $client->tiene_tienda_nube,
            'precio_ecommerce' => $client->precio_ecommerce,
            'precio_mercado_libre' => $client->precio_mercado_libre,
            'precio_tienda_nube' => $client->precio_tienda_nube,
            'total_mensualidad' => $client->total_mensualidad,
            'payment_expired_at' => $client->payment_expired_at,
            'afip_cuit' => $client->afip_cuit,
            'afip_razon_social' => $client->afip_razon_social,
            'afip_condicion_iva' => $client->afip_condicion_iva,
            'afip_domicilio' => $client->afip_domicilio,
            'desglose' => $desglose,
        ];
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Desglose acordado en cada contrato; los contratos anteriores conservan sus totales. */
class ContractPreciosService
{
    /**
     * Planes de Gestión por ventas mensuales (política del 26/10/2026). El abono de cada plan lo
     * precarga la SPA y es editable por contrato; acá viven el nombre y el límite para el PDF.
     * `limite` null = sin tope (a medida: más de 5.000 ventas por mes, importe libre).
     */
    const PLANES_GESTION = [
        'inicial'  => ['nombre' => 'Inicial',  'limite' => 300,  'abono' => 34200],
        'estandar' => ['nombre' => 'Estándar', 'limite' => 1500, 'abono' => 71500],
        'pro'      => ['nombre' => 'Pro',      'limite' => 5000, 'abono' => 105600],
        'a_medida' => ['nombre' => 'A medida', 'limite' => null, 'abono' => null],
    ];

    /** Texto del plan para el PDF, o null si la clave no es un plan conocido. */
    public static function plan_gestion($clave): ?array
    {
        if (!is_string($clave) || !isset(self::PLANES_GESTION[$clave])) {
            return null;
        }
        $plan = self::PLANES_GESTION[$clave];
        $limite = $plan['limite'] === null
            ? 'más de '.number_format(self::PLANES_GESTION['pro']['limite'], 0, ',', '.').' ventas por mes'
            : 'hasta '.number_format($plan['limite'], 0, ',', '.').' ventas por mes';
        return ['clave' => $clave, 'nombre' => $plan['nombre'], 'limite' => $limite];
    }

    public static function reglas(): array
    {
        $centavos = function ($atributo, $valor, $fallar) {
            if (is_numeric($valor) && abs((float) $valor * 100 - round((float) $valor * 100)) > 0.00001) {
                $fallar('El importe admite hasta dos decimales.');
            }
        };
        $reglas = [
            'contract_precios_sistemas' => ['nullable', 'array:gestion,ecommerce,agentes', 'size:3'],
            'contract_descuento_conjunto' => ['nullable', 'numeric', 'min:0', 'max:999999999', $centavos],
            'contract_mes_gratis' => ['nullable', 'boolean'],
            'contract_plan_gestion' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::PLANES_GESTION))],
            'contract_pago_anual' => ['nullable', 'boolean'],
            'contract_descuento_anual' => ['nullable', 'numeric', 'min:0', 'max:100', $centavos],
            'contract_meses_actualizacion' => ['nullable', 'integer', 'min:1', 'max:60'],
            'contract_sistemas' => ['nullable', 'array'],
            'contract_sistemas.*' => ['string', 'in:gestion,ecommerce,agentes'],
        ];
        foreach (['gestion', 'ecommerce', 'agentes'] as $key) {
            $reglas['contract_precios_sistemas.'.$key] = ['required_with:contract_precios_sistemas', 'array:licencia,mensualidad'];
            foreach (['licencia', 'mensualidad'] as $concepto) {
                $reglas['contract_precios_sistemas.'.$key.'.'.$concepto] = ['required_with:contract_precios_sistemas', 'numeric', 'min:0', 'max:999999999', $centavos];
            }
        }
        return $reglas;
    }

    /** Valida antes de escribir y deriva los totales históricos para cobranzas. */
    public static function preparar_request(Request $request, $contrato): void
    {
        $request->validate(self::reglas());
        $precios = $request->input('contract_precios_sistemas', $contrato->contract_precios_sistemas);
        self::preparar_precio_extra($request, $contrato, $precios);
        self::preparar_anual($request, $contrato, $precios);
        if (!is_array($precios)) {
            return;
        }
        $modalidad = $request->input('contract_modalidad', $contrato->contract_modalidad);
        if ($modalidad !== 'licencia') {
            throw ValidationException::withMessages(['contract_modalidad' => 'El desglose de licencias por sistema corresponde a la modalidad Licencia.']);
        }
        $sistemas = $request->input('contract_sistemas', $contrato->contract_sistemas);
        if (!is_array($sistemas) || count(array_diff($sistemas, ['gestion', 'ecommerce', 'agentes'])) > 0) {
            throw ValidationException::withMessages(['contract_sistemas' => 'Elegí sistemas válidos para el desglose.']);
        }
        $descuento = $request->input('contract_descuento_conjunto', $contrato->contract_descuento_conjunto ?? 300);
        $resumen = self::resumen($precios, $sistemas, (float) $descuento);
        if ($resumen['descuento'] > $resumen['subtotal']) {
            throw ValidationException::withMessages(['contract_descuento_conjunto' => 'El descuento no puede superar la suma de las licencias.']);
        }
        $request->merge([
            // Vaciar el input equivale a cero; guardar el efectivo evita que el PDF reponga 300.
            'contract_descuento_conjunto' => (float) $descuento,
            'contract_precio_licencia' => (string) $resumen['total'],
            'contract_mensualidad_base' => (string) $resumen['mensualidad'],
        ]);
    }

    private static function preparar_anual(Request $request, $contrato, $precios): void
    {
        $activo = (bool) $request->input('contract_pago_anual', $contrato->contract_pago_anual);
        if (!$activo) {
            return;
        }
        // Un cero pactado es válido; una base ausente no permite ofrecer un anual calculado.
        $base = $request->input('contract_mensualidad_base', $contrato->contract_mensualidad_base);
        if (!is_array($precios) && !self::es_monto_valido($base)) {
            throw ValidationException::withMessages(['contract_mensualidad_base' => 'Cargá el abono base para calcular el pago anual.']);
        }
        if (!is_array($precios) && (is_int($base) || is_float($base))) {
            $request->merge(['contract_mensualidad_base' => self::numero_a_texto((float) $base)]);
        }
        $porcentaje = $request->input('contract_descuento_anual', $contrato->contract_descuento_anual ?? 20);
        // Ausente conserva el valor/default; vacío explícito equivale a cero, igual que la vista.
        $request->merge(['contract_descuento_anual' => (float) $porcentaje]);
    }

    private static function preparar_precio_extra(Request $request, $contrato, $precios): void
    {
        $anual = (bool) $request->input('contract_pago_anual', $contrato->contract_pago_anual);
        if (!is_array($precios) && !$anual) {
            return;
        }
        $cantidad = $request->input('contract_usuarios_extra', $contrato->contract_usuarios_extra ?? 0);
        validator(['contract_usuarios_extra' => $cantidad], ['contract_usuarios_extra' => ['nullable', 'integer', 'min:0']])->validate();
        $precio = $request->input('contract_precio_usuario_extra', $contrato->contract_precio_usuario_extra);
        $tiene_precio = $precio !== null && $precio !== '';
        if (((int) $cantidad > 0 || $tiene_precio) && !self::es_monto_valido($precio)) {
            throw ValidationException::withMessages(['contract_precio_usuario_extra' => 'Cargá un precio válido para cada usuario extra; cero es válido si está bonificado.']);
        }
        if ($tiene_precio && (is_int($precio) || is_float($precio))) {
            $request->merge(['contract_precio_usuario_extra' => self::numero_a_texto((float) $precio)]);
        }
    }

    private static function numero_a_texto(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.');
    }

    private static function es_monto_valido($valor): bool
    {
        if (is_int($valor) || is_float($valor)) {
            return is_finite((float) $valor) && $valor >= 0 && abs((float) $valor - round((float) $valor, 2)) < 0.0000001;
        }
        if (!is_string($valor)) {
            return false;
        }
        $limpio = preg_replace('/^(?:ARS|USD|\$)\s*/i', '', trim($valor));
        return (bool) preg_match('/^(?:\d+(?:[.,]\d{1,2})?|\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?)$/', $limpio);
    }

    /** Los usuarios y otros adicionales no integran la base del adelanto de doce meses. */
    public static function resumen_anual(float $base_mensual, float $porcentaje): array
    {
        $bruto = round($base_mensual * 12, 2);
        $descuento = round($bruto * $porcentaje / 100, 2);
        return ['base_mensual' => $base_mensual, 'meses' => 12, 'porcentaje' => $porcentaje,
            'bruto' => $bruto, 'descuento' => $descuento, 'total' => round($bruto - $descuento, 2)];
    }

    public static function resumen(array $precios, array $sistemas, float $descuento): array
    {
        $filas = [];
        $subtotal = 0;
        $mensualidad = 0;
        foreach (CotizadorSettings::SISTEMAS as $key => $label) {
            if (!in_array($key, $sistemas, true)) {
                continue;
            }
            $licencia = (float) ($precios[$key]['licencia'] ?? 0);
            $abono = (float) ($precios[$key]['mensualidad'] ?? 0);
            $subtotal += $licencia;
            $mensualidad += $abono;
            $filas[] = ['key' => $key, 'label' => $label, 'licencia' => $licencia, 'mensualidad' => $abono];
        }
        $descuento_aplicado = count($filas) === 3 ? $descuento : 0;
        return ['filas' => $filas, 'subtotal' => round($subtotal, 2), 'descuento' => round($descuento_aplicado, 2),
            'total' => round($subtotal - $descuento_aplicado, 2), 'mensualidad' => round($mensualidad, 2)];
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Desglose acordado en cada contrato; los contratos anteriores conservan sus totales. */
class ContractPreciosService
{
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

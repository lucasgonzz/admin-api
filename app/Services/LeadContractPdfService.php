<?php

namespace App\Services;

use App\Models\Lead;
use Barryvdh\DomPDF\Facade as Pdf;
use Carbon\Carbon;

/**
 * Genera el PDF del contrato ComercioCity para un lead a partir de los campos `contract_*`.
 *
 * Usa dompdf (barryvdh/laravel-dompdf) y la vista {@see resources/views/emails/lead/contract.blade.php}.
 */
class LeadContractPdfService
{
    /**
     * Construye datos del contrato, renderiza la vista y devuelve el PDF como string binario.
     *
     * @param Lead $lead Lead con campos de contrato cargados.
     *
     * @return string Contenido binario del PDF.
     */
    public static function generate(Lead $lead): string
    {
        // Array de datos para la vista Blade del contrato.
        $datos = self::build_contract_data($lead);

        // Instancia PDF en A4 con márgenes definidos en la vista (@page).
        $pdf = Pdf::loadView('emails.lead.contract', $datos);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    /**
     * Arma el array de variables del contrato a partir del modelo Lead.
     *
     * @param Lead $lead
     *
     * @return array<string, mixed>
     */
    protected static function build_contract_data(Lead $lead): array
    {
        // Moneda y montos del pago único.
        $moneda = $lead->contract_currency ?? 'USD';
        $mensualidad_moneda = $lead->contract_mensualidad_moneda ?? 'ARS';

        // Usuarios y perfiles ecommerce (enteros con default 0).
        $usuarios_extra = (int) ($lead->contract_usuarios_extra ?? 0);
        $perfiles_ecommerce = (int) ($lead->contract_perfiles_ecommerce ?? 0);

        // Cuotas de financiación con fechas legibles en español.
        $financiacion_raw = $lead->contract_financiacion ?? [];
        $financiacion_filas = self::normalize_financiacion_rows($financiacion_raw);

        // Total mensual: base + extras de usuarios + perfiles ecommerce.
        $total_mensual = self::calculate_monthly_total(
            $lead->contract_mensualidad_base,
            $usuarios_extra,
            $lead->contract_precio_usuario_extra,
            $perfiles_ecommerce,
            $lead->contract_precio_perfil_ecommerce
        );

        return [
            'cc_nombre_fantasia'   => 'ComercioCity',
            'cc_razon_social'      => 'Lucas González',
            'cc_cuit'              => '20-42354898-4',

            'cliente_nombre'            => $lead->contract_client_name,
            'cliente_razon_social'      => $lead->contract_client_razon_social,
            'cliente_cuit'              => $lead->contract_client_cuit,

            'moneda'               => $moneda,
            'precio_licencia'      => $lead->contract_precio_licencia,
            'fecha_emision'        => self::format_contract_date($lead->contract_fecha_emision) ?? now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'fecha_primer_pago_unico' => self::format_contract_date($lead->contract_fecha_primer_pago_unico),

            'financiacion'         => $financiacion_filas,

            'mensualidad_moneda'          => $mensualidad_moneda,
            'mensualidad_base'            => $lead->contract_mensualidad_base,
            'usuarios_incluidos'          => $lead->contract_usuarios_incluidos ?? 1,
            'usuarios_extra'              => $usuarios_extra,
            'precio_usuario_extra'        => $lead->contract_precio_usuario_extra,
            'perfiles_ecommerce'          => $perfiles_ecommerce,
            'precio_perfil_ecommerce'     => $lead->contract_precio_perfil_ecommerce,
            'fecha_primer_pago_mensual'   => self::format_contract_date($lead->contract_fecha_primer_pago_mensual),

            'total_mensual'               => $total_mensual,
            'total_mensual_formateado'    => self::format_amount_for_display($total_mensual),
        ];
    }

    /**
     * Normaliza filas de financiación para la tabla del PDF.
     *
     * @param mixed $financiacion_raw JSON decodificado o array.
     *
     * @return array<int, array{monto: string, fecha: string}>
     */
    protected static function normalize_financiacion_rows($financiacion_raw): array
    {
        if (!is_array($financiacion_raw)) {
            return [];
        }

        $filas = [];
        foreach ($financiacion_raw as $cuota) {
            if (!is_array($cuota)) {
                continue;
            }
            $monto = isset($cuota['monto']) ? (string) $cuota['monto'] : '';
            $fecha_raw = $cuota['fecha'] ?? null;
            $fecha = $fecha_raw ? (self::format_contract_date($fecha_raw) ?? (string) $fecha_raw) : '';
            $filas[] = [
                'monto' => $monto,
                'fecha' => $fecha,
            ];
        }

        return $filas;
    }

    /**
     * Formatea una fecha de contrato en español (día de mes de año).
     *
     * @param mixed $value Fecha en string, Carbon o null.
     *
     * @return string|null
     */
    protected static function format_contract_date($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse($value)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }

    /**
     * Suma mensualidad base + usuarios extra + perfiles ecommerce.
     *
     * @param string|null $mensualidad_base
     * @param int         $usuarios_extra
     * @param string|null $precio_usuario_extra
     * @param int         $perfiles_ecommerce
     * @param string|null $precio_perfil_ecommerce
     *
     * @return float
     */
    protected static function calculate_monthly_total(
        $mensualidad_base,
        int $usuarios_extra,
        $precio_usuario_extra,
        int $perfiles_ecommerce,
        $precio_perfil_ecommerce
    ): float {
        $total = self::parse_numeric_amount($mensualidad_base);

        if ($usuarios_extra > 0) {
            $total += $usuarios_extra * self::parse_numeric_amount($precio_usuario_extra);
        }

        if ($perfiles_ecommerce > 0) {
            $total += $perfiles_ecommerce * self::parse_numeric_amount($precio_perfil_ecommerce);
        }

        return $total;
    }

    /**
     * Convierte un monto almacenado como string a float para cálculos.
     *
     * @param mixed $value
     *
     * @return float
     */
    protected static function parse_numeric_amount($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^0-9.,]/', '', (string) $value);
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }

    /**
     * Formatea un monto numérico para mostrar en el PDF (sin decimales si es entero).
     *
     * @param float $amount
     *
     * @return string
     */
    protected static function format_amount_for_display(float $amount): string
    {
        if (floor($amount) == $amount) {
            return number_format($amount, 0, ',', '.');
        }

        return number_format($amount, 2, ',', '.');
    }
}

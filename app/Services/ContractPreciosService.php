<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Desglose acordado en cada contrato; los contratos anteriores conservan sus totales. */
class ContractPreciosService
{
    /**
     * Planes de Gestión por OPERACIONES mensuales (ventas + compras + gastos), política del
     * 3/11/2026 (programa «Prueba gratuita de 7 días», misión prueba-precios-landing). Copian los
     * cortes de Contagram un 5 % más baratos, redondeados a la centena para abajo; el abono es el
     * precio final (no se le suma IVA). Son los mismos que publica comerciocity.com, donde se muestran
     * con el 50 % de descuento de los primeros tres meses (ver DESCUENTO_INICIAL).
     *
     * El abono de cada plan lo precarga la SPA y es editable por contrato; acá viven el nombre, el
     * rango (`desde`/`limite`), los usuarios incluidos y el precio de la operación excedente, que es
     * lo que usa `cuota_mensual()`. `limite` null = sin tope (a medida: más de 3.000 operaciones por
     * mes, importe libre).
     *
     * 🔴 Gestión ya no tiene licencia (pago único): la precarga de la SPA la deja en 0. Tienda y
     * Agentes conservan la suya (USD 800 cada una, la mitad si contrata durante la prueba).
     */
    const PLANES_GESTION = [
        'inicial'    => ['nombre' => 'Inicial',    'desde' => 0,   'limite' => 100,  'abono' => 55900,  'usuarios' => 1, 'excedente' => 760],
        'intermedio' => ['nombre' => 'Intermedio', 'desde' => 101, 'limite' => 250,  'abono' => 85400,  'usuarios' => 2, 'excedente' => 570],
        'plus'       => ['nombre' => 'Plus',       'desde' => 251, 'limite' => 500,  'abono' => 199400, 'usuarios' => 2, 'excedente' => 190],
        'empresa'    => ['nombre' => 'Empresa',    'desde' => 501, 'limite' => 3000, 'abono' => 263000, 'usuarios' => 3, 'excedente' => 76],
        'a_medida'   => ['nombre' => 'A medida',   'desde' => 3001, 'limite' => null, 'abono' => null,  'usuarios' => 3, 'excedente' => null],
    ];

    /**
     * Los planes de la política anterior (26/10/2026, por VENTAS mensuales), que siguen siendo
     * válidos en los contratos que los eligieron: un contrato viejo tiene que poder regenerar el
     * MISMO PDF que firmó, con «ventas por mes» y no «operaciones». `inicial` existe en las dos
     * políticas con otro rango: el histórico se reconoce por su abono pactado (ver `plan_gestion()`).
     */
    const PLANES_HISTORICOS = [
        'inicial'  => ['nombre' => 'Inicial',  'limite' => 300,  'abono' => 34200],
        'estandar' => ['nombre' => 'Estándar', 'limite' => 1500, 'abono' => 71500],
        'pro'      => ['nombre' => 'Pro',      'limite' => 7000, 'abono' => 105600],
    ];

    /** Cada usuario adicional, por mes, en cualquier plan (Contagram $32.000 × 0,95 → $30.400). */
    const USUARIO_EXTRA = 30400;

    /** El descuento de bienvenida: 50 % del abono durante los primeros 3 meses (Lucas, 3/11/2026). */
    const DESCUENTO_INICIAL = ['porcentaje' => 50, 'meses' => 3];

    /**
     * Texto del plan para el PDF, o null si la clave no es un plan conocido (vigente ni histórico).
     *
     * Con `$abono_pactado` (el importe de Gestión guardado en `contract_precios_sistemas`), un
     * `inicial` de $34.200 se lee como el histórico de 300 ventas y no como el vigente de 100
     * operaciones: el PDF de un contrato firmado antes del 3/11/2026 no cambia.
     *
     * @param mixed      $clave
     * @param mixed|null $abono_pactado
     *
     * @return array|null
     */
    public static function plan_gestion($clave, $abono_pactado = null): ?array
    {
        if (!is_string($clave)) {
            return null;
        }
        $historico = isset(self::PLANES_HISTORICOS[$clave]) ? self::PLANES_HISTORICOS[$clave] : null;
        $vigente = isset(self::PLANES_GESTION[$clave]) ? self::PLANES_GESTION[$clave] : null;

        if ($historico !== null && ($vigente === null || self::es_abono_historico($clave, $abono_pactado))) {
            return [
                'clave'  => $clave,
                'nombre' => $historico['nombre'],
                'limite' => 'hasta '.number_format($historico['limite'], 0, ',', '.').' ventas por mes',
            ];
        }
        if ($vigente === null) {
            return null;
        }
        if ($vigente['limite'] === null) {
            $limite = 'más de '.number_format(self::PLANES_GESTION['empresa']['limite'], 0, ',', '.').' operaciones por mes';
        } elseif ($vigente['desde'] === 0) {
            $limite = 'hasta '.number_format($vigente['limite'], 0, ',', '.').' operaciones por mes';
        } else {
            $limite = 'de '.number_format($vigente['desde'], 0, ',', '.').' a '.number_format($vigente['limite'], 0, ',', '.').' operaciones por mes';
        }
        return ['clave' => $clave, 'nombre' => $vigente['nombre'], 'limite' => $limite];
    }

    /**
     * ¿Este abono pactado es el del plan histórico con la misma clave? Solo decide para las claves
     * que existen en las dos políticas (hoy, `inicial`).
     *
     * @param string $clave
     * @param mixed  $abono_pactado
     *
     * @return bool
     */
    private static function es_abono_historico(string $clave, $abono_pactado): bool
    {
        if (!isset(self::PLANES_HISTORICOS[$clave]) || !isset(self::PLANES_GESTION[$clave])) {
            return false;
        }
        $abono = ClientContratoService::monto_desde_texto($abono_pactado);
        return $abono > 0 && abs($abono - (float) self::PLANES_HISTORICOS[$clave]['abono']) < 0.01;
    }

    /**
     * El plan vigente que corresponde a una cantidad de operaciones por mes.
     *
     * @param int $operaciones
     *
     * @return string Clave de PLANES_GESTION.
     */
    public static function plan_por_operaciones(int $operaciones): string
    {
        $operaciones = max(0, $operaciones);
        foreach (self::PLANES_GESTION as $clave => $plan) {
            if ($plan['limite'] !== null && $operaciones <= $plan['limite']) {
                return $clave;
            }
        }
        return 'a_medida';
    }

    /**
     * La cuota mensual de Gestión de un plan para las operaciones del mes: el abono más los
     * excedentes (cada operación por encima del límite, al precio del plan) más los usuarios extra.
     *
     * El sistema del cliente no frena nada al pasarse: el excedente se cobra con la mensualidad.
     * Para `a_medida`, o un plan desconocido, no hay excedente (el importe es libre) y el abono es el
     * pactado.
     *
     * @param string     $plan           Clave de PLANES_GESTION.
     * @param int        $operaciones    Ventas + compras + gastos del mes.
     * @param int        $usuarios_extra Usuarios por encima de los incluidos en el plan.
     * @param float|null $abono_pactado  El abono del contrato si difiere del de lista (editable).
     *
     * @return array{plan: string, nombre: string, abono: float, operaciones: int, limite: int|null, excedentes: int, precio_excedente: float, importe_excedentes: float, usuarios_extra: int, precio_usuario_extra: float, importe_usuarios: float, total: float}
     */
    public static function cuota_mensual(string $plan, int $operaciones, int $usuarios_extra = 0, ?float $abono_pactado = null): array
    {
        $datos = isset(self::PLANES_GESTION[$plan]) ? self::PLANES_GESTION[$plan] : self::PLANES_GESTION['a_medida'];
        $clave = isset(self::PLANES_GESTION[$plan]) ? $plan : 'a_medida';
        $abono = $abono_pactado !== null ? (float) $abono_pactado : (float) ($datos['abono'] ?? 0);
        $operaciones = max(0, $operaciones);
        $usuarios_extra = max(0, $usuarios_extra);

        $excedentes = 0;
        $precio_excedente = (float) ($datos['excedente'] ?? 0);
        if ($datos['limite'] !== null && $operaciones > $datos['limite']) {
            $excedentes = $operaciones - $datos['limite'];
        }
        $importe_excedentes = round($excedentes * $precio_excedente, 2);
        $importe_usuarios = round($usuarios_extra * self::USUARIO_EXTRA, 2);

        return [
            'plan'                 => $clave,
            'nombre'               => $datos['nombre'],
            'abono'                => round($abono, 2),
            'operaciones'          => $operaciones,
            'limite'               => $datos['limite'],
            'excedentes'           => $excedentes,
            'precio_excedente'     => $precio_excedente,
            'importe_excedentes'   => $importe_excedentes,
            'usuarios_extra'       => $usuarios_extra,
            'precio_usuario_extra' => (float) self::USUARIO_EXTRA,
            'importe_usuarios'     => $importe_usuarios,
            'total'                => round($abono + $importe_excedentes + $importe_usuarios, 2),
        ];
    }

    /**
     * El abono con el descuento de bienvenida (los primeros 3 meses al 50 %): lo que muestra la
     * landing.
     *
     * @param float $abono
     *
     * @return float
     */
    public static function abono_con_descuento_inicial(float $abono): float
    {
        return round($abono * (100 - self::DESCUENTO_INICIAL['porcentaje']) / 100, 2);
    }

    public static function reglas(): array
    {
        $centavos = function ($atributo, $valor, $fallar) {
            if (is_numeric($valor) && abs((float) $valor * 100 - round((float) $valor * 100)) > 0.00001) {
                $fallar('El importe admite hasta dos decimales.');
            }
        };
        /* Vigentes e históricos: guardar un contrato viejo sin tocarle el plan no puede dar 422. */
        $planes = array_unique(array_merge(array_keys(self::PLANES_GESTION), array_keys(self::PLANES_HISTORICOS)));
        $reglas = [
            'contract_precios_sistemas' => ['nullable', 'array:gestion,ecommerce,agentes', 'size:3'],
            'contract_descuento_conjunto' => ['nullable', 'numeric', 'min:0', 'max:999999999', $centavos],
            'contract_mes_gratis' => ['nullable', 'boolean'],
            'contract_plan_gestion' => ['nullable', 'string', 'in:'.implode(',', $planes)],
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

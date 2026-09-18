<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LicenciaCuota;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * El contrato del cliente: cómo llega del lead y qué se deriva de él (misión modulo-cobranzas,
 * 18/9/2026).
 *
 * Tres cosas, y las tres salen de las mismas 17 columnas `contract_*` que el lead ya tenía:
 *
 *  1. **Copiarlo del lead al cliente** cuando el lead se promueve (`RunUserSetupService::
 *     ensure_production_client`) y, para atrás, con `cobranzas:copiar-contratos-de-leads`.
 *  2. **Generar las cuotas de la licencia** desde `contract_financiacion` (una por fila) o, si no
 *     hay financiación, una sola por `contract_precio_licencia`.
 *  3. **Deducir el primer mes que se cobra** la mensualidad desde `contract_fecha_primer_pago_mensual`.
 *
 * Todo es idempotente a propósito: la copia no pisa un contrato ya copiado (salvo que se le pida),
 * las cuotas no se generan si el cliente ya tiene alguna, y el inicio de mensualidad lo setea el
 * llamador solo cuando está en null. La misma función corre en la promoción de hoy y en el
 * backfill de los 46 clientes ya promovidos, y no puede dar distinto.
 */
class ClientContratoService
{
    /**
     * Las 17 columnas del contrato que comparten `leads` y `clients`, con el mismo nombre en las
     * dos tablas. Es la lista que se copia y la que mira `lead_tiene_contrato()`.
     *
     * @var array<int, string>
     */
    const CAMPOS_CONTRATO = [
        'contract_client_name',
        'contract_client_razon_social',
        'contract_client_cuit',
        'contract_currency',
        'contract_precio_licencia',
        'contract_fecha_emision',
        'contract_fecha_primer_pago_unico',
        'contract_financiacion',
        'contract_mensualidad_moneda',
        'contract_mensualidad_base',
        'contract_usuarios_incluidos',
        'contract_usuarios_extra',
        'contract_precio_usuario_extra',
        'contract_perfiles_ecommerce',
        'contract_precio_perfil_ecommerce',
        'contract_fecha_primer_pago_mensual',
        'contract_clausulas_particulares',
    ];

    /**
     * Meses entre actualizaciones por IPC cuando el contrato no dice otra cosa. Es el "seis (6)
     * meses" que el PDF tuvo siempre como texto fijo.
     */
    const MESES_ACTUALIZACION_DEFAULT = 6;

    /**
     * ¿El lead tiene algo cargado en el contrato?
     *
     * Mira las 17 columnas y devuelve true con la primera que no esté vacía. Los enteros con
     * default 0 (`contract_usuarios_extra`, `contract_perfiles_ecommerce`) no cuentan como
     * "cargado" cuando valen 0, porque los tiene todo lead sin que nadie los haya tocado.
     *
     * @param Lead $lead
     *
     * @return bool
     */
    public function lead_tiene_contrato(Lead $lead): bool
    {
        foreach (self::CAMPOS_CONTRATO as $campo) {
            $valor = $lead->{$campo};

            if ($valor === null || $valor === '' || $valor === []) {
                continue;
            }

            // Un 0 en un contador con default 0 no es un dato cargado.
            if (is_numeric($valor) && (float) $valor == 0.0
                && in_array($campo, ['contract_usuarios_extra', 'contract_perfiles_ecommerce'], true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Copia el contrato del lead al cliente.
     *
     * No pisa un cliente que ya tenga `contract_copiado_desde_lead_at` salvo con `$pisar`: ese
     * cliente pudo haber editado el contrato en su propia pestaña después de la promoción, y el
     * backfill no tiene por qué deshacerle eso. Tampoco copia nada si el lead no tiene contrato
     * cargado: marcaría "copiado del lead" sobre 17 nulos y la pestaña del cliente diría que hay
     * contrato cuando no lo hay.
     *
     * @param Lead   $lead   De dónde.
     * @param Client $client A dónde.
     * @param bool   $pisar  Copiar aunque el cliente ya tenga un contrato copiado.
     *
     * @return bool Si copió.
     */
    public function copiar_desde_lead(Lead $lead, Client $client, bool $pisar = false): bool
    {
        if (! $pisar && $client->contract_copiado_desde_lead_at !== null) {
            return false;
        }

        if (! $this->lead_tiene_contrato($lead)) {
            return false;
        }

        foreach (self::CAMPOS_CONTRATO as $campo) {
            $client->{$campo} = $lead->{$campo};
        }

        // Los meses de actualización: los del lead, o el default histórico si el lead no los tiene.
        $meses = (int) ($lead->contract_meses_actualizacion ?? 0);
        $client->contract_meses_actualizacion = $meses > 0 ? $meses : self::MESES_ACTUALIZACION_DEFAULT;

        $client->contract_copiado_desde_lead_at = now();
        $client->save();

        return true;
    }

    /**
     * Genera las cuotas de la licencia desde el contrato del cliente.
     *
     * - Con `contract_financiacion` (`[{monto, fecha}]`): una cuota por fila, numeradas en orden,
     *   con el monto parseado con `monto_desde_texto()` (formato argentino: `1.500` son mil
     *   quinientos, no uno y medio), `vencimiento` = la fecha, `periodo` = el mes de esa fecha.
     * - Sin financiación pero con `contract_precio_licencia` > 0: UNA cuota por el total, con
     *   `vencimiento` = `contract_fecha_primer_pago_unico`.
     *
     * Si el cliente ya tiene alguna cuota (importada de la planilla o cargada a mano) no hace nada:
     * lo que hay es la verdad y no se duplica.
     *
     * @param Client $client
     *
     * @return int Cuántas cuotas creó.
     */
    public function generar_cuotas_desde_contrato(Client $client): int
    {
        if (LicenciaCuota::where('client_id', $client->id)->exists()) {
            return 0;
        }

        $moneda = $this->normalizar_moneda($client->contract_currency);

        /** Filas de financiación válidas (array con monto parseable > 0). */
        $filas = [];
        $financiacion = $client->contract_financiacion;

        if (is_array($financiacion)) {
            foreach ($financiacion as $cuota) {
                if (! is_array($cuota)) {
                    continue;
                }

                $monto = self::monto_desde_texto($cuota['monto'] ?? null);
                if ($monto <= 0) {
                    continue;
                }

                $filas[] = [
                    'monto' => $monto,
                    'fecha' => $cuota['fecha'] ?? null,
                ];
            }
        }

        // Sin financiación: una sola cuota por el precio de la licencia, si lo hay.
        if (count($filas) === 0) {
            $precio_licencia = self::monto_desde_texto($client->contract_precio_licencia);

            if ($precio_licencia <= 0) {
                return 0;
            }

            $filas[] = [
                'monto' => $precio_licencia,
                'fecha' => $client->contract_fecha_primer_pago_unico,
            ];
        }

        $creadas = 0;

        foreach ($filas as $indice => $fila) {
            $vencimiento = $this->parsear_fecha($fila['fecha']);

            LicenciaCuota::create([
                'client_id'    => $client->id,
                'numero'       => $indice + 1,
                'periodo'      => $vencimiento ? $vencimiento->format('Y-m') : AppTime::now()->format('Y-m'),
                'vencimiento'  => $vencimiento ? $vencimiento->toDateString() : null,
                'monto'        => round($fila['monto'], 2),
                'moneda'       => $moneda,
                'estado'       => LicenciaCuota::ESTADO_PENDIENTE,
                'monto_pagado' => null,
                'fecha_pago'   => null,
                'observacion'  => null,
                'importado'    => false,
            ]);

            $creadas++;
        }

        return $creadas;
    }

    /**
     * Primer mes que se cobra la mensualidad según el contrato: el mes de
     * `contract_fecha_primer_pago_mensual`, o el mes corriente si el contrato no lo dice.
     *
     * @param Lead|Client $contrato Cualquiera de los dos modelos: leen la misma columna.
     *
     * @return string Primer día del mes, 'YYYY-MM-01'.
     */
    public function inicio_de_mensualidad_desde_contrato($contrato): string
    {
        $fecha = $this->parsear_fecha($contrato->contract_fecha_primer_pago_mensual);

        if ($fecha === null) {
            $fecha = AppTime::now();
        }

        return $fecha->copy()->startOfMonth()->toDateString();
    }

    /**
     * Convierte el monto tipeado en el contrato a número, leyéndolo como se escribe en Argentina.
     *
     * 🔴 No se usa `LeadContractPdfService::parse_numeric_amount()` a propósito: esa función toma
     * el punto como decimal, así que `'1.500'` (mil quinientos, como lo tipea cualquiera acá) da
     * 1,5 — y una cuota de licencia de USD 1,50 en vez de USD 1.500 es exactamente el error que
     * nadie ve hasta que llega la plata. La regla acá es la del lector humano: si hay punto Y coma,
     * el último de los dos es el decimal; si hay un solo separador y detrás vienen exactamente tres
     * dígitos (`1.500`, `12.000`, `1,500`), es de miles; en cualquier otro caso es decimal
     * (`1500.50`, `1,5`). Lo que no sea dígito ni separador (moneda, espacios, `$`) se descarta.
     * La regla del PDF queda como está: cambiarla ahí es otra misión y toca comprobantes ya emitidos.
     *
     * @param mixed $valor
     *
     * @return float 0.0 si no hay nada legible.
     */
    public static function monto_desde_texto($valor): float
    {
        if ($valor === null || $valor === '') {
            return 0.0;
        }

        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        /** Solo dígitos y separadores; el signo se pierde a propósito (una cuota nunca es negativa). */
        $limpio = preg_replace('/[^0-9.,]/', '', (string) $valor);

        if ($limpio === '' || $limpio === null) {
            return 0.0;
        }

        $ultimo_punto = strrpos($limpio, '.');
        $ultima_coma  = strrpos($limpio, ',');

        if ($ultimo_punto !== false && $ultima_coma !== false) {
            // Los dos separadores: el que aparece último es el decimal, el otro es de miles.
            $decimal = $ultimo_punto > $ultima_coma ? '.' : ',';
            $miles   = $decimal === '.' ? ',' : '.';
            $limpio  = str_replace($miles, '', $limpio);
            $limpio  = str_replace($decimal, '.', $limpio);

            return (float) $limpio;
        }

        $separador = $ultimo_punto !== false ? '.' : ($ultima_coma !== false ? ',' : null);

        if ($separador === null) {
            return (float) $limpio;
        }

        $partes = explode($separador, $limpio);

        /* Un solo separador con grupos de exactamente tres dígitos detrás (`1.500`, `1.500.000`)
         * es de miles. Cualquier otro largo (`1500.50`, `1,5`, `0.75`) es decimal. */
        $es_de_miles = count($partes) >= 2;
        foreach (array_slice($partes, 1) as $grupo) {
            if (strlen($grupo) !== 3) {
                $es_de_miles = false;
            }
        }

        if ($es_de_miles) {
            return (float) implode('', $partes);
        }

        return (float) str_replace(',', '.', $limpio);
    }

    /**
     * Moneda del contrato normalizada a las dos que admite la tabla. Cualquier otra cosa (o nada)
     * cae a USD, que es la moneda en la que se pactan las licencias.
     *
     * @param mixed $moneda
     *
     * @return string
     */
    private function normalizar_moneda($moneda): string
    {
        $moneda = strtoupper(trim((string) $moneda));

        return in_array($moneda, LicenciaCuota::MONEDAS, true) ? $moneda : 'USD';
    }

    /**
     * Parsea una fecha del contrato (string 'YYYY-MM-DD', Carbon o null) tolerando basura: una
     * fecha que no se entiende vuelve como null y se loguea, no frena la promoción de un lead.
     *
     * @param mixed $valor
     *
     * @return Carbon|null
     */
    private function parsear_fecha($valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return Carbon::instance($valor);
        }

        try {
            return Carbon::parse((string) $valor);
        } catch (\Throwable $error) {
            Log::warning('ClientContratoService: fecha de contrato ilegible, se ignora.', [
                'valor' => (string) $valor,
                'error' => $error->getMessage(),
            ]);

            return null;
        }
    }
}

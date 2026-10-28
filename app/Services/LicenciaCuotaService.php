<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Client;
use App\Models\LicenciaCuota;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Las cuotas de la licencia de un cliente: alta, edición, pagos (enteros o parciales), baja y el
 * resumen por moneda (misión modulo-cobranzas, 18/9/2026).
 *
 * Es la hoja LICENCIAS del Excel hecha servicio. La regla que manda es la del pago parcial —el
 * caso real de Únicas, que pagó $436.000 de una cuota de USD 600—: `monto_pagado` ACUMULA lo que
 * entró, y el estado se deduce de ahí (`pagada` si cubre el monto o si Lucas la da por completa,
 * `parcial` si entró algo, `pendiente` si nada). El resumen se separa por moneda porque en la
 * planilla conviven pesos y dólares, a veces dentro del mismo cliente, y sumarlos sería mentir.
 *
 * Desde la misión ventas-y-comisiones (28/10/2026) una cuota puede pertenecer a una venta
 * (`venta_id`). Cuando cambia una cuota de una venta —alta, monto, baja— el total de la venta y su
 * comisión se recalculan con `VentaService::sincronizar_total()`: el total de una venta es siempre
 * la suma de sus cuotas. Las cuotas sueltas (sin venta) se comportan exactamente como antes.
 */
class LicenciaCuotaService
{
    /**
     * Crea una cuota nueva para el cliente.
     *
     * `numero` se asigna como el siguiente al máximo existente si no viene. `periodo` se deriva
     * del vencimiento (o del mes corriente) si no viene, porque la columna del Excel es el mes y la
     * pantalla lo necesita siempre.
     *
     * `venta_id` (opcional, misión ventas-y-comisiones): la cuota nace dentro de esa venta, que
     * tiene que ser del mismo cliente (si no, 422), y en la moneda de la venta (la que venga se
     * ignora). La cuota se suma al total de la venta y la comisión se recalcula.
     *
     * @param Client $client
     * @param array  $datos {monto, moneda?, vencimiento?, periodo?, numero?, observacion?, venta_id?}
     *
     * @return LicenciaCuota
     *
     * @throws ValidationException Si `venta_id` no es una venta de este cliente.
     */
    public function crear(Client $client, array $datos): LicenciaCuota
    {
        $vencimiento = $this->fecha_o_null($datos['vencimiento'] ?? null);

        /* La venta, si vino: tiene que ser de ESTE cliente. Una cuota colgada de la venta de otro
           cliente sumaría plata a una cuenta ajena sin que nada lo muestre. */
        $venta = null;
        if (isset($datos['venta_id']) && $datos['venta_id'] !== null && $datos['venta_id'] !== '') {
            $venta = Venta::where('client_id', $client->id)->find((int) $datos['venta_id']);

            if ($venta === null) {
                throw ValidationException::withMessages([
                    'venta_id' => 'La venta indicada no existe o no es de este cliente.',
                ]);
            }
        }

        /* La cuota y el total de su venta van juntos o no van: si el recálculo fallara después de
           guardar la cuota, la venta quedaría con un total que no es la suma de sus cuotas. */
        return DB::transaction(function () use ($client, $datos, $vencimiento, $venta) {
            /** Siguiente número libre del cliente. */
            $numero = isset($datos['numero']) && (int) $datos['numero'] > 0
                ? (int) $datos['numero']
                : ((int) LicenciaCuota::where('client_id', $client->id)->max('numero')) + 1;

            $cuota = LicenciaCuota::create([
                'client_id'    => $client->id,
                'venta_id'     => $venta ? $venta->id : null,
                'numero'       => $numero,
                'periodo'      => $this->periodo_de($datos['periodo'] ?? null, $vencimiento),
                'vencimiento'  => $vencimiento ? $vencimiento->toDateString() : null,
                'monto'        => round((float) $datos['monto'], 2),
                // Con venta, la moneda es SIEMPRE la de la venta: una cuota en otra moneda partiría el
                // total en dos números que no se suman. La que venga se ignora (sin 422, para que la
                // SPA vieja, que manda la moneda del formulario, no se rompa).
                'moneda'       => $venta ? $venta->moneda : $this->moneda($datos['moneda'] ?? null),
                'estado'       => LicenciaCuota::ESTADO_PENDIENTE,
                'monto_pagado' => null,
                'fecha_pago'   => null,
                'observacion'  => $this->texto_o_null($datos['observacion'] ?? null),
                'importado'    => false,
            ]);

            if ($venta !== null) {
                $this->ventas()->sincronizar_total($venta);
            }

            return $cuota;
        });
    }

    /**
     * Edita los datos pactados de una cuota (monto, moneda, vencimiento, mes, nota) y, si viene,
     * fuerza el estado a mano. Solo toca las claves presentes en `$datos`.
     *
     * Si cambia el monto y NO viene un estado explícito, el estado se recalcula contra lo pagado:
     * bajar el monto de una cuota parcial puede dejarla cubierta, y la pantalla no tiene por qué
     * pedir dos guardados para reflejarlo.
     *
     * @param LicenciaCuota $cuota
     * @param array         $datos {monto?, moneda?, vencimiento?, periodo?, numero?, observacion?, estado?}
     *
     * @return LicenciaCuota
     */
    public function editar(LicenciaCuota $cuota, array $datos): LicenciaCuota
    {
        if (array_key_exists('monto', $datos) && $datos['monto'] !== null && $datos['monto'] !== '') {
            $cuota->monto = round((float) $datos['monto'], 2);
        }

        /* La moneda de una cuota que es de una venta no se cambia de a una: es la de la venta, y se
           cambia desde la venta (que la cambia en todas sus cuotas). Se ignora en silencio, sin 422,
           para que la SPA vieja —que manda la moneda en cada edición— no se rompa. */
        if ($cuota->venta_id === null && array_key_exists('moneda', $datos) && $datos['moneda'] !== null && $datos['moneda'] !== '') {
            $cuota->moneda = $this->moneda($datos['moneda']);
        }

        if (array_key_exists('vencimiento', $datos)) {
            $vencimiento = $this->fecha_o_null($datos['vencimiento']);
            $cuota->vencimiento = $vencimiento ? $vencimiento->toDateString() : null;
        }

        if (array_key_exists('periodo', $datos) && $datos['periodo'] !== null && $datos['periodo'] !== '') {
            $cuota->periodo = (string) $datos['periodo'];
        } elseif (array_key_exists('vencimiento', $datos) && $cuota->vencimiento) {
            // Cambió el vencimiento y no dijeron el mes: el mes sigue al vencimiento.
            $cuota->periodo = Carbon::parse($cuota->vencimiento)->format('Y-m');
        }

        if (array_key_exists('numero', $datos) && (int) $datos['numero'] > 0) {
            $cuota->numero = (int) $datos['numero'];
        }

        if (array_key_exists('observacion', $datos)) {
            $cuota->observacion = $this->texto_o_null($datos['observacion']);
        }

        if (array_key_exists('estado', $datos) && in_array($datos['estado'], LicenciaCuota::ESTADOS, true)) {
            $cuota->estado = $datos['estado'];
        } elseif ($cuota->isDirty('monto')) {
            $cuota->estado = $this->estado_por_monto($cuota, false);
        }

        $cambio_el_monto = $cuota->isDirty('monto');

        // Guardar la cuota y recalcular su venta, en una sola transacción (mismo motivo que crear()).
        DB::transaction(function () use ($cuota, $cambio_el_monto) {
            $cuota->save();

            // Si la cuota es de una venta y cambió su monto, el total de la venta y su comisión lo siguen.
            if ($cambio_el_monto) {
                $this->sincronizar_venta_de($cuota);
            }
        });

        return $cuota;
    }

    /**
     * Registra un pago sobre la cuota, acumulando sobre lo que ya había entrado.
     *
     * `completa` es Lucas diciendo "con esto la cuota está" aunque el monto no llegue (un descuento,
     * una diferencia de cambio); sin eso decide la cuenta: cubre el monto → `pagada`, entró algo →
     * `parcial`.
     *
     * @param LicenciaCuota $cuota
     * @param float         $monto_pagado Lo que entró AHORA (se suma a lo anterior).
     * @param string|null   $fecha_pago   Cuándo; default hoy.
     * @param string|null   $observacion  Reemplaza la nota si viene.
     * @param bool          $completa     Dar la cuota por pagada aunque falte.
     *
     * @return LicenciaCuota
     */
    public function registrar_pago(LicenciaCuota $cuota, float $monto_pagado, ?string $fecha_pago = null, ?string $observacion = null, bool $completa = false): LicenciaCuota
    {
        $cuota->monto_pagado = round((float) ($cuota->monto_pagado ?? 0) + max(0.0, $monto_pagado), 2);

        $fecha = $this->fecha_o_null($fecha_pago);
        $cuota->fecha_pago = ($fecha ?? AppTime::now())->toDateString();

        if ($observacion !== null) {
            $cuota->observacion = $this->texto_o_null($observacion);
        }

        $cuota->estado = $this->estado_por_monto($cuota, $completa);
        $cuota->save();

        return $cuota;
    }

    /**
     * Borra la cuota.
     *
     * @param LicenciaCuota $cuota
     *
     * @return void
     */
    public function eliminar(LicenciaCuota $cuota): void
    {
        // Borrar la cuota y recalcular su venta, en una sola transacción (mismo motivo que crear()).
        DB::transaction(function () use ($cuota) {
            $cuota->delete();

            // Si era de una venta, la venta baja su total (y su comisión) en lo que valía la cuota.
            $this->sincronizar_venta_de($cuota);
        });
    }

    /**
     * Las cuotas del cliente ordenadas por número, listas para el front.
     *
     * @param Client $client
     *
     * @return array<int, array<string, mixed>>
     */
    public function cuotas(Client $client): array
    {
        return LicenciaCuota::where('client_id', $client->id)
            ->orderBy('numero')->orderBy('id')
            ->get()
            ->map(function (LicenciaCuota $cuota) {
                return $this->cuota_para_json($cuota);
            })
            ->all();
    }

    /**
     * Una cuota tal como viaja al front. Los decimales van como float (el cast los deja string).
     *
     * @param LicenciaCuota $cuota
     *
     * @return array<string, mixed>
     */
    public function cuota_para_json(LicenciaCuota $cuota): array
    {
        return [
            'id'              => $cuota->id,
            'client_id'       => $cuota->client_id,
            'venta_id'        => $cuota->venta_id !== null ? (int) $cuota->venta_id : null,
            'numero'          => (int) $cuota->numero,
            'periodo'         => $cuota->periodo,
            'vencimiento'     => $cuota->vencimiento ? Carbon::parse($cuota->vencimiento)->toDateString() : null,
            'monto'           => (float) $cuota->monto,
            'moneda'          => $cuota->moneda,
            'estado'          => $cuota->estado,
            'monto_pagado'    => $cuota->monto_pagado !== null ? (float) $cuota->monto_pagado : null,
            'monto_cobrado'   => $cuota->monto_cobrado(),
            'monto_pendiente' => $cuota->monto_pendiente(),
            'fecha_pago'      => $cuota->fecha_pago ? Carbon::parse($cuota->fecha_pago)->toDateString() : null,
            'observacion'     => $cuota->observacion,
            'importado'       => (bool) $cuota->importado,
        ];
    }

    /**
     * El resumen de la licencia del cliente, por moneda: cuántas cuotas, cuánto suman, cuánto
     * entró, cuánto falta, y cuántas están pendientes o parciales.
     *
     * Las claves de moneda aparecen solo para las monedas que el cliente tiene; un cliente sin
     * cuotas devuelve los tres mapas vacíos y cero en los contadores.
     *
     * @param Client $client
     *
     * @return array{cantidad: int, total_por_moneda: array, pagado_por_moneda: array, pendiente_por_moneda: array, pendientes: int, parciales: int}
     */
    public function resumen(Client $client): array
    {
        return $this->resumen_de(LicenciaCuota::where('client_id', $client->id)->get());
    }

    /**
     * El mismo resumen, sobre cuotas ya cargadas: lo usa la tabla del módulo, que trae las cuotas
     * de todos los clientes en una sola consulta y no puede permitirse una por fila.
     *
     * @param \Illuminate\Support\Collection $cuotas Cuotas de UN cliente.
     *
     * @return array<string, mixed>
     */
    public function resumen_de($cuotas): array
    {
        $total = [];
        $pagado = [];
        $pendiente = [];
        $pendientes = 0;
        $parciales = 0;

        foreach ($cuotas as $cuota) {
            $moneda = $cuota->moneda;

            $total[$moneda] = round(($total[$moneda] ?? 0) + (float) $cuota->monto, 2);
            $pagado[$moneda] = round(($pagado[$moneda] ?? 0) + $cuota->monto_cobrado(), 2);
            $pendiente[$moneda] = round(($pendiente[$moneda] ?? 0) + $cuota->monto_pendiente(), 2);

            if ($cuota->estado === LicenciaCuota::ESTADO_PENDIENTE) {
                $pendientes++;
            } elseif ($cuota->estado === LicenciaCuota::ESTADO_PARCIAL) {
                $parciales++;
            }
        }

        return [
            'cantidad'             => $cuotas->count(),
            'total_por_moneda'     => $total,
            'pagado_por_moneda'    => $pagado,
            'pendiente_por_moneda' => $pendiente,
            'pendientes'           => $pendientes,
            'parciales'            => $parciales,
        ];
    }

    /**
     * Recalcula el total (y la comisión) de la venta de la cuota, si tiene. No hace nada con una
     * cuota suelta.
     *
     * @param LicenciaCuota $cuota
     *
     * @return void
     */
    protected function sincronizar_venta_de(LicenciaCuota $cuota): void
    {
        if ($cuota->venta_id === null) {
            return;
        }

        $venta = Venta::find($cuota->venta_id);

        if ($venta !== null) {
            $this->ventas()->sincronizar_total($venta);
        }
    }

    /**
     * El servicio de ventas, resuelto a demanda: `VentaService` recibe a este servicio por
     * constructor, así que inyectarlo acá también armaría un ciclo en el contenedor.
     *
     * @return VentaService
     */
    protected function ventas(): VentaService
    {
        return app(VentaService::class);
    }

    /**
     * El estado que corresponde a lo pagado: cubre el monto (o la dan por completa) → pagada;
     * entró algo → parcial; nada → pendiente.
     *
     * @param LicenciaCuota $cuota
     * @param bool          $completa
     *
     * @return string
     */
    protected function estado_por_monto(LicenciaCuota $cuota, bool $completa): string
    {
        $pagado = (float) ($cuota->monto_pagado ?? 0);

        if ($completa || ($pagado > 0 && $pagado >= (float) $cuota->monto)) {
            return LicenciaCuota::ESTADO_PAGADA;
        }

        if ($pagado > 0) {
            return LicenciaCuota::ESTADO_PARCIAL;
        }

        return LicenciaCuota::ESTADO_PENDIENTE;
    }

    /**
     * El mes de la cuota: el que vino, si no el del vencimiento, si no el corriente.
     *
     * @param mixed       $periodo
     * @param Carbon|null $vencimiento
     *
     * @return string
     */
    protected function periodo_de($periodo, ?Carbon $vencimiento): string
    {
        if (CobranzasMensualidadService::es_periodo_valido($periodo)) {
            return $periodo;
        }

        if ($vencimiento !== null) {
            return $vencimiento->format('Y-m');
        }

        return AppTime::now()->format('Y-m');
    }

    /**
     * Moneda normalizada a las dos admitidas; cualquier otra cosa cae a USD.
     *
     * @param mixed $moneda
     *
     * @return string
     */
    protected function moneda($moneda): string
    {
        $moneda = strtoupper(trim((string) $moneda));

        return in_array($moneda, LicenciaCuota::MONEDAS, true) ? $moneda : 'USD';
    }

    /**
     * @param mixed $valor
     *
     * @return Carbon|null
     */
    protected function fecha_o_null($valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return Carbon::parse($valor);
    }

    /**
     * @param mixed $valor
     *
     * @return string|null
     */
    protected function texto_o_null($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}

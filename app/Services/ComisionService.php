<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\ComisionPago;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Las comisiones de los vendedores (misión ventas-y-comisiones, 28/10/2026): los pagos que se le
 * hacen a cuenta de cada venta, la tabla de Cobranzas → Comisiones (la de Lucas) y el resumen de
 * "Mis comisiones" (la del vendedor).
 *
 * El cálculo de la comisión de UNA venta (total, liberada, pagada, a pagar, por liberar) vive en
 * `VentaService::comision_de()` y acá se usa tal cual: hay una sola definición de "cuánto le toca".
 *
 * 🔴 Se puede pagar más de lo liberado (un adelanto): el pago se registra igual y la respuesta lo
 * marca con `excede: true`. El front avisa ANTES de mandar; acá no se frena porque es plata que ya
 * salió y negarse a registrarla dejaría la cuenta peor.
 */
class ComisionService
{
    /**
     * @var VentaService
     */
    protected $ventas;

    /**
     * @param VentaService $ventas
     */
    public function __construct(VentaService $ventas)
    {
        $this->ventas = $ventas;
    }

    /**
     * Registra un pago al vendedor de la venta, a cuenta de su comisión.
     *
     * - La moneda es la de la venta (la comisión se liquida en la moneda de la venta).
     * - El vendedor es el que tiene la venta HOY; queda fijo en el pago.
     * - Una venta sin vendedor no admite pagos (422): no hay a quién pagarle.
     *
     * @param Venta       $venta
     * @param float       $monto Lo que se le paga ahora.
     * @param string|null $fecha Cuándo; default hoy.
     * @param string|null $nota  Nota libre.
     * @param Admin|null  $autor Quién lo registra.
     *
     * @return array{pago: ComisionPago, excede: bool} `excede` = el pago supera lo que estaba a pagar.
     *
     * @throws ValidationException
     */
    public function registrar_pago(Venta $venta, float $monto, ?string $fecha, ?string $nota, ?Admin $autor): array
    {
        if ($venta->vendedor_admin_id === null) {
            throw ValidationException::withMessages([
                'venta' => 'Esta venta no tiene vendedor asignado: asignale uno antes de registrar un pago de comisión.',
            ]);
        }

        if ($monto <= 0) {
            throw ValidationException::withMessages([
                'monto' => 'El monto del pago tiene que ser mayor a cero.',
            ]);
        }

        /* `a_pagar` ANTES del pago: es contra lo que se compara para avisar que excede. */
        $comision = $this->ventas->comision_de($venta);
        $excede = round($monto, 2) - $comision['a_pagar'] > 0.009;

        $pago = ComisionPago::create([
            'venta_id'                => $venta->id,
            'vendedor_admin_id'       => $venta->vendedor_admin_id,
            'monto'                   => round($monto, 2),
            'moneda'                  => $venta->moneda,
            'fecha'                   => ($fecha !== null && $fecha !== '' ? Carbon::parse($fecha) : AppTime::now())->toDateString(),
            'nota'                    => $this->texto_o_null($nota),
            'registrado_por_admin_id' => $autor ? $autor->id : null,
        ]);

        return ['pago' => $pago, 'excede' => $excede];
    }

    /**
     * Borra un pago al vendedor (un error de carga). La comisión a pagar vuelve a subir sola: se
     * calcula siempre desde los pagos.
     *
     * @param ComisionPago $pago
     *
     * @return void
     */
    public function eliminar_pago(ComisionPago $pago): void
    {
        $pago->delete();
    }

    /**
     * La tabla de comisiones: TODAS las ventas con vendedor (o las de uno, si se pide), cada una
     * con su cliente, su comisión y sus pagos. Tres consultas en total, sin importar cuántas
     * ventas haya (`with` de cuotas, pagos, vendedor y cliente).
     *
     * @param int|null $vendedor_admin_id
     *
     * @return array<int, array<string, mixed>>
     */
    public function tabla(?int $vendedor_admin_id): array
    {
        $query = Venta::query()
            ->whereNotNull('vendedor_admin_id')
            ->with(['cuotas', 'comision_pagos', 'vendedor', 'client'])
            ->orderByDesc('fecha')->orderByDesc('id');

        if ($vendedor_admin_id !== null) {
            $query->where('vendedor_admin_id', $vendedor_admin_id);
        }

        return $query->get()->map(function (Venta $venta) {
            return $this->fila($venta);
        })->values()->all();
    }

    /**
     * Una fila de la tabla de comisiones: la venta (`venta_para_json`) más el cliente y los pagos
     * al vendedor.
     *
     * @param Venta $venta Con `client`, `cuotas`, `comision_pagos` y `vendedor` cargadas.
     *
     * @return array<string, mixed>
     */
    public function fila(Venta $venta): array
    {
        $client = $venta->client;

        return array_merge($this->ventas->venta_para_json($venta), [
            'client' => [
                'id'     => (int) $venta->client_id,
                'nombre' => $client ? $client->resolve_display_name() : null,
            ],
            'pagos'  => $venta->comision_pagos->map(function (ComisionPago $pago) {
                return $this->pago_para_json($pago);
            })->values()->all(),
        ]);
    }

    /**
     * Totales por vendedor y por moneda, armados sobre filas de `tabla()`.
     *
     * @param array<int, array<string, mixed>> $filas
     *
     * @return array<int, array{vendedor_admin_id: int, vendedor_nombre: string|null, por_moneda: array}>
     */
    public function resumen_por_vendedor(array $filas): array
    {
        $por_vendedor = [];

        foreach ($filas as $fila) {
            $id = (int) $fila['comision']['vendedor_admin_id'];

            if (! isset($por_vendedor[$id])) {
                $por_vendedor[$id] = [
                    'vendedor_admin_id' => $id,
                    'vendedor_nombre'   => $fila['comision']['vendedor_nombre'],
                    'por_moneda'        => [],
                ];
            }

            $por_vendedor[$id]['por_moneda'] = $this->sumar_a($por_vendedor[$id]['por_moneda'], $fila['comision']);
        }

        return array_values($por_vendedor);
    }

    /**
     * Lo de UN vendedor ("Mis comisiones"): los totales por moneda (para cobrar, ya cobrado, se
     * libera cuando paguen los clientes) y la lista de pagos recibidos, del más nuevo al más viejo.
     *
     * Los pagos salen por `vendedor_admin_id` del pago, no de la venta: si una venta cambió de
     * vendedor, lo cobrado sigue figurando en quien lo cobró.
     *
     * @param int $vendedor_admin_id
     *
     * @return array{por_moneda: array, pagos: array}
     */
    public function resumen_vendedor(int $vendedor_admin_id): array
    {
        $por_moneda = [];

        foreach ($this->tabla($vendedor_admin_id) as $fila) {
            $por_moneda = $this->sumar_a($por_moneda, $fila['comision']);
        }

        $pagos = ComisionPago::where('vendedor_admin_id', $vendedor_admin_id)
            ->with(['venta.client'])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get()
            ->map(function (ComisionPago $pago) {
                $json = $this->pago_para_json($pago);
                $client = $pago->venta ? $pago->venta->client : null;
                $json['client'] = [
                    'id'     => $pago->venta ? (int) $pago->venta->client_id : null,
                    'nombre' => $client ? $client->resolve_display_name() : null,
                ];

                return $json;
            })
            ->values()
            ->all();

        return [
            'por_moneda' => $por_moneda,
            'pagos'      => $pagos,
        ];
    }

    /**
     * Un pago al vendedor tal como viaja al front.
     *
     * @param ComisionPago $pago
     *
     * @return array<string, mixed>
     */
    public function pago_para_json(ComisionPago $pago): array
    {
        return [
            'id'                      => (int) $pago->id,
            'venta_id'                => (int) $pago->venta_id,
            'vendedor_admin_id'       => (int) $pago->vendedor_admin_id,
            'monto'                   => (float) $pago->monto,
            'moneda'                  => (string) $pago->moneda,
            'fecha'                   => $pago->fecha ? Carbon::parse($pago->fecha)->toDateString() : null,
            'nota'                    => $pago->nota,
            'registrado_por_admin_id' => $pago->registrado_por_admin_id !== null ? (int) $pago->registrado_por_admin_id : null,
            'created_at'              => $pago->created_at ? $pago->created_at->toDateTimeString() : null,
        ];
    }

    /**
     * Suma una comisión (de `VentaService::comision_de()`) a un acumulado por moneda.
     *
     * @param array<string, array<string, float>> $acumulado
     * @param array<string, mixed>                $comision
     *
     * @return array<string, array<string, float>>
     */
    protected function sumar_a(array $acumulado, array $comision): array
    {
        $moneda = (string) $comision['moneda'];

        if (! isset($acumulado[$moneda])) {
            $acumulado[$moneda] = ['total' => 0.0, 'liberada' => 0.0, 'pagada' => 0.0, 'a_pagar' => 0.0, 'por_liberar' => 0.0];
        }

        foreach (['total', 'liberada', 'pagada', 'a_pagar', 'por_liberar'] as $clave) {
            $acumulado[$moneda][$clave] = round($acumulado[$moneda][$clave] + (float) $comision[$clave], 2);
        }

        return $acumulado;
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

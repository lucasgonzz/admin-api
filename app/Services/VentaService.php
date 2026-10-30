<?php

namespace App\Services;

use App\Helpers\AppTime;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ComisionPago;
use App\Models\Lead;
use App\Models\LicenciaCuota;
use App\Models\Sena;
use App\Models\Venta;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Las ventas de un cliente y la comisión del vendedor (misión ventas-y-comisiones, 28/10/2026).
 *
 * Una venta es lo que se le vende a un cliente —el sistema de gestión, el e-commerce, los agentes,
 * un desarrollo a medida u otro motivo— con sus cuotas. Nace de tres lados y los tres pasan por acá:
 *
 *  1. **La promoción de un lead con contrato** (`ClientContratoService::generar_cuotas_desde_contrato()`
 *     → `crear_desde_cuotas()`), origen `contrato`.
 *  2. **A mano desde el admin** (`POST client/{id}/ventas` → `crear()`), origen `manual`.
 *  3. **Pedida desde Claude** (`POST claude/clients/{id}/ventas` → `armar()` en el dry_run y
 *     `crear()` en el real), origen `claude`.
 *
 * Y una cuarta, de una sola vez: `ventas:migrar-licencias` agrupa en ventas las cuotas que ya
 * existían (origen `planilla` o `contrato`).
 *
 * 🔴 LA COMISIÓN SE LIBERA A MEDIDA QUE PAGA EL CLIENTE (decisión de Lucas en la Fase 2). Al dar de
 * alta se calcula la comisión TOTAL (`monto_total × porcentaje / 100`, en `comision_monto`); lo que
 * el vendedor puede cobrar es esa comisión por la fracción cobrada de la venta. Se le paga sobre lo
 * liberado. No hay comisión sobre mensualidades ni sobre el abono anual: el esquema viejo de "% de
 * la entrada + resto en seis mensualidades" se eliminó.
 *
 * 🔴 `monto_total` SIGUE A LAS CUOTAS. Después del alta, el total de la venta es la suma de sus
 * cuotas: agregar una cuota, cambiarle el monto o borrarla (también por las rutas viejas de
 * licencias, que siguen andando) recalcula el total y la comisión con `sincronizar_total()`. Si el
 * total pudiera quedar distinto de la suma de las cuotas, "lo que debe el cliente" y la fracción
 * liberada de la comisión saldrían de dos números distintos y uno de los dos mentiría.
 */
class VentaService
{
    /**
     * @var LicenciaCuotaService
     */
    protected $cuotas;

    /**
     * @param LicenciaCuotaService $cuotas
     */
    public function __construct(LicenciaCuotaService $cuotas)
    {
        $this->cuotas = $cuotas;
    }

    /**
     * Reglas de validación de FORMA del alta de una venta. Las comparten el alta del admin
     * (`ClientVentaController`) y la de Claude (`ClaudeVentasController`), para que el mismo
     * payload valga igual por los dos caminos. Las reglas de NEGOCIO (que la suma de las cuotas dé
     * el total, que desarrollo/otro traigan importe) las aplica `armar()`.
     *
     * @return array<string, array<int, string>>
     */
    public static function reglas_de_alta(): array
    {
        return [
            'productos'             => ['required', 'array', 'min:1'],
            'productos.*'           => ['string', 'distinct', 'in:' . implode(',', array_keys(Venta::PRODUCTOS))],
            'descripcion'           => ['nullable', 'string', 'max:255'],
            'fecha'                 => ['nullable', 'date'],
            'monto_total'           => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'moneda'                => ['nullable', 'string', 'in:' . implode(',', LicenciaCuota::MONEDAS)],
            'contado'               => ['nullable', 'boolean'],
            'cuotas'                => ['nullable', 'array', 'min:1', 'max:60'],
            'cuotas.*.monto'        => ['required_with:cuotas', 'numeric', 'min:0', 'max:999999999'],
            'cuotas.*.vencimiento'  => ['nullable', 'date'],
            'cantidad_cuotas'       => ['nullable', 'integer', 'min:1', 'max:60'],
            'primer_vencimiento'    => ['nullable', 'date'],
            'vendedor_admin_id'     => ['nullable', 'integer', 'exists:admins,id'],
            'comision_porcentaje'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacion'           => ['nullable', 'string'],
        ];
    }

    /**
     * Reglas de validación de la edición de una venta: solo los datos de la venta, nunca las
     * cuotas (esas se gestionan una por una, como siempre).
     *
     * @return array<string, array<int, string>>
     */
    public static function reglas_de_edicion(): array
    {
        return [
            'productos'           => ['sometimes', 'array', 'min:1'],
            'productos.*'         => ['string', 'distinct', 'in:' . implode(',', array_keys(Venta::PRODUCTOS))],
            'descripcion'         => ['nullable', 'string', 'max:255'],
            'fecha'               => ['nullable', 'date'],
            'moneda'              => ['nullable', 'string', 'in:' . implode(',', LicenciaCuota::MONEDAS)],
            'vendedor_admin_id'   => ['nullable', 'integer', 'exists:admins,id'],
            'comision_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'observacion'         => ['nullable', 'string'],
        ];
    }

    /**
     * Da de alta una venta con sus cuotas, todo en una transacción.
     *
     * Es `armar()` (que valida y calcula todo sin escribir) más el guardado: la venta, y cada
     * cuota con su `venta_id` y el siguiente número libre del cliente.
     *
     * @param Client     $client
     * @param array      $datos  Ver `armar()`.
     * @param Admin|null $autor  Quién la carga (null = un proceso).
     * @param string     $origen Una de Venta::ORIGENES.
     *
     * @return Venta La venta guardada, con `cuotas`, `vendedor` y `comision_pagos` cargadas.
     *
     * @throws ValidationException Si el payload no cierra (422).
     */
    public function crear(Client $client, array $datos, ?Admin $autor, string $origen): Venta
    {
        $venta = $this->armar($client, $datos, $autor, $origen);

        DB::transaction(function () use ($venta) {
            /** Las cuotas armadas viajan como relación; se guardan después de la venta para tener su id. */
            $cuotas = $venta->getRelation('cuotas');

            $venta->save();

            /* El número se vuelve a pedir adentro de la transacción y no se confía en el que puso
               `armar()`: entre el armado y el guardado otra pestaña pudo haber agregado una cuota. */
            $numero = ((int) LicenciaCuota::where('client_id', $venta->client_id)->max('numero')) + 1;

            foreach ($cuotas as $cuota) {
                $cuota->venta_id = $venta->id;
                $cuota->numero = $numero++;
                $cuota->save();
            }
        });

        return $this->recargar($venta);
    }

    /**
     * Arma la venta que se crearía, SIN escribir nada: valida las reglas de negocio, completa los
     * defaults (precio sugerido, cuotas, vendedor y porcentaje por defecto) y devuelve una `Venta`
     * sin guardar con sus cuotas (también sin guardar) como relación `cuotas`. Es lo que devuelve
     * el dry_run de Claude, y `crear()` guarda exactamente esto: el dry_run no puede predecir
     * distinto de lo que después se escribe.
     *
     * Reglas, en orden:
     *  - `productos`: sin repetidos, en el orden de Venta::PRODUCTOS.
     *  - Con `cuotas` explícitas: la suma de sus montos es el total. Si además vino `monto_total` y
     *    no coincide (tolerancia de un centavo) → 422.
     *  - Sin `cuotas`: el total es `monto_total` o, si no vino, `precio_sugerido()`; si no hay precio
     *    (desarrollo/otro) → 422. En una moneda que no es USD el precio de lista no aplica (está en
     *    dólares): sin `monto_total` → 422. Se reparte en `cantidad_cuotas` cuotas iguales y mensuales desde
     *    `primer_vencimiento` (default: la fecha de la venta); la última absorbe el redondeo.
     *    `cantidad_cuotas` por defecto es 1, salvo los tres sistemas sin contado: 3.
     *  - El total tiene que ser mayor a cero.
     *  - `vendedor_admin_id`: ausente = `vendedor_por_defecto()`; null explícito = sin vendedor.
     *  - `comision_porcentaje`: ausente o null = `porcentaje_por_defecto()`.
     *
     * @param Client     $client
     * @param array      $datos  {productos[], descripcion?, fecha?, monto_total?, moneda?, contado?,
     *                           cuotas?: [{monto, vencimiento?}], cantidad_cuotas?, primer_vencimiento?,
     *                           vendedor_admin_id?, comision_porcentaje?, observacion?}
     * @param Admin|null $autor
     * @param string     $origen
     *
     * @return Venta Sin guardar, con las relaciones `cuotas`, `vendedor` y `comision_pagos` puestas.
     *
     * @throws ValidationException
     */
    public function armar(Client $client, array $datos, ?Admin $autor, string $origen): Venta
    {
        $productos = $this->normalizar_productos($datos['productos'] ?? []);

        if (count($productos) === 0) {
            throw ValidationException::withMessages([
                'productos' => 'Elegí al menos un producto: ' . implode(', ', array_keys(Venta::PRODUCTOS)) . '.',
            ]);
        }

        $contado = ! empty($datos['contado']);
        $moneda = $this->moneda($datos['moneda'] ?? null);
        $fecha = $this->fecha_o_null($datos['fecha'] ?? null) ?: AppTime::now()->copy()->startOfDay();

        /* Las cuotas: o vienen escritas una por una, o se reparten en partes iguales. */
        $filas = [];

        if (isset($datos['cuotas']) && is_array($datos['cuotas']) && count($datos['cuotas']) > 0) {
            $suma = 0.0;

            foreach (array_values($datos['cuotas']) as $cuota) {
                $monto = round((float) ($cuota['monto'] ?? 0), 2);
                $suma = round($suma + $monto, 2);
                $filas[] = [
                    'monto'       => $monto,
                    'vencimiento' => $this->fecha_o_null($cuota['vencimiento'] ?? null),
                ];
            }

            if ($this->viene_con_valor($datos, 'monto_total') && abs(round((float) $datos['monto_total'], 2) - $suma) > 0.009) {
                throw ValidationException::withMessages([
                    'cuotas' => 'La suma de las cuotas (' . number_format($suma, 2, ',', '.') . ') no coincide con el total de la venta ('
                        . number_format((float) $datos['monto_total'], 2, ',', '.') . '). Corregí una de las dos cosas.',
                ]);
            }

            $total = $suma;
        } else {
            /* Los precios de lista son en DÓLARES: precargarlos en una venta en pesos daría una venta
               de $1.500 por los tres sistemas. En pesos, el importe se escribe siempre. */
            if (! $this->viene_con_valor($datos, 'monto_total') && $moneda !== 'USD') {
                throw ValidationException::withMessages([
                    'monto_total' => 'Los precios de lista son en dólares: para una venta en ' . $moneda . ' indicá el importe de la venta.',
                ]);
            }

            $total = $this->viene_con_valor($datos, 'monto_total')
                ? round((float) $datos['monto_total'], 2)
                : $this->precio_sugerido($productos, $contado);

            if ($total === null) {
                throw ValidationException::withMessages([
                    'monto_total' => 'Desarrollo a medida y Otro no tienen precio de lista: indicá el importe de la venta.',
                ]);
            }

            $cantidad = isset($datos['cantidad_cuotas']) && (int) $datos['cantidad_cuotas'] > 0
                ? (int) $datos['cantidad_cuotas']
                : $this->cantidad_de_cuotas_por_defecto($productos, $contado);

            $primer_vencimiento = $this->fecha_o_null($datos['primer_vencimiento'] ?? null) ?: $fecha->copy();

            $filas = $this->repartir($total, $cantidad, $primer_vencimiento);
        }

        if ($total <= 0) {
            throw ValidationException::withMessages([
                'monto_total' => 'El total de la venta tiene que ser mayor a cero.',
            ]);
        }

        /* Vendedor: ausente = el por defecto; null explícito = sin vendedor (se elige después). */
        $vendedor_admin_id = array_key_exists('vendedor_admin_id', $datos)
            ? ($datos['vendedor_admin_id'] !== null && $datos['vendedor_admin_id'] !== '' ? (int) $datos['vendedor_admin_id'] : null)
            : $this->vendedor_por_defecto();

        $porcentaje = $this->viene_con_valor($datos, 'comision_porcentaje')
            ? round((float) $datos['comision_porcentaje'], 2)
            : $this->porcentaje_por_defecto($client, $productos);

        $venta = new Venta([
            'client_id'           => $client->id,
            'lead_id'             => isset($datos['lead_id']) ? (int) $datos['lead_id'] : $this->lead_de_origen_id($client),
            'productos'           => $productos,
            'descripcion'         => $this->texto_o_null($datos['descripcion'] ?? null),
            'fecha'               => $fecha->toDateString(),
            'monto_total'         => $total,
            'moneda'              => $moneda,
            'origen'              => in_array($origen, Venta::ORIGENES, true) ? $origen : Venta::ORIGEN_MANUAL,
            'vendedor_admin_id'   => $vendedor_admin_id,
            'comision_porcentaje' => $porcentaje,
            'comision_monto'      => $this->comision_total($total, $porcentaje),
            'observacion'         => $this->texto_o_null($datos['observacion'] ?? null),
            'created_by_admin_id' => $autor ? $autor->id : null,
        ]);

        /* Las cuotas sin guardar, numeradas desde el siguiente número libre (el definitivo lo pone
           `crear()` adentro de la transacción). */
        $numero = ((int) LicenciaCuota::where('client_id', $client->id)->max('numero')) + 1;
        $cuotas = new Collection();

        foreach ($filas as $fila) {
            /** @var Carbon|null $vencimiento */
            $vencimiento = $fila['vencimiento'];

            $cuotas->push(new LicenciaCuota([
                'client_id'    => $client->id,
                'numero'       => $numero++,
                'periodo'      => ($vencimiento ?: $fecha)->format('Y-m'),
                'vencimiento'  => $vencimiento ? $vencimiento->toDateString() : null,
                'monto'        => $fila['monto'],
                'moneda'       => $moneda,
                'estado'       => LicenciaCuota::ESTADO_PENDIENTE,
                'monto_pagado' => null,
                'fecha_pago'   => null,
                'observacion'  => null,
                'importado'    => false,
            ]));
        }

        $venta->setRelation('cuotas', $cuotas);
        $venta->setRelation('comision_pagos', new Collection());
        $venta->setRelation('vendedor', $vendedor_admin_id ? Admin::find($vendedor_admin_id) : null);

        return $venta;
    }

    /**
     * Crea UNA venta a partir de cuotas que ya existen y las engancha: la usan la promoción de un
     * lead (las cuotas recién nacidas del contrato) y `ventas:migrar-licencias` (las cuotas viejas
     * agrupadas por cliente y moneda).
     *
     * - `productos` = los sistemas de `contract_sistemas` del cliente (vacío → solo gestión).
     * - `monto_total` = la suma de las cuotas; `moneda` = la de la primera.
     * - `fecha` = la que se pasa o, si no, el vencimiento (o el mes) más viejo de las cuotas.
     * - Con `$con_comision`: vendedor por defecto, porcentaje por defecto y comisión calculada. Sin
     *   ella (las ventas viejas, anteriores al esquema de comisiones): sin vendedor ni comisión, que
     *   se asignan a mano si hace falta.
     *
     * @param Client      $client
     * @param Collection  $cuotas       LicenciaCuota ya guardadas, del mismo cliente.
     * @param string      $origen       Una de Venta::ORIGENES.
     * @param int|null    $lead_id      Lead de origen, si se conoce.
     * @param bool        $con_comision Asignar vendedor y comisión por defecto.
     * @param Carbon|null $fecha        Fecha de la venta; null = la de la cuota más vieja.
     *
     * @return Venta
     */
    public function crear_desde_cuotas(Client $client, Collection $cuotas, string $origen, ?int $lead_id, bool $con_comision, ?Carbon $fecha = null): Venta
    {
        $productos = $this->productos_del_contrato($client);
        $total = round((float) $cuotas->sum(function (LicenciaCuota $cuota) {
            return (float) $cuota->monto;
        }), 2);

        $porcentaje = $con_comision ? $this->porcentaje_por_defecto($client, $productos) : null;

        return DB::transaction(function () use ($client, $cuotas, $origen, $lead_id, $con_comision, $fecha, $productos, $total, $porcentaje) {
            $venta = Venta::create([
                'client_id'           => $client->id,
                'lead_id'             => $lead_id,
                'productos'           => $productos,
                'descripcion'         => null,
                'fecha'               => ($fecha ?: $this->fecha_mas_vieja($cuotas))->toDateString(),
                'monto_total'         => $total,
                'moneda'              => $this->moneda(optional($cuotas->first())->moneda),
                'origen'              => in_array($origen, Venta::ORIGENES, true) ? $origen : Venta::ORIGEN_CONTRATO,
                'vendedor_admin_id'   => $con_comision ? $this->vendedor_por_defecto() : null,
                'comision_porcentaje' => $porcentaje,
                'comision_monto'      => $porcentaje !== null ? $this->comision_total($total, $porcentaje) : null,
                'observacion'         => null,
                'created_by_admin_id' => null,
            ]);

            LicenciaCuota::whereIn('id', $cuotas->pluck('id')->all())->update(['venta_id' => $venta->id]);

            return $this->recargar($venta);
        });
    }

    /**
     * Edita los datos de la venta (no sus cuotas) y recalcula la comisión. Solo toca las claves
     * presentes en `$datos`.
     *
     * - Cambiar la `moneda` la cambia también en las cuotas de la venta: es la misma venta, y una
     *   cuota en otra moneda partiría el total en dos números que no se suman. Si la venta ya tiene
     *   pagos de comisión, NO se puede cambiar (422 en `moneda`): esos pagos salieron en la moneda
     *   vieja y quedarían comparados contra una comisión en otra.
     * - Asignar un vendedor a una venta sin porcentaje (las viejas, migradas sin comisión) le pone
     *   el porcentaje por defecto si no vino uno.
     * - `comision_porcentaje` null explícito saca la comisión SOLO si la venta queda sin vendedor.
     *   Con vendedor, un null se trata como ausente (se conserva el % o se pone el por defecto);
     *   para una venta con vendedor y sin comisión se manda 0.
     *
     * @param Venta $venta
     * @param array $datos {productos?, descripcion?, fecha?, moneda?, vendedor_admin_id?, comision_porcentaje?, observacion?}
     *
     * @return Venta
     */
    public function editar(Venta $venta, array $datos): Venta
    {
        /* La moneda no cambia si ya se le pagó comisión al vendedor en la moneda actual. */
        if (array_key_exists('moneda', $datos) && $datos['moneda'] !== null && $datos['moneda'] !== ''
            && $this->moneda($datos['moneda']) !== $venta->moneda
            && ComisionPago::where('venta_id', $venta->id)->exists()) {
            throw ValidationException::withMessages([
                'moneda' => 'Esta venta ya tiene pagos de comisión en ' . $venta->moneda . ': no se le puede cambiar la moneda. Eliminá esos pagos antes.',
            ]);
        }

        DB::transaction(function () use ($venta, $datos) {
            if (array_key_exists('productos', $datos) && is_array($datos['productos'])) {
                $productos = $this->normalizar_productos($datos['productos']);
                if (count($productos) > 0) {
                    $venta->productos = $productos;
                }
            }

            if (array_key_exists('descripcion', $datos)) {
                $venta->descripcion = $this->texto_o_null($datos['descripcion']);
            }

            if (array_key_exists('fecha', $datos) && $datos['fecha'] !== null && $datos['fecha'] !== '') {
                $venta->fecha = Carbon::parse($datos['fecha'])->toDateString();
            }

            if (array_key_exists('observacion', $datos)) {
                $venta->observacion = $this->texto_o_null($datos['observacion']);
            }

            if (array_key_exists('moneda', $datos) && $datos['moneda'] !== null && $datos['moneda'] !== '') {
                $moneda = $this->moneda($datos['moneda']);
                if ($moneda !== $venta->moneda) {
                    $venta->moneda = $moneda;
                    LicenciaCuota::where('venta_id', $venta->id)->update(['moneda' => $moneda]);
                }
            }

            if (array_key_exists('vendedor_admin_id', $datos)) {
                $venta->vendedor_admin_id = $datos['vendedor_admin_id'] !== null && $datos['vendedor_admin_id'] !== ''
                    ? (int) $datos['vendedor_admin_id']
                    : null;
            }

            /* El porcentaje (revisión, 28/10/2026):
               - con valor → ese (0 es válido: "sin comisión" se pide con 0, no con null);
               - con vendedor y sin valor (ausente o null): un null explícito con vendedor no tiene
                 sentido —es el formulario mandando el campo vacío—, así que se trata igual que si no
                 viniera: se conserva el que tenía o, si no tenía, arranca con el por defecto;
               - sin vendedor y null explícito → null (venta sin comisión). */
            if ($this->viene_con_valor($datos, 'comision_porcentaje')) {
                $venta->comision_porcentaje = round((float) $datos['comision_porcentaje'], 2);
            } elseif ($venta->vendedor_admin_id !== null) {
                if ($venta->comision_porcentaje === null) {
                    // Se le asignó vendedor a una venta sin comisión: arranca con el porcentaje por defecto.
                    $venta->comision_porcentaje = $this->porcentaje_por_defecto($venta->client, (array) $venta->productos);
                }
            } elseif (array_key_exists('comision_porcentaje', $datos)) {
                $venta->comision_porcentaje = null;
            }

            $venta->comision_monto = $venta->comision_porcentaje !== null
                ? $this->comision_total((float) $venta->monto_total, (float) $venta->comision_porcentaje)
                : null;

            $venta->save();
        });

        return $this->recargar($venta);
    }

    /**
     * Borra la venta con sus cuotas y sus señas. Si ya se le pagó comisión al vendedor por esta venta, NO se
     * borra (422): esos pagos son plata que salió, y borrar la venta los dejaría colgando de nada.
     * Para borrarla hay que eliminar antes los pagos de comisión.
     *
     * @param Venta $venta
     *
     * @return void
     *
     * @throws ValidationException
     */
    public function eliminar(Venta $venta): void
    {
        if (ComisionPago::where('venta_id', $venta->id)->exists()) {
            throw ValidationException::withMessages([
                'venta' => 'Esta venta ya tiene pagos de comisión registrados al vendedor. Eliminá esos pagos antes de borrar la venta.',
            ]);
        }

        DB::transaction(function () use ($venta) {
            LicenciaCuota::where('venta_id', $venta->id)->delete();
            ComisionPago::where('venta_id', $venta->id)->delete();
            // Las señas de la venta (misión senas-en-ventas) se borran con ella: sus pagos eran de
            // sus cuotas, que ya no están, y dejarlas contaría una seña de una venta que no existe.
            Sena::where('venta_id', $venta->id)->delete();
            $venta->delete();
        });
    }

    /**
     * Agrega una cuota a una venta existente (`POST client/{id}/ventas/{ventaId}/cuotas`). Reusa
     * `LicenciaCuotaService::crear()` con `venta_id`, que de paso suma la cuota al total de la
     * venta y recalcula la comisión. La moneda es SIEMPRE la de la venta: si viene otra, se ignora.
     *
     * @param Venta $venta
     * @param array $datos {monto, vencimiento?, periodo?, observacion?, moneda? (se ignora)}
     *
     * @return Venta
     */
    public function agregar_cuota(Venta $venta, array $datos): Venta
    {
        $datos['venta_id'] = $venta->id;
        $datos['moneda'] = $venta->moneda;

        $this->cuotas->crear($venta->client, $datos);

        return $this->recargar($venta);
    }

    /**
     * Vuelve a calcular el total de la venta como la suma de sus cuotas y, con él, la comisión.
     * La llaman `LicenciaCuotaService::crear/editar/eliminar` cada vez que cambia una cuota de una
     * venta (también por las rutas viejas de licencias).
     *
     * @param Venta $venta
     *
     * @return Venta
     */
    public function sincronizar_total(Venta $venta): Venta
    {
        $total = round((float) LicenciaCuota::where('venta_id', $venta->id)->sum('monto'), 2);

        $venta->monto_total = $total;
        $venta->comision_monto = $venta->comision_porcentaje !== null
            ? $this->comision_total($total, (float) $venta->comision_porcentaje)
            : null;
        $venta->save();

        return $venta;
    }

    /**
     * Precio de lista de los productos (política del 26/10/2026):
     *  - con desarrollo u otro → null (importe libre);
     *  - los tres sistemas juntos → 1.500;
     *  - si no, la suma de los precios de cada sistema (gestión 800, e-commerce 500, agentes 500);
     *  - de contado, 10 % de descuento.
     *
     * @param array<int, string> $productos
     * @param bool               $contado
     *
     * @return float|null
     */
    public function precio_sugerido(array $productos, bool $contado): ?float
    {
        $productos = $this->normalizar_productos($productos);

        if (count($productos) === 0
            || in_array(Venta::PRODUCTO_DESARROLLO, $productos, true)
            || in_array(Venta::PRODUCTO_OTRO, $productos, true)) {
            return null;
        }

        if ($this->son_los_tres($productos)) {
            $precio = (float) Venta::PRECIOS['los_tres'];
        } else {
            $precio = 0.0;
            foreach ($productos as $producto) {
                $precio += (float) Venta::PRECIOS[$producto];
            }
        }

        if ($contado) {
            $precio = $precio * (100 - (float) Venta::PRECIOS['contado_descuento']) / 100;
        }

        return round($precio, 2);
    }

    /**
     * Porcentaje de comisión por defecto: 30 si la venta incluye el sistema de gestión y el
     * contrato del cliente —o el del lead del que salió— está en modalidad Suscripción; 15 en
     * cualquier otro caso (decisión 4 de Lucas en la Fase 2). Siempre editable en cada venta.
     *
     * @param Client             $client
     * @param array<int, string> $productos
     *
     * @return float
     */
    public function porcentaje_por_defecto(Client $client, array $productos): float
    {
        if (in_array(Venta::PRODUCTO_GESTION, $productos, true) && $this->es_suscripcion($client)) {
            return (float) Venta::COMISION_PORCENTAJE_SUSCRIPCION;
        }

        return (float) Venta::COMISION_PORCENTAJE_DEFAULT;
    }

    /**
     * ¿El contrato del cliente (o, si el cliente no lo tiene cargado, el del lead del que salió)
     * está en modalidad Suscripción?
     *
     * @param Client $client
     *
     * @return bool
     */
    public function es_suscripcion(Client $client): bool
    {
        if ($client->contract_modalidad === 'suscripcion') {
            return true;
        }

        return Lead::where('promoted_client_id', $client->id)
            ->where('contract_modalidad', 'suscripcion')
            ->exists();
    }

    /**
     * El vendedor por defecto de una venta nueva: el admin marcado como vendedor, si hay
     * EXACTAMENTE uno. Con varios o ninguno, null: se elige a mano.
     *
     * @return int|null
     */
    public function vendedor_por_defecto(): ?int
    {
        $ids = Admin::where('es_vendedor', true)->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * Los admins marcados como vendedor, para el select del formulario.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function vendedores(): array
    {
        return Admin::where('es_vendedor', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Admin $admin) {
                return ['id' => (int) $admin->id, 'name' => (string) $admin->name];
            })
            ->values()
            ->all();
    }

    /**
     * La comisión de una venta, liberada a medida que paga el cliente.
     *
     *  - `total`: la comisión entera (`comision_monto`).
     *  - `liberada`: `total × min(1, cobrado / monto_total)`, con `cobrado` según
     *    `cobrado_para_comision()`: una cuota `pagada` cuenta su monto completo, y lo cobrado de
     *    cada cuota se topa en su monto.
     *  - `pagada`: lo que ya se le pagó por esta venta al vendedor ACTUAL (los pagos a un vendedor
     *    anterior no cuentan).
     *  - `a_pagar`: lo liberado que todavía no se le pagó (nunca negativo).
     *  - `por_liberar`: lo que se libera cuando el cliente termine de pagar.
     *
     * Usa las relaciones `cuotas`, `comision_pagos` y `vendedor` si están cargadas (la tabla las
     * trae de una), y las consulta si no.
     *
     * @param Venta $venta
     *
     * @return array{vendedor_admin_id: int|null, vendedor_nombre: string|null, porcentaje: float|null, total: float, liberada: float, pagada: float, a_pagar: float, por_liberar: float, moneda: string}
     */
    public function comision_de(Venta $venta): array
    {
        $total = $venta->comision_monto !== null ? (float) $venta->comision_monto : 0.0;
        $monto_total = (float) $venta->monto_total;
        $cobrado = $this->cobrado_para_comision($venta);

        $fraccion = $monto_total > 0 ? min(1.0, $cobrado / $monto_total) : 0.0;
        $liberada = round($total * $fraccion, 2);

        /* Solo los pagos al vendedor ACTUAL de la venta (revisión, 28/10/2026). Si la venta cambió de
           vendedor, lo que se le pagó al anterior no descuenta de lo que le toca al nuevo: el nuevo
           arranca sin cobrado. Esos pagos siguen existiendo (y frenan la baja de la venta). */
        $vendedor_actual = $venta->vendedor_admin_id !== null ? (int) $venta->vendedor_admin_id : null;
        $pagada = round((float) $venta->comision_pagos->filter(function (ComisionPago $pago) use ($vendedor_actual) {
            return $vendedor_actual !== null && (int) $pago->vendedor_admin_id === $vendedor_actual;
        })->sum(function (ComisionPago $pago) {
            return (float) $pago->monto;
        }), 2);

        $vendedor = $venta->vendedor;

        return [
            'vendedor_admin_id' => $venta->vendedor_admin_id !== null ? (int) $venta->vendedor_admin_id : null,
            'vendedor_nombre'   => $vendedor ? (string) $vendedor->name : null,
            'porcentaje'        => $venta->comision_porcentaje !== null ? (float) $venta->comision_porcentaje : null,
            'total'             => round($total, 2),
            'liberada'          => $liberada,
            'pagada'            => $pagada,
            'a_pagar'           => max(0.0, round($liberada - $pagada, 2)),
            'por_liberar'       => round($total - $liberada, 2),
            'moneda'            => (string) $venta->moneda,
        ];
    }

    /**
     * Una venta tal como viaja al front: sus datos, las etiquetas de los productos, sus cuotas
     * (con `cuota_para_json`), el resumen de lo que debe el cliente y la comisión.
     *
     * Funciona también sobre una venta SIN guardar (la del dry_run de Claude): `id` va null y las
     * cuotas salen con `id` null.
     *
     * 🔴 COBRO DUDOSO (misión cobro-dudoso-y-pagos-ventas, 30/10/2026). `resumen.pendiente` sigue
     * siendo la deuda pendiente TOTAL de la venta, sin cambiar de significado. Lo que se suma es su
     * partición según la marca del CLIENTE (`clients.cobro_dudoso`):
     *  - `resumen.en_espera`: el pendiente si el cliente es de dudoso cobro; si no, 0.
     *  - `resumen.por_cobrar`: el resto (`pendiente - en_espera`), la plata que de verdad hay que cobrar.
     *  - `cobro_dudoso` (en la raíz de la venta): la marca del cliente.
     * La comisión del vendedor NO mira la marca: se libera por lo que el cliente paga de verdad.
     *
     * 🔴 SEÑAS (misión senas-en-ventas, 30/10/2026). Se suman dos claves, sin cambiar ninguna otra:
     *  - `senas`: la lista de señas de la venta (`SenaService::sena_para_json()`: id, monto, moneda,
     *    fecha, nota, origen…). Una venta sin señas o sin guardar trae `[]`.
     *  - `resumen.sena_total`: lo que suman esas señas. La seña ya está DENTRO de `resumen.cobrado`
     *    (se aplicó a las cuotas como un pago más): `sena_total` solo dice cuánto de lo cobrado fue seña.
     *
     * @param Venta $venta
     *
     * @return array<string, mixed>
     */
    public function venta_para_json(Venta $venta): array
    {
        $productos = is_array($venta->productos) ? array_values($venta->productos) : [];

        $etiquetas = [];
        foreach ($productos as $producto) {
            $etiquetas[] = Venta::PRODUCTOS[$producto] ?? $producto;
        }

        $cuotas = $venta->cuotas;
        $cobrado = $this->cobrado_de($venta);
        $pendiente = round((float) $cuotas->sum(function (LicenciaCuota $cuota) {
            return $cuota->monto_pendiente();
        }), 2);

        /* La marca de dudoso cobro es del cliente: con ella, todo el pendiente de la venta está «en
           espera» (stand-by) y nada «por cobrar». Sin ella, al revés. */
        $cobro_dudoso = $this->cobro_dudoso_de($venta);
        $en_espera = $cobro_dudoso ? $pendiente : 0.0;

        return [
            'id'                  => $venta->id,
            'client_id'           => (int) $venta->client_id,
            'lead_id'             => $venta->lead_id !== null ? (int) $venta->lead_id : null,
            'productos'           => $productos,
            'productos_etiquetas' => $etiquetas,
            'descripcion'         => $venta->descripcion,
            'fecha'               => $venta->fecha ? Carbon::parse($venta->fecha)->toDateString() : null,
            'monto_total'         => (float) $venta->monto_total,
            'moneda'              => (string) $venta->moneda,
            'origen'              => (string) $venta->origen,
            'vendedor_admin_id'   => $venta->vendedor_admin_id !== null ? (int) $venta->vendedor_admin_id : null,
            'comision_porcentaje' => $venta->comision_porcentaje !== null ? (float) $venta->comision_porcentaje : null,
            'comision_monto'      => $venta->comision_monto !== null ? (float) $venta->comision_monto : null,
            'observacion'         => $venta->observacion,
            'created_by_admin_id' => $venta->created_by_admin_id !== null ? (int) $venta->created_by_admin_id : null,
            'created_at'          => $venta->created_at ? $venta->created_at->toDateTimeString() : null,
            'cobro_dudoso'        => $cobro_dudoso,
            'cuotas'              => $cuotas->map(function (LicenciaCuota $cuota) {
                return $this->cuotas->cuota_para_json($cuota);
            })->values()->all(),
            'senas'               => $this->senas()->senas_de_la_venta_para_json($venta),
            'resumen'             => [
                'total'      => (float) $venta->monto_total,
                'cobrado'    => $cobrado,
                'pendiente'  => $pendiente,
                'en_espera'  => $en_espera,
                'por_cobrar' => round($pendiente - $en_espera, 2),
                'sena_total' => $this->senas()->total_de_la_venta($venta),
            ],
            'comision'            => $this->comision_de($venta),
        ];
    }

    /**
     * Todo lo que necesita la pestaña Ventas del cliente (`GET client/{id}/ventas` y
     * `GET claude/clients/{id}/ventas`, que devuelven exactamente esto).
     *
     * `porcentaje_por_defecto` es el porcentaje base (15). Si el cliente está en Suscripción, una
     * venta que incluya gestión lleva el 30: para que el front no tenga que adivinarlo van, como
     * claves agregadas, `es_suscripcion` y `porcentaje_con_gestion` (el que corresponde si se tilda
     * gestión).
     *
     * 🔴 Cobro dudoso (misión cobro-dudoso-y-pagos-ventas, 30/10/2026): en la raíz salen además la
     * marca del cliente (`cobro_dudoso`), desde cuándo (`cobro_dudoso_desde`, Y-m-d o null) y por
     * qué (`cobro_dudoso_motivo`). Son claves nuevas: quien no las conoce las ignora.
     *
     * @param Client $client
     *
     * @return array<string, mixed>
     */
    public function lista_del_cliente(Client $client): array
    {
        $ventas = Venta::where('client_id', $client->id)
            ->with(['cuotas', 'comision_pagos', 'vendedor', 'senas'])
            ->orderBy('fecha')->orderBy('id')
            ->get();

        /* Todas las ventas son de este cliente: se le pasa el objeto que ya se tiene para que
           `venta_para_json()` no vaya a buscarlo a la base una vez por venta. */
        foreach ($ventas as $venta) {
            $venta->setRelation('client', $client);
        }

        $sueltas = LicenciaCuota::where('client_id', $client->id)
            ->whereNull('venta_id')
            ->orderBy('numero')->orderBy('id')
            ->get();

        return [
            'ventas'                 => $ventas->map(function (Venta $venta) {
                return $this->venta_para_json($venta);
            })->values()->all(),
            'cuotas_sin_venta'       => $sueltas->map(function (LicenciaCuota $cuota) {
                return $this->cuotas->cuota_para_json($cuota);
            })->values()->all(),
            'vendedores'             => $this->vendedores(),
            'productos'              => Venta::PRODUCTOS,
            'precios'                => Venta::PRECIOS,
            'porcentaje_por_defecto' => $this->porcentaje_por_defecto($client, []),
            'porcentaje_con_gestion' => $this->porcentaje_por_defecto($client, [Venta::PRODUCTO_GESTION]),
            'es_suscripcion'         => $this->es_suscripcion($client),
            'vendedor_por_defecto'   => $this->vendedor_por_defecto(),
            'cobro_dudoso'           => (bool) $client->cobro_dudoso,
            'cobro_dudoso_desde'     => $client->cobro_dudoso_desde ? Carbon::parse($client->cobro_dudoso_desde)->toDateString() : null,
            'cobro_dudoso_motivo'    => $client->cobro_dudoso_motivo !== null ? (string) $client->cobro_dudoso_motivo : null,
        ];
    }

    /**
     * Enciende o apaga la marca de «cliente de dudoso cobro» (misión cobro-dudoso-y-pagos-ventas,
     * 30/10/2026). La usan el panel (`PUT client/{id}/cobro-dudoso`) y Claude
     * (`POST claude/clients/{id}/cobro-dudoso`): una sola definición de qué hace la marca.
     *
     *  - Encender: `cobro_dudoso = true`. `cobro_dudoso_desde` se pone en hoy SOLO si la marca estaba
     *    apagada: encender dos veces no corre la fecha. El motivo se guarda si viene (texto no vacío,
     *    recortado); si no viene, se conserva el que había.
     *  - Apagar: las tres columnas vuelven a `false / null / null`, venga o no un motivo.
     *
     * No toca ventas, cuotas ni comisiones: la marca solo cambia en qué balde se cuenta la deuda
     * pendiente (`en_espera` en vez de `por_cobrar`).
     *
     * @param Client      $client
     * @param bool        $encendido Si la marca queda encendida (true) o apagada (false).
     * @param string|null $motivo    Nota libre (hasta 255); null o vacío = no cambiar el motivo.
     *
     * @return Client El mismo cliente, ya guardado.
     */
    public function cambiar_cobro_dudoso(Client $client, bool $encendido, ?string $motivo = null): Client
    {
        if (! $encendido) {
            $client->cobro_dudoso = false;
            $client->cobro_dudoso_desde = null;
            $client->cobro_dudoso_motivo = null;
            $client->save();

            return $client;
        }

        $estaba_encendido = (bool) $client->cobro_dudoso;

        $client->cobro_dudoso = true;

        if (! $estaba_encendido || $client->cobro_dudoso_desde === null) {
            $client->cobro_dudoso_desde = AppTime::now()->toDateString();
        }

        $motivo_limpio = $this->texto_o_null($motivo);
        if ($motivo_limpio !== null) {
            $client->cobro_dudoso_motivo = $motivo_limpio;
        }

        $client->save();

        return $client;
    }

    /**
     * Resumen de un conjunto de ventas por moneda: lo que deben los clientes (todas las ventas) y lo
     * que hay que pagarle a los vendedores (solo las ventas CON vendedor). Lo usa la tabla del
     * módulo (por cliente) y sus cifras de arriba.
     *
     * 🔴 Cobro dudoso (misión cobro-dudoso-y-pagos-ventas, 30/10/2026): `pendiente` sigue siendo la
     * deuda pendiente total y se suman `en_espera` (la de clientes de dudoso cobro, en stand-by) y
     * `por_cobrar` (la que de verdad hay que cobrar); `pendiente = en_espera + por_cobrar`. La
     * comisión no mira la marca.
     *
     * @param iterable<Venta> $ventas Con `cuotas` y `comision_pagos` cargadas.
     *
     * @return array<string, array{total: float, cobrado: float, pendiente: float, en_espera: float, por_cobrar: float, comision_total: float, comision_liberada: float, comision_pagada: float, comision_a_pagar: float, comision_por_liberar: float}>
     */
    public function resumen_por_moneda($ventas): array
    {
        $resumen = [];

        foreach ($ventas as $venta) {
            $json = $this->venta_para_json($venta);
            $moneda = $json['moneda'];

            if (! isset($resumen[$moneda])) {
                $resumen[$moneda] = [
                    'total'                => 0.0,
                    'cobrado'              => 0.0,
                    'pendiente'            => 0.0,
                    'en_espera'            => 0.0,
                    'por_cobrar'           => 0.0,
                    'comision_total'       => 0.0,
                    'comision_liberada'    => 0.0,
                    'comision_pagada'      => 0.0,
                    'comision_a_pagar'     => 0.0,
                    'comision_por_liberar' => 0.0,
                ];
            }

            $resumen[$moneda]['total'] = round($resumen[$moneda]['total'] + $json['resumen']['total'], 2);
            $resumen[$moneda]['cobrado'] = round($resumen[$moneda]['cobrado'] + $json['resumen']['cobrado'], 2);
            $resumen[$moneda]['pendiente'] = round($resumen[$moneda]['pendiente'] + $json['resumen']['pendiente'], 2);
            $resumen[$moneda]['en_espera'] = round($resumen[$moneda]['en_espera'] + $json['resumen']['en_espera'], 2);
            $resumen[$moneda]['por_cobrar'] = round($resumen[$moneda]['por_cobrar'] + $json['resumen']['por_cobrar'], 2);

            /* La comisión cuenta solo si la venta tiene vendedor: una venta sin vendedor puede tener
               porcentaje (y comision_monto), pero no hay a quién pagarle, y sumarla inflaría "lo que
               hay que pagarle a los vendedores" con plata que no le corresponde a nadie. */
            if ($json['vendedor_admin_id'] === null) {
                continue;
            }

            $resumen[$moneda]['comision_total'] = round($resumen[$moneda]['comision_total'] + $json['comision']['total'], 2);
            $resumen[$moneda]['comision_liberada'] = round($resumen[$moneda]['comision_liberada'] + $json['comision']['liberada'], 2);
            $resumen[$moneda]['comision_pagada'] = round($resumen[$moneda]['comision_pagada'] + $json['comision']['pagada'], 2);
            $resumen[$moneda]['comision_a_pagar'] = round($resumen[$moneda]['comision_a_pagar'] + $json['comision']['a_pagar'], 2);
            $resumen[$moneda]['comision_por_liberar'] = round($resumen[$moneda]['comision_por_liberar'] + $json['comision']['por_liberar'], 2);
        }

        return $resumen;
    }

    /**
     * Los productos que vienen del contrato del cliente (`contract_sistemas`), filtrados a los tres
     * sistemas. Un contrato sin sistemas (los históricos) es una venta del sistema de gestión.
     *
     * @param Client $client
     *
     * @return array<int, string>
     */
    public function productos_del_contrato(Client $client): array
    {
        $sistemas = is_array($client->contract_sistemas) ? $client->contract_sistemas : [];
        $productos = array_values(array_intersect(Venta::SISTEMAS, $sistemas));

        return count($productos) > 0 ? $productos : [Venta::PRODUCTO_GESTION];
    }

    /**
     * El id del lead del que salió el cliente (`leads.promoted_client_id`), si hay uno.
     *
     * @param Client $client
     *
     * @return int|null
     */
    public function lead_de_origen_id(Client $client): ?int
    {
        $id = Lead::where('promoted_client_id', $client->id)->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * ¿El cliente de la venta está marcado como de dudoso cobro? Usa la relación `client` si ya está
     * cargada (la tabla de Cobranzas la trae de una) y la consulta si no. Una venta sin cliente
     * (no debería pasar) cuenta como NO dudosa: ante la duda, la deuda sigue siendo «por cobrar».
     *
     * @param Venta $venta
     *
     * @return bool
     */
    protected function cobro_dudoso_de(Venta $venta): bool
    {
        $client = $venta->client;

        return $client !== null && (bool) $client->cobro_dudoso;
    }

    /**
     * Lo cobrado de una venta: la suma de `monto_cobrado()` de sus cuotas.
     *
     * @param Venta $venta
     *
     * @return float
     */
    protected function cobrado_de(Venta $venta): float
    {
        return round((float) $venta->cuotas->sum(function (LicenciaCuota $cuota) {
            return $cuota->monto_cobrado();
        }), 2);
    }

    /**
     * Lo cobrado de una venta A LOS EFECTOS DE LIBERAR LA COMISIÓN (revisión de la misión,
     * 28/10/2026). No es `cobrado_de()`, y la diferencia es a propósito:
     *
     *  - Una cuota `pagada` cuenta su `monto` completo, aunque `monto_pagado` diga menos: si Lucas la
     *    dio por completa ("con esto la cuota está", un descuento o una diferencia de cambio), para
     *    el vendedor la cuota está cobrada.
     *  - Lo cobrado de cada cuota se topa en su `monto`. El caso real es Únicas: pagos en PESOS
     *    cargados sobre una cuota en DÓLARES (`monto_pagado` = 436.000 sobre USD 600). Sumados
     *    tal cual, una sola cuota liberaría la comisión de la venta entera.
     *
     * El resumen de la venta (`resumen.cobrado`) sigue usando `cobrado_de()`, el mismo criterio que
     * el resumen de cuotas de siempre: lo que se muestra como cobrado no cambia.
     *
     * @param Venta $venta
     *
     * @return float
     */
    protected function cobrado_para_comision(Venta $venta): float
    {
        return round((float) $venta->cuotas->sum(function (LicenciaCuota $cuota) {
            $monto = (float) $cuota->monto;

            if ($cuota->estado === LicenciaCuota::ESTADO_PAGADA) {
                return $monto;
            }

            return min($monto, max(0.0, (float) ($cuota->monto_pagado ?? 0)));
        }), 2);
    }

    /**
     * Reparte un total en `$cantidad` cuotas iguales y mensuales desde `$primer_vencimiento`. Las
     * primeras se truncan al centavo y la última absorbe la diferencia, así la suma da exacto.
     *
     * @param float  $total
     * @param int    $cantidad
     * @param Carbon $primer_vencimiento
     *
     * @return array<int, array{monto: float, vencimiento: Carbon}>
     */
    protected function repartir(float $total, int $cantidad, Carbon $primer_vencimiento): array
    {
        $cantidad = max(1, $cantidad);
        $base = floor($total * 100 / $cantidad) / 100;

        $filas = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $monto = $i === $cantidad - 1
                ? round($total - $base * ($cantidad - 1), 2)
                : $base;

            $filas[] = [
                'monto'       => round($monto, 2),
                'vencimiento' => $primer_vencimiento->copy()->addMonthsNoOverflow($i),
            ];
        }

        return $filas;
    }

    /**
     * Cuántas cuotas por defecto: 3 si son los tres sistemas y no es de contado; 1 si no.
     *
     * @param array<int, string> $productos
     * @param bool               $contado
     *
     * @return int
     */
    protected function cantidad_de_cuotas_por_defecto(array $productos, bool $contado): int
    {
        return (! $contado && $this->son_los_tres($productos)) ? Venta::CUOTAS_LOS_TRES : 1;
    }

    /**
     * ¿Los productos son exactamente los tres sistemas (gestión, e-commerce y agentes)?
     *
     * @param array<int, string> $productos Ya normalizados.
     *
     * @return bool
     */
    protected function son_los_tres(array $productos): bool
    {
        return count($productos) === count(Venta::SISTEMAS)
            && count(array_diff(Venta::SISTEMAS, $productos)) === 0;
    }

    /**
     * Comisión total: monto × porcentaje / 100, redondeada al centavo.
     *
     * @param float      $monto
     * @param float|null $porcentaje
     *
     * @return float|null
     */
    protected function comision_total(float $monto, ?float $porcentaje): ?float
    {
        if ($porcentaje === null) {
            return null;
        }

        return round($monto * $porcentaje / 100, 2);
    }

    /**
     * Productos sin repetidos, solo claves conocidas y en el orden de Venta::PRODUCTOS (así dos
     * ventas con los mismos productos se ven y se comparan igual).
     *
     * @param mixed $productos
     *
     * @return array<int, string>
     */
    protected function normalizar_productos($productos): array
    {
        if (! is_array($productos)) {
            return [];
        }

        $productos = array_map('strval', $productos);

        return array_values(array_filter(array_keys(Venta::PRODUCTOS), function ($clave) use ($productos) {
            return in_array($clave, $productos, true);
        }));
    }

    /**
     * La fecha más vieja de un grupo de cuotas: el vencimiento, o si no tiene, el primer día de su
     * mes. Sin nada legible, hoy.
     *
     * @param Collection $cuotas
     *
     * @return Carbon
     */
    protected function fecha_mas_vieja(Collection $cuotas): Carbon
    {
        $minima = null;

        foreach ($cuotas as $cuota) {
            if ($cuota->vencimiento) {
                $fecha = Carbon::parse($cuota->vencimiento);
            } elseif (CobranzasMensualidadService::es_periodo_valido($cuota->periodo)) {
                $fecha = Carbon::parse($cuota->periodo . '-01');
            } else {
                continue;
            }

            if ($minima === null || $fecha->lt($minima)) {
                $minima = $fecha;
            }
        }

        return $minima ?: AppTime::now()->copy()->startOfDay();
    }

    /**
     * Recarga la venta con lo que necesita `venta_para_json()`.
     *
     * @param Venta $venta
     *
     * @return Venta
     */
    protected function recargar(Venta $venta): Venta
    {
        return $venta->fresh(['cuotas', 'comision_pagos', 'vendedor', 'senas']);
    }

    /**
     * El servicio de señas, resuelto a demanda (misión senas-en-ventas, 30/10/2026). No se inyecta
     * por constructor para no cambiar la firma de `VentaService`, que se arma a mano en varios
     * lugares y en las pruebas.
     *
     * @return SenaService
     */
    protected function senas(): SenaService
    {
        return app(SenaService::class);
    }

    /**
     * ¿La clave vino con un valor (ni ausente, ni null, ni vacío)?
     *
     * @param array  $datos
     * @param string $clave
     *
     * @return bool
     */
    protected function viene_con_valor(array $datos, string $clave): bool
    {
        return array_key_exists($clave, $datos) && $datos[$clave] !== null && $datos[$clave] !== '';
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

        if ($valor instanceof Carbon) {
            return $valor->copy();
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

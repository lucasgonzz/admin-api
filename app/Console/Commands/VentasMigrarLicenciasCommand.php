<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Lead;
use App\Models\LicenciaCuota;
use App\Models\Venta;
use App\Services\VentaService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pasa las "licencias" a ventas (misión ventas-y-comisiones, 28/10/2026). Corre UNA vez en el
 * despliegue (`pendientes.json` de /deploy-admin: primero `--simular`, se mira, después real).
 *
 * Tres pasos, en orden:
 *
 *  1. **El vendedor.** Si no hay ningún admin marcado `es_vendedor`, se marcan los que tienen
 *     `is_closer` (Tomás es el closer). Si ya hay alguno marcado, no se toca nada: alguien ya lo
 *     decidió a mano.
 *  2. **Agrupar las cuotas sueltas en ventas.** Por cada cliente con cuotas sin `venta_id`, una
 *     venta por moneda: `origen = planilla` si todas sus cuotas vinieron del importador de la
 *     planilla, `contrato` si no; productos de `contract_sistemas` (vacío → gestión); total = la
 *     suma; fecha = el vencimiento (o el mes) más viejo. Las cuotas quedan enganchadas. Nacen SIN
 *     vendedor ni comisión.
 *  3. **La comisión de las ventas nuevas del esquema.** A las ventas de clientes promovidos desde un
 *     lead desde `--desde` (default 13/10/2026, el día que arrancó el esquema de comisiones) que
 *     todavía no tienen vendedor ni porcentaje, y que nacieron del contrato o de la planilla: el
 *     vendedor por defecto, el porcentaje por defecto y la comisión. Si no hay un vendedor único,
 *     este paso no asigna nada y lo dice (se asigna a mano desde la pestaña Ventas).
 *
 * 🔴 LA FECHA DE PROMOCIÓN. `leads` NO tiene una columna con la fecha en que se promovió (se buscó
 * `promoted_at` y equivalentes: no existen; la promoción solo escribe `promoted_client_id`). Se usa
 * `clients.created_at`: el cliente nace en la promoción (`RunUserSetupService::
 * ensure_production_client`), así que su fecha de alta ES la fecha de promoción. Y se exige que el
 * cliente tenga un lead de origen (`leads.promoted_client_id`), como pide el plan.
 *
 * Idempotente: la segunda corrida no encuentra cuotas sueltas ni ventas sin vendedor que cumplan,
 * y el paso 1 no hace nada si ya hay un vendedor. `--simular` hace todo en una transacción que se
 * revierte al final y muestra el mismo resumen.
 */
class VentasMigrarLicenciasCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ventas:migrar-licencias
        {--simular : Recorre y muestra el resumen sin escribir nada}
        {--desde=2026-10-13 : Desde qué fecha de promoción del lead se liquida comisión (AAAA-MM-DD)}';

    /**
     * @var string
     */
    protected $description = 'Agrupa las cuotas de licencia sueltas en ventas y liquida la comisión de las ventas de clientes promovidos desde --desde. Idempotente; --simular no escribe.';

    /**
     * @param VentaService $ventas
     *
     * @return int
     */
    public function handle(VentaService $ventas): int
    {
        $simular = (bool) $this->option('simular');

        try {
            $desde = Carbon::parse((string) $this->option('desde'))->startOfDay();
        } catch (\Throwable $error) {
            $this->error('--desde no es una fecha válida (AAAA-MM-DD): ' . $this->option('desde'));

            return 1;
        }

        $conteo = [
            'vendedores_marcados' => 0,
            'ventas_creadas'      => 0,
            'cuotas_enlazadas'    => 0,
            'comisiones'          => 0,
        ];
        $detalle_ventas = [];
        $detalle_comisiones = [];
        $aviso_vendedor = null;

        DB::beginTransaction();

        try {
            /* 1. El vendedor: si no hay ninguno, los closers. */
            if (! Admin::where('es_vendedor', true)->exists()) {
                $conteo['vendedores_marcados'] = Admin::where('is_closer', true)->update(['es_vendedor' => true]);
            }

            /* 2. Las cuotas sueltas, agrupadas por cliente y moneda. */
            $client_ids = LicenciaCuota::whereNull('venta_id')->distinct()->orderBy('client_id')->pluck('client_id');

            foreach ($client_ids as $client_id) {
                $client = Client::find($client_id);
                if ($client === null) {
                    // Cuotas de un cliente borrado: no hay a quién venderle; quedan como están.
                    continue;
                }

                $por_moneda = LicenciaCuota::where('client_id', $client->id)
                    ->whereNull('venta_id')
                    ->orderBy('numero')->orderBy('id')
                    ->get()
                    ->groupBy('moneda');

                foreach ($por_moneda as $moneda => $cuotas) {
                    $todas_importadas = $cuotas->every(function (LicenciaCuota $cuota) {
                        return (bool) $cuota->importado;
                    });

                    $venta = $ventas->crear_desde_cuotas(
                        $client,
                        $cuotas,
                        $todas_importadas ? Venta::ORIGEN_PLANILLA : Venta::ORIGEN_CONTRATO,
                        $ventas->lead_de_origen_id($client),
                        false
                    );

                    $conteo['ventas_creadas']++;
                    $conteo['cuotas_enlazadas'] += $cuotas->count();

                    $detalle_ventas[] = [
                        $client->id,
                        $client->resolve_display_name(),
                        $venta->origen,
                        implode(', ', (array) $venta->productos),
                        $venta->moneda . ' ' . number_format((float) $venta->monto_total, 2, ',', '.'),
                        $cuotas->count(),
                    ];
                }
            }

            /* 3. La comisión de las ventas de clientes promovidos desde `--desde`. */
            $vendedor_id = $ventas->vendedor_por_defecto();

            if ($vendedor_id === null) {
                $aviso_vendedor = 'No hay un vendedor único (admins.es_vendedor): no se asignó comisión a ninguna venta. Marcá un vendedor y volvé a correr el comando, o asignalo a mano en la pestaña Ventas.';
            } else {
                /** Clientes que nacieron de un lead en la promoción, desde la fecha pedida. */
                $promovidos = Client::query()
                    ->where('created_at', '>=', $desde->toDateTimeString())
                    ->whereIn('id', Lead::whereNotNull('promoted_client_id')->select('promoted_client_id'))
                    ->pluck('id');

                $candidatas = Venta::query()
                    ->whereIn('client_id', $promovidos)
                    ->whereNull('vendedor_admin_id')
                    ->whereNull('comision_porcentaje')
                    ->whereIn('origen', [Venta::ORIGEN_CONTRATO, Venta::ORIGEN_PLANILLA])
                    ->with('client')
                    ->orderBy('id')
                    ->get();

                foreach ($candidatas as $venta) {
                    $venta = $ventas->editar($venta, ['vendedor_admin_id' => $vendedor_id]);

                    $conteo['comisiones']++;

                    $detalle_comisiones[] = [
                        $venta->id,
                        $venta->client_id,
                        $venta->client ? $venta->client->resolve_display_name() : '',
                        number_format((float) $venta->comision_porcentaje, 2, ',', '.') . ' %',
                        $venta->moneda . ' ' . number_format((float) $venta->comision_monto, 2, ',', '.'),
                    ];
                }
            }

            if ($simular) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $error) {
            DB::rollBack();

            $this->error('La migración falló y no se escribió nada: ' . $error->getMessage());

            return 1;
        }

        $this->info($simular ? 'Resumen de la SIMULACIÓN (no se escribió nada):' : 'Resumen de la migración de licencias a ventas:');

        $this->table(['Qué', 'Cantidad'], [
            ['Admins marcados como vendedor (eran closers)', $conteo['vendedores_marcados']],
            ['Ventas creadas', $conteo['ventas_creadas']],
            ['Cuotas enlazadas', $conteo['cuotas_enlazadas']],
            ['Comisiones generadas (promovidos desde ' . $desde->toDateString() . ')', $conteo['comisiones']],
        ]);

        if (count($detalle_ventas) > 0) {
            $this->table(['Cliente', 'Nombre', 'Origen', 'Productos', 'Total', 'Cuotas'], $detalle_ventas);
        }

        if (count($detalle_comisiones) > 0) {
            $this->table(['Venta', 'Cliente', 'Nombre', 'Porcentaje', 'Comisión'], $detalle_comisiones);
        }

        if ($aviso_vendedor !== null) {
            $this->warn($aviso_vendedor);
        }

        return 0;
    }
}

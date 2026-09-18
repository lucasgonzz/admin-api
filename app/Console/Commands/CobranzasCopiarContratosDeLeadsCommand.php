<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Lead;
use App\Services\ClientContratoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill del contrato: copia al Client el contrato del Lead del que se promovió (misión
 * modulo-cobranzas, 18/9/2026).
 *
 * `RunUserSetupService::ensure_production_client` lo hace de acá en adelante, al crear el
 * cliente. Este comando cubre para atrás a los clientes ya promovidos, que hoy tienen el contrato
 * solo en su lead. Para cada Lead con `promoted_client_id`:
 *
 *   - el cliente tiene que existir y NO tener `contract_copiado_desde_lead_at` (si lo tiene, ya se
 *     copió, o alguien lo editó a mano en el cliente, y no se pisa);
 *   - el lead tiene que tener al menos un `contract_*` cargado (si no, no hay nada que copiar);
 *   - se copia el contrato, y `mensualidad_inicio` se fija SOLO si está en null y el contrato
 *     trae fecha de primer pago mensual.
 *
 * 🔴 A diferencia de la promoción de un lead nuevo, acá NO se generan cuotas de licencia ni se
 * inventa un mes de inicio: son clientes que ya vienen operando, su licencia puede estar cobrada
 * hace meses sin figurar en la planilla, y una cuota "pendiente" nacida del contrato (o un inicio
 * en el mes corriente) sería deuda fantasma en el módulo. Eso se hace a mano, cliente por cliente,
 * desde las pestañas Licencias ("Generar cuotas desde el contrato") y Mensualidad.
 *
 * Idempotente por construcción: la segunda corrida no encuentra clientes sin fecha de copia. Con
 * `--simular` recorre y cuenta sin escribir (transacción revertida al final).
 *
 * Corre en producción por el deploy (`pendientes.json` de /deploy-admin), DESPUÉS de
 * `cobranzas:importar-planilla` (el orden ya no cambia el resultado, pero se mantiene por si
 * alguna vez vuelve a generar cuotas).
 */
class CobranzasCopiarContratosDeLeadsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cobranzas:copiar-contratos-de-leads
        {--simular : Recorre y muestra el resumen sin escribir nada}';

    /**
     * @var string
     */
    protected $description = 'Copia el contrato del lead a cada cliente promovido que todavía no lo tenga (sin generar cuotas ni inventar el inicio de la mensualidad). Idempotente; --simular no escribe.';

    /**
     * @param ClientContratoService $contrato_service
     *
     * @return int
     */
    public function handle(ClientContratoService $contrato_service): int
    {
        $simular = (bool) $this->option('simular');

        $conteo = [
            'leads_promovidos'      => 0,
            'sin_cliente'           => 0,
            'ya_copiados'           => 0,
            'lead_sin_contrato'     => 0,
            'copiados'              => 0,
            'cuotas_creadas'        => 0,
            'inicio_fijado'         => 0,
        ];
        $detalle = [];

        DB::beginTransaction();

        try {
            Lead::whereNotNull('promoted_client_id')
                ->orderBy('id')
                ->chunkById(100, function ($leads) use (&$conteo, &$detalle, $contrato_service) {
                    foreach ($leads as $lead) {
                        $conteo['leads_promovidos']++;

                        $client = Client::find($lead->promoted_client_id);
                        if ($client === null) {
                            $conteo['sin_cliente']++;
                            continue;
                        }

                        if ($client->contract_copiado_desde_lead_at !== null) {
                            $conteo['ya_copiados']++;
                            continue;
                        }

                        if (! $contrato_service->lead_tiene_contrato($lead)) {
                            $conteo['lead_sin_contrato']++;
                            continue;
                        }

                        if (! $contrato_service->copiar_desde_lead($lead, $client)) {
                            continue;
                        }
                        $conteo['copiados']++;

                        /* 🔴 A diferencia de la promoción de un lead nuevo, el backfill NO genera cuotas ni
                         * fija el mes de inicio. Estos clientes ya vienen operando: su licencia puede estar
                         * cobrada hace meses sin figurar en la planilla, y una cuota "pendiente" nacida del
                         * contrato sería deuda fantasma en el módulo. Lo mismo el inicio: sin fecha de
                         * primer pago mensual en el contrato el default es el mes corriente, y eso pintaría
                         * de rojo a un cliente que quizás no se cobra. Las cuotas se generan a mano desde la
                         * pestaña Licencias ("Generar cuotas desde el contrato") y el inicio se carga en la
                         * pestaña Mensualidad, cliente por cliente y mirando. */
                        $cuotas = 0;

                        $inicio = '';
                        $primer_pago_mensual = $lead->contract_fecha_primer_pago_mensual;
                        if ($client->mensualidad_inicio === null && ! empty($primer_pago_mensual)) {
                            // Solo si el contrato lo dice explícitamente: nunca el mes corriente por default.
                            $client->mensualidad_inicio = $contrato_service->inicio_de_mensualidad_desde_contrato($lead);
                            $client->save();
                            $conteo['inicio_fijado']++;
                            $inicio = $client->mensualidad_inicio->format('Y-m');
                        }

                        $detalle[] = [
                            $lead->id,
                            $client->id,
                            $client->resolve_display_name(),
                            $cuotas,
                            $inicio !== '' ? $inicio : ($client->mensualidad_inicio !== null ? '(ya tenía)' : '(sin fecha en el contrato)'),
                        ];
                    }
                });

            if ($simular) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $error) {
            DB::rollBack();

            $this->error('El backfill falló y no se escribió nada: ' . $error->getMessage());

            return 1;
        }

        $this->info($simular ? 'Resumen de la SIMULACIÓN (no se escribió nada):' : 'Resumen del backfill de contratos:');

        $this->table(['Qué', 'Cantidad'], [
            ['Leads promovidos revisados', $conteo['leads_promovidos']],
            ['Sin cliente (promoted_client_id roto)', $conteo['sin_cliente']],
            ['Clientes que ya tenían el contrato copiado', $conteo['ya_copiados']],
            ['Leads sin contrato cargado', $conteo['lead_sin_contrato']],
            ['Contratos copiados', $conteo['copiados']],
            ['Cuotas de licencia generadas', $conteo['cuotas_creadas']],
            ['Inicios de mensualidad fijados', $conteo['inicio_fijado']],
        ]);

        if (count($detalle) > 0) {
            $this->table(['Lead', 'Cliente', 'Nombre', 'Cuotas', 'Inicio'], $detalle);
        }

        return 0;
    }
}

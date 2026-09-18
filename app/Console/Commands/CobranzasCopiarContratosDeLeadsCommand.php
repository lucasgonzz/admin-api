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
 *   - se copia, se generan las cuotas de licencia desde la financiación (no hace nada si el
 *     cliente ya tiene cuotas, importadas de la planilla o cargadas a mano) y se fija
 *     `mensualidad_inicio` solo si está en null.
 *
 * Idempotente por construcción: la segunda corrida no encuentra clientes sin fecha de copia. Con
 * `--simular` recorre y cuenta sin escribir (transacción revertida al final).
 *
 * Corre en producción por el deploy (`pendientes.json` de /deploy-admin), DESPUÉS de
 * `cobranzas:importar-planilla`: así las cuotas importadas de la planilla ya están cuando este
 * comando pregunta si hay cuotas, y no se generan encima las del contrato.
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
    protected $description = 'Copia el contrato del lead a cada cliente promovido que todavía no lo tenga, genera sus cuotas de licencia y fija el inicio de la mensualidad. Idempotente; --simular no escribe.';

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

                        $cuotas = $contrato_service->generar_cuotas_desde_contrato($client);
                        $conteo['cuotas_creadas'] += $cuotas;

                        $inicio = '';
                        if ($client->mensualidad_inicio === null) {
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
                            $inicio !== '' ? $inicio : '(ya tenía)',
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

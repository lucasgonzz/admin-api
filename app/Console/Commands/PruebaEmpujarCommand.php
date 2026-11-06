<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\PruebaDelClienteService;
use App\Services\PruebaWhatsappService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * El empujón diario de la prueba gratuita (misión cruzada prueba-martin-importacion, 3/11/2026): por cada
 * negocio en prueba, destraba la bienvenida en cola, avisa a Tomás si es el día 5 y manda como mucho UNA
 * plantilla `cc_prueba_paso_<hito>` por el hito que falta. Se frena si el negocio escribió en las últimas
 * 24 h, nunca después de vencida, y solo con la plantilla aprobada por Meta.
 *
 * Corre una vez por día a las 10:00 de Argentina (Kernel). Es idempotente: una segunda corrida el mismo
 * día no manda nada (`ya_hoy`).
 *
 * Uso: `php artisan prueba:empujar` · `php artisan prueba:empujar --cliente=123` (uno solo).
 */
class PruebaEmpujarCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'prueba:empujar {--cliente= : Id del cliente en prueba (solo ese)} {--solo-bienvenidas : Retoma las bienvenidas pendientes sin enviar recordatorios}';

    /**
     * @var string
     */
    protected $description = 'Manda el empujón diario por hito faltante a los negocios en prueba gratuita';

    /**
     * @param PruebaWhatsappService   $whatsapp
     * @param PruebaDelClienteService $pruebas
     *
     * @return int
     */
    public function handle(PruebaWhatsappService $whatsapp, PruebaDelClienteService $pruebas)
    {
        $solo = $this->option('cliente');

        if ($this->option('solo-bienvenidas')) {
            $whatsapp->retomar_bienvenidas($solo !== null && $solo !== '' ? (int) $solo : null);

            return 0;
        }

        if ($solo !== null && $solo !== '') {
            $client = Client::find((int) $solo);
            if ($client === null || ! $pruebas->es_cliente_en_prueba($client)) {
                $this->error('El cliente ' . $solo . ' no existe o no está en prueba.');

                return 1;
            }

            $lead  = $pruebas->lead_del_cliente($client);
            $datos = $pruebas->datos($client);
            $tomas = $whatsapp->avisar_a_tomas_si_es_el_dia_5($client, $lead, $datos);
            $paso  = $whatsapp->empujar($client, $lead, $datos);

            $resultados = [[
                'client_id' => (int) $client->id,
                'nombre'    => $client->resolve_display_name(),
                'estado'    => $paso['estado'],
                'detalle'   => $paso['detalle'],
                'tomas'     => $tomas,
            ]];
        } else {
            $resultados = $whatsapp->empujar_a_todos();
        }

        $filas = [];
        foreach ($resultados as $resultado) {
            $filas[] = [
                $resultado['client_id'],
                $resultado['nombre'],
                $resultado['estado'],
                $resultado['detalle'] !== null ? mb_substr((string) $resultado['detalle'], 0, 80) : '',
                is_array($resultado['tomas']) ? (string) $resultado['tomas']['estado'] : '-',
            ];
        }

        $this->table(['Cliente', 'Nombre', 'Resultado', 'Detalle', 'Tomás'], $filas);
        $this->info('Clientes en prueba recorridos: ' . count($resultados));

        Log::channel('daily')->info('prueba:empujar: corrida terminada.', ['clientes' => count($resultados)]);

        return 0;
    }
}

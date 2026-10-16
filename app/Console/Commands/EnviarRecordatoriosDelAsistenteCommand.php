<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\AsistenteRecordatoriosService;
use Illuminate\Console\Command;

/**
 * Manda por WhatsApp los recordatorios de la Agenda que vencieron: «recordame el viernes a las 10
 * que llame a Juan», a las 10 del viernes.
 *
 * Misión cruzada recordatorios-whatsapp (16/10/2026). Corre cada cinco minutos desde el `Kernel`: el
 * `empresa-api` ofrece cada recordatorio desde su hora hasta tres horas después, así que en el peor
 * caso llega cinco minutos tarde, y consultar cada minuto a todos los clientes con el canal prendido
 * (acá no hay filtro de actividad, ver `AsistenteRecordatoriosService`) sería cinco veces más pedidos
 * por algo que nadie nota.
 *
 * Es idempotente y se puede correr de nuevo sin miedo: cada recordatorio se reclama antes de
 * mandarlo, y el `empresa-api` deja de ofrecerlo apenas se lo marca como mandado.
 */
class EnviarRecordatoriosDelAsistenteCommand extends Command
{
    /**
     * Nombre del comando Artisan.
     *
     * El `--client=` es para probar o reintentar uno solo sin barrer a todos.
     *
     * @var string
     */
    protected $signature = 'asistente:enviar-recordatorios {--client= : ID de un solo cliente, para probar o reintentar sin barrer a todos}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Manda por WhatsApp los recordatorios vencidos de la Agenda que el dueño pidió desde el asistente';

    /**
     * Ejecuta el barrido.
     *
     * @param AsistenteRecordatoriosService $recordatorios Servicio que hace el trabajo.
     *
     * @return int
     */
    public function handle(AsistenteRecordatoriosService $recordatorios): int
    {
        $client_id = $this->option('client');

        if ($client_id !== null && trim((string) $client_id) !== '') {
            $client = Client::find((int) $client_id);
            if ($client === null) {
                $this->error('No existe el cliente #' . (int) $client_id . '.');

                return 1;
            }

            /* Igual que en los avisos: con `--client` se consulta aunque el barrido lo hubiera
             * salteado por el interruptor, porque quien escribe el ID lo está pidiendo. Se avisa. */
            if (! (bool) $client->asistente_whatsapp_activo) {
                $this->warn('Ojo: el cliente #' . $client->id . ' tiene el canal del asistente APAGADO. Se consulta igual porque lo pediste por ID.');
            }

            $resultados = [$recordatorios->enviar_a($client)];
        } else {
            $resultados = $recordatorios->enviar_a_todos();
        }

        if ($resultados === []) {
            $this->info('Ningún cliente tiene el asistente por WhatsApp prendido con clave y teléfono: no hay a quién consultar.');

            return 0;
        }

        $this->table(
            ['Cliente', 'Nombre', 'Estado', 'Recordatorios', 'Enviados', 'Detalle'],
            array_map(function (array $fila) {
                return [
                    $fila['client_id'],
                    $fila['nombre'],
                    $fila['estado'],
                    $fila['recordatorios'],
                    $fila['enviados'],
                    $fila['detalle'],
                ];
            }, $resultados)
        );

        return 0;
    }
}

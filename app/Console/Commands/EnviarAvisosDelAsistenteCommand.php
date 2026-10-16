<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\AsistenteAvisosService;
use Illuminate\Console\Command;

/**
 * Manda por WhatsApp los avisos del asistente que el sistema de cada cliente tiene listos: hoy, que
 * terminó de leer la factura que el dueño le mandó, con el link que abre la compra y la revisión.
 *
 * Misión asistente-aviso-escaneo-factura (16/10/2026). Corre cada minuto desde el `Kernel`: un aviso
 * de "ya leí tu factura" que llega media hora tarde ya no sirve, porque el dueño se fue a otra cosa.
 * Es barato porque solo consulta a los clientes cuyo dueño le escribió al asistente en las últimas
 * 24 hs (ver `AsistenteAvisosService::enviar_a_todos()`).
 *
 * Es idempotente y se puede correr de nuevo sin miedo: cada aviso se reclama antes de mandarlo, y el
 * `empresa-api` deja de ofrecerlo apenas se lo marca como mandado.
 */
class EnviarAvisosDelAsistenteCommand extends Command
{
    /**
     * Nombre del comando Artisan.
     *
     * El `--client=` es para probar o reintentar uno solo sin barrer a todos.
     *
     * @var string
     */
    protected $signature = 'asistente:enviar-avisos {--client= : ID de un solo cliente, para probar o reintentar sin barrer a todos}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Manda por WhatsApp los avisos pendientes del asistente (escaneo de factura terminado) a los dueños que le escribieron en las últimas 24 hs';

    /**
     * Ejecuta el barrido.
     *
     * @param AsistenteAvisosService $avisos Servicio que hace el trabajo.
     *
     * @return int
     */
    public function handle(AsistenteAvisosService $avisos): int
    {
        $client_id = $this->option('client');

        if ($client_id !== null && trim((string) $client_id) !== '') {
            $client = Client::find((int) $client_id);
            if ($client === null) {
                $this->error('No existe el cliente #' . (int) $client_id . '.');

                return 1;
            }

            /* Igual que en los informes: con `--client` se consulta aunque el barrido lo hubiera
             * salteado por el interruptor, porque quien escribe el ID lo está pidiendo. Se avisa. */
            if (! (bool) $client->asistente_whatsapp_activo) {
                $this->warn('Ojo: el cliente #' . $client->id . ' tiene el canal del asistente APAGADO. Se consulta igual porque lo pediste por ID.');
            }

            $resultados = [$avisos->enviar_a($client)];
        } else {
            $resultados = $avisos->enviar_a_todos();
        }

        if ($resultados === []) {
            $this->info('Ningún dueño le escribió al asistente en las últimas 24 hs: no hay a quién consultar.');

            return 0;
        }

        $this->table(
            ['Cliente', 'Nombre', 'Estado', 'Avisos', 'Enviados', 'Detalle'],
            array_map(function (array $fila) {
                return [
                    $fila['client_id'],
                    $fila['nombre'],
                    $fila['estado'],
                    $fila['avisos'],
                    $fila['enviados'],
                    $fila['detalle'],
                ];
            }, $resultados)
        );

        return 0;
    }
}

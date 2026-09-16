<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\AsistenteInformesService;
use Illuminate\Console\Command;

/**
 * Manda por WhatsApp los informes de la mañana del mostrador a los dueños con el canal prendido.
 *
 * 🔴 **Por qué es un comando del admin y no un paso de la skill `/mostrador`.** La skill se corre
 * a mano, con Lucas sentado adelante, y hay mañanas en las que no se corre. Un aviso que depende
 * de que alguien se acuerde no es un aviso: es una intención. El comando manda lo que haya, cuando
 * haya, y si un día no hay informes no manda nada. De paso, la skill no se toca — o sea que esto
 * no obliga a sincronizar tooling en las veinticinco carpetas del pool.
 *
 * Es idempotente y se puede correr de nuevo sin miedo: lo que impide el aviso repetido es
 * `mostrador_reportes.avisado_at`, que vive del lado del cliente y se estampa recién DESPUÉS de
 * que el WhatsApp salió.
 */
class EnviarInformesDelAsistenteCommand extends Command
{
    /**
     * Nombre del comando Artisan.
     *
     * El `--client=` es para el día que haya que reintentar uno solo sin volver a barrer los
     * cuarenta, que es exactamente lo que se necesita cuando un cliente falló y los demás salieron.
     *
     * @var string
     */
    protected $signature = 'asistente:enviar-informes {--client= : ID de un solo cliente, para reintentar sin barrer a todos}';

    /**
     * Descripción visible en php artisan list.
     *
     * @var string
     */
    protected $description = 'Manda por WhatsApp los informes del mostrador del día a los dueños con el asistente por WhatsApp prendido';

    /**
     * Ejecuta el barrido.
     *
     * @param AsistenteInformesService $informes Servicio que hace el trabajo.
     *
     * @return int
     */
    public function handle(AsistenteInformesService $informes): int
    {
        $client_id = $this->option('client');

        if ($client_id !== null && trim((string) $client_id) !== '') {
            $client = Client::find((int) $client_id);
            if ($client === null) {
                $this->error('No existe el cliente #' . (int) $client_id . '.');

                return 1;
            }

            /* Con `--client` se manda igual aunque el barrido lo hubiera salteado por el
             * interruptor: quien escribe el ID está pidiendo explícitamente ese cliente, y
             * silenciarlo sin decir por qué sería peor. Se avisa y se sigue. */
            if (! (bool) $client->asistente_whatsapp_activo) {
                $this->warn('Ojo: el cliente #' . $client->id . ' tiene el canal del asistente APAGADO. Se manda igual porque lo pediste por ID.');
            }

            $resultados = [$informes->enviar_a($client)];
        } else {
            $resultados = $informes->enviar_a_todos();
        }

        if ($resultados === []) {
            $this->info('No hay clientes con el asistente por WhatsApp prendido.');

            return 0;
        }

        $this->table(
            ['Cliente', 'Nombre', 'Estado', 'Informes', 'Detalle'],
            array_map(function (array $fila) {
                return [
                    $fila['client_id'],
                    $fila['nombre'],
                    $fila['estado'],
                    $fila['informes'],
                    $fila['detalle'],
                ];
            }, $resultados)
        );

        /* 🔴 `sin_plantilla` se cuenta aparte y se grita, porque es el estado que hace que esta
         * parte NO FUNCIONE en producción y que por fuera se ve idéntico a "no había informes": el
         * comando corre, no rompe nada y nadie recibe nada. A las 8:30 la ventana de 24 hs de Meta
         * está cerrada para casi todos los dueños, así que sin la plantilla aprobada el camino real
         * es siempre este. */
        $sin_plantilla = count(array_filter($resultados, function (array $fila) {
            return $fila['estado'] === 'sin_plantilla';
        }));

        if ($sin_plantilla > 0) {
            $this->error(
                $sin_plantilla . ' cliente(s) tenían informes y no se les mandó nada por falta de plantilla de Meta aprobada. '
                . 'Cargá el nombre de la plantilla en admin_settings.asistente_informe_template_name '
                . 'y la fila correspondiente en client_templates.'
            );
        }

        return 0;
    }
}

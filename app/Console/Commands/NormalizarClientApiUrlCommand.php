<?php

namespace App\Console\Commands;

use App\Models\ClientApi;
use App\Services\ClientEmpresaApiUrlResolver;
use Illuminate\Console\Command;

/**
 * Barrido de datos existentes (grupo 237, prompt 03): limpia `client_apis.url` de los sufijos
 * "/public" que hayan quedado cargados a mano en el pasado, ahora que ClientApiController ya
 * normaliza las cargas nuevas (normalize_api_url(), con el mismo criterio de "while" que acá).
 *
 * Modo reporte por defecto: solo muestra qué filas cambiarían. Escribe en la base únicamente
 * con `--aplicar`. Es idempotente: correrlo dos veces con `--aplicar` la segunda vez reporta
 * cero cambios, porque una URL ya normalizada no vuelve a matchear el filtro de cambio.
 */
class NormalizarClientApiUrlCommand extends Command
{
    /**
     * Nombre y opciones del comando artisan.
     *
     * @var string
     */
    protected $signature = 'normalizar_client_api_url {--aplicar : Persiste los cambios; sin esta opción solo reporta}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Normaliza client_apis.url quitando sufijos "/public" repetidos (modo reporte por defecto, --aplicar para escribir)';

    /**
     * Recorre todos los ClientApi, calcula la URL normalizada de cada uno con el mismo criterio
     * que ClientApiController::normalize_api_url() (trim + rtrim de "/" + while de "/public"
     * final) y reporta las que cambiarían. Con --aplicar, persiste el cambio.
     *
     * No toca filas cuya URL quede vacía tras normalizar (evita borrar datos por accidente
     * ante un valor raro/corrupto: esas filas se listan aparte para revisión manual).
     *
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        /* Flag de escritura: sin --aplicar el comando corre en modo reporte (no persiste nada). */
        $aplicar = (bool) $this->option('aplicar');

        /* Trae todos los ClientApi con su Client para mostrar un nombre legible en el reporte. */
        $client_apis = ClientApi::query()->with('client')->orderBy('id')->get();

        // Filas a mostrar en la tabla de cambios detectados.
        $rows = [];
        // Filas omitidas porque la normalización dejó la URL vacía (no se tocan).
        $omitidas_vacias = [];
        // Contador de filas efectivamente cambiadas (o que cambiarían, en modo reporte).
        $cambiadas_count = 0;

        foreach ($client_apis as $client_api) {
            $url_actual = (string) $client_api->url;

            // Misma normalización que ClientApiController::normalize_api_url(): trim, rtrim de
            // "/" y "while" de sufijos "/public" finales, reutilizada acá vía el resolver para
            // no duplicar el criterio en un tercer lugar.
            $url_normalizada = ClientEmpresaApiUrlResolver::strip_public_suffix(trim($url_actual));

            // Si no cambia nada, no hay nada para reportar ni persistir.
            if ($url_normalizada === $url_actual) {
                continue;
            }

            // Si la normalización dejó la URL vacía, no tocar la fila: se lista aparte.
            if ($url_normalizada === '') {
                $omitidas_vacias[] = [
                    'id'          => $client_api->id,
                    'cliente'     => $this->nombre_cliente($client_api),
                    'url_actual'  => $url_actual,
                ];
                continue;
            }

            $rows[] = [
                'id'               => $client_api->id,
                'cliente'          => $this->nombre_cliente($client_api),
                'url_actual'       => $url_actual,
                'url_normalizada'  => $url_normalizada,
            ];

            if ($aplicar) {
                $client_api->url = $url_normalizada;
                $client_api->save();
            }

            $cambiadas_count++;
        }

        if (! empty($rows)) {
            $this->table(['ID', 'Cliente', 'URL actual', 'URL normalizada'], $rows);
        }

        if (! empty($omitidas_vacias)) {
            $this->warn('Filas omitidas (la normalización dejaría la URL vacía, no se tocan):');
            $this->table(['ID', 'Cliente', 'URL actual'], $omitidas_vacias);
        }

        if ($aplicar) {
            $this->info("client_apis normalizadas y guardadas: {$cambiadas_count}.");
        } else {
            $this->info("Modo reporte (sin --aplicar): {$cambiadas_count} fila(s) cambiarían. Ninguna se modificó.");
        }

        return 0;
    }

    /**
     * Nombre legible del cliente dueño del ClientApi, para el reporte en consola.
     *
     * @param  ClientApi  $client_api
     * @return string
     */
    private function nombre_cliente(ClientApi $client_api): string
    {
        if ($client_api->client === null) {
            return '(sin cliente)';
        }

        return $client_api->client->resolve_display_name();
    }
}

<?php

namespace App\Console\Commands;

use App\Models\ClientVersionUpgrade;
use App\Models\Version;
use App\Services\VersionNumberComparator;
use Illuminate\Console\Command;

/**
 * Barrido de datos existentes: alinea `clients.current_version_id` con la versión de destino del
 * upgrade `terminada` más alto de cada cliente.
 *
 * Repara lo que dejó el agujero que arregla el hook `saved` de ClientVersionUpgrade: hasta ahora
 * la única que escribía `current_version_id` era PublishVersionService::syncExisting() (el botón
 * "sincronizar al cliente"), así que un deployment completo o un "Terminada" puesto a mano en la
 * grilla dejaban al cliente figurando en una versión vieja. El hook cubre de acá en adelante;
 * este comando cubre para atrás.
 *
 * Medido el 28/8/2026 contra producción: 1 cliente desalineado (Servian, upgrade 56 del 1/8/2026,
 * deployment `completed` con los seis pasos hechos y el cliente en 3.3.1 con la 3.3.3 arriba).
 *
 * Modo reporte por defecto: solo muestra qué clientes cambiarían. Escribe únicamente con
 * `--aplicar`. Es idempotente: una segunda corrida reporta cero cambios, porque
 * `alinear_version_del_cliente()` no reescribe una versión que ya coincide.
 *
 * 🔴 Nunca baja la versión de un cliente: la comparación es semántica
 * (VersionNumberComparator), no por `id` de la fila.
 */
class RealinearVersionDeClientesCommand extends Command
{
    /**
     * Nombre y opciones del comando artisan.
     *
     * @var string
     */
    protected $signature = 'realinear_version_de_clientes {--aplicar : Persiste los cambios; sin esta opción solo reporta}';

    /**
     * Descripción del comando para `php artisan list`.
     *
     * @var string
     */
    protected $description = 'Alinea clients.current_version_id con el upgrade terminado más alto de cada cliente (modo reporte por defecto, --aplicar para escribir)';

    /**
     * @return int Código de salida (0 = éxito).
     */
    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        /**
         * Un solo barrido de los upgrades terminados, con cliente y versión de destino cargados.
         * Los que no tienen cliente o destino se descartan acá: `alinear_version_del_cliente()`
         * los rechazaría igual, pero así no ensucian el reporte.
         */
        $terminados = ClientVersionUpgrade::where('status', 'terminada')
            ->whereNotNull('client_id')
            ->whereNotNull('to_version_id')
            ->with(['client', 'to_version'])
            ->orderBy('id')
            ->get()
            ->filter(function ($upgrade) {
                return !is_null($upgrade->client) && !is_null($upgrade->to_version);
            });

        /**
         * El upgrade que manda por cliente es el de destino semánticamente MÁS ALTO, no el último
         * por `id` ni por fecha: con hotfixes de por medio, un upgrade cargado después puede
         * apuntar a una versión anterior (es exactamente el motivo por el que existe
         * VersionNumberComparator).
         */
        $mas_alto_por_cliente = [];

        foreach ($terminados as $upgrade) {

            $client_id = (int) $upgrade->client_id;

            if (!isset($mas_alto_por_cliente[$client_id])) {
                $mas_alto_por_cliente[$client_id] = $upgrade;
                continue;
            }

            $actual = $mas_alto_por_cliente[$client_id];

            if (VersionNumberComparator::compare($upgrade->to_version->version, $actual->to_version->version) > 0) {
                $mas_alto_por_cliente[$client_id] = $upgrade;
            }
        }

        $filas = [];

        foreach ($mas_alto_por_cliente as $upgrade) {

            $client = $upgrade->client;

            $version_actual = is_null($client->current_version_id)
                ? null
                : Version::find($client->current_version_id);

            $texto_actual = is_null($version_actual) ? '(sin versión)' : $version_actual->version;

            /**
             * La decisión de si corresponde escribir es del modelo, no de acá: en modo reporte se
             * pregunta lo mismo que se haría al aplicar, para que las dos corridas no puedan
             * discrepar. Con --aplicar el método escribe; sin él se simula el mismo criterio.
             */
            if ($aplicar) {
                $cambio = $upgrade->alinear_version_del_cliente();
            } else {
                $cambio = $this->cambiaria($client->current_version_id, $upgrade->to_version);
            }

            if (!$cambio) {
                continue;
            }

            $filas[] = [
                $client->id,
                $client->company_name ?: $client->name,
                $texto_actual,
                $upgrade->to_version->version,
                '#' . $upgrade->id,
            ];
        }

        if (empty($filas)) {
            $this->info('Todos los clientes con upgrades terminados ya están alineados. Nada que hacer.');
            return 0;
        }

        $this->table(
            ['Cliente', 'Nombre', 'Figuraba en', 'Pasa a', 'Upgrade'],
            $filas
        );

        if ($aplicar) {
            $this->info(count($filas) . ' cliente(s) realineado(s).');
        } else {
            $this->warn(count($filas) . ' cliente(s) desalineado(s). Corré con --aplicar para escribir.');
        }

        return 0;
    }

    /**
     * Mismo criterio que `ClientVersionUpgrade::alinear_version_del_cliente()`, sin escribir:
     * hay cambio si el cliente no tiene versión, o si la de destino es semánticamente mayor.
     *
     * @param  int|null $current_version_id
     * @param  Version  $destino
     * @return bool
     */
    private function cambiaria($current_version_id, Version $destino): bool
    {
        if (is_null($current_version_id)) {
            return true;
        }

        if ((int) $current_version_id === (int) $destino->id) {
            return false;
        }

        $actual = Version::find($current_version_id);

        if (is_null($actual)) {
            return true;
        }

        return VersionNumberComparator::compare($destino->version, $actual->version) > 0;
    }
}

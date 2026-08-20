<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientSshCredential;
use App\Services\Afip\AfipCertificateProvisionService;
use App\Services\ClientApiPathResolver;
use Illuminate\Console\Command;
use phpseclib3\Net\SFTP;

/**
 * Audita —y opcionalmente repone— los certificados de AFIP de los clientes ya instalados.
 *
 * Por qué hace falta: desde el 26/7/2026 los certificados no viajan más en el código
 * (empresa-api commit ec6e164a). InstallationService y DeploymentService ya los instalan solos,
 * pero un cliente que hoy está roto no se arregla hasta que le toque una actualización. Este
 * comando permite verlos todos de una y arreglarlos sin esperar a la próxima versión.
 *
 * Uso:
 *   php artisan afip:certificados-clientes              (solo informa)
 *   php artisan afip:certificados-clientes --instalar   (repone lo que falte)
 *   php artisan afip:certificados-clientes --cliente=12 (un solo cliente, por id)
 */
class AfipCertificadosClientesCommand extends Command
{
    /**
     * Firma del comando.
     *
     * @var string
     */
    protected $signature = 'afip:certificados-clientes
                            {--instalar : Instala los certificados que falten en vez de solo informar}
                            {--cliente= : Id de un único cliente a revisar}';

    /**
     * Descripción del comando.
     *
     * @var string
     */
    protected $description = 'Revisa qué clientes no tienen los certificados de AFIP instalados y, con --instalar, se los copia desde el admin';

    /**
     * Recorre las APIs activas de los clientes, mira por SFTP qué certificados tienen y reporta.
     *
     * @return int
     */
    public function handle(): int
    {
        $service  = new AfipCertificateProvisionService();
        $resolver = new ClientApiPathResolver();
        $instalar = (bool) $this->option('instalar');

        $faltantes_en_admin = $service->faltantes_en_admin();
        if (! empty($faltantes_en_admin)) {
            $this->error(
                'Faltan certificados en el servidor del admin: ' . implode(', ', $faltantes_en_admin) . '.'
            );
            $this->line('Cargalos en el panel (Configuración AFIP → Certificados) antes de instalar nada.');

            if ($instalar) {
                return 1;
            }
        }

        $query = Client::query()->whereNotNull('active_client_api_id')->with('active_client_api');
        if (! empty($this->option('cliente'))) {
            $query->where('id', (int) $this->option('cliente'));
        }

        $clients = $query->get();
        if ($clients->isEmpty()) {
            $this->info('No hay clientes con una API activa para revisar.');

            return 0;
        }

        // Una sesión SFTP por tipo de credencial, reusada entre clientes: abrir una por cliente
        // contra el mismo hosting compartido es tiempo tirado.
        $sesiones = [];
        $con_faltantes = 0;
        $reparados = 0;

        foreach ($clients as $client) {
            $client_api = $client->active_client_api;
            if ($client_api === null) {
                continue;
            }

            $nombre = $client->resolve_display_name() ?: ('cliente ' . $client->id);

            try {
                $api_path = $resolver->resolve($client_api);
                $credential_type = $resolver->credential_type($client_api);

                if (! isset($sesiones[$credential_type])) {
                    $sesiones[$credential_type] = $this->abrir_sftp($credential_type);
                }

                $sftp = $sesiones[$credential_type];
                $auditoria = $service->auditar($sftp, $api_path);

                if (empty($auditoria['faltantes'])) {
                    $this->line('  OK       ' . $nombre);
                    continue;
                }

                $con_faltantes++;
                $this->warn('  FALTAN   ' . $nombre . ': ' . implode(', ', $auditoria['faltantes']));

                if (! $instalar) {
                    continue;
                }

                $log = function (string $linea, string $nivel) {
                    $this->line('             ' . $linea);
                };

                $resultado = $service->provision($sftp, $api_path, $log);

                if (! empty($resultado['errores'])) {
                    $this->error('             ' . implode(' ', $resultado['errores']));
                    continue;
                }

                if (! empty($resultado['instalados'])) {
                    $reparados++;
                    $this->info('             instalados: ' . implode(', ', $resultado['instalados']));
                }
            } catch (\Exception $e) {
                $this->error('  ERROR    ' . $nombre . ': ' . $e->getMessage());
            }
        }

        foreach ($sesiones as $sftp) {
            $sftp->disconnect();
        }

        $this->newLine();
        $this->info('Clientes revisados: ' . $clients->count() . '. Con certificados faltantes: ' . $con_faltantes . '.');

        if ($instalar) {
            $this->info('Clientes reparados: ' . $reparados . '.');
        } elseif ($con_faltantes > 0) {
            $this->line('Corré el comando con --instalar para reponerlos.');
        }

        return 0;
    }

    /**
     * Abre una sesión SFTP con la credencial guardada para ese tipo de hosting.
     *
     * @param  string  $credential_type
     * @return SFTP
     * @throws \RuntimeException Si no se puede autenticar.
     */
    private function abrir_sftp(string $credential_type): SFTP
    {
        $credential = ClientSshCredential::where('type', $credential_type)->firstOrFail();
        $sftp = new SFTP($credential->host, (int) $credential->port);

        if (! $sftp->login($credential->username, $credential->password)) {
            throw new \RuntimeException("No se pudo conectar por SFTP ({$credential_type}).");
        }

        return $sftp;
    }
}

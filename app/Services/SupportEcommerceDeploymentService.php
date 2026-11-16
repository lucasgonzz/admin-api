<?php

namespace App\Services;

use App\Models\ClientEcommerceInstallation;
use App\Models\SupportCodexScheduledUpgrade as Scheduled;

/** El pipeline habitual, acotado al acuerdo, con respaldo privado y recuperación de archivos. */
class SupportEcommerceDeploymentService extends EcommerceDeploymentService
{
    private $support_row;

    public function recover(): void
    {
        $row = $this->support_row->fresh();
        if ($row->live_touched_at === null) { return; }
        if (! hash_equals((string) $row->agreement->store_identity_hash, SupportMaintenancePolicy::store_hash($this->ecommerce))) {
            throw new \RuntimeException('Cambió la identidad del destino respaldado.');
        }
        $this->restore_backup();
        $this->ecommerce->update(['status' => 'active', 'ecommerce_version_id' => $row->from_version_id]);
        $this->installation->update(['status' => 'fallida', 'finished_at' => now(), 'failure_reason' => 'Restaurada por corte de soporte interrumpido.']);
        $row->update(['status' => Scheduled::FAILED, 'reason' => 'tienda_restaurada_tras_fallo']);
    }

    public function __construct(ClientEcommerceInstallation $installation, Scheduled $row)
    {
        parent::__construct($installation);
        $this->support_row = $row;
    }

    public function run()
    {
        try {
            parent::run();
            $this->reconnect_hosting_ssh();
            $this->support_command('support_up', 'cd ' . escapeshellarg($this->get_api_path()) . ' && '
                . $this->hosting_php() . ' artisan up --no-ansi 2>&1', true, true);
        } catch (\Throwable $error) {
            $row = $this->support_row->fresh();
            if ($row->live_touched_at !== null) {
                try {
                    $this->recover();
                    app(SupportStoreUpgradeService::class)->request_review($row, 'Falló la actualización de tienda y se restauraron los archivos respaldados. Verificar disponibilidad y coordinar otro corte; no anunciar el arreglo disponible.');
                } catch (\Throwable $restore_error) {
                    $row->update(['status' => Scheduled::FAILED, 'reason' => 'restauracion_tienda_sin_confirmar']);
                    app(SupportStoreUpgradeService::class)->request_review($row, 'Falló la actualización de tienda y no se confirmó la restauración. Revisar inmediatamente su disponibilidad y el respaldo antes de repetir una escritura.');
                }
            }
            throw $error;
        }
    }

    protected function step_upload_spa()
    {
        $service = app(SupportStoreUpgradeService::class);
        $service->before_write($this->support_row->fresh(), false);
        $this->assert_store_idle();
        $this->backup();
        $service->before_write($this->support_row->fresh(), true);
        $this->reconnect_hosting_ssh();
        $this->support_command('support_down', 'cd ' . escapeshellarg($this->get_api_path()) . ' && '
            . $this->hosting_php() . ' artisan down --no-ansi 2>&1', true, true);
        $this->support_command('support_drain', 'sleep 30', true, true);
        $this->assert_store_idle();
        $service->before_write($this->support_row->fresh(), false);
        parent::step_upload_spa();
    }

    protected function step_upload_api()
    {
        app(SupportStoreUpgradeService::class)->before_write($this->support_row->fresh(), false);
        $this->assert_store_idle();
        parent::step_upload_api();
    }

    protected function step_finalize()
    {
        app(SupportStoreUpgradeService::class)->before_write($this->support_row->fresh(), false);
        parent::step_finalize();
    }

    protected function assert_support_store_artifact(string $repo, array $asset, string $file): void
    {
        $expected = $this->support_row->artifacts[$repo] ?? null;
        $id = basename((string) parse_url((string) ($asset['url'] ?? ''), PHP_URL_PATH));
        if ($expected === null || (string) $expected['id'] !== $id || ! is_file($file)
            || (int) filesize($file) !== (int) $expected['size'] || ! hash_equals($expected['sha256'], hash_file('sha256', $file))) {
            throw new \RuntimeException('El artefacto descargado no coincide con el release acordado.');
        }
    }

    private function support_command(string $step, string $command, bool $must_succeed = true, bool $long_running = false): string
    {
        $output = $this->exec_hosting_ssh($step, $command . " && printf '\nCC_SUPPORT_OK\n'", true, $long_running);
        if (substr(rtrim($output), -13) !== 'CC_SUPPORT_OK') {
            throw new \RuntimeException('El servidor no confirmó la operación de mantenimiento.');
        }
        return rtrim(substr(rtrim($output), 0, -13));
    }

    private function hosting_php(): string
    {
        return escapeshellarg((string) config('services.deploy_tienda.php_bin', '/opt/alt/php84/usr/bin/php'));
    }

    private function assert_store_idle(): void
    {
        $payload = file_get_contents(__DIR__ . '/UpgradePreflight/read_coordinated_store_activity.php');
        if ($payload === false) { throw new \RuntimeException('Falta el lector de actividad de tienda.'); }
        $row = $this->support_row->fresh();
        $this->reconnect_hosting_ssh();
        $out = $this->support_command('support_activity', 'cd ' . escapeshellarg($this->get_api_path()) . ' && '
            . $this->hosting_php() . ' -d display_errors=0 -r ' . escapeshellarg(substr($payload, 5)) . ' -- '
            . (int) $this->client->user_id . ' ' . \Carbon\Carbon::parse($row->starts_at_utc, 'UTC')->timestamp . ' 2>/dev/null', true, true);
        $activity = json_decode(trim($out), true);
        if (! is_array($activity) || array_keys($activity) !== ['pedidos_recientes', 'carritos_recientes', 'trabajos_pendientes']) {
            throw new \RuntimeException('No se pudo verificar la actividad y la cola de la tienda.');
        }
        foreach ($activity as $count) {
            if (! is_int($count) || $count !== 0) { throw new \RuntimeException('La tienda todavía tiene actividad o trabajos pendientes.'); }
        }
    }

    private function paths(): array
    {
        $spa = $this->get_spa_docroot();
        $api = $this->get_api_path();
        foreach ([$spa, $api] as $path) {
            if (! preg_match('~^domains/[a-zA-Z0-9.-]+/public_html(?:/[a-zA-Z0-9._/-]+)?$~D', $path)
                || preg_match('~(^|/)\.\.?(/|$)~', $path)) {
                throw new \RuntimeException('La ruta de la tienda no está acotada a su dominio.');
            }
        }
        // El SPA suele contener /api: no respaldar ni restaurar dos veces ese árbol.
        return strpos($api . '/', rtrim($spa, '/') . '/') === 0 ? [$spa] : [$spa, $api];
    }

    private function backup(): void
    {
        $row = $this->support_row->fresh();
        if ($row->backup_path !== null) { throw new \RuntimeException('La corrida ya tiene un respaldo: requiere conciliación antes de reejecutar.'); }
        $path = '.cc-support-backups/tienda-' . (int) $row->id . '-' . $this->installation->uuid . '.tar.gz';
        $paths = implode(' ', array_map('escapeshellarg', $this->paths()));
        $this->reconnect_hosting_ssh();
        $out = $this->support_command('support_backup', 'umask 077; mkdir -p .cc-support-backups && chmod 700 .cc-support-backups'
            . ' && test ! -e ' . escapeshellarg($path) . ' && test ! -e ' . escapeshellarg($this->get_api_path() . '/storage/framework/down')
            . ' && tar -czf ' . escapeshellarg($path) . ' -- ' . $paths
            . ' && tar -tzf ' . escapeshellarg($path) . ' >/dev/null && chmod 600 ' . escapeshellarg($path)
            . ' && sha256sum ' . escapeshellarg($path) . ' && stat -c %s ' . escapeshellarg($path), true, true);
        if (! preg_match('/\b([a-f0-9]{64})\s+[^\r\n]+\r?\n([0-9]+)\s*$/D', trim($out), $matches) || (int) $matches[2] < 1) {
            throw new \RuntimeException('No se confirmó el respaldo privado de la tienda.');
        }
        $row->update(['backup_path' => $path, 'backup_sha256' => $matches[1], 'backup_size' => (int) $matches[2]]);
    }

    private function restore_backup(): void
    {
        $row = $this->support_row->fresh();
        $expected = '.cc-support-backups/tienda-' . (int) $row->id . '-' . $this->installation->uuid . '.tar.gz';
        if ($row->backup_path !== $expected || ! preg_match('/^[a-f0-9]{64}$/D', $row->backup_sha256 ?? '')) {
            throw new \RuntimeException('El respaldo no corresponde a la corrida.');
        }
        $quarantine = '.cc-support-backups/fallida-' . (int) $row->id . '-' . $this->installation->uuid;
        $command = 'test "$(sha256sum ' . escapeshellarg($expected) . ' | cut -d " " -f1)" = ' . escapeshellarg($row->backup_sha256)
            . ' && test "$(stat -c %s ' . escapeshellarg($expected) . ')" = ' . escapeshellarg((string) $row->backup_size)
            . ' && mkdir ' . escapeshellarg($quarantine);
        foreach ($this->paths() as $index => $path) {
            $command .= ' && mv -- ' . escapeshellarg($path) . ' ' . escapeshellarg($quarantine . '/' . $index);
        }
        $command .= ' && tar -xzf ' . escapeshellarg($expected) . ' && test -f ' . escapeshellarg($this->get_api_path() . '/artisan');
        $this->reconnect_hosting_ssh();
        $this->support_command('support_restore', $command, true, true);
    }
}

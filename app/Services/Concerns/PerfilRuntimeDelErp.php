<?php

namespace App\Services\Concerns;

use App\Services\RuntimeProfileService;
use App\Services\ReleaseArtifactService;

/** Adaptación común de los pipelines existentes; ausencia del marcador conserva el legado. */
trait PerfilRuntimeDelErp
{
    private $runtime_profile = null;
    private $runtime_extensions = [];
    private $runtime_preflight_done = false;

    private function runtime_service(): RuntimeProfileService
    {
        return new RuntimeProfileService();
    }

    private function runtime_context(): array
    {
        if (isset($this->target_api)) {
            return [$this->get_hosting_credential_type(), $this->get_api_path(),
                isset($this->upgrade) ? $this->version_tag() : 'v' . $this->installation->version->version];
        }
        return [$this->demo_hosting_type(), $this->demo_api_path(),
            isset($this->demo_update) ? 'v' . $this->version->version : $this->version_tag()];
    }

    /** Se ejecuta antes de subir SPA/API, incluso cuando se reanuda directamente en la API. */
    private function runtime_preflight(string $step): void
    {
        if ($this->runtime_preflight_done) {
            return;
        }
        list($hosting, $path, $tag) = $this->runtime_context();
        $this->runtime_profile = null;
        if ($hosting === 'vps' && $this->ssh === null) {
            if (isset($this->target_api)) {
                $this->connect();
            } else {
                $this->connect_hosting_ssh();
            }
        }
        $exec = function (string $command) use ($step): string {
            return $this->exec_hosting_ssh($step, $command);
        };
        // El archivo todavía no es confiable: nunca volcar su contenido al log del pipeline.
        $read = function (string $command): string {
            return $this->runtime_read_marker($this->ssh, $command);
        };
        $service = $this->runtime_service();
        $profile = $service->discover($hosting, $path, $read);
        if (isset($this->upgrade) && ! $this->es_en_el_lugar()) {
            $active = $this->upgrade->client->active_client_api;
            if ($active !== null && (int) $active->id !== (int) $this->target_api->id) {
                $resolver = new \App\Services\ClientApiPathResolver();
                $sourceHosting = (string) ($active->hosting_type ?? 'shared_hosting');
                if ($sourceHosting === 'vps' && $hosting !== 'vps') {
                    // No consultar un marcador VPS a través de una sesión del shared.
                    $credential = \App\Models\ClientSshCredential::where('type', 'vps')->firstOrFail();
                    $ssh = $this->abrir_sesion_ssh($credential);
                    try {
                        $source = $service->discover($sourceHosting, $resolver->resolve($active), function ($command) use ($ssh) {
                            return $this->runtime_read_marker($ssh, $command);
                        });
                    } finally {
                        $ssh->disconnect();
                    }
                } else {
                    $source = $service->discover($sourceHosting, $resolver->resolve($active), $read);
                }
                $service->assertSame($profile, $source);
            }
        }
        $this->runtime_profile = $profile;
        if ($profile === null) {
            $this->runtime_preflight_done = true;
            return;
        }
        if (isset($this->upgrade)) {
            $this->upgrade->load(['update_commands.version_command', 'update_seeders.version_seeder']);
            foreach ($this->upgrade->update_commands as $item) {
                $command = $item->version_command;
                if ($command === null || $item->status === 'exitoso' || (bool) $item->skipped
                    || $this->is_version_command_manual($command)) {
                    continue;
                }
                $service->assertPublishedCommand($this->resolve_client_run_command(
                    (string) $command->command, $command->run_scope
                ));
            }
            foreach ($this->upgrade->update_seeders as $item) {
                $seeder = $item->version_seeder;
                if ($seeder === null || (bool) $item->skipped
                    || ($this->es_en_el_lugar() && $item->status === 'exitoso')) {
                    continue;
                }
                $service->assertPublishedCommand($this->resolve_client_run_command(
                    $this->get_seeder_command($seeder), $seeder->run_scope
                ));
            }
        }

        // Validar el tag elegido antes de tocar el SPA, sin ejecutar código del release.
        $release = isset($this->upgrade) ? $this->release_artifacts() : $this->artefactos_release();
        $asset = $release->find_asset('empresa-api', $tag, 'empresa-api-' . $tag . '.zip');
        $directory = storage_path('app/deployments');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('No se pudo crear el directorio para verificar el perfil.');
        }
        $zip = tempnam($directory, 'runtime-');
        try {
            if ($asset !== null) {
                $release->download_asset($asset, $zip);
                $this->runtime_extensions = $service->assertZip($zip);
            } else {
                if (! config('services.deploy.permitir_build_en_vps')
                    || $release->download_source_zip('empresa-api', $tag, $zip) === null) {
                    throw new \RuntimeException('El release elegido no trae un artefacto API verificable para el perfil VPS.');
                }
                $this->runtime_extensions = $service->assertZip($zip, true);
            }
            $service->assertRuntime($exec, $this->runtime_extensions);
            $this->runtime_preflight_done = true;
        } finally {
            if (is_file($zip)) {
                unlink($zip);
            }
        }
    }

    private function runtime_command(string $command): string
    {
        return $this->runtime_profile === null ? $command : $this->runtime_service()->environment($command);
    }

    private function runtime_read_marker(\phpseclib3\Net\SSH2 $ssh, string $command): string
    {
        $ssh->setTimeout(10);
        $output = $ssh->exec('{ ' . $command . "\n} < /dev/null");
        if ($output === false || $ssh->getExitStatus() !== 0) {
            throw new \RuntimeException('No se pudo leer el marcador runtime de forma segura.');
        }
        return $output;
    }

    private function runtime_assert_zip(string $zip): void
    {
        if ($this->runtime_profile !== null) {
            $this->runtime_service()->assertZip($zip);
        }
    }

    /** Comprobar intérprete y requisitos también en el build autorizado. */
    private function runtime_assert_build(string $step): void
    {
        if ($this->runtime_profile !== null) {
            $this->runtime_service()->assertRuntime(function ($command) use ($step) {
                return $this->exec_build_ssh($step, $command);
            }, $this->runtime_extensions);
            $code = '$files=[]; foreach (["composer.json","composer.lock","composer.vps.json","composer.vps.lock"] as $file) { $files[$file]=file_get_contents($file); } echo json_encode($files);';
            $output = $this->exec_build_ssh($step, 'cd ' . $this->runtime_service()->quote($this->builds_api_path())
                . ' && /usr/bin/php8.4 -r ' . $this->runtime_service()->quote($code));
            $files = json_decode(trim($output), true);
            if (! is_array($files)) {
                throw new \RuntimeException('No se pudo verificar el perfil del checkout del build.');
            }
            $this->runtime_service()->assertManifests($files);
        }
    }
}

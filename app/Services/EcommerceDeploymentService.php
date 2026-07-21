<?php

namespace App\Services;

/**
 * Ejecuta el pipeline de ACTUALIZACIÓN del ecommerce (tienda-spa + tienda-api) de un cliente que
 * ya tiene su tienda instalada y configurada.
 *
 * Extiende `EcommerceInstallationService` (prompt 584) para reutilizar sin duplicar: el
 * constructor (carga de la corrida/tienda/cliente), `run()` (maneja estados de la corrida y del
 * ecommerce, incluyendo restaurar el status previo del ecommerce si la corrida falla) y los pasos
 * comunes del pipeline — `ensure_spa_cloned` (siempre trae la última de master), `compile_spa`
 * (branding en vivo + íconos PWA + build) y `upload_spa` (mv atómico en la raíz del dominio).
 *
 * Las únicas diferencias respecto de la clase padre:
 *   - `expected_mode()`: valida `mode = 'update'` en vez de `'install'`.
 *   - `$steps`: sin `write_env` — una actualización nunca re-crea ni pisa el `.env` ya existente
 *     en el hosting, ni vuelve a correr la lógica de instalación desde cero.
 *   - `step_upload_api()`: sube SOLO el código de tienda-api (excluye `.env`, `vendor/`, `public/`
 *     y `storage/`, además de `.git/`) en vez del paquete de instalación inicial (que sí sube
 *     `public/`/`storage/` porque todavía no existen en el hosting).
 *
 * Espeja el mismo criterio que `DeploymentService` (empresa) para sus actualizaciones: comprime
 * todo el código excepto `.env`, `public/` y `storage/`, sin re-crear la base ni el `.env`.
 *
 * No hay selección de tag/versión: siempre se usa la última de la rama `master`, tanto para
 * tienda-spa (heredado de `step_ensure_spa_cloned`) como para tienda-api (ver `step_upload_api`
 * de abajo).
 */
class EcommerceDeploymentService extends EcommerceInstallationService
{
    /**
     * Orden de etapas del pipeline de actualización: sin `write_env` (no se toca el `.env`
     * existente) ni ninguna otra etapa de creación desde cero.
     *
     * @var array<int, string>
     */
    protected $steps = [
        'ensure_spa_cloned',
        'compile_spa',
        'upload_spa',
        'upload_api',
        'finalize',
    ];

    /**
     * Esta clase solo maneja `mode = 'update'`; `mode = 'install'` lo maneja la clase padre.
     *
     * @return string
     */
    protected function expected_mode(): string
    {
        return 'update';
    }

    /**
     * Etapa: sube SOLO el código de tienda-api a la subcarpeta /api del dominio del cliente y
     * corre `composer install --no-scripts`, sin tocar el `.env` existente ni `public/`/`storage/`
     * (donde vive contenido y el symlink de storage que la actualización no debe pisar).
     *
     * Sobrescribe a `EcommerceInstallationService::step_upload_api()` (instalación desde cero, que
     * sí sube `public/`/`storage/` porque todavía no existen en el hosting).
     *
     * @return void
     */
    protected function step_upload_api()
    {
        $this->connect_build_vps();

        $api_build_path = $this->builds_api_path();
        $this->log('upload_api', 'Preparando tienda-api en VPS de builds (actualización, última de master)');

        // Siempre trae la última de master (mismo criterio que step_ensure_spa_cloned del SPA):
        // no hay selección de tag/versión para el ecommerce.
        $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path) . ' && git fetch origin master 2>&1'
        );
        $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path) . ' && git checkout master 2>&1'
        );
        $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path) . ' && git reset --hard origin/master 2>&1'
        );

        $this->log('upload_api', 'Corriendo composer install en VPS...');
        $this->exec_build_ssh(
            'upload_api',
            $this->build_composer_install_command($api_build_path, true)
        );
        $this->log('upload_api', 'composer install en VPS completado', 'success');

        // Empaqueta SOLO código: excluye .env (nunca se toca en una actualización), vendor/ (se
        // reinstala en el hosting vía composer), public/ y storage/ (contenido/symlink ya
        // existente que la actualización no debe pisar) y .git/.
        $zip_name       = 'tienda_api_update_' . $this->installation->uuid . '.zip';
        $api_zip_remote = $api_build_path . '/' . $zip_name;
        $this->reconnect_build_vps();

        // Limpieza de ZIPs huérfanos de corridas previas (mismo criterio que la instalación).
        $this->exec_build_ssh(
            'upload_api',
            'cd ' . escapeshellarg($api_build_path)
            . " && find . -maxdepth 1 -name 'tienda_api_*.zip' -mmin +120 -delete 2>&1"
        );

        $zip_command = 'cd ' . escapeshellarg($api_build_path)
            . ' && rm -f ' . escapeshellarg($zip_name)
            . ' && zip -r ' . escapeshellarg($zip_name) . ' . '
            . "--exclude='.env' --exclude='vendor/*' --exclude='public/*' --exclude='storage/*'"
            . " --exclude='.git/*' --exclude='*.zip'"
            . ' 2>&1';
        $this->exec_build_ssh('upload_api', $zip_command, true, true);

        $api_zip_bytes = $this->verify_zip_on_vps($api_zip_remote, 'upload_api');
        $this->log('upload_api', "tienda-api empaquetada para actualización ({$api_zip_bytes} bytes en VPS)");

        $deployments_dir = storage_path('app/deployments');
        if (! is_dir($deployments_dir)) {
            mkdir($deployments_dir, 0755, true);
        }
        $local_zip  = storage_path('app/deployments/tienda_api_update_' . $this->installation->uuid . '.zip');
        $sftp_build = $this->open_sftp_session('vps');
        $this->sftp_download_file($sftp_build, $api_zip_remote, $local_zip, $api_zip_bytes, 'upload_api');
        $this->log('upload_api', 'ZIP descargado al servidor de admin');

        $api_path     = $this->get_api_path();
        $remote_zip   = "{$api_path}/{$zip_name}";
        $sftp_hosting = $this->open_sftp_session('shared_hosting');
        $this->sftp_upload_file($sftp_hosting, $local_zip, $remote_zip, 'upload_api');
        $this->log('upload_api', 'ZIP subido al hosting');

        // unzip -o sobrescribe SOLO los archivos que vienen en el ZIP (código); .env/public/storage
        // no están en el ZIP y quedan intactos.
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            "cd {$api_path} && unzip -o {$zip_name} && rm {$zip_name}",
            true,
            true
        );
        $this->log(
            'upload_api',
            'tienda-api actualizada en el hosting (código, sin tocar .env/public/storage)',
            'success'
        );

        // composer install sin scripts: el .env ya existe (a diferencia de la instalación inicial),
        // pero de todas formas se posponen los scripts de arranque a finalize(), que ya corre
        // package:discover y limpieza de caches con el .env real en disco.
        $this->log('upload_api', 'Corriendo composer install en hosting (sin scripts)...');
        $this->reconnect_hosting_ssh();
        $this->exec_hosting_ssh(
            'upload_api',
            $this->build_composer_install_command($api_path, false),
            true,
            true
        );
        $this->log('upload_api', 'Dependencias de tienda-api actualizadas en el hosting', 'success');

        if (is_file($local_zip)) {
            unlink($local_zip);
        }
        $this->reconnect_build_vps();
        $this->exec_build_ssh('upload_api', 'rm -f ' . escapeshellarg($api_zip_remote));
        $this->log('upload_api', 'Archivos temporales eliminados');
    }
}

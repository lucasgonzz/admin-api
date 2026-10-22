<?php

namespace App\Services;

/** Contrato opt-in del ERP. No escribe marcadores ni decide por el tipo de hosting. */
class RuntimeProfileService
{
    const PROFILE = 'vps-php84-v1';
    const PHP = '/usr/bin/php8.4';
    const COMPOSER = '/usr/local/bin/composer';

    public function markerReadCommand(string $path): string
    {
        $reader = <<<'PYTHON'
import os, sys, stat, json
fd = None
result = 'CC_RUNTIME_INVALIDO'
try:
    fd = os.open(sys.argv[1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    info = os.fstat(fd)
    if stat.S_ISREG(info.st_mode) and info.st_size <= 1024:
        data = os.read(fd, 1025)
        if len(data) <= 1024:
            marker = json.loads(data)
            if isinstance(marker, dict) and set(marker) == {'schema', 'profile'} and type(marker['schema']) is int and marker['schema'] == 1 and marker['profile'] == 'vps-php84-v1':
                result = '{"schema":1,"profile":"vps-php84-v1"}'
except FileNotFoundError:
    result = 'CC_RUNTIME_AUSENTE'
except Exception:
    pass
finally:
    if fd is not None:
        os.close(fd)
print(result)
PYTHON;
        return 'python3 -c ' . $this->quote($reader) . ' ' . $this->quote($path);
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    public function discover(string $hosting, string $apiPath, callable $exec): ?string
    {
        if ($hosting !== 'vps') {
            return null;
        }
        if (! preg_match('#^/home/api-[A-Za-z0-9_][A-Za-z0-9_-]*/empresa-api$#D', $apiPath)) {
            throw new \RuntimeException('Ruta VPS inválida para consultar el perfil del ERP.');
        }
        $output = trim($exec($this->markerReadCommand(dirname($apiPath) . '/.comerciocity-runtime.json')));
        if ($output === 'CC_RUNTIME_AUSENTE') {
            return null;
        }
        $marker = json_decode($output, true);
        if (! is_array($marker) || count($marker) !== 2 || ($marker['schema'] ?? null) !== 1
            || ($marker['profile'] ?? null) !== self::PROFILE) {
            throw new \RuntimeException('Marcador runtime inválido o perfil desconocido; no se despliega.');
        }
        return self::PROFILE;
    }

    public function assertSame(?string $target, ?string $source): void
    {
        if ($target !== $source) {
            throw new \RuntimeException('Los frentes tienen perfiles runtime distintos; no se rota.');
        }
    }

    /** Los comandos publicados conservan su shell y las asignaciones USER_ID. */
    public function environment(string $command): string
    {
        return 'export COMPOSER=composer.vps.json; php() { /usr/bin/php8.4 "$@"; }; '
            . 'composer() { /usr/bin/php8.4 /usr/local/bin/composer "$@"; }; ' . $command;
    }

    public function composer(string $directory, bool $skipScripts): string
    {
        return 'cd ' . $this->quote($directory)
            . ' && COMPOSER=composer.vps.json COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1 '
            . self::PHP . ' ' . self::COMPOSER
            . ' install --no-dev --optimize-autoloader --no-interaction --no-ansi'
            . ($skipScripts ? ' --no-scripts' : '') . ' 2>&1';
    }

    /** Gramática v1 de comandos publicados: USER_ID entero opcional, php artisan y &&. */
    public function assertPublishedCommand(string $command): void
    {
        if ($command === '' || preg_match('/[$`;|<>\r\n()]/', $command)) {
            throw new \RuntimeException('Comando publicado no soportado por el perfil VPS: usá php artisan, USER_ID entero opcional y &&.');
        }
        $clauses = [];
        $tokens = [];
        $token = '';
        $quote = '';
        $length = strlen($command);
        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                } elseif ($char === '\\' && $quote === '"' && $i + 1 < $length) {
                    $token .= $command[++$i];
                } else {
                    $token .= $char;
                }
            } elseif ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '&') {
                if ($i + 1 >= $length || $command[++$i] !== '&') {
                    throw new \RuntimeException('Sólo se permite && entre comandos publicados del perfil VPS.');
                }
                if ($token !== '') { $tokens[] = $token; $token = ''; }
                $clauses[] = $tokens;
                $tokens = [];
            } elseif (ctype_space($char)) {
                if ($token !== '') { $tokens[] = $token; $token = ''; }
            } elseif ($char === '\\' && $i + 1 < $length) {
                $token .= $command[++$i];
            } else {
                $token .= $char;
            }
        }
        if ($quote !== '') {
            throw new \RuntimeException('Comillas sin cerrar en un comando publicado del perfil VPS.');
        }
        if ($token !== '') { $tokens[] = $token; }
        $clauses[] = $tokens;
        foreach ($clauses as $clause) {
            if (isset($clause[0]) && preg_match('/^USER_ID=[0-9]+$/D', $clause[0])) {
                array_shift($clause);
            }
            if (($clause[0] ?? '') !== 'php' || ($clause[1] ?? '') !== 'artisan'
                || ! isset($clause[2]) || ! preg_match('/^[A-Za-z0-9:_-]+$/D', $clause[2])) {
                throw new \RuntimeException('Comando publicado no soportado por el perfil VPS: sólo php artisan, sin Composer, env ni shells anidados.');
            }
        }
    }

    public function assertRuntime(callable $exec, array $extensions): void
    {
        $requirements = $extensions;
        $extensions = $requirements['extensions'];
        $code = 'echo json_encode(["php"=>PHP_VERSION,"extensions"=>get_loaded_extensions()]);';
        $probe = json_decode(trim($exec(self::PHP . ' -r ' . $this->quote($code))), true);
        if (! is_array($probe) || ! isset($probe['php'], $probe['extensions'])
            || version_compare($probe['php'], '8.4.27', '<')
            || version_compare($probe['php'], '8.5.0', '>=')) {
            throw new \RuntimeException('El perfil requiere PHP 8.4.27 o superior dentro de 8.4.');
        }
        $loaded = array_map('strtolower', $probe['extensions']);
        foreach ($extensions as $extension) {
            if (! in_array(strtolower($extension), $loaded, true)) {
                throw new \RuntimeException('Falta la extensión PHP del perfil: ' . $extension);
            }
        }
        $composer = $exec(self::PHP . ' ' . self::COMPOSER . ' --version --no-ansi');
        if (strpos($composer, 'Composer version 2.') === false) {
            throw new \RuntimeException('Composer 2 no está disponible con PHP 8.4.');
        }
        // Composer verifica las restricciones reales del lock sin instalar, plugins ni red.
        // Sólo se escribe un directorio temporal fuera del sistema vivo y siempre se elimina.
        $lock = $this->quote(base64_encode(json_encode($requirements['platform-lock'])));
        $manifest = $this->quote(base64_encode('{"require":{}}'));
        $platform = $exec('runtime_tmp=$(mktemp -d) && ( trap \'rm -rf -- "$runtime_tmp"\' EXIT; '
            . 'printf %s ' . $lock . ' | base64 -d > "$runtime_tmp/composer.vps.lock" && '
            . 'printf %s ' . $manifest . ' | base64 -d > "$runtime_tmp/composer.vps.json" && '
            . 'cd "$runtime_tmp" && COMPOSER=composer.vps.json COMPOSER_ALLOW_SUPERUSER=1 '
            . self::PHP . ' ' . self::COMPOSER . ' --no-plugins --no-scripts check-platform-reqs --lock --no-dev --no-ansi '
            . '&& printf CC_RUNTIME_OK )');
        if (substr(trim($platform), -13) !== 'CC_RUNTIME_OK') {
            throw new \RuntimeException('Los requisitos de plataforma del lock VPS no pasaron el preflight.');
        }
    }

    /** Lee únicamente los cuatro JSON del tag, sin extraer ni ejecutar el paquete. */
    public function assertZip(string $path, bool $source = false): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Falta ZipArchive: no se puede validar el perfil antes del despliegue.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo abrir el ZIP del perfil.');
        }
        try {
            $prefix = $source ? explode('/', $zip->getNameIndex(0))[0] . '/' : '';
            $files = [];
            foreach (['composer.json', 'composer.lock', 'composer.vps.json', 'composer.vps.lock'] as $name) {
                $content = $zip->getFromName($prefix . $name);
                if ($content === false && ! $source) {
                    $content = $zip->getFromName('./' . $name);
                }
                if ($content === false) {
                    throw new \RuntimeException('El release no trae ' . $name . '; no admite el perfil VPS.');
                }
                $files[$name] = $content;
            }
            return $this->assertManifests($files);
        } finally {
            $zip->close();
        }
    }

    public function canonicalHash(string $json): string
    {
        $value = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_object($value)) {
            throw new \RuntimeException('JSON inválido en el contrato del perfil.');
        }
        return hash('sha256', json_encode($this->canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonical($value)
    {
        if (is_object($value)) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $result = new \stdClass();
            foreach ($properties as $key => $item) {
                $result->$key = $this->canonical($item);
            }
            return $result;
        }
        if (is_array($value)) {
            return array_map([$this, 'canonical'], $value);
        }
        return $value;
    }

    public function assertManifests(array $files): array
    {
        $decoded = [];
        foreach (['composer.json', 'composer.lock', 'composer.vps.json', 'composer.vps.lock'] as $name) {
            if (! isset($files[$name]) || ! is_string($files[$name])) {
                throw new \RuntimeException('Falta ' . $name . ' en el contrato del perfil.');
            }
        }
        foreach ($files as $name => $json) {
            $decoded[$name] = json_decode($json, true);
            if (! is_array($decoded[$name]) || json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('JSON inválido en ' . $name);
            }
        }
        $profile = $decoded['composer.vps.json'];
        $lock = $decoded['composer.vps.lock'];
        $metadata = $profile['extra']['comerciocity'] ?? [];
        if (($metadata['runtime-profile'] ?? null) !== self::PROFILE
            || ($metadata['base-manifest-sha256'] ?? '') !== $this->canonicalHash($files['composer.json'])
            || ($metadata['base-lock-sha256'] ?? '') !== $this->canonicalHash($files['composer.lock'])
            || ($profile['scripts'] ?? []) !== ($decoded['composer.json']['scripts'] ?? [])
            || ($profile['config']['platform']['php'] ?? '') !== '8.4.27'
            || ($profile['require']['php'] ?? '') !== '>=8.4.27 <8.5'
            || ($profile['require']['openspout/openspout'] ?? '') !== '4.32.0') {
            throw new \RuntimeException('El perfil VPS diverge del Composer normal o no cumple el contrato.');
        }
        // Mismo algoritmo público de Composer Locker::getContentHash().
        $relevant = array_intersect_key($profile, array_flip(['name', 'version', 'require', 'require-dev',
            'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra']));
        if (isset($profile['config']['platform'])) {
            $relevant['config']['platform'] = $profile['config']['platform'];
        }
        ksort($relevant);
        if (($lock['content-hash'] ?? '') !== md5(json_encode($relevant))) {
            throw new \RuntimeException('composer.vps.lock no corresponde al manifiesto VPS.');
        }
        $this->assertAllowedChanges($decoded);
        $extensions = [];
        $requirements = [$profile['require'] ?? []];
        $spout = false;
        foreach ($lock['packages'] ?? [] as $package) {
            $requirements[] = $package['require'] ?? [];
            if (($package['name'] ?? '') === 'openspout/openspout') {
                $spout = ($package['version'] ?? '') === 'v4.32.0' || ($package['version'] ?? '') === '4.32.0';
            }
        }
        if (! $spout || ($lock['platform']['php'] ?? '') !== '>=8.4.27 <8.5') {
            throw new \RuntimeException('El lock VPS no fija OpenSpout 4.32.0 y PHP 8.4.');
        }
        foreach ($requirements as $require) {
            foreach ($require as $name => $constraint) {
                if (strpos($name, 'ext-') === 0) {
                    $extensions[] = substr($name, 4);
                }
            }
        }
        $platformPackages = [];
        foreach ($lock['packages'] ?? [] as $package) {
            $platformPackages[] = array_intersect_key($package, array_flip(['name', 'version', 'require', 'provide', 'replace', 'type']));
        }
        return ['extensions' => array_values(array_unique($extensions)), 'platform-lock' => [
            'packages' => $platformPackages, 'packages-dev' => [], 'platform' => $lock['platform'] ?? [],
            'platform-dev' => [], 'plugin-api-version' => $lock['plugin-api-version'] ?? '2.0.0',
        ]];
    }

    /** v1 permite exclusivamente los tres paquetes probados y las cuatro excepciones Linux. */
    private function assertAllowedChanges(array $files): void
    {
        $allowed = $files['composer.json'];
        $allowed['require']['php'] = '>=8.4.27 <8.5';
        $allowed['require']['openspout/openspout'] = '4.32.0';
        $allowed['config']['platform']['php'] = '8.4.27';
        $allowed['autoload']['classmap'] = array_values(array_unique(array_merge(
            $allowed['autoload']['classmap'] ?? [], [
                'app/Http/Controllers/Pdf/reportes/InventarioPdf.php',
                'app/Http/Controllers/Pdf/reportes/ClientesPdf.php',
                'app/Http/Controllers/Pdf/reportes/ReportePdf.php',
                'app/Models/MeLiBuyer.php',
            ]
        )));
        $metadata = $files['composer.vps.json']['extra']['comerciocity'];
        $allowed['extra']['comerciocity'] = array_merge($allowed['extra']['comerciocity'] ?? [], [
            'runtime-profile' => self::PROFILE,
            'base-manifest-sha256' => $metadata['base-manifest-sha256'],
            'base-lock-sha256' => $metadata['base-lock-sha256'],
        ]);
        if ($allowed !== $files['composer.vps.json']) {
            throw new \RuntimeException('El manifiesto VPS cambia campos fuera de la lista permitida del perfil.');
        }
        $base = [];
        $profile = [];
        foreach ($files['composer.lock']['packages'] ?? [] as $package) {
            $base[$package['name']] = $package;
        }
        foreach ($files['composer.vps.lock']['packages'] ?? [] as $package) {
            $profile[$package['name']] = $package;
        }
        $pins = ['nette/schema' => 'v1.4.0', 'nette/utils' => 'v4.1.6', 'openspout/openspout' => 'v4.32.0'];
        if (array_keys($base) !== array_keys($profile)) {
            throw new \RuntimeException('El lock VPS agrega o elimina paquetes fuera del contrato.');
        }
        foreach ($pins as $name => $version) {
            if (($profile[$name]['version'] ?? null) !== $version) {
                throw new \RuntimeException('El lock VPS no fija la versión aprobada de ' . $name);
            }
        }
        foreach ($base as $name => $package) {
            if (! isset($pins[$name]) && $package !== $profile[$name]) {
                throw new \RuntimeException('El lock VPS modifica un paquete no permitido: ' . $name);
            }
        }
        if (($files['composer.lock']['packages-dev'] ?? []) !== ($files['composer.vps.lock']['packages-dev'] ?? [])
            || ($files['composer.vps.lock']['platform-overrides']['php'] ?? null) !== '8.4.27') {
            throw new \RuntimeException('El lock VPS cambió dependencias de desarrollo o su plataforma aprobada.');
        }
    }
}

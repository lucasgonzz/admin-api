<?php

namespace App\Services;

/**
 * Cliente de la API pública de Hostinger (developers.hostinger.com): subdominios, bases de datos,
 * cronjobs y zonas DNS de la cuenta de shared hosting.
 *
 * Es TRANSPORTE PURO: sabe qué endpoints existen y qué payload lleva cada uno, y nada más. No
 * conoce slugs, ni clientes, ni instalaciones — toda la lógica de negocio del aprovisionamiento
 * (las guardas de derivación, la lista blanca del DNS, la idempotencia) vive en las clases de
 * HostingProvisioning*. El transporte HTTP propiamente dicho está en la clase padre,
 * HostingerHttpTransport, que es también la costura que falsean los tests.
 *
 * Por qué existe: en el shared hosting de Hostinger los crons NO se pueden editar por SSH (no hay
 * binario `crontab` y /var/spool/cron/ está vacío adentro del CageFS; verificado el 25/8/2026
 * limpiando los cronjobs de producción). Lo mismo pasa con los subdominios, las bases y la zona
 * DNS: la única vía programática es esta API.
 *
 * 🔴 De dónde salió. La lógica de crons está portada de
 * `claude-comerciocity/herramientas/crons-hostinger/HostingerCronClient.php`, pero el TRANSPORTE no:
 * ese archivo usa curl pelado con un `C:/wamp64/bin/php/cacert.pem` hardcodeado, que es una ruta de
 * la máquina de Lucas y no existe en el hosting. Acá el transporte es `Http::` con verify_ssl y
 * ca_bundle de config, que es el patrón del repo (SubdomainSuggestionService::build_http_client()).
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class HostingerApiClient extends HostingerHttpTransport
{
    /**
     * Sonda barata del token: un GET /cron-jobs, que es lectura pura y la llamada más liviana de
     * toda la API.
     *
     * Existe para que una corrida sin token válido muera en el preflight y no a la mitad, con
     * subdominios creados y una base a medias.
     *
     * @return void
     * @throws \RuntimeException Con el texto exacto que el operador tiene que ejecutar.
     */
    public function probar_token(): void
    {
        if (! $this->token_configurado()) {
            throw new \RuntimeException(self::MENSAJE_SIN_TOKEN);
        }

        try {
            $this->list_crons();
        } catch (\RuntimeException $excepcion) {
            $codigo = (int) $excepcion->getCode();

            if ($codigo === 401 || $codigo === 403) {
                throw new \RuntimeException(self::MENSAJE_TOKEN_RECHAZADO, $codigo);
            }

            throw $excepcion;
        }
    }

    /**
     * Lista todos los cronjobs de la cuenta.
     *
     * Ojo: la cuenta es una sola y los cronjobs de TODOS los clientes conviven en la misma lista.
     * No hay forma de filtrar por cliente del lado de la API: se filtra por la ruta que aparece en
     * el `command` (ver crons_for_api_path()).
     *
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function list_crons(): array
    {
        return $this->request('GET', $this->ruta_cuenta('/cron-jobs'));
    }

    /**
     * Crea un cronjob.
     *
     * @param  string  $time     Expresión cron (ej: '* * * * *').
     * @param  string  $command  Comando completo, con rutas absolutas.
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function create_cron(string $time, string $command): array
    {
        return $this->request('POST', $this->ruta_cuenta('/cron-jobs'), [
            'time'    => $time,
            'command' => $command,
        ]);
    }

    /**
     * Borra un cronjob por su uid.
     *
     * @param  string  $uid
     * @return void
     * @throws \RuntimeException
     */
    public function delete_cron(string $uid): void
    {
        $this->request('DELETE', $this->ruta_cuenta('/cron-jobs/' . rawurlencode($uid)));
    }

    /**
     * Devuelve los cronjobs cuyo comando apunta a una ruta de API determinada.
     *
     * Portado tal cual de HostingerCronClient: la aguja es '/<api_path>/artisan', o sea la ruta
     * seguida del binario, y no la ruta sola. Con la ruta sola, 'colman/api' matchearía también los
     * crons de 'colman/api2'.
     *
     * @param  string  $api_path  Ruta relativa de la API, tal como la devuelve
     *                            ClientApiPathResolver::resolve() (ej:
     *                            'domains/comerciocity.com/public_html/colman/api').
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException
     */
    public function crons_for_api_path(string $api_path): array
    {
        $aguja       = '/' . trim($api_path, '/') . '/artisan';
        $encontrados = [];

        foreach ($this->list_crons() as $cron) {
            if (! is_array($cron) || ! isset($cron['command'])) {
                continue;
            }

            if (strpos((string) $cron['command'], $aguja) !== false) {
                $encontrados[] = $cron;
            }
        }

        return $encontrados;
    }

    /**
     * ¿El comando es de procesamiento de cola (scheduler o worker)?
     *
     * Portado tal cual. Deliberadamente NO incluye los comandos de negocio que están como cron
     * directo (set_company_performances, check_stocks, etc.): esos no están en el Kernel.php, así
     * que tratarlos como "cron de cola" y reemplazarlos apagaría funcionalidad sin reemplazo.
     *
     * @param  string  $command
     * @return bool
     */
    public function es_cron_de_cola(string $command): bool
    {
        return strpos($command, 'schedule:run') !== false
            || strpos($command, 'queue:work') !== false;
    }

    /**
     * Lista los subdominios del dominio de config.
     *
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function list_subdomains(): array
    {
        return $this->request('GET', $this->ruta_subdominios());
    }

    /**
     * Crea un subdominio.
     *
     * Los tres datos se reciben ya resueltos: el que decide el directorio y el flag de public es el
     * servicio de aprovisionamiento, leyendo services.hostinger.subdomain_* (§3.2 del plan). Acá no
     * se arma ninguno de los dos, justamente para que el contrato quede en un solo lugar.
     *
     * @param  string  $subdomain                  Label pelado, sin el dominio (ej: 'api-lacava').
     * @param  string  $directory                  Directorio destino, ya armado.
     * @param  bool    $is_using_public_directory  Si el docroot es la subcarpeta public/ del destino.
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function create_subdomain(string $subdomain, string $directory, bool $is_using_public_directory): array
    {
        return $this->request('POST', $this->ruta_subdominios(), [
            'subdomain'                 => $subdomain,
            'directory'                 => $directory,
            'is_using_public_directory' => $is_using_public_directory,
        ]);
    }

    /**
     * Lista las bases de datos de la cuenta.
     *
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function list_databases(): array
    {
        return $this->request('GET', $this->ruta_cuenta('/databases'));
    }

    /**
     * Crea una base de datos con su usuario.
     *
     * 🔴 La API de Hostinger no tiene endpoint para leer ni resetear la contraseña de una base ya
     * creada. O sea: la contraseña que se manda acá es la única que va a existir. El llamador la
     * persiste cifrada en el instante siguiente a la respuesta exitosa y antes de cualquier otra
     * cosa (§3.2 del plan); si no lo hace, una caída posterior deja una base huérfana e
     * irrecuperable.
     *
     * @param  string  $name            Nombre de la base, con el prefijo de la cuenta ya puesto.
     * @param  string  $user            Usuario, con el prefijo de la cuenta ya puesto.
     * @param  string  $password        Contraseña generada.
     * @param  string  $website_domain  Dominio del sitio dueño de la base.
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function create_database(string $name, string $user, string $password, string $website_domain): array
    {
        return $this->request('POST', $this->ruta_cuenta('/databases'), [
            'name'           => $name,
            'user'           => $user,
            'password'       => $password,
            'website_domain' => $website_domain,
        ]);
    }

    /**
     * Trae la zona DNS del dominio de config.
     *
     * Es lectura pura, y en el hosting compartido es lo ÚNICO que se hace con el DNS: Hostinger
     * crea el A record solo al crear el subdominio, así que el paso provision_dns del shared
     * verifica que los cuatro nombres estén y nada más (guarda G1).
     *
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function get_dns_zone(): array
    {
        return $this->request('GET', $this->ruta_zona());
    }

    /**
     * Escribe registros en la zona DNS del dominio de config.
     *
     * 🔴 NO TIENE PARÁMETRO DE SOBREESCRITURA, Y ES A PROPÓSITO — es la guarda G6 del plan.
     *
     * Este PUT va sobre la zona donde viven los subdominios de los ~40 clientes activos. Con la
     * sobreescritura prendida, un registro existente se pisa: repuntar un A record es mover el
     * tráfico de producción de un cliente que hoy anda, y este paso solo sirve para dar de alta
     * clientes nuevos. Si un nombre ya existe apuntando a otra IP, lo correcto es que el PUT falle y
     * que el operador mire por qué, no que se sobreescriba en silencio.
     *
     * El plan pedía la firma `put_dns_zone(array $zone, bool $overwrite = false)`. Se sacó el
     * parámetro: mientras exista, alguien puede pasarle true desde otra clase y ninguna revisión de
     * este archivo lo detectaría. Sin parámetro, el literal es imposible de introducir sin editar
     * esta línea, que es exactamente donde el comentario está esperándolo.
     *
     * Las otras siete guardas (lista blanca de nombres, tope de cardinalidad, snapshot previo,
     * verificación posterior por diferencia de conjuntos) NO viven acá: son lógica de negocio y van
     * en el servicio de aprovisionamiento del VPS. Acá está solo la que es del transporte.
     *
     * @param  array<int, array<string, mixed>>  $zone  Registros a escribir, ya validados por el llamador.
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function put_dns_zone(array $zone): array
    {
        return $this->request('PUT', $this->ruta_zona(), [
            'overwrite' => false,
            'zone'      => array_values($zone),
        ]);
    }

    /**
     * Pide un snapshot de la zona DNS.
     *
     * Guarda G7: es la única forma de volver atrás de un PUT. Si el snapshot falla, no se escribe.
     * El id que devuelve se loguea en el panel de operaciones, porque es lo que una persona va a
     * necesitar tipear en hPanel a las tres de la mañana.
     *
     * @return array<int|string, mixed>
     * @throws \RuntimeException
     */
    public function create_dns_snapshot(): array
    {
        return $this->request('POST', '/api/dns/v1/snapshots/' . rawurlencode($this->dominio()));
    }

    /**
     * Path del recurso de subdominios del dominio de config.
     *
     * @return string
     */
    protected function ruta_subdominios(): string
    {
        return $this->ruta_cuenta('/websites/' . rawurlencode($this->dominio()) . '/subdomains');
    }

    /**
     * Path de la zona DNS del dominio de config.
     *
     * @return string
     */
    protected function ruta_zona(): string
    {
        return '/api/dns/v1/zones/' . rawurlencode($this->dominio());
    }
}

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
     * Tope de páginas que list_databases() recorre antes de rendirse.
     *
     * Con 25 filas por página son 500 bases, contra las ~74 que tiene la cuenta hoy (13/10/2026).
     * No es un límite "de diseño": es el freno que impide un bucle infinito si la API ignora `?page`
     * y devuelve siempre lo mismo. Y al llegar al tope NO se devuelve lo que se juntó, se LANZA: una
     * lista parcial haría creer que una base no existe, que es exactamente el bug que esta lectura
     * paginada vino a cerrar.
     *
     * @var int
     */
    const MAX_PAGINAS_DE_LISTA = 20;

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
     * ⚠️ El pipeline de aprovisionamiento NO lo llama: crea crons, no los borra. Se queda igual,
     * y el criterio es este: es la única forma programática de deshacer un cron creado por este
     * mismo cliente —en el hosting compartido no existe el binario `crontab` y /var/spool/cron
     * está vacío adentro del CageFS, así que esta llamada es el único camino—, y es el paso V2 del
     * runbook de migración (apagar los crons del cliente en el shared). Además es lo que fija por
     * test el escapado del uid en la ruta y que un 204 sin cuerpo no sea un error, que son dos
     * reglas del transporte que valen para todos los verbos.
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
     * Lista las bases de datos de la cuenta: la lista PLANA de filas de TODAS las páginas.
     *
     * 🔴 La API real no devuelve una lista pelada. Medido el 13/10/2026 contra la cuenta de
     * producción: `{"data":[...25 filas...],"meta":{"current_page":1,"per_page":25,"total":74}}`,
     * envuelta en `data` y paginada con `?page=N` (más allá de la última página responde 200 con
     * `data: []`). Este método leía la respuesta como si fuera la lista, así que el llamador
     * recorría `data` y `meta` como si fueran bases y NUNCA encontró ninguna: la idempotencia de la
     * base no funcionó jamás en producción. Y aunque hubiera desenvuelto `data`, habría visto solo
     * la página 1 de 3. (Los cronjobs y los subdominios sí vienen como lista pelada: ese es el
     * contraste que hace creíble suponer la forma sin mirarla.)
     *
     * Reglas de lectura, en orden:
     *   - La página 1 se pide SIN query string. No es capricho: es la ruta que ya fija
     *     HostingerApiClientTest, y pedir `?page=1` no aporta nada. Desde la 2 va `?page=N`.
     *   - Si la respuesta trae `data` como array se usa ese; si es una lista pelada (los fakes viejos
     *     de los tests, y por compatibilidad si Hostinger cambiara de forma) se usa tal cual y no se
     *     pagina: sin `meta` no hay de dónde saber que falta algo.
     *   - Se sigue pidiendo mientras `meta.total` y `meta.per_page` existan, falten filas por ver y
     *     la página recién leída haya traído alguna (ver hay_pagina_siguiente()).
     *   - Al llegar a MAX_PAGINAS_DE_LISTA con páginas todavía pendientes se LANZA, no se devuelve
     *     lo juntado hasta ahí.
     *
     * Se pagina con el tamaño por defecto y no se sube `per_page`: la API lo respeta (se midió con
     * 100), pero no hay un máximo documentado y no hace falta apostar a uno.
     *
     * @return array<int, mixed>  Filas de todas las páginas, en el orden en que las devolvió la API.
     * @throws \RuntimeException Si una lectura falla, o si la lista pasa del tope de páginas.
     */
    public function list_databases(): array
    {
        $ruta   = $this->ruta_cuenta('/databases');
        $filas  = [];
        $pagina = 1;

        while (true) {
            $respuesta    = $this->request('GET', $pagina === 1 ? $ruta : $ruta . '?page=' . $pagina);
            $de_la_pagina = $this->filas_de_la_respuesta($respuesta);
            $filas        = array_merge($filas, $de_la_pagina);

            if (! $this->hay_pagina_siguiente($respuesta, $pagina, $de_la_pagina)) {
                return $filas;
            }

            if ($pagina >= self::MAX_PAGINAS_DE_LISTA) {
                $meta = $respuesta['meta'];

                throw new \RuntimeException(
                    'La lista de bases de datos de Hostinger pasa del tope de ' . self::MAX_PAGINAS_DE_LISTA
                    . ' páginas (meta.total=' . (int) $meta['total'] . ', meta.per_page='
                    . (int) $meta['per_page'] . ') y se leyeron ' . count($filas) . ' filas. No se '
                    . 'devuelve una lista parcial: con ella una base que existe parecería no existir y '
                    . 'se intentaría crear de nuevo.',
                    0
                );
            }

            $pagina++;
        }
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
     * Lista los snapshots que Hostinger tiene de la zona DNS.
     *
     * 🔴 ANTES ESTO PEDÍA UN SNAPSHOT CON POST, Y ESE ENDPOINT NO EXISTE. Medido el 10/9/2026
     * instalando `pescamayorista`: `POST /api/dns/v1/snapshots/comerciocity.com` devuelve **405**,
     * *"The POST method is not supported for this route. Supported methods: GET, HEAD"*. La guarda
     * G7 exigía algo que la API nunca ofreció, así que provision_dns fallaba SIEMPRE — igual que el
     * docroot, esta etapa tampoco había corrido nunca de verdad.
     *
     * Lo que sí hace Hostinger es tomar el snapshot **solo, en cada escritura de la zona**. Se ve en
     * la lista: cada entrada trae `reason` con el pedido que la originó (`Zone records update
     * request`, `Zone records delete request`) y su `created_at`. O sea que el respaldo del PUT
     * existe igual; lo que hay que hacer no es pedirlo, es saber identificarlo después.
     *
     * @return array<int, array<string, mixed>>  Snapshots, los más nuevos primero.
     * @throws \RuntimeException
     */
    public function list_dns_snapshots(): array
    {
        $respuesta = $this->request('GET', '/api/dns/v1/snapshots/' . rawurlencode($this->dominio()));

        return is_array($respuesta) ? array_values($respuesta) : [];
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

    /**
     * Las filas de UNA respuesta de lista, la venga envuelta en `data` o pelada.
     *
     * @param  array<int|string, mixed>  $respuesta
     * @return array<int, mixed>
     */
    protected function filas_de_la_respuesta(array $respuesta): array
    {
        if (isset($respuesta['data']) && is_array($respuesta['data'])) {
            return array_values($respuesta['data']);
        }

        return array_values($respuesta);
    }

    /**
     * ¿Falta leer otra página después de ésta?
     *
     * Solo con `meta.total` y `meta.per_page` presentes y numéricos: sin ellos la respuesta es una
     * sola página, y asumir lo contrario dejaría a los fakes viejos (y a una API que dejara de
     * paginar) en un bucle de peticiones. Una página vacía corta siempre, aunque `meta` diga que
     * faltan: si la API se contradice, seguir pidiendo no la va a arreglar.
     *
     * @param  array<int|string, mixed>  $respuesta        La página recién leída, tal cual vino.
     * @param  int                       $pagina           Número de esa página (la primera es 1).
     * @param  array<int, mixed>         $filas_de_la_pagina
     * @return bool
     */
    protected function hay_pagina_siguiente(array $respuesta, int $pagina, array $filas_de_la_pagina): bool
    {
        if ($filas_de_la_pagina === [] || ! isset($respuesta['meta']) || ! is_array($respuesta['meta'])) {
            return false;
        }

        $meta = $respuesta['meta'];

        if (! isset($meta['total'], $meta['per_page']) || ! is_numeric($meta['total'])
            || ! is_numeric($meta['per_page']) || (int) $meta['per_page'] < 1) {
            return false;
        }

        return $pagina * (int) $meta['per_page'] < (int) $meta['total'];
    }
}

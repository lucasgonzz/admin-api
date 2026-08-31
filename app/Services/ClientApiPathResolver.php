<?php

namespace App\Services;

use App\Models\ClientApi;

/**
 * Resuelve el directorio raíz de la API de un cliente en su servidor, según el hosting_type.
 *
 * Único lugar donde vive esa regla. Estaba adentro de DeploymentService, que es donde nació, pero
 * ahora también la necesita el comando que audita los certificados de AFIP de los clientes ya
 * instalados. Dos copias de la misma convención de rutas es exactamente lo que después diverge sin
 * que nadie lo note, así que se extrajo acá y DeploymentService delega.
 *
 * Desde el 31/8/2026 también resuelve el directorio del SPA y el dueño de los archivos en el VPS,
 * porque InstallationService dejó de asumir hosting compartido (U9). Esa convención estaba
 * duplicada adentro de DeploymentService::get_spa_path()/get_spa_hosting_dir(); ese archivo se
 * terminó tocando igual en esta misma misión —para meterle la guarda del borrado— así que la
 * duplicación se cerró ahí mismo: los dos métodos de allá ahora delegan acá.
 *
 * 🔴 Y no era una duplicación inofensiva: assert_directorio_de_spa_borrable() valida el string
 * que le pasan y no lo recalcula, así que con dos copias divergentes la guarda pasa igual mientras
 * el `find -delete` corre sobre el directorio que calculó la copia mala.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class ClientApiPathResolver
{
    /**
     * Prefijo de la cuenta de hosting compartido, relativo al home del usuario SSH.
     *
     * Está como constante y no suelto en cinco strings porque, sin la barra del final, ES la raíz de
     * la cuenta compartida: el directorio que la guarda de más abajo tiene prohibido dejar borrar.
     *
     * @var string
     */
    const PREFIJO_SHARED = 'domains/comerciocity.com/public_html/';

    /**
     * Directorio raíz de la API del cliente en su servidor.
     *
     * - shared_hosting: prefijo de Hostinger + path relativo de la API
     *   (ej: domains/comerciocity.com/public_html/colman/api).
     * - vps: path absoluto construido como /home/api-{vps_path}/empresa-api.
     *
     * @param  ClientApi  $client_api
     * @return string
     * @throws \RuntimeException Si es un VPS sin vps_path configurado.
     */
    public function resolve(ClientApi $client_api): string
    {
        $hosting_type = $client_api->hosting_type ?? 'shared_hosting';

        if ($hosting_type === 'vps') {
            $vps_path = trim((string) ($client_api->vps_path ?? ''));
            if ($vps_path === '') {
                throw new \RuntimeException(
                    'La ClientApi tiene hosting_type=vps pero no tiene vps_path configurado. '
                    . 'Completá el campo vps_path antes de deployar.'
                );
            }

            return '/home/api-' . $vps_path . '/empresa-api';
        }

        return self::PREFIJO_SHARED . $client_api->path;
    }

    /**
     * Ruta del SPA de esa API, en la misma forma en que la devuelve resolve() para la API.
     *
     * - shared_hosting: ruta RELATIVA al home del usuario SSH, derivada del path de la API
     *   (colman/api → colman/spa). Para tener el directorio donde se despliega de verdad hay que
     *   anteponerle el prefijo: eso es spa_hosting_dir().
     * - vps: path ABSOLUTO /home/{vps_path}/htdocs/{dominio_del_spa}. El dominio sale de spa_url y
     *   no del vps_path porque el sitio de CloudPanel se creó con ese nombre exacto (§F2 del
     *   informe de migración del 26/8/2026).
     *
     * @param  ClientApi  $client_api
     * @return string
     * @throws \RuntimeException Si es un VPS sin vps_path o sin spa_url.
     */
    public function resolve_spa(ClientApi $client_api): string
    {
        $hosting_type = $client_api->hosting_type ?? 'shared_hosting';

        if ($hosting_type === 'vps') {
            $vps_path = trim((string) ($client_api->vps_path ?? ''));
            if ($vps_path === '') {
                throw new \RuntimeException(
                    'La ClientApi tiene hosting_type=vps pero no tiene vps_path configurado, y de '
                    . 'ahí sale el directorio del SPA. Completá el campo vps_path antes de instalar '
                    . 'o de actualizar a este cliente.'
                );
            }

            $spa_url    = trim((string) ($client_api->spa_url ?? ''));
            $spa_domain = (string) preg_replace('#^https?://#', '', rtrim($spa_url, '/'));
            if ($spa_domain === '') {
                throw new \RuntimeException(
                    'La ClientApi tiene hosting_type=vps pero no tiene spa_url configurada, y el '
                    . 'docroot del SPA en el VPS es /home/<vps_path>/htdocs/<dominio del SPA>. '
                    . 'Completá el campo spa_url antes de instalar o de actualizar a este cliente.'
                );
            }

            return '/home/' . $vps_path . '/htdocs/' . $spa_domain;
        }

        return str_replace('/api', '/spa', (string) $client_api->path);
    }

    /**
     * Directorio donde se despliega el SPA, listo para un `cd` por SSH.
     *
     * En VPS resolve_spa() ya devuelve un path absoluto y no lleva prefijo; en compartido hay que
     * anteponer el de la cuenta. Es el único método que hay que usar para armar un comando remoto:
     * concatenar el prefijo a mano es exactamente lo que hacía InstallationService y lo que dejaba
     * el `find -delete` apuntando a la raíz de la cuenta compartida.
     *
     * @param  ClientApi  $client_api
     * @return string
     * @throws \RuntimeException
     */
    public function spa_hosting_dir(ClientApi $client_api): string
    {
        if (($client_api->hosting_type ?? 'shared_hosting') === 'vps') {
            return $this->resolve_spa($client_api);
        }

        return self::PREFIJO_SHARED . $this->resolve_spa($client_api);
    }

    /**
     * Usuario de sistema dueño de los archivos de la API en el VPS, o '' si no es un VPS.
     *
     * En CloudPanel cada sitio tiene su propio usuario y php-fpm corre como él (§F6 del informe de
     * migración): sin un chown posterior a la subida, el código queda de root y el sistema del
     * cliente no puede escribir en storage/. En el hosting compartido no existe el problema —el
     * usuario SSH es el dueño de todo— y por eso devuelve vacío en vez de inventar un nombre.
     *
     * @param  ClientApi  $client_api
     * @return string  'api-<vps_path>' en VPS, '' en hosting compartido.
     * @throws \RuntimeException Si es un VPS sin vps_path configurado.
     */
    public function vps_site_user(ClientApi $client_api): string
    {
        if (($client_api->hosting_type ?? 'shared_hosting') !== 'vps') {
            return '';
        }

        $vps_path = trim((string) ($client_api->vps_path ?? ''));
        if ($vps_path === '') {
            throw new \RuntimeException(
                'La ClientApi tiene hosting_type=vps pero no tiene vps_path configurado, y de ahí '
                . 'sale el usuario del sitio de CloudPanel. Completá el campo vps_path.'
            );
        }

        return 'api-' . $vps_path;
    }

    /**
     * 🔴 ÚLTIMA LÍNEA ANTES DE UN BORRADO RECURSIVO. Frena si el directorio que se está por vaciar
     * no es, sin lugar a dudas, el del SPA de ESTE cliente.
     *
     * EL INCIDENTE QUE EVITA. El despliegue del SPA hace `cd "$SPA_DIR"` y después
     * `find . -mindepth 1 -delete`. Hasta el 31/8/2026 ese directorio se armaba concatenando
     * 'domains/comerciocity.com/public_html/' con str_replace('/api','/spa', $path). Con una
     * ClientApi de VPS y `path` vacío —que es exactamente cómo quedaron los clientes 43 y 13 en la
     * migración, según §2.5 del informe— la cuenta daba la RAÍZ de la cuenta compartida, y ese
     * find vaciaba el public_html entero: las carpetas de los ~40 clientes activos, de una.
     *
     * 🔴 No alcanza con que el resolver esté bien. Esta guarda existe justamente para el día en que
     * el resolver devuelva mal: es barata, es mecánica y es lo único que se interpone entre un
     * `vps_path` en blanco y el borrado. No la saques porque "el path ya viene resuelto".
     *
     * @param  ClientApi  $client_api
     * @param  string     $dir  El directorio que el comando remoto va a vaciar.
     * @return void
     * @throws \RuntimeException Si el directorio no es identificable como el de este cliente.
     */
    public function assert_directorio_de_spa_borrable(ClientApi $client_api, string $dir): void
    {
        $dir_limpio = rtrim(trim($dir), '/');

        /* Vacío o la raíz del filesystem. */
        if ($dir_limpio === '') {
            throw new \RuntimeException($this->motivo_de_freno($client_api, $dir, 'está vacío'));
        }

        /* La raíz de la cuenta compartida y la de los homes del VPS: adentro viven TODOS. */
        $raices = [rtrim(self::PREFIJO_SHARED, '/'), '/home', 'domains', 'domains/comerciocity.com'];
        if (in_array($dir_limpio, $raices, true)) {
            throw new \RuntimeException(
                $this->motivo_de_freno($client_api, $dir, 'es un directorio raíz compartido')
            );
        }

        /*
         * El identificador del cliente tiene que estar ADENTRO de la ruta. En compartido es el
         * primer segmento del path (el de 'colman/api' es 'colman'), en VPS es el vps_path. Si el
         * dato está vacío no hay con qué identificar el directorio y se frena: es el caso del
         * incidente.
         */
        $identificador = $this->identificador_de_cliente($client_api);
        if ($identificador === '') {
            throw new \RuntimeException(
                $this->motivo_de_freno(
                    $client_api,
                    $dir,
                    'la ClientApi no tiene ni path ni vps_path, así que no hay con qué identificarlo'
                )
            );
        }

        if (strpos($dir_limpio, $identificador) === false) {
            throw new \RuntimeException(
                $this->motivo_de_freno(
                    $client_api,
                    $dir,
                    'no contiene el identificador "' . $identificador . '" de este cliente'
                )
            );
        }
    }

    /**
     * Lo que identifica al cliente adentro de la ruta de su SPA.
     *
     * @param  ClientApi  $client_api
     * @return string  '' si no hay ninguno cargado.
     */
    private function identificador_de_cliente(ClientApi $client_api): string
    {
        if (($client_api->hosting_type ?? 'shared_hosting') === 'vps') {
            return trim((string) ($client_api->vps_path ?? ''));
        }

        $path = trim((string) ($client_api->path ?? ''), '/ ');
        if ($path === '') {
            return '';
        }

        $segmentos = explode('/', $path);

        return (string) $segmentos[0];
    }

    /**
     * Mensaje único de la guarda: dice qué se frenó, por qué y qué mirar.
     *
     * @param  ClientApi  $client_api
     * @param  string     $dir
     * @param  string     $motivo
     * @return string
     */
    private function motivo_de_freno(ClientApi $client_api, string $dir, string $motivo): string
    {
        return 'FRENADO ANTES DE BORRAR: el directorio del SPA calculado para la ClientApi '
            . (int) $client_api->id . ' (' . $motivo . ') no se puede vaciar. Ruta calculada: "'
            . $dir . '". El despliegue del SPA le corre un `find . -mindepth 1 -delete` adentro, así '
            . 'que con una ruta mal resuelta borraría los archivos de todos los clientes de ese '
            . 'servidor. Revisá path / vps_path / spa_url de esa ClientApi antes de reintentar.';
    }

    /**
     * Tipo de credencial SSH/SFTP con la que se llega a esa API.
     *
     * @param  ClientApi  $client_api
     * @return string  'shared_hosting' | 'vps'
     */
    public function credential_type(ClientApi $client_api): string
    {
        return ($client_api->hosting_type ?? 'shared_hosting') === 'vps' ? 'vps' : 'shared_hosting';
    }
}

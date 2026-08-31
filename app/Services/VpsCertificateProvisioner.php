<?php

namespace App\Services;

/**
 * La base común del aprovisionamiento del VPS —lo que todas sus etapas necesitan— más la última
 * etapa de todo el pipeline: el certificado de Let's Encrypt.
 *
 * 🔴 Por qué el VPS está partido en varios archivos. La regla R2 del plan (§9) fija un techo de 450
 * líneas por archivo nuevo de app/Services/, y §1.1 ya proyectaba VpsHostingProvisioning en ~380
 * con una densidad de comentarios que la medición de A4 mostró que es ~2,4 veces menor que la real
 * del repo. La acción prescrita para R2 es partir la clase, así que se partió por lo que el VPS
 * hace y que no comparte nada entre sí:
 *
 *   VpsCertificateProvisioner  (esto)  → infraestructura común del VPS + provision_ssl
 *   VpsSiteProvisioner                 → provision_check + provision_sites
 *   VpsDatabaseProvisioner             → provision_db + provision_cron
 *   VpsHostingProvisioning             → provision_dns, el PUT con las 8 guardas de §5
 *
 * El DNS quedó en la clase concreta, la que nombra la fábrica, a propósito: es la parte
 * irreversible de toda la misión y tiene que ser lo primero que encuentre quien abra el archivo.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
abstract class VpsCertificateProvisioner extends HostingProvisioningService
{
    /**
     * Plantilla de vhost de CloudPanel para todo sitio del ecosistema.
     *
     * 'Generic' y no 'Laravel 12': lo dicta el patrón de los 9 sitios que ya están arriba (§F2 del
     * informe de migración). Las plantillas de Laravel de CloudPanel arman el docroot como
     * htdocs/<dominio>/public, y acá el docroot ES un symlink a empresa-api/public.
     *
     * @var string
     */
    const VHOST_TEMPLATE = 'Generic';

    /**
     * Versión de PHP de los sitios del cliente. empresa-api corre en 7.4 (restricción del repo).
     *
     * @var string
     */
    const PHP_VERSION = '7.4';

    /**
     * Segundos entre dos sondas de propagación del DNS.
     *
     * @var int
     */
    const INTERVALO_DE_SONDA = 15;

    /**
     * Pide el certificado de los 4 dominios. Es el último paso de todo el pipeline y ES FATAL.
     *
     * 🔴 Fatal a propósito: un cliente cuyo SPA no puede hablar HTTPS con su API no está instalado,
     * y marcarlo 'completada' sería mentir. La alternativa —terminar en verde con un warning— se
     * descartó porque get_step_status() pinta TODO en verde cuando el estado es 'completada': ahí
     * un warning es invisible (§10.10 del plan).
     *
     * ⚠️ Y va DESPUÉS de upload_spa, no antes: upload_spa hace `find . -mindepth 1 -delete` sobre el
     * docroot del SPA, que en el VPS es htdocs/<slug>.comerciocity.com — el mismo lugar donde Let's
     * Encrypt escribe .well-known/acme-challenge.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function provision_ssl(): void
    {
        foreach ($this->dominios() as $dominio) {
            $this->esperar_propagacion($dominio);
            $this->emitir_certificado($dominio);
        }

        $this->log('provision_ssl', 'Los 4 dominios tienen certificado.', 'success');
    }

    /**
     * En el VPS la base NO lleva el prefijo de la cuenta compartida (§F3 del informe de migración):
     * ese prefijo se lo impone Hostinger a las bases de su hosting, y acá el MySQL es propio.
     *
     * @return string
     */
    protected function prefijo_de_base(): string
    {
        return '';
    }

    /**
     * Los 4 dominios completos del cliente, en el mismo orden que los labels.
     *
     * @return array<int, string>
     */
    protected function dominios(): array
    {
        $dominios = [];

        foreach ($this->nombres_de_subdominios() as $label) {
            $dominios[] = $label . '.' . $this->dominio();
        }

        return $dominios;
    }

    /**
     * IP del VPS, siempre de config.
     *
     * @return string
     * @throws \RuntimeException Si no está configurada.
     */
    protected function ip_del_vps(): string
    {
        $ip = trim((string) config('services.hostinger.vps_ip', ''));

        if ($ip === '') {
            throw new \RuntimeException(
                'Falta HOSTINGER_VPS_IP en el .env de admin-api: sin la IP del VPS no se puede '
                . 'apuntar el DNS ni verificar la propagación.'
            );
        }

        return $ip;
    }

    /**
     * Usuario de sistema de la instancia que recibió la instalación real (ej: api-lacava2).
     *
     * Sale del spa_url de la ClientApi destino y no del slug del cliente: el slug es 'lacava' para
     * las dos instancias, y el cron tiene que quedar en la que se acaba de instalar.
     *
     * @return string
     * @throws \RuntimeException
     */
    protected function usuario_de_la_instancia(): string
    {
        $label = HostingProvisioningStructure::label_de_url((string) $this->target_api->spa_url);

        if ($label === '') {
            throw new \RuntimeException(
                'No se pudo derivar el usuario del sitio en el VPS del spa_url de la ClientApi ("'
                . $this->target_api->spa_url . '").'
            );
        }

        return 'api-' . $label;
    }

    /**
     * 🔴 GUARDA G2 — el interruptor de escritura del DNS, apagado por defecto.
     *
     * Se chequea en el preflight (para fallar antes de crear nada) y otra vez justo antes del PUT.
     * Las dos veces a propósito: son dos momentos distintos y el segundo es el que protege contra
     * que alguien llame a provision_dns() por fuera del pipeline.
     *
     * @return void
     * @throws \RuntimeException
     */
    protected function assert_dns_write_enabled(): void
    {
        if ((bool) config('services.hostinger.dns_write_enabled', false)) {
            return;
        }

        throw new \RuntimeException(
            'La escritura del DNS está deshabilitada (HOSTINGER_DNS_WRITE_ENABLED). Prenderla '
            . 'habilita el único PUT irreversible del aprovisionamiento: un PUT sobre la zona de '
            . $this->dominio() . ', que es donde viven los subdominios de los ~40 clientes activos. '
            . 'Poné HOSTINGER_DNS_WRITE_ENABLED=true en el .env de admin-api, reiniciá el worker de '
            . 'cola y mirá el id del snapshot en el log de esta etapa.'
        );
    }

    /**
     * Runner del VPS, ya atado a una etapa.
     *
     * @param  string  $step
     * @return RemoteCommandRunner
     */
    protected function vps(string $step): RemoteCommandRunner
    {
        return $this->runner('vps')->para_etapa($step);
    }

    /**
     * Espera acotada a que el A record propague, sondeando desde el VPS contra el DNS de Google.
     *
     * No es fatal si no propaga: se avisa y se intenta el certificado igual. Let's Encrypt valida
     * contra su propio resolver, así que el `dig` es una señal y no la verdad; y si la validación
     * falla, el que corta es emitir_certificado(), con su mensaje.
     *
     * Con dns_wait_seconds en 0 hace una sola sonda y sigue, sin dormir: es lo que hace que esta
     * espera sea probable en un test sin dejar la suite colgada tres minutos.
     *
     * @param  string  $dominio
     * @return void
     */
    private function esperar_propagacion(string $dominio): void
    {
        $ip           = $this->ip_del_vps();
        $tope         = (int) config('services.hostinger.dns_wait_seconds', 180);
        $transcurrido = 0;

        while (true) {
            $salida = $this->vps('provision_ssl')->run(
                'dig +short @8.8.8.8 ' . escapeshellarg($dominio),
                [],
                false
            );

            if (strpos($salida, $ip) !== false) {
                $this->log('provision_ssl', $dominio . ' ya resuelve a ' . $ip . '.');

                return;
            }

            if ($transcurrido + self::INTERVALO_DE_SONDA > $tope) {
                $this->log(
                    'provision_ssl',
                    $dominio . ' todavía no resuelve a ' . $ip . ' después de ' . $transcurrido
                        . ' s. Se pide el certificado igual: Let\'s Encrypt valida contra su propio '
                        . 'resolver y puede verlo antes que Google.',
                    'warning'
                );

                return;
            }

            sleep(self::INTERVALO_DE_SONDA);
            $transcurrido += self::INTERVALO_DE_SONDA;
        }
    }

    /**
     * Pide el certificado, hasta 2 intentos, y si no sale corta la etapa con las instrucciones.
     *
     * @param  string  $dominio
     * @return void
     * @throws \RuntimeException
     */
    private function emitir_certificado(string $dominio): void
    {
        $comando = 'clpctl lets-encrypt:install:certificate --domainName=' . escapeshellarg($dominio);
        $ultimo  = '';

        for ($intento = 1; $intento <= 2; $intento++) {
            try {
                $this->vps('provision_ssl')->run($comando);
                $this->result->creado('certificado', $dominio);

                return;
            } catch (\Throwable $excepcion) {
                $ultimo = $excepcion->getMessage();
                $this->log(
                    'provision_ssl',
                    'Intento ' . $intento . ' de 2 fallido para ' . $dominio . ': ' . $ultimo,
                    'warning'
                );
            }
        }

        throw new \RuntimeException(
            'No se pudo emitir el certificado de ' . $dominio . ' en 2 intentos: ' . $ultimo . '. '
            . '🔴 TODO LO DEMÁS YA QUEDÓ HECHO (los 4 sitios, el DNS, la base y el cron): lo único '
            . 'que falta son los certificados. Entrá al VPS y corré estos 4 comandos, en este '
            . 'orden, cuando el DNS haya propagado: ' . implode(' ; ', $this->comandos_de_certificado())
        );
    }

    /**
     * Los 4 comandos exactos para emitir los certificados a mano.
     *
     * @return array<int, string>
     */
    private function comandos_de_certificado(): array
    {
        $comandos = [];

        foreach ($this->dominios() as $dominio) {
            $comandos[] = 'clpctl lets-encrypt:install:certificate --domainName=' . $dominio;
        }

        return $comandos;
    }
}

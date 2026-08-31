<?php

namespace App\Services;

use App\Models\ClientSshCredential;

/**
 * La mitad del aprovisionamiento del VPS que habla de SITIOS: el preflight y los 4 sitios de
 * CloudPanel con el symlink de su docroot.
 *
 * Partido de VpsCertificateProvisioner por la regla R2 del plan (§9): 450 líneas por archivo nuevo
 * de app/Services/. La cadena completa es
 * HostingProvisioningService ← VpsCertificateProvisioner ← VpsSiteProvisioner ←
 * VpsDatabaseProvisioner ← VpsHostingProvisioning, y el porqué de cada corte está escrito en la
 * primera.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
abstract class VpsSiteProvisioner extends VpsCertificateProvisioner
{
    /**
     * Preflight del VPS. NO ESCRIBE NADA: ni del lado del proveedor ni del nuestro.
     *
     * El orden importa y es el mismo criterio que el shared: primero lo que no cuesta nada (el
     * token), después la estructura del cliente, después el servidor, y el interruptor del DNS al
     * final —porque es el que le explica al operador qué es lo que está por habilitar—. Este es el
     * único paso que puede fallar sin dejar nada creado del otro lado, así que es donde conviene
     * que fallen todos los problemas.
     *
     * 🔴 Hasta el 31/8/2026 este paso terminaba llamando a marcar_apis_como_vps(), y eso hacía que
     * el paso documentado como "preflight, no escribe nada" fuera el que dejaba el estado más caro
     * de todo el sistema escrito en NUESTRA base. El flip se mudó al final de provision_sites(); el
     * porqué está escrito ahí.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function provision_check(): void
    {
        $this->log('provision_check', 'Verificando el VPS antes de tocar nada...');

        /* El DNS del VPS sale por la MISMA API de Hostinger, así que el token hace falta igual. */
        $this->hostinger()->probar_token();
        $this->log('provision_check', 'El token de la API de Hostinger responde.');

        /* Las 5 guardas de §1.4. Derivan el slug y frenan si el cliente no es estándar. */
        $slug = $this->slug();
        $this->log(
            'provision_check',
            'Slug derivado: ' . $slug . '. Sitios: ' . implode(', ', $this->nombres_de_subdominios()) . '.'
        );

        /*
         * 🔴 Guarda 6, y va ANTES del flip —que ahora vive al final de provision_sites— porque la
         * comparación tiene que hacerse contra el estado previo. En esta rama el caso normal es
         * "pido vps y las APIs todavía dicen shared_hosting", que la guarda deja pasar a propósito.
         */
        $this->structure()->assert_hosting_type_coherente(
            trim((string) $this->installation->provision_hosting_type)
        );

        if (ClientSshCredential::where('type', 'vps')->first() === null) {
            throw new \RuntimeException(
                'No hay credencial SSH de tipo vps cargada en el admin. Cargala antes de instalar: '
                . 'todo el aprovisionamiento del VPS pasa por clpctl y por SSH.'
            );
        }

        $this->assert_binario('clpctl');
        $this->assert_binario('supervisorctl');

        /*
         * `dig` NO es fatal —el certificado se pide igual, Let's Encrypt valida contra su propio
         * resolver— pero su ausencia tiene que salir ACÁ y no doce minutos después: sin dnsutils,
         * `dig +short` devuelve vacío en cada sonda y los 4 dominios esperaban el tope completo sin
         * que ninguna línea del log dijera por qué. provision_ssl se saltea la espera cuando falta.
         */
        if ($this->hay_dig('provision_check')) {
            $this->log('provision_check', 'dig está: se va a poder verificar la propagación del DNS.');
        } else {
            $this->log(
                'provision_check',
                'El VPS no tiene `dig` (falta el paquete dnsutils). No frena la instalación, pero no '
                    . 'se va a poder verificar la propagación del DNS antes de pedir el certificado: '
                    . 'instalalo con `apt-get install -y dnsutils` si querés esa verificación.',
                'warning'
            );
        }

        $this->assert_dns_write_enabled();

        $this->log(
            'provision_check',
            'Preflight OK: no se escribió nada, ni del lado del VPS ni en la base del admin. Las '
                . 'ClientApi pasan a hosting_type=vps recién cuando los 4 sitios existan.',
            'success'
        );
    }

    /**
     * Crea los 4 sitios de CloudPanel, deja el docroot de los dos de API apuntando a
     * empresa-api/public y —solo entonces— marca las ClientApi como VPS.
     *
     * 🔴 EL FLIP VA ACÁ Y NO EN EL PREFLIGHT, y esto se decidió el 31/8/2026 con el motivo escrito.
     *
     * `hosting_type` no es una anotación: es la columna que DeploymentService lee para resolver la
     * credencial SSH, el api_path y el docroot del SPA de TODO upgrade futuro de ese cliente. Con el
     * flip en el preflight, cualquier falla posterior —un clpctl con un mensaje que la clasificación
     * no reconoce, el `rmdir` que el plan marca como no verificado (§10.5), la sesión SSH que se
     * cae— dejaba las dos ClientApi diciendo "vps" SIN QUE EXISTIERA UN SOLO SITIO del otro lado: el
     * cliente seguía sirviendo desde el compartido y el admin ya no sabía llegar ahí. Y nada las
     * devolvía.
     *
     * Se eligió mover el flip (opción (a) del hallazgo) sobre revertirlo ante el error (opción (b))
     * porque (b) depende de que el proceso siga vivo para poder revertir, y los dos modos de falla
     * que más importan —el worker que muere, la sesión que se corta— son justamente los que no
     * dejan correr ningún `catch`. Acá el estado guardado no puede mentir por construcción: se
     * escribe después de que los 4 sitios existen y de que los dos docroots verificaron con
     * `readlink`.
     *
     * ⚠️ Sigue estando ANTES de compile_spa, que es la otra restricción dura (§3.1): el bundle se
     * compila con build_api_url_for_env(), que le agrega '/public' a la URL si el hosting es
     * compartido. El pipeline es provision_check → provision_sites → provision_dns → provision_db →
     * compile_spa, así que mover el flip un paso más adelante no toca esa garantía. Lo que sí hace
     * falta es refrescar la instancia en memoria: eso lo hace step_provision_sites() en el trait.
     *
     * @return void
     * @throws \Throwable
     */
    public function provision_sites(): void
    {
        foreach ($this->claves_de_sitio() as $label => $clave) {
            $this->crear_sitio((string) $label, (string) $clave);
        }

        /* El symlink es solo de las dos APIs: el docroot del SPA es htdocs/<dominio> tal cual. */
        foreach ($this->labels_de_api() as $label) {
            $this->enlazar_docroot_de_api((string) $label);
        }

        $this->log('provision_sites', 'Los 4 sitios de ' . $this->slug() . ' están.', 'success');

        /* Recién ahora el estado guardado dice la verdad: los sitios existen. */
        $this->marcar_apis_como_vps($this->slug());
    }

    /**
     * Los 4 labels con el prefijo que llevan sus secretos en provisioning_secrets.
     *
     * El shape de §2 (M2) nombraba api_site_* y spa_site_*; con 4 sitios hacen falta 4 pares, así
     * que la instancia 2 lleva api2_/spa2_. Los dos secretos de cada sitio se guardan juntos.
     *
     * @return array<string, string>  label => prefijo de la clave.
     */
    protected function claves_de_sitio(): array
    {
        $slug = $this->slug();

        return [
            'api-' . $slug       => 'api_site',
            $slug                => 'spa_site',
            'api-' . $slug . '2' => 'api2_site',
            $slug . '2'          => 'spa2_site',
        ];
    }

    /**
     * Los dos labels de API (los únicos que llevan symlink de docroot).
     *
     * @return array<int, string>
     */
    protected function labels_de_api(): array
    {
        $slug = $this->slug();

        return ['api-' . $slug, 'api-' . $slug . '2'];
    }

    /**
     * El binario está instalado en el VPS.
     *
     * must_succeed en false: `command -v` devuelve exit 1 cuando no encuentra nada, y eso no es un
     * error del comando sino su respuesta. Lo que decide es la salida.
     *
     * @param  string  $binario
     * @return void
     * @throws \RuntimeException
     */
    private function assert_binario(string $binario): void
    {
        $salida = trim($this->vps('provision_check')->run('command -v ' . $binario, [], false));

        if ($salida === '') {
            throw new \RuntimeException(
                'El VPS no tiene ' . $binario . ' instalado (o no está en el PATH del usuario SSH). '
                . 'Todo el aprovisionamiento del VPS depende de ese binario: revisalo antes de '
                . 'seguir.'
            );
        }

        $this->log('provision_check', $binario . ' está en ' . $salida . '.');
    }

    /**
     * Marca las dos ClientApi del cliente como VPS. Se llama al final de provision_sites(), cuando
     * los 4 sitios ya existen: el porqué está escrito ahí arriba.
     *
     * 🔴 NO SE TOCA client_apis.path, y no es un olvido. Esa columna alimenta
     * build_spa_hosting_deploy_shell(), que arma el docroot del SPA como
     * 'domains/comerciocity.com/public_html/' . get_spa_path() y después le corre un
     * `find . -mindepth 1 -delete`. Con path vacío ese find borra el public_html entero de la
     * cuenta compartida —las carpetas de los ~40 clientes activos, de una—, que es exactamente el
     * estado en el que quedaron los clientes 43 y 13 en la migración. El path viejo no le molesta a
     * nadie: en VPS quien manda es vps_path.
     *
     * @param  string  $slug
     * @return void
     */
    private function marcar_apis_como_vps(string $slug): void
    {
        $labels = [$slug, $slug . '2'];

        foreach ($this->apis() as $indice => $api) {
            $api->hosting_type = 'vps';
            $api->vps_path     = $labels[$indice];
            $api->save();
        }

        $this->log(
            'provision_check',
            'Las 2 ClientApi del cliente quedaron en hosting_type=vps (vps_path ' . $slug . ' y '
                . $slug . '2). El campo path NO se tocó, a propósito.'
        );
    }

    /**
     * Crea un sitio de CloudPanel y persiste su contraseña en el instante siguiente.
     *
     * @param  string  $label  Label del sitio, que es también su usuario de sistema.
     * @param  string  $clave  Prefijo de la clave en provisioning_secrets.
     * @return void
     * @throws \Throwable
     */
    private function crear_sitio(string $label, string $clave): void
    {
        $dominio  = $label . '.' . $this->dominio();
        $password = $this->generar_password();

        $comando = 'clpctl site:add:php'
            . ' --domainName=' . $this->escapar($dominio)
            . ' --phpVersion=' . $this->escapar(self::PHP_VERSION)
            . ' --vhostTemplate=' . $this->escapar(self::VHOST_TEMPLATE)
            . ' --siteUser=' . $this->escapar($label)
            . ' --siteUserPassword=' . $this->escapar($password);

        try {
            /* 🔴 El password va en la lista de redacción: es lo que impide que termine en el panel. */
            $this->vps('provision_sites')->run($comando, [$password]);
        } catch (\Throwable $excepcion) {
            $this->manejar_sitio_existente($label, $excepcion);

            return;
        }

        /*
         * 🔴 Persistencia inmediata, antes de loguear siquiera el éxito: si el proceso muere acá
         * abajo, el sitio queda creado con una contraseña que nadie conoce.
         */
        $this->persistir_secretos([
            $clave . '_user'     => $label,
            $clave . '_password' => $password,
        ]);

        $this->result->creado('sitio', $label);
        $this->log('provision_sites', 'Sitio ' . $dominio . ' creado y su contraseña guardada cifrada.');
    }

    /**
     * Decide qué hacer con el error de un `clpctl site:add:php`.
     *
     * Un sitio que ya existía NO es motivo de falla: el flujo normal ante una instalación fallida es
     * reintentar. Lo que sí se pierde es la contraseña —la generada no sirve, porque el sitio se
     * creó con otra— y por eso NO se persiste ninguna: guardar la que no es sería peor que no tener
     * ninguna, porque nadie volvería a mirar. Va un warning porque esa contraseña la necesita Lucas,
     * no el pipeline (todo corre como root).
     *
     * Un error que NO se puede clasificar como "ya existe" hace fallar la etapa: nunca se adivina.
     *
     * @param  string      $label
     * @param  \Throwable  $excepcion
     * @return void
     * @throws \Throwable
     */
    private function manejar_sitio_existente(string $label, \Throwable $excepcion): void
    {
        if ($this->hostinger()->clasificar_error($excepcion) !== HostingerApiClient::CLASIFICACION_YA_EXISTE) {
            throw $excepcion;
        }

        $this->result->ya_existia('sitio', $label);
        $this->log(
            'provision_sites',
            'El sitio ' . $label . ' ya existía, se sigue. Ojo: su contraseña la puso la corrida '
                . 'anterior y no queda guardada en esta. Mensaje del VPS: ' . $excepcion->getMessage(),
            'warning'
        );
    }

    /**
     * Deja htdocs/<dominio> apuntando a empresa-api/public.
     *
     * 🔴 `rmdir` Y NUNCA `rm -rf`. CloudPanel crea htdocs/<dominio> como DIRECTORIO y el symlink no
     * lo puede reemplazar: hay que sacarlo primero, pero con rmdir, que FALLA si tiene contenido.
     * En un reintento sobre un sitio ya instalado, un `rm -rf` acá borraría el docroot de un cliente
     * que está sirviendo producción. El rmdir que falla es la señal correcta: "esto ya tiene algo,
     * mirá qué es".
     *
     * Por eso el rmdir va con must_succeed en false y la verificación se hace después con readlink:
     * en un reintento sobre un docroot que YA es el symlink, el rmdir también falla ("Not a
     * directory") y ahí no hay nada malo. Lo que decide es a dónde apunta el docroot al final.
     *
     * ⚠️ Qué pasa exactamente si el rmdir no pudo sacar el directorio, porque el comentario que
     * había acá prometía otra cosa: con `-n` —que es lo que este código usa— GNU `ln` NO crea el
     * enlace adentro del directorio; se niega con "cannot overwrite directory" y sale con exit ≠ 0,
     * así que el que corta es el `run()` del `ln`, antes del readlink. (Sin `-n`, y solo sin `-n`,
     * el enlace se crearía adentro.) El resultado final es el mismo y sigue siendo el correcto
     * —falla ruidosa, no se borra nada—, pero el error que ve el operador es el del `ln`, no el
     * mensaje del readlink de más abajo.
     *
     * @param  string  $label
     * @return void
     * @throws \RuntimeException
     */
    private function enlazar_docroot_de_api(string $label): void
    {
        $home    = '/home/' . $label;
        $destino = $home . '/empresa-api/public';
        $docroot = $home . '/htdocs/' . $label . '.' . $this->dominio();
        $runner  = $this->vps('provision_sites');

        $runner->run('mkdir -p ' . $this->escapar($home . '/empresa-api'));
        $runner->run('rmdir ' . $this->escapar($docroot), [], false);
        $runner->run('ln -sfn ' . $this->escapar($destino) . ' ' . $this->escapar($docroot));

        $apunta_a = trim($runner->run('readlink ' . $this->escapar($docroot), [], false));

        if ($apunta_a !== $destino) {
            throw new \RuntimeException(
                'El docroot ' . $docroot . ' no quedó apuntando a ' . $destino . ' (readlink dio "'
                . $apunta_a . '"). Casi seguro ya tenía contenido y el rmdir falló: miralo a mano '
                . 'antes de seguir. NO se borra por las dudas — si ese directorio es el docroot de '
                . 'un cliente que está sirviendo producción, borrarlo lo deja caído.'
            );
        }
    }
}

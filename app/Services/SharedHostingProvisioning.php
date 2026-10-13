<?php

namespace App\Services;

use App\Models\ClientSshCredential;

/**
 * Aprovisionamiento del hosting COMPARTIDO de Hostinger: el preflight, la base de datos del cliente,
 * el cron y el certificado, todo por la API pública de developers.hostinger.com.
 *
 * Los 4 subdominios y la verificación del DNS están en la clase padre, SharedHostingSubdomains, con
 * la guarda G1 escrita ahí: en hosting compartido NO existe ninguna escritura de zona. La partición
 * la forzó la regla R2 de §9 (450 líneas por archivo nuevo de app/Services/).
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class SharedHostingProvisioning extends SharedHostingSubdomains
{
    /**
     * Mensaje textual de §3.2 para la base que ya existe y de la que no tenemos la contraseña.
     *
     * Está entero y con el paso a paso porque la API de Hostinger NO permite leer ni resetear la
     * contraseña de una base existente: no hay reintento posible, y la única salida es una acción
     * humana. Un mensaje genérico acá deja al operador reintentando para siempre.
     *
     * @var string
     */
    const MENSAJE_BASE_SIN_SECRETO = 'La base %s ya existe y no tengo su contraseña. La API de '
        . 'Hostinger no permite leerla ni resetearla. Destildá el aprovisionamiento y cargá '
        . 'DB_DATABASE/DB_USERNAME/DB_PASSWORD a mano, o borrá la base desde hPanel si es un resto de '
        . 'una prueba.';

    /**
     * Preflight. No escribe ni una sola cosa del otro lado.
     *
     * El orden importa: primero el token (sin token no se puede ni mirar), después la sonda contra
     * la API, después la estructura del cliente y por último la credencial SSH. Cada uno falla con
     * el texto que le dice al operador qué hacer, porque este es el único paso que puede fallar sin
     * dejar nada creado y por eso es donde conviene que fallen todos los problemas.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function provision_check(): void
    {
        $this->log('provision_check', 'Verificando el hosting compartido antes de tocar nada...');

        /* Token configurado + sonda GET /cron-jobs (lectura pura, la llamada más barata). */
        $this->hostinger()->probar_token();
        $this->log('provision_check', 'El token de la API de Hostinger responde.');

        /* Las 5 guardas de §1.4. Derivan el slug y frenan si el cliente no es estándar. */
        $slug = $this->slug();
        $this->log(
            'provision_check',
            'Slug derivado: ' . $slug . '. Subdominios: ' . implode(', ', $this->nombres_de_subdominios()) . '.'
        );

        /*
         * 🔴 Guarda 6: el hosting pedido tiene que ser el de las ClientApi. Acá el caso peligroso es
         * el real —aprovisionar el compartido para un cliente cuyas APIs ya dicen 'vps' crea los
         * subdominios y la base en la cuenta compartida mientras el pipeline sube el código al VPS—
         * y el motivo largo está en HostingProvisioningStructure::assert_hosting_type_coherente().
         */
        $this->structure()->assert_hosting_type_coherente(
            trim((string) $this->installation->provision_hosting_type)
        );

        /*
         * La credencial de shared la exige el pipeline de instalación entero (InstallationService la
         * carga con firstOrFail en su constructor). Se chequea acá igual para que la falta salga en
         * el preflight y no quince minutos después, en medio de un upload.
         */
        if (ClientSshCredential::where('type', 'shared_hosting')->first() === null) {
            throw new \RuntimeException(
                'No hay credencial SSH de tipo shared_hosting cargada en el admin. Cargala antes de '
                . 'instalar: sin ella el pipeline no puede subir el código.'
            );
        }

        $this->log('provision_check', 'Preflight OK: no se escribió nada todavía.', 'success');
    }

    /**
     * Crea la base de datos del cliente (una sola para las dos instancias) y persiste su
     * contraseña cifrada en el instante siguiente.
     *
     * 🔴 ESTA ETAPA CORRE DOS VECES POR CLIENTE Y LA SEGUNDA TIENE QUE SER UN NO-OP. Un cliente de
     * hosting compartido se instala como un par (la real y el esqueleto del subdominio secundario) y
     * las dos filas comparten UNA base. La real la crea y guarda su contraseña; el esqueleto corre
     * después sobre el mismo cliente y encuentra la base puesta. Medido el 13/10/2026 con Los Peña
     * (cliente 73): la real terminó bien y el esqueleto murió a los 18 s en esta etapa, con
     * `422 "[Hosting:9999] Database name must be unique"`.
     *
     * Por qué no frenó el chequeo previo: base_ya_existe() leía mal la lista (ver
     * HostingerApiClient::list_databases()) y nunca dio true en producción. La primera instalación de
     * un cliente no lo necesita; el esqueleto lo necesita siempre.
     *
     * Y por qué el POST va dentro de un try/catch en vez de confiar en el chequeo previo: entre la
     * lectura y el POST hay una carrera posible (otra corrida del mismo cliente, o una base que
     * Hostinger todavía no refleja en la lista), y el 422 que sale de ahí tiene que poder reusarse.
     * Pero solo después de VERIFICAR contra el proveedor: ver manejar_error_de_base().
     *
     * @return void
     * @throws \RuntimeException
     */
    public function provision_db(): void
    {
        $nombre = $this->prefijo_de_base() . $this->slug();

        if ($this->base_ya_existe($nombre)) {
            $this->reusar_base_existente($nombre);

            return;
        }

        /*
         * 🔴 Esta contraseña existe solo para ESTE intento de creación. Si el POST falla y la base
         * resulta ser la de la instalación real, se descarta: persistir_secretos() NO se llama en
         * ningún camino de reuso. Guardarla pisaría en las dos ClientApi la contraseña que
         * efectivamente tiene la base, y el .env del cliente (que sale de esos secretos) quedaría con
         * una clave que MySQL no conoce. La API de Hostinger no deja leer ni resetear la contraseña
         * de una base existente, así que ese error no tendría vuelta atrás.
         */
        $password = $this->generar_password();

        $this->log('provision_db', 'Creando la base ' . $nombre . '...');

        try {
            $this->hostinger()->create_database($nombre, $nombre, $password, $this->dominio());
        } catch (\Throwable $excepcion) {
            $this->manejar_error_de_base($nombre, $excepcion);

            return;
        }

        /*
         * 🔴 Persistencia INMEDIATA, en su propia escritura, antes de loguear siquiera el éxito.
         * Es la línea que hace que una caída acá abajo no deje una base creada con la contraseña
         * perdida para siempre (la API no la deja leer ni resetear).
         */
        $this->persistir_secretos([
            'db_name'     => $nombre,
            'db_user'     => $nombre,
            'db_password' => $password,
        ]);

        $this->result->creado('base', $nombre);
        $this->log('provision_db', 'Base ' . $nombre . ' creada y credenciales guardadas cifradas.', 'success');
    }

    /**
     * Crea el cron de la instancia, si no hay ya uno de cola apuntando a esa misma ruta.
     *
     * 🔴 En el hosting compartido los crons NO se editan por SSH: no existe el binario `crontab` y
     * /var/spool/cron/ está vacío adentro del CageFS (verificado el 25/8/2026 limpiando los cronjobs
     * de producción). La única vía programática es esta API, y por eso este paso no reusa la sesión
     * SSH que el pipeline ya tiene abierta.
     *
     * Antes de crear se pregunta, y no se asume: alguien pudo haber creado el cron a mano, o este
     * puede ser el reintento de una instalación fallida. Dos crons de cola sobre la misma instancia
     * no rompen nada gracias al flock, pero duplican carga en un servidor que ya está a load 14.
     *
     * @param  string  $api_path
     * @param  bool    $kernel_optimizado
     * @return void
     * @throws \RuntimeException
     */
    public function provision_cron(string $api_path, bool $kernel_optimizado): void
    {
        $existente = $this->cron_de_cola_existente($api_path);

        if ($existente !== null) {
            $this->log(
                'provision_cron',
                'Esta instancia ya tiene un cron de cola (uid ' . $existente . '): no se crea otro.',
                'warning'
            );
            $this->result->ya_existia('cron', $existente);

            return;
        }

        $comando = $this->comando_de_cron($api_path, $kernel_optimizado);
        $this->log('provision_cron', 'Creando el cron: * * * * * ' . $comando);

        $respuesta = $this->hostinger()->create_cron('* * * * *', $comando);
        $uid       = isset($respuesta['uid']) ? (string) $respuesta['uid'] : '';

        /* El uid es lo único con lo que después se puede borrar o mover este cron. */
        if ($uid !== '') {
            $this->persistir_secretos(['cron_uid' => $uid]);
        }

        $this->result->creado('cron', $uid === '' ? $comando : $uid);
        $this->log('provision_cron', 'Cron creado' . ($uid === '' ? '.' : ' (uid ' . $uid . ').'), 'success');
    }

    /**
     * En el hosting compartido no hay nada que hacer con el certificado.
     *
     * @return void
     */
    public function provision_ssl(): void
    {
        $this->log(
            'provision_ssl',
            'Hostinger emite el certificado del subdominio por su cuenta: no hay nada que pedir.'
        );
    }

    /**
     * uid del cron de cola que ya existe para esa ruta, o null si no hay ninguno.
     *
     * Se filtra con es_cron_de_cola() a propósito: la cuenta es una sola y los ~47 cronjobs de
     * comandos de negocio de los otros clientes (set_company_performances, check_stocks, etc.)
     * conviven en la misma lista. Ninguno de esos está en el Kernel.php, así que tratarlos como
     * "cron de cola" y darlos por reemplazados apagaría funcionalidad sin reemplazo.
     *
     * @param  string  $api_path
     * @return string|null
     * @throws \RuntimeException
     */
    private function cron_de_cola_existente(string $api_path): ?string
    {
        foreach ($this->hostinger()->crons_for_api_path($api_path) as $cron) {
            if (! is_array($cron) || ! isset($cron['command'])) {
                continue;
            }

            if ($this->hostinger()->es_cron_de_cola((string) $cron['command'])) {
                return isset($cron['uid']) ? (string) $cron['uid'] : '(sin uid)';
            }
        }

        return null;
    }

    /**
     * Ruta absoluta de la API en el hosting compartido.
     *
     * ClientApiPathResolver devuelve la ruta relativa al home del usuario SSH
     * ('domains/comerciocity.com/public_html/lacava/api'), que es lo que sirve para los comandos que
     * corren con esa sesión abierta. El cron, en cambio, lo ejecuta el panel de Hostinger y necesita
     * la absoluta: los crons de producción tienen todos la forma
     * '/usr/bin/php /home/u767360347/domains/.../api/artisan schedule:run'.
     *
     * Es también lo que hace que crons_for_api_path() encuentre después este mismo cron: esa función
     * busca '/<api_path>/artisan', con la barra adelante, y sin el prefijo del home la barra no está.
     *
     * @param  string  $api_path
     * @return string
     */
    protected function ruta_absoluta_de_api(string $api_path): string
    {
        if (strpos($api_path, '/') === 0) {
            return $api_path;
        }

        return '/home/' . trim((string) config('services.hostinger.account_username', '')) . '/' . $api_path;
    }


    /**
     * ¿La base ya está en la cuenta?
     *
     * La idempotencia se verifica SIEMPRE contra el proveedor y nunca contra
     * client_apis.hosting_provisioned_at: alguien puede haber borrado la base a mano desde hPanel y
     * la columna no se entera.
     *
     * Lee la lista COMPLETA, todas las páginas (list_databases() las recorre): con solo la primera,
     * una base que cae en la página 2 o 3 parecería no existir, y es el mismo falso "no existe" que
     * tumbó al esqueleto de Los Peña.
     *
     * @param  string  $nombre
     * @return bool
     * @throws \RuntimeException
     */
    private function base_ya_existe(string $nombre): bool
    {
        foreach ($this->hostinger()->list_databases() as $base) {
            if (is_array($base) && isset($base['name']) && (string) $base['name'] === $nombre) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide qué hacer con el error del POST que crea la base.
     *
     * Misma idea que manejar_error_de_subdominio() —la idempotencia importa y "ya existe" no se
     * adivina nunca—, con una diferencia que es a propósito: acá NO se confía en el texto del error,
     * se pregunta al proveedor.
     *
     *   - OTRO_ERROR (401, 403, 404, 5xx, transporte): se relanza SIN consultar nada. Token
     *     inválido, permisos o una caída no tienen relación con que la base exista, y una segunda
     *     lectura contra una API que acaba de fallar solo sumaría ruido a lo que el operador lee.
     *   - YA_EXISTE o DESCONOCIDA: se verifica con base_ya_existe(), o sea con la lista PAGINADA de
     *     la cuenta. El 422 de Hostinger dice "Database name must be unique" sin decir de quién es la
     *     base: lo único que prueba que está es verla. Si la verificación misma falla, o la base NO
     *     figura, se relanza el error ORIGINAL (no el de la verificación): es el que describe lo que
     *     pasó con la creación y el que el operador tiene que ver.
     *
     * Si la base está, se reusa con reusar_base_existente(), que exige tener su contraseña guardada.
     * Ese es el seguro contra la mitad peligrosa del caso: una base que existe pero NO es la de este
     * cliente (un resto de otra prueba con el mismo nombre) falla con el mensaje del operador, en vez
     * de seguir de largo con una contraseña que nadie conoce.
     *
     * 🔴 NADA de esto escribe secretos. Ver el comentario del password en provision_db().
     *
     * @param  string      $nombre
     * @param  \Throwable  $excepcion  El error original del POST.
     * @return void
     * @throws \Throwable
     */
    private function manejar_error_de_base(string $nombre, \Throwable $excepcion): void
    {
        if ($this->hostinger()->clasificar_error($excepcion) === HostingerApiClient::CLASIFICACION_OTRO_ERROR) {
            throw $excepcion;
        }

        try {
            $figura = $this->base_ya_existe($nombre);
        } catch (\Throwable $verificacion) {
            throw $excepcion;
        }

        if (! $figura) {
            throw $excepcion;
        }

        $this->log(
            'provision_db',
            'El POST que crea la base ' . $nombre . ' falló (' . $excepcion->getMessage() . '), pero la '
                . 'base ya figura en la cuenta de Hostinger: se toma como ya existente y se sigue.',
            'warning'
        );

        $this->reusar_base_existente($nombre);
    }

    /**
     * La base ya estaba: se reusa si tenemos su contraseña guardada, y si no, se falla.
     *
     * No escribe NINGÚN secreto: solo lee los que ya guardó la instalación que la creó.
     *
     * @param  string  $nombre
     * @return void
     * @throws \RuntimeException
     */
    private function reusar_base_existente(string $nombre): void
    {
        $secretos = $this->secretos_guardados();

        if (! isset($secretos['db_password']) || (string) $secretos['db_password'] === '') {
            throw new \RuntimeException(sprintf(self::MENSAJE_BASE_SIN_SECRETO, $nombre));
        }

        $this->result->ya_existia('base', $nombre);
        $this->log(
            'provision_db',
            'La base ' . $nombre . ' ya existía y tengo su contraseña guardada: se reusa.',
            'warning'
        );
    }
}

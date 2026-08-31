<?php

namespace App\Services;

use App\Models\ClientSshCredential;

/**
 * Aprovisionamiento del hosting COMPARTIDO de Hostinger: los 4 subdominios, la verificación del DNS
 * y la base de datos del cliente, todo por la API pública de developers.hostinger.com.
 *
 * 🔴 GUARDA G1 — ACÁ NO HAY NI UNA ESCRITURA DE DNS, Y ES EL PUNTO MÁS IMPORTANTE DE ESTA CLASE.
 * En el hosting compartido Hostinger crea el A record solo al crear el subdominio, así que
 * provision_dns() hace un GET de la zona y VERIFICA que los 4 nombres estén. No existe una rama de
 * código que llegue a HostingerApiClient::put_dns_zone() desde acá: ese PUT va sobre la zona donde
 * viven los subdominios de los ~40 clientes activos y, con el hosting compartido, no hace falta para
 * nada. Si alguien "unifica" los dos proveedores y hace que este paso escriba, el test 4 de §7 se
 * pone en rojo — y ese test existe exactamente para eso.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class SharedHostingProvisioning extends HostingProvisioningService
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
     * Crea los 4 subdominios del cliente.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function provision_sites(): void
    {
        $slug = $this->slug();

        foreach ($this->directorios_de_subdominios() as $subdominio => $directorio) {
            $this->crear_subdominio((string) $subdominio, (string) $directorio);
        }

        $this->log('provision_sites', 'Los 4 subdominios de ' . $slug . ' están.', 'success');
    }

    /**
     * Verifica que los 4 A records estén en la zona. NO escribe (guarda G1).
     *
     * @return void
     * @throws \RuntimeException
     */
    public function provision_dns(): void
    {
        $this->log('provision_dns', 'Leyendo la zona DNS de ' . $this->dominio() . ' (solo lectura).');

        $nombres_en_la_zona = $this->nombres_de_la_zona($this->hostinger()->get_dns_zone());
        $faltantes          = [];

        foreach ($this->nombres_de_subdominios() as $nombre) {
            if (! in_array($nombre, $nombres_en_la_zona, true)) {
                $faltantes[] = $nombre;
            }
        }

        /*
         * 🔴 Si falta alguno, se FALLA y se dice cuál. No se escribe el registro que falta.
         *
         * Que falte un nombre significa una de dos cosas, y las dos las tiene que mirar una persona:
         * o el POST del subdominio no creó el A record (y entonces el diseño del paso está mal, §10.4
         * del plan), o alguien borró el registro a mano. Escribirlo desde acá sería el PUT sobre la
         * zona de los ~40 clientes activos, que es justamente lo que el hosting compartido no
         * necesita hacer nunca.
         */
        if ($faltantes !== []) {
            throw new \RuntimeException(
                'Faltan A records en la zona de ' . $this->dominio() . ': ' . implode(', ', $faltantes)
                . '. Hostinger los crea solo al crear el subdominio, así que revisalos en hPanel → '
                . 'DNS antes de seguir. El aprovisionamiento del hosting compartido NO escribe la zona.'
            );
        }

        $this->log('provision_dns', 'Los 4 A records ya estaban en la zona.', 'success');
    }

    /**
     * Crea la base de datos del cliente (una sola para las dos instancias) y persiste su
     * contraseña cifrada en el instante siguiente.
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

        $password = $this->generar_password();

        $this->log('provision_db', 'Creando la base ' . $nombre . '...');
        $this->hostinger()->create_database($nombre, $nombre, $password, $this->dominio());

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
     * Los 4 subdominios con su directorio destino, en el orden en que se crean.
     *
     * 🔴 EL SUBDOMINIO DE LA API APUNTA A `<slug>/api`, NUNCA A `<slug>/api/public`.
     *
     * Si estás por "arreglar" esto agregándole `/public`, leé esto primero:
     * ClientEmpresaApiUrlResolver::normalize_api_base_url() le AGREGA `/public` a la URL de toda
     * ClientApi con hosting_type='shared_hosting', y esa URL alimenta APP_URL y VUE_APP_API_URL.
     * Con el docroot ya apuntando a public/, el SPA pediría `.../public/api/...` sobre un docroot que
     * YA es public/ → 404 en todo el sistema, en el ERP y en la tienda. Los ~30 clientes de
     * producción que hoy andan tienen `/public` en la URL: esa es la evidencia de cuál es la
     * convención.
     *
     * Por eso también `is_using_public_directory` sale en false desde config.
     *
     * @return array<string, string>  subdominio => directorio.
     */
    public function directorios_de_subdominios(): array
    {
        $slug = $this->slug();

        return [
            'api-' . $slug        => $this->directorio_de($slug . '/api'),
            $slug                 => $this->directorio_de($slug . '/spa'),
            'api-' . $slug . '2'  => $this->directorio_de($slug . '2/api'),
            $slug . '2'           => $this->directorio_de($slug . '2/spa'),
        ];
    }

    /**
     * Aplica la plantilla de config al path relativo del sitio.
     *
     * Sale de config y no hardcodeado porque es lo único del contrato de la API que no se pudo
     * verificar (§10.1): si la primera corrida real muestra que `directory` es relativo al home del
     * usuario y no a public_html, se cambia la plantilla en config y no se toca una línea de código.
     *
     * @param  string  $path  Ruta relativa dentro de public_html (ej: 'lacava/api').
     * @return string
     */
    private function directorio_de(string $path): string
    {
        $plantilla = (string) config('services.hostinger.subdomain_directory_template', '{path}');

        return str_replace(['{path}', '{domain}'], [$path, $this->dominio()], $plantilla);
    }

    /**
     * Crea un subdominio, tolerando que ya exista.
     *
     * @param  string  $subdominio
     * @param  string  $directorio
     * @return void
     * @throws \RuntimeException
     */
    private function crear_subdominio(string $subdominio, string $directorio): void
    {
        $publico = (bool) config('services.hostinger.subdomain_is_using_public_directory', false);

        $this->log(
            'provision_sites',
            'Creando el subdominio ' . $subdominio . ' → ' . $directorio
                . ' (is_using_public_directory: ' . ($publico ? 'true' : 'false') . ')...'
        );

        try {
            $this->hostinger()->create_subdomain($subdominio, $directorio, $publico);
        } catch (\Throwable $excepcion) {
            $this->manejar_error_de_subdominio($subdominio, $excepcion);

            return;
        }

        $this->result->creado('subdominio', $subdominio);
    }

    /**
     * Decide qué hacer con el error de un POST de subdominio.
     *
     * La idempotencia importa de verdad: el flujo normal ante una instalación fallida es reintentar,
     * y en el reintento los subdominios de la corrida anterior ya están. Pero "ya existía" se acepta
     * solo cuando el proveedor lo dice de forma reconocible; ante un mensaje que no se puede
     * clasificar se consulta la zona DNS y, si el nombre tampoco está ahí, se falla. Nunca se
     * adivina: dar por bueno un "ya existe" que no fue deja el pipeline creyendo que el subdominio
     * está, y el error aparece quince minutos después en un paso que no tiene nada que ver.
     *
     * @param  string      $subdominio
     * @param  \Throwable  $excepcion
     * @return void
     * @throws \Throwable
     */
    private function manejar_error_de_subdominio(string $subdominio, \Throwable $excepcion): void
    {
        $clasificacion = $this->hostinger()->clasificar_error($excepcion);

        if ($clasificacion === HostingerApiClient::CLASIFICACION_YA_EXISTE) {
            $this->result->ya_existia('subdominio', $subdominio);
            $this->log(
                'provision_sites',
                'El subdominio ' . $subdominio . ' ya existía, se sigue: ' . $excepcion->getMessage(),
                'warning'
            );

            return;
        }

        if ($clasificacion === HostingerApiClient::CLASIFICACION_DESCONOCIDA
            && $this->nombre_esta_en_la_zona($subdominio)) {
            $this->result->ya_existia('subdominio', $subdominio);
            $this->log(
                'provision_sites',
                'El POST de ' . $subdominio . ' falló con un error que no sé clasificar ('
                    . $excepcion->getMessage() . '), pero el nombre YA ESTÁ en la zona DNS: se toma '
                    . 'como ya existente y se sigue.',
                'warning'
            );

            return;
        }

        throw $excepcion;
    }

    /**
     * ¿El nombre está en la zona DNS? Es la verificación de último recurso ante un error que no se
     * pudo clasificar.
     *
     * Si el GET de la zona también falla, se devuelve false: ante la duda, el llamador falla.
     *
     * @param  string  $nombre
     * @return bool
     */
    private function nombre_esta_en_la_zona(string $nombre): bool
    {
        try {
            $nombres = $this->nombres_de_la_zona($this->hostinger()->get_dns_zone());
        } catch (\Throwable $excepcion) {
            return false;
        }

        return in_array($nombre, $nombres, true);
    }

    /**
     * Labels presentes en la zona DNS, normalizados.
     *
     * Es deliberadamente tolerante con la forma de la respuesta: el contrato del GET de la zona no
     * se pudo verificar (§10.1) y lo único que necesitamos de acá son los nombres. Se acepta tanto
     * un array plano de registros como uno anidado, y se normaliza el nombre a label pelado
     * (`api-lacava.comerciocity.com.` → `api-lacava`) porque cada proveedor lo devuelve distinto.
     *
     * @param  array<int|string, mixed>  $zona
     * @return array<int, string>
     */
    public function nombres_de_la_zona(array $zona): array
    {
        $nombres = [];

        foreach ($zona as $entrada) {
            if (is_array($entrada) && isset($entrada['name'])) {
                $nombres[] = $this->normalizar_nombre_de_zona((string) $entrada['name']);
                continue;
            }

            /* Una respuesta envuelta ({data: [...]}) trae los registros un nivel más adentro. */
            if (is_array($entrada)) {
                foreach ($this->nombres_de_la_zona($entrada) as $anidado) {
                    $nombres[] = $anidado;
                }
            }
        }

        return array_values(array_unique($nombres));
    }

    /**
     * `api-lacava.comerciocity.com.` → `api-lacava`.
     *
     * @param  string  $nombre
     * @return string
     */
    private function normalizar_nombre_de_zona(string $nombre): string
    {
        $nombre = rtrim(trim($nombre), '.');
        $sufijo = '.' . $this->dominio();

        if (substr($nombre, -strlen($sufijo)) === $sufijo) {
            $nombre = substr($nombre, 0, strlen($nombre) - strlen($sufijo));
        }

        return $nombre;
    }

    /**
     * ¿La base ya está en la cuenta?
     *
     * La idempotencia se verifica SIEMPRE contra el proveedor y nunca contra
     * client_apis.hosting_provisioned_at: alguien puede haber borrado la base a mano desde hPanel y
     * la columna no se entera.
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
     * La base ya estaba: se reusa si tenemos su contraseña guardada, y si no, se falla.
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
        $this->result->agregar_credenciales($secretos);
        $this->log(
            'provision_db',
            'La base ' . $nombre . ' ya existía y tengo su contraseña guardada: se reusa.',
            'warning'
        );
    }
}

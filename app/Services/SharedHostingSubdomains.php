<?php

namespace App\Services;

/**
 * Los 4 subdominios del cliente en el hosting compartido, y la verificación de que el DNS quedó
 * apuntando: la mitad de SharedHostingProvisioning que habla de nombres.
 *
 * 🔴 GUARDA G1 — ACÁ NO HAY NI UNA ESCRITURA DE DNS, Y ES EL PUNTO MÁS IMPORTANTE DE ESTE ARCHIVO.
 * En el hosting compartido Hostinger crea el A record solo al crear el subdominio, así que
 * provision_dns() hace un GET de la zona y VERIFICA que los 4 nombres estén. No existe una rama de
 * código que llegue al PUT de zona desde acá: ese PUT va sobre la zona donde viven los subdominios
 * de los ~40 clientes activos y, con hosting compartido, no hace falta para nada. Si alguien
 * "unifica" los dos proveedores y hace que este paso escriba, el test 4 de §7 se pone en rojo — y
 * ese test existe exactamente para eso.
 *
 * 🔴 Por qué está partido de SharedHostingProvisioning. La regla R2 del plan (§9) fija 450 líneas
 * por archivo nuevo de app/Services/, y con el cron de U5 adentro el archivo daba 520. Se partió
 * por la misma costura que usó U1 con el cliente HTTP: una clase abstracta con una mitad, y la
 * concreta que la extiende con la otra.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
abstract class SharedHostingSubdomains extends HostingProvisioningService
{
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
}

<?php

namespace App\Services;

use App\Models\ClientApi;
use App\Models\ClientInstallation;

/**
 * Base del aprovisionamiento del hosting de un cliente: lo que es igual en el hosting compartido y
 * en el VPS.
 *
 * Acá adentro vive lo común y nada más: la fábrica por provision_hosting_type, la generación de
 * contraseñas con el alfabeto acotado de §3.2, la persistencia cifrada de los secretos y el armado
 * del comando del cron. Todo lo que habla con un proveedor concreto —la API de Hostinger o clpctl
 * por SSH— es abstracto y lo implementan las subclases.
 *
 * La derivación del slug y las 5 guardas de §1.4 viven en HostingProvisioningStructure, que esta
 * clase instancia y delega (la partición la forzó la regla R2 de §9).
 *
 * 🔴 Por qué esto NO vive adentro de InstallationService. Esa clase ya está en 2222 líneas y el
 * aprovisionamiento no comparte nada con el pipeline actual: no usa SFTP, no usa el VPS de builds,
 * no usa el zip. Lo único que comparte es el log, y por eso el log entra por un closure en vez de
 * por herencia. InstallationService suma solo métodos de delegación de ≤ 18 líneas (regla R1, §9).
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
abstract class HostingProvisioningService
{
    /**
     * Largo de toda contraseña generada.
     *
     * @var int
     */
    const LARGO_PASSWORD = 24;

    /**
     * 🔴 Alfabeto ACOTADO a propósito, y no es paranoia de más.
     *
     * Estas contraseñas viajan por línea de comando SSH (clpctl las recibe como argumento) y por el
     * `sed -i` de EnvSshService cuando se escriben en el .env del cliente. Los caracteres que faltan
     * —`$ " ' \ ` ; | & < >` y el espacio— son exactamente los que tienen significado en alguna de
     * esas dos capas. Es más barato sacarlos del alfabeto que confiar en que tres capas de escapado
     * seguidas estén todas bien: si una falla, no falla con un error, falla con una contraseña
     * distinta de la que quedó guardada, y la base queda inaccesible sin que nada se ponga en rojo.
     *
     * Con 24 caracteres de este alfabeto la entropía sigue arriba de 140 bits.
     *
     * @var string
     */
    const ALFABETO_MAYUSCULAS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    const ALFABETO_MINUSCULAS = 'abcdefghijklmnopqrstuvwxyz';
    const ALFABETO_DIGITOS    = '0123456789';
    const ALFABETO_ESPECIALES = '._-';

    /**
     * Instalación que dispara el aprovisionamiento.
     *
     * @var ClientInstallation
     */
    protected $installation;

    /**
     * API destino de ESTA fila. Ojo: el aprovisionamiento trabaja sobre las DOS APIs del cliente,
     * no solo sobre esta. Esta es la que dice a cuál se le está instalando el sistema.
     *
     * @var ClientApi
     */
    protected $target_api;

    /**
     * Closure de log: function (string $step, string $linea, string $level): void.
     *
     * Entra por acá y no por herencia porque el log es de la instalación, no del aprovisionamiento:
     * así esta clase no sabe nada de DeploymentLog y se puede probar sin base.
     *
     * @var \Closure
     */
    protected $logger;

    /**
     * Inventario de lo que se fue creando.
     *
     * @var HostingProvisioningResult
     */
    protected $result;

    /**
     * Derivación validada del slug y de las dos ClientApi del cliente.
     *
     * Se instancia perezosamente porque las 5 guardas consultan la base y el preflight es el primer
     * lugar donde tienen que correr, no el constructor.
     *
     * @var HostingProvisioningStructure|null
     */
    private $structure = null;

    /**
     * @param  ClientInstallation  $installation
     * @param  ClientApi           $target_api
     * @param  \Closure            $logger  function (string $step, string $linea, string $level).
     */
    public function __construct(ClientInstallation $installation, ClientApi $target_api, \Closure $logger)
    {
        $this->installation = $installation;
        $this->target_api   = $target_api;
        $this->logger       = $logger;
        $this->result       = new HostingProvisioningResult();
    }

    /**
     * Fábrica: devuelve el proveedor que corresponde al provision_hosting_type de la fila.
     *
     * @param  ClientInstallation  $installation
     * @param  ClientApi           $target_api
     * @param  \Closure            $logger
     * @return HostingProvisioningService
     * @throws \RuntimeException Si la fila no pide aprovisionar, o pide un tipo desconocido.
     */
    public static function para(ClientInstallation $installation, ClientApi $target_api, \Closure $logger): self
    {
        $tipo = trim((string) $installation->provision_hosting_type);

        if ($tipo === ClientInstallation::PROVISION_SHARED_HOSTING) {
            return new SharedHostingProvisioning($installation, $target_api, $logger);
        }

        /*
         * El VPS lo construye U8. Hasta entonces esto tiene que fallar fuerte y no devolver el
         * proveedor de shared "mientras tanto": aprovisionar un cliente de VPS contra la cuenta
         * compartida crearía cuatro subdominios y una base que nadie va a usar, en el hosting de
         * los otros 40 clientes.
         */
        if ($tipo === ClientInstallation::PROVISION_VPS) {
            throw new \RuntimeException(
                'El aprovisionamiento en VPS todavía no está implementado en este servidor. '
                . 'Destildá el aprovisionamiento o elegí hosting compartido.'
            );
        }

        throw new \RuntimeException(
            'La instalación no pide aprovisionar el hosting (provision_hosting_type vacío o '
            . 'desconocido: "' . $tipo . '").'
        );
    }

    /**
     * Preflight: verifica todo lo que hace falta y NO escribe nada.
     *
     * @return void
     * @throws \RuntimeException
     */
    abstract public function provision_check(): void;

    /**
     * Crea los 4 sitios/subdominios del cliente.
     *
     * @return void
     * @throws \RuntimeException
     */
    abstract public function provision_sites(): void;

    /**
     * Deja el DNS apuntando donde tiene que apuntar.
     *
     * @return void
     * @throws \RuntimeException
     */
    abstract public function provision_dns(): void;

    /**
     * Crea la base de datos (una sola, compartida por las dos instancias) y persiste sus
     * credenciales.
     *
     * @return void
     * @throws \RuntimeException
     */
    abstract public function provision_db(): void;

    /**
     * Inventario de lo hecho en esta corrida.
     *
     * @return HostingProvisioningResult
     */
    public function result(): HostingProvisioningResult
    {
        return $this->result;
    }

    /**
     * Slug del cliente, derivado de sus dos ClientApi y validado por las 5 guardas de §1.4.
     *
     * @return string
     * @throws \RuntimeException Si la estructura del cliente no es la estándar.
     */
    public function slug(): string
    {
        return $this->structure()->slug();
    }

    /**
     * Las dos ClientApi del cliente, la 1 primero.
     *
     * @return array<int, ClientApi>
     * @throws \RuntimeException
     */
    public function apis(): array
    {
        return $this->structure()->apis();
    }

    /**
     * Los 4 labels del cliente, en el orden en que se crean.
     *
     * @return array<int, string>
     */
    public function nombres_de_subdominios(): array
    {
        return $this->structure()->nombres_de_subdominios();
    }

    /**
     * Derivación validada de los nombres del cliente.
     *
     * @return HostingProvisioningStructure
     */
    protected function structure(): HostingProvisioningStructure
    {
        if ($this->structure === null) {
            $this->structure = new HostingProvisioningStructure($this->target_api, $this->prefijo_de_base());
        }

        return $this->structure;
    }

    /**
     * Comando exacto del cron de esa instancia.
     *
     * 🔴 La regla es mecánica y decide las DOS ramas de los DOS hostings, y sale del README de
     * `claude-comerciocity/herramientas/crons-hostinger/`:
     *
     * - Kernel optimizado (programa `queue:work --stop-when-empty` adentro del schedule): va
     *   `schedule:run` SIN flock, porque el propio Kernel.php ya usa `withoutOverlapping(75)`.
     * - Kernel viejo: va el `queue:work --stop-when-empty` directo CON `flock -n` obligatorio. Sin
     *   flock, una cola que tarda más de un minuto en vaciarse apila workers una vez por minuto, que
     *   es exactamente el problema que este cron viene a evitar.
     *
     * El lock lleva el slug en el nombre porque en el hosting compartido /tmp es de toda la cuenta:
     * un lock con nombre fijo serializaría las colas de los ~40 clientes entre sí.
     *
     * @param  string  $api_path            Ruta de la API en el servidor (ClientApiPathResolver).
     * @param  bool    $kernel_optimizado   Lo dice el `grep -c stop-when-empty` sobre el Kernel.php.
     * @return string
     */
    public function comando_de_cron(string $api_path, bool $kernel_optimizado): string
    {
        $artisan = rtrim($api_path, '/') . '/artisan';

        if ($kernel_optimizado) {
            return '/usr/bin/php ' . $artisan . ' schedule:run';
        }

        return '/usr/bin/flock -n /tmp/queue-' . $this->slug() . '.lock'
            . ' /usr/bin/php ' . $artisan . ' queue:work --stop-when-empty';
    }

    /**
     * Prefijo que lleva el nombre de la base en este hosting.
     *
     * En el compartido, Hostinger obliga al prefijo de la cuenta (u767360347_). En el VPS no hay
     * prefijo (§F3 del informe de migración), por eso es un punto de extensión y no una constante.
     *
     * @return string
     */
    protected function prefijo_de_base(): string
    {
        return (string) config('services.hostinger.database_prefix', '');
    }

    /**
     * Dominio dueño de la zona DNS y de los subdominios, SIEMPRE de config (guarda G5): no hay un
     * solo camino por el que un valor de la base o de un request llegue a armar un nombre de zona.
     *
     * @return string
     */
    protected function dominio(): string
    {
        return HostingProvisioningStructure::dominio();
    }

    /**
     * Cliente HTTP de Hostinger, resuelto por el container.
     *
     * app() y no `new`: sin binding registrado el resultado es idéntico, pero habilita que un test
     * lo reemplace con $this->app->instance() y pruebe todo el armado de payloads sin salir a la red.
     *
     * @return HostingerApiClient
     */
    protected function hostinger(): HostingerApiClient
    {
        return app(HostingerApiClient::class);
    }

    /**
     * Escribe una línea en el panel de operaciones de la instalación.
     *
     * @param  string  $step
     * @param  string  $linea
     * @param  string  $level  info | warning | error | success.
     * @return void
     */
    protected function log(string $step, string $linea, string $level = 'info'): void
    {
        $logger = $this->logger;
        $logger($step, $linea, $level);
    }

    /**
     * Contraseña nueva del alfabeto acotado: 24 caracteres, con al menos una mayúscula, una
     * minúscula, un dígito y exactamente uno de `._-`.
     *
     * random_int() y no rand()/str_shuffle(): son contraseñas de bases de datos de producción y el
     * generador de PHP sin sembrar es predecible.
     *
     * @return string
     */
    protected function generar_password(): string
    {
        $alfanumerico = self::ALFABETO_MAYUSCULAS . self::ALFABETO_MINUSCULAS . self::ALFABETO_DIGITOS;

        /* Los cuatro obligatorios primero, el resto alfanumérico, y después se mezcla todo. */
        $caracteres = [
            $this->caracter_al_azar(self::ALFABETO_MAYUSCULAS),
            $this->caracter_al_azar(self::ALFABETO_MINUSCULAS),
            $this->caracter_al_azar(self::ALFABETO_DIGITOS),
            $this->caracter_al_azar(self::ALFABETO_ESPECIALES),
        ];

        while (count($caracteres) < self::LARGO_PASSWORD) {
            $caracteres[] = $this->caracter_al_azar($alfanumerico);
        }

        /* Fisher-Yates con random_int: str_shuffle usa el generador débil. */
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $temporal        = $caracteres[$i];
            $caracteres[$i]  = $caracteres[$j];
            $caracteres[$j]  = $temporal;
        }

        return implode('', $caracteres);
    }

    /**
     * @param  string  $alfabeto
     * @return string
     */
    private function caracter_al_azar(string $alfabeto): string
    {
        return $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }

    /**
     * Persiste secretos cifrados en las DOS ClientApi del cliente, en su propia escritura.
     *
     * 🔴 Esto se llama en el instante siguiente a la respuesta exitosa del proveedor y ANTES de
     * cualquier otra cosa (§3.2). No es prolijidad: la API de Hostinger no tiene endpoint para leer
     * ni para resetear la contraseña de una base ya creada. Si el proceso muere entre el POST que
     * crea la base y esta escritura, la base queda huérfana e irrecuperable y hay que borrarla a
     * mano desde hPanel. Cada llamada que crea algo con credencial tiene su persist inmediato.
     *
     * Se escribe en las dos filas porque las dos instancias del cliente comparten la misma base: el
     * blue/green del upgrade alterna cuál está activa, y la que quede sin secretos sería una
     * instalación que no puede escribir su .env.
     *
     * @param  array<string, string>  $secretos  Se mergean sobre los que ya estaban.
     * @return void
     */
    protected function persistir_secretos(array $secretos): void
    {
        $secretos['provisioned_by_installation_id'] = (string) $this->installation->id;

        foreach ($this->apis() as $api) {
            $anteriores = is_array($api->provisioning_secrets) ? $api->provisioning_secrets : [];

            $api->provisioning_secrets   = array_merge($anteriores, $secretos);
            $api->hosting_provisioned_at = now();
            $api->save();
        }

        $this->result->agregar_credenciales($secretos);
    }

    /**
     * Secretos ya guardados para este cliente (de una corrida anterior o de un reintento).
     *
     * @return array<string, string>
     */
    protected function secretos_guardados(): array
    {
        foreach ($this->apis() as $api) {
            $secretos = $api->provisioning_secrets;

            if (is_array($secretos) && $secretos !== []) {
                return $secretos;
            }
        }

        return [];
    }
}

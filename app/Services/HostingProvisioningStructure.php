<?php

namespace App\Services;

use App\Models\ClientApi;

/**
 * La estructura aprovisionable de un cliente: sus dos ClientApi, el slug derivado de ellas y los 4
 * nombres de subdominio que salen de ese slug — todo validado por las 5 guardas de §1.4 del plan.
 *
 * 🔴 Por qué está partido de HostingProvisioningService. La regla R2 del plan (§9) fija un techo de
 * 450 líneas por archivo nuevo de app/Services/, y la base daba 545. Se partió por la costura que
 * ya estaba marcada en el código: de un lado la DERIVACIÓN de los nombres del cliente (esto), del
 * otro la orquestación del aprovisionamiento. Es además la única parte que no necesita ni la
 * instalación ni el logger, y por eso se puede probar sola.
 *
 * 🔴 Por qué estas guardas existen. El aprovisionamiento deriva cuatro nombres de subdominio, una
 * base y un cron de un dato que NO está en ninguna columna: el slug. Con un par de APIs cargado con
 * nombres no estándar, esa derivación da nombres que no son los del cliente, y el paso terminaría
 * creando subdominios ajenos en la zona donde viven los ~40 clientes activos. Ante cualquier duda se
 * frena: un cliente raro se aprovisiona a mano, que es barato. Adivinar no lo es.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class HostingProvisioningStructure
{
    /**
     * Tope de largo de un nombre de base/usuario en MySQL (guarda 5).
     *
     * @var int
     */
    const TOPE_NOMBRE_MYSQL = 32;

    /**
     * API destino de la instalación. De ella sale el client_id.
     *
     * @var ClientApi
     */
    private $target_api;

    /**
     * Prefijo que el hosting le impone al nombre de la base ('u767360347_' en el compartido, vacío
     * en el VPS). Entra por constructor porque la guarda 5 lo necesita y depende del proveedor.
     *
     * @var string
     */
    private $prefijo_de_base;

    /**
     * @var string|null
     */
    private $slug = null;

    /**
     * Las dos ClientApi ordenadas: la 1 primero, la 2 después.
     *
     * @var array<int, ClientApi>|null
     */
    private $apis = null;

    /**
     * @param  ClientApi  $target_api
     * @param  string     $prefijo_de_base
     */
    public function __construct(ClientApi $target_api, string $prefijo_de_base)
    {
        $this->target_api      = $target_api;
        $this->prefijo_de_base = $prefijo_de_base;
    }

    /**
     * Slug del cliente, ya validado.
     *
     * @return string
     * @throws \RuntimeException Si la estructura del cliente no es la estándar.
     */
    public function slug(): string
    {
        if ($this->slug === null) {
            $this->derivar();
        }

        return (string) $this->slug;
    }

    /**
     * Las dos ClientApi del cliente, la 1 primero.
     *
     * @return array<int, ClientApi>
     * @throws \RuntimeException
     */
    public function apis(): array
    {
        if ($this->apis === null) {
            $this->derivar();
        }

        return (array) $this->apis;
    }

    /**
     * 🔴 GUARDA 6 — el hosting que se pidió aprovisionar tiene que ser el mismo que el de las dos
     * ClientApi del cliente. Frena ANTES de la primera escritura, de los dos lados.
     *
     * El escenario que cierra, medido el 31/8/2026: una instalación en VPS falla después de que
     * provision_sites marcó las dos ClientApi como 'vps'. El operador hace lo que manda el flujo de
     * reintento —borrar la fila fallida y crear otra— y esta vez tilda "Hosting compartido". Sin
     * esta guarda, el aprovisionamiento crea los 4 subdominios y la base EN LA CUENTA COMPARTIDA
     * (SharedHostingProvisioning fuerza la credencial 'shared_hosting') mientras el pipeline de
     * instalación sube el código AL VPS (get_api_path(), la credencial y el SFTP salen de
     * ClientApiPathResolver, o sea del hosting_type de la fila). Quedan recursos creados de los dos
     * lados y un sistema con DB_HOST=127.0.0.1 apuntando a una base que vive en el MySQL del otro
     * servidor: no bootea, y el desastre ya está hecho cuando alguien se entera.
     *
     * ⚠️ El desvío legítimo, y es UNO SOLO: pedir 'vps' sobre APIs que todavía dicen
     * 'shared_hosting'. Ese es el camino normal de la primera vez —el flip a 'vps' lo hace el propio
     * aprovisionamiento, al final de provision_sites— y tiene que seguir funcionando. El inverso,
     * pedir 'shared_hosting' sobre una API que ya dice 'vps', es el caso peligroso y es el que se
     * frena.
     *
     * 🔴 Y por eso quien la llama tiene que hacerlo ANTES del flip: si se corriera después de
     * marcar_apis_como_vps() no compararía nada.
     *
     * Mira las DOS ClientApi y no solo la destino: las dos comparten los subdominios y la base, y un
     * par a medio migrar (una en 'vps' y la otra en 'shared_hosting') es un estado que ningún
     * aprovisionamiento sabe resolver solo.
     *
     * @param  string  $pedido  provision_hosting_type de la instalación, ya trimeado.
     * @return void
     * @throws \RuntimeException
     */
    public function assert_hosting_type_coherente(string $pedido): void
    {
        foreach ($this->apis() as $api) {
            $actual = trim((string) $api->hosting_type);
            if ($actual === '') {
                $actual = 'shared_hosting';
            }

            if ($actual === $pedido) {
                continue;
            }

            /* El único desvío legítimo: el alta de un cliente que todavía vive en el compartido. */
            if ($pedido === 'vps' && $actual === 'shared_hosting') {
                continue;
            }

            throw new \RuntimeException(
                'La instalación pide aprovisionar "' . $pedido . '" pero la ClientApi '
                . $api->url . ' del cliente está marcada como "' . $actual . '". No se toca nada: '
                . 'aprovisionar en un servidor mientras el pipeline instala en el otro deja los '
                . 'subdominios y la base de un lado y el código del otro, con una base de datos que '
                . 'el sistema no puede alcanzar. Si el cliente ya está en el VPS, aprovisioná VPS; '
                . 'si de verdad volvió al hosting compartido, corregí el hosting_type de sus dos '
                . 'ClientApi antes de instalar.'
            );
        }
    }

    /**
     * Los 4 labels del cliente, en el orden en que se crean.
     *
     * @return array<int, string>
     * @throws \RuntimeException
     */
    public function nombres_de_subdominios(): array
    {
        $slug = $this->slug();

        return ['api-' . $slug, $slug, 'api-' . $slug . '2', $slug . '2'];
    }

    /**
     * Label de un host del dominio de config: 'https://lacava.comerciocity.com' → 'lacava'.
     *
     * Devuelve '' si la URL está vacía o si el host no cuelga del dominio de config. Es estático
     * porque step_write_env() lo necesita para el prefijo de Redis del VPS (§3.3) y ahí no hay
     * ningún proveedor instanciado.
     *
     * @param  string  $url
     * @return string
     */
    public static function label_de_url(string $url): string
    {
        $host = (string) parse_url(trim($url), PHP_URL_HOST);

        if ($host === '') {
            return '';
        }

        $sufijo = '.' . self::dominio();

        if (substr($host, -strlen($sufijo)) !== $sufijo) {
            return '';
        }

        return substr($host, 0, strlen($host) - strlen($sufijo));
    }

    /**
     * Dominio dueño de la zona y de los subdominios, SIEMPRE de config (guarda G5): no hay un solo
     * camino por el que un valor de la base o de un request llegue a armar un nombre de zona.
     *
     * @return string
     */
    public static function dominio(): string
    {
        return trim((string) config('services.hostinger.domain', 'comerciocity.com'));
    }

    /**
     * Las 5 guardas de §1.4, todas juntas y antes de tocar nada.
     *
     * @return void
     * @throws \RuntimeException
     */
    private function derivar(): void
    {
        $apis = ClientApi::where('client_id', $this->target_api->client_id)->orderBy('id')->get();

        /* Guarda 1 — exactamente 2 ClientApi. Con 1 o 3+ no hay forma de saber cuáles son los 4. */
        if ($apis->count() !== 2) {
            throw new \RuntimeException(
                'El cliente tiene ' . $apis->count() . ' API(s) cargada(s) y el aprovisionamiento '
                . 'necesita exactamente 2 (la instancia 1 y la 2). Corregí las ClientApi del cliente '
                . 'o aprovisioná el hosting a mano.'
            );
        }

        $primera = $apis->get(0);
        $segunda = $apis->get(1);

        $label_a = self::label_de_url((string) $primera->spa_url);
        $label_b = self::label_de_url((string) $segunda->spa_url);

        /*
         * Guarda 2 — el par tiene que ser <label> y <label>2. No se asume que la de id más bajo sea
         * la 1: se mira cuál es prefijo de cuál, porque el orden de creación no es un contrato.
         */
        if ($label_a !== '' && $label_a . '2' === $label_b) {
            $api_1 = $primera;
            $api_2 = $segunda;
            $slug  = $label_a;
        } elseif ($label_b !== '' && $label_b . '2' === $label_a) {
            $api_1 = $segunda;
            $api_2 = $primera;
            $slug  = $label_b;
        } else {
            throw new \RuntimeException(
                'Las dos APIs del cliente no forman el par estándar <slug> / <slug>2: los spa_url '
                . 'dan "' . $label_a . '" y "' . $label_b . '". Un par con nombres no estándar no se '
                . 'aprovisiona solo.'
            );
        }

        /* Guarda 3 — mismo tope de 20 y mismo alfabeto que SubdomainSuggestionService. */
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,19}$/', $slug) !== 1) {
            throw new \RuntimeException(
                'El slug derivado ("' . $slug . '") no es un subdominio válido: se esperaba entre 1 y '
                . '20 caracteres de [a-z0-9-] empezando por letra o número.'
            );
        }

        /* Guarda 4 — los hosts de `url` tienen que ser api-<slug> y api-<slug>2 del mismo dominio. */
        $this->assert_host_de_api($api_1, 'api-' . $slug);
        $this->assert_host_de_api($api_2, 'api-' . $slug . '2');

        /*
         * Guarda 5 — tope de MySQL. Con un slug de ≤ 20 y el prefijo de la cuenta siempre entra,
         * pero se chequea igual: el slug sale de la base del admin, no del validador del alta, así
         * que nadie garantiza que pasó por ahí.
         */
        $nombre_de_base = $this->prefijo_de_base . $slug;
        if (strlen($nombre_de_base) > self::TOPE_NOMBRE_MYSQL) {
            throw new \RuntimeException(
                'El nombre de base derivado ("' . $nombre_de_base . '") tiene ' . strlen($nombre_de_base)
                . ' caracteres y MySQL admite hasta ' . self::TOPE_NOMBRE_MYSQL . '.'
            );
        }

        $this->slug = $slug;
        $this->apis = [$api_1, $api_2];
    }

    /**
     * Guarda 4: el host de `url` de esa fila es exactamente <esperado>.<dominio de config>.
     *
     * @param  ClientApi  $api
     * @param  string     $label_esperado
     * @return void
     * @throws \RuntimeException
     */
    private function assert_host_de_api(ClientApi $api, string $label_esperado): void
    {
        $label = self::label_de_url((string) $api->url);

        if ($label !== $label_esperado) {
            throw new \RuntimeException(
                'La ClientApi ' . $api->id . ' tiene url "' . $api->url . '" y el aprovisionamiento '
                . 'esperaba el host "' . $label_esperado . '.' . self::dominio() . '". No se '
                . 'aprovisiona un cliente cuyos subdominios no siguen la convención.'
            );
        }
    }
}

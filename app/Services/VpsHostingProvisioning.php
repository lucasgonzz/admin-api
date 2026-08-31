<?php

namespace App\Services;

/**
 * Aprovisionamiento del hosting en el VPS propio, y —lo que importa de este archivo— EL ÚNICO PUT
 * DE ZONA DNS DE TODO EL SISTEMA, con las 8 guardas de §5 del plan.
 *
 * 🔴 LEER ESTO ANTES DE TOCAR provision_dns().
 *
 * `PUT /api/dns/v1/zones/comerciocity.com` va sobre la zona donde viven los subdominios de los ~40
 * clientes activos. Es la única operación irreversible de toda la misión: si esa llamada resultara
 * ser un reemplazo total de la zona en vez de un agregado —cosa que NO se pudo verificar, §10.3 del
 * plan, porque el token todavía no existe— una corrida mal armada deja al ERP y a la tienda de los
 * 40 clientes sin resolver DNS, de una, hasta que alguien restaure el snapshot a mano desde hPanel.
 *
 * Por eso hay ocho guardas y no una, todas antes de la llamada y todas con test propio:
 *
 *   G1 — El PUT no existe para hosting compartido. No hay una rama de código que llegue acá con
 *        hosting_type='shared_hosting': el shared vive en SharedHostingSubdomains y ahí el DNS es de
 *        solo lectura. Es la guarda más fuerte porque elimina el 100% de los casos de uso actuales.
 *   G2 — Interruptor apagado por default (services.hostinger.dns_write_enabled), chequeado en el
 *        preflight y otra vez acá. Vive en VpsSiteProvisioner::assert_dns_write_enabled().
 *   G3 — Lista blanca de nombres CALCULADA, no recibida: cada registro tiene que ser type A y su
 *        name tiene que estar entre los 4 labels derivados en §1.4.
 *   G4 — Tope de cardinalidad: entre 1 y 4 registros. Un bug que arme el cuerpo con la zona entera
 *        muere acá.
 *   G5 — El dominio sale de config y jamás de un request (vive en HostingerHttpTransport).
 *   G6 — overwrite:false SIEMPRE. El literal `true` no aparece en el código y put_dns_zone() ni
 *        siquiera tiene el parámetro, justamente para que no se pueda introducir sin editar esa
 *        línea.
 *   G7 — Snapshot obligatorio antes, y si el snapshot falla NO SE ESCRIBE.
 *   G8 — Verificación posterior por diferencia de conjuntos. Si desapareció un solo registro:
 *        línea `error` con los nombres perdidos y el id del snapshot, y la etapa falla. NO se
 *        intenta restaurar solo.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class VpsHostingProvisioning extends VpsDatabaseProvisioner
{
    /**
     * TTL de los A records que se crean. 14400 s (4 h) es el default de la zona de Hostinger.
     *
     * @var int
     */
    const TTL = 14400;

    /**
     * Nombres que se rechazan explícitamente, además de la lista blanca (G3).
     *
     * Están escritos aunque la lista blanca ya los dejaría afuera: son los cuatro que, si por un bug
     * llegaran al cuerpo, no romperían un cliente sino la zona entera. '@' es la raíz del dominio,
     * '*' el comodín que atrapa TODO lo que no matchea otro registro, 'www' el sitio institucional,
     * y el vacío es lo que devuelve una derivación de slug que falló en silencio.
     *
     * @var array<int, string>
     */
    const NOMBRES_PROHIBIDOS = ['@', '*', 'www', ''];

    /**
     * Deja los 4 A records del cliente apuntando al VPS.
     *
     * @return void
     * @throws \RuntimeException
     */
    public function provision_dns(): void
    {
        /* G2 — otra vez acá, y no solo en el preflight: esto protege de una llamada por afuera. */
        $this->assert_dns_write_enabled();

        $this->log('provision_dns', 'Leyendo la zona DNS de ' . $this->dominio() . '...');

        $zona_antes = new DnsZoneRecords($this->hostinger()->get_dns_zone());
        $antes      = $zona_antes->pares();

        $registros = $this->registros_a_escribir($zona_antes);

        /*
         * Si los 4 ya apuntaban al VPS no se escribe nada y ni siquiera se pide el snapshot: un PUT
         * que no cambia nada sigue siendo un PUT sobre la zona de los 40 clientes.
         */
        if ($registros === []) {
            $this->log('provision_dns', 'Los 4 A records ya apuntaban al VPS: no se escribe nada.', 'success');

            return;
        }

        $this->assert_lista_blanca($registros);
        $this->assert_cardinalidad($registros);

        $snapshot = $this->tomar_snapshot();

        $this->log(
            'provision_dns',
            'Escribiendo ' . count($registros) . ' A record(s) (overwrite=false): '
                . implode(', ', $this->nombres_de($registros)) . '.'
        );

        $this->hostinger()->put_dns_zone($registros);

        $this->assert_no_se_perdio_nada($antes, $snapshot);

        foreach ($this->nombres_de($registros) as $nombre) {
            $this->result->creado('a_record', $nombre);
        }

        $this->log('provision_dns', 'La zona quedó con los 4 A records del cliente.', 'success');
    }

    /**
     * Los registros que hay que agregar, y la decisión de qué hacer con los que ya están.
     *
     * 🔴 Un nombre que ya existe apuntando a OTRA IP hace fallar la etapa y NO se repunta. Repuntar
     * un A record existente es mover el tráfico de producción de un cliente que hoy anda, y este
     * paso solo sirve para dar de alta clientes nuevos. Si el nombre es de verdad de este cliente y
     * hay que moverlo, eso es una migración y tiene su propio procedimiento.
     *
     * @param  DnsZoneRecords  $zona
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException
     */
    private function registros_a_escribir(DnsZoneRecords $zona): array
    {
        $ip         = $this->ip_del_vps();
        $existentes = $zona->contenidos_por_nombre('A');
        $registros  = [];

        foreach ($this->nombres_de_subdominios() as $nombre) {
            if (! isset($existentes[$nombre])) {
                $registros[] = [
                    'name'    => $nombre,
                    'type'    => 'A',
                    'ttl'     => self::TTL,
                    'records' => [['content' => $ip]],
                ];

                continue;
            }

            if (in_array($ip, $existentes[$nombre], true)) {
                $this->log('provision_dns', 'El A record ' . $nombre . ' ya apuntaba a ' . $ip . '.');

                continue;
            }

            throw new \RuntimeException(
                'El A record "' . $nombre . '" ya existe en la zona de ' . $this->dominio()
                . ' apuntando a ' . implode(', ', $existentes[$nombre]) . ' y no a ' . $ip . '. '
                . 'NO se repunta desde acá: mover un A record existente es mover el tráfico de '
                . 'producción de quien lo esté usando. Miralo en hPanel → DNS y decidí a mano.'
            );
        }

        return $registros;
    }

    /**
     * 🔴 GUARDA G3 — lista blanca de nombres, calculada acá y no recibida de ningún lado.
     *
     * El cuerpo del PUT se arma en un solo método (registros_a_escribir) y esto lo revisa igual,
     * justo antes de serializar. Es redundante a propósito: la redundancia es barata y la
     * alternativa es que un bug futuro en el armado del cuerpo llegue a la zona de los 40 clientes.
     *
     * @param  array<int, array<string, mixed>>  $registros
     * @return void
     * @throws \RuntimeException
     */
    private function assert_lista_blanca(array $registros): void
    {
        $permitidos = $this->nombres_de_subdominios();

        foreach ($registros as $registro) {
            $tipo   = isset($registro['type']) ? (string) $registro['type'] : '';
            $nombre = isset($registro['name']) ? (string) $registro['name'] : '';

            if ($tipo !== 'A') {
                throw new \RuntimeException(
                    'El aprovisionamiento solo escribe registros A y se armó uno de tipo "' . $tipo
                    . '". Se frena antes de tocar la zona de ' . $this->dominio() . '.'
                );
            }

            if (in_array($nombre, self::NOMBRES_PROHIBIDOS, true)) {
                throw new \RuntimeException(
                    'El nombre "' . $nombre . '" está prohibido en el cuerpo del PUT de DNS: no es '
                    . 'un subdominio de un cliente sino la raíz, el comodín o el sitio '
                    . 'institucional de ' . $this->dominio() . '.'
                );
            }

            /* Tiene que ser el label pelado: un name con el dominio adentro es otra cosa. */
            if (strpos($nombre, $this->dominio()) !== false) {
                throw new \RuntimeException(
                    'El nombre "' . $nombre . '" trae el dominio adentro y el PUT espera el label '
                    . 'pelado (ej: "api-lacava"). Se frena antes de tocar la zona.'
                );
            }

            if (! in_array($nombre, $permitidos, true)) {
                throw new \RuntimeException(
                    'El nombre "' . $nombre . '" no está en la lista blanca del cliente ('
                    . implode(', ', $permitidos) . '). El PUT de DNS solo escribe los 4 nombres '
                    . 'derivados de las ClientApi del cliente que se está instalando.'
                );
            }
        }
    }

    /**
     * 🔴 GUARDA G4 — tope de cardinalidad. Un bug que arme el cuerpo con la zona entera muere acá,
     * aunque todos esos nombres pasaran la lista blanca por casualidad.
     *
     * @param  array<int, array<string, mixed>>  $registros
     * @return void
     * @throws \RuntimeException
     */
    private function assert_cardinalidad(array $registros): void
    {
        $cantidad = count($registros);

        if ($cantidad >= 1 && $cantidad <= 4) {
            return;
        }

        throw new \RuntimeException(
            'El cuerpo del PUT de DNS quedó con ' . $cantidad . ' registro(s) y un cliente tiene '
            . 'entre 1 y 4. Se frena antes de tocar la zona de ' . $this->dominio() . '.'
        );
    }

    /**
     * 🔴 GUARDA G7 — snapshot obligatorio, y si falla NO SE ESCRIBE.
     *
     * Es la única forma de volver atrás de este PUT. El id se loguea en el panel porque es lo que
     * una persona va a necesitar tipear en hPanel a las tres de la mañana.
     *
     * @return string  Id del snapshot, o '(sin id)' si la API no lo devolvió.
     * @throws \RuntimeException Si el snapshot falla.
     */
    private function tomar_snapshot(): string
    {
        try {
            $respuesta = $this->hostinger()->create_dns_snapshot();
        } catch (\Throwable $excepcion) {
            throw new \RuntimeException(
                'No se pudo tomar el snapshot de la zona de ' . $this->dominio() . ', así que NO se '
                . 'escribe nada: sin snapshot no hay forma de volver atrás de un PUT sobre la zona '
                . 'donde viven los subdominios de todos los clientes. Error: '
                . $excepcion->getMessage()
            );
        }

        $id = '';
        foreach (['id', 'snapshot_id', 'uid'] as $clave) {
            if (isset($respuesta[$clave]) && (string) $respuesta[$clave] !== '') {
                $id = (string) $respuesta[$clave];
                break;
            }
        }

        if ($id === '') {
            /*
             * El snapshot se tomó (la llamada no falló) pero no sabemos cómo se llama. Se sigue,
             * porque el respaldo existe igual, pero con un warning: si después hay que restaurar,
             * hay que buscarlo por fecha en hPanel.
             */
            $this->log(
                'provision_dns',
                'El snapshot de la zona se tomó pero la API no devolvió su id. Si hay que '
                    . 'restaurar, buscalo por fecha en hPanel → DNS → Snapshots.',
                'warning'
            );

            return '(sin id)';
        }

        $this->log(
            'provision_dns',
            'Snapshot de la zona tomado antes de escribir. 🔴 Id: ' . $id . ' — es lo que hay que '
                . 'restaurar en hPanel si algo sale mal.',
            'success'
        );

        return $id;
    }

    /**
     * 🔴 GUARDA G8 — verificación posterior por diferencia de conjuntos.
     *
     * No alcanza con que el PUT devuelva 200: lo que hay que saber es si la zona PERDIÓ algo, que es
     * lo que pasaría si el PUT resultara ser un reemplazo total (§10.3). Se comparan los pares
     * (name, type) de antes contra los de después: todos los de antes tienen que seguir estando.
     *
     * 🔴 Si falta uno solo, se falla Y NO SE INTENTA RESTAURAR SOLO. Un restore automático sobre una
     * zona a medio arreglar —con los registros nuevos ya escritos y los viejos a saber en qué
     * estado— es peor que el problema: lo correcto es que una persona mire el snapshot y decida.
     *
     * @param  array<int, string>  $antes     Pares 'name|type' de antes del PUT.
     * @param  string              $snapshot  Id del snapshot que se tomó.
     * @return void
     * @throws \RuntimeException
     */
    private function assert_no_se_perdio_nada(array $antes, string $snapshot): void
    {
        /*
         * 🔴 El GET va envuelto, y no por prolijidad. Este método arranca releyendo la zona, y si
         * ESA lectura falla —un 502 del proveedor, un timeout— hasta el 31/8/2026 subía la
         * excepción cruda del transporte ("La API de Hostinger respondió 502"). El operador la leía
         * en el paso provision_dns y concluía, razonablemente, que no se había escrito nada; pero
         * el PUT YA SE EJECUTÓ una línea más arriba. Era el único camino ciego de G8: el estado del
         * mundo cambió y el mensaje decía lo contrario.
         */
        try {
            $zona_despues = $this->hostinger()->get_dns_zone();
        } catch (\Throwable $excepcion) {
            throw new \RuntimeException(
                '🔴 EL PUT SOBRE LA ZONA DE ' . $this->dominio() . ' YA SE EJECUTÓ, y lo que falló '
                . 'fue la verificación posterior: no se pudo volver a leer la zona para comprobar '
                . 'que no se perdió ningún registro. NO des por hecho que no se escribió nada. '
                . 'Entrá a hPanel → DNS y miralo a mano; si algo falta, restaurá el snapshot '
                . $snapshot . '. Error de la lectura: ' . $excepcion->getMessage()
            );
        }

        $despues  = (new DnsZoneRecords($zona_despues))->pares();
        $perdidos = array_values(array_diff($antes, $despues));

        if ($perdidos === [] && count($despues) >= count($antes)) {
            $this->log(
                'provision_dns',
                'Verificación posterior OK: la zona tenía ' . count($antes) . ' registro(s) y ahora '
                    . 'tiene ' . count($despues) . '. No se perdió ninguno.'
            );

            return;
        }

        $mensaje = '🔴 LA ZONA DNS DE ' . $this->dominio() . ' PERDIÓ REGISTROS con este PUT. '
            . 'Faltan: ' . ($perdidos === [] ? '(ninguno por nombre, pero bajó la cantidad: '
                . count($antes) . ' → ' . count($despues) . ')' : implode(', ', $perdidos)) . '. '
            . 'Restaurá el snapshot ' . $snapshot . ' desde hPanel → DNS → Snapshots AHORA: cada '
            . 'registro perdido es un cliente que dejó de resolver. El pipeline NO restaura solo, a '
            . 'propósito: un restore automático sobre una zona a medio arreglar es peor que el '
            . 'problema.';

        $this->log('provision_dns', $mensaje, 'error');

        throw new \RuntimeException($mensaje);
    }

    /**
     * Los nombres de una lista de registros.
     *
     * @param  array<int, array<string, mixed>>  $registros
     * @return array<int, string>
     */
    private function nombres_de(array $registros): array
    {
        $nombres = [];

        foreach ($registros as $registro) {
            $nombres[] = isset($registro['name']) ? (string) $registro['name'] : '';
        }

        return $nombres;
    }
}

<?php

namespace App\Services\Prueba;

use App\Services\DnsZoneRecords;
use App\Services\HostingerApiClient;
use Illuminate\Support\Facades\Log;

/**
 * El DNS de la prueba gratuita (decisión D9 del plan de `prueba-alta-instancia`, 3/11/2026): los A
 * records del slug del negocio y de los nombres del pool, con las MISMAS guardas que
 * `VpsHostingProvisioning::provision_dns()` y un interruptor propio.
 *
 *  - Interruptor `PRUEBA_DNS_WRITE_ENABLED` (`services.prueba.dns_write_enabled`), independiente del de
 *    las instalaciones: apagado por default, y en producción se prende solo cuando el pool y las altas
 *    están en servicio.
 *  - Lista blanca CALCULADA: los 4 labels de un slug (`<slug>`, `api-<slug>`, `tienda-<slug>`,
 *    `api-tienda-<slug>`) o los 4 de un nombre del pool (`prueba-NN`, `api-prueba-NN`, ...). Nada más.
 *    `@`, `*`, `www` y el vacío están prohibidos aunque alguna derivación los produjera.
 *  - Cardinalidad 1 a 4.
 *  - Respaldo de la zona entera en `storage/app/dns-zonas/` ANTES de escribir o borrar; sin respaldo no se
 *    toca nada.
 *  - Verificación posterior por diferencia de conjuntos: al crear no puede faltar ninguno de los pares que
 *    estaban; al borrar solo pueden faltar los que se pidieron.
 *  - TTL 300 (D9): el alta no puede esperar 4 horas a que un resolver suelte el registro.
 *  - `overwrite: false` SIEMPRE: un nombre que ya existe apuntando a otra IP hace fallar, no se repunta.
 *
 * Es transporte + guardas; el cliente HTTP es `HostingerApiClient`, resuelto por el container para que
 * los tests lo reemplacen.
 */
class PruebaDnsService
{
    const TTL = 300;

    const NOMBRES_PROHIBIDOS = ['@', '*', 'www', ''];

    /**
     * Crea los A records que falten para una base (slug del negocio o nombre del pool).
     *
     * @param string $base Slug (`lacava`) o nombre (`prueba-01`).
     *
     * @return array<int, string> Labels que se escribieron (vacío si ya estaban todos).
     */
    public function crear_registros(string $base): array
    {
        $this->assert_write_enabled();

        $permitidos = $this->labels_permitidos($base);
        $ip         = $this->ip_del_vps();

        $zona_antes = new DnsZoneRecords($this->hostinger()->get_dns_zone());
        $antes      = $zona_antes->pares();
        $existentes = $zona_antes->contenidos_por_nombre('A');

        $registros = [];
        foreach ($permitidos as $label) {
            if (! isset($existentes[$label])) {
                $registros[] = ['name' => $label, 'type' => 'A', 'ttl' => self::TTL, 'records' => [['content' => $ip]]];

                continue;
            }
            if (in_array($ip, $existentes[$label], true)) {
                continue;
            }

            throw new \RuntimeException(
                'El A record "' . $label . '" ya existe en la zona de ' . $this->dominio() . ' apuntando a '
                . implode(', ', $existentes[$label]) . ' y no a ' . $ip . '. No se repunta desde acá: ese nombre '
                . 'es de otro. Elegí otro slug o miralo en hPanel → DNS.'
            );
        }

        if ($registros === []) {
            Log::info('PruebaDnsService: los A records de ' . $base . ' ya apuntaban al VPS; no se escribe nada.');

            return [];
        }

        $this->assert_lista_blanca($registros, $permitidos);
        $this->assert_cardinalidad(count($registros));

        $respaldo = $this->respaldar_zona($zona_antes, 'alta-' . $base);

        $this->hostinger()->put_dns_zone($registros);

        $despues  = (new DnsZoneRecords($this->hostinger()->get_dns_zone()))->pares();
        $perdidos = array_values(array_diff($antes, $despues));

        if ($perdidos !== [] || count($despues) < count($antes)) {
            $mensaje = '🔴 LA ZONA DNS DE ' . $this->dominio() . ' PERDIÓ REGISTROS al escribir los de ' . $base
                . '. Faltan: ' . ($perdidos === [] ? '(bajó la cantidad: ' . count($antes) . ' → ' . count($despues) . ')' : implode(', ', $perdidos))
                . '. VOLVÉ ATRÁS con el respaldo ' . $respaldo . '. No se restaura solo, a propósito.';
            Log::error($mensaje);

            throw new \RuntimeException($mensaje);
        }

        $nombres = array_map(function ($registro) {
            return (string) $registro['name'];
        }, $registros);

        Log::info('PruebaDnsService: A records escritos.', ['base' => $base, 'labels' => $nombres, 'respaldo' => $respaldo]);

        return $nombres;
    }

    /**
     * Borra los A records de una base (slug o nombre del pool) que existan y apunten al VPS.
     *
     * Un label que apunta a OTRA IP no se borra: no es nuestro.
     *
     * @return array<int, string> Labels borrados.
     */
    public function borrar_registros(string $base): array
    {
        $this->assert_write_enabled();

        $permitidos = $this->labels_permitidos($base);
        $ip         = $this->ip_del_vps();

        $zona_antes = new DnsZoneRecords($this->hostinger()->get_dns_zone());
        $antes      = $zona_antes->pares();
        $existentes = $zona_antes->contenidos_por_nombre('A');

        $filtros = [];
        foreach ($permitidos as $label) {
            if (! isset($existentes[$label])) {
                continue;
            }
            if (! in_array($ip, $existentes[$label], true)) {
                Log::warning('PruebaDnsService: el A record ' . $label . ' no apunta al VPS; no se borra.', ['contenidos' => $existentes[$label]]);

                continue;
            }
            $filtros[] = ['name' => $label, 'type' => 'A'];
        }

        if ($filtros === []) {
            Log::info('PruebaDnsService: no hay A records de ' . $base . ' que borrar.');

            return [];
        }

        $this->assert_lista_blanca($filtros, $permitidos);
        $this->assert_cardinalidad(count($filtros));

        $respaldo = $this->respaldar_zona($zona_antes, 'baja-' . $base);

        $this->hostinger()->delete_dns_records($filtros);

        $despues   = (new DnsZoneRecords($this->hostinger()->get_dns_zone()))->pares();
        $borrados  = array_map(function ($filtro) {
            return $filtro['name'] . '|A';
        }, $filtros);
        $esperados = array_values(array_diff($antes, $borrados));
        $perdidos  = array_values(array_diff($esperados, $despues));

        if ($perdidos !== []) {
            $mensaje = '🔴 LA ZONA DNS DE ' . $this->dominio() . ' PERDIÓ REGISTROS QUE NO SE PIDIERON al borrar los de '
                . $base . '. Faltan: ' . implode(', ', $perdidos) . '. VOLVÉ ATRÁS con el respaldo ' . $respaldo
                . '. No se restaura solo, a propósito.';
            Log::error($mensaje);

            throw new \RuntimeException($mensaje);
        }

        $nombres = array_map(function ($filtro) {
            return (string) $filtro['name'];
        }, $filtros);

        Log::info('PruebaDnsService: A records borrados.', ['base' => $base, 'labels' => $nombres, 'respaldo' => $respaldo]);

        return $nombres;
    }

    /**
     * La lista blanca de una base: los 4 labels del slug o del nombre del pool. Cualquier otra base
     * es inválida y no llega a ningún lado.
     *
     * @return array<int, string>
     */
    public function labels_permitidos(string $base): array
    {
        if (PruebaNombres::es_nombre_valido($base)) {
            return array_values(PruebaNombres::labels_del_pool($base));
        }

        if (PruebaNombres::es_slug_valido($base)) {
            return array_values(PruebaNombres::labels_del_slug($base));
        }

        throw new \InvalidArgumentException('Base de DNS inválida: "' . $base . '" (ni slug ni prueba-NN).');
    }

    public function write_enabled(): bool
    {
        return (bool) config('services.prueba.dns_write_enabled', false);
    }

    protected function assert_write_enabled(): void
    {
        if ($this->write_enabled()) {
            return;
        }

        throw new \RuntimeException(
            'La escritura del DNS de las pruebas está deshabilitada (PRUEBA_DNS_WRITE_ENABLED). Prenderla habilita '
            . 'escribir y borrar A records en la zona de ' . $this->dominio() . ', donde viven los subdominios de '
            . 'todos los clientes. Poné PRUEBA_DNS_WRITE_ENABLED=true en el .env de admin-api y reiniciá el worker.'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $registros Registros o filtros, con `name` y `type`.
     * @param array<int, string>               $permitidos
     */
    private function assert_lista_blanca(array $registros, array $permitidos): void
    {
        foreach ($registros as $registro) {
            $nombre = isset($registro['name']) ? (string) $registro['name'] : '';
            $tipo   = isset($registro['type']) ? (string) $registro['type'] : '';

            if ($tipo !== 'A') {
                throw new \RuntimeException('La prueba gratuita solo toca registros A y se armó uno de tipo "' . $tipo . '".');
            }
            if (in_array($nombre, self::NOMBRES_PROHIBIDOS, true)) {
                throw new \RuntimeException('El nombre "' . $nombre . '" está prohibido en el DNS de las pruebas.');
            }
            if (strpos($nombre, $this->dominio()) !== false) {
                throw new \RuntimeException('El nombre "' . $nombre . '" trae el dominio adentro; se espera el label pelado.');
            }
            if (! in_array($nombre, $permitidos, true)) {
                throw new \RuntimeException(
                    'El nombre "' . $nombre . '" no está en la lista blanca (' . implode(', ', $permitidos) . ').'
                );
            }
        }
    }

    private function assert_cardinalidad(int $cantidad): void
    {
        if ($cantidad >= 1 && $cantidad <= 4) {
            return;
        }

        throw new \RuntimeException('Una prueba tiene entre 1 y 4 registros DNS y se armaron ' . $cantidad . '.');
    }

    /** Guarda la zona entera, tal como vino, antes de tocarla. Sin respaldo no se escribe. */
    private function respaldar_zona(DnsZoneRecords $zona, string $motivo): string
    {
        $directorio = storage_path('app/dns-zonas');
        if (! is_dir($directorio) && ! @mkdir($directorio, 0755, true) && ! is_dir($directorio)) {
            throw new \RuntimeException('No se pudo crear ' . $directorio . ' para respaldar la zona DNS: no se toca nada.');
        }

        $archivo = $directorio . '/' . $this->dominio() . '-prueba-' . preg_replace('/[^a-z0-9-]/', '', $motivo) . '-' . date('Ymd-His') . '.json';
        $crudo   = json_encode($zona->crudos(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($crudo === false || file_put_contents($archivo, $crudo) === false) {
            throw new \RuntimeException('No se pudo guardar el respaldo de la zona en ' . $archivo . ': no se toca nada.');
        }

        return $archivo;
    }

    private function ip_del_vps(): string
    {
        $ip = trim((string) config('services.hostinger.vps_ip', ''));
        if ($ip === '') {
            throw new \RuntimeException('Falta HOSTINGER_VPS_IP en el .env de admin-api.');
        }

        return $ip;
    }

    private function dominio(): string
    {
        return PruebaNombres::dominio();
    }

    protected function hostinger(): HostingerApiClient
    {
        return app(HostingerApiClient::class);
    }
}

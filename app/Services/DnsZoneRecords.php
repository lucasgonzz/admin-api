<?php

namespace App\Services;

/**
 * Lectura de la respuesta del GET de una zona DNS de Hostinger: la aplana, normaliza los nombres a
 * label pelado y expone lo único que el aprovisionamiento necesita saber de ella.
 *
 * 🔴 Por qué es una clase aparte y no métodos privados del proveedor. Dos motivos:
 *
 * 1. La necesitan las DOS ramas. El hosting compartido lee la zona para verificar que los 4 A
 *    records estén (guarda G1: ahí el DNS es de solo lectura) y el VPS la lee dos veces, antes y
 *    después del PUT, para la verificación por diferencia de conjuntos (guarda G8). Dos copias de
 *    este parseo es exactamente lo que después diverge sin que nadie lo note — y una divergencia
 *    acá significa que G8 compara mal y no denuncia una zona que perdió registros.
 * 2. La regla R2 del plan (§9) fija 450 líneas por archivo nuevo de app/Services/, y con esto
 *    adentro VpsHostingProvisioning daba 474.
 *
 * ⚠️ Es DELIBERADAMENTE TOLERANTE con la forma de la respuesta. El contrato del GET de zona no se
 * pudo verificar (§10.1 del plan: el token todavía no existe), así que se acepta el array plano de
 * registros, el envuelto ({data: [...]}), el contenido en `content` pelado y el contenido en
 * `records[].content`. Lo que NO se hace es adivinar: un registro sin `name` reconocible
 * simplemente no entra, y el llamador ve que falta.
 *
 * PHP 7.4: sin `?->`, `match`, `str_contains`, argumentos nombrados, union types, promoción en
 * constructor, `readonly`, `enum`, atributos, `mixed` ni `never`.
 */
class DnsZoneRecords
{
    /**
     * Registros ya aplanados: [['name' => 'api-lacava', 'type' => 'A', 'contenidos' => ['1.2.3.4']]].
     *
     * @var array<int, array<string, mixed>>
     */
    private $registros;

    /**
     * @param  array<int|string, mixed>  $zona  Respuesta cruda del GET de la zona.
     */
    public function __construct(array $zona)
    {
        $this->registros = $this->aplanar($zona);
    }

    /**
     * Labels presentes en la zona, sin repetir.
     *
     * @return array<int, string>
     */
    public function nombres(): array
    {
        $nombres = [];

        foreach ($this->registros as $registro) {
            $nombres[] = $registro['name'];
        }

        return array_values(array_unique($nombres));
    }

    /**
     * Los pares 'name|type' de la zona, sin repetir.
     *
     * Es el conjunto con el que la guarda G8 compara el antes y el después del PUT: si un par que
     * estaba ya no está, la zona perdió un registro y hay un cliente que dejó de resolver.
     *
     * @return array<int, string>
     */
    public function pares(): array
    {
        $pares = [];

        foreach ($this->registros as $registro) {
            $pares[] = $registro['name'] . '|' . $registro['type'];
        }

        return array_values(array_unique($pares));
    }

    /**
     * Los contenidos de los registros de un tipo, indexados por nombre.
     *
     * @param  string  $tipo  'A', 'CNAME', etc.
     * @return array<string, array<int, string>>
     */
    public function contenidos_por_nombre(string $tipo): array
    {
        $por_nombre = [];

        foreach ($this->registros as $registro) {
            if ($registro['type'] !== $tipo) {
                continue;
            }

            $nombre = $registro['name'];

            if (! isset($por_nombre[$nombre])) {
                $por_nombre[$nombre] = [];
            }

            foreach ($registro['contenidos'] as $contenido) {
                $por_nombre[$nombre][] = $contenido;
            }
        }

        return $por_nombre;
    }

    /**
     * Recorre la respuesta, entre a la profundidad que entre, y saca los registros.
     *
     * @param  array<int|string, mixed>  $zona
     * @return array<int, array<string, mixed>>
     */
    private function aplanar(array $zona): array
    {
        $registros = [];

        foreach ($zona as $entrada) {
            if (! is_array($entrada)) {
                continue;
            }

            if (isset($entrada['name'])) {
                $registros[] = [
                    'name'       => $this->label_pelado((string) $entrada['name']),
                    'type'       => isset($entrada['type']) ? (string) $entrada['type'] : '',
                    'contenidos' => $this->contenidos_de($entrada),
                ];

                continue;
            }

            /* Una respuesta envuelta ({data: [...]}) trae los registros un nivel más adentro. */
            foreach ($this->aplanar($entrada) as $anidado) {
                $registros[] = $anidado;
            }
        }

        return $registros;
    }

    /**
     * Los valores de un registro, en cualquiera de las dos formas posibles.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<int, string>
     */
    private function contenidos_de(array $entrada): array
    {
        if (isset($entrada['content'])) {
            return [trim((string) $entrada['content'])];
        }

        $contenidos = [];

        if (isset($entrada['records']) && is_array($entrada['records'])) {
            foreach ($entrada['records'] as $sub) {
                if (is_array($sub) && isset($sub['content'])) {
                    $contenidos[] = trim((string) $sub['content']);
                }
            }
        }

        return $contenidos;
    }

    /**
     * 'api-lacava.comerciocity.com.' → 'api-lacava'.
     *
     * El dominio sale de config (guarda G5), igual que en todo el resto del aprovisionamiento.
     *
     * @param  string  $nombre
     * @return string
     */
    private function label_pelado(string $nombre): string
    {
        $nombre = rtrim(trim($nombre), '.');
        $sufijo = '.' . HostingProvisioningStructure::dominio();

        if (substr($nombre, -strlen($sufijo)) === $sufijo) {
            $nombre = substr($nombre, 0, strlen($nombre) - strlen($sufijo));
        }

        return $nombre;
    }
}

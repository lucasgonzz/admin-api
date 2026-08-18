<?php

namespace App\Services;

/**
 * Compara y valida códigos de versión semántica (`"3.3.1"`, `"3.3.1.2"`, ...) por su
 * VALOR NUMÉRICO componente a componente, no por el `id` de la fila en `versions` ni
 * por orden alfabético de string.
 *
 * 🔴 POR QUÉ EXISTE ESTA CLASE (18/8/2026)
 *
 * `VersionPathService::versionsInRange()` armaba el rango de una actualización filtrando
 * por `id` de tabla (`WHERE id > from_id AND id <= to_id`). Eso funciona solo si las
 * versiones se cargan siempre en el mismo orden en que se van a publicar — y con
 * hotfixes de por medio (una versión "3.3.1.1" cargada después de "3.3.2", por ejemplo)
 * deja de ser cierto: el `id` ya no refleja el orden semántico del código de versión.
 *
 * Esta clase es el único lugar del repo que sabe comparar dos códigos de versión. Es
 * estática y sin dependencias de Eloquent a propósito: la usan tanto el service que
 * arma el rango (que sí toca la base) como, en el futuro, cualquier código que solo
 * necesite comparar dos strings sueltos.
 */
class VersionNumberComparator
{
    /**
     * Un código de versión válido tiene al menos 3 componentes numéricos separados
     * por puntos (`"3.3.1"` en adelante). Dos componentes (`"3.3"`) no alcanza: es el
     * mismo criterio que ya usaba `maxlength=30` en el form, ahora hecho explícito.
     *
     * @var string
     */
    const VALID_REGEX = '/^\d+(\.\d+){2,}$/';

    /**
     * Descompone un código de versión en sus componentes numéricos.
     *
     * No rellena con ceros: devuelve los componentes tal como vienen, crudos. El
     * relleno para comparar longitudes distintas es responsabilidad de `compare()`,
     * no de este método.
     *
     * @param string|null $version Código de versión (ej. `"3.3.1.2"`). `null` o cadena
     *                             vacía (después de `trim`) devuelven un array vacío.
     *
     * @return array<int, int> Componentes en orden, como enteros. Ej.: `"3.3.3.4"` ->
     *                         `[3, 3, 3, 4]`; `"3.3.3"` -> `[3, 3, 3]`; `null` -> `[]`.
     */
    public static function parse(?string $version): array
    {
        $version = trim((string) $version);
        if ($version === '') {
            return [];
        }

        return array_map('intval', explode('.', $version));
    }

    /**
     * Compara dos códigos de versión por su valor numérico, componente a componente.
     *
     * Normaliza ambos a la misma cantidad de componentes (la mayor de las dos),
     * rellenando con ceros a la derecha antes de comparar — así `"3.3.3"` y
     * `"3.3.3.0"` dan iguales, y `"3.3.1"` es menor que `"3.3.1.1"`. No está
     * hardcodeado a una cantidad fija de componentes: soporta cualquier cantidad.
     *
     * `null` se trata como versión sin componentes (`[]`), que queda por debajo de
     * cualquier versión con al menos un componente.
     *
     * @param string|null $a Primer código de versión.
     * @param string|null $b Segundo código de versión.
     *
     * @return int `-1` si `$a` es menor que `$b`, `0` si son iguales, `1` si `$a` es
     *             mayor que `$b`.
     */
    public static function compare(?string $a, ?string $b): int
    {
        $componentesA = self::parse($a);
        $componentesB = self::parse($b);

        $cantidad = max(count($componentesA), count($componentesB));

        for ($i = 0; $i < $cantidad; $i++) {
            $valorA = isset($componentesA[$i]) ? $componentesA[$i] : 0;
            $valorB = isset($componentesB[$i]) ? $componentesB[$i] : 0;

            if ($valorA !== $valorB) {
                return $valorA <=> $valorB;
            }
        }

        return 0;
    }

    /**
     * ¿El código de versión cumple el formato válido (al menos 3 componentes numéricos
     * separados por puntos)?
     *
     * @param string|null $version Código de versión a validar.
     *
     * @return bool
     */
    public static function isValid(?string $version): bool
    {
        return preg_match(self::VALID_REGEX, (string) $version) === 1;
    }

    /**
     * ¿El código de versión corresponde a un hotfix?
     *
     * Regla literal de Lucas: más de 3 componentes ⇒ hotfix. Es una heurística sobre
     * la cantidad de componentes, no sobre su valor — `"3.3.1.0"` también da `true`
     * aunque el cuarto componente sea cero. Para eso existe el override manual del
     * checkbox en el form de edición (`VersionController::resolve_is_hotfix()`): si el
     * cálculo automático se equivoca en un caso puntual, se corrige a mano.
     *
     * @param string|null $version Código de versión a evaluar.
     *
     * @return bool
     */
    public static function isHotfix(?string $version): bool
    {
        return count(self::parse($version)) > 3;
    }
}

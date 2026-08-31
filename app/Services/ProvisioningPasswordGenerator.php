<?php

namespace App\Services;

/**
 * Genera las contraseñas que el aprovisionamiento le pone a la base de datos del cliente y —en el
 * VPS— a los usuarios de los sitios de CloudPanel.
 *
 * 🔴 EL ALFABETO ESTÁ ACOTADO A PROPÓSITO Y NO ES PARANOIA DE MÁS.
 *
 * Estas contraseñas viajan por línea de comando SSH (clpctl las recibe como argumento) y por el
 * `sed -i` de EnvSshService cuando se escriben en el .env del cliente. Los caracteres que faltan
 * —`$ " ' \ ` ; | & < >` y el espacio— son exactamente los que tienen significado en alguna de esas
 * dos capas. Es más barato sacarlos del alfabeto que confiar en que tres capas de escapado seguidas
 * estén todas bien: si una falla, no falla con un error, falla con una contraseña DISTINTA de la que
 * quedó guardada, y la base del cliente queda inaccesible sin que nada se ponga en rojo. Y la
 * contraseña de una base de Hostinger no se puede volver a leer ni resetear.
 *
 * Con 24 caracteres de este alfabeto la entropía sigue arriba de 140 bits.
 *
 * Está en su propio archivo porque la regla R2 de §9 (450 líneas por archivo nuevo de
 * app/Services/) no dejaba que esto creciera adentro de HostingProvisioningService, y porque una
 * regla con este motivo merece un archivo con su nombre.
 *
 * PHP 7.4.
 */
class ProvisioningPasswordGenerator
{
    /**
     * Largo de toda contraseña generada.
     *
     * @var int
     */
    const LARGO = 24;

    /**
     * @var string
     */
    const MAYUSCULAS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * @var string
     */
    const MINUSCULAS = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * @var string
     */
    const DIGITOS = '0123456789';

    /**
     * Los únicos tres caracteres no alfanuméricos que sobreviven al shell y al sed.
     *
     * @var string
     */
    const ESPECIALES = '._-';

    /**
     * Contraseña nueva: LARGO caracteres, con al menos una mayúscula, una minúscula, un dígito y
     * exactamente uno de `._-`.
     *
     * random_int() y no rand()/str_shuffle(): son contraseñas de bases de datos de producción, y el
     * generador de PHP sin sembrar es predecible.
     *
     * @return string
     */
    public function generar(): string
    {
        $alfanumerico = self::MAYUSCULAS . self::MINUSCULAS . self::DIGITOS;

        /* Los cuatro obligatorios primero, el resto alfanumérico, y después se mezcla todo. */
        $caracteres = [
            $this->al_azar(self::MAYUSCULAS),
            $this->al_azar(self::MINUSCULAS),
            $this->al_azar(self::DIGITOS),
            $this->al_azar(self::ESPECIALES),
        ];

        while (count($caracteres) < self::LARGO) {
            $caracteres[] = $this->al_azar($alfanumerico);
        }

        /* Fisher-Yates con random_int: str_shuffle usa el generador débil. */
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);

            $temporal       = $caracteres[$i];
            $caracteres[$i] = $caracteres[$j];
            $caracteres[$j] = $temporal;
        }

        return implode('', $caracteres);
    }

    /**
     * @param  string  $alfabeto
     * @return string
     */
    private function al_azar(string $alfabeto): string
    {
        return $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
}

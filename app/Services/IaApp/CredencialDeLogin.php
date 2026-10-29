<?php

namespace App\Services\IaApp;

/**
 * La contraseña del login de la app `ia.comerciocity.com`, envuelta para que no se pueda escapar por accidente
 * (misión cruzada `app-ia-y-memoria`, 29/10/2026, corrección del revisor).
 *
 * 🔴 POR QUÉ. Pasada como un string suelto, la contraseña es un argumento posicional de cada función por la que
 * pasa, y PHP 7.4 copia los argumentos en la traza de cualquier excepción: `getTraceAsString()` muestra los
 * strings de hasta 15 caracteres TAL CUAL, y esa traza termina en `laravel.log`. Envuelta, la traza dice
 * `Object(App\Services\IaApp\CredencialDeLogin)` y nada más.
 *
 * Lo que tapa: `__toString()`, `var_dump`/`print_r` (`__debugInfo()`), `serialize()` (no se serializa nunca:
 * una credencial no viaja a una cola ni a una caché) y `json_encode()`. El valor en claro sale SOLO por
 * `valor()`, en el único lugar donde hace falta: el body del POST al sistema del cliente.
 *
 * PHP 7.4: sin promoción en constructor ni `readonly`.
 */
final class CredencialDeLogin implements \JsonSerializable
{
    /** Lo que se muestra en lugar de la contraseña. */
    const TAPADA = '[oculta]';

    /** @var string */
    private $password;

    /**
     * @param string $password
     */
    public function __construct($password)
    {
        $this->password = (string) $password;
    }

    /**
     * La contraseña en claro. Solo para armar el pedido al sistema del cliente.
     *
     * @return string
     */
    public function valor()
    {
        return $this->password;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return self::TAPADA;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo()
    {
        return ['password' => self::TAPADA];
    }

    /**
     * @return string
     */
    public function jsonSerialize()
    {
        return self::TAPADA;
    }

    /**
     * Una credencial no se serializa: no viaja a una cola, una caché ni una sesión.
     *
     * @return array
     */
    public function __serialize(): array
    {
        throw new \LogicException('Una credencial de login no se serializa.');
    }

    /**
     * @param array $datos
     *
     * @return void
     */
    public function __unserialize(array $datos): void
    {
        throw new \LogicException('Una credencial de login no se serializa.');
    }
}

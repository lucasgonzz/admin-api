<?php

namespace App\Services\ImplementationFollowups;

/**
 * Un freno del seguimiento de implementaciones con su código del contrato (`reservado`, `no_vencido`,
 * `cliente_escribio`, `martin_ocupado`, `sin_seguimiento`...). El controlador lo devuelve como
 * `{error: codigo, motivo: codigo, message: "codigo: texto", ...extra}` con el status HTTP.
 *
 * `error` y `motivo` llevan los dos el código a propósito: `error` es la clave que lee el monitor para los
 * códigos del contrato y `motivo` la que usan los demás endpoints de `claude/implementations/*`. `message` repite
 * la forma `codigo: texto` de los frenos de `SupportCodexService` (HttpException), así cualquier lector que ya
 * mire esa forma entiende este también.
 */
class SeguimientoException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $codigo;

    /** @var array<string, mixed> */
    private $extra;

    /**
     * @param int                  $status HTTP.
     * @param string               $codigo El código del contrato.
     * @param string               $texto  La explicación, en castellano.
     * @param array<string, mixed> $extra  Claves de más para la respuesta.
     */
    public function __construct($status, $codigo, $texto, array $extra = [])
    {
        parent::__construct($codigo . ': ' . $texto);

        $this->status = (int) $status;
        $this->codigo = (string) $codigo;
        $this->extra  = $extra;
    }

    /**
     * @return int
     */
    public function status()
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function codigo()
    {
        return $this->codigo;
    }

    /**
     * El cuerpo JSON de la respuesta.
     *
     * @return array<string, mixed>
     */
    public function cuerpo()
    {
        return array_merge($this->extra, [
            'error'   => $this->codigo,
            'motivo'  => $this->codigo,
            'message' => $this->getMessage(),
        ]);
    }
}

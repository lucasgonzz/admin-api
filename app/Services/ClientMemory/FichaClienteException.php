<?php

namespace App\Services\ClientMemory;

/**
 * Un freno de la ficha del cliente con su código del contrato (`entrada_ajena`, `ficha_llena`, `dato_sensible`,
 * `invalid_lease`, `sin_cliente`...). Sale como `{error: codigo, motivo: codigo, message: "codigo: texto", ...extra}`
 * con su status: la misma forma de `SeguimientoException`, y el `message` con el prefijo `codigo:` de los frenos de
 * `SupportCodexService` (HttpException), así cualquier lector del monitor que ya mire una de las dos formas entiende
 * esta.
 */
class FichaClienteException extends \RuntimeException
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

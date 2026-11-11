<?php

namespace App\Services\ImportacionesDeMartin;

/**
 * Un freno de las rutas de `importaciones-de-martin` con su código del contrato (`tarea_invalida`, `tarea_vencida`,
 * `tarea_de_otro_ticket`, `alcance_no_permitido`, `sin_permiso`, `contacto_no_habilitado`...). Sale como
 * `{error: codigo, motivo: codigo, message: "codigo: texto"}` con su status: la misma forma de `FichaClienteException`
 * y de los frenos de `SupportCodexService` (HttpException), así el monitor lee una sola forma.
 */
class ImportacionesDeMartinException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $codigo;

    /**
     * @param int    $status HTTP.
     * @param string $codigo El código del contrato.
     * @param string $texto  La explicación, en castellano.
     */
    public function __construct($status, $codigo, $texto)
    {
        parent::__construct($codigo . ': ' . $texto);

        $this->status = (int) $status;
        $this->codigo = (string) $codigo;
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
     * @return array<string, string>
     */
    public function cuerpo()
    {
        return ['error' => $this->codigo, 'motivo' => $this->codigo, 'message' => $this->getMessage()];
    }
}

<?php

namespace App\Services\Prueba;

/**
 * El nombre de la empresa no deja armar un slug (chequeo B1 de la misión prueba-alta-instancia, 3/11/2026):
 * sin letras, o solo prefijos de host («Tienda API»). El alta la traduce a un 422 sobre `empresa`, adentro
 * de la transacción, así no queda ningún lead a medias.
 */
class SlugDePruebaImposibleException extends \RuntimeException
{
    public function __construct(string $empresa)
    {
        parent::__construct(
            'Con «' . trim($empresa) . '» no podemos armar la dirección de tu sistema: escribí el nombre de tu '
            . 'negocio con letras (no solo «tienda» o «api»).'
        );
    }
}

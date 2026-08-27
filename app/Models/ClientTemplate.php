<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla Meta aprobada para escribirle a un CLIENTE desde la bandeja de soporte.
 *
 * A diferencia de FollowupTemplate —que DERIVA la categoría del estado del pipeline de leads,
 * porque ahí ese estado existe y es la única agrupación que tiene sentido—, acá la categoría es
 * una columna: la define quien crea la plantilla, con su etiqueta y su orden.
 *
 * Por eso los accessors de abajo no derivan nada: solo tapan agujeros. Una fila cargada sin
 * etiqueta, sin orden o sin título no puede romper el agrupado del selector ni dejar un
 * encabezado en blanco, y el que carga la plantilla no tiene por qué acordarse de los tres
 * campos decorativos para que la pantalla se vea bien.
 */
class ClientTemplate extends Model
{
    /**
     * Alta y edición vienen del endpoint de Claude y del ABM, los dos ya validados.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Casts de los campos que el SPA necesita tipados.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'activa'          => 'boolean',
        'categoria_orden' => 'integer',
        'variables'       => 'array',
    ];

    /**
     * Scope estándar para contrato homogéneo con fullModel, igual que FollowupTemplate.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
    }

    /**
     * Accessor `categoria`: el slug con el que el selector agrupa.
     *
     * Cae en 'otras' cuando la columna vino vacía, porque un slug vacío armaría un grupo sin
     * nombre en la pantalla, que es peor que un grupo llamado "Otras plantillas".
     *
     * @param mixed $value Valor crudo de la columna.
     *
     * @return string
     */
    public function getCategoriaAttribute($value)
    {
        $slug = trim((string) $value);

        return $slug !== '' ? $slug : 'otras';
    }

    /**
     * Accessor `categoria_label`: la etiqueta legible del grupo.
     *
     * Si nadie la cargó se arma una a partir del slug (guiones y guiones bajos pasan a espacios),
     * así una fila incompleta igual muestra un encabezado que se entiende.
     *
     * @param mixed $value Valor crudo de la columna.
     *
     * @return string
     */
    public function getCategoriaLabelAttribute($value)
    {
        $label = trim((string) $value);
        if ($label !== '') {
            return $label;
        }

        /* Slug ya normalizado por el accessor de arriba. */
        $slug = $this->categoria;

        if ($slug === 'otras') {
            return 'Otras plantillas';
        }

        return ucfirst(str_replace(array('_', '-'), ' ', $slug));
    }

    /**
     * Accessor `categoria_orden`: la posición del grupo en el selector.
     *
     * Un 0 o un negativo (que la columna unsigned no debería dejar entrar, pero un cast en el
     * camino sí) se leen como "sin ordenar" y van al final, no adelante de todo.
     *
     * @param mixed $value Valor crudo de la columna.
     *
     * @return int
     */
    public function getCategoriaOrdenAttribute($value)
    {
        $orden = (int) $value;

        return $orden > 0 ? $orden : 99;
    }

    /**
     * Accessor `titulo`: el nombre corto que ve el operador en la lista.
     *
     * Sin título cae al nombre técnico de Meta: no dice mucho, pero al menos identifica la fila.
     * Una entrada en blanco en el selector sería imposible de elegir.
     *
     * @param mixed $value Valor crudo de la columna.
     *
     * @return string
     */
    public function getTituloAttribute($value)
    {
        $titulo = trim((string) $value);

        return $titulo !== '' ? $titulo : (string) $this->template_name;
    }
}

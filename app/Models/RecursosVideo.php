<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un video del catálogo del centro de recursos (misión centro-de-recursos-clientes, 13/10/2026).
 *
 * Lo carga Claude por `POST claude/recursos/catalogo` (upsert por `codigo`). Nunca se borra: un video
 * que deja de venir queda `activo = false` (ver la migración).
 *
 * @property int         $id
 * @property string      $codigo
 * @property int         $carpeta
 * @property string      $carpeta_nombre
 * @property string|null $seccion
 * @property int         $orden
 * @property string      $titulo
 * @property string|null $para_que
 * @property string|null $cuando_no
 * @property array|null  $relacionados
 * @property string|null $transcripcion
 * @property float       $duracion
 * @property string      $url
 * @property string|null $miniatura_url
 * @property string|null $version
 * @property bool        $activo
 */
class RecursosVideo extends Model
{
    protected $table = 'recursos_videos';

    protected $guarded = [];

    protected $casts = [
        'carpeta'      => 'integer',
        'orden'        => 'integer',
        'relacionados' => 'array',
        'duracion'     => 'float',
        'activo'       => 'boolean',
    ];

    /**
     * Cuántos segundos enteros tiene el video: el largo de `recursos_progresos.vistos`.
     *
     * @return int
     */
    public function segundos(): int
    {
        return (int) max(0, ceil((float) $this->duracion));
    }

    /**
     * El video como lo publica el catálogo (`GET recursos/catalogo`): sin transcripción.
     *
     * @param array<int, string>|null $codigos_activos Si viene, los relacionados se filtran a estos
     *                                                 códigos (un relacionado desactivado no se ofrece).
     *
     * @return array<string, mixed>
     */
    public function para_el_catalogo(?array $codigos_activos = null): array
    {
        $relacionados = array_values(array_filter((array) $this->relacionados, 'is_string'));

        if ($codigos_activos !== null) {
            $relacionados = array_values(array_filter($relacionados, function ($codigo) use ($codigos_activos) {
                return isset($codigos_activos[$codigo]);
            }));
        }

        return [
            'codigo'        => $this->codigo,
            'titulo'        => $this->titulo,
            'carpeta'       => (int) $this->carpeta,
            'seccion'       => $this->seccion,
            'orden'         => (int) $this->orden,
            'para_que'      => $this->para_que,
            'cuando_no'     => $this->cuando_no,
            'relacionados'  => $relacionados,
            'duracion'      => (float) $this->duracion,
            'url'           => $this->url,
            'miniatura_url' => $this->miniatura_url,
        ];
    }

    public function progresos()
    {
        return $this->hasMany(RecursosProgreso::class, 'recursos_video_id');
    }

    public function votos()
    {
        return $this->hasMany(RecursosVoto::class, 'recursos_video_id');
    }

    public function preguntas()
    {
        return $this->hasMany(RecursosPregunta::class, 'recursos_video_id');
    }

    public function encuestas()
    {
        return $this->hasMany(RecursosEncuesta::class, 'recursos_video_id');
    }
}

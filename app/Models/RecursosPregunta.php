<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una pregunta de un usuario sobre un video, con la respuesta de ComercioCity (misión
 * centro-de-recursos-clientes, 13/10/2026). Quién ve cuál: ver `scopeVisiblesPara()`.
 *
 * @property int         $id
 * @property int         $recursos_video_id
 * @property int         $recursos_usuario_id
 * @property string      $texto
 * @property string|null $respuesta
 * @property int|null    $respondido_por_admin_id
 * @property \Carbon\Carbon|null $respondido_at
 * @property string|null $borrador
 * @property bool        $oculta
 * @property \Carbon\Carbon|null $vista_por_autor_at
 */
class RecursosPregunta extends Model
{
    protected $table = 'recursos_preguntas';

    protected $guarded = [];

    protected $casts = [
        'recursos_video_id'       => 'integer',
        'recursos_usuario_id'     => 'integer',
        'respondido_por_admin_id' => 'integer',
        'respondido_at'           => 'datetime',
        'oculta'                  => 'boolean',
        'vista_por_autor_at'      => 'datetime',
    ];

    /**
     * Las preguntas que un usuario del centro puede ver (decisión de Lucas, 13/10/2026):
     *   - las respondidas y no ocultas, de cualquiera;
     *   - las suyas que todavía no tienen respuesta, si no están ocultas.
     * Sin sesión (`$recursos_usuario_id` null), solo las primeras.
     *
     * 🔴 `oculta` gana siempre, también para el autor: una pregunta que el admin ocultó (un insulto,
     * un dato privado) no se le sigue mostrando a nadie.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null                              $recursos_usuario_id
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeVisiblesPara($query, ?int $recursos_usuario_id)
    {
        $query->where('oculta', false);

        return $query->where(function ($q) use ($recursos_usuario_id) {
            $q->whereNotNull('respuesta');

            if ($recursos_usuario_id !== null) {
                $q->orWhere('recursos_usuario_id', $recursos_usuario_id);
            }
        });
    }

    public function video()
    {
        return $this->belongsTo(RecursosVideo::class, 'recursos_video_id');
    }

    public function usuario()
    {
        return $this->belongsTo(RecursosUsuario::class, 'recursos_usuario_id');
    }

    public function me_gustas()
    {
        return $this->hasMany(RecursosPreguntaMeGusta::class, 'recursos_pregunta_id');
    }
}

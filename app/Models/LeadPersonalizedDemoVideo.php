<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Video tutorial personalizado asociado a un prospecto (`Lead`).
 *
 * Se muestra en el correo "Mail 1 - DEMO" en una sección dedicada, con título,
 * descripción y enlace externo al recurso de video (p. ej. Loom, YouTube).
 *
 * El campo `comments` es solo para el equipo (brief interno); no se envía en el mail.
 */
class LeadPersonalizedDemoVideo extends Model
{
    use HasUuid;

    /**
     * Asignación masiva permitida para alta/edición desde admin-spa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'lead_id',
        'title',
        'description',
        'comments',
        'video_url',
        'sort_order',
    ];

    /**
     * Lead propietario de este video personalizado.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }
}

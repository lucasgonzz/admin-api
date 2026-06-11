<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Proceso de implementación de la tienda online de un cliente (una por client_id).
 *
 * @property int         $client_id               Cliente dueño.
 * @property int|null    $client_ecommerce_id     Tienda online asociada.
 * @property string      $status                  pending | in_progress | completed.
 * @property int         $current_stage           Etapa actual (1–5).
 * @property int|null    $assigned_admin_id       Admin asignado.
 * @property string|null $migration_contact_phone Teléfono de contacto de migración del dominio.
 */
class EcommerceImplementation extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'client_id',
        'client_ecommerce_id',
        'status',
        'current_stage',
        'assigned_admin_id',
        'started_at',
        'completed_at',
        'migration_contact_phone',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'current_stage' => 'integer',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'status'        => 'string',
    ];

    /**
     * Eager load de relaciones habituales del panel.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithAll($query)
    {
        $query->with(['client', 'client_ecommerce', 'stages', 'messages']);
    }

    /**
     * Cliente dueño de esta implementación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Tienda online asociada a esta implementación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client_ecommerce()
    {
        return $this->belongsTo(ClientEcommerce::class);
    }

    /**
     * Etapas instanciadas de esta implementación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stages()
    {
        return $this->hasMany(EcommerceImplementationStage::class)->orderBy('stage_number', 'asc');
    }

    /**
     * Mensajes WhatsApp del flujo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(EcommerceImplementationMessage::class)->orderBy('sent_at', 'asc');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Motivo por el que un cliente requiere actualizar su sistema o su tienda (misión
 * martin-descripcion-y-actualizaciones, 25/11/2026): una corrección de soporte ya integrada o una
 * funcionalidad ya mergeada.
 *
 * Abierta mientras `resolved_at` es null. La crea y la resuelve `ClientUpgradeMarkService` (que además
 * mantiene `clients.requires_upgrade`); este modelo sólo sabe leerse y armar su forma pública (contrato C6).
 */
class ClientUpgradeMark extends Model
{
    /** Estados públicos de una marca (contrato C6). */
    const ESTADO_LISTA = 'lista_para_actualizar';
    const ESTADO_FALTA_RELEASE = 'falta_release';
    const ESTADO_TIENDA_MANUAL = 'tienda_manual';
    const ESTADO_RESUELTA = 'resuelta';

    /** Repos que se instalan en el cliente, por destino. Lo de admin o del repo de conocimiento no marca. */
    const REPOS_POR_TARGET = [
        'sistema' => ['empresa-api', 'empresa-spa'],
        'tienda' => ['tienda-api', 'tienda-spa'],
    ];

    protected $guarded = [];

    protected $casts = [
        'client_id' => 'integer',
        'support_ticket_id' => 'integer',
        'support_codex_mission_id' => 'integer',
        'available_version_id' => 'integer',
        'last_checked_version_id' => 'integer',
        'resolved_version_id' => 'integer',
        'commits' => 'array',
        'merged_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Scope estándar del proyecto para cargar relaciones en `fullModel()`.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function scopeWithAll($query)
    {
        $query->with('available_version', 'resolved_version');
    }

    /**
     * Cliente al que pertenece la marca.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Primera versión publicada que contiene la corrección (null si todavía no salió).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function available_version()
    {
        return $this->belongsTo(Version::class, 'available_version_id');
    }

    /**
     * Versión del cliente cuando la marca se resolvió por instalación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function resolved_version()
    {
        return $this->belongsTo(Version::class, 'resolved_version_id');
    }

    /**
     * Indica si la marca sigue abierta.
     *
     * @return bool
     */
    public function is_open(): bool
    {
        return $this->resolved_at === null;
    }

    /**
     * Estado público: resuelta, tienda_manual (las de tienda no se resuelven solas en esta misión),
     * lista_para_actualizar (ya hay una versión publicada que la contiene) o falta_release.
     *
     * @return string
     */
    public function estado(): string
    {
        if (! $this->is_open()) {
            return self::ESTADO_RESUELTA;
        }
        if ($this->target === 'tienda') {
            return self::ESTADO_TIENDA_MANUAL;
        }

        return $this->available_version_id !== null ? self::ESTADO_LISTA : self::ESTADO_FALTA_RELEASE;
    }

    /**
     * Forma pública de la marca (contrato C6), con fechas ISO 8601.
     *
     * @return array<string, mixed>
     */
    public function to_api_array(): array
    {
        return [
            'id' => (int) $this->id,
            'kind' => $this->kind,
            'target' => $this->target,
            'source' => $this->source,
            'title' => $this->title,
            'detail' => $this->detail,
            'support_ticket_id' => $this->support_ticket_id,
            'support_codex_mission_id' => $this->support_codex_mission_id,
            'external_key' => $this->external_key,
            'commits' => $this->commits ?: (object) [],
            'merged_at' => $this->merged_at === null ? null : $this->merged_at->toIso8601String(),
            'available_version' => $this->available_version === null ? null : $this->available_version->version,
            'available_by' => $this->available_version_id === null ? null : $this->available_by,
            'estado' => $this->estado(),
            'resolved_at' => $this->resolved_at === null ? null : $this->resolved_at->toIso8601String(),
            'resolved_version' => $this->resolved_version === null ? null : $this->resolved_version->version,
            'resolved_reason' => $this->resolved_reason,
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
        ];
    }
}

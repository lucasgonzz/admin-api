<?php

namespace App\Models;

use App\Services\ImplementationSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Proceso de implementación guiada de un cliente (una instancia por client_id).
 *
 * @property int         $client_id      Cliente dueño.
 * @property int         $current_stage  Etapa actual (1–8).
 * @property string      $status         pending | in_progress | completed | paused.
 * @property string      $automation_mode manual | auto. Gatea los puntos de disparo automático (prompt 342).
 * @property string|null $migration_contact_phone Teléfono del responsable de migración.
 * @property string|null $form_token     UUID v4 para acceso público al formulario de configuración.
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $form_submitted_at Fecha/hora de envío definitivo del formulario.
 * @property \Illuminate\Support\Carbon|null $user_setup_executed_at Momento en que se aplicó el UserSetup con éxito (lock del botón, prompt 477).
 */
class Implementation extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Atributos virtuales incluidos automáticamente en la serialización JSON.
     *
     * form_link: link completo del formulario (url_base + form_token) para que el admin lo copie.
     *
     * @var array<int, string>
     */
    protected $appends = ['form_link'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'current_stage'     => 'integer',
        'started_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'form_submitted_at' => 'datetime',
        'status'            => 'string',
        // Lock del botón de UserSetup (prompt 477): NULL = todavía no se aplicó con éxito.
        'user_setup_executed_at' => 'datetime',
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
        $query->with(['client', 'stages', 'messages']);
    }

    /**
     * Indica si esta implementación ejecuta el flujo automático (mensajes y avances sin intervención).
     *
     * Default: false (modo manual — Martín orquesta cada paso desde el panel).
     *
     * @return bool
     */
    public function is_automated(): bool
    {
        return ((string) ($this->automation_mode ?? 'manual')) === 'auto';
    }

    /**
     * Atributo virtual: link completo del formulario público para copiar y enviar al cliente.
     *
     * Combina la URL base configurada en settings con el form_token de esta implementación.
     * Devuelve null si el token no está generado o la URL base no está configurada.
     *
     * @return string|null URL completa (ej: https://admin.cc.com/configuracion/{token}).
     */
    public function getFormLinkAttribute(): ?string
    {
        // Sin token no se puede construir el link.
        if (empty($this->form_token)) {
            return null;
        }

        // Leer la URL base configurada en settings; si está vacía devolver null.
        $base_url = rtrim(ImplementationSettings::get_form_url(), '/');

        if ($base_url === '') {
            return null;
        }

        // Link completo: {url_base}/{form_token}.
        return "{$base_url}/{$this->form_token}";
    }

    /**
     * Filtra por el token público del formulario de configuración.
     *
     * Permite buscar una implementación por su form_token sin exponer el id interno.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string                                $token Token UUID v4 del formulario.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByFormToken($query, string $token)
    {
        return $query->where('form_token', $token);
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
     * Etapas instanciadas de esta implementación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function stages()
    {
        return $this->hasMany(ImplementationStage::class)->orderBy('stage_number', 'asc');
    }

    /**
     * Mensajes WhatsApp del flujo.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(ImplementationMessage::class)->orderBy('sent_at', 'asc');
    }

    /**
     * Registro de la etapa actual según current_stage.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function current_stage_record()
    {
        return $this->hasOne(ImplementationStage::class)
            ->where('stage_number', $this->current_stage);
    }

    /**
     * Configuración maestra de la etapa actual.
     *
     * @return ImplementationStageConfig|null
     */
    public function current_stage_config()
    {
        return ImplementationStageConfig::where('stage_number', $this->current_stage)->first();
    }
}

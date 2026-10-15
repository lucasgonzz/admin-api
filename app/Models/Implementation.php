<?php

namespace App\Models;

use App\Services\ImplementationSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Proceso de implementación guiada de un cliente (una instancia por client_id).
 *
 * @property int         $client_id      Cliente dueño.
 * @property int         $current_stage  Etapa actual (1 a ULTIMA_ETAPA; 8 solo en las implementaciones anteriores al 15/10/2026).
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
     * Última etapa del flujo de implementación: la 7, vinculación con ARCA/AFIP. Es la que CIERRA la
     * implementación: cerrarla deja `status = completed` y `current_stage` en 7.
     *
     * 🔴 Hasta el 15/10/2026 el flujo tenía OCHO etapas (la 6 era «Capacitación» y la 8 «Videollamada de
     * capacitación»). Decisión de Lucas del 15/10/2026: pasa a SIETE. La 6 se convierte, en la misma
     * posición, en «Catálogo: categorías, imágenes y descripciones» (la capacitación es el centro de
     * recursos que se entrega con el sistema, en la 5) y la 8 sale del flujo: el soporte es por WhatsApp y
     * la videollamada solo existe si el cliente la pide. Este es el ÚNICO lugar donde vive ese número:
     * todo lo que antes decía `8` (el tope del botón «Avanzar etapa», qué etapa cierra, cuántas filas crea
     * el alta, la línea de progreso del mail) lo lee de acá.
     *
     * @var int
     */
    const ULTIMA_ETAPA = 7;

    /**
     * La etapa 8 que ya no existe en el flujo pero que traen las implementaciones creadas antes del
     * 15/10/2026 (una fila `implementation_stages` con `stage_number = 8`).
     *
     * COMPATIBILIDAD HACIA ATRÁS, que es lo que hace que sea una constante y no un número perdido:
     *  - Una implementación que ya está EN la 8 se sigue cerrando (cerrar es `etapa >= ULTIMA_ETAPA`).
     *  - Al cerrar desde la 7, la fila 8 que haya quedado `pending` se marca `skipped`
     *    (`saltar_las_etapas_heredadas()`).
     *  - La validación de `etapa_actual` y de `etapa` de `claude/implementations/*` sigue aceptando 1 a 8.
     *  - El bloque `etapas` de `GET claude/implementations/{id}` muestra la 8 solo si esa fila llegó a
     *    arrancar (no `pending` ni `skipped`).
     *
     * @var int
     */
    const ETAPA_HEREDADA = 8;

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
        // client.client_ecommerce va precargada porque los accesores ecommerce_* del $appends de
        // Client resuelven contra ella: sin esto salia una consulta por implementacion serializada.
        $query->with(['client', 'client.client_ecommerce', 'stages', 'messages']);
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
     * Marca como `skipped` las filas de etapas POSTERIORES a la última (la 8 heredada) que hayan quedado
     * `pending`: una implementación creada antes del 15/10/2026 trae esa fila, y al cerrarse desde la 7 no
     * tiene que quedar una etapa «por hacer» en una implementación completada.
     *
     * Solo toca las `pending`: una fila 8 que ya está en curso o completada (una implementación vieja que
     * llegó a la 8) no se pisa. Es idempotente.
     *
     * @return int Cantidad de filas marcadas.
     */
    public function saltar_las_etapas_heredadas(): int
    {
        return ImplementationStage::where('implementation_id', $this->id)
            ->where('stage_number', '>', self::ULTIMA_ETAPA)
            ->where('status', 'pending')
            ->update(['status' => 'skipped', 'completed_at' => now()]);
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

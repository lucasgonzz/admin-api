<?php

namespace App\Models;

use App\ModelProperties\LeadProperties;
use App\Models\Concerns\HasUuid;
use App\Models\LeadPipelineStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Prospecto comercial cargado desde el panel de admin-api.
 *
 * Un Lead concentra:
 * - datos de contacto,
 * - configuración técnica del sistema (espejo del formulario de demo),
 * - referencia al empresa-api destino donde se corre la demo remotamente,
 * - api_url del sistema productivo del cliente (para user-setup una vez promovido),
 * - trazabilidad del mail de presentación y del setup remoto,
 * - fecha de la demo y horas de inicio/fin (texto) acordadas con el prospecto,
 * - tutoriales de video personalizados para el mail de demo (`personalized_demo_videos`).
 *
 * Ver migraciones de la tabla `leads` (create y alter) para el esquema de columnas.
 */
class Lead extends Model
{
    use HasUuid;

    /**
     * Estados por defecto del pipeline (legacy; catálogo dinámico en {@see LeadPipelineStatus}).
     */
    public const PIPELINE_STATUSES = [
        'nuevo',
        'contactado',
        'calificado',
        'demo_agendada',
        'demo_realizada',
        'mail2_enviado',
        'cerrado_ganado',
        'cerrado_perdido',
        'en_pausa',
    ];

    /**
     * Slugs válidos del pipeline (catálogo en BD o defaults).
     *
     * @return array<int, string>
     */
    public static function pipeline_status_slugs(): array
    {
        return LeadPipelineStatus::all_slugs();
    }

    /**
     * Elimina videos personalizados y mensajes de conversación al borrar el lead.
     *
     * @return void
     */
    protected static function booted()
    {
        static::deleting(function (Lead $lead) {
            $lead->personalized_demo_videos()->delete();
            $lead->messages()->delete();
        });
    }

    /**
     * Devuelve definición declarativa de propiedades para admin-spa.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function properties()
    {
        return LeadProperties::all();
    }

    protected $guarded = [];

    /**
     * Casts para mantener tipados los booleanos del formulario y las fechas
     * de agendamiento / trazabilidad (demo y producción).
     */
    protected $casts = [
        'demo_date'                    => 'date',
        'meeting_scheduled_at'         => 'datetime',
        'presentation_mail_sent_at'    => 'datetime',
        'demo_setup_last_run_at'       => 'datetime',
        'user_setup_last_run_at'       => 'datetime',
        'followup_mail_sent_at'        => 'datetime',
        'demo_mail_sent_at'            => 'datetime',

        'use_deposits'                 => 'boolean',
        'use_price_lists'              => 'boolean',
        'iva_included'                 => 'boolean',
        'ventas_con_fecha_de_entrega'  => 'boolean',
        'cajas'                        => 'boolean',
        'usar_codigos_de_barra'        => 'boolean',
        'codigos_de_barra_por_defecto' => 'boolean',
        'consultora_de_precios'        => 'boolean',
        'imagenes'                     => 'boolean',
        'produccion'                   => 'boolean',
        'ask_amount_in_vender'         => 'boolean',
        'redondear_centenas_en_vender' => 'boolean',
        'omitir_cuentas_corrientes'    => 'boolean',

        'tiene_sugerencia_pendiente'   => 'boolean',
        'requiere_seguimiento'         => 'boolean',
        'tiene_seguimiento_sin_ver'    => 'boolean',

        // Flag de recordatorio pre-demo: evita generar el mensaje más de una vez por demo agendada.
        'recordatorio_demo_enviado'    => 'boolean',
    ];

    /**
     * Scope `withAll` requerido por la convención de admin-api (regla 20).
     * Precarga las relaciones habituales para evitar N+1 al listar leads.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
        $query->with(
            'target_client',
            'promoted_client',
            'created_by_admin',
            'demo',
            'personalized_demo_videos',
            'messages.attachments'
        );
        $query->withUnreadLeadMessagesCount();
    }

    /**
     * Agrega unread_messages_count: mensajes del lead (sender lead) sin read_at.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithUnreadLeadMessagesCount($query)
    {
        return $query->withCount([
            'messages as unread_messages_count' => function ($sub) {
                $sub->where('sender', 'lead')->whereNull('read_at');
            },
        ]);
    }

    /**
     * Empresa-api (Client registrado en admin-api) donde se corre la demo.
     */
    public function target_client()
    {
        return $this->belongsTo(Client::class, 'target_client_id');
    }

    /**
     * Client de producción creado automáticamente al promover el Lead.
     * Es el sistema real donde se corre el user-setup.
     */
    public function promoted_client()
    {
        return $this->belongsTo(Client::class, 'promoted_client_id');
    }

    /**
     * Admin que dio de alta el Lead.
     */
    public function created_by_admin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * Demo elegida para este lead desde el catálogo administrado.
     */
    public function demo()
    {
        return $this->belongsTo(Demo::class, 'demo_id');
    }

    /**
     * Tutoriales personalizados incluidos en el mail de demo (ordenados).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function personalized_demo_videos()
    {
        return $this->hasMany(LeadPersonalizedDemoVideo::class, 'lead_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Mensajes de la conversación WhatsApp (lead, setter, sugerencias de Claude).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(LeadMessage::class, 'lead_id')->with('attachments')->orderBy('id');
    }

    /**
     * Recalcula flags de sugerencias pendientes y seguimiento según mensajes `sugerido`.
     *
     * @return void
     */
    public function sync_suggestion_flags(): void
    {
        $pending = $this->messages()->where('status', 'sugerido')->exists();
        $this->tiene_sugerencia_pendiente = $pending;
        if (! $pending) {
            $this->requiere_seguimiento = false;
        }
        $this->save();
    }

    /**
     * Normaliza fecha de demo a Y-m-d; vacío limpia la columna.
     *
     * @param mixed $value input type="date", ISO o DateTimeInterface.
     */
    public function setDemoDateAttribute($value)
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->attributes['demo_date'] = null;

            return;
        }
        if ($value instanceof \DateTimeInterface) {
            $this->attributes['demo_date'] = $value->format('Y-m-d');

            return;
        }
        $this->attributes['demo_date'] = substr((string) $value, 0, 10);
    }

    /**
     * Hora de inicio de demo: blancos → null.
     *
     * @param mixed $value texto libre (p. ej. 09:00).
     */
    public function setDemoStartTimeAttribute($value)
    {
        $this->attributes['demo_start_time'] = self::nullable_trimmed_string($value);
    }

    /**
     * Hora de fin de demo: blancos → null.
     *
     * @param mixed $value texto libre (p. ej. 18:00).
     */
    public function setDemoEndTimeAttribute($value)
    {
        $this->attributes['demo_end_time'] = self::nullable_trimmed_string($value);
    }

    /**
     * @param mixed $raw
     *
     * @return string|null
     */
    protected static function nullable_trimmed_string($raw)
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim((string) $raw);

        return $trimmed === '' ? null : $trimmed;
    }
}

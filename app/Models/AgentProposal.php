<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propuesta generada por el agente analizador que Lucas puede aprobar o rechazar.
 * Al aprobarla se ejecuta automáticamente el payload de acción correspondiente.
 */
class AgentProposal extends Model
{
    /**
     * Todos los campos son asignables masivamente.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * Conversiones de tipo para atributos del modelo.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'datos_de_soporte' => 'array',
        'accion_payload'   => 'array',
        'aprobada_at'      => 'datetime',
        'rechazada_at'     => 'datetime',
    ];

    /**
     * Reporte del que surgió esta propuesta.
     *
     * @return BelongsTo
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(AgentDailyReport::class, 'report_id');
    }

    /**
     * Aplica la acción del payload cuando la propuesta es aprobada.
     * Si la propuesta no está en estado pendiente, no hace nada.
     *
     * @return void
     */
    public function apply(): void
    {
        /* Solo se puede aprobar una propuesta pendiente. */
        if ($this->estado !== 'pendiente') {
            return;
        }

        /* Payload y tipo de acción a ejecutar. */
        $payload = $this->accion_payload ?? [];
        $tipo    = $this->tipo;

        /* Ejecuta la acción según el tipo de propuesta. */
        match ($tipo) {
            /* Cambia el valor de una admin_setting existente o la crea. */
            'cambiar_setting' => AdminSetting::set(
                $payload['key'],
                (string) $payload['value']
            ),

            /* Desactiva una variante de mensaje por su slug. */
            'desactivar_variante' => MessageVariant::where('slug', $payload['slug'])
                ->update(['active' => false]),

            /* Crea una nueva variante de mensaje A/B. */
            'nueva_variante' => MessageVariant::create([
                'slug'          => $payload['slug'],
                'name'          => $payload['name'],
                'message_type'  => $payload['message_type'] ?? 'welcome_with_name',
                'body'          => $payload['body'],
                'delay_seconds' => $payload['delay_seconds'] ?? null,
                'active'        => true,
                'notes'         => $payload['notes'] ?? null,
            ]),

            /* Tipos no implementados: no hace nada. */
            default => null,
        };

        /* Marca la propuesta como aprobada. */
        $this->update([
            'estado'      => 'aprobada',
            'aprobada_at' => now(),
        ]);
    }
}

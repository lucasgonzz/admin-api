<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La encuesta de fin de prueba gratuita de un negocio (misión cruzada prueba-e2e-campana-imperio,
 * 11/11/2026). Una por cliente: se crea al vencer la prueba o al contratar, y el negocio la contesta una
 * sola vez desde el enlace público con su `token`.
 *
 * Las seis preguntas de sí/no quedan en null si no las contestó: null no es «no».
 */
class PruebaEncuesta extends Model
{
    /** Se le mandó porque la prueba venció sin contratar. */
    const MOTIVO_VENCIDA = 'vencida';

    /** Se le mandó porque contrató. */
    const MOTIVO_CONTRATO = 'contrato';

    /**
     * Las seis preguntas de sí/no, en el orden en que se muestran, con el texto que lee el negocio.
     *
     * @var array<string, string>
     */
    const PREGUNTAS = [
        'implementacion_practica' => '¿Te resultó fácil empezar a usar el sistema?',
        'soporte_sirvio'          => '¿Te sirvió el soporte que tuviste?',
        'probo_gestion'           => '¿Llegaste a entender y probar el sistema de gestión?',
        'probo_tienda'            => '¿Llegaste a entender y probar la tienda online?',
        'probo_agentes'           => '¿Llegaste a entender y probar los Agentes (Soporte y tu Asistente personal)?',
        'atencion_buena'          => '¿La atención fue buena?',
    ];

    protected $table = 'prueba_encuestas';

    protected $guarded = [];

    /** El token abre la encuesta: no viaja en ningún JSON. */
    protected $hidden = ['token'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'client_id'               => 'integer',
        'lead_id'                 => 'integer',
        'estrellas'               => 'integer',
        'atencion_buena'          => 'boolean',
        'implementacion_practica' => 'boolean',
        'soporte_sirvio'          => 'boolean',
        'probo_gestion'           => 'boolean',
        'probo_tienda'            => 'boolean',
        'probo_agentes'           => 'boolean',
        'contrato_al_responder'   => 'boolean',
        'enviada_at'              => 'datetime',
        'respondida_at'           => 'datetime',
        'avisada_at'              => 'datetime',
    ];

    /**
     * Scope estándar para contrato homogéneo con fullModel (regla admin-api).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    public function scopeWithAll($query)
    {
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Lo que se puede mostrar de la encuesta (pestaña Prueba, resumen para Claude): nunca el token.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        return [
            'id'                      => (int) $this->id,
            'motivo'                  => (string) $this->motivo,
            'enviada_at'              => $this->enviada_at !== null ? $this->enviada_at->toIso8601String() : null,
            'respondida_at'           => $this->respondida_at !== null ? $this->respondida_at->toIso8601String() : null,
            'estrellas'               => $this->estrellas,
            'atencion_buena'          => $this->atencion_buena,
            'implementacion_practica' => $this->implementacion_practica,
            'soporte_sirvio'          => $this->soporte_sirvio,
            'probo_gestion'           => $this->probo_gestion,
            'probo_tienda'            => $this->probo_tienda,
            'probo_agentes'           => $this->probo_agentes,
            'comentario'              => $this->comentario,
            'contrato_al_responder'   => $this->contrato_al_responder,
        ];
    }
}

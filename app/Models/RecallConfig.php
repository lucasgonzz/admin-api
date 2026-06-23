<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración de la integración con Recall.ai para transcripción de llamadas.
 *
 * Se espera un único registro activo por entorno (tabla singleton),
 * siguiendo el mismo patrón que WhatsappConfig.
 */
class RecallConfig extends Model
{
    /**
     * Nombre de la tabla en base de datos.
     *
     * @var string
     */
    protected $table = 'recall_configs';

    /**
     * Campos asignables desde seeders o panel admin.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'recall_api_key',
        'webhook_secret',
        'is_active',
    ];

    /**
     * Casteos de tipos para lectura y persistencia consistente.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Devuelve el registro de configuración activo, o null si no existe.
     *
     * @return self|null
     */
    public static function getActive(): ?self
    {
        return self::where('is_active', true)->first();
    }
}

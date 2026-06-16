<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuración de la integración WhatsApp vía Kapso (wrapper Meta Cloud API).
 * Se espera un único registro activo por entorno.
 */
class WhatsappConfig extends Model
{
    /**
     * Nombre de la tabla en base de datos.
     *
     * @var string
     */
    protected $table = 'whatsapp_config';

    /**
     * Campos asignables desde el panel admin o seeders.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'kapso_api_key',
        'phone_number_id',
        'webhook_secret',
        'is_active',
        'test_mode',
    ];

    /**
     * Casteos de tipos para lectura y persistencia consistente.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'test_mode' => 'boolean',
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

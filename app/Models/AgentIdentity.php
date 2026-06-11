<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Perfil del agente de ventas (Martín) usado por Claude como identidad en WhatsApp.
 *
 * Solo existe un registro activo a la vez. La descripción se inyecta como encabezado
 * del system prompt en cada llamada a la API de Anthropic.
 */
class AgentIdentity extends Model
{
    /**
     * Campos asignables en masa.
     *
     * @var array<string>
     */
    protected $fillable = ['name', 'description', 'activa'];

    /**
     * Conversiones de tipo para atributos.
     *
     * @var array<string, string>
     */
    protected $casts = ['activa' => 'boolean'];

    /**
     * Devuelve el registro activo, o null si no existe ninguno.
     *
     * @return self|null
     */
    public static function obtener_activo(): ?self
    {
        return self::where('activa', true)->first();
    }
}

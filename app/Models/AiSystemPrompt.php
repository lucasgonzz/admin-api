<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Esqueleto del system prompt de Claude; el protocolo completo se inyecta desde GitHub en runtime.
 */
class AiSystemPrompt extends Model
{
    /** @var array<int, string> */
    protected $fillable = ['contenido', 'descripcion', 'activa'];

    /** @var array<string, string> */
    protected $casts = [
        'activa' => 'boolean',
    ];

    /**
     * Devuelve el único prompt marcado como activo, o null si no existe.
     *
     * @return self|null
     */
    public static function obtener_activo(): ?self
    {
        return self::where('activa', true)->first();
    }

    /**
     * Al activar un registro, desactiva los demás para mantener un solo activo.
     *
     * @return void
     */
    protected static function booted()
    {
        static::saving(function (self $prompt) {
            if (! $prompt->activa) {
                return;
            }

            self::query()
                ->where('activa', true)
                ->when($prompt->exists, function ($query) use ($prompt) {
                    $query->where('id', '!=', $prompt->id);
                })
                ->update(['activa' => false]);
        });
    }
}

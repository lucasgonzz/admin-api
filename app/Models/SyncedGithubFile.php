<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Archivo markdown sincronizado desde lucasgonzz/claude-comerciocity sin modelo
 * de dominio propio (ej: comercial/leads_protocolo_whatsapp.md).
 *
 * El contenido se persiste vía AgentPromptSyncService y los servicios de runtime
 * lo leen desde acá, evitando pegarle a la GitHub API al generar una sugerencia.
 */
class SyncedGithubFile extends Model
{
    /**
     * Campos asignables en masa.
     *
     * @var array<string>
     */
    protected $fillable = ['key', 'repo_path', 'content', 'synced_at'];

    /**
     * Conversiones de tipo para atributos.
     *
     * @var array<string, string>
     */
    protected $casts = ['synced_at' => 'datetime'];

    /**
     * Devuelve el registro asociado a una clave interna, o null si no existe.
     *
     * @param string $key Clave interna (ej: 'leads_protocolo_whatsapp')
     * @return self|null
     */
    public static function obtener_por_key(string $key): ?self
    {
        return self::where('key', $key)->first();
    }
}

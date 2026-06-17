<?php

namespace App\Services;

use App\Models\SyncedGithubFile;

/**
 * Devuelve el protocolo de WhatsApp leyéndolo de base de datos.
 *
 * El contenido se sincroniza desde GitHub vía {@see AgentPromptSyncService} (botón
 * del admin + scheduled job cada 10 minutos) y se persiste en SyncedGithubFile.
 * Este servicio ya NO le pega a la GitHub API: lee de BD, fuera del camino crítico.
 *
 * Si el registro todavía no existe (instalación nueva o antes del primer sync),
 * devuelve string vacío para no interrumpir el flujo de sugerencias de Claude.
 */
class WhatsappProtocolService
{
    /** Clave interna del archivo de protocolo en SyncedGithubFile. */
    const PROTOCOL_KEY = 'leads_protocolo_whatsapp';

    /**
     * Devuelve el texto del protocolo leído desde base de datos.
     *
     * @return string Contenido del markdown o string vacío si aún no fue sincronizado.
     */
    public function getProtocol(): string
    {
        $synced = SyncedGithubFile::obtener_por_key(self::PROTOCOL_KEY);

        return $synced ? (string) $synced->content : '';
    }
}

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

    /** Clave del system base modular (tool use). */
    const SYSTEM_BASE_KEY = 'whatsapp_system_base';

    /** Prefijo de clave para los recursos del protocolo modular. */
    const RECURSO_KEY_PREFIX = 'whatsapp_recurso_';

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

    /**
     * Devuelve el system base modular (mucho más pequeño que el protocolo completo).
     * Se usa cuando LeadAiService opera en modo tool-use.
     *
     * @return string Contenido del markdown o string vacío si aún no fue sincronizado.
     */
    public function getSystemBase(): string
    {
        $synced = SyncedGithubFile::obtener_por_key(self::SYSTEM_BASE_KEY);

        return $synced ? (string) $synced->content : '';
    }

    /**
     * Devuelve el contenido de un recurso del protocolo por nombre.
     *
     * Nombres válidos: calificacion, posicionamiento, precios, demo_agenda,
     *                  demo_ciclo, post_demo, reglas, referidos.
     *
     * @param string $nombre Nombre del recurso a recuperar.
     * @return string Contenido del markdown o string vacío si no está sincronizado.
     */
    public function getRecurso(string $nombre): string
    {
        $key    = self::RECURSO_KEY_PREFIX . $nombre;
        $synced = SyncedGithubFile::obtener_por_key($key);

        return $synced ? (string) $synced->content : '';
    }
}

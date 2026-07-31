<?php

namespace App\Services;

use App\Models\SyncedGithubFile;
use Illuminate\Support\Facades\Log;

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

    /** Prefijo de clave para las variantes de la dinámica de demo nueva (grupo 293). */
    const RECURSO_KEY_PREFIX_V2 = 'whatsapp_recurso_v2_';

    /** Recursos que tienen variante para la dinámica de demo nueva. */
    const RECURSOS_CON_VARIANTE = ['calificacion', 'demo_agenda', 'demo_ciclo', 'post_demo'];

    /**
     * Devuelve el texto del protocolo leído desde base de datos.
     *
     * @deprecated El protocolo monolítico (comercial/leads_protocolo_whatsapp.md) fue deprecado
     * el 6/7/2026 (ver prompt 271): el agente de leads opera exclusivamente en modo modular
     * (getSystemBase() + getRecurso()), que cubre todo su contenido vigente. Este método ya no
     * tiene llamadores en LeadAiService y queda solo por compatibilidad hasta confirmar que no
     * lo usa nadie más; no agregar llamadores nuevos.
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
     * Devuelve el contenido de un recurso del protocolo por nombre, resolviendo la variante
     * de la dinámica de demo del lead (grupo 293).
     *
     * Nombres válidos: calificacion, posicionamiento, precios, demo_agenda,
     *                  demo_ciclo, post_demo, reglas, referidos.
     *
     * Si $variante === 'nueva' y el recurso tiene variante v2 (self::RECURSOS_CON_VARIANTE),
     * se busca primero el archivo v2 sincronizado. Si no existe o vino vacío, se cae a la
     * variante vigente (no a string vacío): si el archivo v2 todavía no se sincronizó, servir
     * vacío haría que el agente responda sin protocolo, y el protocolo del agente tiene una
     * regla explícita anti-placeholder (inventar datos frente a un lead real es el peor
     * desenlace posible). Servir el protocolo vigente es, como mucho, una demo explicada a la
     * manera vieja: raro, pero coherente y sin daño.
     *
     * @param string $nombre   Nombre del recurso a recuperar.
     * @param string $variante 'actual' (default, compatibilidad hacia atrás) o 'nueva'.
     * @return string Contenido del markdown o string vacío si no está sincronizado.
     */
    public function getRecurso(string $nombre, string $variante = 'actual'): string
    {
        /* Dinámica nueva + recurso con variante propia: intentar servir la v2 primero. */
        if ($variante === 'nueva' && in_array($nombre, self::RECURSOS_CON_VARIANTE, true)) {
            $key_v2    = self::RECURSO_KEY_PREFIX_V2 . $nombre;
            $synced_v2 = SyncedGithubFile::obtener_por_key($key_v2);

            if ($synced_v2 && (string) $synced_v2->content !== '') {
                return (string) $synced_v2->content;
            }

            /* v2 no sincronizada todavía (o vacía): loguear y caer a la variante vigente. */
            Log::warning('WhatsappProtocolService: variante v2 no disponible, sirviendo la vigente', [
                'recurso'  => $nombre,
                'variante' => $variante,
            ]);
        }

        /* Comportamiento vigente, intacto: variante 'actual' o fallback desde 'nueva'. */
        $key    = self::RECURSO_KEY_PREFIX . $nombre;
        $synced = SyncedGithubFile::obtener_por_key($key);

        return $synced ? (string) $synced->content : '';
    }
}

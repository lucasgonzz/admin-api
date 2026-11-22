<?php

/**
 * Ajustes del canal de WhatsApp del asistente personal (misión asistente-whatsapp-velocidad).
 *
 * Vive en un archivo propio y no en `config/services.php` para no pisar otras misiones.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Indicador de "escribiendo…"
    |--------------------------------------------------------------------------
    |
    | Mientras el asistente prepara la respuesta, el dueño ve "escribiendo…" en su WhatsApp (el
    | mensaje además queda marcado como leído). Con `false` el job no pide el indicador: sirve para
    | apagarlo sin desplegar, cambiando ASISTENTE_WHATSAPP_INDICADOR_ESCRIBIENDO en el .env.
    |
    */
    'indicador_escribiendo' => env('ASISTENTE_WHATSAPP_INDICADOR_ESCRIBIENDO', true),

];

<?php

/*
 | Seguimiento de misiones en el Admin (misión dotin-misiones-admin, 26/11/2026).
 |
 | La Gamer (la PC de Lucas) publica el estado de cada misión en `PUT claude/missions/{slug}`; el Admin mide qué tan
 | fresca es esa información contra su PROPIA hora (`reportado_at`) y la expone en El local y por el conector MCP.
 |
 | 🔴 Todas las claves tienen valor por defecto: el deploy del admin no toca el `.env` de producción, así que el
 | seguimiento tiene que andar sin ninguna variable. Las variables sirven sólo para pisar el valor.
 */
return [

    /* Minutos sin reporte hasta los cuales el estado se considera «al día». */
    'reporte_al_dia_min' => (int) env('MISSIONS_REPORTE_AL_DIA_MIN', 15),

    /* Minutos sin reporte a partir de los cuales se dice «sin señal» de la PC (entre uno y otro: «desactualizada»). */
    'reporte_sin_senal_min' => (int) env('MISSIONS_REPORTE_SIN_SENAL_MIN', 60),

    /* Minutos sin actividad real del transcript a partir de los cuales una misión «activa» figura «activa sin señal». */
    'actividad_real_max_min' => (int) env('MISSIONS_ACTIVIDAD_REAL_MAX_MIN', 20),

    /*
     | Emails de admin (separados por coma) que pueden registrar la respuesta a una decisión desde el Admin. Vacío =
     | cualquier admin humano (no agente, no «sólo leads»). Las preguntas sensibles nunca se registran por el Admin.
     */
    'answer_admin_emails' => (string) env('MISSIONS_ANSWER_ADMIN_EMAILS', ''),

    /* Respuestas que devuelve `GET claude/missions/answers/pending` si la Gamer no pide otro `limit`. */
    'pending_limit' => 20,

    /* Eventos que trae el detalle de una misión (`get_mission`). */
    'eventos_en_detalle' => 10,
];

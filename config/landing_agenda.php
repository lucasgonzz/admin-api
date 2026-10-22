<?php

/*
|--------------------------------------------------------------------------
| Agenda de la demo desde la landing (misión demo-autogestionada-landing, 22/10/2026)
|--------------------------------------------------------------------------
|
| El formulario «Elegí día y hora de tu demo» de comerciocity.com pide los turnos libres y reserva
| por dos endpoints públicos (`landing/agenda/turnos` y `landing/agenda/reservas`).
|
| 🔴 Lo de `presentacion` es un FILTRO DE PRESENTACIÓN, no de validez (D1 del plan). Los turnos
| válidos son los de la grilla del panel (`LeadAiService::build_availability_json`) y nada más; esto
| solo decide cuáles se le MUESTRAN a un visitante: no le ofrecemos una demo a las 3 de la mañana ni
| un turno que arranca a las 15:05 porque hoy los slots van cada 5 minutos. Un turno que este filtro
| esconde sigue siendo válido para el agente de WhatsApp y para la reserva.
|
| Va en código y no en el `.env`: el deploy del admin no toca el `.env` de producción.
*/

return [

    /* Throttle por IP de cada endpoint (D13). Ver `RouteServiceProvider`. */
    'turnos_por_minuto' => 30,
    'reservas_por_hora' => 10,

    /*
     * Tope GLOBAL de reservas efectivas desde la web, sumando todas las IP (segunda vuelta, 🟠3): con
     * tres instancias, ~100 reservas tapan la semana entera. Al pasarse, 429 con un mensaje que manda a
     * WhatsApp. Ver `LandingAgendaService::tope_global_alcanzado()`.
     */
    'reservas_globales_por_hora' => 20,
    'reservas_globales_por_dia'  => 60,

    'presentacion' => [
        /* Franja de INICIO que se muestra (inclusive en los dos extremos), en hora de Argentina. */
        'desde' => '09:00',
        'hasta' => '21:00',
        /* Solo inicios en múltiplos de este paso (minutos desde la medianoche): horas enteras. */
        'paso_minutos' => 60,
        /* Ningún inicio más cerca que esto de ahora. */
        'minimo_minutos_desde_ahora' => 60,
        /* Días corridos desde hoy (inclusive) que se consultan en la grilla. */
        'dias' => 7,
    ],

    /* Anti-spam (D13): un formulario enviado antes de esto desde que se abrió es un bot. */
    'abierto_minimo_ms' => 2500,

    /*
     * Hosts aceptados en `event_source_url`: la landing de producción, más los orígenes locales que
     * ya habilita la atribución (`ATRIBUCION_ORIGENES_LOCALES`), para poder probar la landing contra
     * un admin local. En producción ese env está vacío y quedan solo los dos de comerciocity.com.
     */
    'hosts_de_origen' => array_values(array_unique(array_filter(array_map(function ($origen) {
        return parse_url((string) $origen, PHP_URL_HOST);
    }, (array) ((require __DIR__ . '/atribucion.php')['cors_origenes'] ?? []))))),

    /*
     * Plantilla de WhatsApp de la confirmación al lead (D10): UTILITY, {{1}} nombre, {{2}} turno
     * («jueves 23/10 de 15:00 a 17:00»), {{3}} URL de su página. Solo sale si la fila de
     * `followup_templates` está activa y APPROVED; si no, queda un evento en el hilo.
     */
    'plantilla_confirmacion' => 'cc_demo_reservada_landing',
];

<?php

/*
|--------------------------------------------------------------------------
| La app del agente del dueño: `ia.comerciocity.com` (misión cruzada `app-ia-y-memoria`, 29/10/2026)
|--------------------------------------------------------------------------
| Lo que el admin necesita para servirla: el login público (`POST api/ia/login`), el directorio de
| dueños, la cola de imágenes que genera la PC de Lucas y las versiones de la app.
|
| ⚠️ Este archivo lo lee también `config/cors.php` con un `require` (ver `cors_origenes`), igual que
| `config/recursos.php`. Por eso tiene que seguir siendo un `return [...]` plano: sin funciones ni clases
| declaradas adentro, que al cargarse dos veces darían "cannot redeclare".
|
| 🔴 Los defaults van escritos acá y no solo en el `.env`: el deploy del admin no toca el `.env` de
| producción, así que la app tiene que andar con los defaults y la variable sirve solo para cambiarlos.
*/

return [

    /*
     | Orígenes del navegador de la app (separados por coma). Se suman a los de `ADMIN_SPA_URL` en
     | `config/cors.php`. Variable: IA_APP_ORIGENES.
     */
    'cors_origenes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('IA_APP_ORIGENES', 'https://ia.comerciocity.com'))
    ))),

    'login' => [
        // Intentos por minuto por IP (todas las cuentas). Variable: IA_LOGIN_POR_IP_POR_MINUTO.
        'por_ip_por_minuto'      => (int) env('IA_LOGIN_POR_IP_POR_MINUTO', 10),
        // Intentos por minuto por usuario (normalizado). Variable: IA_LOGIN_POR_USUARIO_POR_MINUTO.
        'por_usuario_por_minuto' => (int) env('IA_LOGIN_POR_USUARIO_POR_MINUTO', 5),
        // Cuántos sistemas se prueban como máximo por login (contrato C4: 5).
        'max_candidatos'         => 5,
        // Segundos de espera de cada `POST admin-sync/ia-app/token` (contrato C4: 15).
        'timeout'                => (int) env('IA_LOGIN_TIMEOUT', 15),
        // Segundos totales que puede tardar un login probando sistemas (los que no entran no se prueban).
        'presupuesto_segundos'   => (int) env('IA_LOGIN_PRESUPUESTO_SEGUNDOS', 45),
    ],

    'directorio' => [
        // Pausa entre cliente y cliente del barrido nocturno, en milisegundos (no ráfaga contra el shared).
        'pausa_ms' => (int) env('IA_DIRECTORIO_PAUSA_MS', 1000),
        'timeout'  => (int) env('IA_DIRECTORIO_TIMEOUT', 15),
    ],

    'imagenes' => [
        // Lease de la reserva de la PC (molde support-codex: 15 minutos).
        'lease_minutos'          => 15,
        // Reservas por pedido antes de darlo por fallido (contrato C3: vuelve a pendiente si intentos < 2).
        'max_intentos'           => 2,
        // Tope de pedidos por día por cliente, del lado del admin. El empresa-api ya corta en 20 por dueño;
        // esto protege la cuenta de ChatGPT de Lucas si una instancia se descontrola. Variable:
        // IA_IMAGENES_TOPE_DIARIO_POR_CLIENTE.
        'tope_diario_por_cliente' => (int) env('IA_IMAGENES_TOPE_DIARIO_POR_CLIENTE', 60),
        // Un pedido que nadie generó en estas horas se da por fallido (la PC estuvo apagada). Variable:
        // IA_IMAGENES_VENCE_HORAS.
        'vence_horas'            => (int) env('IA_IMAGENES_VENCE_HORAS', 6),
        // Disco privado del admin donde quedan las imágenes (nunca el público).
        'disco'                  => 'local',
        // Segundos de espera de la entrega al empresa-api (sube hasta 15 MB).
        'timeout_entrega'        => (int) env('IA_IMAGENES_TIMEOUT_ENTREGA', 60),
    ],

    'versiones' => [
        // Repo de la app en GitHub, sin owner (el owner es `services.github.releases_owner`).
        'release_repo' => env('IA_APP_RELEASE_REPO', 'ia-spa'),
    ],

];

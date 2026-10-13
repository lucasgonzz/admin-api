<?php

/*
|--------------------------------------------------------------------------
| Centro de recursos para clientes (misión centro-de-recursos-clientes, 13/10/2026)
|--------------------------------------------------------------------------
|
| La página pública de tutoriales en video (`recursos.comerciocity.com`), donde el usuario de un
| cliente entra con su perfil de ComercioCity y se guarda qué vio. El admin es su API: catálogo,
| sesión, avance, votos, preguntas y encuestas (rutas `recursos/*`), más el panel (`admin/recursos/*`)
| y la carga del catálogo por Claude (`claude/recursos/*`).
|
| 🔴 Los valores van ESCRITOS ACÁ y no con `env()`. El deploy del admin no toca el `.env` de
| producción, así que una variable de entorno nueva nunca llegaría: la página quedaría sin CORS y el
| link del dueño saldría apuntando a ningún lado. Para cambiarlos, se edita este archivo. Mismo
| criterio que `commerciocity.implementacion_mail`.
|
| ⚠️ Este archivo lo lee también `config/cors.php` con un `require` (ver `cors_origenes`). Por eso
| tiene que seguir siendo un `return [...]` plano: sin funciones ni clases declaradas adentro, que
| al cargarse dos veces darían "cannot redeclare".
*/

return [

    // La página del centro. Con ella se arma el link personal del dueño (`/?t=<token>`) y es el
    // destino del botón "Centro de recursos" del sistema de cada cliente (lo arma empresa-api).
    'url' => 'https://recursos.comerciocity.com',

    // Orígenes que el CORS del admin acepta además de los de `ADMIN_SPA_URL`. Van acá y no en el
    // `.env` por lo que dice el encabezado: el deploy no toca el `.env` de producción. Si
    // `ADMIN_SPA_URL` está vacío, `config/cors.php` sigue con `['*']` como siempre.
    'cors_origenes' => [
        'https://recursos.comerciocity.com',
    ],

    // Cuánto vive una sesión del centro desde que se crea (por pase o por link). Un año: el usuario
    // entra desde su sistema una vez y no tiene que volver a hacerlo en esa compu.
    'sesion_dias' => 365,

    // Cuánto vive el link personal del dueño. Es reutilizable hasta vencer (lo abre en la compu y en
    // el celular); vencido, el panel genera otro.
    'link_dias' => 90,

    // Con qué fracción del video vista cuenta como terminado. La misma constante que el prototipo
    // aprobado (`HECHO = 0.9`): si se cambia acá, hay que cambiarla también en la página.
    'umbral_terminado' => 0.9,

    // Topes de lo que manda la página en cada POST de avance (`recursos/progreso`). La página manda
    // lo reproducido cada ~10 s, así que 30 s por tramo y 50 tramos por pedido sobran.
    'progreso' => [
        'max_tramos'          => 50,
        'max_segundos_tramo'  => 30,
        // Holgura al final del video: el reproductor puede reportar `currentTime` unas décimas más
        // allá de la duración que se midió con ffprobe. Lo que se pase de esto es un tramo inválido.
        'holgura_final'       => 1,
    ],

    // Borrador de respuesta con IA (panel → "Proponer respuesta").
    'borrador' => [
        'max_tokens' => 700,
        'timeout'    => 40,
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     | Los orígenes del `.env` (`ADMIN_SPA_URL`, separados por coma) MÁS los de la página del centro de
     | recursos para clientes (`config/recursos.php` → `cors_origenes`, misión
     | centro-de-recursos-clientes, 13/10/2026).
     |
     | 🔴 Los del centro van escritos en código y no en el `.env`: el deploy del admin no toca el `.env`
     | de producción, así que un origen nuevo ahí nunca llegaría y la página quedaría sin poder hablarle
     | al admin. Se lee con `require` y no con `config()` porque acá la configuración todavía se está
     | cargando. Si `ADMIN_SPA_URL` está vacío, sigue `['*']` como siempre (abre todo, incluido el centro).
     */
    'allowed_origins' => env('ADMIN_SPA_URL')
        ? array_values(array_unique(array_merge(
            array_values(array_filter(array_map('trim', explode(',', env('ADMIN_SPA_URL'))))),
            (array) ((require __DIR__ . '/recursos.php')['cors_origenes'] ?? [])
        )))
        : ['*'],


    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

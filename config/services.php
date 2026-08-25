<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Configuración de llamadas salientes hacia cada empresa-api cliente.
    'client_api' => [
        'timeout' => env('CLIENT_API_TIMEOUT', 15),
        'retries' => env('CLIENT_API_RETRIES', 2),
        /*
         * Techo propio, en segundos, para la única llamada saliente que dispara una operación
         * destructiva y larga del otro lado: el demo setup (RunDemoSetupService).
         *
         * 🔴 Va aparte del 'timeout' genérico y de su multiplicador ×20 a propósito. Ese techo lo
         * comparten PublishVersionService y compañía, que hacen operaciones cortas; subírselo a
         * todos para que entre el setup les cambiaría el comportamiento a cambio de nada.
         *
         * 900 y no 300: medido el 25/8/2026, una corrida sola de DemoSetupHelper::run tarda
         * 565,7 s (9 m 26 s). Con el techo viejo de 300 s el admin daba por muerta una corrida que
         * seguía perfectamente viva del otro lado —el endpoint corre con ignore_user_abort(true) y
         * set_time_limit(0)—, la marcaba `fallido`, el panel volvía a mostrar el botón, y el
         * segundo click le hacía otro migrate:fresh a la base que la primera estaba sembrando.
         *
         * 🔴 Si movés este número, movés `RunDemoSetupJob::$timeout` en el MISMO commit. El worker
         * mata el proceso a los 1200 s, así que un techo de acá por encima de ése deja al service
         * sin llegar nunca a su `catch (ConnectionException)`: el lead se queda en `ejecutandose`
         * en vez de `sin_confirmar`, y todo el manejo del timeout se vuelve código muerto por el
         * camino automático. Está explicado entero en el docblock de esa propiedad, con los tres
         * umbrales y el orden en que tienen que estar.
         */
        'demo_setup_timeout' => env('CLIENT_API_DEMO_SETUP_TIMEOUT', 900),
    ],

    // Integración inbound desde empresa-api (rutas /api/inbound/*).
    // require_api_key: si es false, no se valida X-Admin-Api-Key; el Client se infiere del body (client_uuid)
    // o del ticket/mensaje. Solo uso temporal; en producción debe ser true.
    // Variable .env: ADMIN_INBOUND_REQUIRE_API_KEY.
    'admin_inbound_integration' => [
        'require_api_key' => env('ADMIN_INBOUND_REQUIRE_API_KEY', false),
    ],

    // Deployment automatizado (VPS de builds + hosting compartido).
    'deploy' => [
        'builds_spa_path' => env('DEPLOY_BUILDS_SPA_PATH', '/home/builds/empresa-spa'),
        'builds_api_path' => env('DEPLOY_BUILDS_API_PATH', '/home/builds/empresa-api'),
        // bash -lic carga .bashrc (nvm suele estar ahí); desactivar solo si rompe el shell remoto.
        'vps_use_interactive_login_shell' => filter_var(
            env('DEPLOY_VPS_USE_INTERACTIVE_LOGIN_SHELL', true),
            FILTER_VALIDATE_BOOLEAN
        ),
        // Si true, solo bash -lc sin preamble automático (legacy).
        'vps_use_login_shell_only' => filter_var(env('DEPLOY_VPS_USE_LOGIN_SHELL_ONLY', false), FILTER_VALIDATE_BOOLEAN),
        // Reemplaza el preamble automático (nvm/fnm/bashrc) si se define.
        'build_shell_preamble' => env('DEPLOY_BUILD_SHELL_PREAMBLE', ''),
        // Ruta absoluta si npm no está en PATH (mismo usuario que SSH_VPS_USERNAME en client_ssh_credentials).
        'npm_bin' => env('DEPLOY_NPM_BIN', 'npm'),
        // NVM fuera de $HOME (p. ej. /root/.nvm cuando el deploy entra como root).
        'nvm_dir' => env('DEPLOY_NVM_DIR', ''),
        // Webpack 4 + Node 17+: obligatorio en Linux (package.json usa "set" solo para Windows).
        'node_options' => env('DEPLOY_NODE_OPTIONS', '--openssl-legacy-provider'),
        // Carpeta de salida de vue-cli-service build (por defecto dist/).
        'spa_output_dir' => env('DEPLOY_SPA_OUTPUT_DIR', 'dist'),
        'spa_pusher_key' => env('DEPLOY_SPA_PUSHER_KEY', '98f389f62ef4a392fc77'),
        'spa_pusher_cluster' => env('DEPLOY_SPA_PUSHER_CLUSTER', 'sa1'),
        // Variables fijas del .env de empresa-spa en el VPS (VUE_APP_API_URL / APP_URL se agregan en runtime).
        'spa_build_env' => [
            'VUE_APP_IDIOM' => env('DEPLOY_SPA_IDIOM', 'en'),
            'VUE_APP_APP_NAME' => env('DEPLOY_SPA_APP_NAME', 'ComercioCity'),
            'VUE_APP_ROUTE_INDEX' => env('DEPLOY_SPA_ROUTE_INDEX', 'article'),
            'VUE_APP_ROUTE_TO_REDIRECT_IF_UNAUTHENTICATED' => env(
                'DEPLOY_SPA_ROUTE_TO_REDIRECT_IF_UNAUTHENTICATED',
                'login'
            ),
            'VUE_APP_IMAGE_URL_PROP_NAME' => env('DEPLOY_SPA_IMAGE_URL_PROP_NAME', 'hosting_url'),
            'VUE_APP_CUSTOM_CONFIGURATION_PAGE' => env('DEPLOY_SPA_CUSTOM_CONFIGURATION_PAGE', 'true'),
            'VUE_APP_USE_HOME_PAGE' => env('DEPLOY_SPA_USE_HOME_PAGE', 'true'),
            'VUE_APP_USE_HELP_DROPDOWN' => env('DEPLOY_SPA_USE_HELP_DROPDOWN', 'true'),
            'VUE_APP_HAS_EXTRA_CONFIG' => env('DEPLOY_SPA_HAS_EXTRA_CONFIG', 'true'),
            'VUE_APP_ATTEMPT_PROP' => env('DEPLOY_SPA_ATTEMPT_PROP', 'doc_number'),
            'VUE_APP_ATTEMPT_TEXT' => env('DEPLOY_SPA_ATTEMPT_TEXT', 'numero de documento'),
            // Buscador de imágenes de artículos (SearchImage.vue). Sin esta key el buscador queda
            // muerto en silencio (grupo 267/02 — la key salió del código fuente en el grupo 220).
            'VUE_APP_GOOGLE_SEARCH_API_KEY' => env('DEPLOY_SPA_GOOGLE_SEARCH_API_KEY', ''),
        ],
        'composer_bin' => env('DEPLOY_COMPOSER_BIN', 'composer'),
        // Variables fijas del .env de tienda-spa en el VPS (VUE_APP_API_URL / VUE_APP_APP_URL /
        // VUE_APP_COMMERCE_ID se arman en runtime en EcommerceInstallationService). Ambas keys
        // salieron del código fuente en el grupo 220 y sin ellas el build de tienda-spa se rompe
        // o queda con push notifications silenciosamente caído (grupo 267/02). Default '' siempre:
        // el valor real solo vive en el .env del admin, nunca en el repo.
        'tienda_build_env' => [
            'VUE_APP_GOOGLE_MAPS_API_KEY' => env('DEPLOY_TIENDA_GOOGLE_MAPS_API_KEY', ''),
            'VUE_APP_FIREBASE_API_KEY' => env('DEPLOY_TIENDA_FIREBASE_API_KEY', ''),
        ],
    ],

    // GitHub API: token para acceder al repositorio de documentación de ComercioCity.
    'github' => [
        'token' => env('GITHUB_PROTOCOL_TOKEN'),
    ],

    // API Anthropic (Claude) para sugerencias de mensajes en conversaciones de leads.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        // Ruta absoluta a cacert.pem (https://curl.se/ca/cacert.pem) si PHP no tiene CA bundle (típico WAMP/Windows).
        'ca_bundle' => env('ANTHROPIC_CAINFO'),
        // Solo desarrollo: false evita error cURL 60 si no configurás openssl.cafile / curl.cainfo en php.ini.
        'verify_ssl' => filter_var(env('ANTHROPIC_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    ],

    // Google Calendar OAuth2: permite que los closers conecten su calendario dedicado
    // de Google para bloquear disponibilidad de demos automáticamente.
    // Credenciales creadas en Google Cloud Console (tipo "Web application",
    // scope https://www.googleapis.com/auth/calendar.readonly).
    'google_calendar' => [
        'client_id'     => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'redirect_uri'  => env(
            'GOOGLE_OAUTH_REDIRECT_URI',
            'https://admin-api.comerciocity.com/api/admin/calendar/google/callback'
        ),
    ],

    // Web Push (VAPID): claves para firmar las notificaciones push enviadas
    // a los devices de los admins (minishlink/web-push). Generadas con
    // Minishlink\WebPush\VAPID::createVapidKeys(); nunca hardcodear en código.
    'vapid' => [
        'public_key'  => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject'     => env('VAPID_SUBJECT', 'mailto:soporte@comerciocity.com'),
    ],

    // URL del frontend admin-spa: usada para construir links directos a leads en notificaciones WhatsApp.
    'admin_spa' => [
        'url' => env('ADMIN_SPA_URL', 'https://admin.comerciocity.com'),
    ],

    // Kapso (WhatsApp Cloud API): TLS saliente desde WhatsappSendService.
    // Repositorio público: prohibido volver a escribir el valor real de api_key/webhook_secret
    // en el código. Ambos se cargan únicamente desde el .env (WhatsappConfigSeeder los lee de acá).
    'kapso' => [
        // Si no se define KAPSO_CAINFO, reutiliza ANTHROPIC_CAINFO (mismo cacert.pem en WAMP).
        'ca_bundle' => env('KAPSO_CAINFO', env('ANTHROPIC_CAINFO')),
        'verify_ssl' => filter_var(
            env('KAPSO_VERIFY_SSL', env('ANTHROPIC_VERIFY_SSL', true)),
            FILTER_VALIDATE_BOOLEAN
        ),
        // API key de Kapso para mandar WhatsApp desde el número de ComercioCity. Sensible: con este
        // valor un tercero puede enviar mensajes desde el canal por donde entran todos los leads.
        'api_key' => env('KAPSO_API_KEY', ''),
        // Secret para validar la firma del webhook entrante de Kapso.
        'webhook_secret' => env('KAPSO_WEBHOOK_SECRET', ''),
    ],

    // Pipeline de instalación/actualización del ecommerce (tienda-spa + tienda-api), prompt 584.
    // Reutiliza las credenciales SSH 'vps' y 'shared_hosting' ya usadas por 'deploy' (empresa);
    // solo agrega las rutas propias de los repos de tienda en el VPS de builds.
    'deploy_tienda' => [
        // Repo git de tienda-spa a clonar la primera vez (ensure_spa_cloned). Rama siempre master.
        'spa_git_repo' => env('DEPLOY_TIENDA_SPA_GIT_REPO', ''),
        // Repo git de tienda-api a clonar la primera vez en el VPS de builds (ensure_repo_cloned,
        // usado desde upload_api). Rama siempre master. Simétrico a spa_git_repo (prompt 189/01).
        'api_git_repo' => env('DEPLOY_TIENDA_API_GIT_REPO', ''),
        // Ruta del clone de tienda-spa en el VPS de builds.
        'builds_spa_path' => env('DEPLOY_TIENDA_BUILDS_SPA_PATH', '/home/builds/tienda-spa'),
        // Ruta del clone de tienda-api en el VPS de builds (se asume ya clonado, igual que empresa-api).
        'builds_api_path' => env('DEPLOY_TIENDA_BUILDS_API_PATH', '/home/builds/tienda-api'),
        // Color de fallback cuando falla la lectura del online_configuration o falta primary_color.
        'default_theme_color' => env('DEPLOY_TIENDA_DEFAULT_THEME_COLOR', '#c5111d'),
        // Timeout (segundos) para la consulta en vivo a GET {api_url}/api/commerce/{commerce_id}.
        'commerce_config_timeout' => env('DEPLOY_TIENDA_COMMERCE_CONFIG_TIMEOUT', 5),
        // Binario de PHP explícito para correr artisan en tienda-api en el hosting compartido.
        // El php de PATH está atado a PHP 7.4 (admin-api) via CloudLinux cl.selector;
        // tienda-api necesita PHP 8.4+ y el Composer platform_check aborta si usa el binario 7.4.
        'php_bin' => env('DEPLOY_TIENDA_PHP_BIN', '/opt/alt/php84/usr/bin/php'),
        // Ruta del script composer en el hosting compartido, para invocarlo explícitamente con
        // el php_bin correcto en lugar de depender del wrapper 'composer' bare de PATH.
        // Criterio estándar en hosting que requiere una versión específica de PHP (confirmado con Lucas, 22/7/2026).
        'composer_script' => env('DEPLOY_TIENDA_COMPOSER_SCRIPT', '/usr/local/bin/composer'),
        // Timeout (segundos) del lock exclusivo sobre el directorio de build compartido entre
        // todos los clientes: cuánto espera una corrida a que se libere el lock de otra antes de
        // abortar, y a partir de qué antigüedad se considera huérfano un lock de una corrida
        // muerta (grupo 208 — EcommerceInstallationService::acquire_build_lock()).
        'build_lock_timeout' => env('DEPLOY_TIENDA_BUILD_LOCK_TIMEOUT', 1800),
        // Invalidacion del cache de vista previa (Open Graph) de Meta al terminar el deploy
        // (grupo 292, correctivo del 270/06). Apagado por defecto: si esta en false, o si el
        // token viene vacio, no se hace ninguna llamada externa y el deploy sigue igual.
        'meta_scrape_enabled' => (bool) env('META_SCRAPE_ENABLED', false),
        // App Access Token de una app de Meta, con el formato {app_id} + barra vertical + {app_secret}.
        // Se obtiene en developers.facebook.com > la app > Herramientas > Explorador de tokens de acceso.
        // Sin este valor la invalidacion NO se hace: se loguea que esta desactivada y se sigue.
        // 🔴 Este token no se loguea nunca, ni entero ni parcial, y no viaja jamas por query string:
        // Guzzle copia la URI completa dentro del mensaje de sus excepciones de transporte.
        'meta_scrape_token' => env('META_SCRAPE_TOKEN', ''),
    ],

    // Ingesta de tareas creadas por Claude desde la conversación (grupo 180).
    // Si la clave no está definida, el endpoint queda cerrado: no se acepta ninguna request.
    'claude_task_ingest' => [
        'key' => env('CLAUDE_TASK_INGEST_KEY'),
        // Admin que figura como creador de las tareas que crea Claude. Si no se define,
        // se usa el primer admin con is_default_task_assignee, y si no hay, el primer admin.
        'default_creator_admin_id' => env('CLAUDE_TASK_INGEST_CREATOR_ADMIN_ID'),
    ],

];

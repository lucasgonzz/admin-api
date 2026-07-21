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
        ],
        'composer_bin' => env('DEPLOY_COMPOSER_BIN', 'composer'),
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
    'kapso' => [
        // Si no se define KAPSO_CAINFO, reutiliza ANTHROPIC_CAINFO (mismo cacert.pem en WAMP).
        'ca_bundle' => env('KAPSO_CAINFO', env('ANTHROPIC_CAINFO')),
        'verify_ssl' => filter_var(
            env('KAPSO_VERIFY_SSL', env('ANTHROPIC_VERIFY_SSL', true)),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    // Pipeline de instalación/actualización del ecommerce (tienda-spa + tienda-api), prompt 584.
    // Reutiliza las credenciales SSH 'vps' y 'shared_hosting' ya usadas por 'deploy' (empresa);
    // solo agrega las rutas propias de los repos de tienda en el VPS de builds.
    'deploy_tienda' => [
        // Repo git de tienda-spa a clonar la primera vez (ensure_spa_cloned). Rama siempre master.
        'spa_git_repo' => env('DEPLOY_TIENDA_SPA_GIT_REPO', ''),
        // Ruta del clone de tienda-spa en el VPS de builds.
        'builds_spa_path' => env('DEPLOY_TIENDA_BUILDS_SPA_PATH', '/home/builds/tienda-spa'),
        // Ruta del clone de tienda-api en el VPS de builds (se asume ya clonado, igual que empresa-api).
        'builds_api_path' => env('DEPLOY_TIENDA_BUILDS_API_PATH', '/home/builds/tienda-api'),
        // Color de fallback cuando falla la lectura del online_configuration o falta primary_color.
        'default_theme_color' => env('DEPLOY_TIENDA_DEFAULT_THEME_COLOR', '#c5111d'),
        // Timeout (segundos) para la consulta en vivo a GET {api_url}/api/commerce/{commerce_id}.
        'commerce_config_timeout' => env('DEPLOY_TIENDA_COMMERCE_CONFIG_TIMEOUT', 5),
    ],

];

<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/versions';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('landing-whatsapp', function (Request $request) {
            return Limit::perMinute((int) config('atribucion.limite_por_minuto', 30))
                ->by('landing-whatsapp:' . $request->ip());
        });
        /* Demo autogestionada desde la landing (22/10/2026, D13): cubetas propias por IP. Turnos: lo pide
           cada visitante que abre el selector. Reservas: pocas por hora, que es lo que una persona hace. */
        RateLimiter::for('landing-agenda-turnos', function (Request $request) {
            return Limit::perMinute(max(1, (int) config('landing_agenda.turnos_por_minuto', 30)))
                ->by('landing-agenda-turnos:' . $request->ip());
        });
        RateLimiter::for('landing-agenda-reservas', function (Request $request) {
            return Limit::perHour(max(1, (int) config('landing_agenda.reservas_por_hora', 10)))
                ->by('landing-agenda-reservas:' . $request->ip());
        });
        RateLimiter::for('demo-chat', function (Request $request) {
            // Todas las demos del VPS comparten IP: cada conversación necesita su propia cuota.
            // Throttle puede ejecutarse antes del middleware de identidad. El hash no revela la clave;
            // DemoEventosKey sigue autenticándola antes de entrar al controlador.
            $token = trim((string) $request->header('X-Demo-Eventos-Key'));
            $limite = max(1, (int) config('services.demo_chat.rate_limit_per_minute', 240));
            return Limit::perMinute($limite)->by('demo-chat:' . ($token !== '' ? hash('sha256', $token) : $request->ip()));
        });

        RateLimiter::for('shared-catalog-inbound', function (Request $request) {
            $client = $request->attributes->get('sync_client');
            abort_unless($client, 401, 'Falta la identidad validada del negocio.');
            $perMinute = max(1, (int) config('services.shared_catalog.rate_limit_per_minute', 180));

            // Las empresas alojadas en el mismo VPS comparten IP de salida. Reservas,
            // polling y telemetría de un negocio no pueden gastar la cubeta de los demás
            // ni la de los callbacks legacy. No se usa la clave privada como cache key.
            return Limit::perMinute($perMinute)->by('shared-catalog:client:' . $client->id);
        });

        RateLimiter::for('api', function (Request $request) {
            // Local: conversación + Pusher superan 60 req/min; en prod usar API_RATE_LIMIT_PER_MINUTE.
            $default_per_minute = app()->environment('local') ? 600 : 60;
            $per_minute = (int) env('API_RATE_LIMIT_PER_MINUTE', $default_per_minute);
            if ($per_minute < 1) {
                $per_minute = $default_per_minute;
            }

            return Limit::perMinute($per_minute)->by(optional($request->user())->id ?: $request->ip());
        });

        /*
         * 🔴 Cubeta PROPIA para el webhook crudo de Meta (atribución Click-to-WhatsApp).
         *
         * El limitador `api` de arriba agrupa por `user->id ?: ip`, o sea UNA sola cubeta por IP
         * para todo /api. Kapso ahora pega dos veces por cada mensaje entrante —una al webhook que
         * crea leads y mensajes, otra a este— desde la misma IP: dejarlos juntos le parte al medio
         * la capacidad efectiva al que NO puede perder una entrega, y en una ráfaga el 429 le toca
         * justo a ese. La atribución se puede perder; una conversación de un lead, no.
         *
         * El prefijo en el `by()` es lo que hace la cubeta distinta aunque la IP sea la misma.
         */
        RateLimiter::for('meta-raw-webhook', function (Request $request) {
            $default_per_minute = app()->environment('local') ? 600 : 120;
            $per_minute = (int) env('META_RAW_WEBHOOK_RATE_LIMIT_PER_MINUTE', $default_per_minute);
            if ($per_minute < 1) {
                $per_minute = $default_per_minute;
            }

            return Limit::perMinute($per_minute)->by('meta-raw-webhook:' . $request->ip());
        });

        /*
         * 🔴 Cubeta PROPIA para el webhook de MENSAJES de WhatsApp (misión
         * whatsapp-dos-numeros, 13/10/2026).
         *
         * Hasta hoy `webhook/whatsapp` colgaba del limitador `api`, que agrupa por
         * `user->id ?: ip`: UNA cubeta de 60/min por IP para toda la API. Y por esa ruta entran
         * ahora los mensajes de los DOS números —el institucional (leads, soporte,
         * implementación) y el del asistente de los dueños—, los dos desde la misma IP de Kapso.
         * O sea que un dueño mandando una ráfaga de fotos a su asistente le comía la cuota a las
         * conversaciones de los leads, y al revés. Un 429 acá no es un reintento más tarde: es un
         * mensaje de una persona que no entra.
         *
         * El prefijo en el `by()` es lo que hace la cubeta distinta aunque la IP sea la misma.
         */
        RateLimiter::for('whatsapp-webhook', function (Request $request) {
            $default_per_minute = app()->environment('local') ? 600 : 120;
            $per_minute = (int) env('WHATSAPP_WEBHOOK_RATE_LIMIT_PER_MINUTE', $default_per_minute);
            if ($per_minute < 1) {
                $per_minute = $default_per_minute;
            }

            return Limit::perMinute($per_minute)->by('whatsapp-webhook:' . $request->ip());
        });
    }
}

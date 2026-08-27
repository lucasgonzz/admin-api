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
    }
}

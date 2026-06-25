<?php

namespace App\Providers;

use GuzzleHttp\Client;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /**
         * Cliente Guzzle con verify configurable (Windows/WAMP sin CA bundle → cURL error 60).
         * Ver config/broadcasting.php: guzzle_verify, guzzle_ca_bundle.
         *
         * @param \Illuminate\Contracts\Foundation\Application $app
         * @param array<string, mixed> $config
         */
        Broadcast::extend('pusher', function ($app, array $config) {
            /**
             * @var array<string, mixed>
             */
            $options = $config['options'] ?? [];

            $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 30;

            $ca_bundle = isset($options['guzzle_ca_bundle']) ? (string) $options['guzzle_ca_bundle'] : '';

            /**
             * @var bool|string
             */
            $verify = $ca_bundle !== '' ? $ca_bundle : (bool) ($options['guzzle_verify'] ?? true);

            $guzzle = new Client([
                'timeout' => $timeout,
                'verify' => $verify,
            ]);

            $pusher = new Pusher(
                $config['key'],
                $config['secret'],
                $config['app_id'],
                $options,
                $guzzle
            );

            if ($config['log'] ?? false) {
                $pusher->setLogger($app->make(LoggerInterface::class));
            }

            return new PusherBroadcaster($pusher);
        });

        // Registra /broadcasting/auth con Sanctum para que los tokens Bearer funcionen en canales privados.
        Broadcast::routes(['middleware' => ['auth:sanctum']]);

        require base_path('routes/channels.php');
    }
}

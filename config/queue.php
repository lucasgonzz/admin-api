<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            /* 🔴 `retry_after` tiene que ser MAYOR que el timeout del job más largo de esta
             * conexión. Si no, el job vuelve a quedar disponible mientras el primer worker lo sigue
             * corriendo bien: el worker del tick siguiente lo reserva, ve `attempts > tries` y lo
             * manda a `failed_jobs` con MaxAttemptsExceededException. No hay doble ejecución
             * (Laravel falla antes de `handle()`), pero `failed_jobs` se llena de fallos falsos y
             * `salud.jobs_en_cola` pasa a leer 0 para un trabajo que sigue vivo — o sea, mienten
             * justo las dos señales que uno mira para debuggear esto. Con los 90 de siempre le
             * pasaba al `RunDemoSetupJob` ($timeout = 600), que fue el caso que lo destapó en la
             * misión 60.
             *
             * 1860 = 1800 + 60 (misión 61). El job más largo de esta conexión NO es
             * `RunDemoSetupJob` sino `RunDeploymentJob`, con `$timeout = 1800`: ese cálculo quedó
             * viejo cuando `ClaudeUpgradeOpsController` empezó a encolar deployments acá, y la
             * misión 61 —que manda también los cuatro despachos del panel a esta conexión— lo
             * volvió el caso normal en vez de la excepción. Con los 660 anteriores, TODO deployment
             * de más de once minutos —que son casi todos: `npm ci` + `npm run build` + dos zips +
             * `composer install`— se marcaba fallido sin serlo.
             *
             * Si algún día un job de esta conexión sube su `$timeout`, este número sube con él. */
            'retry_after' => 1860,
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => 'localhost',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];

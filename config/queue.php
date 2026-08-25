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
             * Con los 660 anteriores, TODO deployment de más de once minutos —que son casi todos:
             * `npm ci` + `npm run build` + dos zips + `composer install`— se marcaba fallido sin
             * serlo. El cálculo de 660 quedó viejo cuando `ClaudeUpgradeOpsController` empezó a
             * encolar deployments acá, porque el job más largo de esta conexión dejó de ser
             * `RunDemoSetupJob` (600) y pasó a ser `RunDeploymentJob` (1800).
             *
             * 🔴 2400 (40 min) = 1800 + 600 (misión 61). Hay TRES umbrales actuando sobre el mismo
             * deployment y conviene tenerlos a la vista, porque no son intercambiables:
             *
             *   1. `RunDeploymentJob::TIMEOUT_SEGUNDOS` — 30 min. MATA el proceso vivo.
             *   2. `VencerDeploymentsColgados` — piso 35 min, default 45. Marca `failed`, pero SOLO
             *      si no hubo actividad de `deployment_logs`: **mira evidencia antes de escribir**.
             *   3. `retry_after` — 40 min. Marca `failed` A CIEGAS, sin mirar logs ni el ancla.
             *
             * 🔴 EL INVARIANTE es que **2 y 3 tienen que ser mayores que 1**, no que estén en un
             * orden entre ellos. Una versión anterior de este comentario afirmaba que `retry_after`
             * tenía que ser "el más alto de los tres", y era un sobre-reclamo imposible de cumplir:
             * el umbral 2 es configurable hasta 720 minutos, así que ningún `retry_after` razonable
             * puede quedar por encima. Que el 2 sea más alto que el 3 tampoco es un problema —
             * justamente porque el 2 mira evidencia, es seguro a cualquier valor.
             *
             * Lo que sí importa del 3 es que sea **holgadamente mayor que 1**: mientras `$timeout`
             * acote de verdad al job, ningún proceso vivo llega a los 40 minutos y este camino
             * simplemente no se dispara nunca. Con los 660 anteriores se disparaba siempre; con los
             * 1860 de la primera corrección de esta misión, a los 31.
             *
             * ⚠️ Y acá está el agujero honesto: el punto 1 solo existe si el CLI del servidor tiene
             * `pcntl` (`Worker::supportsAsyncSignals()`). Sin `pcntl` no hay `SIGALRM`, `$timeout`
             * es letra muerta, `exec_ssh_session` usa `setTimeout(0)` y un pipeline colgado no tiene
             * ninguna cota superior — o sea que NINGÚN valor finito de `retry_after` garantiza no
             * pisar un proceso vivo. Lo único que queda en ese escenario es que el texto que escribe
             * `RunDeploymentJob::motivo_del_fallo()` para `MaxAttemptsExceededException` avisa que
             * NO se reintente sin confirmar. Verificar `pcntl` en el servidor es un paso previo al
             * despliegue, no un detalle.
             *
             * El invariante lo verifica `RobustezDelDeploymentDesatendidoTest`, que además chequea
             * que ningún OTRO job de esta conexión tenga un `$timeout` por encima — hoy
             * `RunClientInstallationGroupJob` (3900) y `RunDemoUpdateJob` (3600) están a un
             * `->onConnection('database')` de romper esto. */
            'retry_after' => 2400,
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

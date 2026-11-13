<?php

/** Corte aceptado: sólo actividad posterior al inicio; la cola debe estar vacía antes de escribir. */
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $owner = (int) $argv[1];
    $since = (int) $argv[2];
    $db = $app['db']->connection();
    if ($owner < 1 || $since < 1 || $db->getDriverName() !== 'mysql') { exit(1); }
    $cutoff = \Carbon\Carbon::createFromTimestampUTC($since)->setTimezone(config('app.timezone'))->toDateTimeString();
    $db->getPdo()->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $owners = $db->table('users')->whereNull('owner_id')->pluck('id');
        if ($owners->count() !== 1 || (int) $owners->first() !== $owner) { exit(1); }
        $users = $db->table('users')->where(function ($q) use ($owner) {
            $q->where('id', $owner)->orWhere('owner_id', $owner);
        })->where('last_activity', '>=', $cutoff)->exists();
        $sales = $db->table('sales')->where('user_id', $owner)->where(function ($q) use ($cutoff) {
            $q->where('created_at', '>=', $cutoff)->orWhere('updated_at', '>=', $cutoff);
        })->exists();
        // Redis también puede tener trabajo. Si su estado no se puede medir, no hay permiso de escritura.
        $pending = $app['queue']->size() > 0;
        if ($app['db']->getSchemaBuilder()->hasTable('jobs')) { $pending = $pending || $db->table('jobs')->exists(); }
        echo json_encode(['usuarios_activos' => (int) $users, 'ventas_recientes' => (int) $sales, 'trabajos_pendientes' => (int) $pending]);
    } finally { $db->rollBack(); }
} catch (\Throwable $error) { exit(1); }

<?php

/** Se ejecuta en la tienda: su cola puede usar otro prefijo que el ERP. No escribe datos. */
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $owner = (int) $argv[1]; $since = (int) $argv[2];
    $db = $app['db']->connection();
    if ($owner < 1 || $since < 1 || $db->getDriverName() !== 'mysql') { exit(1); }
    $cutoff = \Carbon\Carbon::createFromTimestampUTC($since)->setTimezone(config('app.timezone'))->toDateTimeString();
    $db->getPdo()->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
    try {
        $owners = $db->table('users')->whereNull('owner_id')->pluck('id');
        if ($owners->count() !== 1 || (int) $owners->first() !== $owner) { exit(1); }
        $orders = $db->table('orders')->where('user_id', $owner)->where(function ($q) use ($cutoff) {
            $q->where('created_at', '>=', $cutoff)->orWhere('updated_at', '>=', $cutoff);
        })->exists();
        $carts = $db->table('carts')->where('user_id', $owner)->where('updated_at', '>=', $cutoff)->exists();
        $pending = $app['queue']->size() > 0;
        if ($db->getSchemaBuilder()->hasTable('jobs')) { $pending = $pending || $db->table('jobs')->exists(); }
        echo json_encode(['pedidos_recientes' => (int) $orders, 'carritos_recientes' => (int) $carts, 'trabajos_pendientes' => (int) $pending]);
    } finally { $db->rollBack(); }
} catch (\Throwable $error) { exit(1); }

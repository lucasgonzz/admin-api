<?php

/**
 * Payload fijo para php -r en el frente activo del ERP.
 * Las señales replican el chequeo de tanda y agregan ventas editadas recientemente.
 */
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $owner = (int) $argv[1];
    $db = $app['db']->connection();
    if ($owner < 1 || $db->getDriverName() !== 'mysql') {
        exit(1);
    }

    $db->getPdo()->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $owners = $db->table('users')->whereNull('owner_id')->pluck('id');
        if ($owners->count() !== 1 || (int) $owners->first() !== $owner) {
            throw new \RuntimeException('El dueño de la base no coincide con el del negocio.');
        }
        $users = $db->table('users')
            ->where(function ($query) use ($owner) {
                $query->where('id', $owner)->orWhere('owner_id', $owner);
            })
            ->whereRaw('last_activity >= NOW() - INTERVAL 30 MINUTE')->exists();
        $sales = $db->table('sales')->where('user_id', $owner)
            ->where(function ($query) {
                $query->whereRaw('created_at >= NOW() - INTERVAL 30 MINUTE')
                    ->orWhereRaw('updated_at >= NOW() - INTERVAL 30 MINUTE');
            })->exists();
        echo json_encode(['usuarios_activos' => (int) $users, 'ventas_recientes' => (int) $sales]);
    } finally {
        $db->rollBack();
    }
} catch (\Throwable $error) {
    exit(1);
}

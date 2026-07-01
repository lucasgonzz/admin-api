<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Agrupa clientes que comparten la misma base de datos física en producción.
 */
class SharedDatabaseGroup extends Model
{
    protected $guarded = [];

    /**
     * Clientes que pertenecen a este grupo de BD compartida.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function clients()
    {
        return $this->hasMany(Client::class, 'shared_database_group_id');
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\Client;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Restricción opcional: sin filas en el pivote = aplica a todos los clientes;
 * con filas = solo a los `Client` vinculados.
 */
trait RestrictsToClients
{
    public static function bootRestrictsToClients(): void
    {
        static::deleting(function ($model) {
            if (method_exists($model, 'restrictedClients')) {
                $model->restrictedClients()->detach();
            }
        });
    }

    public function restrictedClients(): MorphToMany
    {
        return $this->morphToMany(Client::class, 'version_item', 'version_item_clients')
            ->withTimestamps();
    }

    /**
     * Ítems sin restricción o cuyo pivote incluye a este cliente.
     */
    public function scopeForClientId($query, int $clientId)
    {
        return $query->where(function ($q) use ($clientId) {
            $q->whereDoesntHave('restrictedClients')
                ->orWhereHas('restrictedClients', function ($c) use ($clientId) {
                    $c->where('clients.id', $clientId);
                });
        });
    }

    public function syncRestrictedClientIdsFromRequest(array $clientIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $clientIds)));
        $this->restrictedClients()->sync($ids);
    }

    public function appliesToClientId(int $clientId): bool
    {
        $this->loadMissing('restrictedClients');
        if ($this->restrictedClients->isEmpty()) {
            return true;
        }

        return $this->restrictedClients->contains('id', (int) $clientId);
    }
}

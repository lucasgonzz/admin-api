<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Suscripción Web Push de un device de un admin (teléfono, notebook, etc.).
 * Generada por el navegador al aceptar el permiso de notificaciones en la PWA.
 */
class AdminPushSubscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    /**
     * Admin propietario de este device.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admin()
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    /**
     * Hash con el que se identifica un endpoint en la base.
     *
     * La unicidad de la suscripción vive en `endpoint_hash` y no en `endpoint`, porque
     * el endpoint es una URL larga (los de Apple pasan los 191 caracteres) que no entra
     * en un índice. Toda búsqueda por endpoint tiene que pasar por acá: buscar por la
     * columna `endpoint` directamente hace un scan sobre un TEXT sin índice.
     *
     * @param string $endpoint URL del push service devuelta por PushSubscription.endpoint.
     *
     * @return string SHA-256 en hexadecimal (64 caracteres).
     */
    public static function hash_endpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class ClientNotificationRead extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    function scopeWithAll($query) {
        // Los accesores ecommerce_* del $appends de Client resuelven contra client_ecommerce:
        // sin precargarla salia una consulta por fila serializada.
        $query->with('client', 'client.client_ecommerce', 'version_notification.version');
    }

    public function client() {
        return $this->belongsTo(Client::class);
    }

    public function version_notification() {
        return $this->belongsTo(VersionNotification::class);
    }
}

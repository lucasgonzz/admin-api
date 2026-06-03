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
        $query->with('client', 'version_notification.version');
    }

    public function client() {
        return $this->belongsTo(Client::class);
    }

    public function version_notification() {
        return $this->belongsTo(VersionNotification::class);
    }
}

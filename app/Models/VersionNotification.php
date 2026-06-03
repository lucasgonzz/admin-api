<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RestrictsToClients;
use Illuminate\Database\Eloquent\Model;

class VersionNotification extends Model
{
    use HasUuid;
    use RestrictsToClients;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    function scopeWithAll($query) {
        $query->with('version', 'restrictedClients');
    }

    public function version() {
        return $this->belongsTo(Version::class);
    }

    public function reads() {
        return $this->hasMany(ClientNotificationRead::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RestrictsToClients;
use Illuminate\Database\Eloquent\Model;

class VersionManualTask extends Model
{
    use HasUuid;
    use RestrictsToClients;

    protected $table = 'version_manual_tasks';

    protected $guarded = [];

    protected $casts = [
        'is_required' => 'boolean',
        'execution_order' => 'integer',
    ];

    function scopeWithAll($query) {
        $query->with('version', 'restrictedClients');
    }

    public function version() {
        return $this->belongsTo(Version::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RestrictsToClients;
use Illuminate\Database\Eloquent\Model;

class VersionCommand extends Model
{
    use HasUuid;
    use RestrictsToClients;

    protected $table = 'version_commands';

    protected $guarded = [];

    protected $casts = [
        'is_required' => 'boolean',
        'run_manually' => 'boolean',
        'execution_order' => 'integer',
    ];

    function scopeWithAll($query) {
        $query->with('version', 'restrictedClients');
    }

    public function version() {
        return $this->belongsTo(Version::class);
    }
}
